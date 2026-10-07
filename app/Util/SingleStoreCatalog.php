<?php
declare(strict_types=1);

namespace App\Util;

use App\Model\Commodity;
use App\Model\Pay;
use Kernel\Exception\JSONException;

/** New storefront purchases use only this shop's local inventory and gateway payments. */
final class SingleStoreCatalog
{
    public static function isLocal(?Commodity $commodity): bool
    {
        return $commodity !== null && (int)$commodity->owner === 0 && (int)$commodity->shared_id === 0;
    }

    public static function assertLocal(?Commodity $commodity): void
    {
        if (!self::isLocal($commodity)) {
            throw new JSONException('商品不存在');
        }
    }

    public static function assertPayment(?Pay $pay): void
    {
        if (!$pay || (int)$pay->commodity !== 1 || (int)$pay->archived === 1 || (string)$pay->handle === '#system') {
            throw new JSONException('该支付方式不存在');
        }
    }
}
