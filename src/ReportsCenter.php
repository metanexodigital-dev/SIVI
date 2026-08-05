<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/ReportsCenter.php
 * Propósito: Genera consultas, filtros y conjuntos de datos para los informes institucionales.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

/**
 * Centro integral de informes de SIVI.
 * Todas las consultas respetan el alcance territorial definido en Scope.
 */
final class ReportsCenter
{
    /** @return array<string,array<string,mixed>> */
    public static function types(): array
    {
        return [
            'avance' => [
                'label' => 'Avance territorial',
                'description' => 'Seguimiento por departamento, municipio y sede con equipos asignados, validados y pendientes.',
                'icon' => '▤',
                'roles' => ['formador','admin_gi','superadmin'],
            ],
            'inventario' => [
                'label' => 'Inventario validado',
                'description' => 'Consolidado maestro de equipos con información original, verificada, estado y responsable.',
                'icon' => '▣',
                'roles' => ['formador','admin_gi','superadmin'],
            ],
            'diferencias' => [
                'label' => 'Diferencias encontradas',
                'description' => 'Cambios entre el inventario original y la información confirmada durante la campaña.',
                'icon' => '⇄',
                'roles' => ['formador','admin_gi','superadmin'],
            ],
            'adicionales' => [
                'label' => 'Equipos adicionales',
                'description' => 'Elementos encontrados físicamente que no estaban asignados inicialmente a la sede.',
                'icon' => '＋',
                'roles' => ['formador','admin_gi','superadmin'],
            ],
            'no_encontrados' => [
                'label' => 'Equipos no encontrados',
                'description' => 'Equipos asignados que no fueron localizados físicamente o fueron reportados en otra condición.',
                'icon' => '⌕',
                'roles' => ['formador','admin_gi','superadmin'],
            ],
            'novedades' => [
                'label' => 'Novedades',
                'description' => 'Novedades abiertas, en gestión y cerradas con criticidad, responsable y tiempos de atención.',
                'icon' => '!',
                'roles' => ['formador','admin_gi','superadmin'],
            ],
            'correcciones' => [
                'label' => 'Correcciones',
                'description' => 'Solicitudes de corrección, estado, responsable y tiempo transcurrido.',
                'icon' => '✎',
                'roles' => ['formador','admin_gi','superadmin'],
            ],
            'calidad' => [
                'label' => 'Control de calidad',
                'description' => 'Indicadores de integridad por sede: seriales, placas, pendientes, novedades y calificación.',
                'icon' => '◎',
                'roles' => ['formador','admin_gi','superadmin'],
            ],
            'traslados' => [
                'label' => 'Traslados',
                'description' => 'Solicitudes y movimientos de equipos entre sedes con origen, destino y aprobación.',
                'icon' => '↔',
                'roles' => ['formador','admin_gi','superadmin'],
            ],
            'reaperturas' => [
                'label' => 'Reaperturas',
                'description' => 'Solicitudes para reabrir sedes finalizadas, motivos, decisiones y responsables.',
                'icon' => '↺',
                'roles' => ['formador','admin_gi','superadmin'],
            ],
            'auditoria' => [
                'label' => 'Auditoría de actividad',
                'description' => 'Acciones realizadas en SIVI, usuario, fecha, dirección IP y entidad afectada.',
                'icon' => '◷',
                'roles' => ['admin_gi','superadmin'],
            ],
            'final_campana' => [
                'label' => 'Informe final de campaña',
                'description' => 'Consolidado nacional o territorial de sedes, inventario, novedades, adicionales y calidad.',
                'icon' => '✓',
                'roles' => ['formador','admin_gi','superadmin'],
            ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public static function availableTypes(): array
    {
        $role = (string)(Auth::user()['role'] ?? '');
        return array_filter(self::types(), static fn(array $type): bool => in_array($role, $type['roles'], true));
    }

    public static function canUse(string $type): bool
    {
        return isset(self::availableTypes()[$type]);
    }

    /** @return array<string,mixed> */
    public static function filters(array $source): array
    {
        $campaignId = max(0, (int)($source['campaign_id'] ?? 0));
        $sedeId = max(0, (int)($source['sede_id'] ?? 0));
        if ($sedeId > 0 && !Scope::canAccessSede($sedeId)) $sedeId = 0;
        $dateFrom = trim((string)($source['date_from'] ?? ''));
        $dateTo = trim((string)($source['date_to'] ?? ''));
        if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = '';
        if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = '';
        return [
            'campaign_id' => $campaignId,
            'department' => trim((string)($source['department'] ?? '')),
            'municipality' => trim((string)($source['municipality'] ?? '')),
            'sede_id' => $sedeId,
            'status' => trim((string)($source['status'] ?? '')),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'q' => mb_substr(trim((string)($source['q'] ?? '')), 0, 180),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public static function campaignOptions(): array
    {
        [$scope, $params] = Scope::sedeCondition('s');
        return Database::fetchAll(
            "SELECT DISTINCT c.id,c.name,c.status,c.start_date,c.end_date FROM campaigns c "
            . "JOIN campaign_sedes cs ON cs.campaign_id=c.id JOIN sedes s ON s.id=cs.sede_id "
            . "WHERE {$scope} ORDER BY c.id DESC",
            $params
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function departmentOptions(): array
    {
        [$scope, $params] = Scope::sedeCondition('s');
        return Database::fetchAll("SELECT DISTINCT s.cod_dd,s.departamento FROM sedes s WHERE {$scope} AND COALESCE(s.departamento,'')<>'' ORDER BY s.departamento", $params);
    }

    /** @return array<int,array<string,mixed>> */
    public static function municipalityOptions(string $department = ''): array
    {
        [$scope, $params] = Scope::sedeCondition('s');
        if ($department !== '') { $scope .= ' AND s.cod_dd=?'; $params[] = $department; }
        return Database::fetchAll("SELECT DISTINCT s.municipio FROM sedes s WHERE {$scope} AND COALESCE(s.municipio,'')<>'' ORDER BY s.municipio", $params);
    }

    /** @return array<int,array<string,mixed>> */
    public static function sedeOptions(string $department = '', string $municipality = ''): array
    {
        [$scope, $params] = Scope::sedeCondition('s');
        if ($department !== '') { $scope .= ' AND s.cod_dd=?'; $params[] = $department; }
        if ($municipality !== '') { $scope .= ' AND s.municipio=?'; $params[] = $municipality; }
        return Database::fetchAll("SELECT s.id,s.identificador,s.nombre_sede,s.municipio,s.departamento FROM sedes s WHERE {$scope} ORDER BY s.departamento,s.municipio,s.identificador", $params);
    }

    /**
     * @return array{title:string,description:string,headers:array<int,string>,rows:array<int,array<int,string>>,total:int,truncated:bool,filename:string}
     */
    public static function dataset(string $type, array $filters, int $limit = 200): array
    {
        if (!self::canUse($type)) throw new RuntimeException('No tiene permiso para generar este informe.');
        $limit = max(0, min(100000, $limit));
        return match ($type) {
            'avance' => self::advance($filters, $limit),
            'inventario' => self::inventory($filters, $limit),
            'diferencias' => self::differences($filters, $limit),
            'adicionales' => self::additionals($filters, $limit),
            'no_encontrados' => self::notFound($filters, $limit),
            'novedades' => self::incidents($filters, $limit),
            'correcciones' => self::corrections($filters, $limit),
            'calidad' => self::quality($filters, $limit),
            'traslados' => self::transfers($filters, $limit),
            'reaperturas' => self::reopenings($filters, $limit),
            'auditoria' => self::audit($filters, $limit),
            'final_campana' => self::campaignFinal($filters, $limit),
            default => throw new InvalidArgumentException('Seleccione un informe válido.'),
        };
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private static function commonWhere(array $f, string $sedeAlias = 's', string $campaignAlias = 'c', ?string $dateColumn = null): array
    {
        [$where, $params] = Scope::sedeCondition($sedeAlias);
        if ((int)$f['campaign_id'] > 0) { $where .= " AND {$campaignAlias}.id=?"; $params[] = (int)$f['campaign_id']; }
        if ((string)$f['department'] !== '') { $where .= " AND {$sedeAlias}.cod_dd=?"; $params[] = (string)$f['department']; }
        if ((string)$f['municipality'] !== '') { $where .= " AND {$sedeAlias}.municipio=?"; $params[] = (string)$f['municipality']; }
        if ((int)$f['sede_id'] > 0) { $where .= " AND {$sedeAlias}.id=?"; $params[] = (int)$f['sede_id']; }
        if ($dateColumn !== null && (string)$f['date_from'] !== '') { $where .= " AND DATE({$dateColumn})>=?"; $params[] = (string)$f['date_from']; }
        if ($dateColumn !== null && (string)$f['date_to'] !== '') { $where .= " AND DATE({$dateColumn})<=?"; $params[] = (string)$f['date_to']; }
        return [$where, $params];
    }

    private static function query(string $sql, array $params, int $limit): array
    {
        $fetchLimit = $limit > 0 ? $limit + 1 : 0;
        if ($fetchLimit > 0) $sql .= ' LIMIT ' . $fetchLimit;
        $rows = Database::fetchAll($sql, $params);
        $truncated = $fetchLimit > 0 && count($rows) > $limit;
        if ($truncated) array_pop($rows);
        return [$rows, $truncated];
    }

    private static function result(string $type, array $headers, array $rows, bool $truncated): array
    {
        $meta = self::types()[$type];
        $normalized = [];
        foreach ($rows as $row) {
            $normalized[] = array_map(static fn($value): string => $value === null ? '' : (string)$value, array_values($row));
        }
        return [
            'title' => (string)$meta['label'],
            'description' => (string)$meta['description'],
            'headers' => $headers,
            'rows' => $normalized,
            'total' => count($normalized),
            'truncated' => $truncated,
            'filename' => 'sivi_' . $type . '_' . date('Ymd_His'),
        ];
    }

    private static function advance(array $f, int $limit): array
    {
        [$where,$params] = self::commonWhere($f, 's', 'c', 'COALESCE(cs.closed_at,cs.submitted_at,cs.site_confirmed_at,cs.notified_at)');
        if ((string)$f['status'] !== '') { $where .= ' AND cs.status=?'; $params[] = (string)$f['status']; }
        if ((string)$f['q'] !== '') { $where .= ' AND (s.identificador LIKE ? OR s.nombre_sede LIKE ? OR cs.responsible_name LIKE ?)'; $like='%'.$f['q'].'%'; array_push($params,$like,$like,$like); }
        [$rows,$truncated] = self::query(
            "SELECT c.name campaign,s.departamento,s.municipio,s.identificador,s.tipo_sede,s.nombre_sede,"
            . "COALESCE(cs.responsible_name,'') responsible,COALESCE(cs.responsible_email,'') email,cs.status,"
            . "(SELECT COUNT(*) FROM campaign_equipment ce WHERE ce.campaign_id=cs.campaign_id AND ce.sede_id=cs.sede_id) assigned,"
            . "(SELECT COUNT(DISTINCT ev.equipment_id) FROM equipment_validations ev WHERE ev.campaign_id=cs.campaign_id AND ev.reported_by_sede_id=cs.sede_id AND ev.validation_status<>'pendiente') validated,"
            . "(SELECT COUNT(*) FROM additional_equipment ae WHERE ae.campaign_id=cs.campaign_id AND ae.sede_id=cs.sede_id) additional_count,"
            . "(SELECT COUNT(*) FROM incidents i WHERE i.campaign_id=cs.campaign_id AND i.sede_id=cs.sede_id AND i.status IN ('abierta','en_gestion')) incidents_count,"
            . "(SELECT COUNT(*) FROM validation_corrections vc JOIN equipment_validations evc ON evc.id=vc.validation_id WHERE evc.campaign_id=cs.campaign_id AND evc.reported_by_sede_id=cs.sede_id AND vc.status='pendiente') corrections_count,"
            . "cs.site_confirmed_at,cs.submitted_at,cs.closed_at "
            . "FROM campaign_sedes cs JOIN campaigns c ON c.id=cs.campaign_id JOIN sedes s ON s.id=cs.sede_id WHERE {$where} "
            . "ORDER BY c.id DESC,s.departamento,s.municipio,s.identificador",
            $params,$limit
        );
        $out=[];
        foreach($rows as $r){$assigned=(int)$r['assigned'];$validated=(int)$r['validated'];$out[]=[
            $r['campaign'],$r['departamento'],$r['municipio'],$r['identificador'],$r['tipo_sede'],$r['nombre_sede'],$r['responsible'],$r['email'],self::statusLabel((string)$r['status']),
            $assigned,$validated,max(0,$assigned-$validated),(int)$r['additional_count'],(int)$r['incidents_count'],(int)$r['corrections_count'],$assigned>0?round($validated/$assigned*100,2):0,$r['site_confirmed_at'],$r['submitted_at'],$r['closed_at']
        ];}
        return self::result('avance',['Campaña','Departamento','Municipio','Código sede','Tipo de sede','Sede','Responsable','Correo','Estado','Asignados','Validados','Pendientes','Adicionales','Novedades abiertas','Correcciones pendientes','Avance %','Sede confirmada','Envío','Finalización'],$out,$truncated);
    }

    private static function inventory(array $f, int $limit): array
    {
        [$where,$params] = self::commonWhere($f,'s','c','ev.submitted_at');
        if ((string)$f['status'] !== '') { $where .= ' AND COALESCE(ev.validation_status,\'pendiente\')=?'; $params[]=(string)$f['status']; }
        if ((string)$f['q'] !== '') { $where .= ' AND (e.name LIKE ? OR e.serial_number LIKE ? OR e.placa_rnec LIKE ? OR e.manufacturer LIKE ? OR e.model LIKE ?)'; $like='%'.$f['q'].'%'; array_push($params,$like,$like,$like,$like,$like); }
        [$rows,$truncated] = self::query(
            "SELECT c.name campaign,s.departamento,s.municipio,s.identificador,s.tipo_sede,s.nombre_sede,e.asset_category,e.equipment_type,e.name,e.manufacturer,e.model,"
            . "e.serial_number serial_original,COALESCE(NULLIF(ev.serial_reported,''),e.serial_number) serial_verified,e.placa_rnec placa_original,COALESCE(NULLIF(ev.placa_reported,''),e.placa_rnec) placa_verified,"
            . "COALESCE(ev.ownership_type,e.ownership_type) ownership,COALESCE(ev.physical_condition,e.inventory_status) equipment_state,COALESCE(ev.validation_status,'pendiente') validation_status,COALESCE(ev.review_status,'pendiente') review_status,"
            . "e.os_name,e.os_version,e.processor,e.memory,e.alternate_user,e.source_location,COALESCE(u.name,'') validator,ev.submitted_at "
            . "FROM campaign_equipment ce JOIN campaigns c ON c.id=ce.campaign_id JOIN equipment e ON e.id=ce.equipment_id JOIN sedes s ON s.id=ce.sede_id "
            . "LEFT JOIN equipment_validations ev ON ev.campaign_id=ce.campaign_id AND ev.equipment_id=ce.equipment_id AND ev.reported_by_sede_id=ce.sede_id LEFT JOIN users u ON u.id=ev.submitted_by "
            . "WHERE {$where} ORDER BY c.id DESC,s.departamento,s.municipio,s.identificador,e.asset_category,e.name",
            $params,$limit
        );
        $out=[]; foreach($rows as $r){$out[]=[
            $r['campaign'],$r['departamento'],$r['municipio'],$r['identificador'],$r['tipo_sede'],$r['nombre_sede'],asset_category_label((string)$r['asset_category']),$r['equipment_type'],$r['name'],$r['manufacturer'],$r['model'],$r['serial_original'],$r['serial_verified'],$r['placa_original'],$r['placa_verified'],self::propertyLabel((string)$r['ownership']),self::statusLabel((string)$r['equipment_state']),self::statusLabel((string)$r['validation_status']),self::statusLabel((string)$r['review_status']),$r['os_name'],$r['os_version'],$r['processor'],$r['memory'],$r['alternate_user'],$r['source_location'],$r['validator'],$r['submitted_at']
        ];}
        return self::result('inventario',['Campaña','Departamento','Municipio','Código sede','Tipo de sede','Sede','Categoría','Tipo de equipo','Nombre / hostname','Marca','Modelo','Serial original','Serial verificado','Placa original','Placa verificada','Propiedad','Estado actual','Resultado validación','Estado revisión','Sistema operativo','Versión SO','Procesador','RAM','Usuario responsable','Ubicación','Validado por','Fecha validación'],$out,$truncated);
    }

    private static function differences(array $f, int $limit): array
    {
        [$where,$params] = self::commonWhere($f,'s','c','ev.updated_at');
        $where .= " AND ev.id IS NOT NULL AND (ev.serial_status IN ('corregido','sin_serial','ilegible') OR ev.placa_status IN ('corregida','sin_placa','ilegible') OR ev.belongs_status<>'pertenece' OR ev.validation_status<>'confirmado' OR (ev.physical_condition<>'pendiente' AND ev.physical_condition<>e.inventory_status))";
        if ((string)$f['status'] !== '') { $where .= ' AND ev.review_status=?'; $params[]=(string)$f['status']; }
        if ((string)$f['q'] !== '') { $where .= ' AND (e.name LIKE ? OR e.serial_number LIKE ? OR e.placa_rnec LIKE ? OR ev.notes LIKE ?)'; $like='%'.$f['q'].'%'; array_push($params,$like,$like,$like,$like); }
        [$rows,$truncated] = self::query(
            "SELECT c.name campaign,s.departamento,s.municipio,s.identificador,s.nombre_sede,e.asset_category,e.name,e.serial_number,ev.serial_reported,ev.serial_status,e.placa_rnec,ev.placa_reported,ev.placa_status,e.inventory_status,ev.physical_condition,ev.belongs_status,ev.validation_status,ev.review_status,ev.notes,COALESCE(u.name,'') validator,ev.updated_at "
            . "FROM equipment_validations ev JOIN campaigns c ON c.id=ev.campaign_id JOIN equipment e ON e.id=ev.equipment_id JOIN sedes s ON s.id=ev.reported_by_sede_id LEFT JOIN users u ON u.id=ev.submitted_by WHERE {$where} ORDER BY ev.updated_at DESC",
            $params,$limit
        );
        $out=[];foreach($rows as $r){$changes=[];if($r['serial_status']!=='confirmado')$changes[]='Serial';if($r['placa_status']!=='confirmada')$changes[]='Placa';if($r['inventory_status']!==$r['physical_condition']&&$r['physical_condition']!=='pendiente')$changes[]='Estado';if($r['belongs_status']!=='pertenece')$changes[]='Pertenencia';$out[]=[
            $r['campaign'],$r['departamento'],$r['municipio'],$r['identificador'],$r['nombre_sede'],asset_category_label((string)$r['asset_category']),$r['name'],implode(', ',$changes),$r['serial_number'],$r['serial_reported'],self::statusLabel((string)$r['serial_status']),$r['placa_rnec'],$r['placa_reported'],self::statusLabel((string)$r['placa_status']),self::statusLabel((string)$r['inventory_status']),self::statusLabel((string)$r['physical_condition']),self::statusLabel((string)$r['belongs_status']),self::statusLabel((string)$r['validation_status']),self::statusLabel((string)$r['review_status']),validation_notes_for_display((string)$r['notes']),$r['validator'],$r['updated_at']
        ];}
        return self::result('diferencias',['Campaña','Departamento','Municipio','Código sede','Sede','Categoría','Equipo','Campos con diferencia','Serial original','Serial reportado','Estado serial','Placa original','Placa reportada','Estado placa','Estado original','Estado reportado','Pertenencia','Resultado validación','Revisión','Observaciones','Validado por','Actualización'],$out,$truncated);
    }

    private static function additionals(array $f, int $limit): array
    {
        [$where,$params] = self::commonWhere($f,'s','c','ae.created_at');
        if ((string)$f['status'] !== '') { $where .= ' AND ae.review_status=?'; $params[]=(string)$f['status']; }
        if ((string)$f['q'] !== '') { $where .= ' AND (ae.name LIKE ? OR ae.serial_number LIKE ? OR ae.placa_rnec LIKE ? OR ae.manufacturer LIKE ? OR ae.model LIKE ?)'; $like='%'.$f['q'].'%'; array_push($params,$like,$like,$like,$like,$like); }
        [$rows,$truncated] = self::query(
            "SELECT c.name campaign,s.departamento,s.municipio,s.identificador,s.nombre_sede,ae.asset_category,ae.equipment_type,ae.name,ae.ownership_type,ae.equipment_state,ae.serial_number,ae.placa_rnec,ae.manufacturer,ae.model,ae.physical_location,ae.notes,ae.review_status,COALESCE(u.name,'') created_by_name,ae.created_at,COALESCE(ru.name,'') reviewed_by_name,ae.reviewed_at FROM additional_equipment ae JOIN campaigns c ON c.id=ae.campaign_id JOIN sedes s ON s.id=ae.sede_id LEFT JOIN users u ON u.id=ae.created_by LEFT JOIN users ru ON ru.id=ae.reviewed_by WHERE {$where} ORDER BY ae.created_at DESC",
            $params,$limit
        );
        $out=[];foreach($rows as $r){$out[]=[$r['campaign'],$r['departamento'],$r['municipio'],$r['identificador'],$r['nombre_sede'],asset_category_label((string)$r['asset_category']),$r['equipment_type'],$r['name'],self::propertyLabel((string)$r['ownership_type']),self::statusLabel((string)$r['equipment_state']),$r['serial_number'],$r['placa_rnec'],$r['manufacturer'],$r['model'],$r['physical_location'],$r['notes'],self::statusLabel((string)$r['review_status']),$r['created_by_name'],$r['created_at'],$r['reviewed_by_name'],$r['reviewed_at']];}
        return self::result('adicionales',['Campaña','Departamento','Municipio','Código sede','Sede','Categoría','Tipo','Nombre / hostname','Propiedad','Estado','Serial','Placa RNEC','Marca','Modelo','Ubicación','Observaciones','Revisión','Registrado por','Fecha registro','Revisado por','Fecha revisión'],$out,$truncated);
    }

    private static function notFound(array $f, int $limit): array
    {
        [$where,$params] = self::commonWhere($f,'s','c','ev.updated_at');
        $where .= " AND (ev.validation_status='no_encontrado' OR ev.physical_condition='no_localizado' OR ev.belongs_reason='no_localizado')";
        if ((string)$f['q'] !== '') { $where .= ' AND (e.name LIKE ? OR e.serial_number LIKE ? OR e.placa_rnec LIKE ? OR ev.notes LIKE ?)'; $like='%'.$f['q'].'%'; array_push($params,$like,$like,$like,$like); }
        [$rows,$truncated] = self::query(
            "SELECT c.name campaign,s.departamento,s.municipio,s.identificador,s.nombre_sede,e.asset_category,e.name,e.manufacturer,e.model,e.serial_number,e.placa_rnec,ev.validation_status,ev.physical_condition,ev.belongs_reason,ev.notes,COALESCE(u.name,'') validator,ev.updated_at FROM equipment_validations ev JOIN campaigns c ON c.id=ev.campaign_id JOIN equipment e ON e.id=ev.equipment_id JOIN sedes s ON s.id=ev.reported_by_sede_id LEFT JOIN users u ON u.id=ev.submitted_by WHERE {$where} ORDER BY ev.updated_at DESC",
            $params,$limit
        );
        $out=[];foreach($rows as $r){$noteParts=validation_notes_parse((string)$r['notes']);$motive=self::statusLabel((string)$r['belongs_reason']);if((string)$r['belongs_reason']==='otro' && trim((string)($noteParts['belongs_reason_other']??''))!=='')$motive='Otro: '.trim((string)$noteParts['belongs_reason_other']);$out[]=[$r['campaign'],$r['departamento'],$r['municipio'],$r['identificador'],$r['nombre_sede'],asset_category_label((string)$r['asset_category']),$r['name'],$r['manufacturer'],$r['model'],$r['serial_number'],$r['placa_rnec'],self::statusLabel((string)$r['validation_status']),self::statusLabel((string)$r['physical_condition']),$motive,validation_notes_for_display((string)$r['notes']),$r['validator'],$r['updated_at']];}
        return self::result('no_encontrados',['Campaña','Departamento','Municipio','Código sede','Sede','Categoría','Equipo','Marca','Modelo','Serial','Placa','Resultado','Condición','Motivo','Observaciones','Responsable','Actualización'],$out,$truncated);
    }

    private static function incidents(array $f, int $limit): array
    {
        [$where,$params] = self::commonWhere($f,'s','c','i.created_at');
        if ((string)$f['status'] !== '') { $where .= ' AND i.status=?'; $params[]=(string)$f['status']; }
        if ((string)$f['q'] !== '') { $where .= ' AND (i.description LIKE ? OR e.name LIKE ? OR e.serial_number LIKE ? OR s.nombre_sede LIKE ?)'; $like='%'.$f['q'].'%'; array_push($params,$like,$like,$like,$like); }
        [$rows,$truncated] = self::query(
            "SELECT i.id,c.name campaign,s.departamento,s.municipio,s.identificador,s.nombre_sede,i.incident_type,i.priority,i.description,i.status,COALESCE(e.name,'') equipment_name,COALESCE(e.serial_number,'') serial_number,COALESCE(u.name,'') created_by_name,i.created_at,i.updated_at,i.resolved_at FROM incidents i JOIN campaigns c ON c.id=i.campaign_id JOIN sedes s ON s.id=i.sede_id LEFT JOIN equipment e ON e.id=i.equipment_id LEFT JOIN users u ON u.id=i.reported_by WHERE {$where} ORDER BY FIELD(i.status,'abierta','en_gestion','resuelta','cerrada'),FIELD(i.priority,'critica','alta','media','baja'),i.created_at DESC",
            $params,$limit
        );
        $out=[];foreach($rows as $r){$out[]=[$r['id'],$r['campaign'],$r['departamento'],$r['municipio'],$r['identificador'],$r['nombre_sede'],self::statusLabel((string)$r['incident_type']),self::statusLabel((string)$r['priority']),$r['description'],self::statusLabel((string)$r['status']),$r['equipment_name'],$r['serial_number'],$r['created_by_name'],$r['created_at'],$r['updated_at'],$r['resolved_at']];}
        return self::result('novedades',['Número','Campaña','Departamento','Municipio','Código sede','Sede','Tipo','Prioridad','Descripción','Estado','Equipo','Serial','Creada por','Apertura','Actualización','Cierre'],$out,$truncated);
    }

    private static function corrections(array $f, int $limit): array
    {
        [$where,$params] = self::commonWhere($f,'s','c','vc.created_at');
        if ((string)$f['status'] !== '') { $where .= ' AND vc.status=?'; $params[]=(string)$f['status']; }
        if ((string)$f['q'] !== '') { $where .= ' AND (vc.notes LIKE ? OR e.name LIKE ? OR e.serial_number LIKE ? OR e.placa_rnec LIKE ?)'; $like='%'.$f['q'].'%'; array_push($params,$like,$like,$like,$like); }
        [$rows,$truncated] = self::query(
            "SELECT vc.id,c.name campaign,s.departamento,s.municipio,s.identificador,s.nombre_sede,e.asset_category,e.name,e.serial_number,e.placa_rnec,vc.notes,vc.status,COALESCE(req.name,'') requested_by_name,COALESCE(ass.name,'') assigned_to_name,vc.created_at,vc.corrected_at,vc.approved_at,TIMESTAMPDIFF(HOUR,vc.created_at,COALESCE(vc.approved_at,vc.corrected_at,NOW())) elapsed_hours FROM validation_corrections vc JOIN equipment_validations ev ON ev.id=vc.validation_id JOIN campaigns c ON c.id=ev.campaign_id JOIN sedes s ON s.id=ev.reported_by_sede_id JOIN equipment e ON e.id=ev.equipment_id LEFT JOIN users req ON req.id=vc.requested_by LEFT JOIN users ass ON ass.id=vc.assigned_to WHERE {$where} ORDER BY FIELD(vc.status,'pendiente','corregida','aprobada','cancelada'),vc.created_at DESC",
            $params,$limit
        );
        $out=[];foreach($rows as $r){$out[]=[$r['id'],$r['campaign'],$r['departamento'],$r['municipio'],$r['identificador'],$r['nombre_sede'],asset_category_label((string)$r['asset_category']),$r['name'],$r['serial_number'],$r['placa_rnec'],$r['notes'],self::statusLabel((string)$r['status']),$r['requested_by_name'],$r['assigned_to_name'],$r['created_at'],$r['corrected_at'],$r['approved_at'],$r['elapsed_hours']];}
        return self::result('correcciones',['Número','Campaña','Departamento','Municipio','Código sede','Sede','Categoría','Equipo','Serial','Placa','Motivo','Estado','Solicitada por','Asignada a','Solicitud','Corrección','Aprobación','Horas transcurridas'],$out,$truncated);
    }

    private static function quality(array $f, int $limit): array
    {
        [$where,$params] = self::commonWhere($f,'s','c','COALESCE(cs.closed_at,cs.submitted_at,cs.site_confirmed_at)');
        if ((string)$f['status'] !== '') { $where .= ' AND cs.status=?'; $params[]=(string)$f['status']; }
        [$rows,$truncated] = self::query(
            "SELECT c.name campaign,s.departamento,s.municipio,s.identificador,s.nombre_sede,cs.status,"
            . "(SELECT COUNT(*) FROM campaign_equipment ce WHERE ce.campaign_id=cs.campaign_id AND ce.sede_id=cs.sede_id) assigned,"
            . "(SELECT COUNT(*) FROM equipment_validations ev WHERE ev.campaign_id=cs.campaign_id AND ev.reported_by_sede_id=cs.sede_id AND ev.validation_status<>'pendiente') validated,"
            . "(SELECT COUNT(*) FROM equipment_validations ev WHERE ev.campaign_id=cs.campaign_id AND ev.reported_by_sede_id=cs.sede_id AND ev.serial_status IN ('corregido','sin_serial','ilegible','pendiente')) serial_issues,"
            . "(SELECT COUNT(*) FROM equipment_validations ev WHERE ev.campaign_id=cs.campaign_id AND ev.reported_by_sede_id=cs.sede_id AND ev.placa_status IN ('corregida','sin_placa','ilegible','pendiente')) plate_issues,"
            . "(SELECT COUNT(*) FROM equipment_validations ev WHERE ev.campaign_id=cs.campaign_id AND ev.reported_by_sede_id=cs.sede_id AND (ev.validation_status='no_encontrado' OR ev.physical_condition='no_localizado')) missing_count,"
            . "(SELECT COUNT(*) FROM incidents i WHERE i.campaign_id=cs.campaign_id AND i.sede_id=cs.sede_id AND i.status IN ('abierta','en_gestion')) incidents_count,"
            . "(SELECT COUNT(*) FROM validation_corrections vc JOIN equipment_validations evc ON evc.id=vc.validation_id WHERE evc.campaign_id=cs.campaign_id AND evc.reported_by_sede_id=cs.sede_id AND vc.status='pendiente') corrections_count "
            . "FROM campaign_sedes cs JOIN campaigns c ON c.id=cs.campaign_id JOIN sedes s ON s.id=cs.sede_id WHERE {$where} ORDER BY c.id DESC,s.departamento,s.municipio,s.identificador",
            $params,$limit
        );
        $out=[];foreach($rows as $r){$assigned=max(0,(int)$r['assigned']);$issues=(int)$r['serial_issues']+(int)$r['plate_issues']+(int)$r['missing_count']+(int)$r['incidents_count']+(int)$r['corrections_count']+max(0,$assigned-(int)$r['validated']);$quality=$assigned>0?max(0,round(100-($issues/$assigned*100),2)):100;$out[]=[$r['campaign'],$r['departamento'],$r['municipio'],$r['identificador'],$r['nombre_sede'],self::statusLabel((string)$r['status']),$assigned,(int)$r['validated'],max(0,$assigned-(int)$r['validated']),(int)$r['serial_issues'],(int)$r['plate_issues'],(int)$r['missing_count'],(int)$r['incidents_count'],(int)$r['corrections_count'],$issues,$quality,self::qualityLevel($quality)];}
        return self::result('calidad',['Campaña','Departamento','Municipio','Código sede','Sede','Estado','Asignados','Validados','Pendientes','Hallazgos serial','Hallazgos placa','No encontrados','Novedades abiertas','Correcciones pendientes','Total hallazgos','Calidad %','Nivel'],$out,$truncated);
    }

    private static function transfers(array $f, int $limit): array
    {
        [$where,$params] = self::commonWhere($f,'so','c','et.created_at');
        if ((string)$f['status'] !== '') { $where .= ' AND et.status=?'; $params[]=(string)$f['status']; }
        if ((string)$f['q'] !== '') { $where .= ' AND (e.name LIKE ? OR e.serial_number LIKE ? OR e.placa_rnec LIKE ? OR et.reason LIKE ?)'; $like='%'.$f['q'].'%'; array_push($params,$like,$like,$like,$like); }
        [$rows,$truncated] = self::query(
            "SELECT et.id,COALESCE(c.name,'Sin campaña') campaign,e.asset_category,e.name,e.serial_number,e.placa_rnec,so.departamento origin_department,so.municipio origin_municipality,so.identificador origin_code,so.nombre_sede origin_sede,sd.departamento destination_department,sd.municipio destination_municipality,sd.identificador destination_code,sd.nombre_sede destination_sede,et.reason,et.status,COALESCE(req.name,'') requested_by_name,COALESCE(rev.name,'') reviewed_by_name,et.created_at,et.reviewed_at,et.applied_at,et.review_notes FROM equipment_transfers et JOIN equipment e ON e.id=et.equipment_id LEFT JOIN campaigns c ON c.id=et.campaign_id LEFT JOIN sedes so ON so.id=et.origin_sede_id JOIN sedes sd ON sd.id=et.destination_sede_id LEFT JOIN users req ON req.id=et.requested_by LEFT JOIN users rev ON rev.id=et.reviewed_by WHERE {$where} ORDER BY et.created_at DESC",
            $params,$limit
        );
        $out=[];foreach($rows as $r){$out[]=[$r['id'],$r['campaign'],asset_category_label((string)$r['asset_category']),$r['name'],$r['serial_number'],$r['placa_rnec'],$r['origin_department'],$r['origin_municipality'],$r['origin_code'],$r['origin_sede'],$r['destination_department'],$r['destination_municipality'],$r['destination_code'],$r['destination_sede'],$r['reason'],self::statusLabel((string)$r['status']),$r['requested_by_name'],$r['reviewed_by_name'],$r['created_at'],$r['reviewed_at'],$r['applied_at'],$r['review_notes']];}
        return self::result('traslados',['Número','Campaña','Categoría','Equipo','Serial','Placa','Departamento origen','Municipio origen','Código origen','Sede origen','Departamento destino','Municipio destino','Código destino','Sede destino','Motivo','Estado','Solicitado por','Revisado por','Solicitud','Revisión','Aplicación','Observaciones revisión'],$out,$truncated);
    }

    private static function reopenings(array $f, int $limit): array
    {
        [$where,$params] = self::commonWhere($f,'s','c','rr.created_at');
        if ((string)$f['status'] !== '') { $where .= ' AND rr.status=?'; $params[]=(string)$f['status']; }
        if ((string)$f['q'] !== '') { $where .= ' AND (rr.reason LIKE ? OR s.identificador LIKE ? OR s.nombre_sede LIKE ?)'; $like='%'.$f['q'].'%'; array_push($params,$like,$like,$like); }
        [$rows,$truncated] = self::query(
            "SELECT rr.id,c.name campaign,s.departamento,s.municipio,s.identificador,s.nombre_sede,rr.reason,rr.status,COALESCE(req.name,'') requested_by_name,COALESCE(rev.name,'') reviewed_by_name,rr.created_at,rr.reviewed_at,cs.closed_at original_closed_at,cs.reopened_at FROM reopening_requests rr JOIN campaigns c ON c.id=rr.campaign_id JOIN sedes s ON s.id=rr.sede_id LEFT JOIN users req ON req.id=rr.requested_by LEFT JOIN users rev ON rev.id=rr.reviewed_by LEFT JOIN campaign_sedes cs ON cs.campaign_id=rr.campaign_id AND cs.sede_id=rr.sede_id WHERE {$where} ORDER BY rr.created_at DESC",
            $params,$limit
        );
        $out=[];foreach($rows as $r){$out[]=[$r['id'],$r['campaign'],$r['departamento'],$r['municipio'],$r['identificador'],$r['nombre_sede'],$r['reason'],self::statusLabel((string)$r['status']),$r['requested_by_name'],$r['reviewed_by_name'],$r['original_closed_at'],$r['created_at'],$r['reviewed_at'],$r['reopened_at']];}
        return self::result('reaperturas',['Número','Campaña','Departamento','Municipio','Código sede','Sede','Motivo','Estado','Solicitada por','Revisada por','Cierre original','Solicitud','Revisión','Reapertura'],$out,$truncated);
    }

    private static function audit(array $f, int $limit): array
    {
        $where='1=1';$params=[];
        if ((string)$f['date_from'] !== '') { $where.=' AND DATE(a.created_at)>=?';$params[]=(string)$f['date_from']; }
        if ((string)$f['date_to'] !== '') { $where.=' AND DATE(a.created_at)<=?';$params[]=(string)$f['date_to']; }
        if ((string)$f['status'] !== '') { $where.=' AND a.action=?';$params[]=(string)$f['status']; }
        if ((string)$f['q'] !== '') { $where.=' AND (a.action LIKE ? OR a.entity_type LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR CAST(a.new_values AS CHAR) LIKE ?)';$like='%'.$f['q'].'%';array_push($params,$like,$like,$like,$like,$like); }
        [$rows,$truncated]=self::query("SELECT a.id,a.created_at,COALESCE(u.name,'Sistema') user_name,COALESCE(u.email,'') email,COALESCE(u.role,'') role,a.action,a.entity_type,a.entity_id,a.ip_address,CAST(a.old_values AS CHAR) old_values,CAST(a.new_values AS CHAR) new_values FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id WHERE {$where} ORDER BY a.id DESC",$params,$limit);
        $out=[];foreach($rows as $r){$out[]=[$r['id'],$r['created_at'],$r['user_name'],$r['email'],role_label((string)$r['role']),$r['action'],$r['entity_type'],$r['entity_id'],$r['ip_address'],$r['old_values'],$r['new_values']];}
        return self::result('auditoria',['Número','Fecha','Usuario','Correo','Perfil','Acción','Entidad','ID entidad','Dirección IP','Valores anteriores','Valores nuevos'],$out,$truncated);
    }

    private static function campaignFinal(array $f, int $limit): array
    {
        [$where,$params] = self::commonWhere($f,'s','c','COALESCE(cs.closed_at,cs.submitted_at,cs.site_confirmed_at)');
        if ((string)$f['status'] !== '') { $where.=' AND c.status=?';$params[]=(string)$f['status']; }
        [$rows,$truncated]=self::query(
            "SELECT c.name campaign,c.status campaign_status,c.start_date,c.end_date,s.cod_dd,s.departamento,COUNT(DISTINCT cs.sede_id) sedes,COUNT(DISTINCT CASE WHEN cs.status IN ('cerrado','aprobado') THEN cs.sede_id END) sedes_closed,COUNT(DISTINCT ce.equipment_id) assigned,COUNT(DISTINCT CASE WHEN ev.validation_status<>'pendiente' THEN ev.equipment_id END) validated,COUNT(DISTINCT ae.id) additional_count,COUNT(DISTINCT CASE WHEN ev.validation_status='no_encontrado' OR ev.physical_condition='no_localizado' THEN ev.equipment_id END) missing_count,COUNT(DISTINCT i.id) incidents_count,COUNT(DISTINCT vc.id) corrections_count FROM campaign_sedes cs JOIN campaigns c ON c.id=cs.campaign_id JOIN sedes s ON s.id=cs.sede_id LEFT JOIN campaign_equipment ce ON ce.campaign_id=cs.campaign_id AND ce.sede_id=cs.sede_id LEFT JOIN equipment_validations ev ON ev.campaign_id=ce.campaign_id AND ev.equipment_id=ce.equipment_id AND ev.reported_by_sede_id=ce.sede_id LEFT JOIN additional_equipment ae ON ae.campaign_id=cs.campaign_id AND ae.sede_id=cs.sede_id LEFT JOIN incidents i ON i.campaign_id=cs.campaign_id AND i.sede_id=cs.sede_id LEFT JOIN validation_corrections vc ON vc.validation_id=ev.id WHERE {$where} GROUP BY c.id,c.name,c.status,c.start_date,c.end_date,s.cod_dd,s.departamento ORDER BY c.id DESC,s.departamento",
            $params,$limit
        );
        $out=[];foreach($rows as $r){$sedes=(int)$r['sedes'];$assigned=(int)$r['assigned'];$out[]=[$r['campaign'],self::statusLabel((string)$r['campaign_status']),$r['start_date'],$r['end_date'],$r['cod_dd'],$r['departamento'],$sedes,(int)$r['sedes_closed'],max(0,$sedes-(int)$r['sedes_closed']),$sedes>0?round(((int)$r['sedes_closed']/$sedes)*100,2):0,$assigned,(int)$r['validated'],max(0,$assigned-(int)$r['validated']),$assigned>0?round(((int)$r['validated']/$assigned)*100,2):0,(int)$r['additional_count'],(int)$r['missing_count'],(int)$r['incidents_count'],(int)$r['corrections_count']];}
        return self::result('final_campana',['Campaña','Estado campaña','Fecha inicio','Fecha fin','Código departamento','Departamento','Sedes incluidas','Sedes finalizadas','Sedes pendientes','Avance sedes %','Equipos asignados','Equipos validados','Equipos pendientes','Avance equipos %','Equipos adicionales','No encontrados','Novedades','Correcciones'],$out,$truncated);
    }

    public static function logExport(string $type, string $format, array $filters, int $rowCount): void
    {
        try {
            Database::execute('INSERT INTO report_exports(report_type,export_format,filters_json,row_count,exported_by,ip_address) VALUES(?,?,?,?,?,?)',[
                $type,$format,json_encode($filters,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$rowCount,Auth::id(),client_ip()
            ]);
            audit('export_report','report',null,null,['type'=>$type,'format'=>$format,'rows'=>$rowCount,'filters'=>$filters]);
        } catch (Throwable) {
            // El reporte debe continuar aunque el registro de trazabilidad no esté disponible.
        }
    }

    public static function filterSummary(array $filters): array
    {
        $summary=[];
        if ((int)$filters['campaign_id']>0) {
            $c=Database::fetchOne('SELECT name FROM campaigns WHERE id=?',[(int)$filters['campaign_id']]);
            if($c)$summary['Campaña']=(string)$c['name'];
        }
        if ((string)$filters['department']!=='') $summary['Departamento']=(string)$filters['department'];
        if ((string)$filters['municipality']!=='') $summary['Municipio']=(string)$filters['municipality'];
        if ((int)$filters['sede_id']>0) {
            $s=Database::fetchOne('SELECT identificador,nombre_sede FROM sedes WHERE id=?',[(int)$filters['sede_id']]);
            if($s)$summary['Sede']=(string)$s['identificador'].' · '.(string)$s['nombre_sede'];
        }
        if ((string)$filters['status']!=='') $summary['Estado']=self::statusLabel((string)$filters['status']);
        if ((string)$filters['date_from']!=='') $summary['Desde']=(string)$filters['date_from'];
        if ((string)$filters['date_to']!=='') $summary['Hasta']=(string)$filters['date_to'];
        if ((string)$filters['q']!=='') $summary['Búsqueda']=(string)$filters['q'];
        return $summary;
    }

    private static function statusLabel(string $status): string
    {
        if ($status==='') return '';
        $labels=[
            'pendiente'=>'Pendiente','en_diligenciamiento'=>'En diligenciamiento','enviado'=>'Enviado','en_revision'=>'En revisión','devuelto'=>'Devuelto','aprobado'=>'Aprobado','aprobada'=>'Aprobada','cerrado'=>'Cerrado','cerrada'=>'Cerrada','activa'=>'Activa','finalizada'=>'Finalizada','cancelada'=>'Cancelada','programada'=>'Programada','borrador'=>'Borrador',
            'confirmado'=>'Confirmado','confirmada'=>'Confirmada','con_correccion'=>'Con corrección','no_encontrado'=>'No encontrado','trasladado'=>'Trasladado','reparacion'=>'En reparación','almacenado'=>'Almacenado','pendiente_baja'=>'Pendiente de baja','dado_baja'=>'Dado de baja','dado_baja'=>'Dado de baja','en_almacen'=>'En almacén','en_mantenimiento'=>'En mantenimiento','activo'=>'Activo','inactivo'=>'Inactivo','para_baja'=>'Para baja','no_localizado'=>'No localizado','bueno'=>'Bueno','regular'=>'Regular','malo'=>'Malo','inoperativo'=>'Inoperativo',
            'corregido'=>'Corregido','corregida'=>'Corregida','sin_serial'=>'Sin serial','sin_placa'=>'Sin placa','ilegible'=>'Ilegible','pertenece'=>'Pertenece','no_pertenece'=>'No pertenece','otro_usuario'=>'Otro usuario','desconocido'=>'Desconocido',
            'abierta'=>'Abierta','en_gestion'=>'En gestión','resuelta'=>'Resuelta','critica'=>'Crítica','alta'=>'Alta','media'=>'Media','baja'=>'Baja','rechazado'=>'Rechazado','rechazada'=>'Rechazada','cancelado'=>'Cancelado','cancelada'=>'Cancelada',
            'reportado'=>'Reportado','pendiente_aprobacion'=>'Pendiente de aprobación','aplicado'=>'Aplicado','reasignada'=>'Reasignada','trasladado'=>'Trasladado','asignacion_incorrecta'=>'Asignación incorrecta','prestamo'=>'Préstamo','baja'=>'Baja','otro'=>'Otro',
        ];
        return $labels[$status] ?? ucfirst(str_replace('_',' ',$status));
    }

    private static function propertyLabel(string $value): string
    {
        return match($value){'propio'=>'Propio de la RNEC','comodato'=>'Comodato','donado_sin_legalizar'=>'Donado sin legalizar',default=>'Desconocido'};
    }

    private static function qualityLevel(float $quality): string
    {
        return $quality>=95?'Excelente':($quality>=85?'Aceptable':($quality>=70?'Requiere revisión':'Crítica'));
    }
}
