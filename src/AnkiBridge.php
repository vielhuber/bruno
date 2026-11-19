<?php

declare(strict_types=1);

namespace Bruno;

use SensitiveParameter;

final class AnkiBridge
{
    /**
     * Use an explicit interpreter so the CLI and the web server share the same Anki version.
     */
    public function __construct(
        private readonly string $python,
        private readonly string $directory,
    ) {
    }

    /**
     * Keep collection ownership checks close to the Anki operation.
     */
    public function call(string $action, #[SensitiveParameter] array $request = []): mixed
    {
        $payload = json_encode(['action' => $action] + $request, JSON_THROW_ON_ERROR);
        $process = proc_open(
            [$this->python, '-B', __DIR__ . '/collection_bridge.py', $this->directory],
            [['pipe', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'a']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new AppException('Anki ist nicht eingerichtet.', 503);
        }

        $written = fwrite($pipes[0], $payload);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $status = proc_close($process);
        $response = json_decode((string) $output, associative: true);

        if ($written !== strlen($payload) || $status !== 0 || !is_array($response)) {
            throw new AppException('Anki ist momentan nicht erreichbar.', 503);
        }
        if (isset($response['error'])) {
            if (isset($response['diagnostic'])) {
                error_log('bruno: Anki ' . $response['diagnostic'] . ' (' . $action . ')');
            }

            throw new AppException($response['error'], $response['status']);
        }

        return $response['result'];
    }
}
