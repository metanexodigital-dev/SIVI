<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/ImportQuality.php
 * Propósito: Evalúa la calidad de los datos importados y clasifica hallazgos.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

/**
 * Validación previa e indicadores de calidad para las fuentes maestras de SIVI.
 * Ninguna validación modifica sedes, equipos o activos de almacén.
 */
final class ImportQuality
{
    private const ISSUE_LIMIT = 50000;

    /**
     * @return array<string,mixed>
     */
    public static function validateFile(
        string $type,
        string $path,
        string $originalName,
        int $userId,
        ?string $category = null
    ): array {
        $allowed = ['sedes','glpi_computers','warehouse','glpi_asset'];
        if (!in_array($type, $allowed, true)) {
            throw new InvalidArgumentException('El tipo de validación no es reconocido.');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('El archivo cargado no está disponible para validación.');
        }

        $hash = hash_file('sha256', $path) ?: null;
        Database::execute(
            "INSERT INTO import_validations(import_type,asset_category,original_name,stored_filename,file_hash,status,traffic_light,created_by) VALUES(?,?,?,?,?,'validando','rojo',?)",
            [$type, $category ?: null, $originalName, basename($path), $hash, $userId]
        );
        $validationId = (int)Database::connection()->lastInsertId();

        try {
            $reader = new XlsxReader($path);
            $result = match ($type) {
                'sedes' => self::validateSedes($reader),
                'glpi_computers' => self::validateGlpi($reader, 'computador'),
                'warehouse' => self::validateWarehouse($reader),
                'glpi_asset' => self::validateGlpi($reader, (string)$category),
            };

            $critical = (int)($result['critical_count'] ?? 0);
            $warnings = (int)($result['warning_count'] ?? 0);
            $traffic = $critical > 0 ? 'rojo' : ($warnings > 0 ? 'amarillo' : 'verde');
            $status = $critical > 0 ? 'rechazada' : ($warnings > 0 ? 'advertencias' : 'aprobada');
            $reportPath = self::writeIssueReport($validationId, $type, $originalName, $result);

            Database::execute(
                'UPDATE import_validations SET status=?,traffic_light=?,rows_read=?,valid_rows=?,warning_count=?,critical_count=?,summary_json=?,issues_json=?,error_report_path=?,completed_at=NOW() WHERE id=?',
                [
                    $status,
                    $traffic,
                    (int)($result['rows_read'] ?? 0),
                    (int)($result['valid_rows'] ?? 0),
                    $warnings,
                    $critical,
                    json_encode($result['summary'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode(array_slice((array)($result['issues'] ?? []), 0, self::ISSUE_LIMIT), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $reportPath,
                    $validationId,
                ]
            );

            audit('validate_import_file', 'import_validation', $validationId, null, [
                'type' => $type,
                'traffic_light' => $traffic,
                'rows_read' => (int)($result['rows_read'] ?? 0),
                'valid_rows' => (int)($result['valid_rows'] ?? 0),
                'warning_count' => $warnings,
                'critical_count' => $critical,
            ]);

            return array_merge($result, [
                'validation_id' => $validationId,
                'status' => $status,
                'traffic_light' => $traffic,
                'report_path' => $reportPath,
                'file_hash' => $hash,
            ]);
        } catch (Throwable $e) {
            $reference = log_exception_reference($e, 'import_file_validation');
            Database::execute(
                "UPDATE import_validations SET status='error',traffic_light='rojo',error_message=?,completed_at=NOW() WHERE id=?",
                [safe_error_message('No fue posible validar el archivo', $reference), $validationId]
            );
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public static function applicableValidation(int $validationId, string $expectedType): array
    {
        $row = Database::fetchOne('SELECT * FROM import_validations WHERE id=?', [$validationId]);
        if (!$row) {
            throw new RuntimeException('La validación seleccionada no existe.');
        }
        if ((string)$row['import_type'] !== $expectedType) {
            throw new RuntimeException('La validación no corresponde a esta etapa.');
        }
        if (!in_array((string)$row['status'], ['aprobada','advertencias'], true)) {
            throw new RuntimeException('El archivo tiene errores críticos o no terminó su validación.');
        }
        if (!empty($row['applied_at'])) {
            throw new RuntimeException('Esta validación ya fue aplicada anteriormente.');
        }
        $path = dirname(__DIR__) . '/storage/uploads/' . basename((string)$row['stored_filename']);
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('El archivo validado ya no está disponible. Vuelva a cargarlo.');
        }
        $hash = hash_file('sha256', $path) ?: '';
        if ((string)$row['file_hash'] !== '' && !hash_equals((string)$row['file_hash'], $hash)) {
            throw new RuntimeException('El archivo cambió después de su validación. Debe validarlo nuevamente.');
        }
        $row['path'] = $path;
        return $row;
    }

    public static function markApplied(int $validationId, string $entityType, int $entityId): void
    {
        Database::execute(
            "UPDATE import_validations SET status='aplicada',applied_entity_type=?,applied_import_id=?,applied_at=NOW() WHERE id=?",
            [$entityType, $entityId, $validationId]
        );
    }

    /** @return array<string,mixed> */
    public static function currentQuality(): array
    {
        $metrics = [
            'sedes_total' => self::count('SELECT COUNT(*) total FROM sedes'),
            'sedes_incompletas' => self::count("SELECT COUNT(*) total FROM sedes WHERE NULLIF(TRIM(identificador),'') IS NULL OR NULLIF(TRIM(departamento),'') IS NULL OR NULLIF(TRIM(municipio),'') IS NULL OR NULLIF(TRIM(tipo_sede),'') IS NULL OR NULLIF(TRIM(nombre_sede),'') IS NULL"),
            'equipos_activos' => self::count('SELECT COUNT(*) total FROM equipment WHERE active=1'),
            'equipos_sin_sede' => self::count('SELECT COUNT(*) total FROM equipment WHERE active=1 AND current_sede_id IS NULL'),
            'equipos_revision_asociacion' => self::count('SELECT COUNT(*) total FROM equipment WHERE active=1 AND association_review_required=1'),
            'equipos_sin_serial' => self::count("SELECT COUNT(*) total FROM equipment WHERE active=1 AND NULLIF(TRIM(serial_number),'') IS NULL AND NOT (serial_review_required=1 AND serial_review_reason='duplicado')"),
            'seriales_pendientes_duplicado' => self::count("SELECT COUNT(*) total FROM equipment WHERE active=1 AND serial_review_required=1 AND serial_review_reason='duplicado'"),
            'seriales_duplicados' => self::count("SELECT COUNT(*) total FROM (SELECT UPPER(REPLACE(REPLACE(REPLACE(TRIM(serial_number),' ',''),'-',''),'.','')) serial_normalized FROM equipment WHERE active=1 AND NULLIF(TRIM(serial_number),'') IS NOT NULL GROUP BY serial_normalized HAVING COUNT(*)>1) q"),
            'placas_duplicadas' => self::count("SELECT COUNT(*) total FROM (SELECT UPPER(REPLACE(REPLACE(TRIM(COALESCE(NULLIF(placa_almacen,''),placa_rnec)),'_','-'),' ','')) placa_normalized FROM equipment WHERE active=1 AND NULLIF(TRIM(COALESCE(NULLIF(placa_almacen,''),placa_rnec)),'') IS NOT NULL GROUP BY placa_normalized HAVING COUNT(*)>1) q"),
            'conciliacion_ambigua' => self::count("SELECT COUNT(*) total FROM equipment WHERE active=1 AND warehouse_match_status='ambigua'"),
            'sin_coincidencia_almacen' => self::count("SELECT COUNT(*) total FROM equipment WHERE active=1 AND source_origin='glpi' AND warehouse_match_status IN ('no_encontrada','sin_serial')"),
            'validaciones_rechazadas' => self::count("SELECT COUNT(*) total FROM import_validations WHERE status='rechazada' AND created_at >= DATE_SUB(NOW(),INTERVAL 30 DAY)"),
        ];

        $criticalRules = [
            ['code'=>'sedes_incompletas','label'=>'Sedes con datos territoriales obligatorios incompletos','count'=>$metrics['sedes_incompletas']],
            ['code'=>'seriales_duplicados','label'=>'Seriales duplicados en el inventario activo','count'=>$metrics['seriales_duplicados']],
            ['code'=>'placas_duplicadas','label'=>'Placas patrimoniales duplicadas en el inventario activo','count'=>$metrics['placas_duplicadas']],
        ];
        $warningRules = [
            ['code'=>'equipos_sin_sede','label'=>'Equipos activos sin sede asociada','count'=>$metrics['equipos_sin_sede']],
            ['code'=>'equipos_revision_asociacion','label'=>'Equipos con asociación territorial por revisar','count'=>$metrics['equipos_revision_asociacion']],
            ['code'=>'equipos_sin_serial','label'=>'Equipos activos sin serial','count'=>$metrics['equipos_sin_serial']],
            ['code'=>'seriales_pendientes_duplicado','label'=>'Equipos cuyo serial duplicado fue eliminado y debe ser registrado por el usuario','count'=>$metrics['seriales_pendientes_duplicado']],
            ['code'=>'conciliacion_ambigua','label'=>'Coincidencias ambiguas con Almacén','count'=>$metrics['conciliacion_ambigua']],
            ['code'=>'sin_coincidencia_almacen','label'=>'Equipos GLPI sin coincidencia patrimonial','count'=>$metrics['sin_coincidencia_almacen']],
        ];
        $critical = array_sum(array_column($criticalRules, 'count'));
        $warnings = array_sum(array_column($warningRules, 'count'));
        $initialization = InitializationState::status();
        if (!$initialization['ready']) {
            $critical++;
            array_unshift($criticalRules, ['code'=>'initialization','label'=>'Inicialización obligatoria sin completar','count'=>1]);
        }
        $traffic = $critical > 0 ? 'rojo' : ($warnings > 0 ? 'amarillo' : 'verde');

        return [
            'traffic_light' => $traffic,
            'critical_count' => $critical,
            'warning_count' => $warnings,
            'campaigns_allowed' => $initialization['ready'] && $critical === 0,
            'metrics' => $metrics,
            'critical_rules' => $criticalRules,
            'warning_rules' => $warningRules,
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    public static function campaignsAllowed(): bool
    {
        return (bool)self::currentQuality()['campaigns_allowed'];
    }

    /** @return array<string,mixed> */
    private static function validateSedes(XlsxReader $reader): array
    {
        $sheet = self::detectSheet($reader->sheetNames(), ['sede']) ?? ($reader->sheetNames()[0] ?? '');
        if ($sheet === '') throw new RuntimeException('El archivo no contiene hojas legibles.');

        $rowsRead = 0; $validRows = 0; $issues = []; $seenIds = []; $seenComposite = [];
        foreach ($reader->rows($sheet, 1) as $rowNo => $row) {
            $identifier = self::value($row, ['db_Identificador_Sede','Id Sede','ID SEDE','ID_SEDE','IDENTIFICADOR']);
            $department = self::value($row, ['db_DEPARTAMENTO','Nombre Departamento','DEPARTAMENTO','Departamento']);
            $municipality = self::value($row, ['db_MUNICIPIO','Nombre Municipio','MUNICIPIO','Municipio']);
            $type = self::value($row, ['db_TIPO_SEDE','Tipo de Sede','TIPO DE SEDE','Tipo Sede']);
            $office = self::value($row, ['db_Nombre_Sede','Nombre de Sede','NOMBRE DE SEDE','Nombre Sede']);
            $address = self::value($row, ['db_Direccion','Direccion Sede','DIRECCION SEDE','DIRECCIÓN','DIRECCION']);
            if ($identifier === '' && $department === '' && $municipality === '' && $office === '') continue;
            $rowsRead++;
            $missing = [];
            foreach (['identificador'=>$identifier,'departamento'=>$department,'municipio'=>$municipality,'tipo de sede'=>$type,'nombre de sede'=>$office] as $label=>$value) {
                if ($value === '') $missing[] = $label;
            }
            if ($missing) {
                self::issue($issues, $rowNo, 'crítico', 'SEDES_REQUIRED', 'Faltan campos obligatorios: ' . implode(', ', $missing), $identifier ?: $office);
                continue;
            }
            $idKey = self::norm($identifier);
            if (isset($seenIds[$idKey])) {
                self::issue($issues, $rowNo, 'crítico', 'SEDES_DUPLICATE_ID', 'El identificador de sede está duplicado dentro del archivo.', $identifier);
                continue;
            }
            $seenIds[$idKey] = true;
            $composite = implode('|', [self::norm($department),self::norm($municipality),self::norm($type),self::norm($office)]);
            if (isset($seenComposite[$composite])) {
                self::issue($issues, $rowNo, 'advertencia', 'SEDES_DUPLICATE_NAME', 'La misma sede territorial aparece más de una vez con identificadores diferentes.', $office);
            }
            $seenComposite[$composite] = true;
            if ($address === '') self::issue($issues, $rowNo, 'advertencia', 'SEDES_NO_ADDRESS', 'La sede no tiene dirección registrada.', $identifier);
            $validRows++;
        }
        if ($rowsRead === 0) self::issue($issues, 0, 'crítico', 'SEDES_EMPTY', 'No se encontraron registros de sedes.', $sheet);
        return self::result($rowsRead, $validRows, $issues, [
            'sheet'=>$sheet,
            'unique_identifiers'=>count($seenIds),
            'expected_structure'=>'db_Identificador_Sede, db_Cod_DD, db_DEPARTAMENTO, db_Cod_MM, db_MUNICIPIO, db_TIPO_SEDE, db_Nombre_Sede, db_Direccion',
        ]);
    }

    /** @return array<string,mixed> */
    private static function validateGlpi(XlsxReader $reader, string $category): array
    {
        $tokens = $category === 'monitor' ? ['monitor'] : ($category === 'impresora' ? ['impresora'] : ['inventario','equipo','computador','glpi']);
        $sheet = self::detectSheet($reader->sheetNames(), $tokens);
        if ($sheet === null) {
            foreach ($reader->sheetNames() as $candidate) {
                if (!str_contains(self::norm($candidate), 'SEDE')) { $sheet = $candidate; break; }
            }
        }
        if ($sheet === null) throw new RuntimeException('No se encontró una hoja de inventario GLPI utilizable.');

        $rowsRead=0; $validRows=0; $issues=[]; $serialRows=[]; $categoryCounts=['cpu'=>0,'portatil'=>0,'pc_todo_en_uno'=>0,'monitor'=>0,'impresora'=>0,'omitidos'=>0];
        foreach ($reader->rows($sheet, 1) as $rowNo=>$row) {
            $name=self::value($row,['Nombre','Hostname','Name']);
            $serial=self::value($row,['Número de serie','Numero de serie','Serial','Número de Serie']);
            $type=self::value($row,['Tipo','Tipo de equipo','Type']);
            $model=self::value($row,['Modelo','Model']);
            $location=self::value($row,['Localizaciones','Localización','Location']);
            if ($name==='' && $serial==='' && $type==='' && $model==='') continue;
            $rowsRead++;
            $detected=self::glpiCategory($type,$model,$name,$serial,$category);
            if ($detected===null) { $categoryCounts['omitidos']++; continue; }
            $categoryCounts[$detected] = ($categoryCounts[$detected] ?? 0) + 1;
            if ($name==='') self::issue($issues,$rowNo,'advertencia','GLPI_NO_NAME','El registro no tiene hostname o nombre.',$serial);
            $serialKey=self::serial($serial);
            if ($serialKey==='') {
                self::issue($issues,$rowNo,'advertencia','GLPI_NO_SERIAL','El equipo no tiene serial utilizable.',$name);
            } elseif (self::genericSerial($serialKey)) {
                self::issue($issues,$rowNo,'advertencia','GLPI_GENERIC_SERIAL','El serial es genérico o no confiable.',$serial);
            } else {
                $serialRows[$serialKey][]=$rowNo;
            }
            if ($location==='') self::issue($issues,$rowNo,'advertencia','GLPI_NO_LOCATION','El equipo no tiene localización GLPI; la asociación dependerá del hostname o usuario.',$name);
            $validRows++;
        }
        foreach ($serialRows as $serial=>$rows) {
            if (count($rows)>1) {
                foreach ($rows as $rowNo) self::issue($issues,$rowNo,'advertencia','GLPI_DUPLICATE_SERIAL','El serial aparece repetido. SIVI lo dejará en blanco en todos los registros implicados para que sea verificado físicamente por el usuario.',$serial);
            }
        }
        if ($validRows===0) self::issue($issues,0,'crítico','GLPI_EMPTY','No se identificaron activos válidos para la categoría seleccionada.',$sheet);
        return self::result($rowsRead,$validRows,$issues,[
            'sheet'=>$sheet,
            'category'=>$category,
            'category_counts'=>$categoryCounts,
            'duplicate_serial_groups'=>count(array_filter($serialRows, static fn(array $rows): bool => count($rows)>1)),
        ]);
    }

    /** @return array<string,mixed> */
    private static function validateWarehouse(XlsxReader $reader): array
    {
        $sheet=self::detectSheet($reader->sheetNames(),['inventario pc']);
        if ($sheet===null) throw new RuntimeException('No se encontró la hoja "INVENTARIO PC\'S" del reporte de Almacén.');
        $rowsRead=0;$validRows=0;$issues=[];$plates=[];$serialRows=[];$selected=0;$categories=[];
        foreach($reader->rows($sheet,1) as $rowNo=>$row){
            $plateRaw=self::value($row,['Placa / Activo','Placa','Activo']);
            $product=self::value($row,['Nombre Producto']);
            $internalSerial=self::value($row,['Numero Serie Interno','Número Serie Interno']);
            $warehouseSerial=self::value($row,['Número de Serie','Numero de Serie']);
            $reference=self::value($row,['Referencia']);
            if($plateRaw==='' && $product==='' && $internalSerial==='' && $reference==='') continue;
            $rowsRead++;
            if($plateRaw==='') {self::issue($issues,$rowNo,'crítico','WAREHOUSE_NO_PLATE','El activo no tiene placa o identificador patrimonial.',$product);continue;}
            $plateKey=self::norm(preg_replace('/[^A-Za-z0-9]/','',$plateRaw)??$plateRaw);
            if(isset($plates[$plateKey])) self::issue($issues,$rowNo,'crítico','WAREHOUSE_DUPLICATE_PLATE','La placa aparece repetida dentro del archivo de Almacén.',$plateRaw);
            $plates[$plateKey]=true;
            $category=self::warehouseCategory($product);
            if($category===null) continue;
            $selected++;$categories[$category]=($categories[$category]??0)+1;
            $internalSerialKey=self::serial($internalSerial);
            $warehouseSerialKey=self::serial($warehouseSerial);
            $referenceKey=self::serial($reference);

            if(self::genericSerial($internalSerialKey))$internalSerialKey='';
            if(self::genericSerial($warehouseSerialKey))$warehouseSerialKey='';
            if(self::genericSerial($referenceKey))$referenceKey='';

            $serialKey=$internalSerialKey!==''?$internalSerialKey:($warehouseSerialKey!==''?$warehouseSerialKey:$referenceKey);
            if($serialKey!=='')$serialRows['serial:'.$serialKey][]=$rowNo;

            if(normalize_placa_rnec($plateRaw)===null) self::issue($issues,$rowNo,'advertencia','WAREHOUSE_INVALID_PLATE','La placa no cumple la longitud o el formato configurado para la Placa RNEC.',$plateRaw);
            if(in_array($category,['cpu','portatil','pc_todo_en_uno'],true) && $internalSerialKey==='' && $warehouseSerialKey==='' && $referenceKey==='') {
                self::issue($issues,$rowNo,'advertencia','WAREHOUSE_NO_MATCH_KEY','El computador no tiene un serial utilizable para conciliar con GLPI.',$plateRaw);
            }
            $validRows++;
        }
        foreach($serialRows as $serialIndex=>$rows){
            $serial=str_starts_with((string)$serialIndex,'serial:')
                ?substr((string)$serialIndex,7)
                :(string)$serialIndex;
            if(count($rows)>1){
                foreach($rows as $rowNo) self::issue($issues,$rowNo,'advertencia','WAREHOUSE_DUPLICATE_SERIAL','El serial aparece repetido. Al consolidar el inventario activo SIVI lo dejará en blanco para verificación física.',$serial);
            }
        }
        if($selected===0) self::issue($issues,0,'crítico','WAREHOUSE_EMPTY','No se identificaron activos de las categorías requeridas.',$sheet);
        return self::result($rowsRead,$validRows,$issues,[
            'sheet'=>$sheet,
            'selected_assets'=>$selected,
            'category_counts'=>$categories,
            'unique_plates'=>count($plates),
            'duplicate_serial_groups'=>count(array_filter($serialRows,static fn(array $rows):bool=>count($rows)>1)),
        ]);
    }

    /** @return array<string,mixed> */
    private static function result(int $rowsRead,int $validRows,array $issues,array $summary): array
    {
        $critical=count(array_filter($issues,static fn(array $i):bool=>($i['severity']??'')==='crítico'));
        $warnings=count(array_filter($issues,static fn(array $i):bool=>($i['severity']??'')==='advertencia'));
        return ['rows_read'=>$rowsRead,'valid_rows'=>$validRows,'critical_count'=>$critical,'warning_count'=>$warnings,'issues'=>$issues,'summary'=>$summary];
    }

    private static function issue(array &$issues,int|string $row,string $severity,string $code,string $message,mixed $value=''): void
    {
        if(count($issues)>=self::ISSUE_LIMIT)return;

        if($value===null){
            $normalizedValue='';
        }elseif(is_bool($value)){
            $normalizedValue=$value?'true':'false';
        }elseif(is_scalar($value)){
            $normalizedValue=(string)$value;
        }else{
            $encoded=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $normalizedValue=$encoded===false?get_debug_type($value):$encoded;
        }

        $issues[]=[
            'row'=>$row,
            'severity'=>$severity,
            'code'=>$code,
            'message'=>$message,
            'value'=>$normalizedValue,
        ];
    }

    private static function writeIssueReport(int $validationId,string $type,string $originalName,array $result): ?string
    {
        $dir=dirname(__DIR__).'/storage/reports';
        if(!is_dir($dir) && !mkdir($dir,0770,true) && !is_dir($dir)) return null;
        $rows=[['Fila','Nivel','Código','Descripción','Valor relacionado']];
        foreach((array)($result['issues']??[]) as $issue){$rows[]=[(string)($issue['row']??''),(string)($issue['severity']??''),(string)($issue['code']??''),(string)($issue['message']??''),(string)($issue['value']??'')];}
        if(count($rows)===1)$rows[]=['','Información','SIN_INCONSISTENCIAS','No se detectaron inconsistencias en la validación previa.',''];
        $summary=[['Indicador','Valor'],['Archivo',$originalName],['Tipo',$type],['Filas leídas',(string)($result['rows_read']??0)],['Filas válidas',(string)($result['valid_rows']??0)],['Errores críticos',(string)($result['critical_count']??0)],['Advertencias',(string)($result['warning_count']??0)]];
        foreach((array)($result['summary']??[]) as $key=>$value){$summary[]=[(string)$key,is_scalar($value)?(string)$value:json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];}
        $tmp=XlsxWriter::create([
            ['name'=>'Resumen','rows'=>$summary,'header_row'=>1,'freeze_row'=>1,'autofilter'=>false],
            ['name'=>'Inconsistencias','rows'=>$rows,'header_row'=>1,'freeze_row'=>1,'autofilter'=>true],
        ]);
        $relative='validation_'.$validationId.'_'.date('Ymd_His').'.xlsx';
        $target=$dir.'/'.$relative;
        if(!@rename($tmp,$target)){if(!@copy($tmp,$target)){@unlink($tmp);return null;}@unlink($tmp);}return 'storage/reports/'.$relative;
    }

    private static function detectSheet(array $names,array $tokens): ?string
    {
        foreach($tokens as $token){$tokenN=self::norm($token);foreach($names as $name){if(str_contains(self::norm($name),$tokenN))return $name;}}
        return null;
    }
    private static function value(array $row,array $names): string {foreach($names as $name){if(array_key_exists($name,$row))return trim((string)$row[$name]);}return '';}
    private static function norm(string $value): string {$value=mb_strtoupper(trim(preg_replace('/\s+/u',' ',$value)??$value));return strtr($value,['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);}
    private static function serial(string $value): string {return preg_replace('/[^A-Z0-9]/','',self::norm($value))??'';}
    private static function tokenNorm(string $value): string {$value=self::norm($value);$normalized=preg_replace('/[^A-Z0-9]+/u',' ',$value);return trim($normalized??'');}
    private static function genericSerial(string $serial): bool {return in_array($serial,['N/A','NA','S/N','SN','SINSERIAL','SINNUMERO','UNKNOWN','DESCONOCIDO','NULO','NOAPLICA','0','00000000'],true)||preg_match('/^(0+|X+|N?A+)$/',$serial)===1;}
    private static function glpiCategory(string $type,string $model,string $name,string $serial,string $requested): ?string
    {
        // Usa la misma normalización, sin distinguir signos de puntuación, que Importer::isComputerInventoryRow().
        // Ejemplo: "Space-Saving" debe interpretarse como "space saving" durante la validación y la importación.
        $typeN=self::tokenNorm($type);
        $probe=self::tokenNorm($type.' '.$name.' '.$model);
        if($requested==='monitor') return (str_contains($probe,'MONITOR')||str_contains($probe,'PANTALLA'))?'monitor':null;
        if($requested==='impresora') return (str_contains($probe,'IMPRESORA')||str_contains($probe,'PRINTER')||str_contains($probe,'MULTIFUNCIONAL')||str_contains($probe,'PLOTTER'))?'impresora':null;
        foreach(['HYPER V','VIRTUAL MACHINE','MAQUINA VIRTUAL','DOCKING STATION','DOCK STATION','RACK MOUNT','SERVIDOR','SERVER','SWITCH','ROUTER','MONITOR','PANTALLA','IMPRESORA','PRINTER','SCANNER','SCANER','ESCANER','UPS'] as $excluded){
            if(str_contains($probe,$excluded))return null;
        }
        $accepted=false;
        foreach(['DESKTOP','LOW PROFILE DESKTOP','MINI TOWER','NOTEBOOK','ALL IN ONE','MINI PC','TOWER','CONVERTIBLE','SPACE SAVING','LAPTOP','PORTATIL','COMPUTADOR','COMPUTER','WORKSTATION','AIO'] as $candidate){
            if(str_contains($typeN,$candidate)){$accepted=true;break;}
        }
        if(!$accepted && in_array($typeN,['','UNKNOWN','DESCONOCIDO'],true)){
            $accepted=trim($name)!=='' && self::serial($serial)!=='';
        }
        if(!$accepted)return null;
        if(str_contains($probe,'TODO EN UNO')||str_contains($probe,'ALL IN ONE')||preg_match('/(^| )AIO( |$)/',$probe)===1||preg_match('/(^| )IMAC( |$)/',$probe)===1)return 'pc_todo_en_uno';
        if(str_contains($probe,'PORTATIL')||str_contains($probe,'LAPTOP')||str_contains($probe,'NOTEBOOK'))return 'portatil';
        return 'cpu';
    }
    private static function warehouseCategory(string $product): ?string
    {
        $v=self::norm($product);return match(true){$v==='CPU'=>'cpu',$v==='COMPUTADOR PORTATIL'=>'portatil',$v==='COMPUTADOR TODO EN UNO'=>'pc_todo_en_uno',str_starts_with($v,'MONITOR')=>'monitor',$v==='IMPRESORA'=>'impresora',$v==='SCANNER'=>'escaner',$v==='UPS'=>'ups',default=>null};
    }
    private static function count(string $sql,array $params=[]): int {try{return (int)(Database::fetchOne($sql,$params)['total']??0);}catch(Throwable){return 0;}}
}
