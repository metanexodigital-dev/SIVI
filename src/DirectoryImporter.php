<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/DirectoryImporter.php
 * Propósito: Importa y compara el directorio institucional con el catálogo de sedes.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

final class DirectoryImporter
{
    public static function preview(string $path, string $originalName, bool $allowReapply = false): array
    {
        $reader = new XlsxReader($path);
        $sheet = in_array('Directorio', $reader->sheetNames(), true) ? 'Directorio' : ($reader->sheetNames()[0] ?? '');
        if ($sheet === '') throw new RuntimeException('El archivo no contiene hojas legibles.');
        $hash = hash_file('sha256', $path) ?: null;
        if (!$allowReapply && $hash && Database::fetchOne("SELECT id FROM directory_imports WHERE file_hash=? AND status='completado' LIMIT 1", [$hash])) {
            throw new RuntimeException('Este archivo ya fue aplicado anteriormente.');
        }
        $stats=['processed'=>0,'created'=>0,'updated'=>0,'unchanged'=>0,'duplicates'=>0,'invalid'=>0,'invalid_email'=>0];
        $seen=[]; $errors=[]; $changes=[];
        foreach ($reader->rows($sheet,1) as $rowNo=>$row) {
            $data=self::rowData($row);
            if ($data===null) continue;
            $stats['processed']++;
            if ($data['department']==='' || $data['municipality']==='' || $data['type']==='' || $data['office']==='') {
                $stats['invalid']++; $errors[]=['fila'=>$rowNo,'tipo'=>'crítico','mensaje'=>'Faltan departamento, municipio, tipo o nombre de sede.']; continue;
            }
            $key=$data['identifier']!=='' ? hash('sha256','ID|'.self::norm($data['identifier'])) : self::key($data['department'],$data['municipality'],$data['type'],$data['office']);
            if(isset($seen[$key])){$stats['duplicates']++;$errors[]=['fila'=>$rowNo,'tipo'=>'advertencia','mensaje'=>'Registro duplicado dentro del archivo.'];continue;}
            $seen[$key]=true;
            if($data['email']!=='' && !filter_var($data['email'],FILTER_VALIDATE_EMAIL)){$stats['invalid_email']++;$errors[]=['fila'=>$rowNo,'tipo'=>'advertencia','mensaje'=>'Correo institucional inválido: '.$data['email']];}
            $data['valid_email']=filter_var($data['email'],FILTER_VALIDATE_EMAIL)?$data['email']:null;
            $sede=self::findSede($data,$key);
            if(!$sede){$stats['created']++;$changes[]=['fila'=>$rowNo,'accion'=>'crear','id_sede'=>$data['identifier'],'sede'=>$data['office'],'ubicacion'=>$data['department'].' / '.$data['municipality']];continue;}
            $delta=self::diff($sede,$data);
            if($delta){$stats['updated']++;$changes[]=['fila'=>$rowNo,'accion'=>'actualizar','id_sede'=>$sede['identificador'],'sede'=>$data['office'],'ubicacion'=>$data['department'].' / '.$data['municipality'],'campos'=>array_keys($delta)];}
            else{$stats['unchanged']++;}
        }
        return $stats+['hash'=>$hash,'original_name'=>$originalName,'errors'=>$errors,'changes'=>$changes];
    }

    public static function import(string $path, string $originalName, int $userId, bool $allowReapply = false): array
    {
        $preview=self::preview($path,$originalName,$allowReapply);
        Database::execute("INSERT INTO directory_imports(original_name,file_hash,status,created_by) VALUES(?,?,'procesando',?)", [$originalName,$preview['hash'],$userId]);
        $importId=(int)Database::connection()->lastInsertId();
        $reader = new XlsxReader($path); $sheet=in_array('Directorio',$reader->sheetNames(),true)?'Directorio':($reader->sheetNames()[0]??'');
        $pdo=Database::connection(); $pdo->beginTransaction(); $seen=[];
        try {
            foreach($reader->rows($sheet,1) as $row){
                $d=self::rowData($row); if($d===null||$d['department']===''||$d['municipality']===''||$d['type']===''||$d['office']==='')continue;
                $key=$d['identifier']!==''?hash('sha256','ID|'.self::norm($d['identifier'])):self::key($d['department'],$d['municipality'],$d['type'],$d['office']);
                if(isset($seen[$key]))continue; $seen[$key]=true; $d['valid_email']=filter_var($d['email'],FILTER_VALIDATE_EMAIL)?$d['email']:null;
                $d=self::upsertCatalogs($d);
                $sede=self::findSede($d,$key);
                if(!$sede){
                    $identifier=$d['identifier']!==''?$d['identifier']:'DIR-'.strtoupper(substr(hash('sha256',$key),0,14));
                    Database::execute("INSERT INTO sedes(identificador,cod_dd,departamento,cod_mm,municipio,tipo_sede,nombre_sede,direccion_original,direccion_actual,email_institucional,email_contacto,telefono_contacto,horario_atencion,directorio_clave,directorio_fuente,directorio_sincronizado_en,directorio_estado) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),'coincide')",[$identifier,$d['cod_dd']?:null,$d['department'],$d['cod_mm']?:null,$d['municipality'],$d['type'],$d['office'],$d['address']?:null,$d['address']?:null,$d['valid_email'],$d['valid_email'],$d['phone']?:null,$d['schedule']?:null,$key,'Directorio Institucional RNEC']);
                    $sedeId=(int)$pdo->lastInsertId();
                    Database::execute('INSERT INTO directory_changes(import_id,sede_id,changes_json) VALUES(?,?,?)',[$importId,$sedeId,json_encode(['__created'=>true],JSON_UNESCAPED_UNICODE)]);
                    continue;
                }
                $delta=self::diff($sede,$d);
                Database::execute("UPDATE sedes SET cod_dd=?,departamento=?,cod_mm=?,municipio=?,tipo_sede=?,nombre_sede=?,direccion_original=COALESCE(NULLIF(?,''),direccion_original),email_institucional=?,telefono_contacto=COALESCE(NULLIF(?,''),telefono_contacto),horario_atencion=COALESCE(NULLIF(?,''),horario_atencion),email_contacto=COALESCE(NULLIF(email_contacto,''),?),directorio_clave=?,directorio_fuente='Directorio Institucional RNEC',directorio_sincronizado_en=NOW(),directorio_estado='coincide' WHERE id=?",[$d['cod_dd']?:null,$d['department'],$d['cod_mm']?:null,$d['municipality'],$d['type'],$d['office'],$d['address'],$d['valid_email'],$d['phone'],$d['schedule'],$d['valid_email'],$key,$sede['id']]);
                if($delta)Database::execute('INSERT INTO directory_changes(import_id,sede_id,changes_json) VALUES(?,?,?)',[$importId,$sede['id'],json_encode($delta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
            }
            Database::execute("UPDATE directory_imports SET rows_processed=?,rows_created=?,rows_updated=?,rows_unchanged=?,rows_duplicates=?,rows_invalid=?,invalid_emails=?,status='completado',completed_at=NOW() WHERE id=?",[$preview['processed'],$preview['created'],$preview['updated'],$preview['unchanged'],$preview['duplicates'],$preview['invalid'],$preview['invalid_email'],$importId]);
            $pdo->commit(); return $preview+['import_id'=>$importId];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();Database::execute("UPDATE directory_imports SET status='error',error_message=?,completed_at=NOW() WHERE id=?",[mb_substr($e->getMessage(),0,2000),$importId]);throw $e;}
    }

    public static function rollback(int $importId): array
    {
        $imp=Database::fetchOne("SELECT * FROM directory_imports WHERE id=? AND status='completado'",[$importId]);
        if(!$imp)throw new RuntimeException('La importación no existe o no puede revertirse.');
        $rows=Database::fetchAll('SELECT * FROM directory_changes WHERE import_id=? ORDER BY id DESC',[$importId]);
        $pdo=Database::connection();$pdo->beginTransaction();$restored=0;$deleted=0;
        try{
            foreach($rows as $r){$c=json_decode((string)$r['changes_json'],true)?:[];
                if(!empty($c['__created'])){
                    $inUse=(int)(Database::fetchOne('SELECT COUNT(*) total FROM equipment WHERE current_sede_id=? OR original_sede_id=?',[$r['sede_id'],$r['sede_id']])['total']??0);
                    if($inUse===0){Database::execute('DELETE FROM sedes WHERE id=?',[$r['sede_id']]);$deleted++;} continue;
                }
                $sets=[];$params=[];foreach($c as $field=>$pair){if(!preg_match('/^[a-z_]+$/',$field))continue;$sets[]="$field=?";$params[]=$pair[0]??null;}
                if($sets){$params[]=$r['sede_id'];Database::execute('UPDATE sedes SET '.implode(',',$sets).',directorio_estado=\'diferencias\' WHERE id=?',$params);$restored++;}
            }
            Database::execute("UPDATE directory_imports SET status='revertido',completed_at=NOW() WHERE id=?",[$importId]);$pdo->commit();return ['restored'=>$restored,'deleted'=>$deleted];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    private static function rowData(array $row): ?array
    {
        $identifier = self::value($row, [
            'db_Identificador_Sede', 'Id Sede', 'ID SEDE', 'ID_SEDE', 'IDENTIFICADOR',
        ]);
        $office = self::value($row, [
            'db_Nombre_Sede', 'Nombre de Sede', 'NOMBRE DE SEDE', 'OFICINA', 'Oficina',
        ]);
        $department = self::value($row, [
            'db_DEPARTAMENTO', 'Nombre Departamento', 'NOMBRE DEPARTAMENTO', 'DEPARTAMENTO', 'Departamento',
        ]);
        if ($office === '' || mb_strtoupper($office) === 'OFICINA'
            || mb_strtoupper($department) === 'DEPARTAMENTO'
            || mb_strtoupper($identifier) === 'ID SEDE') {
            return null;
        }
        return [
            'identifier' => $identifier,
            'cod_dd' => self::normalizeTerritorialCode(self::value($row, ['db_Cod_DD', 'Cod Departamento', 'COD DEPARTAMENTO', 'COD_DD']),2),
            'office' => $office,
            'department' => $department,
            'cod_mm' => self::normalizeTerritorialCode(self::value($row, ['db_Cod_MM', 'Cod Municipio', 'COD MUNICIPIO', 'COD_MM']),3),
            'municipality' => self::value($row, ['db_MUNICIPIO', 'Nombre Municipio', 'NOMBRE MUNICIPIO', 'MUNICIPIO', 'Municipio']),
            'type' => self::value($row, ['db_TIPO_SEDE', 'Tipo de Sede', 'TIPO DE SEDE', 'TIPO', 'Tipo']),
            'address' => self::value($row, ['db_Direccion', 'Direccion Sede', 'DIRECCION SEDE', 'DIRECCIÓN', 'DIRECCION', 'Dirección', 'Direccion']),
            'phone' => self::value($row, ['TELÉFONO', 'TELEFONO', 'Teléfono', 'Telefono']),
            'email' => strtolower(self::value($row, ['EMAIL', 'CORREO', 'Email', 'Correo'])),
            'schedule' => self::value($row, ['HORARIO', 'Horario']),
        ];
    }
    private static function findSede(array $d,string $key): ?array {$s=$d['identifier']!==''?Database::fetchOne('SELECT * FROM sedes WHERE identificador=? LIMIT 1',[$d['identifier']]):null;if(!$s)$s=Database::fetchOne('SELECT * FROM sedes WHERE directorio_clave=? LIMIT 1',[$key]);if(!$s)$s=Database::fetchOne('SELECT * FROM sedes WHERE UPPER(TRIM(departamento))=? AND UPPER(TRIM(municipio))=? AND UPPER(TRIM(tipo_sede))=? AND UPPER(TRIM(nombre_sede))=? LIMIT 1',[self::norm($d['department']),self::norm($d['municipality']),self::norm($d['type']),self::norm($d['office'])]);return $s?:null;}
    private static function diff(array $s,array $d): array{$out=[];foreach(['cod_dd'=>$d['cod_dd'],'departamento'=>$d['department'],'cod_mm'=>$d['cod_mm'],'municipio'=>$d['municipality'],'tipo_sede'=>$d['type'],'nombre_sede'=>$d['office'],'direccion_original'=>$d['address'],'email_institucional'=>$d['valid_email'],'telefono_contacto'=>$d['phone'],'horario_atencion'=>$d['schedule']] as $f=>$v){if(trim((string)($s[$f]??''))!==trim((string)$v))$out[$f]=[$s[$f]??null,$v?:null];}return $out;}
    private static function upsertCatalogs(array $d): array
    {
        $departmentCode=$d['cod_dd']!==''?$d['cod_dd']:'00';

        Database::execute(
            'INSERT INTO departments(code,name,active) VALUES(?,?,1) ON DUPLICATE KEY UPDATE name=VALUES(name),active=1',
            [$departmentCode,$d['department']]
        );

        $department=Database::fetchOne(
            'SELECT code FROM departments WHERE name=? LIMIT 1',
            [$d['department']]
        );
        if($department){
            $departmentCode=(string)$department['code'];
        }

        $municipalityCode=$d['cod_mm']!==''?$d['cod_mm']:'000';
        Database::execute(
            'INSERT INTO municipalities(department_code,code,name,active) VALUES(?,?,?,1) ON DUPLICATE KEY UPDATE name=VALUES(name),active=1',
            [$departmentCode,$municipalityCode,$d['municipality']]
        );

        Database::execute(
            'INSERT INTO sede_types(name,active) VALUES(?,1) ON DUPLICATE KEY UPDATE active=1',
            [$d['type']]
        );

        $d['cod_dd']=$departmentCode;
        $d['cod_mm']=$municipalityCode;
        return $d;
    }

    private static function normalizeTerritorialCode(string $value,int $width): string
    {
        $value=trim($value);
        if($value==='')return '';

        if(preg_match('/^([0-9]+)(?:[.,]0+)?$/',$value,$match)===1){
            return str_pad((string)(int)$match[1],$width,'0',STR_PAD_LEFT);
        }

        return mb_strtoupper($value);
    }
    private static function value(array $row,array $names): string {foreach($names as $name){if(array_key_exists($name,$row))return trim((string)$row[$name]);}return '';}
    private static function norm(string $value): string {$value=mb_strtoupper(trim(preg_replace('/\s+/u',' ',$value)??$value));return strtr($value,['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U']);}
    private static function key(string $department,string $municipality,string $type,string $office): string{return hash('sha256',implode('|',[self::norm($department),self::norm($municipality),self::norm($type),self::norm($office)]));}
}
