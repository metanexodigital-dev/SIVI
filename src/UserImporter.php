<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/UserImporter.php
 * Propósito: Importa usuarios y valida su relación con sedes y alcances.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

final class UserImporter
{
    public static function import(string $path): array
    {
        $reader = new XlsxReader($path);
        $sheetNames = $reader->sheetNames();
        $sheet = in_array('Usuarios', $sheetNames, true) ? 'Usuarios' : ($sheetNames[0] ?? '');
        if ($sheet === '') {
            throw new RuntimeException('El archivo no contiene hojas para importar.');
        }

        $sedes = Database::fetchAll('SELECT id,identificador,cod_dd,departamento,municipio,tipo_sede,nombre_sede FROM sedes');
        $sedeByIdentifier = [];
        foreach ($sedes as $sede) {
            $identifier = strtoupper(trim((string)$sede['identificador']));
            if ($identifier !== '') $sedeByIdentifier[$identifier] = $sede;
        }
        $validDepartments = [];
        $departmentAliases = [];
        foreach ($sedes as $sede) {
            $code = trim((string)$sede['cod_dd']);
            $validDepartments[$code] = (string)$sede['departamento'];
            $departmentAliases[self::normalizeDepartmentCode($code)] = $code;
        }

        $created = 0;
        $errors = [];
        $seenEmails = [];
        $rowNumber = 1;
        foreach ($reader->rows($sheet, 1) as $row) {
            $rowNumber++;
            $name = trim(self::value($row, ['Nombre Completo','Nombre','Usuario']));
            $email = strtolower(trim(self::value($row, ['Correo Electronico','Correo Electrónico','Correo','Email'])));
            $password = self::value($row, ['Contrasena Inicial','Contraseña Inicial','Contrasena','Contraseña','Password']);
            $role = self::normalizeRole(self::value($row, ['Rol','Perfil']));
            $active = self::normalizeActive(self::value($row, ['Activo','Estado']));

            if ($name === '' && $email === '' && $password === '' && $role === '') continue;
            $rowErrors = [];
            if ($name === '') $rowErrors[] = 'Falta el nombre completo.';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $rowErrors[] = 'El correo no es válido.';
            if (strlen($password) < 8) $rowErrors[] = 'La contraseña debe tener mínimo 8 caracteres.';
            if (!in_array($role, ['registrador','formador','admin_gi'], true)) $rowErrors[] = 'El rol no es válido.';
            if (isset($seenEmails[$email])) $rowErrors[] = 'El correo está repetido dentro del archivo.';
            if ($email !== '' && Database::fetchOne('SELECT id FROM users WHERE email=?', [$email])) $rowErrors[] = 'El correo ya existe en la plataforma.';

            $sedeId = null;
            $departments = [];
            if ($role === 'registrador') {
                $identifier = strtoupper(trim(self::value($row, ['Identificador Sede','ID Sede','Codigo Sede','Código Sede'])));
                $sede = $identifier !== '' ? ($sedeByIdentifier[$identifier] ?? null) : null;
                if (!$sede) {
                    $sede = self::findSedeByFields($sedes, $row);
                }
                if (!$sede) {
                    $rowErrors[] = 'No fue posible identificar la sede del Registrador. Diligencie el Identificador Sede o la jerarquía completa.';
                } else {
                    $sedeId = (int)$sede['id'];
                }
            } elseif ($role === 'formador') {
                $departmentText = self::value($row, ['Departamentos Formador','Departamentos','Codigo Departamento','Código Departamento']);
                $rawDepartments = self::splitDepartments($departmentText);
                foreach ($rawDepartments as $rawCode) {
                    $alias = self::normalizeDepartmentCode($rawCode);
                    if (!isset($departmentAliases[$alias])) {
                        $rowErrors[] = "El departamento {$rawCode} no existe en el maestro de sedes.";
                        continue;
                    }
                    $departments[] = $departmentAliases[$alias];
                }
                $departments = array_values(array_unique($departments));
                if (!$departments) $rowErrors[] = 'El Formador debe tener al menos un código de departamento válido.';
            }

            if ($rowErrors) {
                $errors[] = ['fila' => $rowNumber, 'correo' => $email, 'errores' => implode(' ', array_unique($rowErrors))];
                continue;
            }

            $pdo = Database::connection();
            try {
                $pdo->beginTransaction();
                Database::execute(
                    'INSERT INTO users (name,email,password_hash,role,sede_id,active) VALUES (?,?,?,?,?,?)',
                    [$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $sedeId, $active]
                );
                $userId = (int)$pdo->lastInsertId();
                foreach ($departments as $dd) {
                    Database::execute('INSERT INTO user_departments (user_id,cod_dd) VALUES (?,?)', [$userId, $dd]);
                }
                $pdo->commit();
                $seenEmails[$email] = true;
                $created++;
                audit('bulk_create_user', 'user', $userId, null, ['role' => $role, 'sede_id' => $sedeId, 'departments' => $departments]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $reference = log_exception_reference($e, 'bulk_user_row_' . $rowNumber);
                $errors[] = [
                    'fila' => $rowNumber,
                    'correo' => $email,
                    'errores' => safe_error_message('No fue posible crear el usuario de esta fila', $reference),
                ];
            }
        }

        return ['created' => $created, 'errors' => $errors, 'sheet' => $sheet];
    }

    private static function value(array $row, array $aliases): string
    {
        $normalized = [];
        foreach ($row as $key => $value) $normalized[self::normalizeText((string)$key)] = trim((string)$value);
        foreach ($aliases as $alias) {
            $key = self::normalizeText($alias);
            if (array_key_exists($key, $normalized)) return $normalized[$key];
        }
        return '';
    }

    private static function normalizeRole(string $role): string
    {
        $role = self::normalizeText($role);
        return match ($role) {
            'registrador' => 'registrador',
            'formador' => 'formador',
            'admin gi', 'admin_gi', 'administrador gi', 'administrador nacional' => 'admin_gi',
            default => '',
        };
    }

    private static function normalizeActive(string $value): int
    {
        $value = self::normalizeText($value);
        return in_array($value, ['no','0','inactivo','desactivado'], true) ? 0 : 1;
    }

    private static function splitDepartments(string $value): array
    {
        $parts = preg_split('/[,;|\s]+/', trim($value)) ?: [];
        $parts = array_values(array_unique(array_filter(array_map(static fn($v) => trim((string)$v), $parts), static fn($v) => $v !== '')));
        return $parts;
    }

    private static function findSedeByFields(array $sedes, array $row): ?array
    {
        $type = self::normalizeText(self::value($row, ['Tipo de Sede','Tipo Sede']));
        $departmentCode = trim(self::value($row, ['Codigo Departamento','Código Departamento','Cod DD']));
        $departmentName = self::normalizeText(self::value($row, ['Departamento']));
        $municipality = self::normalizeText(self::value($row, ['Municipio']));
        $sedeName = self::normalizeText(self::value($row, ['Nombre Sede','Sede']));
        $matches = [];
        foreach ($sedes as $sede) {
            if ($type !== '' && self::normalizeText((string)$sede['tipo_sede']) !== $type) continue;
            if ($departmentCode !== '' && self::normalizeDepartmentCode((string)$sede['cod_dd']) !== self::normalizeDepartmentCode($departmentCode)) continue;
            if ($departmentCode === '' && $departmentName !== '' && self::normalizeText((string)$sede['departamento']) !== $departmentName) continue;
            if ($municipality !== '' && self::normalizeText((string)$sede['municipio']) !== $municipality) continue;
            if ($sedeName !== '' && self::normalizeText((string)$sede['nombre_sede']) !== $sedeName) continue;
            $matches[] = $sede;
        }
        return count($matches) === 1 ? $matches[0] : null;
    }

    private static function normalizeDepartmentCode(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        if (ctype_digit($value)) return (string)((int)$value);
        return strtoupper($value);
    }

    private static function normalizeText(string $value): string
    {
        $value = strtolower(trim($value));
        $value = strtr($value, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        return $value;
    }
}
