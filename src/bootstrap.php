<?php

declare(strict_types=1);

namespace Bruno;

use finfo;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/AppException.php';
require_once __DIR__ . '/AnkiBridge.php';
require_once __DIR__ . '/AnkiSync.php';
require_once __DIR__ . '/BackupHistory.php';
require_once __DIR__ . '/Account.php';
require_once __DIR__ . '/Credentials.php';
require_once __DIR__ . '/Upload.php';
require_once __DIR__ . '/GatewayGemini.php';
require_once __DIR__ . '/GatewayCodex.php';
require_once __DIR__ . '/Gateway.php';

umask(0o077);
$config = array_replace(
    require __DIR__ . '/../config.example.php',
    is_file(__DIR__ . '/../config.local.php') ? require __DIR__ . '/../config.local.php' : [],
);

/**
 * Return trimmed scalar input without coercing arrays or truncating it.
 */
function input(array $values, string $key, int $maximum = 0): string
{
    $value = $values[$key] ?? '';
    $value = is_string($value) ? trim($value) : '';
    if ($maximum > 0 && !mb_check_encoding($value, encoding: 'UTF-8')) {
        throw new AppException('Bitte UTF-8-kodierten Text verwenden.', 422);
    }
    if ($maximum > 0 && mb_strlen($value) > $maximum) {
        throw new AppException('Eine Eingabe ist zu lang.', 422);
    }

    return $value;
}

/**
 * Escape plain text for use in both text nodes and quoted attributes.
 */
function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, encoding: 'UTF-8');
}

/**
 * Validate the complete upload set before it can be sent to the AI.
 *
 * @param array<string, string> $types
 * @return list<Upload>
 */
function uploads(string $field, array $types, int $limit, int $maxFiles = 1): array
{
    $file = $_FILES[$field] ?? [];
    $errors = (array) ($file['error'] ?? []);
    if (count($errors) > $maxFiles) {
        throw new AppException('Zu viele Dateien. Erlaubt sind höchstens ' . $maxFiles . '.', 422);
    }
    $paths = (array) ($file['tmp_name'] ?? []);
    $sizes = (array) ($file['size'] ?? []);
    $detector = new finfo(FILEINFO_MIME_TYPE);
    $validated = [];
    foreach ($errors as $index => $error) {
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $size = $sizes[$index] ?? 0;
        if ($error !== UPLOAD_ERR_OK || !is_int($size) || $size < 1 || $size > $limit) {
            throw new AppException('Eine Datei konnte nicht gelesen werden oder die zulässige Gesamtgröße wurde überschritten.', 422);
        }
        $limit -= $size;
        $path = $paths[$index] ?? '';
        if (!is_string($path) || !is_uploaded_file($path)) {
            throw new AppException('Ungültiger Upload.', 422);
        }
        $mime = $detector->file($path);
        if (in_array($mime, ['audio/x-wav', 'audio/vnd.wave', 'audio/wave'], strict: true)) {
            $mime = 'audio/wav';
        }
        if ($mime === false || !isset($types[$mime])) {
            throw new AppException('Dieses Dateiformat wird nicht unterstützt.', 422);
        }
        $validated[] = new Upload($path, $mime, $types[$mime]);
    }

    return $validated;
}
