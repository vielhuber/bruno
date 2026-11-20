<?php

declare(strict_types=1);

namespace Bruno;

use finfo;

require_once __DIR__ . '/AppException.php';
require_once __DIR__ . '/AnkiBridge.php';
require_once __DIR__ . '/AnkiSync.php';
require_once __DIR__ . '/BackupHistory.php';
require_once __DIR__ . '/Account.php';
require_once __DIR__ . '/Credentials.php';
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
 * Return validated upload data; never use the supplied filename.
 *
 * @return array{mime: string, extension: string, data: string}|null
 */
function upload(string $field, array $types, int $limit): ?array
{
    $file = $_FILES[$field] ?? [];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > $limit || $file['size'] < 1) {
        throw new AppException('Die Datei konnte nicht gelesen werden oder ist zu groß.', 422);
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new AppException('Ungültiger Upload.', 422);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (in_array($mime, ['audio/x-wav', 'audio/vnd.wave', 'audio/wave'], strict: true)) {
        $mime = 'audio/wav';
    }
    if (!isset($types[$mime])) {
        throw new AppException('Dieses Dateiformat wird nicht unterstützt.', 422);
    }
    $data = file_get_contents($file['tmp_name']);
    if ($data === false) {
        throw new AppException('Die Datei konnte nicht gelesen werden.', 422);
    }

    return ['mime' => $mime, 'extension' => $types[$mime], 'data' => base64_encode($data)];
}
