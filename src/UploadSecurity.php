<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/UploadSecurity.php
 * Propósito: Unifica controles de tamaño, extensión, MIME, estructura y malware.
 */
declare(strict_types=1);

final class UploadSecurity
{
    /** @param array<string,mixed> $file */
    public static function validateXlsx(array $file, int $maxBytes = 104857600): void
    {
        self::assertUpload($file, $maxBytes);
        $name = trim((string)($file['name'] ?? ''));
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new InvalidArgumentException('Solo se admiten archivos XLSX.');
        }

        $tmp = (string)$file['tmp_name'];
        $mime = strtolower((string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp));
        $allowed = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'application/octet-stream',
        ];
        if (!in_array($mime, $allowed, true)) {
            throw new InvalidArgumentException('El contenido del archivo no corresponde a un XLSX válido.');
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            throw new InvalidArgumentException('El archivo XLSX está dañado o no puede abrirse.');
        }
        try {
            if (
                $zip->locateName('[Content_Types].xml') === false
                || $zip->locateName('xl/workbook.xml') === false
            ) {
                throw new InvalidArgumentException('El archivo no contiene la estructura interna esperada de Excel.');
            }
            if ($zip->numFiles > 10000) {
                throw new InvalidArgumentException('El archivo contiene demasiados elementos internos.');
            }

            /*
             * Defensa contra ZIP bombs. XLSX es un contenedor ZIP y un archivo
             * pequeño puede declarar una expansión desproporcionada. Los límites
             * son deliberadamente amplios para no afectar libros institucionales
             * grandes, pero detienen relaciones de compresión anómalas.
             */
            $maximumExpandedBytes = max(
                268435456,
                min(805306368, $maxBytes * 8)
            );
            $maximumEntryBytes = 268435456;
            $maximumCompressionRatio = 300.0;
            $expandedBytes = 0;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (!is_array($stat)) {
                    throw new InvalidArgumentException(
                        'No fue posible validar la estructura interna del XLSX.'
                    );
                }

                $entryName = (string)($stat['name'] ?? '');
                if ($entryName === '' || str_ends_with($entryName, '/')) {
                    continue;
                }

                $entryBytes = max(0, (int)($stat['size'] ?? 0));
                $compressedBytes = max(0, (int)($stat['comp_size'] ?? 0));
                if ($entryBytes > $maximumEntryBytes) {
                    throw new InvalidArgumentException(
                        'El XLSX contiene un elemento interno excesivamente grande.'
                    );
                }

                $expandedBytes += $entryBytes;
                if ($expandedBytes > $maximumExpandedBytes) {
                    throw new InvalidArgumentException(
                        'El tamaño expandido del XLSX excede el límite de seguridad.'
                    );
                }

                if ($entryBytes >= 1048576 && $compressedBytes > 0) {
                    $ratio = $entryBytes / $compressedBytes;
                    if ($ratio > $maximumCompressionRatio) {
                        throw new InvalidArgumentException(
                            'El XLSX presenta una relación de compresión no permitida.'
                        );
                    }
                }
            }
        } finally {
            $zip->close();
        }

        MalwareScanner::scanOrFail($tmp);
    }

    /** @param array<string,mixed> $file */
    public static function validateImage(
        array $file,
        int $maxBytes = 12582912,
        bool $allowPdf = false
    ): string {
        self::assertUpload($file, $maxBytes);
        $tmp = (string)$file['tmp_name'];
        $mime = strtolower((string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp));

        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if ($allowPdf) $allowed[] = 'application/pdf';

        if (!in_array($mime, $allowed, true)) {
            throw new InvalidArgumentException(
                $allowPdf
                    ? 'Solo se permiten imágenes JPG, PNG, WebP o PDF.'
                    : 'Solo se permiten imágenes JPG, PNG o WebP.'
            );
        }

        if (str_starts_with($mime, 'image/')) {
            $size = @getimagesize($tmp);
            if (!is_array($size) || ($size[0] ?? 0) < 1 || ($size[1] ?? 0) < 1) {
                throw new InvalidArgumentException('La imagen no tiene una estructura válida.');
            }
            if ((int)$size[0] > 12000 || (int)$size[1] > 12000) {
                throw new InvalidArgumentException('Las dimensiones de la imagen exceden el límite permitido.');
            }
        }

        MalwareScanner::scanOrFail($tmp);
        return $mime;
    }

    /** @param array<string,mixed> $file */
    private static function assertUpload(array $file, int $maxBytes): void
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('No se recibió un archivo válido.');
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            throw new InvalidArgumentException('No fue posible acceder al archivo temporal.');
        }
        if (PHP_SAPI !== 'cli' && !is_uploaded_file($tmp)) {
            throw new InvalidArgumentException('El archivo no proviene de una carga HTTP válida.');
        }
        $size = (int)($file['size'] ?? (filesize($tmp) ?: 0));
        if ($size < 1 || $size > $maxBytes) {
            throw new InvalidArgumentException('El archivo excede el tamaño máximo permitido.');
        }
    }
}
