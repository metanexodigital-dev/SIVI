<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_ux_final_1_0_0_0.php
 * Propósito: Verifica los ajustes finales de experiencia de usuario previos
 * al lanzamiento oficial 1.0.0.0 sin reducir controles SOC.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CheckRunner.php';

$root = dirname(__DIR__);
$check = new CheckRunner('ux_final_1.0.0.0', $root);

$controller = (string)@file_get_contents($root . '/public/index.php');
$helpers = (string)@file_get_contents($root . '/src/helpers.php');
$settings = (string)@file_get_contents($root . '/src/AppSettings.php');
$quality = (string)@file_get_contents($root . '/src/SiteQualityGate.php');
$experience = (string)@file_get_contents($root . '/src/OperationalExperience.php');
$views = (string)@file_get_contents($root . '/src/views.php');
$javascript = (string)@file_get_contents($root . '/public/assets/app.js');
$reports = (string)@file_get_contents($root . '/src/ReportsCenter.php');
$release = json_decode(
    (string)@file_get_contents($root . '/RELEASE.json'),
    true
);
$schemaPath = $root . '/database/schema.sql';

$check->add(
    'suggested_plate_inside_plate_field',
    str_contains($controller, 'class="plate-input-actions"')
        && str_contains($controller, 'data-copy-suggested-plate')
        && str_contains($controller, '>Usar placa sugerida</button>'),
    'Acción junto al input de Placa RNEC'
);

$check->add(
    'own_plate_unavailable_exception',
    str_contains($controller, "name=\"placa_no_visible\"")
        && str_contains($controller, "field('plate_unavailable_reason'")
        && str_contains($controller, 'mb_strlen($plateUnavailableReason) < 10')
        && str_contains($controller, 'Placa RNEC válida o una justificación')
        && str_contains($quality, 'PLATE_NOT_VISIBLE_JUSTIFIED'),
    'Equipo propio puede continuar sin placa únicamente con justificación'
);

$check->add(
    'plate_exception_metadata_without_schema_change',
    str_contains($helpers, '[SIVI:PLACA_NO_VISIBLE]')
        && str_contains($helpers, 'placa_unavailable_reason')
        && hash_file('sha256', $schemaPath)
            === '88147044e42d8794351caf163a8e7ee43619f067de8dd84cf3a9b80b2fda3043',
    'Observación persistida en notes; schema.sql SOC sin cambios'
);

$check->add(
    'other_belongs_reason_text',
    str_contains($controller, "'otro'")
        && str_contains($controller, 'belongs_reason_other')
        && str_contains($controller, 'mb_strlen($belongsReasonOther) < 5')
        && str_contains($javascript, 'data-belongs-other-panel')
        && str_contains($helpers, '[SIVI:OTRO_MOTIVO]'),
    'Otro motivo exige explicación'
);

$check->add(
    'validation_images_admin_configuration',
    str_contains($settings, "'validation.images_mode'")
        && str_contains($settings, 'validationImagesEnabled')
        && str_contains($controller, 'name="validation_disable_images"')
        && str_contains($controller, 'if ($validationImagesEnabled)')
        && str_contains($quality, 'AppSettings::validationImagesEnabled()'),
    'Paso 3 oculta evidencias cuando Administración lo deshabilita'
);

$check->add(
    'site_validation_result_not_requested',
    !str_contains($controller, "field('site_confirmation_status'")
        && !str_contains($controller, "$" . "_POST['site_confirmation_status']")
        && str_contains($controller, "\$siteStatus=\$siteNotes!==''?'con_novedad':'confirmada';"),
    'Resultado de la sede se deriva automáticamente'
);

$check->add(
    'responsible_role_closed_list',
    str_contains($controller, "\$allowedResponsibleRoles=['Registrador','Auxiliar','Técnico'];")
        && str_contains($controller, "'Registrador'=>'Registrador'")
        && str_contains($controller, "'Auxiliar'=>'Auxiliar'")
        && str_contains($controller, "'Técnico'=>'Técnico'"),
    'Cargo: Registrador, Auxiliar o Técnico'
);

$check->add(
    'pending_navigation_only',
    str_contains($views, 'navigation_pending_modules')
        && str_contains($views, "read_at IS NULL AND (sede_id IS NULL OR sede_id=?)")
        && str_contains($views, "vc.status='pendiente'")
        && str_contains($views, "if (\$pendingNavigation['notificaciones'])")
        && str_contains($views, "if (\$pendingNavigation['correcciones'])")
        && str_contains($controller, "if ((int)\$state['corrections'] > 0)")
        && str_contains($controller, "if ((int)\$state['unread_notifications'] > 0)"),
    'Notificaciones y Correcciones se muestran solo cuando hay pendientes'
);

$check->add(
    'fixed_site_without_territorial_filter',
    str_contains($controller, "\$sedeId>0?'':' data-territorial-filters'")
        && str_contains($controller, 'Sede en contexto')
        && str_contains($controller, "Buscar dentro de esta sede")
        && str_contains($controller, "if (\$sedeId > 0)")
        && str_contains($controller, "type=\"hidden\" name=\"sede_id\""),
    'Dentro de una sede no se muestran selectores territoriales redundantes'
);

$check->add(
    'drafts_admin_only',
    str_contains($settings, "'validation.drafts_enabled'")
        && str_contains($controller, 'name="validation_drafts_enabled"')
        && str_contains($controller, 'AppSettings::validationDraftsEnabled()')
        && str_contains($controller, 'Guardado automático')
        && !str_contains($controller, 'data-discard-draft')
        && !str_contains($javascript, 'data-discard-draft'),
    'Borrador transparente, sin control operativo para deshabilitar/descartar'
);

$check->add(
    'draft_endpoint_disabled_by_configuration',
    str_contains($controller, 'if (!AppSettings::validationDraftsEnabled())')
        && str_contains($controller, 'Guardado de borradores deshabilitado por configuración.')
        && str_contains($experience, 'if (!AppSettings::validationDraftsEnabled()) return 0;')
        && str_contains($quality, 'if(AppSettings::validationDraftsEnabled())'),
    'Sin peticiones/bloqueos de borrador cuando Administración lo deshabilita'
);

$check->add(
    'report_metadata_hidden',
    str_contains($reports, 'validation_notes_for_display')
        && str_contains($controller, "validation_notes_for_display((string)(\$record['notes'] ?? ''))"),
    'Etiquetas internas no se exponen en reportes'
);

$check->add(
    'soc_hardening_preserved',
    is_file($root . '/scripts/check_soc_hardening_1_0_0_0.php')
        && !is_file($root . '/scripts/migrate.php')
        && !is_file($root . '/scripts/apply_secure_schema.php')
        && !is_file($root . '/scripts/install_guided_ux_patch.php')
        && ($release['database_migration_required'] ?? true) === false
        && ($release['automatic_migrations'] ?? true) === false,
    'Hardening SOC y política sin migraciones preservados'
);

$check->outputAndExit();
