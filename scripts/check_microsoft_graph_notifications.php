<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_microsoft_graph_notifications.php
 * Propósito: Verifica automáticamente que la funcionalidad «microsoft graph notifications» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$version = trim((string)@file_get_contents($root . '/VERSION'));
$files = [
    'src/SecretVault.php',
    'src/MicrosoftGraphClient.php',
    'src/NotificationTemplate.php',
    'src/NotificationQueue.php',
    'src/NotificationService.php',
    'src/Mailer.php',
    'scripts/notification_worker.php',
    'scripts/process_notification_queue.php',
];
$checks = [];
$errors = [];
foreach ($files as $file) {
    $ok = is_file($root . '/' . $file) && filesize($root . '/' . $file) > 0;
    $checks['file:' . $file] = $ok;
    if (!$ok) $errors[] = 'Falta ' . $file;
}

$graph = (string)@file_get_contents($root . '/src/MicrosoftGraphClient.php');
$queue = (string)@file_get_contents($root . '/src/NotificationQueue.php');
$settings = (string)@file_get_contents($root . '/src/AppSettings.php');
$schema = (string)@file_get_contents($root . '/database/schema.sql');
$index = (string)@file_get_contents($root . '/public/index.php');
$views = (string)@file_get_contents($root . '/src/views.php');
$compose = (string)@file_get_contents($root . '/docker-compose.yml');
$entrypoint = (string)@file_get_contents($root . '/docker/entrypoint.sh');
$dockerfile = (string)@file_get_contents($root . '/Dockerfile');

$expectations = [
    'client_credentials_scope' => str_contains($graph, 'https://graph.microsoft.com/.default') && str_contains($graph, "'grant_type' => 'client_credentials'"),
    'sendmail_users_endpoint' => str_contains($graph, '/v1.0/users/') && str_contains($graph, '/sendMail'),
    'mail_send_role_check' => str_contains($graph, "in_array('Mail.Send'"),
    'https_verification' => str_contains($graph, 'CURLOPT_SSL_VERIFYPEER') && str_contains($graph, 'CURLOPT_PROTOCOLS'),
    'encrypted_secret' => str_contains((string)@file_get_contents($root . '/src/SecretVault.php'), 'aes-256-gcm') && str_contains($settings, 'microsoft_graph.client_secret'),
    'queue_tables' => str_contains($schema, 'CREATE TABLE IF NOT EXISTS notification_queue') && str_contains($schema, 'CREATE TABLE IF NOT EXISTS notification_templates'),
    'queue_retries' => str_contains($queue, 'next_attempt_at') && str_contains($queue, 'max_attempts'),
    'admin_route' => str_contains($index, "case 'correo': mail_notifications_page();") && str_contains($index, 'function mail_notifications_page(): void'),
    'admin_menu' => str_contains($views, "['correo','Correo y notificaciones','✉']"),
    'worker_service' => str_contains($compose, 'notifications:') && str_contains($compose, 'SIVI_PROCESS_ROLE: notifications'),
    'worker_entrypoint' => str_contains($entrypoint, 'notification_worker.php'),
    'php_curl_extension' => str_contains($dockerfile, 'libcurl4-openssl-dev') && preg_match('/docker-php-ext-install[^\n]+curl/', $dockerfile) === 1,
    'campaign_notification_hook' => str_contains($index, "sendTemplate('campaign_published'"),
    'site_closure_hook' => str_contains($index, "sendTemplate('site_closed'"),
    'transfer_resolution_hook' => str_contains($index, "sendTemplate('transfer_resolved'"),
    'reopening_resolution_hook' => str_contains($index, "sendTemplate('reopening_resolved'"),
    'correction_resolution_hook' => str_contains($index, "sendTemplate('correction_resolved'"),
    'system_health_hook' => str_contains((string)@file_get_contents($root . '/src/SystemHealth.php'), "'notification_queue'") && str_contains((string)@file_get_contents($root . '/src/SystemHealth.php'), "'encryption_key'"),
    'no_imap' => !str_contains(strtolower($graph . $queue), 'imap'),
];
foreach ($expectations as $name => $ok) {
    $checks[$name] = $ok;
    if (!$ok) $errors[] = 'No se cumple: ' . $name;
}

$result = [
    'ok' => $errors === [],
    'version' => $version,
    'check' => 'microsoft_graph_notifications',
    'checks' => $checks,
    'errors' => $errors,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 2);
