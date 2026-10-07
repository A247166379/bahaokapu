<?php
declare(strict_types=1);
namespace App\Util;

use App\Model\Config;
use App\Model\Pay;
use Kernel\Exception\JSONException;

/** Buyer methods are stable; plugin routes and credentials stay private. */
final class PaymentMethods
{
    public const CODES = ['alipay', 'wxpay', 'usdt'];
    private const LABELS = [
        'zh-cn' => ['alipay'=>'支付宝', 'wxpay'=>'微信支付', 'usdt'=>'USDT', 'unavailable'=>'暂不可用'],
        'zh-tw' => ['alipay'=>'支付寶', 'wxpay'=>'微信支付', 'usdt'=>'USDT', 'unavailable'=>'暫不可用'],
        'en' => ['alipay'=>'Alipay', 'wxpay'=>'WeChat Pay', 'usdt'=>'USDT', 'unavailable'=>'Currently unavailable'],
        'ru' => ['alipay'=>'Alipay', 'wxpay'=>'WeChat Pay', 'usdt'=>'USDT', 'unavailable'=>'Временно недоступно'],
        'vi' => ['alipay'=>'Alipay', 'wxpay'=>'WeChat Pay', 'usdt'=>'USDT', 'unavailable'=>'Tạm thời không khả dụng'],
    ];
    public static function label(string $code, ?string $locale=null): string
    {
        $labels = self::LABELS[$locale ?? StoreLocale::get()] ?? self::LABELS['zh-cn'];
        return $labels[$code] ?? $code;
    }
    public static function icon(string $code): string
    {
        return '/assets/common/images/payment-' . $code . '.svg';
    }
    public static function infer(array $route): string
    {
        $explicit = (string)($route['buyer_method'] ?? '');
        if ($explicit !== '') return in_array($explicit,self::CODES,true) ? $explicit : '';
        $code = strtolower((string)($route['code'] ?? ''));
        if (in_array($code,self::CODES,true)) return $code;
        return match ((string)($route['handle'] ?? '')) { 'Alipay', 'AlipayPersonalPay'=>'alipay', 'UsdtTrc20'=>'usdt', default=>'' };
    }
    public static function enabled(string $method): bool
    {
        if (!in_array($method,self::CODES,true)) return false;
        $value = Config::get('payment_method_' . $method);
        return $value === null || $value === '' || (string)$value === '1';
    }
    public static function bindingKey(string $method): string
    {
        if (!in_array($method, self::CODES, true)) throw new JSONException('支付方式格式不正确');
        return 'payment_method_binding_' . $method;
    }
    public static function boundId(string $method): int
    {
        $value = (string)Config::get(self::bindingKey($method));
        return preg_match('/^[1-9]\d*$/D', $value) && (float)$value <= 4294967295 ? (int)$value : 0;
    }
    /** Only declared plugin products are offered by the simplified binding form. */
    public static function pluginMethodCodes(string $handle, array $info): array
    {
        $out = [];
        foreach (is_array($info['options'] ?? null) ? $info['options'] : [] as $code => $label) {
            $code = (string)$code;
            if ($code === '' || strlen($code) > 32 || preg_match('/[\x00-\x1F\x7F<>"\']/u', $code)) continue;
            $method = self::infer(['handle' => $handle, 'code' => $code]);
            if ($method !== '') $out[$method][] = $code;
        }
        return $out;
    }
    /** Legacy rows may have an empty buyer_method; their plugin/product still identifies the method. */
    public static function bindingRow(string $method, array $rows, ?int $boundId = null): ?array
    {
        $boundId ??= self::boundId($method);
        $rows = array_values(array_filter($rows, static fn(array $row): bool => self::infer($row) === $method
            && (string)($row['handle'] ?? '') !== '#system' && (int)($row['id'] ?? 0) > 1 && (int)($row['archived'] ?? 0) === 0));
        if ($boundId > 0) {
            foreach ($rows as $row) if ((int)$row['id'] === $boundId) return $row;
            return null; // An invalid explicit binding must never select a different merchant/plugin.
        }
        usort($rows, static fn(array $a, array $b): int => [(int)($a['sort'] ?? 0), (int)$a['id']] <=> [(int)($b['sort'] ?? 0), (int)$b['id']]);
        foreach ($rows as $row) if (self::routeAllowed($row, self::device()) && self::readiness($row)['ready']) return $row;
        foreach ($rows as $row) if ((int)($row['commodity'] ?? 0) === 1 && self::readiness($row)['ready']) return $row;
        foreach ($rows as $row) if ((int)($row['commodity'] ?? 0) === 1) return $row;
        return $rows[0] ?? null;
    }
    /** Configuration-only readiness. This never calls an upstream provider. */
    public static function readiness(array $route, ?array $config=null): array
    {
        $fail = static fn(string $why): array => ['ready'=>false,'message'=>$why];
        $handle = (string)($route['handle'] ?? '');
        if (LocalExtensionPolicy::installedDirectory($handle,1) === null) return $fail('支付插件尚未安装');
        $method = self::infer($route);
        if ($method === '') return $fail('请选择客户支付方式');
        $config ??= PayProfile::raw($handle,(int)($route['pay_config_id'] ?? 0));
        if ($config === null) return $fail('尚未选择有效支付配置');
        if ($handle === 'UsdtTrc20') {
            if ($method !== 'usdt' || (string)($route['code'] ?? '') !== 'usdt') return $fail('USDT-TRC20 路由须对应 USDT');
            try { UsdtConfig::normalize($config); } catch (\Throwable $e) { return $fail($e->getMessage()); }
            return ['ready'=>true,'message'=>'收款参数已配置；真实到账仍需链上确认'];
        }
        if ($handle === 'Epay') {
            if ((string)($route['code'] ?? '') !== $method) return $fail('易支付网关通道编码须与买家支付方式一致');
            $version=(string)($config['version'] ?? '');
            $required=$version==='1' ? ['url','pid','private_key','platform_public_key'] : ['url','pid','key'];
            if (!in_array($version,['0','1'],true)) return $fail('请配置易支付协议版本');
            foreach ($required as $field) if (trim((string)($config[$field] ?? ''))==='') return $fail('请补齐易支付网关与商户凭据');
            $url=parse_url((string)$config['url']);
            if (!$url || !in_array(strtolower($url['scheme'] ?? ''),['http','https'],true) || empty($url['host'])) return $fail('支付网关地址无效');
        } else {
            if (in_array($handle,['Alipay','AlipayPersonalPay'],true) && $method!=='alipay') return $fail('支付宝插件须对应支付宝支付方式');
            $schemaPath=BASE_PATH.'/app/Pay/'.$handle.'/Config/Submit.php';
            $schema=is_file($schemaPath) ? require $schemaPath : [];
            foreach (is_array($schema) ? $schema : [] as $field) {
                if (is_array($field) && !empty($field['required']) && trim((string)($config[$field['name'] ?? ''] ?? ''))==='') return $fail('请补齐支付插件必填配置');
            }
            if ($config === [] || !array_filter($config,static fn($v)=>is_scalar($v) && trim((string)$v)!=='')) return $fail('尚未填写支付配置');
        }
        return ['ready'=>true,'message'=>'参数已配置；请确认支付平台已开通该通道'];
    }
    public static function device(): int
    {
        return Client::isWeChat() ? 3 : (Client::isMobile() ? 1 : 2);
    }
    public static function routeAllowed(array $route, int $device): bool
    {
        return (int)($route['commodity'] ?? 0)===1 && (int)($route['archived'] ?? 0)===0
            && (string)($route['handle'] ?? '')!=='#system'
            && in_array((int)($route['equipment'] ?? 0),[0,$device],true);
    }
    /** The lowest configured priority is selected; no unqualified fallback. */
    public static function candidates(string $method, int $device): array
    {
        if (!self::enabled($method)) return [];
        $boundId = self::boundId($method);
        $found=[];
        foreach (Pay::query()->where('commodity',1)->where('archived',0)->orderBy('sort')->orderBy('id')->get() as $pay) {
            $row=$pay->toArray();
            if ($boundId > 0 && (int)$pay->id !== $boundId) continue;
            if (self::infer($row)===$method && self::routeAllowed($row,$device) && self::readiness($row)['ready']) $found[]=$pay;
        }
        return $found;
    }
    public static function resolve(string $method, int $requestedId=0, ?int $device=null): Pay
    {
        if (!in_array($method,self::CODES,true)) throw new JSONException('支付方式格式不正确');
        $routes=self::candidates($method,$device ?? self::device());
        if ($routes===[]) throw new JSONException('该支付方式暂不可用，请选择其他支付方式');
        if ($requestedId>0 && (int)$routes[0]->id!==$requestedId) throw new JSONException('支付方式已更新，请重新选择');
        return $routes[0];
    }
    public static function assertRoute(Pay $pay, ?string $method=null, ?int $device=null): void
    {
        $row=$pay->toArray(); $actual=self::infer($row);
        $boundId = $actual !== '' ? self::boundId($actual) : 0;
        if ($actual==='' || ($method!==null && $method!=='' && $method!==$actual) || !self::enabled($actual)
            || ($boundId > 0 && (int)$pay->id !== $boundId)
            || !self::routeAllowed($row,$device ?? self::device()) || !self::readiness($row)['ready']) {
            throw new JSONException('该支付方式暂不可用，请重新选择');
        }
    }
    public static function publicList(): array
    {
        $out=[];
        foreach (self::CODES as $code) {
            if (!self::enabled($code)) continue;
            $routes=self::candidates($code,self::device());
            $out[]=['id'=>(int)($routes[0]->id ?? 0),'method'=>$code,'name'=>self::label($code),'icon'=>self::icon($code),
                'available'=>$routes!==[],'unavailable_text'=>self::label('unavailable')];
        }
        return $out;
    }
    public static function overview(): array
    {
        $rows=Pay::query()->where('handle','!=','#system')->where('archived',0)->get()->toArray(); $methods=[];
        foreach (self::CODES as $code) {
            $all=array_values(array_filter($rows,static fn($r)=>self::infer($r)===$code));
            $ready=array_filter($all,static fn($r)=>(int)$r['commodity']===1 && self::readiness($r)['ready']);
            $binding = self::bindingRow($code, $all);
            $bindingStatus = $binding ? self::readiness($binding) : ['ready'=>false,'message'=>'请选择支付插件'];
            $bindingData = $binding ? [
                'id'=>(int)$binding['id'], 'handle'=>(string)$binding['handle'],
                'pay_config_id'=>(int)$binding['pay_config_id'], 'code'=>(string)$binding['code'],
                'ready'=>$bindingStatus['ready'] && (int)($binding['commodity'] ?? 0) === 1,
                'readiness_message'=>(int)($binding['commodity'] ?? 0) === 1 ? $bindingStatus['message'] : '该支付方式的商品支付已停用',
            ] : null;
            if ($bindingData) {
                foreach (PayProfile::list($bindingData['handle']) as $profile) {
                    if ((int)$profile['id'] === $bindingData['pay_config_id']) $bindingData['config_name'] = (string)$profile['name'];
                }
            }
            $methods[]=['code'=>$code,'name'=>self::label($code,'zh-cn'),'enabled'=>self::enabled($code),
                'route_count'=>count($all),'ready_count'=>count($ready),'binding'=>$bindingData,
                'binding_id'=>self::boundId($code) ?: (int)($binding['id'] ?? 0)];
        }
        return ['methods'=>$methods];
    }
}
