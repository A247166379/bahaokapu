<?php
declare(strict_types=1);

namespace App\Pay\UsdtTrc20\Impl;

use App\Entity\PayEntity;
use App\Pay\Base;
use App\Util\UsdtPayment;

final class Pay extends Base implements \App\Pay\Pay
{
    public function trade(): PayEntity
    {
        $instructions = UsdtPayment::create($this->tradeNo, $this->amount, $this->config, $this->returnUrl);
        // Only buyer instructions are persisted as publicly renderable OrderOption values.
        $allowed = ['receive_address', 'usdt_amount', 'expires_at', 'settlement_expires_at', 'poll_token',
            'network', 'return_url', 'rate', 'poll_interval_seconds'];
        $options = array_intersect_key($instructions, array_flip($allowed));
        $entity = new PayEntity();
        $entity->setType(\App\Pay\Pay::TYPE_LOCAL_RENDER);
        $entity->setUrl('');
        $entity->setOption($options);
        return $entity;
    }
}
