<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/lib/CheckRunner.php
 * Propósito: Unifica resultados, métricas y códigos de salida de controles CLI.
 */
declare(strict_types=1);

final class CheckRunner
{
    private float $startedAt;
    /** @var array<string,array{ok:bool,detail:string}> */
    private array $checks = [];

    public function __construct(
        private readonly string $name,
        private readonly string $root
    ) {
        $this->startedAt = microtime(true);
    }

    public function add(string $name, bool $ok, string $detail = ''): void
    {
        $this->checks[$name] = [
            'ok' => $ok,
            'detail' => $detail,
        ];
    }

    public function assertFile(string $relative): void
    {
        $path = $this->root . '/' . ltrim($relative, '/');
        $this->add(
            'file:' . $relative,
            is_file($path) && filesize($path) > 0,
            is_file($path) ? 'Presente' : 'Faltante'
        );
    }

    public function assertContains(
        string $relative,
        string $needle,
        ?string $checkName = null
    ): void {
        $path = $this->root . '/' . ltrim($relative, '/');
        $contents = is_file($path) ? (string)file_get_contents($path) : '';
        $this->add(
            $checkName ?? ('contains:' . $relative),
            $contents !== '' && str_contains($contents, $needle),
            $needle
        );
    }

    public function assertJson(string $relative): void
    {
        $path = $this->root . '/' . ltrim($relative, '/');
        $ok = false;
        $detail = 'Faltante';
        if (is_file($path)) {
            try {
                json_decode(
                    (string)file_get_contents($path),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
                $ok = true;
                $detail = 'JSON válido';
            } catch (Throwable $exception) {
                $detail = $exception->getMessage();
            }
        }
        $this->add('json:' . $relative, $ok, $detail);
    }

    /** @return array<string,mixed> */
    public function result(): array
    {
        $failed = array_keys(array_filter(
            $this->checks,
            static fn(array $check): bool => !$check['ok']
        ));

        return [
            'ok' => $failed === [],
            'check' => $this->name,
            'duration_ms' => (int)round(
                (microtime(true) - $this->startedAt) * 1000
            ),
            'memory_peak_mb' => round(
                memory_get_peak_usage(true) / 1048576,
                2
            ),
            'checks' => $this->checks,
            'failed' => $failed,
        ];
    }

    public function outputAndExit(): never
    {
        $result = $this->result();
        echo json_encode(
            $result,
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        ) . PHP_EOL;
        exit($result['ok'] ? 0 : 2);
    }
}
