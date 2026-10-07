<?php
declare(strict_types=1);

namespace App\Util;

use App\Model\Order;

/**
 * Reconcile an exact pending order through a payment plugin's authenticated
 * query API. This is a fallback for development/local installations whose
 * 127.0.0.1 callback URL cannot be reached by the remote gateway.
 */
final class PayReconciler
{
    public static function order(string $tradeNo, \App\Service\Order $service, ?int $ownerId = null): bool
    {
        $tradeNo = trim($tradeNo);
        if (preg_match('/^\d{18}$/D', $tradeNo) !== 1) {
            return false;
        }

        // Avoid turning repeated page refreshes into an upstream request flood.
        if (Throttle::tooMany('pay-reconcile-order:' . $tradeNo, 1, 5)) {
            return false;
        }

        $builder = Order::with(['pay'])->where('trade_no', $tradeNo)->where('status', 0);
        if ($ownerId !== null) {
            $builder->where('owner', $ownerId);
        }
        $order = $builder->first();
        if (!$order || !$order->pay) {
            return false;
        }

        $handle = (string)$order->pay->handle;
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $handle) !== 1) {
            return false;
        }

        $class = "\\App\\Pay\\{$handle}\\Impl\\Query";
        if (!class_exists($class) || !is_subclass_of($class, \App\Pay\Query::class)) {
            return false;
        }

        try {
            $autoload = BASE_PATH . '/app/Pay/' . $handle . '/Vendor/autoload.php';
            if (is_file($autoload)) {
                require_once $autoload;
            }

            /** @var \App\Pay\Query $query */
            $query = new $class();
            $config = PayProfile::config($order->pay);
            $sourceAmount = $order->gateway_amount !== null
                ? (string)$order->gateway_amount
                : (string)$order->amount;
            $map = $query->paidCallback($tradeNo, $sourceAmount, $config);
            if ($map === null) {
                return false;
            }

            // Reuse the normal callback pipeline: plugin signature, order number,
            // amount, transaction lock and fulfilment are all checked in one place.
            $service->callback($tradeNo, $map);
            return (int)(Order::query()->where('trade_no', $tradeNo)->value('status') ?? 0) === 1;
        } catch (\Throwable $exception) {
            // A real callback may have won the race. Treat that as success; log all
            // other failures without exposing credentials to the public query API.
            if ((int)(Order::query()->where('trade_no', $tradeNo)->value('status') ?? 0) === 1) {
                return true;
            }
            $message = preg_replace('/[\x00-\x1F\x7F]/u', ' ', strip_tags($exception->getMessage())) ?? '';
            PayConfig::log($handle, 'QUERY', mb_substr(trim($message), 0, 180));
            return false;
        }
    }
}
