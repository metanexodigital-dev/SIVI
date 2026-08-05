<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/lib/ProcessRunner.php
 * Propósito: Ejecuta comandos de validación y captura duración y salida.
 */
declare(strict_types=1);

final class ProcessRunner
{
    /** @param array<int,string> $command
     *  @return array<string,mixed>
     */
    public static function run(array $command, string $cwd): array
    {
        $job = self::start($command, $cwd);
        while (true) {
            $result = self::poll($job);
            if ($result !== null) {
                return $result;
            }
            usleep(20000);
        }
    }

    /** @param array<int,string> $command
     *  @return array<string,mixed>
     */
    public static function start(array $command, string $cwd): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $startedAt = microtime(true);
        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            $cwd,
            null,
            ['bypass_shell' => true]
        );

        if (!is_resource($process)) {
            throw new RuntimeException(
                'No fue posible iniciar: ' . implode(' ', $command)
            );
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [
            'process' => $process,
            'pipes' => $pipes,
            'command' => $command,
            'started_at' => $startedAt,
            'stdout' => '',
            'stderr' => '',
        ];
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>|null
     */
    public static function poll(array &$job): ?array
    {
        $job['stdout'] .= stream_get_contents($job['pipes'][1]) ?: '';
        $job['stderr'] .= stream_get_contents($job['pipes'][2]) ?: '';

        $status = proc_get_status($job['process']);
        if ($status['running']) {
            return null;
        }

        $job['stdout'] .= stream_get_contents($job['pipes'][1]) ?: '';
        $job['stderr'] .= stream_get_contents($job['pipes'][2]) ?: '';
        fclose($job['pipes'][1]);
        fclose($job['pipes'][2]);

        $exitCode = (int)$status['exitcode'];
        $closedCode = proc_close($job['process']);
        if ($exitCode < 0) {
            $exitCode = $closedCode;
        }

        return [
            'ok' => $exitCode === 0,
            'exit_code' => $exitCode,
            'command' => $job['command'],
            'stdout' => trim((string)$job['stdout']),
            'stderr' => trim((string)$job['stderr']),
            'duration_ms' => (int)round(
                (microtime(true) - (float)$job['started_at']) * 1000
            ),
        ];
    }
}
