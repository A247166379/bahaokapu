<?php
declare(strict_types=1);

namespace App\Util;

use App\Model\Config;

/** Reference prices only. Never used to price goods, create orders or call a gateway. */
final class DisplayCurrency
{
    public const CONFIG_KEY = 'display_currency_settings';
    public const BASE_CODE = 'CNY';
    public const PROVIDER_URL = 'https://open.er-api.com/v6/latest/CNY';
    public const ATTRIBUTION_URL = 'https://www.exchangerate-api.com';
    public const MAX_AGE = 259200;
    private const MAX_BYTES = 65536;
    private const RETRY_COOLDOWN = 600;
    private const MANUAL_COOLDOWN = 60;

    public static function currencies(): array
    {
        return [
            'CNY' => ['code' => 'CNY', 'label' => 'CNY · 人民币', 'name' => '人民币', 'symbol' => '¥', 'decimals' => 2],
            'USD' => ['code' => 'USD', 'label' => 'USD · 美元', 'name' => '美元', 'symbol' => '$', 'decimals' => 2],
            'RUB' => ['code' => 'RUB', 'label' => 'RUB · 俄罗斯卢布', 'name' => '俄罗斯卢布', 'symbol' => '₽', 'decimals' => 2],
            'VND' => ['code' => 'VND', 'label' => 'VND · 越南盾', 'name' => '越南盾', 'symbol' => '₫', 'decimals' => 0],
            'HKD' => ['code' => 'HKD', 'label' => 'HKD · 港币', 'name' => '港币', 'symbol' => 'HK$', 'decimals' => 2],
            'TWD' => ['code' => 'TWD', 'label' => 'TWD · 新台币', 'name' => '新台币', 'symbol' => 'NT$', 'decimals' => 2],
        ];
    }

    public static function normalizeSettings(array $data): array
    {
        if (count($data) !== 2 || !array_key_exists('enabled', $data) || !array_key_exists('adjustments', $data)
            || !is_bool($data['enabled']) || !is_array($data['adjustments'])) {
            throw new \InvalidArgumentException('外币显示设置格式不正确');
        }
        $codes = array_keys(self::currencies());
        if (count($data['adjustments']) !== count($codes) || array_diff($codes, array_keys($data['adjustments']))) {
            throw new \InvalidArgumentException('请为全部支持的币种填写显示调整值');
        }
        $adjustments = [];
        foreach ($codes as $code) {
            $value = $data['adjustments'][$code];
            if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)
                || $value < -20 || $value > 50 || abs($value * 100 - round($value * 100)) > 0.000001) {
                throw new \InvalidArgumentException($code . ' 显示调整须为 -20% 至 50%，最多两位小数');
            }
            if ($code === self::BASE_CODE && $value != 0) {
                throw new \InvalidArgumentException('人民币基准价不能调整');
            }
            $adjustments[$code] = round((float)$value, 2);
        }
        return ['enabled' => $data['enabled'], 'adjustments' => $adjustments];
    }

    public static function settings(): array
    {
        $raw = Config::get(self::CONFIG_KEY);
        $default = ['enabled' => true, 'adjustments' => array_fill_keys(array_keys(self::currencies()), 0.0)];
        try {
            $settings = $raw === '' ? $default : self::normalizeSettings(json_decode($raw, true, 8, JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            // A damaged stored setting must not enable an unexpected conversion.
            $settings = $default;
            $settings['enabled'] = false;
        }
        $settings['revision'] = hash('sha256', $raw);
        return $settings;
    }

    /** Validate one complete provider response before replacing the last good snapshot. */
    public static function normalizeSnapshot(array $data, int $fetchedAt): array
    {
        if (($data['result'] ?? '') !== 'success' || ($data['base_code'] ?? '') !== self::BASE_CODE
            || !is_array($data['rates'] ?? null)) {
            throw new \InvalidArgumentException('汇率服务未返回有效的人民币基准数据');
        }
        $updated = $data['time_last_update_unix'] ?? null;
        $next = $data['time_next_update_unix'] ?? null;
        if (!is_int($updated) || !is_int($next) || $updated <= 0 || $updated > $fetchedAt + 300
            || $next <= $updated || $next > $updated + 259200 || $fetchedAt <= 0) {
            throw new \InvalidArgumentException('汇率服务返回的更新时间无效');
        }
        $rates = [];
        foreach (array_keys(self::currencies()) as $code) {
            $rate = $data['rates'][$code] ?? null;
            if ((!is_int($rate) && !is_float($rate)) || !is_finite((float)$rate) || $rate <= 0 || $rate > 1.0e9) {
                throw new \InvalidArgumentException('汇率服务返回的币种数据不完整');
            }
            $rates[$code] = (float)$rate;
        }
        if (abs($rates[self::BASE_CODE] - 1.0) > 0.000000001) {
            throw new \InvalidArgumentException('汇率服务返回的人民币基准无效');
        }
        $rates[self::BASE_CODE] = 1.0;
        return ['version' => 1, 'base_code' => self::BASE_CODE, 'rates' => $rates,
            'provider_updated_at' => $updated, 'next_update' => $next, 'fetched_at' => $fetchedAt];
    }

    private static function path(string $name): string
    {
        return BASE_PATH . '/runtime/display-currency' . $name;
    }

    private static function readJson(string $path): ?array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) return null;
        try {
            $raw = stream_get_contents($handle, self::MAX_BYTES + 1);
            if ($raw === false || strlen($raw) > self::MAX_BYTES) return null;
            $data = json_decode($raw, true, 12, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : null;
        } catch (\Throwable) {
            return null;
        } finally {
            fclose($handle);
        }
    }

    private static function snapshot(int $now): ?array
    {
        $data = self::readJson(self::path('.json'));
        if (!$data || ($data['version'] ?? 0) !== 1 || !is_int($data['fetched_at'] ?? null)
            || $data['fetched_at'] > $now + 300) return null;
        try {
            return self::normalizeSnapshot(['result' => 'success', 'base_code' => $data['base_code'] ?? '',
                'rates' => $data['rates'] ?? null, 'time_last_update_unix' => $data['provider_updated_at'] ?? null,
                'time_next_update_unix' => $data['next_update'] ?? null], $data['fetched_at']);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Local cache only: a storefront request never waits for the provider. */
    public static function publicVars(?int $now = null): array
    {
        $now ??= time();
        $settings = self::settings();
        $snapshot = self::snapshot($now);
        $status = !$snapshot ? 'missing' : ($now - $snapshot['provider_updated_at'] > self::MAX_AGE
            ? 'expired' : ($now >= $snapshot['next_update'] ? 'stale' : 'fresh'));
        $compatible = Currency::code() === self::BASE_CODE;
        if (!$compatible) $status = 'incompatible_base';
        elseif (!$settings['enabled']) $status = 'disabled';
        $usable = $snapshot && $now - $snapshot['provider_updated_at'] <= self::MAX_AGE;
        return ['enabled' => $settings['enabled'] && $compatible && (bool)$usable,
            'base_code' => self::BASE_CODE, 'rates' => $usable && $compatible ? $snapshot['rates'] : ['CNY' => 1.0],
            'last_update_unix' => $snapshot['provider_updated_at'] ?? 0,
            'next_update_unix' => $snapshot['next_update'] ?? 0, 'fetched_at_unix' => $snapshot['fetched_at'] ?? 0,
            'status' => $status, 'adjustments' => $settings['adjustments'], 'currencies' => self::currencies()];
    }

    public static function adminState(): array
    {
        $public = self::publicVars();
        $state = self::readJson(self::path('-state.json')) ?? [];
        return ['status' => $public['status'], 'display_available' => $public['enabled'], 'site_code' => Currency::code(),
            'last_update_unix' => $public['last_update_unix'], 'next_update_unix' => $public['next_update_unix'],
            'fetched_at_unix' => $public['fetched_at_unix'], 'rates' => $public['rates'],
            'last_attempt_unix' => (int)($state['last_attempt_unix'] ?? 0),
            'last_success_unix' => (int)($state['last_success_unix'] ?? 0),
            'last_result' => in_array($state['last_result'] ?? '', ['updated', 'error'], true) ? $state['last_result'] : '',
            'last_error' => is_string($state['last_error'] ?? null) ? mb_substr($state['last_error'], 0, 200) : '',
            'provider_url' => self::PROVIDER_URL, 'attribution_url' => self::ATTRIBUTION_URL,
            'max_age_hours' => self::MAX_AGE / 3600];
    }

    /** Atomic rename means readers see either complete old JSON or complete new JSON. */
    private static function atomicJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > self::MAX_BYTES) throw new \RuntimeException('汇率缓存大小超出限制');
        $temporary = @tempnam(dirname($path), '.display-currency-');
        if ($temporary === false) throw new \RuntimeException('无法创建汇率缓存');
        try {
            @chmod($temporary, 0600);
            $handle = @fopen($temporary, 'wb');
            if ($handle === false) throw new \RuntimeException('无法写入汇率缓存');
            try {
                $offset = 0;
                while ($offset < strlen($json)) {
                    $written = fwrite($handle, substr($json, $offset));
                    if ($written === false || $written === 0) throw new \RuntimeException('无法写入完整汇率缓存');
                    $offset += $written;
                }
                if (!fflush($handle)) throw new \RuntimeException('无法保存汇率缓存');
            } finally {
                fclose($handle);
            }
            if (!@rename($temporary, $path)) throw new \RuntimeException('无法发布汇率缓存');
        } finally {
            if (is_file($temporary)) @unlink($temporary);
        }
    }

    private static function fetch(int $now): array
    {
        if (!function_exists('curl_init')) throw new \RuntimeException('服务器未启用 cURL');
        $curl = curl_init(self::PROVIDER_URL);
        if ($curl === false) throw new \RuntimeException('无法连接汇率服务');
        $body = '';
        try {
            curl_setopt_array($curl, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROXY => '', CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_USERAGENT => 'ACG-Reference-Display-Currency/1.0',
                CURLOPT_WRITEFUNCTION => static function ($handle, string $part) use (&$body): int {
                    if (strlen($body) + strlen($part) > self::MAX_BYTES) return 0;
                    $body .= $part;
                    return strlen($part);
                }]);
            $ok = curl_exec($curl);
            if ($ok === false || curl_getinfo($curl, CURLINFO_HTTP_CODE) !== 200) {
                throw new \RuntimeException('汇率服务暂不可用，已保留上次有效数据');
            }
        } finally {
            curl_close($curl);
        }
        try {
            $data = json_decode($body, true, 12, JSON_THROW_ON_ERROR);
            if (!is_array($data)) throw new \InvalidArgumentException();
            return self::normalizeSnapshot($data, $now);
        } catch (\Throwable) {
            throw new \RuntimeException('汇率服务数据无效，已保留上次有效数据');
        }
    }

    /** Called by the administrator or the hourly CLI worker, never by publicVars(). */
    public static function refresh(bool $force = false): array
    {
        $now = time();
        $lock = @fopen(self::path('.lock'), 'c');
        if ($lock === false) throw new \RuntimeException('汇率缓存目录不可写');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return ['result' => 'locked', 'message' => '汇率更新正在进行，请稍后刷新状态'];
        }
        try {
            $snapshot = self::snapshot($now);
            $state = self::readJson(self::path('-state.json')) ?? [];
            if (!$force && $snapshot && $now < $snapshot['next_update'] && $now - $snapshot['provider_updated_at'] <= self::MAX_AGE) {
                return ['result' => 'cached', 'message' => '当前汇率尚未到下次更新时间'];
            }
            $cooldown = $force ? self::MANUAL_COOLDOWN : self::RETRY_COOLDOWN;
            if ($now - (int)($state['last_attempt_unix'] ?? 0) < $cooldown) {
                return ['result' => 'cooldown', 'message' => '刚刚已检查汇率，请稍后重试'];
            }
            $state = ['last_attempt_unix' => $now, 'last_success_unix' => (int)($state['last_success_unix'] ?? 0),
                'last_result' => 'error', 'last_error' => '更新未完成，已保留上次有效数据'];
            self::atomicJson(self::path('-state.json'), $state);
            try {
                $fresh = self::fetch($now);
                if ($snapshot && $fresh['provider_updated_at'] < $snapshot['provider_updated_at']) {
                    throw new \RuntimeException('汇率服务返回了较旧数据，已保留上次有效数据');
                }
                self::atomicJson(self::path('.json'), $fresh);
                $state['last_success_unix'] = $now;
                $state['last_result'] = 'updated';
                $state['last_error'] = '';
                self::atomicJson(self::path('-state.json'), $state);
                return ['result' => 'updated', 'message' => '参考汇率已更新'];
            } catch (\Throwable $error) {
                // Only our fixed error text is stored; no response body or credentials enter logs/UI.
                $state['last_error'] = $error instanceof \RuntimeException ? $error->getMessage() : '汇率更新失败，已保留上次有效数据';
                self::atomicJson(self::path('-state.json'), $state);
                return ['result' => 'error', 'message' => $state['last_error']];
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
