<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/XlsxWriter.php
 * Propósito: Genera archivos XLSX seguros para reportes y exportaciones.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

/**
 * Escritor XLSX liviano sin dependencias externas.
 * Genera libros con texto, encabezados, filtros y anchos de columna.
 */
final class XlsxWriter
{
    /**
     * @param array<int,array{name:string,rows:array,header_row?:int,freeze_row?:int,autofilter?:bool}> $sheets
     */
    public static function download(string $filename, array $sheets): never
    {
        $path = self::create($sheets);
        $safeFilename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'reporte.xlsx';

        if (!is_file($path) || !is_readable($path)) {
            @unlink($path);
            throw new RuntimeException('No fue posible preparar el archivo XLSX para la descarga.');
        }

        // Cualquier salida previa (espacios, avisos o HTML) corrompe el ZIP que compone el XLSX.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @ini_set('zlib.output_compression', '0');

        if (headers_sent($sourceFile, $sourceLine)) {
            @unlink($path);
            throw new RuntimeException(
                'No se puede descargar el archivo porque ya se envió contenido desde '
                . $sourceFile . ':' . $sourceLine . '.'
            );
        }

        clearstatcache(true, $path);
        $size = filesize($path);
        header_remove('Content-Type');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
        header('Content-Transfer-Encoding: binary');
        header('Content-Length: ' . ($size === false ? 0 : $size));
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: public');
        header('Expires: 0');
        header('X-Content-Type-Options: nosniff');

        $stream = fopen($path, 'rb');
        if ($stream === false) {
            @unlink($path);
            throw new RuntimeException('No fue posible abrir el archivo XLSX generado.');
        }
        fpassthru($stream);
        fclose($stream);
        @unlink($path);
        exit;
    }

    /**
     * @param array<int,array{name:string,rows:array,header_row?:int,freeze_row?:int,autofilter?:bool}> $sheets
     */
    public static function create(array $sheets): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('La extensión PHP Zip no está habilitada.');
        }
        if (!$sheets) {
            throw new InvalidArgumentException('Debe incluir al menos una hoja.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'sivi_xlsx_');
        if ($tmp === false) {
            throw new RuntimeException('No fue posible crear el archivo temporal XLSX.');
        }
        $xlsxPath = $tmp . '.xlsx';
        @unlink($tmp);

        $zip = new ZipArchive();
        if ($zip->open($xlsxPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No fue posible crear el archivo XLSX.');
        }

        $sheetNames = [];
        foreach ($sheets as $index => $sheet) {
            $sheetNames[] = self::safeSheetName((string)($sheet['name'] ?? ('Hoja ' . ($index + 1))), $sheetNames);
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypes(count($sheets)));
        $zip->addFromString('_rels/.rels', self::rootRelationships());
        $zip->addFromString('docProps/app.xml', self::appProperties($sheetNames));
        $zip->addFromString('docProps/core.xml', self::coreProperties());
        $zip->addFromString('xl/workbook.xml', self::workbook($sheetNames));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRelationships(count($sheets)));
        $zip->addFromString('xl/styles.xml', self::styles());

        foreach ($sheets as $index => $sheet) {
            $zip->addFromString(
                'xl/worksheets/sheet' . ($index + 1) . '.xml',
                self::worksheet(
                    (array)($sheet['rows'] ?? []),
                    (int)($sheet['header_row'] ?? 1),
                    (int)($sheet['freeze_row'] ?? 1),
                    (bool)($sheet['autofilter'] ?? true)
                )
            );
        }
        $zip->close();
        return $xlsxPath;
    }

    private static function worksheet(array $rows, int $headerRow, int $freezeRow, bool $autoFilter): string
    {
        $maxColumns = 1;
        $widths = [];
        foreach ($rows as $row) {
            $maxColumns = max($maxColumns, count((array)$row));
            foreach (array_values((array)$row) as $col => $value) {
                $length = self::textLength((string)$value);
                $widths[$col] = min(45, max($widths[$col] ?? 10, $length + 2));
            }
        }

        $cols = '';
        for ($i = 0; $i < $maxColumns; $i++) {
            $width = max(10, min(45, (float)($widths[$i] ?? 14)));
            $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $width . '" customWidth="1"/>';
        }

        $sheetData = '';
        foreach ($rows as $rowIndex => $row) {
            $excelRow = $rowIndex + 1;
            $style = $excelRow === $headerRow ? 1 : 2;
            $cells = '';
            foreach (array_values((array)$row) as $colIndex => $value) {
                $reference = self::columnName($colIndex + 1) . $excelRow;
                $text = (string)$value;
                $cells .= '<c r="' . $reference . '" t="inlineStr" s="' . $style . '"><is><t xml:space="preserve">' . self::xml($text) . '</t></is></c>';
            }
            $sheetData .= '<row r="' . $excelRow . '">' . $cells . '</row>';
        }

        $lastCell = self::columnName($maxColumns) . max(1, count($rows));
        $filter = ($autoFilter && count($rows) >= $headerRow) ? '<autoFilter ref="A' . $headerRow . ':' . self::columnName($maxColumns) . max($headerRow, count($rows)) . '"/>' : '';
        $pane = $freezeRow > 0
            ? '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . $freezeRow . '" topLeftCell="A' . ($freezeRow + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            : '<sheetViews><sheetView workbookViewId="0"/></sheetViews>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="A1:' . $lastCell . '"/>'
            . $pane
            . '<sheetFormatPr defaultRowHeight="18"/>'
            . '<cols>' . $cols . '</cols>'
            . '<sheetData>' . $sheetData . '</sheetData>'
            . $filter
            . '<pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>'
            . '</worksheet>';
    }

    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="10"/><name val="Aptos"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="10"/><name val="Aptos"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF174A7E"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFD9E2F3"/></left><right style="thin"><color rgb="FFD9E2F3"/></right><top style="thin"><color rgb="FFD9E2F3"/></top><bottom style="thin"><color rgb="FFD9E2F3"/></bottom><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="49" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private static function contentTypes(int $sheetCount): string
    {
        $overrides = '';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . $overrides . '</Types>';
    }

    private static function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private static function workbook(array $sheetNames): string
    {
        $sheets = '';
        foreach ($sheetNames as $index => $name) {
            $sheets .= '<sheet name="' . self::xml($name) . '" sheetId="' . ($index + 1) . '" r:id="rId' . ($index + 1) . '"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<bookViews><workbookView xWindow="0" yWindow="0" windowWidth="24000" windowHeight="12000"/></bookViews>'
            . '<sheets>' . $sheets . '</sheets><calcPr calcId="191029"/></workbook>';
    }

    private static function workbookRelationships(int $sheetCount): string
    {
        $relationships = '';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $relationships .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        }
        $relationships .= '<Relationship Id="rId' . ($sheetCount + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relationships . '</Relationships>';
    }

    private static function appProperties(array $sheetNames): string
    {
        $titles = '';
        foreach ($sheetNames as $name) {
            $titles .= '<vt:lpstr>' . self::xml($name) . '</vt:lpstr>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>SIVI</Application><DocSecurity>0</DocSecurity><ScaleCrop>false</ScaleCrop>'
            . '<HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Hojas</vt:lpstr></vt:variant><vt:variant><vt:i4>' . count($sheetNames) . '</vt:i4></vt:variant></vt:vector></HeadingPairs>'
            . '<TitlesOfParts><vt:vector size="' . count($sheetNames) . '" baseType="lpstr">' . $titles . '</vt:vector></TitlesOfParts>'
            . '<Company>RNEC</Company><AppVersion>1.0</AppVersion></Properties>';
    }

    private static function coreProperties(): string
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>SIVI</dc:creator><cp:lastModifiedBy>SIVI</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified></cp:coreProperties>';
    }

    private static function safeSheetName(string $name, array $existing): string
    {
        // Excel no permite los caracteres \ / : * ? [ ] en el nombre de una hoja.
        // Se usa ~ como delimitador para evitar que la barra / cierre accidentalmente la expresión.
        $name = preg_replace('~[\\\\/:?*\[\]]~u', ' ', trim($name)) ?: 'Hoja';
        $name = trim($name, " '");
        if ($name === '') {
            $name = 'Hoja';
        }
        $name = self::textSubstring($name, 0, 31);
        $base = $name;
        $suffix = 2;
        while (in_array($name, $existing, true)) {
            $tail = ' ' . $suffix++;
            $name = self::textSubstring($base, 0, 31 - self::textLength($tail)) . $tail;
        }
        return $name;
    }

    private static function columnName(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)) . $name;
            $number = intdiv($number, 26);
        }
        return $name;
    }

    private static function xml(string $value): string
    {
        $value = preg_replace('/[^\P{C}\t\n\r]/u', '', $value) ?? '';
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private static function textSubstring(string $value, int $start, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, $start, $length, 'UTF-8') : substr($value, $start, $length);
    }
}
