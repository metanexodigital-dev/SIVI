<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/SiteQualityGate.php
 * Propósito: Evalúa la calidad de los datos antes de permitir el cierre de una sede.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

final class SiteQualityGate
{
    public static function ensureSchema(?PDO $pdo = null): void
    {
        // Las tablas de calidad forman parte de database/schema.sql.
        // La cuenta web no requiere privilegios CREATE/ALTER en producción.
    }

    /** @return array{run_id:int,score:int,blocking_count:int,warning_count:int,status:string,findings:array<int,array<string,mixed>>} */
    public static function run(int $campaignId, int $sedeId, ?int $userId): array
    {
        self::ensureSchema();
        $findings = [];
        $add = static function(string $severity, string $code, string $title, string $detail, ?int $equipmentId = null, ?string $route = null) use (&$findings): void {
            $findings[] = compact('severity','code','title','detail','equipmentId','route');
        };

        $pending = Database::fetchAll(
            "SELECT e.id,e.name FROM campaign_equipment ce JOIN equipment e ON e.id=ce.equipment_id "
            . "LEFT JOIN equipment_validations ev ON ev.campaign_id=ce.campaign_id AND ev.equipment_id=ce.equipment_id AND ev.reported_by_sede_id=ce.sede_id "
            . "WHERE ce.campaign_id=? AND ce.sede_id=? AND (ev.id IS NULL OR ev.validation_status='pendiente') ORDER BY e.name LIMIT 100",
            [$campaignId,$sedeId]
        );
        foreach ($pending as $row) {
            $id=(int)$row['id'];
            $add('bloqueante','EQUIPMENT_PENDING','Equipo pendiente de validación','Debe completar y guardar la validación de “'.(string)$row['name'].'”.',$id,route_url('equipo_validar',['id'=>$id,'campaign_id'=>$campaignId]));
        }

        $validations = Database::fetchAll(
            "SELECT e.id,e.name,e.serial_number,e.placa_rnec,e.ownership_type,ev.id validation_id,ev.serial_reported,ev.placa_reported,ev.ownership_type validation_ownership,ev.physical_condition,ev.destination_sede_id,ev.disposal_date,ev.disposal_document,ev.notes "
            . "FROM campaign_equipment ce JOIN equipment e ON e.id=ce.equipment_id "
            . "JOIN equipment_validations ev ON ev.campaign_id=ce.campaign_id AND ev.equipment_id=ce.equipment_id AND ev.reported_by_sede_id=ce.sede_id "
            . "WHERE ce.campaign_id=? AND ce.sede_id=?",
            [$campaignId,$sedeId]
        );
        $serialGroups=[];$plateGroups=[];$totalChars=PlatePolicy::totalCharacters(Database::connection());
        foreach($validations as $row){
            $id=(int)$row['id'];$name=(string)$row['name'];
            $serial=trim((string)($row['serial_reported'] ?: $row['serial_number']));
            if($serial==='')$add('bloqueante','SERIAL_MISSING','Serial obligatorio pendiente','Registre el número de serie verificado de “'.$name.'”.',$id,route_url('equipo_validar',['id'=>$id,'campaign_id'=>$campaignId]));
            else{$key=mb_strtoupper(preg_replace('/[^A-Z0-9]/iu','',$serial)??'');if($key!=='')$serialGroups[$key][]=['id'=>$id,'name'=>$name];}
            $ownership=(string)($row['validation_ownership'] ?: $row['ownership_type']);
            if($ownership===''||$ownership==='desconocido')$add('bloqueante','OWNERSHIP_UNDEFINED','Tipo de propiedad pendiente','Defina si “'.$name.'” es propio, comodato o donado sin legalizar.',$id,route_url('equipo_validar',['id'=>$id,'campaign_id'=>$campaignId]));
            $plateRaw=trim((string)($row['placa_reported'] ?: $row['placa_rnec']));
            if($ownership==='propio'){
                $noteParts=validation_notes_parse((string)($row['notes']??''));
                $plateUnavailableReason=trim((string)($noteParts['placa_unavailable_reason']??''));
                if($plateRaw===''){
                    if($plateUnavailableReason===''){
                        $add('bloqueante','PLATE_INVALID','Placa RNEC obligatoria o justificación requerida',$name.': registre la placa o explique por qué no puede visualizarse físicamente.',$id,route_url('equipo_validar',['id'=>$id,'campaign_id'=>$campaignId]));
                    } else {
                        $add('advertencia','PLATE_NOT_VISIBLE_JUSTIFIED','Placa RNEC no visible físicamente',$name.': '.$plateUnavailableReason,$id,route_url('equipo_validar',['id'=>$id,'campaign_id'=>$campaignId]));
                    }
                } else {
                    $result=PlatePolicy::validate($plateRaw,$totalChars,true);
                    if(!$result['ok'])$add('bloqueante','PLATE_INVALID','Placa RNEC inválida',$name.': '.(string)$result['message'],$id,route_url('equipo_validar',['id'=>$id,'campaign_id'=>$campaignId]));
                }
            }
            if($plateRaw!==''){$plate=PlatePolicy::format($plateRaw,$totalChars);if($plate!=='')$plateGroups[$plate][]=['id'=>$id,'name'=>$name];}
            if((string)$row['physical_condition']==='trasladado' && empty($row['destination_sede_id']))$add('bloqueante','TRANSFER_DESTINATION_MISSING','Traslado sin sede destino','Seleccione la sede destino de “'.$name.'”.',$id,route_url('equipo_validar',['id'=>$id,'campaign_id'=>$campaignId]));
            if((string)$row['physical_condition']==='dado_baja' && (empty($row['disposal_date'])||trim((string)$row['disposal_document'])===''))$add('bloqueante','DISPOSAL_SUPPORT_MISSING','Baja sin fecha o soporte','Complete la fecha y resolución o acta de baja de “'.$name.'”.',$id,route_url('equipo_validar',['id'=>$id,'campaign_id'=>$campaignId]));
        }
        foreach($serialGroups as $items){if(count($items)>1){$names=implode(', ',array_column($items,'name'));$add('bloqueante','DUPLICATE_SERIAL','Serial duplicado en la campaña','El mismo serial aparece en: '.$names.'.',(int)$items[0]['id'],route_url('equipos',['campaign_id'=>$campaignId,'sede_id'=>$sedeId]));}}
        foreach($plateGroups as $items){if(count($items)>1){$names=implode(', ',array_column($items,'name'));$add('bloqueante','DUPLICATE_PLATE','Placa RNEC duplicada en la campaña','La misma placa aparece en: '.$names.'.',(int)$items[0]['id'],route_url('equipos',['campaign_id'=>$campaignId,'sede_id'=>$sedeId]));}}

        $drafts=0;
        if(AppSettings::validationDraftsEnabled()){
            $drafts=(int)(Database::fetchOne('SELECT COUNT(*) total FROM validation_drafts WHERE campaign_id=? AND sede_id=?',[$campaignId,$sedeId])['total']??0);
            if($drafts>0)$add('bloqueante','OPEN_DRAFTS','Borradores automáticos pendientes','Existen '.$drafts.' borrador(es) de validación pendientes de confirmar.',null,route_url('equipos',['campaign_id'=>$campaignId,'sede_id'=>$sedeId,'validation'=>'pending']));
        }
        $corrections=(int)(Database::fetchOne("SELECT COUNT(*) total FROM validation_corrections vc JOIN equipment_validations ev ON ev.id=vc.validation_id WHERE ev.campaign_id=? AND ev.reported_by_sede_id=? AND vc.status IN ('pendiente','corregida')",[$campaignId,$sedeId])['total']??0);
        if($corrections>0)$add('bloqueante','OPEN_CORRECTIONS','Correcciones pendientes','Existen '.$corrections.' corrección(es) sin aprobación.',null,route_url('correcciones',['campaign_id'=>$campaignId,'sede_id'=>$sedeId]));
        $critical=(int)(Database::fetchOne("SELECT COUNT(*) total FROM incidents WHERE campaign_id=? AND sede_id=? AND priority IN ('alta','critica') AND status IN ('abierta','en_gestion')",[$campaignId,$sedeId])['total']??0);
        if($critical>0)$add('bloqueante','CRITICAL_INCIDENTS','Novedades críticas abiertas','Existen '.$critical.' novedad(es) de prioridad alta o crítica sin resolver.',null,route_url('novedades',['campaign_id'=>$campaignId,'sede_id'=>$sedeId]));
        $additional=(int)(Database::fetchOne("SELECT COUNT(*) total FROM additional_equipment WHERE campaign_id=? AND sede_id=? AND review_status='pendiente'",[$campaignId,$sedeId])['total']??0);
        if($additional>0)$add('bloqueante','ADDITIONAL_PENDING','Equipos adicionales pendientes','Existen '.$additional.' equipo(s) adicional(es) pendientes de revisión.',null,route_url('adicionales',['campaign_id'=>$campaignId,'sede_id'=>$sedeId]));

        $campaign=Database::fetchOne('SELECT requires_evidence FROM campaigns WHERE id=?',[$campaignId])?:[];
        if(AppSettings::validationImagesEnabled() && !empty($campaign['requires_evidence'])){
            $without=(int)(Database::fetchOne("SELECT COUNT(*) total FROM equipment_validations ev LEFT JOIN evidence_files ef ON ef.validation_id=ev.id AND ef.evidence_type='general' WHERE ev.campaign_id=? AND ev.reported_by_sede_id=? AND ef.id IS NULL",[$campaignId,$sedeId])['total']??0);
            if($without>0)$add('bloqueante','EVIDENCE_MISSING','Evidencias obligatorias faltantes','Faltan fotografías generales en '.$without.' validación(es).',null,route_url('equipos',['campaign_id'=>$campaignId,'sede_id'=>$sedeId]));
        }

        $profile = function_exists('campaign_site_profile_complete') ? campaign_site_profile_complete($campaignId,$sedeId) : true;
        if(!$profile)$add('bloqueante','SITE_PROFILE_INCOMPLETE','Información de sede incompleta','Confirme la dirección y diligencie el responsable sin usar datos prediligenciados.',null,route_url('campania_sede_contacto',['campaign_id'=>$campaignId,'sede_id'=>$sedeId]));

        $blocking=count(array_filter($findings,static fn($f)=>$f['severity']==='bloqueante'));
        $warnings=count($findings)-$blocking;
        $score=max(0,100-($blocking*8)-($warnings*2));
        $status=$blocking===0?'aprobado':'bloqueado';
        Database::execute('INSERT INTO site_quality_runs(campaign_id,sede_id,score,blocking_count,warning_count,status,executed_by) VALUES(?,?,?,?,?,?,?)',[$campaignId,$sedeId,$score,$blocking,$warnings,$status,$userId]);
        $runId=(int)Database::connection()->lastInsertId();
        foreach($findings as $finding){
            Database::execute('INSERT INTO site_quality_findings(run_id,severity,finding_code,title,detail,equipment_id,action_route) VALUES(?,?,?,?,?,?,?)',[$runId,$finding['severity'],$finding['code'],$finding['title'],$finding['detail'],$finding['equipmentId'],$finding['route']]);
        }
        return ['run_id'=>$runId,'score'=>$score,'blocking_count'=>$blocking,'warning_count'=>$warnings,'status'=>$status,'findings'=>$findings];
    }

    public static function latest(int $campaignId,int $sedeId): ?array
    {
        self::ensureSchema();
        $run=Database::fetchOne('SELECT r.*,u.name executed_name FROM site_quality_runs r LEFT JOIN users u ON u.id=r.executed_by WHERE r.campaign_id=? AND r.sede_id=? ORDER BY r.id DESC LIMIT 1',[$campaignId,$sedeId]);
        if(!$run)return null;
        $run['findings']=Database::fetchAll('SELECT * FROM site_quality_findings WHERE run_id=? ORDER BY FIELD(severity,\'bloqueante\',\'advertencia\'),id',[(int)$run['id']]);
        return $run;
    }
}
