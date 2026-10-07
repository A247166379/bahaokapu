<?php
declare(strict_types=1);

namespace App\Controller\User\Api;


use App\Consts\Hook;
use App\Controller\Base\API\User;
use App\Entity\Query\Get;
use App\Interceptor\UserVisitor;
use App\Interceptor\Waf;
use App\Model\Card;
use App\Model\Commodity;
use App\Model\Config;
use App\Model\Order;
use App\Model\Pay;
use App\Model\UserCommodity;
use App\Service\Query;
use App\Service\Shared;
use App\Service\Shop;
use App\Util\Client;
use App\Util\PayReconciler;
use App\Util\SingleStoreCatalog;
use App\Util\Throttle;
use App\Util\Tree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Exception\RuntimeException;
use Kernel\Util\Decimal;
use Kernel\Util\Log;
use Kernel\Waf\Filter;
use Kernel\Waf\Firewall;

#[Interceptor([Waf::class, UserVisitor::class])]
class Index extends User
{
    #[Inject]
    private Shared $shared;

    #[Inject]
    private Query $query;

    #[Inject]
    private \App\Service\Order $order;

    #[Inject]
    private Shop $shop;


    /**
     * @return array
     */
    public function data(): array
    {
        //分类名是站长自定义的动态文案，建树前在平层统一走 dyn 翻译
        $category = Tree::generate(\App\Util\CommodityLang::categories($this->shop->getCategory($this->getUserGroup())));
        hook(Hook::USER_API_INDEX_CATEGORY_LIST, $category);
        return $this->json(200, "success", $category);
    }

    /**
     * @return array
     * @throws JSONException
     * @throws RuntimeException
     */
    public function commodity(): array
    {
        \App\Util\Schema::ensureCommodityTags();
        \App\Util\Schema::ensureCommodityDisplaySales();
        $salesNow = time();

        $keywords = (string)$_GET['keywords'];
        //本方法走 $_GET 直连 paginate（未过 Get::setPaginate 的钳制）：limit 为负会让 paginate 生成
        //take(-N) 非法 SQL→500（免登录可打）。limit=0 是「返回全部」的既有约定，负数归 0(与既有行为一致、不新增面)。
        $limit = (int)$_GET['limit'];
        if ($limit < 0) {
            $limit = 0;
        }
        $page = max(1, (int)$_GET['page']);
        $categoryId = $_GET['categoryId'];

        $commodity = Commodity::query()
            ->with(['owner' => function (Relation $relation) {
                $relation->select(["id", "username", "avatar"]);
            }, 'category' => function (Relation $relation) {
                $relation->select(["id", "name", "icon"]);
            }]);

        if ($categoryId == 'recommend') {
            $commodity = $commodity->where("recommend", 1);
        } elseif ($categoryId != 0) {
            $commodity = $commodity->where("category_id", $categoryId);
        }

        if ($keywords != "") {
            $commodity = $commodity->where('name', 'like', '%' . $keywords . '%');
        }

        $commodity = $commodity->where('owner', 0)->where(static function (Builder $local): void {
            $local->where('shared_id', 0)->orWhereNull('shared_id');
        });

        $commodity = $commodity
            ->where("status", 1)
            ->orderBy("sort", "asc")
            ->select([
                'id', 'name', 'cover',
                'status', 'delivery_way', 'price',
                'user_price', 'channel_price', 'channel_prices',
                'level_disable', 'level_price', 'hide', 'owner', 'inventory_hidden', "recommend", 'category_id', 'stock', 'shared_id',
                'tags', 'display_sales', 'display_heat_period', 'display_heat_step', 'display_heat_started_at',
                'display_heat_random', 'display_heat_daily_cap',
                //下面几列只用于算出 seckill_active / has_wholesale 两个展示字段，
                //算完就从响应里剔掉（config 里有成本价和定价结构，不能外泄）。见 issue #806
                'seckill_status', 'seckill_start_time', 'seckill_end_time', 'config'
            ])
            ->withDisplaySalesMetrics($salesNow);
        if ($limit == 0) {
            $commodity = $commodity
                ->get();
            $total = count($commodity);
            $data = $commodity->toArray();
        } else {
            $commodity = $commodity
                ->paginate($limit, ["*"], "", $page);
            $total = $commodity->total();
            $data = $commodity->toArray()['data'];
        }


        $user = $this->getUser();
        $userGroup = $this->getUserGroup();
        //取得分类
        $category = $this->shop->getCategory($userGroup);
        $cates = [];
        foreach ($category as $cate) {
            $cates[] = (string)$cate['id'];
        }

        //最终的商品数据遍历
        foreach ($data as $key => $val) {
            try {
                $parseGroupConfig = Commodity::parseGroupConfig($val['level_price'], $userGroup);
            } catch (\Throwable $e) {
                //会员等级配置损坏时按无等级配置处理，单个商品的脏数据不能拖垮整个列表
                $parseGroupConfig = null;
                Log::inst()->error("商品[{$val['id']}]会员等级配置解析失败，已按默认处理：" . $e->getMessage());
            }
            if (!in_array((string)$val['category_id'], $cates) || $val['hide'] == 1 && (!$parseGroupConfig || !isset($parseGroupConfig['show']) || $parseGroupConfig['show'] != 1)) {
                //隐藏商品
                unset($data[$key]);
                continue;
            }

            $data[$key] = array_merge($data[$key], \App\Util\ChannelPrice::display($commodity[$key]));

            //标签（#807）：入库是 JSON 字符串，给前端的是数组，脏数据当成没标签
            $data[$key]['tags'] = Commodity::parseTags($val['tags'] ?? null);

            $data[$key]['order_sold'] = (int)($val['order_sold'] ?? 0);
            $data[$key]['paid_sales_units'] = min(\App\Util\DisplayHeat::LIMIT, max(0, (int)($val['paid_sales_units'] ?? 0)));
            $data[$key]['recent_paid_sales'] = min(\App\Util\DisplayHeat::LIMIT, max(0, (int)($val['recent_paid_sales'] ?? 0)));
            $data[$key]['sales_display_configured'] = ($val['display_sales'] ?? null) !== null;
            $data[$key]['sales'] = \App\Util\DisplayHeat::total(
                ($val['display_sales'] ?? null) === null ? null : (int)$val['display_sales'],
                (int)($val['display_heat_period'] ?? 0),
                (int)($val['display_heat_step'] ?? 0),
                (int)($val['display_heat_started_at'] ?? 0),
                $data[$key]['paid_sales_units'], $salesNow,
                (int)($val['display_heat_random'] ?? 0) === 1,
                (int)($val['display_heat_daily_cap'] ?? 0), (int)$val['id']
            );

            if ($val['delivery_way'] == 0 && !$val['shared_id']) {
                $data[$key]['stock'] = Card::query()->where("status", 0)->where("commodity_id", $val['id'])->count();
            }

            //如果登录后，则自动计算登录后的价格
            if ($user) {
                try {
                    $tradeAmount = $this->order->valuation(commodity: $commodity[$key], group: $userGroup);
                    $data[$key]['price'] = $tradeAmount;
                    $data[$key]['user_price'] = $tradeAmount;
                } catch (\Throwable $e) {
                    //估价失败（通常是商品配置脏数据）时降级为原价展示，单个商品不能拖垮整个列表
                    Log::inst()->error("商品[{$val['id']}]会员价计算失败，已降级为原价展示：" . $e->getMessage());
                }
            }

            //秒杀是否"正在进行"（开关开着还不够，得落在起止时间内）、是否配了批发价。
            //让前端可以直接打标签，不用各家自己去解析 config。见 issue #806
            $data[$key]['seckill_active'] = $commodity[$key]->isSeckillActive();
            $data[$key]['has_wholesale'] = Commodity::hasWholesaleConfig($val['config'] ?? null);

            unset(
                $data[$key]['level_price'],
                $data[$key]['level_disable'],
                //原始配置不能给前端：里面有成本价、种类单价、SKU 加价等定价结构
                $data[$key]['config'],
                $data[$key]['seckill_start_time'],
                $data[$key]['seckill_end_time'],
                $data[$key]['display_sales'],
                $data[$key]['display_heat_period'],
                $data[$key]['display_heat_step'],
                $data[$key]['display_heat_started_at'],
                $data[$key]['display_heat_random'],
                $data[$key]['display_heat_daily_cap'],
                //shared_id 只用于上面那段库存分支。它一旦出现在响应里，任何人（本接口
                //免登录）都能一眼看出哪些商品是从别处转售来的、共几家上游——
                //这是转售身份的泄露面，见 App\Util\SharedPayload
                $data[$key]['shared_id']
            );

            if (!$val['cover']) {
                $data[$key]['cover'] = "/favicon.ico";
            }

            //会员价留空(0)时回退零售价，与下单计价口径一致（放在分站加价之前）
            if ((float)($data[$key]['user_price'] ?? 0) <= 0) {
                $data[$key]['user_price'] = $data[$key]['price'];
            }

            //隐藏库存
            $data[$key]['stock_state'] = $this->shop->getStockState($data[$key]['stock']);
            if ($val['inventory_hidden'] == 1) {
                $data[$key]['stock'] = $this->shop->getHideStock($data[$key]['stock']);
            }
        }

        $data = array_values($data);
        hook(Hook::USER_API_INDEX_COMMODITY_LIST, $data);
        //商品名（含分站自定义名）是动态文案，最终出口统一走 dyn 翻译；下单按 id 提交不受影响
        $data = \App\Util\CommodityLang::categoryRelations($data);
        //卡片上还会露出标签，标签是对象数组，transList 覆盖不到
        $data = \App\Util\CommodityLang::listTags($data);
        $json = $this->json(200, "success", $data);
        $json['total'] = $total;
        return $json;
    }

    /**
     * @param int $commodityId
     * @return array
     */
    public function commodityDetail(int $commodityId): array
    {
        $array = $this->shop->getItem($commodityId, $this->getUser(), $this->getUserGroup());
        hook(Hook::USER_API_INDEX_COMMODITY_DETAIL_INFO, $array);

        $array['stock_state'] = $this->shop->getStockState($array['stock']);
        if ($array['inventory_hidden'] == 1) {
            $array['stock'] = $this->shop->getHideStock($array['stock']);
        }

        //商品展示文案统一在出口翻译（名称/详情/标签/自定义下单字段/各类提示），字段清单见 CommodityLang
        $array = \App\Util\CommodityLang::detail($array);

        return $this->json(200, 'success', $array);
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function card(): array
    {
        $map = $this->request->post();
        /**
         * @var Commodity $commodity
         */
        //item_id 必须是标量：传数组会让 find([]) 返回集合，后面 ->status 抛异常→500（免登录可打）。
        //归一化回 $map，供下方闭包 where("commodity_id",...) 与 shared 分支转发复用。
        $map['item_id'] = is_scalar($map['item_id'] ?? null) ? (int)$map['item_id'] : 0;
        $commodity = Commodity::with(['shared'])->find($map['item_id']);
        $limit = $map['limit'] ?? 10;

        if (!$commodity) {
            throw new JSONException("商品不存在");
        }
        SingleStoreCatalog::assertLocal($commodity);

        if ($commodity->status != 1) {
            throw new JSONException("该商品暂未上架");
        }
        if ($commodity->draft_status != 1) {
            throw new JSONException("该商品不支持预选");
        }

        //限流：本接口免登录，纵深防御挡住对预选库存的高频枚举/盲注（主防线是下方 setFilterColumns 列白名单）。
        //阈值给得比订单/卡密查询宽松，正常买家翻页+搜索预选内容够用，脚本化刷库会被拦。
        if (Throttle::tooMany("draft:ip:" . Client::getAddress(), 60, 60)) {
            throw new JSONException("请求过于频繁，请稍后再试");
        }

        if ($commodity->shared) {
            $data = $this->shared->draftCard($commodity->shared, $commodity->shared_code, $map);
            //加价算法
            foreach ($data['list'] as &$item) {
                if ($item['draft_premium'] > 0) {
                    $item['draft_premium'] = $this->shared->AdjustmentExtra($commodity, $item['draft_premium']);
                }
            }
        } else {
            $get = new Get(Card::class);
            $get->setPaginate((int)$this->request->post("page"), (int)$limit);
            $get->setWhere($map);
            //本接口免登录、且强制 status=0（未售库存）。客户端唯一合法的过滤是「搜索可选内容」= search-draft，
            //draft 是本就随列表返回的预览内容。若放任客户端过滤任意列，search-secret / betweenStart-secret
            //会把 total 的 0/1 变成布尔预言机，匿名逐字符盲注拖走未售卡密的 secret。故只白名单 draft。
            $get->setFilterColumns(['draft']);
            $get->setColumn('id', 'draft', 'draft_premium');

            $data = $this->query->get($get, function (Builder $builder) use ($map) {
                $builder = $builder->where("commodity_id", $map['item_id'])->where("status", 0);

                if (!empty($map['race'])) {
                    $builder = $builder->where("race", $map['race']);
                }

                if (!empty($map['sku']) && is_array($map['sku'])) {
                    foreach ($map['sku'] as $k => $v) {
                        $builder = $builder->where("sku->{$k}", $v);
                    }
                }

                return $builder;
            });
        }

        //分站处理
        if (\App\Model\Business::state()) {
            foreach ($data['list'] as &$item) {
                if ($item['draft_premium'] > 0) {
                    $item['draft_premium'] = $this->shop->getSubstationPrice($commodity, $item['draft_premium']);
                }
            }
        }

        if ($commodity->level_disable != 1 && $this->request->post('price_type') !== 'channel') {
            //渠道分支的预选卡加价不叠加会员折扣，与真实估价一致
            foreach ($data['list'] as &$item) {
                if ($item['draft_premium'] > 0) {
                    $item['draft_premium'] = $this->order->getValuationPrice($commodity->id, $item['draft_premium'], $this->getUserGroup());
                }
            }
        }

        //预选卡预告信息是动态文案，展示层翻译；下单按 card_id 提交不受影响
        $data['list'] = \Kernel\Util\Lang::transList($data['list'], ['draft']);

        return $this->json(data: $data);
    }


    /**
     * @return array
     */
    public
    function valuation(): array
    {
        $quoteItem = Commodity::query()->find((int)$this->request->post('item_id'));
        if (!$quoteItem || (int)$quoteItem->status !== 1) throw new JSONException('该商品暂未上架');
        SingleStoreCatalog::assertLocal($quoteItem);
        $quoteQuantity = (int)$this->request->post('num');
        if ((int)$quoteItem->minimum > 0 && $quoteQuantity < (int)$quoteItem->minimum) throw new JSONException(sprintf(lang('本商品最少购买%s个'), $quoteItem->minimum));
        if ((int)$quoteItem->maximum > 0 && $quoteQuantity > (int)$quoteItem->maximum) throw new JSONException(sprintf(lang('本商品单次最多购买%s个'), $quoteItem->maximum));
        $price = $this->order->valuation(
            commodity: (int)$this->request->post("item_id"),
            num: (int)$this->request->post("num"),
            race: (string)$this->request->post("race"),
            sku: (array)$this->request->post("sku"),
            cardId: (int)$this->request->post("card_id"),
            coupon: (string)$this->request->post("coupon"),
            group: $this->getUserGroup(),
            priceType: (string)($this->request->post("price_type") ?: "retail")
        );
        $price = $this->shop->getSubstationPrice((int)$this->request->post("item_id"), $price);
        $fee = '0.00';
        $payId = (int)$this->request->post('pay_id');
        $method = $this->request->post('payment_method');
        if ($method !== null && $method !== '' && !is_string($method)) throw new JSONException('支付方式格式不正确');
        if ($payId > 0 || ($method !== null && $method !== '')) {
            $pay = $method !== null && $method !== ''
                ? \App\Util\PaymentMethods::resolve($method, $payId)
                : Pay::query()->find($payId);
            SingleStoreCatalog::assertPayment($pay);
            \App\Util\PaymentMethods::assertRoute($pay);
            if ($pay->handle !== '#system' && bccomp($price, '0', 2) > 0) $fee = (new Decimal($pay->cost_type == 0 ? $pay->cost : (new Decimal($price, 2))->mul($pay->cost)->getAmount(), 2))->getAmount();
        }
        return $this->json(data: ['price' => $price, 'fee' => $fee, 'total' => (new Decimal($price, 2))->add($fee)->getAmount()]);
    }


    /**
     * @return array
     */
    public
    function stock(): array
    {
        $commodity = Commodity::with(['shared'])->find((int)$this->request->post("item_id"));

        //getItemStock 的入参不可空，商品不存在时传 null 会抛 TypeError 落到通用兜底=500。
        //与同控制器 valuation() 一致，改为返回干净的业务错误（也避免免登录高频触发 500 与日志噪声）。
        if (!$commodity) {
            throw new JSONException("商品不存在");
        }
        SingleStoreCatalog::assertLocal($commodity);

        $_race = (string)$this->request->post("race");
        $_skus = (array)$this->request->post("sku") ?: [];

        $stock = $this->shop->getItemStock($commodity, $_race, $_skus);

        $array = ["stock" => $stock];
        $array['stock_state'] = $this->shop->getStockState($stock);
        if ($commodity->inventory_hidden == 1) {
            $array['stock'] = $this->shop->getHideStock($stock);
        }
        return $this->json(data: $array);
    }


    /**
     * @return array
     */
    public
    function pay(): array
    {

        return $this->json(200, 'success', \App\Util\PaymentMethods::publicList());

    }


    /**
     * @return array
     * @throws JSONException
     */
    public function query(): array
    {
        //与下单侧同一条取参管线（Filter::NORMAL），保证入库值与检索值经历同样的转换（#833）
        $keywords = trim((string)$this->request->post("keywords", flags: Filter::NORMAL));

        if ($keywords == "-") {
            throw new JSONException("无数据");
        }

        //老订单兼容：历史联系方式入库自旧清洗管线（多一次 urldecode、裸 & 实体化），用旧管线重算一个候选值
        $firewall = Firewall::inst();
        $legacyKeywords = trim((string)$firewall->filterContent(
            $firewall->xssKillerLegacy((string)$this->request->unsafePost("keywords")),
            Filter::NORMAL
        ));
        $keywordsCandidates = array_values(array_unique(array_filter([$keywords, $legacyKeywords], fn($k) => $k !== "")));

        //限流：挡住按订单号/联系方式批量枚举订单卡密（本接口免登录）
        if (Throttle::tooMany("query:ip:" . Client::getAddress(), 30, 600)) {
            throw new JSONException("请求过于频繁，请稍后再试");
        }

        // 本地环境的 127.0.0.1 回调地址无法被外部支付网关访问。用户按精确订单号
        // 查询时，先走支付插件的鉴权核单；若上游已付，仍复用标准回调管线发货。
        if (preg_match('/^\d{18}$/D', $keywords)) {
            PayReconciler::order($keywords, $this->order);
        }

        $get = new Get(Order::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setColumn('id', 'trade_no', 'sku', 'secret', 'user_id', 'password', 'amount', 'leave_message', 'display_locale', 'price_type', 'pay_id', 'commodity_id', 'create_time', 'pay_time', 'delivery_status', 'status', 'card_num', 'contact', "race");

        $data = $this->query->get($get, function (Builder $builder) use ($keywords, $keywordsCandidates) {

            $builder = $builder->with(['pay' => function (Relation $relation) {
                $relation->select(['id', 'name', 'icon', 'code', 'handle', 'buyer_method']);
            }, 'commodity' => function (Relation $relation) {
                $relation->select(['id', 'name', 'cover', 'password_status', 'leave_message']);
            }]);

            if (preg_match('/^\d{18}$/', $keywords)) {
                $builder = $builder->where("trade_no", $keywords);
            } else {
                $builder = $builder->whereIn("contact", $keywordsCandidates);
            }
            return $builder;
        });

        foreach ($data['list'] as &$item) {
            //发货留言统一在这里定版：优先订单快照，老订单回退商品表（issue #813）。
            //各主题读的都是 order.leave_message，这里给全了它们就都对
            $messageSource = $item['leave_message'] ?? $item['commodity']['leave_message'] ?? '';
            $item['leave_message'] = \App\Util\CommodityLang::orderMessage((int)$item['commodity_id'], (string)$messageSource);
            if (!empty($item['commodity'])) {
                $item['commodity'] = \App\Util\CommodityLang::detail($item['commodity']);
            }
            $item['race'] = lang((string)($item['race'] ?? ''), 'dyn');
            if (is_array($item['sku'] ?? null)) {
                $labels = [];
                foreach ($item['sku'] as $key => $value) $labels[lang((string)$key, 'dyn')] = lang((string)$value, 'dyn');
                $item['sku'] = $labels;
            }
            if (isset($item['pay'])) {
                $method = \App\Util\PaymentMethods::infer($item['pay']);
                $item['payment_method_name'] = \App\Util\PaymentMethods::label($method);
                $item['pay'] = ['name' => $item['payment_method_name'], 'icon' => \App\Util\PaymentMethods::icon($method)];
            }
            $item['leave_message'] = \App\Util\RichHtml::sanitize((string)($item['leave_message'] ?? ''), false);
            if ($item['status'] != 1) {
                unset($item['commodity']['leave_message'], $item['leave_message']);
                unset($item['secret']);
            }

            if (!empty($item['password'])) {
                $item['password'] = true;
                unset($item['secret'], $item['leave_message'], $item['commodity']['leave_message']);
            }
        }

        hook(Hook::USER_API_INDEX_QUERY_LIST, $data);
        return $this->json(data: $data);
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function secret(): array
    {
        //与下单侧同一条取参管线（Filter::NORMAL）。旧版从 $_REQUEST 注入参数，
        //多经历一层 htmlspecialchars/strip_tags，含 & < > " ' 的查单密码永远对不上（#833）
        $tradeNo = trim((string)$this->request->post("tradeNo", flags: Filter::NORMAL));
        $password = (string)$this->request->post("password", flags: Filter::NORMAL);
        $ip = Client::getAddress();

        //限流：挡住卡密查询密码爆破 / 订单号枚举（本接口免登录，曾被单次刷 1 万+）
        if (Throttle::tooMany("secret:ip:{$ip}", 40, 600)
            || Throttle::tooMany("secret:no:{$tradeNo}:{$ip}", 8, 600)) {
            throw new JSONException("请求过于频繁，请稍后再试");
        }

        $order = Order::with(['commodity'])->where("trade_no", $tradeNo)->first();

        if (!$order) {
            throw new JSONException("未查询到相关信息");
        }

        if (!empty($order->password)) {
            //候选一：当前管线的值（新订单）；候选二：旧清洗管线重放值（3.5.8 及以前的订单，
            //当年入库的是「二次 urldecode + & 实体化」后的形态）。定时安全比较防按响应时间逐字符猜测
            $firewall = Firewall::inst();
            $legacyPassword = (string)$firewall->filterContent(
                $firewall->xssKillerLegacy((string)$this->request->unsafePost("password")),
                Filter::NORMAL
            );
            if (!hash_equals((string)$order->password, $password)
                && !hash_equals((string)$order->password, $legacyPassword)) {
                throw new JSONException("密码错误");
            }
        }

        if ($order->status != 1) {
            throw new JSONException("订单还未支付");
        }

        //验证通过，重置该订单的失败计数，避免正常用户多次查看被误伤
        Throttle::clear("secret:no:{$tradeNo}:{$ip}");

        $widget = \App\Util\CommodityLang::orderWidgetLabels((int)$order->commodity_id, (array)json_decode((string)$order->widget, true));
        if (empty($widget)) {
            $widget = null;
        }

        hook(Hook::USER_API_INDEX_QUERY_SECRET, $order);
        return $this->json(data: [
            'secret' => $order->secret,
            'widget' => $widget,
            'leave_message' => \App\Util\RichHtml::sanitize(\App\Util\CommodityLang::orderMessage((int)$order->commodity_id, (string)($order->leave_message ?? $order?->commodity?->leave_message ?? '')), false)
        ]);
    }
}
