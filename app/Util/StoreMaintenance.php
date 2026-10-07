<?php
declare(strict_types=1);

namespace App\Util;

use App\Model\Config;
use Kernel\Exception\JSONException;

/** Maintenance stops new shopping requests, while existing order queries and callbacks stay available. */
final class StoreMaintenance
{
    public static function closed(): bool
    {
        return Config::get('closed') === '1';
    }

    public static function assertOpen(): void
    {
        if (self::closed()) throw new JSONException(lang('店铺正在维护'));
    }

    public static function responseHeaders(): void
    {
        http_response_code(503);
        header('Cache-Control: no-store, max-age=0');
        header('Retry-After: 60');
    }
}
