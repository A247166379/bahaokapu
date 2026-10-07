<?php
declare(strict_types=1);

namespace App\Util;

/** Configuration validation stays offline; invoices explicitly freeze fixed or cached market rates. */
final class UsdtConfig
{
    public const API = 'https://api.trongrid.io';
    public const CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

    public static function assertRuntime(): void
    {
        if (!extension_loaded('bcmath')) {
            throw new \RuntimeException('USDT 支付需要 BCMath 扩展');
        }
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('USDT 支付需要 cURL 扩展');
        }
    }

    public static function normalize(array $config): array
    {
        self::assertRuntime();
        $address = self::text($config['receive_address'] ?? '', '收款地址');
        TronUsdtVerifier::addressToHex($address);
        if (isset($config['tron_api']) && self::text($config['tron_api'], '链查询地址') !== self::API) {
            throw new \InvalidArgumentException('USDT 只允许官方 TronGrid HTTPS 地址');
        }
        if (isset($config['usdt_contract']) && self::text($config['usdt_contract'], '合约') !== self::CONTRACT) {
            throw new \InvalidArgumentException('USDT 合约必须为 TRON 主网 USDT 合约');
        }
        $mode = self::rateMode($config);
        $rate = '';
        if ($mode === 'fixed') {
            $rateUnits = self::amountToUnits(self::text($config['cny_per_usdt'] ?? $config['rate'] ?? '', 'CNY/USDT 汇率'), 4);
            if (bccomp($rateUnits, '40000', 0) < 0 || bccomp($rateUnits, '120000', 0) > 0) {
                throw new \InvalidArgumentException('请明确设置 4 至 12 的 CNY/USDT 汇率');
            }
            $rate = self::unitsToAmount($rateUnits, 4);
        }
        $key = self::text($config['tron_api_key'] ?? '', 'TronGrid API Key');
        if (!preg_match('/^[A-Za-z0-9_-]{0,256}$/D', $key)) {
            throw new \InvalidArgumentException('TronGrid API Key 格式无效');
        }
        $minimum = self::text($config['min_usdt'] ?? '1.000000', '最低 USDT 金额');
        $minimumUnits = self::amountToUnits($minimum);
        if (bccomp($minimumUnits, '0', 0) <= 0 || bccomp($minimumUnits, '10000000000', 0) > 0) {
            throw new \InvalidArgumentException('最低 USDT 金额无效');
        }
        return [
            'receive_address' => $address,
            'rate_mode' => $mode,
            'rate' => $rate,
            'cny_per_usdt' => $rate,
            'tron_api' => self::API,
            'tron_api_key' => $key,
            'usdt_contract' => self::CONTRACT,
            'network' => 'TRC20',
            'min_usdt' => self::unitsToAmount($minimumUnits),
            'payment_usdt_scale' => self::integer($config, 'payment_usdt_scale', 4, 4, 6),
            'order_ttl_seconds' => self::integer($config, 'order_ttl_seconds', 1800, 600, 3600),
            'settlement_grace_seconds' => self::integer($config, 'settlement_grace_seconds', 1800, 1800, 86400),
            'confirm_seconds' => self::integer($config, 'confirm_seconds', 60, 60, 600),
            'payment_clock_skew_seconds' => self::integer($config, 'payment_clock_skew_seconds', 30, 0, 30),
            'chain_check_min_interval_seconds' => self::integer($config, 'chain_check_min_interval_seconds', 5, 5, 60),
            'poll_interval_seconds' => self::integer($config, 'poll_interval_seconds', 8, 5, 60),
            'max_active_unpaid_orders' => self::integer($config, 'max_active_unpaid_orders', 3, 1, 10),
            'unique_amount_max' => self::integer($config, 'unique_amount_max', 999, 1, 999),
        ];
    }

    public static function rateMode(array $config): string
    {
        $mode = self::text(array_key_exists('rate_mode', $config) ? $config['rate_mode'] : 'fixed', '汇率模式');
        if (!in_array($mode, ['fixed', 'realtime'], true)) throw new \InvalidArgumentException('请选择实时汇率或固定汇率');
        return $mode;
    }

    /** Freeze an effective fixed rate so existing invoice scans remain completely offline. */
    public static function forInvoice(array $raw): array
    {
        $config = self::normalize($raw);
        $mode = $config['rate_mode'];
        $quote = $mode === 'realtime' ? UsdtExchangeRate::cachedQuote()
            : ['rate'=>$config['rate'], 'source'=>'fixed', 'updated_at'=>0, 'fetched_at'=>0];
        $config['rate_mode'] = 'fixed';
        $config['configured_rate_mode'] = $mode;
        $config['rate'] = $config['cny_per_usdt'] = $quote['rate'];
        $config['rate_source'] = $quote['source'];
        $config['rate_updated_at'] = $quote['updated_at'];
        $config['rate_fetched_at'] = $quote['fetched_at'];
        return $config;
    }

    public static function amountToUnits(string $amount, int $scale = 6): string
    {
        if ($scale < 0 || $scale > 6 || !preg_match('/^(0|[1-9][0-9]{0,17})(?:\.([0-9]{1,' . max(1, $scale) . '}))?$/D', $amount, $match)
            || ($scale === 0 && isset($match[2]))) {
            throw new \InvalidArgumentException('金额格式或小数精度无效');
        }
        return ltrim($match[1] . str_pad($match[2] ?? '', $scale, '0'), '0') ?: '0';
    }

    public static function unitsToAmount(string $units, int $scale = 6): string
    {
        if ($scale < 0 || $scale > 6 || !preg_match('/^[0-9]{1,78}$/D', $units)) {
            throw new \InvalidArgumentException('整数金额无效');
        }
        $units = ltrim($units, '0') ?: '0';
        if ($scale === 0) return $units;
        $padded = str_pad($units, $scale + 1, '0', STR_PAD_LEFT);
        return substr($padded, 0, -$scale) . '.' . substr($padded, -$scale);
    }

    /** Return six-decimal token units, rounding UP at the requested cashier scale. */
    public static function cnyToUsdtUnits(string $cny, string $rate, int $scale = 4): string
    {
        self::assertRuntime();
        if ($scale < 4 || $scale > 6) throw new \InvalidArgumentException('USDT 收银台精度无效');
        $cents = self::amountToUnits($cny, 2);
        $rateUnits = self::amountToUnits($rate, 4);
        if (bccomp($cents, '0', 0) <= 0 || bccomp($rateUnits, '0', 0) <= 0) {
            throw new \InvalidArgumentException('应付金额和汇率必须大于零');
        }
        $numerator = bcmul($cents, '1' . str_repeat('0', $scale + 2), 0);
        $ticks = bcdiv($numerator, $rateUnits, 0);
        if (bccomp(bcmod($numerator, $rateUnits), '0', 0) > 0) $ticks = bcadd($ticks, '1', 0);
        return bcmul($ticks, '1' . str_repeat('0', 6 - $scale), 0);
    }

    private static function text(mixed $value, string $label): string
    {
        if (!is_string($value) && !is_int($value)) throw new \InvalidArgumentException($label . '格式无效');
        return trim((string)$value);
    }

    private static function integer(array $config, string $field, int $default, int $minimum, int $maximum): int
    {
        $value = self::text($config[$field] ?? $default, $field);
        if (!preg_match('/^[0-9]{1,6}$/D', $value) || (int)$value < $minimum || (int)$value > $maximum) {
            throw new \InvalidArgumentException($field . '超出允许范围');
        }
        return (int)$value;
    }
}
