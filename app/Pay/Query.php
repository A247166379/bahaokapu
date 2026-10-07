<?php
declare(strict_types=1);

namespace App\Pay;

/**
 * Optional payment-order query capability.
 *
 * A payment plugin can implement this when the gateway exposes an authenticated
 * order-query API. The returned map must be shaped exactly like a normal signed
 * callback, so the core callback pipeline still performs signature, order-number
 * and amount verification before any order is fulfilled.
 */
interface Query
{
    public function paidCallback(string $tradeNo, string $expectedAmount, array $config): ?array;
}
