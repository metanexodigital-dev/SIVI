<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/OperationalExperience.php
 * Propósito: Organiza ayudas, mensajes y reglas destinadas a mejorar la experiencia del usuario.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

/**
 * Consolida la información necesaria para una experiencia operativa guiada.
 * No modifica datos: únicamente calcula avance, pendientes y búsquedas dentro
 * del alcance territorial del usuario autenticado.
 */
final class OperationalExperience
{
    /** @return array<string,mixed> */
    public static function siteState(int $campaignId, int $sedeId, ?int $userId = null): array
    {
        if ($campaignId < 1 || $sedeId < 1) return [];

        $base = Database::fetchOne(
            "SELECT c.id campaign_id,c.name campaign_name,c.status campaign_status,c.start_date,c.end_date,c.requires_evidence,"
            . "s.id sede_id,s.identificador,s.nombre_sede,s.tipo_sede,s.departamento,s.municipio,"
            . "cs.status site_status,cs.closed_at,cs.closure_code,cs.responsible_name,cs.responsible_email,"
            . "cs.site_confirmed_at,cs.site_confirmation_status,cs.site_confirmed_address "
            . "FROM campaigns c JOIN campaign_sedes cs ON cs.campaign_id=c.id "
            . "JOIN sedes s ON s.id=cs.sede_id WHERE c.id=? AND s.id=?",
            [$campaignId,$sedeId]
        );
        if (!$base) return [];

        $assigned = (int)(Database::fetchOne(
            'SELECT COUNT(*) total FROM campaign_equipment WHERE campaign_id=? AND sede_id=?',
            [$campaignId,$sedeId]
        )['total'] ?? 0);
        $validated = (int)(Database::fetchOne(
            "SELECT COUNT(DISTINCT equipment_id) total FROM equipment_validations "
            . "WHERE campaign_id=? AND reported_by_sede_id=? AND validation_status<>'pendiente'",
            [$campaignId,$sedeId]
        )['total'] ?? 0);
        $additional = (int)(Database::fetchOne(
            "SELECT COUNT(*) total FROM additional_equipment WHERE campaign_id=? AND sede_id=? AND review_status<>'rechazado'",
            [$campaignId,$sedeId]
        )['total'] ?? 0);
        $incidents = (int)(Database::fetchOne(
            "SELECT COUNT(*) total FROM incidents WHERE campaign_id=? AND sede_id=? AND status IN ('abierta','en_gestion')",
            [$campaignId,$sedeId]
        )['total'] ?? 0);
        $criticalIncidents = (int)(Database::fetchOne(
            "SELECT COUNT(*) total FROM incidents WHERE campaign_id=? AND sede_id=? AND status IN ('abierta','en_gestion') AND priority IN ('alta','critica')",
            [$campaignId,$sedeId]
        )['total'] ?? 0);
        $drafts = self::unresolvedDraftCount($campaignId,$sedeId);
        $corrections = (int)(Database::fetchOne(
            "SELECT COUNT(DISTINCT vc.id) total FROM validation_corrections vc "
            . "JOIN equipment_validations ev ON ev.id=vc.validation_id "
            . "WHERE ev.campaign_id=? AND ev.reported_by_sede_id=? AND vc.status='pendiente'",
            [$campaignId,$sedeId]
        )['total'] ?? 0);
        $serialPending = (int)(Database::fetchOne(
            "SELECT COUNT(*) total FROM campaign_equipment ce JOIN equipment e ON e.id=ce.equipment_id "
            . "LEFT JOIN equipment_validations ev ON ev.campaign_id=ce.campaign_id AND ev.equipment_id=ce.equipment_id AND ev.reported_by_sede_id=ce.sede_id "
            . "WHERE ce.campaign_id=? AND ce.sede_id=? "
            . "AND COALESCE(NULLIF(TRIM(ev.serial_reported),''),NULLIF(TRIM(e.serial_number),'')) IS NULL",
            [$campaignId,$sedeId]
        )['total'] ?? 0);
        $unread = 0;
        if (($userId ?? 0) > 0) {
            $unread = (int)(Database::fetchOne(
                'SELECT COUNT(*) total FROM internal_notifications WHERE user_id=? AND read_at IS NULL AND (campaign_id IS NULL OR campaign_id=?) AND (sede_id IS NULL OR sede_id=?)',
                [$userId,$campaignId,$sedeId]
            )['total'] ?? 0);
        }

        $profileComplete = !empty($base['site_confirmed_at'])
            && trim((string)($base['site_confirmed_address'] ?? '')) !== ''
            && trim((string)($base['responsible_name'] ?? '')) !== ''
            && filter_var((string)($base['responsible_email'] ?? ''), FILTER_VALIDATE_EMAIL) !== false;
        $pending = max(0,$assigned-$validated);
        $closed = in_array((string)$base['site_status'], ['cerrado','aprobado'], true);
        $issuesResolved = $criticalIncidents === 0 && $corrections === 0;
        $readyToClose = $profileComplete && $pending === 0 && $drafts === 0 && $issuesResolved;
        $equipmentProgress = $assigned > 0 ? (int)round(($validated / $assigned) * 100) : 100;
        $progress = ($profileComplete ? 15 : 0)
            + (int)round($equipmentProgress * 0.65)
            + ($issuesResolved ? 10 : 0)
            + ($closed ? 10 : 0);
        $progress = max(0,min(100,$progress));

        $next = self::nextPendingEquipment($campaignId,$sedeId);
        $nextAction = [
            'label' => 'Revisar la sede',
            'url' => route_url('equipos',['campaign_id'=>$campaignId,'sede_id'=>$sedeId]),
            'tone' => 'primary',
            'reason' => 'Revise el estado general de la sede.',
        ];
        if (!$profileComplete) {
            $nextAction = [
                'label'=>'Validar información de la sede',
                'url'=>route_url('campania_sede_contacto',['campaign_id'=>$campaignId,'sede_id'=>$sedeId]),
                'tone'=>'primary',
                'reason'=>'Este paso es obligatorio antes de validar equipos.',
            ];
        } elseif ($next) {
            $nextAction = [
                'label'=>'Validar siguiente equipo',
                'url'=>route_url('equipo_validar',['id'=>(int)$next['id'],'campaign_id'=>$campaignId]),
                'tone'=>'success',
                'reason'=>trim((string)($next['name'] ?? '')) ?: asset_category_label((string)($next['asset_category'] ?? 'otro')),
            ];
        } elseif ($drafts > 0) {
            $nextAction = [
                'label'=>'Revisar borradores pendientes',
                'url'=>route_url('equipos',['campaign_id'=>$campaignId,'sede_id'=>$sedeId,'validation'=>'pending']),
                'tone'=>'warning',
                'reason'=>$drafts . ' borrador(es) sin una validación confirmada.',
            ];
        } elseif ($corrections > 0) {
            $nextAction = [
                'label'=>'Resolver correcciones pendientes',
                'url'=>route_url('correcciones',['campaign_id'=>$campaignId,'sede_id'=>$sedeId]),
                'tone'=>'warning',
                'reason'=>$corrections . ' corrección(es) requieren atención.',
            ];
        } elseif ($criticalIncidents > 0) {
            $nextAction = [
                'label'=>'Resolver novedades prioritarias',
                'url'=>route_url('novedades',['campaign_id'=>$campaignId,'sede_id'=>$sedeId]),
                'tone'=>'warning',
                'reason'=>$criticalIncidents . ' novedad(es) de prioridad alta o crítica.',
            ];
        } elseif (!$closed) {
            $nextAction = [
                'label'=>'Revisar y finalizar la sede',
                'url'=>route_url('equipos',['campaign_id'=>$campaignId,'sede_id'=>$sedeId,'focus'=>'closure']),
                'tone'=>'success',
                'reason'=>'Todos los equipos obligatorios están validados.',
            ];
        } else {
            $nextAction = [
                'label'=>'Ver constancia de cierre',
                'url'=>route_url('acta_sede',['campaign_id'=>$campaignId,'sede_id'=>$sedeId]),
                'tone'=>'secondary',
                'reason'=>'La sede ya fue finalizada.',
            ];
        }

        $steps = [
            [
                'number'=>1,'label'=>'Validar información de la sede',
                'status'=>$profileComplete?'complete':'current',
                'detail'=>$profileComplete?'Sede y responsable confirmados':'Falta confirmar dirección y responsable',
                'url'=>route_url('campania_sede_contacto',['campaign_id'=>$campaignId,'sede_id'=>$sedeId]),
            ],
            [
                'number'=>2,'label'=>'Validar equipos asignados',
                'status'=>$pending===0?'complete':($profileComplete?'current':'blocked'),
                'detail'=>$assigned===0?'Sin equipos asignados':$validated.' de '.$assigned.' equipos validados'.($drafts>0?' · '.$drafts.' borrador(es) pendiente(s)':''),
                'url'=>route_url('equipos',['campaign_id'=>$campaignId,'sede_id'=>$sedeId,'validation'=>'pending']),
            ],
            [
                'number'=>3,'label'=>'Registrar equipos adicionales',
                'status'=>'available','detail'=>$additional.' equipo(s) adicional(es) vigente(s)',
                'url'=>route_url('adicionales',['campaign_id'=>$campaignId,'sede_id'=>$sedeId]),
            ],
            [
                'number'=>4,'label'=>'Resolver novedades y correcciones',
                'status'=>$issuesResolved?'complete':'attention',
                'detail'=>$incidents.' novedad(es) abierta(s) · '.$corrections.' corrección(es) pendiente(s)',
                'url'=>$corrections>0?route_url('correcciones',['campaign_id'=>$campaignId,'sede_id'=>$sedeId]):route_url('novedades',['campaign_id'=>$campaignId,'sede_id'=>$sedeId]),
            ],
            [
                'number'=>5,'label'=>'Finalizar la sede',
                'status'=>$closed?'complete':($readyToClose?'current':'blocked'),
                'detail'=>$closed?'Validación cerrada':($readyToClose?'Lista para finalizar':'Complete los pasos obligatorios anteriores'),
                'url'=>$closed?route_url('acta_sede',['campaign_id'=>$campaignId,'sede_id'=>$sedeId]):route_url('equipos',['campaign_id'=>$campaignId,'sede_id'=>$sedeId,'focus'=>'closure']),
            ],
        ];

        return array_merge($base,[
            'profile_complete'=>$profileComplete,
            'assigned'=>$assigned,
            'validated'=>$validated,
            'pending'=>$pending,
            'additional'=>$additional,
            'incidents'=>$incidents,
            'critical_incidents'=>$criticalIncidents,
            'drafts'=>$drafts,
            'corrections'=>$corrections,
            'serial_pending'=>$serialPending,
            'unread_notifications'=>$unread,
            'closed'=>$closed,
            'ready_to_close'=>$readyToClose,
            'progress'=>$progress,
            'equipment_progress'=>$equipmentProgress,
            'next_equipment'=>$next,
            'next_action'=>$nextAction,
            'steps'=>$steps,
        ]);
    }


    /**
     * Cuenta únicamente borradores que todavía no tienen una validación final.
     * Los borradores residuales de equipos ya confirmados no deben bloquear el cierre.
     */
    public static function unresolvedDraftCount(int $campaignId, int $sedeId): int
    {
        if (!AppSettings::validationDraftsEnabled()) return 0;
        if ($campaignId < 1 || $sedeId < 1) return 0;
        return (int)(Database::fetchOne(
            "SELECT COUNT(*) total FROM validation_drafts vd "
            . "WHERE vd.campaign_id=? AND vd.sede_id=? "
            . "AND NOT EXISTS (SELECT 1 FROM equipment_validations ev "
            . "WHERE ev.campaign_id=vd.campaign_id AND ev.equipment_id=vd.equipment_id "
            . "AND ev.reported_by_sede_id=vd.sede_id AND ev.validation_status<>'pendiente')",
            [$campaignId,$sedeId]
        )['total'] ?? 0);
    }

    /**
     * Elimina borradores residuales de equipos que ya cuentan con validación final.
     */
    public static function clearResolvedDrafts(int $campaignId, int $sedeId): void
    {
        if ($campaignId < 1 || $sedeId < 1) return;
        Database::execute(
            "DELETE vd FROM validation_drafts vd "
            . "JOIN equipment_validations ev ON ev.campaign_id=vd.campaign_id "
            . "AND ev.equipment_id=vd.equipment_id AND ev.reported_by_sede_id=vd.sede_id "
            . "WHERE vd.campaign_id=? AND vd.sede_id=? AND ev.validation_status<>'pendiente'",
            [$campaignId,$sedeId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function territoryOverview(int $campaignId): array
    {
        if ($campaignId < 1) return [];
        [$scopeWhere,$scopeParams] = Scope::sedeCondition('s');
        $params = array_merge([$campaignId],$scopeParams);
        return Database::fetchAll(
            "SELECT s.id,s.identificador,s.nombre_sede,s.departamento,s.municipio,cs.status,cs.site_confirmed_at,cs.responsible_name,cs.responsible_email,"
            . "COUNT(DISTINCT ce.equipment_id) assigned,"
            . "COUNT(DISTINCT CASE WHEN ev.validation_status<>'pendiente' THEN ev.equipment_id END) validated,"
            . "COUNT(DISTINCT CASE WHEN i.status IN ('abierta','en_gestion') THEN i.id END) incidents,"
            . "COUNT(DISTINCT CASE WHEN vc.status='pendiente' THEN vc.id END) corrections "
            . "FROM campaign_sedes cs JOIN sedes s ON s.id=cs.sede_id "
            . "LEFT JOIN campaign_equipment ce ON ce.campaign_id=cs.campaign_id AND ce.sede_id=cs.sede_id "
            . "LEFT JOIN equipment_validations ev ON ev.campaign_id=cs.campaign_id AND ev.equipment_id=ce.equipment_id AND ev.reported_by_sede_id=cs.sede_id "
            . "LEFT JOIN incidents i ON i.campaign_id=cs.campaign_id AND i.sede_id=cs.sede_id "
            . "LEFT JOIN validation_corrections vc ON vc.validation_id=ev.id "
            . "WHERE cs.campaign_id=? AND {$scopeWhere} "
            . "GROUP BY s.id,s.identificador,s.nombre_sede,s.departamento,s.municipio,cs.status,cs.site_confirmed_at,cs.responsible_name,cs.responsible_email "
            . "ORDER BY CASE WHEN cs.status IN ('cerrado','aprobado') THEN 2 WHEN cs.status='pendiente' THEN 0 ELSE 1 END,"
            . "(COUNT(DISTINCT ce.equipment_id)-COUNT(DISTINCT CASE WHEN ev.validation_status<>'pendiente' THEN ev.equipment_id END)) DESC,s.departamento,s.municipio,s.nombre_sede",
            $params
        );
    }

    /** @return array{equipment:array<int,array<string,mixed>>,additional:array<int,array<string,mixed>>} */
    public static function search(string $query, int $limit = 40): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) return ['equipment'=>[],'additional'=>[]];
        $limit = max(1,min(100,$limit));
        [$scopeWhere,$scopeParams] = Scope::sedeCondition('s');
        $like = '%' . $query . '%';
        $normalized = SerialIntegrity::normalize($query);
        // Evita que una consulta compuesta solo por signos genere LIKE '%%' y devuelva todo el inventario.
        $normalizedLike = $normalized !== '' ? '%' . $normalized . '%' : '__SIVI_NO_NORMALIZED_MATCH__';

        $equipmentParams = $scopeParams;
        array_push($equipmentParams,$like,$like,$like,$like,$like,$like,$normalizedLike,$normalizedLike);
        $equipment = Database::fetchAll(
            "SELECT e.id,e.name,e.asset_category,e.equipment_type,e.serial_number,e.placa_rnec,e.manufacturer,e.model,e.inventory_status,"
            . "s.id sede_id,s.identificador,s.nombre_sede,s.municipio,s.departamento "
            . "FROM equipment e LEFT JOIN sedes s ON s.id=e.current_sede_id "
            . "WHERE e.active=1 AND {$scopeWhere} AND ("
            . "e.name LIKE ? OR e.serial_number LIKE ? OR e.placa_rnec LIKE ? OR e.manufacturer LIKE ? OR e.model LIKE ? OR e.alternate_user LIKE ? "
            . "OR UPPER(REPLACE(REPLACE(REPLACE(COALESCE(e.serial_number,''),'-',''),' ',''),'.','')) LIKE ? "
            . "OR UPPER(REPLACE(REPLACE(REPLACE(COALESCE(e.placa_rnec,''),'-',''),' ',''),'.','')) LIKE ?"
            . ") ORDER BY CASE WHEN e.serial_number=? OR e.placa_rnec=? OR e.name=? THEN 0 ELSE 1 END,s.departamento,s.municipio,e.name LIMIT {$limit}",
            array_merge($equipmentParams,[$query,$query,$query])
        );

        $additionalParams = $scopeParams;
        array_push($additionalParams,$like,$like,$like,$like,$like,$normalizedLike,$normalizedLike);
        $additional = Database::fetchAll(
            "SELECT ae.id,ae.name,ae.asset_category,ae.equipment_type,ae.serial_number,ae.placa_rnec,ae.manufacturer,ae.model,ae.review_status,"
            . "s.id sede_id,s.identificador,s.nombre_sede,s.municipio,s.departamento,c.name campaign_name "
            . "FROM additional_equipment ae JOIN sedes s ON s.id=ae.sede_id JOIN campaigns c ON c.id=ae.campaign_id "
            . "WHERE ae.review_status<>'rechazado' AND {$scopeWhere} AND ("
            . "ae.name LIKE ? OR ae.serial_number LIKE ? OR ae.placa_rnec LIKE ? OR ae.manufacturer LIKE ? OR ae.model LIKE ? "
            . "OR UPPER(REPLACE(REPLACE(REPLACE(COALESCE(ae.serial_number,''),'-',''),' ',''),'.','')) LIKE ? "
            . "OR UPPER(REPLACE(REPLACE(REPLACE(COALESCE(ae.placa_rnec,''),'-',''),' ',''),'.','')) LIKE ?"
            . ") ORDER BY ae.id DESC LIMIT {$limit}",
            $additionalParams
        );
        return ['equipment'=>$equipment,'additional'=>$additional];
    }

    /** @return array<string,mixed>|null */
    private static function nextPendingEquipment(int $campaignId, int $sedeId): ?array
    {
        return Database::fetchOne(
            "SELECT e.id,e.name,e.asset_category FROM campaign_equipment ce JOIN equipment e ON e.id=ce.equipment_id "
            . "LEFT JOIN equipment_validations ev ON ev.campaign_id=ce.campaign_id AND ev.equipment_id=ce.equipment_id AND ev.reported_by_sede_id=ce.sede_id "
            . "WHERE ce.campaign_id=? AND ce.sede_id=? AND (ev.id IS NULL OR ev.validation_status='pendiente') "
            . "ORDER BY e.asset_category,e.name,e.id LIMIT 1",
            [$campaignId,$sedeId]
        );
    }
}
