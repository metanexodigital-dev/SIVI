<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/NotificationTemplate.php
 * Propósito: Define y procesa las plantillas utilizadas en las notificaciones.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

final class NotificationTemplate
{
    /** @return array<string,array{name:string,subject:string,html:string}> */
    public static function defaults(): array
    {
        return [
            'campaign_published' => [
                'name' => 'Campaña disponible para validación',
                'subject' => 'Validación de inventario - {{campania}}',
                'html' => '<p>Señor(a) {{responsable_nombre}},</p><p>Está disponible la campaña <strong>{{campania}}</strong> para la sede <strong>{{sede}}</strong>.</p><p><a href="{{url_accion}}">Ingresar a validar el inventario</a></p><p>Fecha límite: {{fecha_limite}}</p>',
            ],
            'site_closed' => [
                'name' => 'Sede finalizada',
                'subject' => 'Sede finalizada - {{sede}}',
                'html' => '<p>La validación de la sede <strong>{{sede}}</strong> fue finalizada correctamente.</p><p>Campaña: {{campania}}<br>Constancia: {{numero_constancia}}<br>Fecha: {{fecha}}</p><p><a href="{{url_accion}}">Consultar constancia</a></p>',
            ],
            'correction_requested' => [
                'name' => 'Corrección solicitada',
                'subject' => 'Corrección solicitada en SIVI - {{sede}}',
                'html' => '<p>Se solicitó una corrección para la sede <strong>{{sede}}</strong>.</p><p>{{detalle}}</p><p><a href="{{url_accion}}">Revisar corrección</a></p>',
            ],
            'correction_resolved' => [
                'name' => 'Corrección atendida',
                'subject' => 'Corrección atendida en SIVI - {{sede}}',
                'html' => '<p>La corrección de la sede <strong>{{sede}}</strong> fue marcada como atendida.</p><p><a href="{{url_accion}}">Consultar seguimiento</a></p>',
            ],
            'transfer_requested' => [
                'name' => 'Traslado solicitado',
                'subject' => 'Solicitud de traslado de equipo - {{equipo}}',
                'html' => '<p>Se registró una solicitud de traslado del equipo <strong>{{equipo}}</strong>.</p><p>Origen: {{sede_origen}}<br>Destino: {{sede_destino}}<br>Motivo: {{detalle}}</p><p><a href="{{url_accion}}">Revisar solicitud</a></p>',
            ],
            'transfer_resolved' => [
                'name' => 'Traslado resuelto',
                'subject' => 'Resultado de traslado - {{equipo}}',
                'html' => '<p>La solicitud de traslado del equipo <strong>{{equipo}}</strong> fue {{resultado}}.</p><p><a href="{{url_accion}}">Consultar traslado</a></p>',
            ],
            'reopening_requested' => [
                'name' => 'Reapertura solicitada',
                'subject' => 'Solicitud de reapertura - {{sede}}',
                'html' => '<p>Se solicitó la reapertura de la sede <strong>{{sede}}</strong>.</p><p>Motivo: {{detalle}}</p><p><a href="{{url_accion}}">Revisar solicitud</a></p>',
            ],
            'reopening_resolved' => [
                'name' => 'Reapertura resuelta',
                'subject' => 'Resultado de reapertura - {{sede}}',
                'html' => '<p>La solicitud de reapertura de la sede <strong>{{sede}}</strong> fue {{resultado}}.</p><p><a href="{{url_accion}}">Consultar reapertura</a></p>',
            ],
            'campaign_reminder' => [
                'name' => 'Recordatorio de campaña',
                'subject' => 'Recordatorio - {{campania}}',
                'html' => '<p>La campaña <strong>{{campania}}</strong> continúa pendiente para la sede <strong>{{sede}}</strong>.</p><p>Fecha límite: {{fecha_limite}}</p><p><a href="{{url_accion}}">Continuar validación</a></p>',
            ],
        ];
    }

    public static function ensureDefaults(): void
    {
        foreach (self::defaults() as $key => $template) {
            Database::execute(
                'INSERT IGNORE INTO notification_templates(template_key,name,subject_template,html_template,active,created_at,updated_at) VALUES(?,?,?,?,1,NOW(),NOW())',
                [$key, $template['name'], $template['subject'], $template['html']]
            );
        }
    }

    /** @return array<string,mixed> */
    public static function get(string $key): array
    {
        self::ensureDefaults();
        $row = Database::fetchOne('SELECT * FROM notification_templates WHERE template_key=? LIMIT 1', [$key]);
        if (!$row) throw new RuntimeException('La plantilla de notificación no existe: ' . $key);
        return $row;
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        self::ensureDefaults();
        return Database::fetchAll('SELECT * FROM notification_templates ORDER BY name,template_key');
    }

    /** @param array<string,mixed> $variables @return array{subject:string,html:string,name:string} */
    public static function render(string $key, array $variables): array
    {
        $template = self::get($key);
        if (!(int)($template['active'] ?? 0)) {
            throw new RuntimeException('La plantilla está desactivada: ' . (string)$template['name']);
        }
        $replace = [];
        foreach ($variables as $name => $value) {
            $replace['{{' . $name . '}}'] = htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        return [
            'subject' => trim(strtr((string)$template['subject_template'], $replace)),
            'html' => strtr((string)$template['html_template'], $replace),
            'name' => (string)$template['name'],
        ];
    }

    public static function update(string $key, string $subject, string $html, bool $active): void
    {
        $defaults = self::defaults();
        if (!isset($defaults[$key])) throw new InvalidArgumentException('Plantilla no permitida.');
        $subject = trim($subject);
        $html = trim($html);
        if ($subject === '' || $html === '') throw new InvalidArgumentException('El asunto y el contenido de la plantilla son obligatorios.');
        Database::execute(
            'UPDATE notification_templates SET subject_template=?,html_template=?,active=?,updated_by=?,updated_at=NOW() WHERE template_key=?',
            [$subject, $html, $active ? 1 : 0, Auth::id(), $key]
        );
    }
}
