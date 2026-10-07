<?php
declare(strict_types=1);
namespace App\Controller\User\Api;
use App\Controller\Base\API\User;
use App\Interceptor\Waf;
use App\Util\UsdtPayment;
use App\Util\Throttle;
use App\Util\Client;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
#[Interceptor(Waf::class)]
class Usdt extends User
{
    public function check(Request $request): array
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') throw new JSONException('请使用 POST 请求');
        $origin=(string)($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin!=='' && parse_url($origin,PHP_URL_HOST)!==parse_url(Client::getUrl(),PHP_URL_HOST)) throw new JSONException('请求来源无效');
        if (isset($_SERVER['HTTP_SEC_FETCH_SITE']) && !in_array($_SERVER['HTTP_SEC_FETCH_SITE'],['same-origin','same-site','none'],true)) throw new JSONException('请求来源无效');
        if (Throttle::tooMany('usdt-check:'.Client::getAddress(),900,600)) throw new JSONException('请求过于频繁，请稍后再试');
        $map=$request->post();
        foreach (['trade_no','poll_token','txid'] as $key) if (isset($map[$key]) && !is_string($map[$key])) throw new JSONException('付款参数无效');
        return $this->json(data: UsdtPayment::check((string)($map['trade_no'] ?? ''),(string)($map['poll_token'] ?? ''),isset($map['txid']) ? trim($map['txid']) : null));
    }
}
