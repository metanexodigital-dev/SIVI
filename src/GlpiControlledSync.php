<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/GlpiControlledSync.php
 * Propósito: Controla la consulta, vista previa y sincronización autorizada desde GLPI hacia SIVI.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

final class GlpiControlledSync
{
    private const PREFIX = 'glpi.';
    private const TYPES = [
        'Computer' => 'cpu',
        'Monitor' => 'monitor',
        'Printer' => 'impresora',
        'Peripheral' => 'escaner',
    ];

    public static function ensureSchema(?PDO $pdo = null): void
    {
        // El esquema GLPI se provisiona en database/schema.sql.
        // SIVI no ejecuta DDL con la cuenta operativa.
    }

    /** @return array<string,mixed> */
    public static function config(): array
    {
        $get=static fn(string $key,string $default=''):string=>AppSettings::get(self::PREFIX.$key,$default);
        return [
            'api_version'=>in_array($get('api_version','v1'),['v1','v2'],true)?$get('api_version','v1'):'v1',
            'base_url'=>rtrim($get('base_url',''),'/'),
            'verify_tls'=>$get('verify_tls','1')!=='0',
            'app_token_configured'=>$get('app_token','')!=='',
            'user_token_configured'=>$get('user_token','')!=='',
            'client_id'=>$get('client_id',''),
            'client_secret_configured'=>$get('client_secret','')!=='',
            'username'=>$get('username',''),
            'password_configured'=>$get('password','')!=='',
            'scanner_keywords'=>$get('scanner_keywords','scanner,escaner,escáner,scanjet,fujitsu fi,kodak alaris,canon dr,brother ads'),
        ];
    }

    /** @param array<string,mixed> $input */
    public static function saveConfig(array $input, ?int $userId): void
    {
        if(!SecretVault::isConfigured())throw new RuntimeException('APP_ENCRYPTION_KEY debe estar configurada antes de guardar credenciales GLPI.');
        $version=in_array((string)($input['api_version']??'v1'),['v1','v2'],true)?(string)$input['api_version']:'v1';
        $url=rtrim(trim((string)($input['base_url']??'')),'/');
        if($url===''||filter_var($url,FILTER_VALIDATE_URL)===false||!preg_match('#^https://#i',$url))throw new InvalidArgumentException('Ingrese una URL base HTTPS válida para GLPI.');
        $values=[
            'glpi.api_version'=>$version,
            'glpi.base_url'=>$url,
            'glpi.verify_tls'=>!empty($input['verify_tls'])?'1':'0',
            'glpi.client_id'=>trim((string)($input['client_id']??'')),
            'glpi.username'=>trim((string)($input['username']??'')),
            'glpi.scanner_keywords'=>trim((string)($input['scanner_keywords']??'scanner,escaner,escáner,scanjet,fujitsu fi,kodak alaris,canon dr,brother ads')),
        ];
        foreach(['app_token','user_token','client_secret','password'] as $secret){
            $raw=(string)($input[$secret]??'');
            if($raw!=='')$values['glpi.'.$secret]=SecretVault::encrypt($raw);
        }
        self::setRaw($values);
        if(function_exists('audit'))audit('save_glpi_config','system',null,null,['api_version'=>$version,'base_url'=>$url,'verify_tls'=>!empty($input['verify_tls']),'updated_by'=>$userId]);
    }

    public static function saveMapping(string $locationKey,string $locationName,int $sedeId,?int $userId): void
    {
        self::ensureSchema();$locationKey=trim($locationKey);
        if($locationKey==='')throw new InvalidArgumentException('La localización GLPI no es válida.');
        if(!Database::fetchOne('SELECT id FROM sedes WHERE id=?',[$sedeId]))throw new InvalidArgumentException('La sede seleccionada no existe.');
        Database::execute('INSERT INTO glpi_location_mappings(location_key,location_name,sede_id,active,updated_by) VALUES(?,?,?,1,?) ON DUPLICATE KEY UPDATE location_name=VALUES(location_name),sede_id=VALUES(sede_id),active=1,updated_by=VALUES(updated_by)',[$locationKey,$locationName,$sedeId,$userId]);
    }

    /** @return array<string,mixed> */
    public static function testConnection(): array
    {
        $config=self::runtimeConfig();
        if($config['api_version']==='v2'){
            $token=self::v2Token($config);
            $result=self::request('GET',$config['base_url'].'/api.php/v2.1/status',['Authorization: Bearer '.$token],null,(bool)$config['verify_tls']);
            if($result['status']>=400){
                $result=self::request('GET',$config['base_url'].'/api.php/status',['Authorization: Bearer '.$token],null,(bool)$config['verify_tls']);
            }
            return ['ok'=>$result['status']>=200&&$result['status']<300,'api_version'=>'v2','http_status'=>$result['status'],'message'=>$result['status']<400?'Conexión OAuth2 establecida con GLPI.':'GLPI respondió HTTP '.$result['status'].'.'];
        }
        $session=self::v1Session($config);
        self::v1KillSession($config,$session);
        return ['ok'=>true,'api_version'=>'v1','message'=>'Sesión de solo consulta establecida y cerrada correctamente.'];
    }

    /** @return array<string,mixed> */
    public static function createPreview(?int $userId,int $limitPerType=250): array
    {
        self::ensureSchema();$config=self::runtimeConfig();$limitPerType=max(1,min(1000,$limitPerType));
        Database::execute("INSERT INTO glpi_sync_runs(api_version,status,created_by) VALUES(?,'preparando',?)",[$config['api_version'],$userId]);
        $runId=(int)Database::connection()->lastInsertId();
        $counts=['total'=>0,'nuevo'=>0,'actualizar'=>0,'vincular'=>0,'conflicto'=>0,'sin_sede'=>0];
        try{
            $assets=$config['api_version']==='v2'?self::fetchV2Assets($config,$limitPerType):self::fetchV1Assets($config,$limitPerType);
            foreach($assets as $asset){
                $decision=self::decide($asset);
                $counts['total']++;$counts[$decision['decision']]++;
                Database::execute('INSERT INTO glpi_sync_items(run_id,glpi_itemtype,glpi_id,source_key,asset_category,name,serial_number,placa_rnec,location_key,location_name,candidate_sede_id,existing_equipment_id,decision,decision_reason,raw_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[
                    $runId,$asset['itemtype'],$asset['id'],$asset['source_key'],$asset['asset_category'],$asset['name'],$asset['serial_number'],$asset['placa_rnec'],$asset['location_key'],$asset['location_name'],$decision['sede_id'],$decision['equipment_id'],$decision['decision'],$decision['reason'],json_encode($asset['raw'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
                ]);
            }
            Database::execute("UPDATE glpi_sync_runs SET status='vista_previa',total_items=?,new_items=?,updated_items=?,linked_items=?,conflict_items=?,unmapped_items=? WHERE id=?",[$counts['total'],$counts['nuevo'],$counts['actualizar'],$counts['vincular'],$counts['conflicto'],$counts['sin_sede'],$runId]);
            return ['ok'=>true,'run_id'=>$runId,'counts'=>$counts];
        }catch(Throwable $e){Database::execute("UPDATE glpi_sync_runs SET status='error',error_message=? WHERE id=?",[mb_substr($e->getMessage(),0,2000),$runId]);throw $e;}
    }

    /** @return array<string,mixed> */
    public static function applyPreview(int $runId,?int $userId): array
    {
        self::ensureSchema();
        $run=Database::fetchOne("SELECT * FROM glpi_sync_runs WHERE id=? AND status='vista_previa'",[$runId]);
        if(!$run)throw new RuntimeException('La vista previa no existe o ya fue aplicada.');
        $items=Database::fetchAll("SELECT * FROM glpi_sync_items WHERE run_id=? AND decision IN ('nuevo','actualizar','vincular') AND candidate_sede_id IS NOT NULL ORDER BY id",[$runId]);
        Database::execute("INSERT INTO imports(filename,original_name,file_hash,source_kind,status,created_by,completed_at) VALUES(?,?,?,'mixto','completado',?,NOW())",['glpi-api-run-'.$runId.'.json','Integración GLPI API · ejecución '.$runId,hash('sha256','glpi-run-'.$runId),$userId]);
        $importId=(int)Database::connection()->lastInsertId();$applied=0;
        foreach($items as $item){
            $raw=json_decode((string)$item['raw_json'],true);if(!is_array($raw))$raw=[];
            $equipmentId=(int)($item['existing_equipment_id']??0);
            $params=[
                (string)$item['source_key'],
                (string)$item['name'],
                self::scalar($raw['users_id']??$raw['user']??''),
                self::dateTimeOrNull($raw['date_mod']??$raw['updated_at']??null),
                self::scalar($raw['states_id']??$raw['status']??''),
                self::scalar($raw['manufacturer']??$raw['manufacturers_id']??''),
                (string)$item['serial_number'],
                self::scalar($raw['itemtype']??$item['glpi_itemtype']),
                (string)$item['asset_category'],
                self::scalar($raw['model']??$raw['models_id']??''),
                self::scalar($raw['operatingsystems_id']??$raw['operating_system']??''),
                self::scalar($raw['processors_id']??$raw['processor']??''),
                self::scalar($raw['memory']??''),
                (string)$item['location_name'],
                (string)$item['placa_rnec'],
                (int)$item['candidate_sede_id']
            ];
            if($equipmentId>0){
                Database::execute("UPDATE equipment SET source_key=?,name=?,alternate_user=?,last_contact=?,source_state=?,manufacturer=?,serial_number=?,equipment_type=?,asset_category=?,category_source='glpi',source_origin='glpi',model=?,os_name=?,processor=?,memory=?,source_location=?,placa_rnec=COALESCE(NULLIF(?,''),placa_rnec),current_sede_id=?,association_method='location',association_confidence='alta',association_review_required=0,updated_at=NOW() WHERE id=?",array_merge($params,[$equipmentId]));
            }else{
                $insertParams=array_merge([$importId],array_slice($params,0,15),[$params[15],$params[15]]);
                Database::execute("INSERT INTO equipment(import_id,source_key,name,alternate_user,last_contact,source_state,manufacturer,serial_number,equipment_type,asset_category,category_source,source_origin,model,os_name,processor,memory,source_location,placa_rnec,original_sede_id,current_sede_id,association_method,association_confidence,association_review_required,inventory_status,active) VALUES(?,?,?,?,?,?,?,?,?,?,'glpi','glpi',?,?,?,?,?,?,?,?,'location','alta',0,'desconocido',1)",$insertParams);
                $equipmentId=(int)Database::connection()->lastInsertId();
            }
            Database::execute('INSERT INTO glpi_asset_links(glpi_itemtype,glpi_id,equipment_id,source_key,last_sync_run_id) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE equipment_id=VALUES(equipment_id),source_key=VALUES(source_key),last_sync_run_id=VALUES(last_sync_run_id)',[$item['glpi_itemtype'],$item['glpi_id'],$equipmentId,$item['source_key'],$runId]);
            Database::execute('UPDATE glpi_sync_items SET existing_equipment_id=?,applied_at=NOW() WHERE id=?',[$equipmentId,$item['id']]);$applied++;
        }
        Database::execute("UPDATE glpi_sync_runs SET status='aplicado',applied_items=?,applied_at=NOW() WHERE id=?",[$applied,$runId]);
        if(function_exists('audit'))audit('apply_glpi_preview','glpi_sync_run',$runId,$run,['applied_items'=>$applied,'performed_by'=>$userId]);
        return ['ok'=>true,'run_id'=>$runId,'applied'=>$applied];
    }

    /** @return array<int,array<string,mixed>> */
    public static function recentRuns(): array { self::ensureSchema(); return Database::fetchAll('SELECT r.*,u.name created_name FROM glpi_sync_runs r LEFT JOIN users u ON u.id=r.created_by ORDER BY r.id DESC LIMIT 20'); }
    /** @return array<int,array<string,mixed>> */
    public static function runItems(int $runId): array { self::ensureSchema(); return Database::fetchAll('SELECT i.*,s.identificador,s.nombre_sede FROM glpi_sync_items i LEFT JOIN sedes s ON s.id=i.candidate_sede_id WHERE i.run_id=? ORDER BY FIELD(i.decision,\'conflicto\',\'sin_sede\',\'nuevo\',\'actualizar\',\'vincular\',\'omitido\'),i.id LIMIT 1000',[$runId]); }
    /** @return array<int,array<string,mixed>> */
    public static function unmappedLocations(): array { self::ensureSchema(); return Database::fetchAll("SELECT location_key,MAX(location_name) location_name,COUNT(*) items FROM glpi_sync_items WHERE location_key IS NOT NULL AND location_key<>'' AND candidate_sede_id IS NULL GROUP BY location_key ORDER BY items DESC,location_name"); }

    /** @param array<string,mixed> $asset @return array{decision:string,reason:string,equipment_id:?int,sede_id:?int} */
    private static function decide(array $asset): array
    {
        $mapping=$asset['location_key']!==''?Database::fetchOne('SELECT sede_id FROM glpi_location_mappings WHERE location_key=? AND active=1',[$asset['location_key']]):null;
        $sedeId=$mapping?(int)$mapping['sede_id']:null;
        $bySource=Database::fetchOne('SELECT id FROM equipment WHERE source_key=? LIMIT 1',[$asset['source_key']]);
        if($bySource)return ['decision'=>$sedeId?'actualizar':'sin_sede','reason'=>$sedeId?'Activo vinculado por identidad GLPI estable.':'La localización GLPI no está homologada.','equipment_id'=>(int)$bySource['id'],'sede_id'=>$sedeId];
        $matches=[];
        if($asset['serial_number']!=='')$matches=Database::fetchAll('SELECT id FROM equipment WHERE UPPER(REPLACE(REPLACE(REPLACE(serial_number,\'-\',\'\'),\' \',\'\'),\'.\',\'\'))=UPPER(REPLACE(REPLACE(REPLACE(?,\'-\',\'\'),\' \',\'\'),\'.\',\'\')) LIMIT 3',[$asset['serial_number']]);
        if($asset['placa_rnec']!==''){
            $plateMatches=Database::fetchAll('SELECT id FROM equipment WHERE placa_rnec=? LIMIT 3',[$asset['placa_rnec']]);
            foreach($plateMatches as $m){$matches[(int)$m['id']]=$m;}
        }
        $ids=[];foreach($matches as $m)$ids[(int)$m['id']]=true;
        if(count($ids)>1)return ['decision'=>'conflicto','reason'=>'Serial o placa coincide con más de un activo SIVI. Requiere revisión manual.','equipment_id'=>null,'sede_id'=>$sedeId];
        if(count($ids)===1){$id=(int)array_key_first($ids);return ['decision'=>$sedeId?'vincular':'sin_sede','reason'=>$sedeId?'Coincidencia única por serial o placa.':'Coincidencia encontrada, pero falta homologar la localización.','equipment_id'=>$id,'sede_id'=>$sedeId];}
        return ['decision'=>$sedeId?'nuevo':'sin_sede','reason'=>$sedeId?'Activo nuevo listo para importar.':'La localización GLPI no está homologada.','equipment_id'=>null,'sede_id'=>$sedeId];
    }

    /** @return array<string,mixed> */
    private static function runtimeConfig(): array
    {
        $config=self::config();if($config['base_url']==='')throw new RuntimeException('Configure primero la URL de GLPI.');
        foreach(['app_token','user_token','client_secret','password'] as $key){$encrypted=AppSettings::get('glpi.'.$key,'');$config[$key]=$encrypted!==''?SecretVault::decrypt($encrypted):'';}
        if($config['api_version']==='v1'&&($config['user_token']===''||$config['app_token']===''))throw new RuntimeException('Configure App Token y User Token para GLPI API V1.');
        if($config['api_version']==='v2'&&($config['client_id']===''||$config['client_secret']===''||$config['username']===''||$config['password']===''))throw new RuntimeException('Configure Client ID, Client Secret, usuario y contraseña técnica para GLPI API V2.');
        return $config;
    }

    /** @param array<string,mixed> $config */
    private static function v1Session(array $config): string
    {
        $headers=['App-Token: '.$config['app_token'],'Authorization: user_token '.$config['user_token'],'Accept: application/json'];
        $response=self::request('GET',$config['base_url'].'/apirest.php/initSession',$headers,null,(bool)$config['verify_tls']);
        $token=(string)($response['json']['session_token']??'');if($response['status']<200||$response['status']>=300||$token==='')throw new RuntimeException('GLPI API V1 no inició sesión. HTTP '.$response['status'].'.');return $token;
    }
    /** @param array<string,mixed> $config */
    private static function v1KillSession(array $config,string $session): void { try{self::request('GET',$config['base_url'].'/apirest.php/killSession',['App-Token: '.$config['app_token'],'Session-Token: '.$session],null,(bool)$config['verify_tls']);}catch(Throwable){} }
    /** @param array<string,mixed> $config */
    private static function v2Token(array $config): string
    {
        $body=http_build_query(['grant_type'=>'password','client_id'=>$config['client_id'],'client_secret'=>$config['client_secret'],'username'=>$config['username'],'password'=>$config['password'],'scope'=>'api']);
        $response=self::request('POST',$config['base_url'].'/api.php/token',['Content-Type: application/x-www-form-urlencoded','Accept: application/json'],$body,(bool)$config['verify_tls']);
        $token=(string)($response['json']['access_token']??'');if($response['status']<200||$response['status']>=300||$token==='')throw new RuntimeException('GLPI API V2 no entregó un token OAuth2. HTTP '.$response['status'].'.');return $token;
    }

    /** @param array<string,mixed> $config @return array<int,array<string,mixed>> */
    private static function fetchV1Assets(array $config,int $limit): array
    {
        $session=self::v1Session($config);$result=[];
        try{foreach(self::TYPES as $type=>$category){$url=$config['base_url'].'/apirest.php/'.$type.'?range=0-'.($limit-1).'&expand_dropdowns=true';$response=self::request('GET',$url,['App-Token: '.$config['app_token'],'Session-Token: '.$session,'Accept: application/json'],null,(bool)$config['verify_tls']);if($response['status']>=400)continue;foreach((array)$response['json'] as $raw){if(is_array($raw)){ $normalized=self::normalizeAsset($type,$category,$raw,$config); if($normalized!==null)$result[]=$normalized; }}}}finally{self::v1KillSession($config,$session);}return $result;
    }
    /** @param array<string,mixed> $config @return array<int,array<string,mixed>> */
    private static function fetchV2Assets(array $config,int $limit): array
    {
        $token=self::v2Token($config);$result=[];
        foreach(self::TYPES as $type=>$category){$urls=[$config['base_url'].'/api.php/v2.1/'.$type.'?limit='.$limit,$config['base_url'].'/api.php/'.$type.'?limit='.$limit];$response=null;foreach($urls as $url){$candidate=self::request('GET',$url,['Authorization: Bearer '.$token,'Accept: application/json'],null,(bool)$config['verify_tls']);if($candidate['status']<400){$response=$candidate;break;}}if(!$response)continue;$payload=$response['json']['data']??$response['json']['items']??$response['json'];foreach((array)$payload as $raw){if(is_array($raw)){ $normalized=self::normalizeAsset($type,$category,$raw,$config); if($normalized!==null)$result[]=$normalized; }}}
        return $result;
    }
    /** @param array<string,mixed> $raw @param array<string,mixed> $config @return array<string,mixed>|null */
    private static function normalizeAsset(string $type,string $category,array $raw,array $config): ?array
    {
        $id=(int)($raw['id']??0);
        if($id<1)return null;
        if($type==='Peripheral' && !self::isScannerPeripheral($raw,(string)($config['scanner_keywords']??'')))return null;
        $location=$raw['location']??$raw['locations_id']??$raw['location_id']??'';
        if(is_array($location)){$locationName=self::scalar($location['name']??$location['completename']??'');$locationKey=self::scalar($location['id']??$locationName);}else{$locationName=self::scalar($location);$locationKey=$locationName;}
        $plateRaw=self::scalar($raw['otherserial']??$raw['asset_tag']??$raw['inventory_number']??'');$plateResult=PlatePolicy::validate($plateRaw,PlatePolicy::totalCharacters(Database::connection()),false);
        return ['itemtype'=>$type,'id'=>$id,'source_key'=>substr('glpi-api:'.$type.':'.$id,0,64),'asset_category'=>$category,'name'=>self::scalar($raw['name']??($type.' '.$id)),'serial_number'=>self::scalar($raw['serial']??$raw['serial_number']??''),'placa_rnec'=>$plateResult['ok']?(string)$plateResult['value']:'','location_key'=>mb_substr($locationKey,0,190),'location_name'=>mb_substr($locationName,0,255),'raw'=>$raw];
    }


    /** @param array<string,mixed> $raw */
    private static function isScannerPeripheral(array $raw,string $keywordList): bool
    {
        $haystack=implode(' ',[
            self::scalar($raw['name']??''),
            self::scalar($raw['comment']??''),
            self::scalar($raw['manufacturer']??$raw['manufacturers_id']??''),
            self::scalar($raw['model']??$raw['models_id']??''),
        ]);
        $normalize=static function(string $value): string {
            $value=mb_strtolower($value);
            return strtr($value,['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
        };
        $haystack=$normalize($haystack);
        $keywords=preg_split('/[,;\n]+/u',$keywordList)?:[];
        foreach($keywords as $keyword){
            $keyword=$normalize(trim($keyword));
            if($keyword!=='' && str_contains($haystack,$keyword))return true;
        }
        return false;
    }

    /** @return array{status:int,json:mixed,body:string} */
    private static function request(string $method,string $url,array $headers,?string $body,bool $verifyTls): array
    {
        if(!function_exists('curl_init'))throw new RuntimeException('La extensión cURL de PHP es obligatoria para consultar GLPI.');
        $ch=curl_init($url);if($ch===false)throw new RuntimeException('No fue posible iniciar cURL.');
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>60,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>$verifyTls,CURLOPT_SSL_VERIFYHOST=>$verifyTls?2:0]);
        if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,$body);
        $response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);
        if($response===false)throw new RuntimeException('Error de conexión con GLPI: '.$error);
        $json=json_decode((string)$response,true);return ['status'=>$status,'json'=>$json,'body'=>(string)$response];
    }
    /** @param array<string,string> $values */
    private static function setRaw(array $values): void { foreach($values as $key=>$value)Database::execute('INSERT INTO app_settings(setting_key,setting_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW()',[$key,$value]); }
    private static function scalar(mixed $value): string
    {
        if(is_scalar($value))return mb_substr(trim((string)$value),0,1000);
        return '';
    }

    private static function dateTimeOrNull(mixed $value): ?string
    {
        $raw=self::scalar($value);
        if($raw==='')return null;
        $timestamp=strtotime($raw);
        return $timestamp===false?null:date('Y-m-d H:i:s',$timestamp);
    }
}
