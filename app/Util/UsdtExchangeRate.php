<?php
declare(strict_types=1);

namespace App\Util;

/** One fixed keyless USDT/CNY source. No wallet, API credentials or payment configuration is used. */
final class UsdtExchangeRate
{
    public const SOURCE = 'CoinGecko';
    public const URL = 'https://api.coingecko.com/api/v3/simple/price?ids=tether&vs_currencies=cny&include_last_updated_at=true&precision=4';
    public const CACHE_SECONDS = 60;
    public const MAX_AGE_SECONDS = 300;
    private const MAX_BYTES = 65536;
    private const FAILURE_COOLDOWN = 30;

    /** Optional transport/time/directory are internal test seams, never request parameters. */
    public static function quote(?callable $transport = null, ?int $now = null, ?string $directory = null): array
    {
        $testNow = $now;
        $now ??= time();
        $path = self::path($directory);
        $snapshot = self::snapshot($path, $now);
        if ($snapshot && $now - $snapshot['fetched_at'] < self::CACHE_SECONDS) return self::result($snapshot, true);
        $lock = @fopen($path . '.lock', 'c');
        if ($lock === false) throw new \RuntimeException('实时汇率缓存不可用，请联系管理员');
        @chmod($path . '.lock', 0600);
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            if ($snapshot) return self::result($snapshot, true);
            throw new \RuntimeException('汇率正在更新，请稍后重试');
        }
        try {
            // Recheck after acquiring the shared refresh lock; another request may have published first.
            $snapshot = self::snapshot($path, $now);
            if ($snapshot && $now - $snapshot['fetched_at'] < self::CACHE_SECONDS) return self::result($snapshot, true);
            $state = self::readJson($path . '-state.json') ?? [];
            $retryAfter = $state['retry_after'] ?? 0;
            if (is_int($retryAfter) && $retryAfter > $now && $retryAfter <= $now + self::MAX_AGE_SECONDS) {
                if ($snapshot) return self::result($snapshot, true);
                throw new \RuntimeException('刚刚获取汇率失败，请稍后重试');
            }
            $failures = is_int($state['failure_count'] ?? null) ? max(0, min(4, $state['failure_count'])) : 0;
            $state = ['last_attempt'=>$now, 'failure_count'=>$failures + 1,
                'retry_after'=>$now + min(self::MAX_AGE_SECONDS, self::FAILURE_COOLDOWN * (2 ** $failures))];
            // Save the cooldown before fetching, including crashes or aborted requests.
            self::atomicJson($path . '-state.json', $state);
            try {
                $fresh = self::fetch($transport, $testNow);
                if ($snapshot && $fresh['updated_at'] < $snapshot['updated_at']) {
                    throw new \RuntimeException('实时汇率数据无效或已过期，请稍后重试');
                }
                self::atomicJson($path, $fresh);
                self::atomicJson($path . '-state.json', ['last_attempt'=>$now, 'failure_count'=>0, 'retry_after'=>0]);
                return self::result($fresh, false);
            } catch (\Throwable $e) {
                // A failed refresh can use only a still-valid prior snapshot, never the profile's fixed rate.
                $snapshot = self::snapshot($path, $testNow ?? time());
                if ($snapshot) return self::result($snapshot, true);
                if ($e instanceof \RuntimeException && in_array($e->getMessage(), [
                    '汇率获取失败或超时，请稍后重试', '实时汇率服务请求受限，请稍后重试',
                    '实时汇率服务暂不可用，请稍后重试', '实时汇率数据无效或已过期，请稍后重试',
                    '实时汇率缓存不可用，请联系管理员',
                ], true)) throw $e;
                throw new \RuntimeException('汇率获取失败或超时，请稍后重试');
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Local read only: safe to call while the order transaction holds locks. Never fetches or writes. */
    public static function cachedQuote(?int $now = null, ?string $directory = null): array
    {
        $snapshot = self::snapshot(self::path($directory), $now ?? time());
        if (!$snapshot) throw new \RuntimeException('实时汇率暂不可用，请先获取当前汇率后重试');
        return self::result($snapshot, true);
    }

    private static function path(?string $directory): string
    {
        return rtrim($directory ?? BASE_PATH . '/runtime', '/') . '/usdt-exchange-rate.json';
    }

    private static function readJson(string $path): ?array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) return null;
        try {
            $raw = stream_get_contents($handle, self::MAX_BYTES + 1);
            if (!is_string($raw) || strlen($raw) > self::MAX_BYTES) return null;
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) { return null; }
        finally { fclose($handle); }
    }

    private static function validRate(string $rate): bool
    {
        return preg_match('/^(?:(?:[4-9]|1[01])\.[0-9]{4}|12\.0000)$/D', $rate) === 1;
    }

    private static function snapshot(string $path, int $now): ?array
    {
        $data = self::readJson($path);
        if (!$data || ($data['version'] ?? null) !== 1 || ($data['source'] ?? null) !== self::SOURCE
            || !is_string($data['rate'] ?? null) || !self::validRate($data['rate'])
            || !is_int($data['updated_at'] ?? null) || !is_int($data['fetched_at'] ?? null)
            || $data['updated_at'] <= 0 || $data['fetched_at'] < $data['updated_at']
            || $data['fetched_at'] > $now || $now - $data['updated_at'] > self::MAX_AGE_SECONDS) return null;
        return ['version'=>1, 'rate'=>$data['rate'], 'source'=>self::SOURCE,
            'updated_at'=>$data['updated_at'], 'fetched_at'=>$data['fetched_at']];
    }

    private static function fetch(?callable $transport, ?int $now): array
    {
        $request = ['method'=>'GET', 'url'=>self::URL, 'headers'=>['Accept: application/json'],
            'connect_timeout'=>3, 'timeout'=>8, 'verify_peer'=>true, 'verify_host'=>2,
            'follow_redirects'=>false, 'max_response_bytes'=>self::MAX_BYTES];
        try { $response = $transport ? $transport($request) : self::request($request); }
        catch (\Throwable) { throw new \RuntimeException('汇率获取失败或超时，请稍后重试'); }
        if (!is_array($response) || !is_int($response['http_status'] ?? null) || !is_string($response['body'] ?? null)
            || !empty($response['too_large']) || strlen($response['body']) > self::MAX_BYTES) {
            throw new \RuntimeException('实时汇率数据无效或已过期，请稍后重试');
        }
        if (!empty($response['transport_error']) || $response['http_status'] === 0) throw new \RuntimeException('汇率获取失败或超时，请稍后重试');
        if ($response['http_status'] === 429) throw new \RuntimeException('实时汇率服务请求受限，请稍后重试');
        if ($response['http_status'] !== 200) throw new \RuntimeException('实时汇率服务暂不可用，请稍后重试');
        try { $data = json_decode($response['body'], true, 8, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new \RuntimeException('实时汇率数据无效或已过期，请稍后重试'); }
        $fetchedAt = $now ?? time();
        $rate = $data['tether']['cny'] ?? null;
        $updatedAt = $data['tether']['last_updated_at'] ?? null;
        if ((!is_int($rate) && !is_float($rate)) || !is_finite((float)$rate) || $rate < 4 || $rate > 12
            || !is_int($updatedAt) || $updatedAt <= 0 || $updatedAt > $fetchedAt
            || $fetchedAt - $updatedAt > self::MAX_AGE_SECONDS) {
            throw new \RuntimeException('实时汇率数据无效或已过期，请稍后重试');
        }
        // Provider precision is fixed to four places; all subsequent payment arithmetic uses decimal strings.
        $rate = sprintf('%.4F', $rate);
        if (!self::validRate($rate)) throw new \RuntimeException('实时汇率数据无效或已过期，请稍后重试');
        return ['version'=>1, 'rate'=>$rate, 'source'=>self::SOURCE, 'updated_at'=>$updatedAt, 'fetched_at'=>$fetchedAt];
    }

    private static function request(array $request): array
    {
        if (!extension_loaded('curl')) throw new \RuntimeException('cURL unavailable');
        $curl = curl_init($request['url']);
        if ($curl === false) throw new \RuntimeException('cURL initialization failed');
        $body = ''; $tooLarge = false;
        try {
            curl_setopt_array($curl, [CURLOPT_HTTPGET=>true, CURLOPT_HEADER=>false, CURLOPT_RETURNTRANSFER=>false,
                CURLOPT_HTTPHEADER=>$request['headers'], CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_TIMEOUT=>8,
                CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_MAXREDIRS=>0, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_PROXY=>'', CURLOPT_USERAGENT=>'ACG-USDT-Exchange-Rate/1.0',
                CURLOPT_WRITEFUNCTION=>static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                    if (strlen($body) + strlen($chunk) > self::MAX_BYTES) { $tooLarge = true; return 0; }
                    $body .= $chunk; return strlen($chunk);
                }]);
            $finished = curl_exec($curl);
            return ['http_status'=>(int)curl_getinfo($curl, CURLINFO_HTTP_CODE), 'body'=>$body,
                'too_large'=>$tooLarge, 'transport_error'=>$finished === false || curl_errno($curl) !== 0];
        } finally { curl_close($curl); }
    }

    private static function atomicJson(string $path, array $data): void
    {
        $temporary = @tempnam(dirname($path), '.usdt-exchange-rate-');
        if ($temporary === false) throw new \RuntimeException('实时汇率缓存不可用，请联系管理员');
        try {
            @chmod($temporary, 0600);
            $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $handle = @fopen($temporary, 'wb');
            if ($handle === false) throw new \RuntimeException('实时汇率缓存不可用，请联系管理员');
            try {
                $offset = 0;
                while ($offset < strlen($json)) {
                    $written = fwrite($handle, substr($json, $offset));
                    if ($written === false || $written === 0) throw new \RuntimeException('实时汇率缓存不可用，请联系管理员');
                    $offset += $written;
                }
                if (!fflush($handle)) throw new \RuntimeException('实时汇率缓存不可用，请联系管理员');
            } finally { fclose($handle); }
            if (!@rename($temporary, $path)) throw new \RuntimeException('实时汇率缓存不可用，请联系管理员');
        } finally { if (is_file($temporary)) @unlink($temporary); }
    }

    private static function result(array $snapshot, bool $cached): array
    {
        return ['rate'=>$snapshot['rate'], 'source'=>self::SOURCE, 'updated_at'=>$snapshot['updated_at'],
            'fetched_at'=>$snapshot['fetched_at'], 'cached'=>$cached];
    }
}
