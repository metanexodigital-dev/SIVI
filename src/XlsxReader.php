<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/XlsxReader.php
 * Propósito: Lee archivos XLSX de forma eficiente sin cargar todo el contenido en memoria.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

/**
 * Lector XLSX por streaming.
 *
 * Evita cargar hojas completas en memoria. Esto es indispensable para
 * reportes de Almacén con decenas de miles de filas y XML internos de
 * varios cientos de megabytes.
 */
final class XlsxReader
{
    private string $path;
    private ZipArchive $zip;
    private array $sharedStrings = [];
    private array $sheets = [];

    public function __construct(string $path)
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('La extensión PHP Zip no está habilitada.');
        }
        if (!class_exists(XMLReader::class)) {
            throw new RuntimeException('La extensión PHP XMLReader no está habilitada.');
        }
        $this->path = $path;
        $this->zip = new ZipArchive();
        if ($this->zip->open($path) !== true) {
            throw new RuntimeException('No fue posible abrir el archivo XLSX.');
        }
        $this->loadSharedStrings();
        $this->loadSheets();
    }

    public function __destruct()
    {
        $this->zip->close();
    }

    public function sheetNames(): array
    {
        return array_keys($this->sheets);
    }

    /**
     * Retorna registros usando la primera fila como encabezado.
     */
    public function rows(string $sheetName, int $headerRow = 0): Generator
    {
        if (!isset($this->sheets[$sheetName])) {
            throw new InvalidArgumentException("No existe la hoja {$sheetName}");
        }

        $sheetPath = $this->sheets[$sheetName];
        $uri = 'zip://' . $this->path . '#' . $sheetPath;
        $reader = new XMLReader();
        if (!$reader->open($uri, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw new RuntimeException("No fue posible leer la hoja {$sheetName}");
        }

        $headers = [];
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                    continue;
                }
                $rowNumber = (int)($reader->getAttribute('r') ?: 0);
                $rowXml = $reader->readOuterXML();
                if ($rowXml === '') continue;
                $valuesByIndex = $this->parseRow($rowXml);

                $nonEmptyValues = array_values(array_filter(
                    $valuesByIndex,
                    static fn($value): bool => trim((string)$value) !== ''
                ));
                $isHeaderRow = $headerRow > 0
                    ? $rowNumber === $headerRow
                    : ($headers === [] && count($nonEmptyValues) >= 2);

                if ($isHeaderRow) {
                    ksort($valuesByIndex);
                    foreach ($valuesByIndex as $idx => $header) {
                        $headers[$idx] = trim((string)$header);
                    }
                    continue;
                }
                if (($headerRow > 0 && $rowNumber < $headerRow) || !$headers) continue;

                $record = [];
                foreach ($headers as $idx => $header) {
                    if ($header === '') continue;
                    $record[$header] = $valuesByIndex[$idx] ?? '';
                }
                if (count(array_filter($record, static fn($v) => $v !== '')) > 0) {
                    yield $record;
                }
            }
        } finally {
            $reader->close();
        }
    }

    private function parseRow(string $rowXml): array
    {
        $row = simplexml_load_string($rowXml);
        if ($row === false) return [];
        $mainNs = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $valuesByIndex = [];
        $rowChildren = $row->children($mainNs);
        if (count($rowChildren) === 0) $rowChildren = $row->children();
        foreach ($rowChildren->c as $cell) {
            $attributes = $cell->attributes();
            $reference = (string)($attributes['r'] ?? '');
            $columnIndex = $this->columnIndex($reference);
            $type = (string)($attributes['t'] ?? '');
            $value = '';
            $cellChildren = $cell->children($mainNs);
            if (count($cellChildren) === 0) $cellChildren = $cell->children();
            if ($type === 'inlineStr') {
                $parts = [];
                if (isset($cellChildren->is)) {
                    $inline = $cellChildren->is->children($mainNs);
                    if (isset($inline->t)) $parts[] = (string)$inline->t;
                    foreach ($inline->r as $run) {
                        $runChildren = $run->children($mainNs);
                        $parts[] = (string)($runChildren->t ?? '');
                    }
                }
                $value = implode('', $parts);
            } elseif (isset($cellChildren->v)) {
                $raw = (string)$cellChildren->v;
                $value = $type === 's' ? ($this->sharedStrings[(int)$raw] ?? '') : $raw;
            }
            $valuesByIndex[$columnIndex] = trim((string)$value);
        }
        return $valuesByIndex;
    }

    private function loadSharedStrings(): void
    {
        $xml = $this->zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) return;
        $reader = new XMLReader();
        if (!$reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) return;
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'si') continue;
            $fragment = $reader->readOuterXML();
            $node = simplexml_load_string($fragment);
            if ($node === false) {
                $this->sharedStrings[] = '';
                continue;
            }
            $node->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $texts = [];
            foreach ($node->xpath('.//a:t') ?: [] as $text) $texts[] = (string)$text;
            $this->sharedStrings[] = implode('', $texts);
        }
        $reader->close();
    }

    private function loadSheets(): void
    {
        $workbookXml = $this->zip->getFromName('xl/workbook.xml');
        $relsXml = $this->zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relsXml === false) {
            throw new RuntimeException('El XLSX no contiene la estructura esperada.');
        }
        $rels = simplexml_load_string($relsXml);
        $relationshipMap = [];
        $relsChildren = $rels->children('http://schemas.openxmlformats.org/package/2006/relationships');
        foreach ($relsChildren->Relationship as $relationship) {
            $attrs = $relationship->attributes();
            $relationshipMap[(string)$attrs['Id']] = (string)$attrs['Target'];
        }
        $workbook = simplexml_load_string($workbookXml);
        $workbook->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        foreach ($workbook->xpath('//a:sheets/a:sheet') ?: [] as $sheet) {
            $attrs = $sheet->attributes();
            $relAttrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $name = (string)$attrs['name'];
            $target = $relationshipMap[(string)$relAttrs['id']] ?? '';
            if ($target === '') continue;
            $target = ltrim($target, '/');
            if (!str_starts_with($target, 'xl/')) $target = 'xl/' . $target;
            $this->sheets[$name] = str_replace('xl/xl/', 'xl/', $target);
        }
    }

    private function columnIndex(string $reference): int
    {
        if (!preg_match('/^([A-Z]+)/i', $reference, $matches)) return 0;
        $index = 0;
        foreach (str_split(strtoupper($matches[1])) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }
        return $index - 1;
    }
}
