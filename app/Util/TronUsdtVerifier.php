<?php
declare(strict_types=1);

namespace App\Util;

/** Read-only TRON mainnet evidence. It never signs, broadcasts, or settles a payment. */
final class TronUsdtVerifier
{
    public const TRANSFER_TOPIC = 'ddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
    private const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    private array $config;
    private ?\Closure $transport;

    public function __construct(array $config, ?callable $transport = null)
    {
        // Chain evidence is independent from the merchant's pricing policy.
        if (($config['tron_api'] ?? UsdtConfig::API) !== UsdtConfig::API
            || ($config['usdt_contract'] ?? UsdtConfig::CONTRACT) !== UsdtConfig::CONTRACT) {
            throw new \InvalidArgumentException('USDT 网络或链查询地址无效');
        }
        $key = $config['tron_api_key'] ?? '';
        if (!is_string($key) || !preg_match('/^[A-Za-z0-9_-]{0,256}$/D', $key)) {
            throw new \InvalidArgumentException('TronGrid API Key 格式无效');
        }
        $this->config = ['tron_api_key' => $key];
        $this->transport = $transport === null ? null : \Closure::fromCallable($transport);
    }

    public function verify(string $txid, string $receiveAddress, string $expectedUnits, int $createdAt, int $expiresAt, int $confirmSeconds = 60, int $clockSkewSeconds = 30, ?int $now = null): array
    {
        $txid = strtolower(trim($txid));
        if (!preg_match('/^[a-f0-9]{64}$/D', $txid)) throw new \InvalidArgumentException('交易哈希格式不正确');
        $receiveHex = self::addressToHex($receiveAddress);
        $this->assertParameters($expectedUnits, $createdAt, $expiresAt, $confirmSeconds, $clockSkewSeconds);
        $info = $this->request('POST', '/walletsolidity/gettransactioninfobyid', ['value' => $txid]);
        if (!is_string($info['id'] ?? null) || strtolower($info['id']) !== $txid) {
            throw new \RuntimeException('交易尚未取得匹配的已确认链上证据');
        }
        if (($info['receipt']['result'] ?? null) !== 'SUCCESS') throw new \RuntimeException('链上执行未明确成功');
        $milliseconds = $info['blockTimeStamp'] ?? null;
        if (!is_int($milliseconds) || $milliseconds <= 0) throw new \RuntimeException('链上区块时间无效');
        $blockTime = intdiv($milliseconds, 1000);
        $now ??= time();
        if ($blockTime < $createdAt - $clockSkewSeconds || $blockTime > $expiresAt + $clockSkewSeconds) {
            throw new \RuntimeException('交易时间超出该订单付款窗口');
        }
        if ($now - $blockTime < $confirmSeconds) throw new \RuntimeException('交易确认等待尚未完成');
        $logs = $info['log'] ?? null;
        if (!is_array($logs) || count($logs) > 1000) throw new \RuntimeException('链上事件日志无效');
        $contractHex = substr(self::addressToHex(UsdtConfig::CONTRACT), 2);
        $matches = [];
        foreach ($logs as $log) {
            if (!is_array($log) || !is_string($log['address'] ?? null) || !preg_match('/^[a-fA-F0-9]{40}$/D', $log['address'])
                || strtolower($log['address']) !== $contractHex) continue;
            $topics = $log['topics'] ?? null;
            if (!is_array($topics) || count($topics) !== 3 || !isset($topics[0], $topics[1], $topics[2])) continue;
            foreach ($topics as $topic) {
                if (!is_string($topic) || !preg_match('/^[a-fA-F0-9]{64}$/D', $topic)) continue 2;
            }
            if (strtolower($topics[0]) !== self::TRANSFER_TOPIC
                || substr($topics[1], 0, 24) !== str_repeat('0', 24)
                || substr($topics[2], 0, 24) !== str_repeat('0', 24)
                || '41' . strtolower(substr($topics[2], 24)) !== $receiveHex) continue;
            if (!is_string($log['data'] ?? null) || !preg_match('/^[a-fA-F0-9]{64}$/D', $log['data'])) continue;
            $units = self::hexToUnits($log['data']);
            if ($units !== (ltrim($expectedUnits, '0') ?: '0')) continue;
            $matches[] = [
                'txid' => $txid,
                'from_address' => self::hexToAddress('41' . strtolower(substr($topics[1], 24))),
                'to_address' => $receiveAddress,
                'amount' => UsdtConfig::unitsToAmount($units),
                'amount_units' => $units,
                'currency' => 'USDT',
                'token_address' => UsdtConfig::CONTRACT,
                'block_time' => $blockTime,
            ];
        }
        if (count($matches) !== 1) throw new \RuntimeException('未找到唯一、金额精确匹配的 USDT-TRC20 入账事件');
        return $matches[0];
    }

    public function findIncomingPayment(string $receiveAddress, string $expectedUnits, int $createdAt, int $expiresAt, array $excludeTxids = [], int $confirmSeconds = 60, int $clockSkewSeconds = 30, ?int $now = null): array
    {
        $receiveHex = self::addressToHex($receiveAddress);
        $this->assertParameters($expectedUnits, $createdAt, $expiresAt, $confirmSeconds, $clockSkewSeconds);
        $excluded = [];
        foreach ($excludeTxids as $txid) if (is_string($txid)) $excluded[strtolower($txid)] = true;
        $query = [
            'only_to' => 'true', 'only_confirmed' => 'true', 'limit' => 200,
            'order_by' => 'block_timestamp,desc', 'contract_address' => UsdtConfig::CONTRACT,
            'min_timestamp' => max(0, $createdAt - $clockSkewSeconds) * 1000,
            'max_timestamp' => ($expiresAt + $clockSkewSeconds) * 1000,
        ];
        $fingerprints = [];
        $seenTxids = [];
        $verificationAttempts = 0;
        for ($page = 0; $page < 4; $page++) {
            $response = $this->request('GET', '/v1/accounts/' . rawurlencode($receiveAddress) . '/transactions/trc20', $query);
            if (($response['success'] ?? true) !== true || !is_array($response['data'] ?? null) || count($response['data']) > 200) {
                throw new \RuntimeException('TronGrid 账户流水响应无效');
            }
            foreach ($response['data'] as $row) {
                if (!is_array($row)) continue;
                $txid = $row['transaction_id'] ?? null;
                if (!is_string($txid) || !preg_match('/^[a-fA-F0-9]{64}$/D', $txid)) continue;
                $txid = strtolower($txid);
                if (isset($excluded[$txid]) || isset($seenTxids[$txid])) continue;
                $token = $row['token_info'] ?? [];
                if (!is_array($token) || ($token['address'] ?? '') !== UsdtConfig::CONTRACT || (string)($token['decimals'] ?? '') !== '6'
                    || !is_string($row['value'] ?? null) || !preg_match('/^[0-9]{1,78}$/D', $row['value'])
                    || (ltrim($row['value'], '0') ?: '0') !== (ltrim($expectedUnits, '0') ?: '0')) continue;
                try {
                    if (!is_string($row['to'] ?? null) || self::addressToHex($row['to']) !== $receiveHex) continue;
                } catch (\InvalidArgumentException) { continue; }
                $milliseconds = $row['block_timestamp'] ?? null;
                if (!is_int($milliseconds) || $milliseconds < $query['min_timestamp'] || $milliseconds > $query['max_timestamp']) continue;
                $seenTxids[$txid] = true;
                if (++$verificationAttempts > 8) throw new \RuntimeException('候选交易较多，请稍后查询或使用 TXID 核验');
                try {
                    return $this->verify($txid, $receiveAddress, $expectedUnits, $createdAt, $expiresAt, $confirmSeconds, $clockSkewSeconds, $now);
                } catch (\RuntimeException) {
                    // A newer unconfirmed/invalid candidate must not hide a valid older transfer.
                    continue;
                }
            }
            $meta = is_array($response['meta'] ?? null) ? $response['meta'] : [];
            $fingerprint = $meta['fingerprint'] ?? null;
            if (!is_string($fingerprint) || $fingerprint === '') break;
            if (strlen($fingerprint) > 1024 || !preg_match('/^[A-Za-z0-9_.~+=\/-]+$/D', $fingerprint) || isset($fingerprints[$fingerprint])) {
                throw new \RuntimeException('TronGrid 分页标识无效');
            }
            $fingerprints[$fingerprint] = true;
            $query['fingerprint'] = $fingerprint;
        }
        throw new \RuntimeException('暂未找到符合该订单的已确认 USDT-TRC20 入账');
    }

    private function assertParameters(string $units, int $createdAt, int $expiresAt, int $confirmSeconds, int $skew): void
    {
        if (!preg_match('/^[0-9]{1,78}$/D', $units) || (ltrim($units, '0') ?: '0') === '0'
            || $createdAt <= 0 || $expiresAt <= $createdAt || $expiresAt - $createdAt > 3600
            || $confirmSeconds < 60 || $confirmSeconds > 600 || $skew < 0 || $skew > 30) {
            throw new \InvalidArgumentException('链上核验参数无效');
        }
    }

    private function request(string $method, string $path, array $payload): array
    {
        if ($this->transport !== null) {
            $response = ($this->transport)($method, $path, $payload);
            if (!is_array($response)) throw new \RuntimeException('链上查询响应无效');
            return $response;
        }
        if (!extension_loaded('curl')) throw new \RuntimeException('USDT 链上查询需要 cURL 扩展');
        $url = UsdtConfig::API . $path . ($method === 'GET' ? '?' . http_build_query($payload) : '');
        $headers = ['Accept: application/json'];
        if ($this->config['tron_api_key'] !== '') $headers[] = 'TRON-PRO-API-KEY: ' . $this->config['tron_api_key'];
        $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS];
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        $curl = curl_init($url);
        if ($curl === false) throw new \RuntimeException('无法初始化链上查询');
        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        $http = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($body === false || $http !== 200 || strlen($body) > 2097152) {
            throw new \RuntimeException('链上查询暂不可用，请稍后重试');
        }
        try { $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new \RuntimeException('链上查询响应格式无效'); }
        if (!is_array($decoded)) throw new \RuntimeException('链上查询响应无效');
        return $decoded;
    }

    public static function addressToHex(string $address): string
    {
        if (!preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/D', $address)) throw new \InvalidArgumentException('TRON 主网收款地址格式无效');
        $bytes = [0];
        for ($i = 0; $i < strlen($address); $i++) {
            $carry = strpos(self::ALPHABET, $address[$i]);
            if ($carry === false) throw new \InvalidArgumentException('TRON 地址格式无效');
            foreach ($bytes as &$byte) { $carry += $byte * 58; $byte = $carry & 255; $carry >>= 8; }
            unset($byte);
            while ($carry > 0) { $bytes[] = $carry & 255; $carry >>= 8; }
        }
        $binary = implode('', array_map('chr', array_reverse($bytes)));
        if (strlen($binary) !== 25) throw new \InvalidArgumentException('TRON 地址长度无效');
        $body = substr($binary, 0, 21);
        $checksum = substr(hash('sha256', hash('sha256', $body, true), true), 0, 4);
        if (ord($body[0]) !== 0x41 || !hash_equals($checksum, substr($binary, 21))) {
            throw new \InvalidArgumentException('TRON 地址校验失败');
        }
        return bin2hex($body);
    }

    public static function hexToAddress(string $hex): string
    {
        if (!preg_match('/^41[a-fA-F0-9]{40}$/D', $hex)) throw new \InvalidArgumentException('TRON 地址编码无效');
        $body = hex2bin($hex);
        $input = $body . substr(hash('sha256', hash('sha256', $body, true), true), 0, 4);
        $digits = [0];
        for ($i = 0; $i < strlen($input); $i++) {
            $carry = ord($input[$i]);
            foreach ($digits as &$digit) { $carry += $digit << 8; $digit = $carry % 58; $carry = intdiv($carry, 58); }
            unset($digit);
            while ($carry > 0) { $digits[] = $carry % 58; $carry = intdiv($carry, 58); }
        }
        return implode('', array_map(static fn(int $digit): string => self::ALPHABET[$digit], array_reverse($digits)));
    }

    private static function hexToUnits(string $hex): string
    {
        UsdtConfig::assertRuntime();
        $number = '0';
        foreach (str_split(strtolower($hex)) as $digit) $number = bcadd(bcmul($number, '16', 0), (string)hexdec($digit), 0);
        return $number;
    }
}
