<?php
declare(strict_types=1);

// Real addresses and API credentials are entered in the admin configuration profile.
return [
    'top' => 0,
    'receive_address' => '',
    'cny_per_usdt' => '',
    'tron_api_key' => '',
    'min_usdt' => '1.000000',
    'payment_usdt_scale' => '4',
    'order_ttl_seconds' => '1800',
    'settlement_grace_seconds' => '1800',
    'confirm_seconds' => '60',
    'payment_clock_skew_seconds' => '30',
    'chain_check_min_interval_seconds' => '5',
    'poll_interval_seconds' => '8',
    'max_active_unpaid_orders' => '3',
    'unique_amount_max' => '999',
];
