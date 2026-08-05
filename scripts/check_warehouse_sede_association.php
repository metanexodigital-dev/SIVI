<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_warehouse_sede_association.php
 * Propósito: Verifica automáticamente que la funcionalidad «warehouse sede association» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/WarehouseSedeAssociator.php';

$root = dirname(__DIR__);
$version = trim((string)@file_get_contents($root . '/VERSION'));

$sedes = [
    ['id'=>1,'identificador'=>'CAN','cod_dd'=>'00','departamento'=>'CAN','municipio'=>'Bogotá D.C','tipo_sede'=>'CAN','nombre_sede'=>'Centro Administrativo Nacional'],
    ['id'=>2,'identificador'=>'RD-REGDIST','cod_dd'=>'16','departamento'=>'Distrito','municipio'=>'Bogotá D.C','tipo_sede'=>'Distrital','nombre_sede'=>'Registraduría Distrital - CAIC y RA Santafe'],
    ['id'=>3,'identificador'=>'AB-KE','cod_dd'=>'16','departamento'=>'Distrito','municipio'=>'Bogotá D.C','tipo_sede'=>'Auxiliares Bogotá','nombre_sede'=>'Kennedy 1 - Central'],
    ['id'=>4,'identificador'=>'AB-NK','cod_dd'=>'16','departamento'=>'Distrito','municipio'=>'Bogotá D.C','tipo_sede'=>'Auxiliares Bogotá','nombre_sede'=>'Kennedy 2 - Portal Banderas'],
    ['id'=>5,'identificador'=>'DD-MD','cod_dd'=>'01','departamento'=>'Antioquia','municipio'=>'Medellín','tipo_sede'=>'Delegación','nombre_sede'=>'Delegacion Departamental'],
    ['id'=>6,'identificador'=>'RE-MD','cod_dd'=>'01','departamento'=>'Antioquia','municipio'=>'Medellín','tipo_sede'=>'Especial','nombre_sede'=>'Registraduria Especial Medellin'],
    ['id'=>7,'identificador'=>'RM-01-004','cod_dd'=>'01','departamento'=>'Antioquia','municipio'=>'Abejorral','tipo_sede'=>'Municipal','nombre_sede'=>'Registraduria Municipal Abejorral'],
    ['id'=>8,'identificador'=>'DD-MT','cod_dd'=>'13','departamento'=>'Cordoba','municipio'=>'Montería','tipo_sede'=>'Delegación','nombre_sede'=>'Delegacion Departamental'],
    ['id'=>9,'identificador'=>'RM-13-038','cod_dd'=>'13','departamento'=>'Cordoba','municipio'=>'Lorica','tipo_sede'=>'Municipal','nombre_sede'=>'Registraduria Municipal Lorica'],
    ['id'=>10,'identificador'=>'DD-RH','cod_dd'=>'48','departamento'=>'La Guajira','municipio'=>'Riohacha','tipo_sede'=>'Delegación','nombre_sede'=>'Delegacion Departamental'],
    ['id'=>11,'identificador'=>'RM-48-053','cod_dd'=>'48','departamento'=>'La Guajira','municipio'=>'Maicao','tipo_sede'=>'Municipal','nombre_sede'=>'Registraduria Municipal Maicao'],
    ['id'=>12,'identificador'=>'DD-BG','cod_dd'=>'15','departamento'=>'Cundinamarca','municipio'=>'Bogotá D.C','tipo_sede'=>'Delegación','nombre_sede'=>'Delegacion Departamental'],
    ['id'=>13,'identificador'=>'RE-SO','cod_dd'=>'15','departamento'=>'Cundinamarca','municipio'=>'Soacha','tipo_sede'=>'Especial','nombre_sede'=>'Registraduria Especial de Soacha'],
    ['id'=>14,'identificador'=>'DD-BG-AR','cod_dd'=>'15','departamento'=>'Cundinamarca','municipio'=>'Bogotá D.C','tipo_sede'=>'Delegación','nombre_sede'=>'Delegacion Departamental Archivo'],
    ['id'=>15,'identificador'=>'RM-15-099','cod_dd'=>'15','departamento'=>'Cundinamarca','municipio'=>'Nariño','tipo_sede'=>'Municipal','nombre_sede'=>'Registraduria Municipal Nariño'],
    ['id'=>16,'identificador'=>'DD-PA','cod_dd'=>'23','departamento'=>'Nariño','municipio'=>'Pasto','tipo_sede'=>'Delegación','nombre_sede'=>'Delegacion Departamental'],
];

$prepared = WarehouseSedeAssociator::prepare($sedes);
$cases = [
    'bogota_centrales_can' => [
        ['BOGOTA-CENTRALES','GERENCIA INFORMATICA'],
        ['sede_id'=>1,'confidence'=>'alta','review_required'=>false,'rule'=>'almacen_bogota_centrales_can'],
    ],
    'bogota_distrito_ambiguo' => [
        ['BOGOTA-DISTRITO','DISTRITO-KENNEDY'],
        ['sede_id'=>2,'confidence'=>'baja','review_required'=>true,'rule'=>'almacen_contingencia_distrital'],
    ],
    'especial_por_centro_costo' => [
        ['DELEGACION ANTIOQUIA','REGISTRADURIA ESPECIAL MEDELLIN'],
        ['sede_id'=>6,'confidence'=>'alta','review_required'=>false,'rule'=>'almacen_sede_exacta'],
    ],
    'municipio_unico' => [
        ['DELEGACIÓN CORDOBA','LORICA'],
        ['sede_id'=>9,'confidence'=>'media','review_required'=>false,'rule'=>'almacen_municipio_tipo'],
    ],
    'departamento_contingencia' => [
        ['DELEGACIÓN CUNDINAMARCA','ALMACEN CUNDIMARCA'],
        ['sede_id'=>12,'confidence'=>'baja','review_required'=>true,'rule'=>'almacen_contingencia_delegacion_departamental'],
    ],
    'guajira_municipio' => [
        ['DELEGACIÓN GUAJIRA','MAICAO'],
        ['sede_id'=>11,'confidence'=>'media','review_required'=>false,'rule'=>'almacen_municipio_tipo'],
    ],
    'especial_soacha' => [
        ['DELEGACIÓN CUNDINAMARCA','REGISTRADURIA ESPECIAL DE SOACHA'],
        ['sede_id'=>13,'confidence'=>'alta','review_required'=>false,'rule'=>'almacen_sede_exacta'],
    ],
    'municipio_homonimo_prioriza_sucursal' => [
        ['DELEGACIÓN CUNDINAMARCA','NARIÑO'],
        ['sede_id'=>15,'confidence'=>'media','review_required'=>false,'rule'=>'almacen_municipio_tipo'],
    ],
    'delegacion_principal_excluye_archivo' => [
        ['DELEGACIÓN CUNDINAMARCA','ALMACEN GENERAL'],
        ['sede_id'=>12,'confidence'=>'baja','review_required'=>true,'rule'=>'almacen_contingencia_delegacion_departamental'],
    ],
    'sin_coincidencia' => [
        ['SUCURSAL DESCONOCIDA','CENTRO DESCONOCIDO'],
        ['sede_id'=>null,'confidence'=>'sin_asignar','review_required'=>true,'rule'=>'almacen_sin_coincidencia_territorial'],
    ],
];

$results = [];
$ok = true;
foreach ($cases as $name => [$input, $expected]) {
    $actual = WarehouseSedeAssociator::associate($input[0], $input[1], $prepared);
    $projection = [
        'sede_id' => $actual['sede_id'],
        'confidence' => $actual['confidence'],
        'review_required' => $actual['review_required'],
        'rule' => $actual['evidence']['rule'] ?? null,
    ];
    $caseOk = $projection === $expected;
    $ok = $ok && $caseOk;
    $results[$name] = ['ok'=>$caseOk,'expected'=>$expected,'actual'=>$projection];
}

echo json_encode([
    'ok'=>$ok,
    'version'=>$version,
    'check'=>'warehouse_sede_association_' . $version,
    'cases'=>$results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 2);
