<?php
declare(strict_types=1);

return [
    'name' => 'USDT-TRC20',
    'author' => 'Local Store',
    'version' => '1.0.0',
    'description' => 'TRON 主网 USDT 精确金额收款，服务端确认链上证据后交付商品。',
    'options' => ['usdt' => 'USDT'],
    'callback' => [
        \App\Consts\Pay::IS_SIGN => true,
        \App\Consts\Pay::IS_STATUS => true,
        \App\Consts\Pay::FIELD_STATUS_KEY => 'status',
        \App\Consts\Pay::FIELD_STATUS_VALUE => 'paid',
        \App\Consts\Pay::FIELD_ORDER_KEY => 'trade_no',
        \App\Consts\Pay::FIELD_AMOUNT_KEY => 'amount',
        \App\Consts\Pay::FIELD_RESPONSE => 'rejected',
    ],
];
