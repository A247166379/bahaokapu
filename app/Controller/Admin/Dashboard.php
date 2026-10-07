<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Base\View\Manage;
use App\Interceptor\ManageSession;
use App\Model\Card;
use App\Model\Commodity;
use App\Model\Order;
use App\Service\Currency;
use App\Util\Date;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;

#[Interceptor(ManageSession::class)]
class Dashboard extends Manage
{
    #[Inject]
    private Currency $currency;

    public function index(): string
    {
        // Only read this store's inventory and purchase records. Existing
        // business records remain intact; this page exposes no wallet modules.
        $paid = static fn() => Order::query()->where('user_id', 0)
            ->where('substation_user_id', 0)->where('status', 1);
        $todayPaid = $paid()->whereBetween('pay_time', [
            Date::calcDay(), Date::calcDay(0, Date::TYPE_END),
        ]);
        $currency = $this->currency->getCurrency();
        $decimals = (int)$currency['decimals'];
        $recent = $paid()->select([
            'id', 'trade_no', 'commodity_id', 'card_num', 'amount',
            'pay_time', 'delivery_status',
        ])->with('commodity:id,name')->orderByDesc('pay_time')->orderByDesc('id')
            ->limit(10)->get()->toArray();
        foreach ($recent as &$order) {
            $order['display_amount'] = number_format((float)$order['amount'], $decimals);
        }
        unset($order);

        return $this->render('控制台', 'Dashboard/Index.html', [
            'dashboard' => [
                'products' => number_format(Commodity::query()->where('owner', 0)->where('status', 1)->count()),
                'cards' => number_format(Card::query()->where('owner', 0)->where('status', 0)->count()),
                'paid_orders' => number_format($paid()->count()),
                'pending_delivery' => number_format($paid()->where('delivery_status', 0)->count()),
                'today_orders' => number_format((clone $todayPaid)->count()),
                'today_sales_display' => (string)$currency['symbol'] . number_format((float)$todayPaid->sum('amount'), $decimals),
                'currency_symbol' => (string)$currency['symbol'],
            ],
            'recent_orders' => $recent,
        ]);
    }
}
