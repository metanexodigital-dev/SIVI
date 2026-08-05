<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_validation_auto_next.php
 * Propósito: Verifica automáticamente que la funcionalidad «validation auto next» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$controller = (string)@file_get_contents($root . '/public/index.php');
$required = (string)@file_get_contents($root . '/scripts/check_required_files.php');
$dockerfile = (string)@file_get_contents($root . '/Dockerfile');
$buildManifest = (string)@file_get_contents($root . '/scripts/build/checks.json');
$version = trim((string)@file_get_contents($root . '/VERSION'));

$checks = [
    'version_0_0_0_31' => $version === '1.0.0.0',
    'next_pending_helper' => str_contains($controller, 'function campaign_next_pending_equipment'),
    'pending_only_query' => str_contains($controller, "ev.id IS NULL OR ev.validation_status='pendiente'"),
    'save_redirects_to_next' => str_contains($controller, "redirect('equipo_validar',['id'=>(int)\$nextPending['id'],'campaign_id'=>\$campaignId])"),
    'last_equipment_redirects_to_summary' => str_contains($controller, "redirect('equipos',['campaign_id'=>\$campaignId,'sede_id'=>(int)\$equipment['current_sede_id']])"),
    'wraps_skipped_pending' => str_contains($controller, 'continuar con el primer pendiente'),
    'required_file_registered' => str_contains($required, 'scripts/check_validation_auto_next.php'),
    'docker_build_check' => str_contains($dockerfile, 'scripts/run_production_build_checks.sh')
        && str_contains($buildManifest, 'scripts/check_validation_auto_next.php'),
];

$ok = !in_array(false, $checks, true);
echo json_encode([
    'ok' => $ok,
    'version' => $version,
    'check' => 'validation_auto_next_1.0.0.0',
    'checks' => $checks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 2);
