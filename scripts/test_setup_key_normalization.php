<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/test_setup_key_normalization.php
 * Propósito: Ejecuta pruebas técnicas sobre «setup key normalization» y muestra un resultado verificable.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/Env.php';

$key = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
$cases = [
    $key,
    '  ' . $key . '  ',
    '"' . $key . '"',
    "'" . $key . "'",
    '\\"' . $key . '\\"',
    "\xEF\xBB\xBF" . $key,
    "\u{200B}" . $key . "\u{200B}",
];
$results = [];
$ok = true;
foreach ($cases as $index => $case) {
    $normalized = Env::normalizeValue($case);
    $caseOk = hash_equals($key, $normalized);
    $results[] = ['case' => $index + 1, 'ok' => $caseOk, 'length' => strlen($normalized)];
    $ok = $ok && $caseOk;
}
echo json_encode(['ok' => $ok, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($ok ? 0 : 2);
