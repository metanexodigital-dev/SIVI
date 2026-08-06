<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_documentation_policy_1_0_0_0.php
 * Propósito: Garantiza que la documentación Markdown de SIVI esté consolidada
 * en README.md y Actualizaciones.md, sin CHANGELOG ni archivos dispersos.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CheckRunner.php';

$root = dirname(__DIR__);
$check = new CheckRunner('documentation_policy_1.0.0.0', $root);

$markdown = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'md') {
        continue;
    }

    $markdown[] = str_replace(
        '\\',
        '/',
        substr($file->getPathname(), strlen($root) + 1)
    );
}

sort($markdown);

$check->add(
    'exact_markdown_files',
    $markdown === ['Actualizaciones.md', 'README.md'],
    implode(', ', $markdown)
);

$readme = (string)@file_get_contents($root . '/README.md');
$updates = (string)@file_get_contents($root . '/Actualizaciones.md');
$version = trim((string)@file_get_contents($root . '/VERSION'));

$check->add(
    'readme_general_documentation',
    str_contains($readme, '`Actualizaciones.md`')
        && str_contains($readme, '**Versión:** `' . $version . '`'),
    'README.md'
);

$check->add(
    'updates_single_change_history',
    str_contains($updates, '# Actualizaciones de SIVI')
        && str_contains($updates, '## ' . $version)
        && str_contains($updates, 'No se utilizará `CHANGELOG.md`'),
    'Actualizaciones.md'
);

$check->add(
    'no_changelog',
    !file_exists($root . '/CHANGELOG.md'),
    'CHANGELOG.md ausente'
);

$check->outputAndExit();
