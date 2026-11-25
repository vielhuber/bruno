<?php

declare(strict_types=1);

namespace Bruno;

require __DIR__ . '/../src/bootstrap.php';

try {
    ['tasks' => $result, 'failed' => $failed] = (new ScheduledCron($config))->run();
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
    exit($failed ? 1 : 0);
} catch (AppException $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
