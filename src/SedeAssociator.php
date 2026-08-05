<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/SedeAssociator.php
 * Propósito: Aplica reglas para identificar y asociar equipos con la sede correspondiente.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

/**
 * Resuelve la sede probable de un activo usando las fuentes institucionales
 * en el orden definido para SIVI:
 *
 * 1. Coincidencia del hostname con el identificador oficial de la sede.
 * 2. Información territorial aportada por el usuario GLPI.
 * 3. Localización GLPI y cruce con la información del usuario/nomenclatura.
 * 4. Reglas de contingencia para AB, RM y RA.
 */
final class SedeAssociator
{
    /**
     * Prepara una sola vez el catálogo de sedes para importaciones masivas.
     *
     * @param array<int,array<string,mixed>> $sedes
     * @return array<int,array<string,mixed>>
     */
    public static function prepare(array $sedes): array
    {
        return self::prepareSedes($sedes);
    }

    /**
     * Punto de compatibilidad para asociaciones individuales.
     *
     * @param array<int,array<string,mixed>> $sedes
     * @return array{sede_id:?int,method:string,confidence:string,review_required:bool,evidence:array<string,mixed>}
     */
    public static function associate(string $hostname, string $alternateUser, string $glpiLocation, array $sedes): array
    {
        return self::associatePrepared(
            $hostname,
            $alternateUser,
            $glpiLocation,
            self::prepareSedes($sedes)
        );
    }

    /**
     * Ejecuta la jerarquía institucional sobre un catálogo previamente preparado.
     * Este método evita normalizar las 1.260 sedes por cada elemento del reporte GLPI.
     *
     * @param array<int,array<string,mixed>> $prepared
     * @return array{sede_id:?int,method:string,confidence:string,review_required:bool,evidence:array<string,mixed>}
     */
    public static function associatePrepared(string $hostname, string $alternateUser, string $glpiLocation, array $prepared): array
    {

        $hostnameUpper = strtoupper(trim($hostname));
        $userNormalized = self::normalize($alternateUser);
        $userCompact = self::compact($alternateUser);
        $locationNormalized = self::normalize($glpiLocation);
        $nomenclature = self::nomenclature($hostnameUpper, $prepared);

        // 1. El hostname es la fuente prioritaria y debe coincidir con el
        // identificador oficial de una sede. Se elige el identificador más largo
        // para evitar que un prefijo corto opaque una sede más específica.
        $hostnameMatches = [];
        foreach ($prepared as $sede) {
            if (self::hostnameMatchesIdentifier($hostnameUpper, $sede['_identifier_upper'])) {
                $hostnameMatches[] = $sede;
            }
        }
        if ($hostnameMatches !== []) {
            usort($hostnameMatches, static fn(array $a, array $b): int => strlen($b['_identifier_upper']) <=> strlen($a['_identifier_upper']));
            $bestLength = strlen($hostnameMatches[0]['_identifier_upper']);
            $best = array_values(array_filter($hostnameMatches, static fn(array $s): bool => strlen($s['_identifier_upper']) === $bestLength));
            if (count($best) === 1) {
                return self::result($best[0], 'hostname', 'alta', false, 'hostname_exacto', [
                    'hostname' => $hostname,
                    'identificador_detectado' => $best[0]['identificador'],
                    'nomenclatura' => $nomenclature,
                ]);
            }
        }

        // 2A. El usuario puede contener explícitamente el identificador oficial.
        $userIdentifierMatches = [];
        $userUpper = strtoupper(trim($alternateUser));
        foreach ($prepared as $sede) {
            $identifier = $sede['_identifier_upper'];
            if ($identifier !== '' && preg_match('/(^|[^A-Z0-9])' . preg_quote($identifier, '/') . '([^A-Z0-9]|$)/', $userUpper) === 1) {
                $userIdentifierMatches[] = $sede;
            }
        }
        if (count($userIdentifierMatches) === 1) {
            return self::result($userIdentifierMatches[0], 'usuario', 'alta', false, 'usuario_identificador_exacto', [
                'usuario' => $alternateUser,
                'identificador_detectado' => $userIdentifierMatches[0]['identificador'],
                'nomenclatura' => $nomenclature,
            ]);
        }

        $userDepartments = self::departmentCodesInText($userNormalized, $userCompact, $prepared);
        $userMunicipalities = self::municipalitiesInText($userNormalized, $userCompact, $prepared);

        // 2B. Posible coincidencia territorial aportada por el usuario.
        // Después de descartar una coincidencia directa del hostname, el usuario
        // se evalúa como segunda fuente y no debe ser invalidado por una
        // nomenclatura incompleta o mal formada. El código del hostname solo se
        // usa para complementar cuando el usuario no aporta departamento.
        $userCandidateDepartments = $userDepartments !== []
            ? $userDepartments
            : ($nomenclature['department_code'] !== null ? [$nomenclature['department_code']] : []);
        $userCandidates = self::filterCandidates(
            $prepared,
            $userCandidateDepartments,
            $userMunicipalities,
            $nomenclature['sede_type']
        );
        $userHasTerritorialContext = $userDepartments !== [] || $nomenclature['department_code'] !== null;
        if (count($userCandidates) === 1 && $userMunicipalities !== [] && $userHasTerritorialContext) {
            return self::result($userCandidates[0], 'usuario', 'media', false, 'usuario_municipio_departamento', [
                'usuario' => $alternateUser,
                'departamentos_detectados' => $userDepartments,
                'municipios_detectados' => array_keys($userMunicipalities),
                'nomenclatura' => $nomenclature,
            ]);
        }

        // 3. La Localización GLPI aporta principalmente el departamento, pero
        // también se aprovechan municipio y tipo de sede cuando están presentes.
        $locationDepartments = self::departmentCodesInText($locationNormalized, self::compact($glpiLocation), $prepared);
        $locationMunicipalities = self::municipalitiesInText($locationNormalized, self::compact($glpiLocation), $prepared);
        $locationType = self::locationType($locationNormalized);

        $departmentCodes = self::crossDepartmentSignals(
            $nomenclature['department_code'],
            $userDepartments,
            $locationDepartments
        );
        $municipalityNames = self::crossMunicipalitySignals($userMunicipalities, $locationMunicipalities);
        $effectiveType = $nomenclature['sede_type'] ?? $locationType;

        // En la etapa operativa solo una contradicción entre usuario y
        // Localización GLPI impide forzar una sede. La nomenclatura se reserva
        // para complementar o para las reglas finales de contingencia.
        $departmentConflict = self::hasOperationalDepartmentConflict(
            $userDepartments,
            $locationDepartments
        );
        $crossCandidates = $departmentConflict
            ? []
            : self::filterCandidates($prepared, $departmentCodes, $municipalityNames, $effectiveType);
        if (count($crossCandidates) === 1 && $departmentCodes !== []) {
            return self::result($crossCandidates[0], 'location', 'media', false, 'cruce_usuario_localizacion_glpi', [
                'usuario' => $alternateUser,
                'localizacion_glpi' => $glpiLocation,
                'departamentos_usuario' => $userDepartments,
                'departamentos_localizacion' => $locationDepartments,
                'municipios_usuario' => array_keys($userMunicipalities),
                'municipios_localizacion' => array_keys($locationMunicipalities),
                'tipo_localizacion' => $locationType,
                'nomenclatura' => $nomenclature,
            ]);
        }

        // Cuando la localización por sí sola identifica departamento, municipio
        // y tipo de sede de manera unívoca, se acepta como asociación media.
        $locationCandidates = $departmentConflict
            ? []
            : self::filterCandidates($prepared, $locationDepartments, $locationMunicipalities, $locationType);
        if (count($locationCandidates) === 1 && $locationDepartments !== []) {
            return self::result($locationCandidates[0], 'location', 'media', false, 'localizacion_glpi_univoca', [
                'localizacion_glpi' => $glpiLocation,
                'departamentos_detectados' => $locationDepartments,
                'municipios_detectados' => array_keys($locationMunicipalities),
                'tipo_localizacion' => $locationType,
                'nomenclatura' => $nomenclature,
            ]);
        }

        // 4A. Auxiliares Bogotá (AB-*): ante ambigüedad se asignan a la
        // Registraduría Distrital y quedan marcadas para revisión.
        if ($nomenclature['family'] === 'AB') {
            $distrital = self::uniqueDistrital($prepared);
            if ($distrital !== null) {
                return self::result($distrital, 'fallback_distrital', 'baja', true, 'contingencia_ab_registraduria_distrital', [
                    'hostname' => $hostname,
                    'usuario' => $alternateUser,
                    'localizacion_glpi' => $glpiLocation,
                    'nomenclatura' => $nomenclature,
                    'motivo_revision' => 'No fue posible identificar una auxiliar de Bogotá de manera unívoca.',
                ]);
            }
        }

        // 4B. Registradurías Municipales (RM-{cod departamento}): si no fue
        // posible identificar el municipio, se asignan a la Delegación del
        // departamento indicado por la nomenclatura.
        if ($nomenclature['family'] === 'RM' && $nomenclature['department_code'] !== null) {
            $delegation = self::uniqueDelegation($prepared, $nomenclature['department_code']);
            if ($delegation !== null) {
                return self::result($delegation, 'fallback_delegacion', 'baja', true, 'contingencia_rm_delegacion_departamental', [
                    'hostname' => $hostname,
                    'usuario' => $alternateUser,
                    'localizacion_glpi' => $glpiLocation,
                    'nomenclatura' => $nomenclature,
                    'motivo_revision' => 'No fue posible identificar el municipio de la Registraduría Municipal.',
                ]);
            }
        }

        // 4C. Registradurías Auxiliares (RA-*): cuando no se identifica la
        // auxiliar exacta, se determina primero el departamento por la propia
        // nomenclatura RA; si no es posible, se usan usuario y Localización GLPI.
        // Una discrepancia en fuentes secundarias no anula un departamento
        // reconocido de manera unívoca por el prefijo oficial del hostname.
        if ($nomenclature['family'] === 'RA' || $locationType === 'auxiliar') {
            $auxDepartment = self::fallbackDepartmentSignal(
                $nomenclature['department_code'],
                $userDepartments,
                $locationDepartments
            );
            if ($auxDepartment['code'] !== null) {
                $delegation = self::uniqueDelegation($prepared, $auxDepartment['code']);
                if ($delegation !== null) {
                    return self::result($delegation, 'fallback_delegacion', 'baja', true, 'contingencia_ra_delegacion_departamental', [
                        'hostname' => $hostname,
                        'usuario' => $alternateUser,
                        'localizacion_glpi' => $glpiLocation,
                        'departamento_detectado' => $auxDepartment['code'],
                        'fuente_departamento' => $auxDepartment['source'],
                        'conflicto_fuentes' => $auxDepartment['conflict'],
                        'departamentos_usuario' => $userDepartments,
                        'departamentos_localizacion' => $locationDepartments,
                        'nomenclatura' => $nomenclature,
                        'motivo_revision' => 'No fue posible identificar la Registraduría Auxiliar exacta; se asignó provisionalmente a la Delegación Departamental.',
                    ]);
                }
            }
        }

        return [
            'sede_id' => null,
            'method' => 'unassigned',
            'confidence' => 'sin_asignar',
            'review_required' => true,
            'evidence' => [
                'rule' => 'sin_coincidencia',
                'orden_validacion' => self::validationOrder(),
                'etapa_resuelta' => 'sin_asignar',
                'hostname' => $hostname,
                'usuario' => $alternateUser,
                'localizacion_glpi' => $glpiLocation,
                'nomenclatura' => $nomenclature,
                'departamentos_usuario' => $userDepartments,
                'departamentos_localizacion' => $locationDepartments,
                'municipios_usuario' => array_keys($userMunicipalities),
                'municipios_localizacion' => array_keys($locationMunicipalities),
            ],
        ];
    }

    /** @param array<int,array<string,mixed>> $sedes */
    private static function prepareSedes(array $sedes): array
    {
        $prepared = [];
        foreach ($sedes as $sede) {
            $sede['_identifier_upper'] = strtoupper(trim((string)($sede['identificador'] ?? '')));
            $sede['_department'] = self::normalize((string)($sede['departamento'] ?? ''));
            $sede['_department_compact'] = self::compact((string)($sede['departamento'] ?? ''));
            $sede['_municipality'] = self::normalize((string)($sede['municipio'] ?? ''));
            $sede['_municipality_compact'] = self::compact((string)($sede['municipio'] ?? ''));
            $sede['_type'] = self::normalizedSedeType($sede);
            $sede['_cod_dd'] = self::normalizeDepartmentCode((string)($sede['cod_dd'] ?? ''));
            $prepared[] = $sede;
        }
        return $prepared;
    }

    private static function hostnameMatchesIdentifier(string $hostname, string $identifier): bool
    {
        if ($hostname === '' || $identifier === '') return false;
        return $hostname === $identifier
            || str_starts_with($hostname, $identifier . '-')
            || str_starts_with($hostname, $identifier . '_')
            || preg_match('/^' . preg_quote($identifier, '/') . '[^A-Z0-9]/', $hostname) === 1;
    }

    /** @param array<int,array<string,mixed>> $sedes */
    private static function nomenclature(string $hostname, array $sedes): array
    {
        $family = null;
        $departmentCode = null;
        $sedeType = null;
        $raPrefix = null;

        if (preg_match('/^AB(?:-|_|$)/', $hostname) === 1) {
            $family = 'AB';
            $sedeType = 'auxiliar_bogota';
        } elseif (preg_match('/^RM[-_](\d{1,3})(?:[-_]|$)/', $hostname, $match) === 1) {
            $family = 'RM';
            $departmentCode = self::normalizeDepartmentCode($match[1]);
            $sedeType = 'municipal';
        } elseif (preg_match('/^RA[-_]([A-Z0-9]{2,5})(?:[-_]|$)/', $hostname, $match) === 1) {
            $family = 'RA';
            $raPrefix = 'RA-' . strtoupper($match[1]) . '-';
            $sedeType = 'auxiliar';
            $codes = [];
            foreach ($sedes as $sede) {
                if (str_starts_with($sede['_identifier_upper'], $raPrefix) && $sede['_cod_dd'] !== '') {
                    // El prefijo evita que PHP convierta códigos como "13" en claves enteras.
                    $codes['dd:' . $sede['_cod_dd']] = $sede['_cod_dd'];
                }
            }
            if (count($codes) === 1) $departmentCode = (string)array_values($codes)[0];
        } elseif (preg_match('/^RE(?:-|_|$)/', $hostname) === 1) {
            $family = 'RE';
            $sedeType = 'especial';
        }

        return [
            'family' => $family,
            'department_code' => $departmentCode,
            'sede_type' => $sedeType,
            'ra_prefix' => $raPrefix,
        ];
    }

    /** @param array<int,array<string,mixed>> $sedes */
    private static function departmentCodesInText(string $normalized, string $compact, array $sedes): array
    {
        if ($normalized === '' && $compact === '') return [];
        $matches = [];
        foreach ($sedes as $sede) {
            $code = $sede['_cod_dd'];
            $department = $sede['_department'];
            $departmentCompact = $sede['_department_compact'];
            if ($code === '' || $department === '' || self::length($department) < 3) continue;
            if (self::containsWholePhrase($normalized, $department)
                || ($departmentCompact !== '' && self::length($departmentCompact) >= 5 && str_contains($compact, $departmentCompact))) {
                // No usar el código como clave directa: PHP convierte cadenas numéricas
                // sin cero inicial (por ejemplo, "13") en enteros.
                $matches['dd:' . $code] = $code;
            }
        }
        return array_values($matches);
    }

    /**
     * @param array<int,array<string,mixed>> $sedes
     * @return array<string,bool>
     */
    private static function municipalitiesInText(string $normalized, string $compact, array $sedes): array
    {
        if ($normalized === '' && $compact === '') return [];
        $matches = [];
        foreach ($sedes as $sede) {
            $municipality = $sede['_municipality'];
            $municipalityCompact = $sede['_municipality_compact'];
            if ($municipality === '' || self::length($municipality) < 3) continue;
            if (self::containsWholePhrase($normalized, $municipality)
                || ($municipalityCompact !== '' && self::length($municipalityCompact) >= 5 && str_contains($compact, $municipalityCompact))) {
                $matches[$municipality] = true;
            }
        }
        return $matches;
    }

    /**
     * @param array<int,array<string,mixed>> $sedes
     * @param array<int,string> $departmentCodes
     * @param array<string,bool> $municipalities
     * @return array<int,array<string,mixed>>
     */
    private static function filterCandidates(array $sedes, array $departmentCodes, array $municipalities, ?string $sedeType): array
    {
        $departmentCodes = self::normalizeDepartmentCodes($departmentCodes);
        $departmentLookup = [];
        foreach ($departmentCodes as $departmentCode) {
            $departmentLookup['dd:' . $departmentCode] = true;
        }
        $out = [];
        foreach ($sedes as $sede) {
            if ($departmentLookup !== [] && !isset($departmentLookup['dd:' . $sede['_cod_dd']])) continue;
            if ($municipalities !== [] && !isset($municipalities[$sede['_municipality']])) continue;
            if ($sedeType !== null && !self::typeMatches($sede['_type'], $sedeType)) continue;
            $out[(int)$sede['id']] = $sede;
        }
        return array_values($out);
    }

    /** @param array<int,string> $userDepartments @param array<int,string> $locationDepartments */
    private static function hasDepartmentConflict(?string $nomenclatureCode, array $userDepartments, array $locationDepartments): bool
    {
        $userDepartments = self::normalizeDepartmentCodes($userDepartments);
        $locationDepartments = self::normalizeDepartmentCodes($locationDepartments);
        $nomenclatureCode = $nomenclatureCode !== null ? self::normalizeDepartmentCode($nomenclatureCode) : null;
        if ($nomenclatureCode !== null && $nomenclatureCode !== '') {
            if ($userDepartments !== [] && !in_array($nomenclatureCode, $userDepartments, true)) return true;
            if ($locationDepartments !== [] && !in_array($nomenclatureCode, $locationDepartments, true)) return true;
        }
        return $userDepartments !== []
            && $locationDepartments !== []
            && array_intersect($userDepartments, $locationDepartments) === [];
    }

    /** @param array<int,string> $userDepartments @param array<int,string> $locationDepartments */
    private static function crossDepartmentSignals(?string $nomenclatureCode, array $userDepartments, array $locationDepartments): array
    {
        $userDepartments = self::normalizeDepartmentCodes($userDepartments);
        $locationDepartments = self::normalizeDepartmentCodes($locationDepartments);
        $nomenclatureCode = $nomenclatureCode !== null ? self::normalizeDepartmentCode($nomenclatureCode) : null;

        // El cruce de las fuentes GLPI prevalece sobre una nomenclatura que no
        // logró coincidir directamente con el maestro de sedes.
        if ($userDepartments !== [] && $locationDepartments !== []) {
            return array_values(array_unique(array_intersect($userDepartments, $locationDepartments)));
        }
        if ($userDepartments !== []) {
            return array_values(array_unique($userDepartments));
        }
        if ($locationDepartments !== []) {
            return array_values(array_unique($locationDepartments));
        }
        if ($nomenclatureCode !== null && $nomenclatureCode !== '') {
            return [$nomenclatureCode];
        }
        return [];
    }

    /** @param array<int,string> $userDepartments @param array<int,string> $locationDepartments */
    private static function hasOperationalDepartmentConflict(array $userDepartments, array $locationDepartments): bool
    {
        $userDepartments = self::normalizeDepartmentCodes($userDepartments);
        $locationDepartments = self::normalizeDepartmentCodes($locationDepartments);
        return $userDepartments !== []
            && $locationDepartments !== []
            && array_intersect($userDepartments, $locationDepartments) === [];
    }

    /**
     * Selecciona el departamento para una regla de contingencia.
     *
     * Prioridad:
     * 1. Código derivado de la nomenclatura oficial del hostname.
     * 2. Coincidencia entre usuario y Localización GLPI.
     * 3. Único departamento identificado en el usuario.
     * 4. Único departamento identificado en la Localización GLPI.
     *
     * @param array<int,string> $userDepartments
     * @param array<int,string> $locationDepartments
     * @return array{code:?string,source:string,conflict:bool}
     */
    private static function fallbackDepartmentSignal(?string $nomenclatureCode, array $userDepartments, array $locationDepartments): array
    {
        $userDepartments = self::normalizeDepartmentCodes($userDepartments);
        $locationDepartments = self::normalizeDepartmentCodes($locationDepartments);
        $nomenclatureCode = $nomenclatureCode !== null
            ? self::normalizeDepartmentCode($nomenclatureCode)
            : null;

        $conflict = self::hasDepartmentConflict($nomenclatureCode, $userDepartments, $locationDepartments);

        if ($nomenclatureCode !== null && $nomenclatureCode !== '') {
            return ['code' => $nomenclatureCode, 'source' => 'nomenclatura_hostname', 'conflict' => $conflict];
        }

        if ($userDepartments !== [] && $locationDepartments !== []) {
            $intersection = array_values(array_unique(array_intersect($userDepartments, $locationDepartments)));
            if (count($intersection) === 1) {
                return ['code' => $intersection[0], 'source' => 'coincidencia_usuario_localizacion', 'conflict' => false];
            }
        }

        if (count($userDepartments) === 1) {
            return ['code' => $userDepartments[0], 'source' => 'usuario_glpi', 'conflict' => $locationDepartments !== [] && !in_array($userDepartments[0], $locationDepartments, true)];
        }

        if (count($locationDepartments) === 1) {
            return ['code' => $locationDepartments[0], 'source' => 'localizacion_glpi', 'conflict' => $userDepartments !== [] && !in_array($locationDepartments[0], $userDepartments, true)];
        }

        return ['code' => null, 'source' => 'sin_departamento_univoco', 'conflict' => $conflict];
    }

    /** @param array<string,bool> $userMunicipalities @param array<string,bool> $locationMunicipalities */
    private static function crossMunicipalitySignals(array $userMunicipalities, array $locationMunicipalities): array
    {
        if ($userMunicipalities !== [] && $locationMunicipalities !== []) {
            $intersection = array_intersect_key($userMunicipalities, $locationMunicipalities);
            if ($intersection !== []) return $intersection;
            // Si las fuentes discrepan, no se fuerza una sede específica.
            return [];
        }
        return $userMunicipalities !== [] ? $userMunicipalities : $locationMunicipalities;
    }

    /**
     * @param array<int,mixed> $codes
     * @return array<int,string>
     */
    private static function normalizeDepartmentCodes(array $codes): array
    {
        $normalized = [];
        $seen = [];
        foreach ($codes as $value) {
            if (!is_string($value) && !is_int($value) && !is_float($value)) continue;
            $code = self::normalizeDepartmentCode($value);
            if ($code === '' || isset($seen[$code])) continue;
            $seen[$code] = true;
            $normalized[] = $code;
        }
        return $normalized;
    }

    /** @param array<int,array<string,mixed>> $sedes */
    private static function uniqueDistrital(array $sedes): ?array
    {
        $matches = array_values(array_filter($sedes, static function (array $sede): bool {
            return $sede['_type'] === 'distrital' || str_starts_with($sede['_identifier_upper'], 'RD-');
        }));
        if (count($matches) === 1) return $matches[0];

        // Si el catálogo incorpora archivos, bodegas u oficinas complementarias,
        // se conserva como contingencia la sede principal de la Registraduría Distrital.
        $primary = array_values(array_filter($matches, static function (array $sede): bool {
            $name = self::normalize((string)($sede['nombre_sede'] ?? ''));
            $identifier = (string)($sede['_identifier_upper'] ?? '');
            return !str_contains($name, 'archivo')
                && !str_contains($name, 'bodega')
                && preg_match('/^RD-[A-Z0-9-]+$/', $identifier) === 1;
        }));
        return count($primary) === 1 ? $primary[0] : null;
    }

    /** @param array<int,array<string,mixed>> $sedes */
    private static function uniqueDelegation(array $sedes, string|int|float $departmentCode): ?array
    {
        $code = self::normalizeDepartmentCode($departmentCode);
        $matches = array_values(array_filter($sedes, static function (array $sede) use ($code): bool {
            return $sede['_cod_dd'] === $code && $sede['_type'] === 'delegacion';
        }));
        if (count($matches) === 1) return $matches[0];

        // Algunos catálogos incluyen dependencias complementarias de la
        // Delegación (por ejemplo, Archivo). Se prioriza la sede principal.
        $primary = array_values(array_filter($matches, static function (array $sede): bool {
            $name = self::normalize((string)($sede['nombre_sede'] ?? ''));
            $identifier = (string)($sede['_identifier_upper'] ?? '');
            return !str_contains($name, 'archivo')
                && !str_contains($name, 'bodega')
                && preg_match('/^DD-[A-Z0-9]{2,5}$/', $identifier) === 1;
        }));
        return count($primary) === 1 ? $primary[0] : null;
    }

    private static function normalizedSedeType(array $sede): string
    {
        $value = self::normalize((string)($sede['tipo_sede'] ?? '') . ' ' . (string)($sede['nombre_sede'] ?? ''));
        return match (true) {
            str_contains($value, 'auxiliares bogota') => 'auxiliar_bogota',
            str_contains($value, 'delegacion') => 'delegacion',
            str_contains($value, 'distrital') => 'distrital',
            str_contains($value, 'municipal') => 'municipal',
            str_contains($value, 'especial') => 'especial',
            str_contains($value, 'auxiliar') => 'auxiliar',
            str_contains($value, 'can') => 'can',
            default => 'otro',
        };
    }

    private static function locationType(string $location): ?string
    {
        if ($location === '') return null;
        return match (true) {
            str_contains($location, 'auxiliares bogota') => 'auxiliar_bogota',
            str_contains($location, 'registradurias municipales') || str_contains($location, 'registraduria municipal') => 'municipal',
            str_contains($location, 'registradurias especiales') || str_contains($location, 'registraduria especial') => 'especial',
            str_contains($location, 'registradurias auxiliares') || str_contains($location, 'registraduria auxiliar') => 'auxiliar',
            str_contains($location, 'registraduria distrital') => 'distrital',
            str_contains($location, 'delegacion') => 'delegacion',
            default => null,
        };
    }

    private static function typeMatches(string $actual, string $expected): bool
    {
        if ($actual === $expected) return true;
        return $expected === 'auxiliar' && $actual === 'auxiliar_bogota';
    }

    private static function normalizeDepartmentCode(string|int|float $value): string
    {
        $value = trim((string)$value);
        if ($value === '' || !ctype_digit($value)) return $value;
        return str_pad((string)(int)$value, 2, '0', STR_PAD_LEFT);
    }

    private static function normalize(string $value): string
    {
        $value = trim($value);
        $value = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        $value = str_replace(['á','é','í','ó','ú','ü','ñ'], ['a','e','i','o','u','u','n'], $value);
        return trim((string)(preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? ''));
    }

    private static function compact(string $value): string
    {
        return str_replace(' ', '', self::normalize($value));
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    private static function containsWholePhrase(string $haystack, string $needle): bool
    {
        if ($haystack === '' || $needle === '') return false;
        return preg_match('/(^|\s)' . preg_quote($needle, '/') . '(\s|$)/u', $haystack) === 1;
    }

    /** @return array<int,string> */
    private static function validationOrder(): array
    {
        return [
            'hostname_base_sedes',
            'usuario_glpi_municipio_departamento',
            'localizacion_glpi_y_cruce_fuentes',
            'contingencia_ab_rm_ra',
        ];
    }

    /** @return array{sede_id:int,method:string,confidence:string,review_required:bool,evidence:array<string,mixed>} */
    private static function result(array $sede, string $method, string $confidence, bool $reviewRequired, string $rule, array $evidence): array
    {
        $evidence['rule'] = $rule;
        $evidence['orden_validacion'] = self::validationOrder();
        $evidence['etapa_resuelta'] = match ($method) {
            'hostname' => 'hostname_base_sedes',
            'usuario' => 'usuario_glpi_municipio_departamento',
            'location' => 'localizacion_glpi_y_cruce_fuentes',
            'fallback_distrital', 'fallback_delegacion' => 'contingencia_ab_rm_ra',
            default => $method,
        };
        $evidence['sede_resultado'] = [
            'id' => (int)$sede['id'],
            'identificador' => (string)($sede['identificador'] ?? ''),
            'departamento' => (string)($sede['departamento'] ?? ''),
            'municipio' => (string)($sede['municipio'] ?? ''),
            'tipo_sede' => (string)($sede['tipo_sede'] ?? ''),
            'nombre_sede' => (string)($sede['nombre_sede'] ?? ''),
        ];
        return [
            'sede_id' => (int)$sede['id'],
            'method' => $method,
            'confidence' => $confidence,
            'review_required' => $reviewRequired,
            'evidence' => $evidence,
        ];
    }
}
