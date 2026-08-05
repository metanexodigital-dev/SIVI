<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/WarehouseSedeAssociator.php
 * Propósito: Asocia registros de Almacén con sedes usando evidencias territoriales.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

/**
 * Asociación territorial para activos del Inventario de Almacén.
 *
 * Orden de validación:
 *  1. Identificador o nombre exacto de la sede en Nombre Sucursal/Centro de Costo.
 *  2. Municipio + tipo de sede, restringido por el departamento detectado.
 *  3. Municipio único dentro del departamento.
 *  4. Contingencia territorial: CAN, Registraduría Distrital o Delegación Departamental.
 *
 * Las asignaciones por departamento son aproximadas y siempre requieren revisión.
 */
final class WarehouseSedeAssociator
{
    /** @var array<string,array<string,mixed>> */
    private static array $cache = [];

    /** @param array<int,array<string,mixed>> $sedes */
    public static function prepare(array $sedes): array
    {
        self::$cache = [];
        $rows = [];
        $departmentAliases = [];
        $delegations = [];
        $distrital = null;
        $can = null;

        foreach ($sedes as $sede) {
            $identifier = strtoupper(trim((string)($sede['identificador'] ?? '')));
            $codDd = self::normalizeCode((string)($sede['cod_dd'] ?? ''));
            $department = self::normalize((string)($sede['departamento'] ?? ''));
            $municipality = self::normalize((string)($sede['municipio'] ?? ''));
            $type = self::type((string)($sede['tipo_sede'] ?? ''), (string)($sede['nombre_sede'] ?? ''));
            $name = self::normalize((string)($sede['nombre_sede'] ?? ''));

            $row = $sede;
            $row['_identifier_upper'] = $identifier;
            $row['_identifier_normalized'] = self::normalize($identifier);
            $row['_cod_dd'] = $codDd;
            $row['_department'] = $department;
            $row['_department_compact'] = self::compact($department);
            $row['_municipality'] = $municipality;
            $row['_municipality_compact'] = self::compact($municipality);
            $row['_type'] = $type;
            $row['_name'] = $name;
            $row['_name_compact'] = self::compact($name);
            $row['_search_name'] = self::searchableSedeName($name);
            $rows[] = $row;

            if ($codDd !== '' && $department !== '') {
                foreach (self::departmentAliases($department) as $alias) {
                    $departmentAliases[$alias]['dd:' . $codDd] = $codDd;
                }
            }
            if ($type === 'delegacion' && $codDd !== '') {
                $delegations['dd:' . $codDd][] = $row;
            } elseif ($type === 'distrital') {
                $distrital = $row;
            } elseif ($type === 'can') {
                $can = $row;
            }
        }

        return [
            'rows' => $rows,
            'department_aliases' => $departmentAliases,
            'delegations' => $delegations,
            'distrital' => $distrital,
            'can' => $can,
        ];
    }

    /** @param array<string,mixed> $prepared */
    public static function associate(string $branch, string $costCenter, array $prepared): array
    {
        $branchN = self::normalize($branch);
        $costN = self::normalize($costCenter);
        $combined = trim($branchN . ' ' . $costN);
        // El Centro de Costo es la señal principal de sede. La Sucursal se usa
        // como contexto departamental y solo reemplaza al centro cuando está vacío.
        $sedeText = $costN !== '' ? $costN : $branchN;
        $cacheKey = hash('sha256', $branchN . '|' . $costN);
        if (isset(self::$cache[$cacheKey])) return self::$cache[$cacheKey];

        if ($combined === '') {
            return self::$cache[$cacheKey] = self::unassigned($branch, $costCenter, 'almacen_sin_datos_territoriales');
        }

        $rows = is_array($prepared['rows'] ?? null) ? $prepared['rows'] : [];
        $departmentCodes = self::departmentCodes($branchN, $costN, $prepared);

        // 1. Identificador institucional explícito en Sucursal o Centro de Costo.
        $identifierMatches = [];
        foreach ($rows as $sede) {
            $identifierN = (string)($sede['_identifier_normalized'] ?? '');
            if ($identifierN !== '' && self::containsWholePhrase($combined, $identifierN)) {
                $identifierMatches[(int)$sede['id']] = $sede;
            }
        }
        if (count($identifierMatches) === 1) {
            return self::$cache[$cacheKey] = self::result(
                array_values($identifierMatches)[0],
                'alta',
                false,
                'almacen_identificador_sede',
                $branch,
                $costCenter,
                $departmentCodes
            );
        }

        // 2. Puntaje por nombre de sede, municipio y tipo de oficina.
        $scored = [];
        foreach ($rows as $sede) {
            $codDd = (string)($sede['_cod_dd'] ?? '');
            if ($departmentCodes !== [] && !in_array($codDd, $departmentCodes, true)) continue;

            $score = 0;
            $reasons = [];
            $name = (string)($sede['_name'] ?? '');
            $searchName = (string)($sede['_search_name'] ?? '');
            $municipality = (string)($sede['_municipality'] ?? '');
            $type = (string)($sede['_type'] ?? '');

            if ($name !== '' && self::length($name) >= 6 && self::containsWholePhrase($sedeText, $name)) {
                $score += 110;
                $reasons[] = 'nombre_sede_completo';
            } elseif ($searchName !== '' && self::length($searchName) >= 5 && self::containsWholePhrase($sedeText, $searchName)) {
                $score += 85;
                $reasons[] = 'nombre_sede_reducido';
            }

            $municipalityDetected = $municipality !== ''
                && self::length($municipality) >= 4
                && self::containsWholePhrase($sedeText, $municipality);
            if ($municipalityDetected) {
                $score += 60;
                $reasons[] = 'municipio';
            }

            $typeSignal = self::typeSignal($costN . ' ' . $branchN);
            if ($typeSignal !== null && $type === $typeSignal) {
                $score += 35;
                $reasons[] = 'tipo_sede';
            }

            // Para auxiliares, el nombre de localidad puede estar contenido en textos como DISTRITO-KENNEDY.
            if ($type === 'auxiliar' && $searchName !== '' && self::containsWholePhrase($sedeText, $searchName)) {
                $score += 25;
                $reasons[] = 'localidad_auxiliar';
            }

            if ($score > 0) {
                $scored[] = ['score'=>$score, 'sede'=>$sede, 'reasons'=>$reasons];
            }
        }

        if ($scored !== []) {
            usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
            $bestScore = (int)$scored[0]['score'];
            $best = array_values(array_filter($scored, static fn(array $item): bool => (int)$item['score'] === $bestScore));
            if (count($best) === 1 && $bestScore >= 60) {
                $reasons = (array)$best[0]['reasons'];
                $isExact = in_array('nombre_sede_completo', $reasons, true)
                    || (in_array('tipo_sede', $reasons, true) && in_array('municipio', $reasons, true));
                return self::$cache[$cacheKey] = self::result(
                    $best[0]['sede'],
                    $isExact ? 'alta' : 'media',
                    false,
                    $isExact ? 'almacen_sede_exacta' : 'almacen_municipio_tipo',
                    $branch,
                    $costCenter,
                    $departmentCodes,
                    ['puntaje'=>$bestScore, 'evidencias'=>$reasons]
                );
            }
        }

        // 3. Municipio único: se permite cuando el texto identifica un solo municipio
        // y dentro de ese municipio existe una única sede operativa no delegación.
        $municipalCandidates = [];
        foreach ($rows as $sede) {
            $codDd = (string)($sede['_cod_dd'] ?? '');
            if ($departmentCodes !== [] && !in_array($codDd, $departmentCodes, true)) continue;
            $municipality = (string)($sede['_municipality'] ?? '');
            if ($municipality === '' || self::length($municipality) < 4 || !self::containsWholePhrase($sedeText, $municipality)) continue;
            $type = (string)($sede['_type'] ?? '');
            if (in_array($type, ['delegacion','can','distrital'], true)) continue;
            $municipalCandidates[(int)$sede['id']] = $sede;
        }
        if (count($municipalCandidates) === 1) {
            return self::$cache[$cacheKey] = self::result(
                array_values($municipalCandidates)[0],
                'media',
                false,
                'almacen_municipio_unico',
                $branch,
                $costCenter,
                $departmentCodes
            );
        }

        // 4A. BOGOTÁ-CENTRALES corresponde a la sede CAN.
        if (self::isBogotaCentrales($branchN, $costN)) {
            $can = $prepared['can'] ?? null;
            if (is_array($can)) {
                return self::$cache[$cacheKey] = self::result(
                    $can,
                    'alta',
                    false,
                    'almacen_bogota_centrales_can',
                    $branch,
                    $costCenter,
                    ['00']
                );
            }
        }

        // 4B. Bogotá Distrito sin auxiliar exacta se asigna a Registraduría Distrital.
        if (self::isBogotaDistrito($branchN, $costN) || in_array('16', $departmentCodes, true)) {
            $distrital = $prepared['distrital'] ?? null;
            if (is_array($distrital)) {
                return self::$cache[$cacheKey] = self::result(
                    $distrital,
                    'baja',
                    true,
                    'almacen_contingencia_distrital',
                    $branch,
                    $costCenter,
                    ['16'],
                    ['motivo_revision'=>'Sucursal/Centro de Costo identifica Bogotá Distrito, pero no una auxiliar única.']
                );
            }
        }

        // 4C. Si solo se reconoce el departamento, se asigna provisionalmente
        // a la Delegación Departamental correspondiente.
        if (count($departmentCodes) === 1) {
            $codDd = $departmentCodes[0];
            $delegations = $prepared['delegations']['dd:' . $codDd] ?? [];
            $delegation = self::principalDelegation(is_array($delegations) ? $delegations : []);
            if (is_array($delegation)) {
                return self::$cache[$cacheKey] = self::result(
                    $delegation,
                    'baja',
                    true,
                    'almacen_contingencia_delegacion_departamental',
                    $branch,
                    $costCenter,
                    $departmentCodes,
                    ['motivo_revision'=>'Solo fue posible identificar el departamento del activo. La asignación provisional prioriza la Delegación principal y excluye sedes de archivo.']
                );
            }
        }

        return self::$cache[$cacheKey] = self::unassigned(
            $branch,
            $costCenter,
            'almacen_sin_coincidencia_territorial',
            ['departamentos_detectados'=>$departmentCodes]
        );
    }


    /**
     * Selecciona la Delegación operativa principal. Algunos catálogos incluyen
     * una sede adicional de Archivo con el mismo departamento; esa sede no debe
     * utilizarse como contingencia territorial.
     *
     * @param array<int,array<string,mixed>> $delegations
     */
    private static function principalDelegation(array $delegations): ?array
    {
        if ($delegations === []) return null;
        $principal = array_values(array_filter($delegations, static function (array $sede): bool {
            $identifier = strtoupper(trim((string)($sede['identificador'] ?? '')));
            $name = self::normalize((string)($sede['nombre_sede'] ?? ''));
            return !str_ends_with($identifier, '-AR')
                && !str_contains($identifier, 'ARCH')
                && !str_contains($name, 'archivo');
        }));
        if (count($principal) === 1) return $principal[0];
        if (count($delegations) === 1) return $delegations[0];

        // Si existen varias sedes no marcadas como archivo, se prefiere la
        // nomenclatura departamental estándar DD-* sin sufijos adicionales.
        $standard = array_values(array_filter($principal, static function (array $sede): bool {
            $identifier = strtoupper(trim((string)($sede['identificador'] ?? '')));
            return preg_match('/^DD-[A-Z0-9]+$/', $identifier) === 1;
        }));
        return count($standard) === 1 ? $standard[0] : null;
    }

    /** @param array<string,mixed> $prepared */
    private static function departmentCodes(string $branchN, string $costN, array $prepared): array
    {
        if (self::isBogotaCentrales($branchN, $costN)) return ['00'];
        if (self::isBogotaDistrito($branchN, $costN)) return ['16'];

        // Nombre Sucursal representa el contexto territorial principal. Esto
        // evita confundir municipios homónimos (Nariño, Sucre, San Andrés) con
        // departamentos cuando aparecen en Nombre Centro de Costo.
        $branchMatches = self::departmentCodesFromSource($branchN, $prepared);
        if ($branchMatches !== []) return $branchMatches;

        // El Centro de Costo se utiliza para inferir el departamento solamente
        // cuando la Sucursal no aporta información territorial suficiente.
        return self::departmentCodesFromSource($costN, $prepared);
    }

    /** @param array<string,mixed> $prepared */
    private static function departmentCodesFromSource(string $source, array $prepared): array
    {
        if ($source === '') return [];
        $matches = [];
        $maxLength = 0;
        $aliases = is_array($prepared['department_aliases'] ?? null) ? $prepared['department_aliases'] : [];
        foreach ($aliases as $alias => $codes) {
            $alias = (string)$alias;
            if ($alias === '' || !self::containsWholePhrase($source, $alias)) continue;
            $length = self::length($alias);
            if ($length > $maxLength) {
                $matches = [];
                $maxLength = $length;
            }
            if ($length === $maxLength) {
                foreach ((array)$codes as $key => $code) $matches[(string)$key] = (string)$code;
            }
        }
        return array_values($matches);
    }

    /** @return array<int,string> */
    private static function departmentAliases(string $department): array
    {
        $aliases = [$department];
        $aliases[] = str_replace('la guajira', 'guajira', $department);
        if ($department === 'valle') $aliases[] = 'valle del cauca';
        if ($department === 'norte de santander') {
            $aliases[] = 'nte de santander';
            $aliases[] = 'n de santander';
        }
        if ($department === 'san andres') $aliases[] = 'san andres y providencia';
        return array_values(array_unique(array_filter($aliases)));
    }

    private static function typeSignal(string $text): ?string
    {
        return match (true) {
            str_contains($text, 'registraduria especial'), str_contains($text, 'reg especial') => 'especial',
            str_contains($text, 'registraduria municipal'), str_contains($text, 'reg municipal') => 'municipal',
            str_contains($text, 'auxiliar'), str_contains($text, 'distrito ') => 'auxiliar',
            str_contains($text, 'delegacion') => 'delegacion',
            str_contains($text, 'registraduria distrital') => 'distrital',
            default => null,
        };
    }

    private static function type(string $type, string $name): string
    {
        $value = self::normalize($type . ' ' . $name);
        return match (true) {
            str_contains($value, 'centro administrativo nacional') || preg_match('/(^| )can( |$)/', $value) === 1 => 'can',
            str_contains($value, 'distrital') => 'distrital',
            str_contains($value, 'delegacion') => 'delegacion',
            str_contains($value, 'auxiliar') || str_contains($value, 'punto atencion') => 'auxiliar',
            str_contains($value, 'especial') => 'especial',
            str_contains($value, 'municipal') => 'municipal',
            default => 'otro',
        };
    }

    private static function searchableSedeName(string $name): string
    {
        $name = preg_replace('/\bregistraduria\b|\bdelegacion\b|\bdepartamental\b|\bmunicipal\b|\bespecial\b|\bauxiliar(?:es)?\b/u', ' ', $name) ?? $name;
        $name = preg_replace('/\b[0-9]+\b/u', ' ', $name) ?? $name;
        return trim((string)(preg_replace('/\s+/', ' ', $name) ?? $name));
    }

    private static function isBogotaCentrales(string $branchN, string $costN): bool
    {
        return str_contains($branchN, 'bogota centrales')
            || $branchN === 'centrales'
            || str_contains($costN, 'centrales') && str_contains($branchN, 'bogota');
    }

    private static function isBogotaDistrito(string $branchN, string $costN): bool
    {
        return str_contains($branchN, 'bogota distrito')
            || str_starts_with($costN, 'distrito ')
            || str_contains($costN, 'registraduria distrital');
    }

    private static function result(
        array $sede,
        string $confidence,
        bool $reviewRequired,
        string $rule,
        string $branch,
        string $costCenter,
        array $departmentCodes,
        array $extra = []
    ): array {
        return [
            'sede_id' => (int)$sede['id'],
            'method' => 'warehouse',
            'confidence' => $confidence,
            'review_required' => $reviewRequired,
            'evidence' => array_merge([
                'rule' => $rule,
                'nombre_sucursal' => $branch,
                'nombre_centro_costo' => $costCenter,
                'departamentos_detectados' => $departmentCodes,
                'sede_identificada' => (string)($sede['identificador'] ?? ''),
                'departamento' => (string)($sede['departamento'] ?? ''),
                'municipio' => (string)($sede['municipio'] ?? ''),
                'nombre_sede' => (string)($sede['nombre_sede'] ?? ''),
            ], $extra),
        ];
    }

    private static function unassigned(string $branch, string $costCenter, string $rule, array $extra = []): array
    {
        return [
            'sede_id' => null,
            'method' => 'unassigned',
            'confidence' => 'sin_asignar',
            'review_required' => true,
            'evidence' => array_merge([
                'rule' => $rule,
                'nombre_sucursal' => $branch,
                'nombre_centro_costo' => $costCenter,
            ], $extra),
        ];
    }

    private static function normalizeCode(string $value): string
    {
        $digits = preg_replace('/\D+/', '', trim($value)) ?? '';
        return $digits === '' ? '' : str_pad($digits, 2, '0', STR_PAD_LEFT);
    }

    private static function containsWholePhrase(string $haystack, string $needle): bool
    {
        if ($haystack === '' || $needle === '') return false;
        return preg_match('/(^|\s)' . preg_quote($needle, '/') . '(\s|$)/u', $haystack) === 1;
    }

    private static function normalize(string $value): string
    {
        $value = trim($value);
        $value = str_replace(
            ['Á','É','Í','Ó','Ú','Ü','Ñ','á','é','í','ó','ú','ü','ñ'],
            ['A','E','I','O','U','U','N','a','e','i','o','u','u','n'],
            $value
        );
        $value = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        $value = trim((string)(preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? ''));
        $replacements = [
            'santader' => 'santander',
            'cundimarca' => 'cundinamarca',
            'vapues' => 'vaupes',
            'manizalez' => 'manizales',
            'cartajena' => 'cartagena',
            'rioacha' => 'riohacha',
        ];
        foreach ($replacements as $from => $to) {
            $value = preg_replace('/(^|\s)' . preg_quote($from, '/') . '(\s|$)/u', '$1' . $to . '$2', $value) ?? $value;
        }
        return trim((string)(preg_replace('/\s+/', ' ', $value) ?? $value));
    }

    private static function compact(string $value): string
    {
        return str_replace(' ', '', self::normalize($value));
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }
}
