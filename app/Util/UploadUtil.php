<?php
declare(strict_types=1);

namespace App\Util;

/** Shared limits and content checks for authenticated upload endpoints. */
final class UploadUtil
{
    public const MAX_KIB = 50 * 1024;
    public const IMAGES = ['jpg', 'jpeg', 'png', 'bmp', 'webp', 'ico', 'gif'];
    public const ALL = ['jpg', 'jpeg', 'png', 'bmp', 'webp', 'ico', 'gif', 'mp4', 'zip', 'woff', 'woff2', 'ttf', 'otf'];

    public static function extensions(string $group): array
    {
        return match ($group) {
            'image' => self::IMAGES,
            'video' => ['mp4'],
            'doc' => ['zip'],
            'other' => self::ALL,
            default => [],
        };
    }

    public static function missingFileMessage(): string
    {
        $setting = trim((string)ini_get('post_max_size'));
        $limit = (float)$setting;
        $unit = strtolower(substr($setting, -1));
        $limit *= match ($unit) { 'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1 };
        return $limit > 0 && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $limit
            ? '上传数据超过服务器大小限制，请缩小文件后重试' : '请选择文件';
    }

    /** Checks the on-disk bytes; does not move files or trust browser MIME/size. */
    public static function inspect(array $upload, array $allowed, int $limitKiB): array|string
    {
        if (!isset($upload['name'], $upload['tmp_name'], $upload['size'], $upload['error'])
            || !is_string($upload['name']) || !is_string($upload['tmp_name'])
            || !is_int($upload['size']) || !is_int($upload['error'])) {
            return '上传文件参数不正确';
        }
        if ($upload['error'] !== UPLOAD_ERR_OK) {
            return match ($upload['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '文件太大，无法上传',
                UPLOAD_ERR_PARTIAL => '上传失败，文件可能损坏',
                UPLOAD_ERR_NO_FILE => '请选择文件',
                UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => '上传失败，服务器无法写入文件',
                default => '文件上传失败',
            };
        }
        $ext = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowed, true)) {
            return '不支持的文件格式，仅支持：' . implode('、', $allowed);
        }
        $tmp = $upload['tmp_name'];
        $bytes = is_file($tmp) && !is_link($tmp) ? @filesize($tmp) : false;
        if ($bytes === false || $bytes < 1 || $bytes !== $upload['size']) {
            return '上传文件为空或大小不正确';
        }
        if ($limitKiB < 1 || $bytes > min($limitKiB, self::MAX_KIB) * 1024) {
            return '文件太大，最大支持 ' . min($limitKiB, self::MAX_KIB) . ' KB';
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (in_array($ext, self::IMAGES, true)) {
            $image = @getimagesize($tmp);
            $expected = match ($ext) {
                'jpg', 'jpeg' => IMAGETYPE_JPEG, 'png' => IMAGETYPE_PNG,
                'bmp' => IMAGETYPE_BMP, 'webp' => IMAGETYPE_WEBP,
                'ico' => IMAGETYPE_ICO, 'gif' => IMAGETYPE_GIF,
            };
            if (!is_array($image) || ($image[2] ?? 0) !== $expected || !str_starts_with((string)$mime, 'image/')) {
                return '图片内容与文件格式不匹配';
            }
            if ($image[0] < 1 || $image[1] < 1 || $image[0] > 20000 || $image[1] > 20000
                || $image[0] * $image[1] > 40000000) {
                return '图片尺寸过大，最多支持四千万像素';
            }
        } else {
            $head = (string)@file_get_contents($tmp, false, null, 0, 32);
            $valid = match ($ext) {
                'mp4' => strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp'
                    && in_array($mime, ['video/mp4', 'application/mp4', 'video/quicktime'], true)
                    && self::validVideo($tmp, $bytes),
                'zip' => self::validArchive($tmp, $head, (string)$mime),
                'woff', 'woff2', 'ttf', 'otf' => self::validFont($ext, $head, $bytes, (string)$mime),
                default => false,
            };
            if (!$valid) return '文件内容与文件格式不匹配';
        }
        return ['tmp' => $tmp, 'size' => $bytes / 1024, 'name' => $upload['name'], 'fix' => $ext];
    }

    private static function validFont(string $ext, string $head, int $bytes, string $mime): bool
    {
        if (strlen($head) < 16 || !in_array($mime, ['font/ttf', 'font/sfnt', 'font/otf', 'font/woff', 'font/woff2',
            'application/font-sfnt', 'application/x-font-sfnt', 'application/x-font-ttf', 'application/x-font-otf', 'application/font-woff',
            'application/x-font-woff', 'application/x-font-woff2', 'application/vnd.ms-opentype', 'application/octet-stream'], true)) return false;
        if ($ext === 'woff' || $ext === 'woff2') {
            $tables = unpack('n', substr($head, 12, 2))[1];
            return str_starts_with($head, $ext === 'woff' ? 'wOFF' : 'wOF2')
                && unpack('N', substr($head, 8, 4))[1] === $bytes && $tables > 0 && $tables <= 4096
                && $bytes >= ($ext === 'woff' ? 44 + 20 * $tables : 48 + 2 * $tables);
        }
        $tables = unpack('n', substr($head, 4, 2))[1];
        return ($ext === 'otf' ? str_starts_with($head, 'OTTO') :
            (str_starts_with($head, "\x00\x01\x00\x00") || str_starts_with($head, 'true')))
            && $tables > 0 && $tables <= 4096 && $bytes >= 12 + 16 * $tables;
    }

    private static function validVideo(string $path, int $bytes): bool
    {
        $stream = @fopen($path, 'rb');
        if (!$stream) return false;
        $offset = 0;
        $types = [];
        try {
            for ($count = 0; $offset < $bytes && $count < 10000; $count++) {
                if (fseek($stream, $offset) !== 0 || strlen($head = (string)fread($stream, 8)) !== 8) return false;
                $size = unpack('N', substr($head, 0, 4))[1];
                $minimum = 8;
                if ($size === 1) {
                    if (strlen($wide = (string)fread($stream, 8)) !== 8) return false;
                    $parts = unpack('Nhigh/Nlow', $wide);
                    if ($parts['high'] !== 0) return false; // files are bounded to 50 MiB
                    $size = $parts['low'];
                    $minimum = 16;
                } elseif ($size === 0) {
                    $size = $bytes - $offset;
                }
                if ($size < $minimum || $size > $bytes - $offset) return false;
                $types[substr($head, 4, 4)] = true;
                $offset += $size;
            }
            return $offset === $bytes && isset($types['ftyp'], $types['moov'], $types['mdat']);
        } finally {
            fclose($stream);
        }
    }

    private static function validArchive(string $path, string $head, string $mime): bool
    {
        if (!in_array(substr($head, 0, 4), ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true)
            || !in_array($mime, ['application/zip', 'application/x-zip', 'application/x-zip-compressed', 'application/octet-stream'], true)) return false;
        if (!class_exists(\ZipArchive::class)) return true;
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CHECKCONS) !== true) return false;
        $zip->close();
        return true;
    }
}
