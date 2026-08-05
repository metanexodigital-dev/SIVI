<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_sede_association.php
 * Propósito: Verifica automáticamente que la funcionalidad «sede association» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/SedeAssociator.php';

$root = dirname(__DIR__);
$version = trim((string)@file_get_contents($root . '/VERSION'));

$sedes = [
    ['id'=>1,'identificador'=>'DD-MD','cod_dd'=>'01','departamento'=>'Antioquia','municipio'=>'Medellín','tipo_sede'=>'Delegación','nombre_sede'=>'Delegacion Departamental'],
    ['id'=>10,'identificador'=>'DD-MD-AR','cod_dd'=>'01','departamento'=>'Antioquia','municipio'=>'Medellín','tipo_sede'=>'Delegación','nombre_sede'=>'Delegacion Departamental - Archivo'],
    ['id'=>2,'identificador'=>'RM-01-004','cod_dd'=>'01','departamento'=>'Antioquia','municipio'=>'Abejorral','tipo_sede'=>'Municipal','nombre_sede'=>'Registraduria Municipal Abejorral'],
    ['id'=>9,'identificador'=>'RM-01-007','cod_dd'=>'01','departamento'=>'Antioquia','municipio'=>'Abriaqui','tipo_sede'=>'Municipal','nombre_sede'=>'Registraduria Municipal Abriaqui'],
    ['id'=>3,'identificador'=>'RA-ANT-BE','cod_dd'=>'01','departamento'=>'Antioquia','municipio'=>'Medellín','tipo_sede'=>'Auxiliares','nombre_sede'=>'Belen'],
    ['id'=>4,'identificador'=>'RA-ANT-BO','cod_dd'=>'01','departamento'=>'Antioquia','municipio'=>'Medellín','tipo_sede'=>'Auxiliares','nombre_sede'=>'Bosque'],
    ['id'=>5,'identificador'=>'DD-MT','cod_dd'=>'13','departamento'=>'Cordoba','municipio'=>'Montería','tipo_sede'=>'Delegación','nombre_sede'=>'Delegacion Departamental'],
    ['id'=>6,'identificador'=>'RM-13-001','cod_dd'=>'13','departamento'=>'Cordoba','municipio'=>'Montería','tipo_sede'=>'Municipal','nombre_sede'=>'Registraduria Municipal Monteria'],
    ['id'=>7,'identificador'=>'RD-REGDIST','cod_dd'=>'00','departamento'=>'Distrito','municipio'=>'Bogotá D.C','tipo_sede'=>'Distrital','nombre_sede'=>'Registraduría Distrital'],
    ['id'=>8,'identificador'=>'AB-US','cod_dd'=>'00','departamento'=>'Distrito','municipio'=>'Bogotá D.C','tipo_sede'=>'Auxiliares Bogotá','nombre_sede'=>'Usme'],
];

$cases = [
    'hostname_prioritario' => [
        ['RM-01-004-PC01', 'usuario Monteria Cordoba', 'Registradurias Municipales / Cordoba'],
        ['sede_id'=>2,'method'=>'hostname','confidence'=>'alta','review_required'=>false],
    ],
    'usuario_municipio_con_nomenclatura' => [
        ['RM-01-999-PC01', 'abejorral@registraduria.gov.co', 'Registradurias Municipales'],
        ['sede_id'=>2,'method'=>'usuario','confidence'=>'media','review_required'=>false],
    ],
    'usuario_prioritario_sobre_localizacion' => [
        ['EQUIPO-SIN-NOMENCLATURA', 'abejorral antioquia', 'Registradurias Municipales / Cordoba'],
        ['sede_id'=>2,'method'=>'usuario','confidence'=>'media','review_required'=>false],
    ],
    'cruce_usuario_localizacion' => [
        ['EQUIPO-SIN-CODIGO', 'monteria.usuario', 'Registradurias Municipales / Cordoba'],
        ['sede_id'=>6,'method'=>'location','confidence'=>'media','review_required'=>false],
    ],
    'ab_a_distrital' => [
        ['AB-XX-PC01', '', 'Auxiliares Bogota'],
        ['sede_id'=>7,'method'=>'fallback_distrital','confidence'=>'baja','review_required'=>true],
    ],
    'rm_a_delegacion' => [
        ['RM-01-999-PC01', '', 'Registradurias Municipales / Antioquia'],
        ['sede_id'=>1,'method'=>'fallback_delegacion','confidence'=>'baja','review_required'=>true],
    ],
    'ra_a_delegacion' => [
        ['RA-ANT-XX-PC01', 'usuario medellin', 'Registradurias Auxiliares / Antioquia'],
        ['sede_id'=>1,'method'=>'fallback_delegacion','confidence'=>'baja','review_required'=>true],
    ],
    'ra_codigo_departamento_numerico' => [
        ['RA-IB-20791', 'usuario sin municipio', 'Registradurias Auxiliares / Cordoba'],
        ['sede_id'=>5,'method'=>'fallback_delegacion','confidence'=>'baja','review_required'=>true],
    ],
    'ra_usuario_define_departamento' => [
        ['RA-IB-20792', 'monteria cordoba', ''],
        ['sede_id'=>5,'method'=>'fallback_delegacion','confidence'=>'baja','review_required'=>true],
    ],
    'ra_nomenclatura_prevalece_conflicto' => [
        ['RA-ANT-XX-PC01', 'monteria cordoba', 'Registradurias Auxiliares / Cordoba'],
        ['sede_id'=>1,'method'=>'fallback_delegacion','confidence'=>'baja','review_required'=>true],
    ],
    'usuario_prioritario_aunque_nomenclatura_discrepe' => [
        ['RM-01-999-PC01', 'monteria cordoba', 'Registradurias Municipales / Cordoba'],
        ['sede_id'=>6,'method'=>'usuario','confidence'=>'media','review_required'=>false],
    ],
    'usuario_solo_municipio_no_fuerza_sede' => [
        ['EQUIPO-SIN-NOMENCLATURA', 'abejorral@registraduria.gov.co', ''],
        ['sede_id'=>null,'method'=>'unassigned','confidence'=>'sin_asignar','review_required'=>true],
    ],
    'sin_coincidencia' => [
        ['EQUIPO-SIN-DATOS', '', ''],
        ['sede_id'=>null,'method'=>'unassigned','confidence'=>'sin_asignar','review_required'=>true],
    ],
];

$results = [];
$ok = true;
$preparedSedes = SedeAssociator::prepare($sedes);
foreach ($cases as $name => [$input, $expected]) {
    $actual = SedeAssociator::associatePrepared($input[0], $input[1], $input[2], $preparedSedes);
    $projection = [
        'sede_id' => $actual['sede_id'],
        'method' => $actual['method'],
        'confidence' => $actual['confidence'],
        'review_required' => $actual['review_required'],
    ];
    $caseOk = $projection === $expected;
    $ok = $ok && $caseOk;
    $results[$name] = ['ok'=>$caseOk,'expected'=>$expected,'actual'=>$projection,'rule'=>$actual['evidence']['rule'] ?? null,'resolved_stage'=>$actual['evidence']['etapa_resuelta'] ?? null,'department_source'=>$actual['evidence']['fuente_departamento'] ?? null];
}

echo json_encode([
    'ok' => $ok,
    'version' => $version,
    'check' => 'sede_association_hierarchy_' . $version,
    'cases' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 2);
