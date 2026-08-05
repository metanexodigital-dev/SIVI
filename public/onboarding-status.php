<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: public/onboarding-status.php
 * Propósito: Consulta y actualiza el estado del recorrido guiado del usuario.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
if (is_file($root . '/src/bootstrap.php')) {
    require_once $root . '/src/bootstrap.php';
}
require_once $root . '/src/SiviRuntimeBridge.php';
require_once $root . '/src/OnboardingService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!OnboardingService::enabled()) {
    respond(['ok' => true, 'enabled' => false, 'should_show' => false]);
}

SiviRuntimeBridge::startSession();
$userId = SiviRuntimeBridge::userId();
if ($userId === null || $userId <= 0) {
    respond(['ok' => false, 'authenticated' => false, 'message' => 'Sesión no autenticada.'], 401);
}

try {
    $pdo = SiviRuntimeBridge::pdo();
    OnboardingService::ensureSchema($pdo);
    $tourKey = OnboardingService::tourKey();

    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET') {
        $status = OnboardingService::get($pdo, $userId, $tourKey);
        respond([
            'ok' => true,
            'enabled' => true,
            'authenticated' => true,
            'tour_key' => $tourKey,
            'role' => SiviRuntimeBridge::role(),
            'csrf' => SiviRuntimeBridge::csrfToken(),
            'status' => $status['status'],
            'last_step' => $status['last_step'],
            'should_show' => $status['should_show'],
        ]);
    }

    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        respond(['ok' => false, 'message' => 'Método no permitido.'], 405);
    }

    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    $payload = str_contains($contentType, 'application/json')
        ? json_decode((string) file_get_contents('php://input'), true)
        : $_POST;
    if (!is_array($payload)) {
        $payload = [];
    }

    $csrf = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $payload['_csrf'] ?? '');
    if (!SiviRuntimeBridge::verifyCsrf($csrf)) {
        respond(['ok' => false, 'message' => 'La sesión del recorrido venció. Recargue la página.'], 419);
    }

    $action = strtolower(trim((string) ($payload['action'] ?? '')));
    $step = max(0, min(99, (int) ($payload['step'] ?? 0)));
    $statusMap = [
        'start' => 'started',
        'progress' => 'in_progress',
        'complete' => 'completed',
        'skip' => 'skipped',
    ];
    if (!isset($statusMap[$action])) {
        respond(['ok' => false, 'message' => 'Acción de recorrido no válida.'], 422);
    }

    OnboardingService::save($pdo, $userId, $statusMap[$action], $step, $tourKey);
    respond(['ok' => true, 'status' => $statusMap[$action], 'step' => $step]);
} catch (Throwable) {
    respond(['ok' => false, 'message' => 'No fue posible actualizar el recorrido guiado.'], 500);
}
