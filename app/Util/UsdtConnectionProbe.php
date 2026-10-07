<?php
declare(strict_types=1);

namespace App\Util;

/** One bounded read-only request. This never saves settings or creates a transaction. */
final class UsdtConnectionProbe
{
    private const MAX_RESPONSE_BYTES = 65536;
    private ?\Closure $transport;

    /** The optional transport exists only for isolated tests; administrators cannot supply it. */
    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport === null ? null : \Closure::fromCallable($transport);
    }

    public function run(mixed $receiveAddress, mixed $apiKey = ''): array
    {
        $address = $this->text($receiveAddress, '收款地址');
        TronUsdtVerifier::addressToHex($address);
        $key = $this->text($apiKey, 'TronGrid API Key');
        if (!preg_match('/^[A-Za-z0-9_-]{0,256}$/D', $key)) throw new \InvalidArgumentException('TronGrid API Key 格式无效');
        $started = hrtime(true);
        $request = [
            'method'=>'GET',
            'url'=>UsdtConfig::API . '/v1/accounts/' . rawurlencode($address) . '/transactions/trc20?' . http_build_query([
                'limit'=>1, 'only_confirmed'=>'true', 'only_to'=>'true', 'contract_address'=>UsdtConfig::CONTRACT,
            ], '', '&', PHP_QUERY_RFC3986),
            'headers'=>['Accept: application/json'],
            'connect_timeout'=>5, 'timeout'=>12, 'verify_peer'=>true, 'verify_host'=>2,
            'follow_redirects'=>false, 'max_response_bytes'=>self::MAX_RESPONSE_BYTES,
        ];
        if ($key !== '') $request['headers'][] = 'TRON-PRO-API-KEY: ' . $key;
        try {
            $response = $this->transport ? ($this->transport)($request) : $this->request($request);
        } catch (\Throwable) {
            // cURL/provider errors can include credentials. Never expose their text.
            return $this->result('network_error', false, null, $started, $key !== '');
        }
        if (!is_array($response) || !is_int($response['http_status'] ?? null) || !is_string($response['body'] ?? null)) {
            return $this->result('invalid_response', false, null, $started, $key !== '');
        }
        $http = $response['http_status'];
        $network = $http >= 100 && $http <= 599;
        if (!empty($response['too_large']) || strlen($response['body']) > self::MAX_RESPONSE_BYTES) {
            return $this->result('invalid_response', $network, null, $started, $key !== '');
        }
        if (!empty($response['transport_error']) || !$network) {
            return $this->result('network_error', false, null, $started, $key !== '');
        }
        if ($http === 429) return $this->result('rate_limited', true, null, $started, $key !== '');
        $body = null;
        try { $body = json_decode($response['body'], false, 16, JSON_THROW_ON_ERROR); } catch (\JsonException) {}
        // Error metadata alone is inspected; transaction history is neither copied nor returned.
        $errorText = '';
        if ($body instanceof \stdClass) {
            foreach (['error', 'Error', 'message', 'msg'] as $field) {
                if (is_string($body->$field ?? null)) $errorText .= ' ' . $body->$field;
            }
        } elseif ($http !== 200) {
            $errorText = $response['body'];
        }
        if (preg_match('/rate[\s_-]*limit|\btoo\s+many\b|\bfrequency\b|\bquota\b|限流|请求频率|配额/i', $errorText)) {
            return $this->result('rate_limited', true, null, $started, $key !== '');
        }
        if ($http === 401 || $http === 403) {
            return $this->result('auth_failed', true, $key !== '' ? false : null, $started, $key !== '');
        }
        if ($http !== 200) return $this->result('upstream_error', true, null, $started, $key !== '');
        if (!$body instanceof \stdClass || !is_bool($body->success ?? null) || !is_array($body->data ?? null)) {
            return $this->result('invalid_response', true, null, $started, $key !== '');
        }
        if ($body->success !== true) return $this->result('upstream_error', true, null, $started, $key !== '');
        return $this->result($key !== '' ? 'success' : 'public_access', true, $key !== '' ? true : null, $started, $key !== '');
    }

    private function text(mixed $value, string $label): string
    {
        if (!is_string($value) && !is_int($value)) throw new \InvalidArgumentException($label . '格式无效');
        return trim((string)$value);
    }

    private function request(array $request): array
    {
        if (!extension_loaded('curl')) throw new \RuntimeException('Read-only probe requires cURL');
        $body = '';
        $tooLarge = false;
        $curl = curl_init($request['url']);
        if ($curl === false) throw new \RuntimeException('Read-only probe initialization failed');
        try {
            curl_setopt_array($curl, [
                CURLOPT_HTTPGET=>true, CURLOPT_RETURNTRANSFER=>false, CURLOPT_HEADER=>false,
                CURLOPT_HTTPHEADER=>$request['headers'], CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>12,
                CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_MAXREDIRS=>0, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_WRITEFUNCTION=>static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                    if (strlen($body) + strlen($chunk) > self::MAX_RESPONSE_BYTES) { $tooLarge = true; return 0; }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $finished = curl_exec($curl);
            return ['http_status'=>(int)curl_getinfo($curl, CURLINFO_HTTP_CODE), 'body'=>$body,
                'too_large'=>$tooLarge, 'transport_error'=>$finished === false || curl_errno($curl) !== 0];
        } finally { curl_close($curl); }
    }

    private function result(string $status, bool $network, ?bool $apiKey, int $started, bool $keyProvided): array
    {
        $message = match ($status) {
            'success'=>'地址格式有效，TronGrid 已接受本次带 API Key 的只读查询；此检测不代表收款或到账确认。',
            'public_access'=>'地址格式有效，TronGrid 公共只读查询可访问；未填写 API Key，因此未验证 Key。',
            'auth_failed'=>$keyProvided
                ? 'TronGrid 拒绝了本次访问，请核对 API Key 权限、安全限制或额度；不能仅凭此响应判定 Key 错误。'
                : 'TronGrid 拒绝公共访问，请填写 API Key 后再检测，并核对访问权限、安全限制或额度。',
            'rate_limited'=>'TronGrid 请求受到限流或服务额度限制，请稍后再试并检查配额。',
            'network_error'=>'TronGrid 连接失败或超时，请检查服务器网络和 HTTPS 连接后重试。',
            'upstream_error'=>'TronGrid 服务暂不可用或未接受本次只读查询，请稍后重试。',
            default=>'TronGrid 响应格式异常，无法确认只读连接，请稍后重试。',
        };
        return ['ok'=>in_array($status, ['success', 'public_access'], true), 'status'=>$status,
            'checks'=>['address'=>true, 'network'=>$network, 'api_key'=>$apiKey],
            'elapsed_ms'=>max(0, (int)round((hrtime(true) - $started) / 1000000)), 'message'=>$message];
    }
}
