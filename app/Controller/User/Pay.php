<?php
declare(strict_types=1);

namespace App\Controller\User;


use App\Controller\Base\View\User;
use App\Model\Config;
use App\Interceptor\Waf;
use App\Model\Order;
use App\Model\OrderOption;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Exception\ViewException;
use Kernel\Util\View;
use App\Util\PayConfig;
use App\Util\CustomerServiceWidget;

#[Interceptor(Waf::class)]
class Pay extends User
{
    /**
     * @return string
     * @throws JSONException
     * @throws ViewException
     * @throws \SmartyException
     */
    public function order(): string
    {
        // Error text uses the visitor's explicit display language even when no order exists.
        $requestedLocale = is_string($_GET['lang'] ?? null) ? $_GET['lang'] : '';
        if (in_array($requestedLocale, \App\Util\StoreLocale::CODES, true)) {
            \App\Util\StoreLocale::useLocale($requestedLocale);
        }
        if (!isset($_GET['_PARAMETER'][0]) || !isset($_GET['_PARAMETER'][1])) {
            return lang('订单不存在');
        }

        $tradeNo = $_GET['_PARAMETER'][0];
        $type = (int)$_GET['_PARAMETER'][1];
        //获取订单信息
        $order = Order::with(['pay'])->where("trade_no", $tradeNo)->first();
        if (!$order) {
            return lang('订单不存在');
        }

        $locale = \App\Util\OrderLocale::normalize($order->display_locale);
        if (in_array($requestedLocale, \App\Util\StoreLocale::CODES, true)) $locale = $requestedLocale;
        if (preg_match('#^/(zh-tw|en|ru|vi)(?:/|$)#', (string)($_SERVER['REQUEST_URI'] ?? ''), $matches)) $locale = $matches[1];
        \App\Util\StoreLocale::useLocale($locale);
        if ((int)$order->status === 1) {
            header('Location: ' . \App\Util\StoreLocale::url('/order/' . $order->trade_no, $locale));
            return '';
        }
        if (!$order->pay) {
            return lang('支付方式不存在');
        }

        $data = OrderOption::get($order->id);
        if ((string)$order->pay->handle === 'UsdtTrc20') {
            \App\Util\UsdtPayment::authorize((string)$order->trade_no, is_string($_GET['token'] ?? null) ? $_GET['token'] : '');
            header('Referrer-Policy: no-referrer');
            header('Cache-Control: no-store');
        }

        if ($type == 2) {
            if (!$data) {
                throw new JSONException("参数错误");
            }
            $policy = \App\Util\Csp::paymentFormPolicy((string)$order->pay_url);
            if ($policy === null) throw new JSONException('支付地址无效，请联系客服');
            if (\App\Util\Csp::enabled()) header(\App\Util\Csp::header() . ': ' . $policy);
            return $this->render(lang("正在跳转支付，请稍候"), "Submit.html", [
                "displayLocale" => $locale,
                "url" => $order->pay_url,
                "data" => $data
            ]);
        }

        //路径安全在 renderTemplate 里把关：code 是站长可填的值，不能直接拼进文件路径
        $html = PayConfig::renderTemplate((string)$order->pay->handle, (string)$order->pay->code);

        if ($html === null) {
            throw new JSONException("视图不存在");
        }

        $appearance = Config::get('store_default_appearance');
        if (!in_array($appearance, ['light', 'gray', 'dark', 'system'], true)) $appearance = 'system';
        $result = View::render($html, ['order' => $order, 'option' => $data, 'displayLocale' => $locale, 'storeAppearance' => $appearance], BASE_PATH . '/app/Pay/');
        return CustomerServiceWidget::inject($result, Config::list());
    }
}
