<?php
declare(strict_types=1);

return [
    ['name' => 'receive_address', 'type' => 'input', 'title' => '波场 TRON 主网收款地址（USDT-TRC20）', 'required' => true, 'placeholder' => '填写商户自己的 TRC20 收款地址'],
    ['name' => 'rate_mode', 'type' => 'custom', 'title' => '', 'default' => 'fixed', 'required' => false],
    ['name' => 'cny_per_usdt', 'type' => 'input', 'title' => '固定汇率：1 USDT = 多少 CNY', 'required' => true, 'placeholder' => '明确填写 4 至 12，最多四位小数'],
    ['name' => 'tron_api_key', 'type' => 'password', 'title' => 'TronGrid API Key', 'required' => false, 'placeholder' => '可留空，公共查询可能受到限流'],
    ['name' => 'min_usdt', 'type' => 'input', 'title' => '最低应付 USDT', 'default' => '1.000000', 'required' => true],
    ['name' => 'payment_usdt_scale', 'type' => 'number', 'title' => '应付 USDT 小数位（4～6）', 'default' => '4', 'required' => true],
    ['name' => 'order_ttl_seconds', 'type' => 'number', 'title' => '付款有效期秒数（600～3600）', 'default' => '1800', 'required' => true],
    ['name' => 'settlement_grace_seconds', 'type' => 'number', 'title' => '确认宽限秒数（1800～86400）', 'default' => '1800', 'required' => true],
    ['name' => 'confirm_seconds', 'type' => 'number', 'title' => '已确认交易等待秒数（60～600）', 'default' => '60', 'required' => true],
    ['name' => 'payment_clock_skew_seconds', 'type' => 'number', 'title' => '时间容差秒数（0～30）', 'default' => '30', 'required' => true],
    ['name' => 'chain_check_min_interval_seconds', 'type' => 'number', 'title' => '查链最小间隔秒数（5～60）', 'default' => '5', 'required' => true],
    ['name' => 'poll_interval_seconds', 'type' => 'number', 'title' => '页面查询间隔秒数（5～60）', 'default' => '8', 'required' => true],
    ['name' => 'max_active_unpaid_orders', 'type' => 'number', 'title' => '每个访问 IP 有效未付订单上限（1～10）', 'default' => '3', 'required' => true],
    ['name' => 'unique_amount_max', 'type' => 'number', 'title' => '精确金额尾数容量（1～999）', 'default' => '999', 'required' => true],
];
