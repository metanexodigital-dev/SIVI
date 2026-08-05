<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: public/admin-plate-policy.php
 * Propósito: Permite al administrador configurar la longitud y reglas de la Placa RNEC.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
if (is_file($root . '/src/bootstrap.php')) {
    require_once $root . '/src/bootstrap.php';
}
require_once $root . '/src/SiviRuntimeBridge.php';
require_once $root . '/src/PlatePolicy.php';

SiviRuntimeBridge::startSession();
if (!SiviRuntimeBridge::isAdmin()) {
    http_response_code(403);
    exit('Acceso restringido. Esta configuración solo está disponible para Admin GI o SuperAdmin.');
}

$pdo = SiviRuntimeBridge::pdo();
PlatePolicy::ensureSchema($pdo);
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!SiviRuntimeBridge::verifyCsrf((string) ($_POST['_csrf'] ?? ''))) {
        $error = 'La sesión del formulario venció. Recargue la página e intente nuevamente.';
    } else {
        $totalCharacters = filter_input(INPUT_POST, 'plate_rnec_total_characters', FILTER_VALIDATE_INT);
        if (
            $totalCharacters === false
            || $totalCharacters < PlatePolicy::MIN_TOTAL_CHARACTERS
            || $totalCharacters > PlatePolicy::MAX_TOTAL_CHARACTERS
        ) {
            $error = sprintf(
                'Seleccione una cantidad total válida entre %d y %d caracteres.',
                PlatePolicy::MIN_TOTAL_CHARACTERS,
                PlatePolicy::MAX_TOTAL_CHARACTERS
            );
        } else {
            PlatePolicy::saveTotalCharacters($pdo, (int) $totalCharacters, SiviRuntimeBridge::userId());
            $message = sprintf(
                'La Placa RNEC quedó configurada con %d caracteres totales, incluido el guion.',
                $totalCharacters
            );
        }
    }
}

$current = PlatePolicy::totalCharacters($pdo);
$currentDigits = PlatePolicy::digitCount($current);
$currentExample = PlatePolicy::example($current);
$csrf = SiviRuntimeBridge::csrfToken();
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Configuración de Placa RNEC - SIVI</title>
    <link rel="stylesheet" href="assets/plate-ux.css?v=1.0.0.0">
    <link rel="stylesheet" href="assets/admin-plate-policy.css?v=1.0.0.0">
</head>
<body>
<main class="settings-wrap">
    <section class="settings-card">
        <h1>Configuración de Placa RNEC</h1>
        <p class="help">
            Defina la cantidad <strong>total de caracteres</strong> de la placa. El valor incluye un guion obligatorio,
            ubicado después de los primeros tres números. El usuario podrá escribir o pegar la placa completa con o sin guion; SIVI normalizará el formato al salir del campo.
        </p>
        <?php if ($message !== ''): ?><div class="notice ok" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="notice bad" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <div class="field">
                <label for="plate_rnec_total_characters">Cantidad total de caracteres de la placa</label>
                <p class="form-text"><strong>El prefijo 000 es obligatorio.</strong> SIVI agregará el guion automáticamente como cuarto carácter.</p>
                <select id="plate_rnec_total_characters" name="plate_rnec_total_characters" required>
                    <?php for ($i = PlatePolicy::MIN_TOTAL_CHARACTERS; $i <= PlatePolicy::MAX_TOTAL_CHARACTERS; $i++): ?>
                        <?php $digits = PlatePolicy::digitCount($i); ?>
                        <option value="<?= $i ?>" <?= $current === $i ? 'selected' : '' ?>>
                            <?= $i ?> caracteres: <?= $digits ?> números + 1 guion
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="preview">
                <div>Configuración actual: <strong><?= $current ?> caracteres</strong> (<?= $currentDigits ?> números y 1 guion).</div>
                <div class="plate-example">Ejemplo: <span class="plate-preview"><?= htmlspecialchars($currentExample, ENT_QUOTES, 'UTF-8') ?></span></div>
            </div>
            <div class="actions plate-actions">
                <button class="button primary" type="submit">Guardar configuración</button>
                <a class="button secondary" href="index.php">Volver a SIVI</a>
            </div>
        </form>
    </section>
</main>
</body>
</html>
