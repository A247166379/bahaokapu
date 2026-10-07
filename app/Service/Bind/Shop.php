<?php
declare(strict_types=1);

namespace App\Service\Bind;

use App\Consts\Hook;
use App\Model\Business;
use App\Model\Card;
use App\Model\Category;
use App\Model\Commodity;
use App\Model\Config;
use App\Model\User;
use App\Model\UserCategory;
use App\Model\UserCommodity;
use App\Model\UserGroup;
use App\Service\Shared;
use App\Util\Client;
use App\Util\Ini;
use App\Util\SingleStoreCatalog;
use App\Util\Tree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Exception\JSONException;
use Kernel\Exception\RuntimeException;
use Kernel\Plugin\Entity\Stock;
use Kernel\Util\Decimal;

class Shop implements \App\Service\Shop
{
    #[Inject]
    private Shared $shared;

    #[Inject]
    private \App\Service\Order $order;

    public function getCategory(?UserGroup $group): array
    {
        $category = Category::query()->withCount(['children as commodity_count' => function (Builder $builder) {
            $builder->where("status", 1)->where('owner', 0)->where(static function (Builder $local): void {
                $local->where('shared_id', 0)->orWhereNull('shared_id');
            });
        }])->where("status", 1)->where('owner', 0)->orderBy("sort", "asc");

        $category = $category->get();

        foreach ($category as $index => $item) {
            $levelConfig = $item->getLevelConfig($group);
            if ($item->hide == 1 && (!$levelConfig || !isset($levelConfig['show']) || (int)$levelConfig['show'] != 1)) {
                unset($category[$index]);
                continue;
            }

            if (!$item->icon) {
                $category[$index]['icon'] = '/favicon.ico';
            }
        }

        $array = $category->toArray();
        $array = array_values($array);

        $commodityRecommend = Config::get("commodity_recommend");
        if ($commodityRecommend == 1) {
            array_unshift($array, [
                "id" => 'recommend',

                "name" => lang((string)Config::get("commodity_name"), "dyn"),
                "sort" => 1,
                "create_time" => "-",
                "owner" => 0,
                "icon" => "/assets/static/images/recommend.png",
                "status" => 1,
                "hide" => 0,
                "user_level_config" => null,
                "commodity_count" => Commodity::query()->where("status", 1)->where("recommend", 1)->where('owner', 0)->where(static function (Builder $local): void {
                    $local->where('shared_id', 0)->orWhereNull('shared_id');
                })->count(),
            ]);
        }

        return $array;
    }

    public function getItem(int|string $commodityId, ?User $user = null, ?UserGroup $group = null): array
    {
        \App\Util\Schema::ensureCommodityTags();
        \App\Util\Schema::ensureCommodityDisplaySales();
        $salesNow = time();

        $commodity = Commodity::query()->where('owner', 0)->where(static function (Builder $local): void {
            $local->where('shared_id', 0)->orWhereNull('shared_id');
        })->with(['owner' => function (Relation $relation) {
            $relation->select(["id", "username", "avatar"]);
        }])
            ->select(["id", "name", "description",
                "only_user", "purchase_count", "category_id", "cover", "price", "user_price", "channel_price", "channel_prices",
                "status", "owner", "delivery_way", "contact_type", "password_status", "level_price",
                "level_disable", "coupon", "shared_id", "shared_code", "shared_premium", "shared_premium_type", "seckill_status",
                "seckill_start_time", "seckill_end_time", "draft_status", "draft_premium", "inventory_hidden",
                "widget", "minimum", "maximum", "shared_sync", "config", "stock", "code", "shared_amount_sync", "shared_config_sync",
                "tags", "display_sales", "display_heat_period", "display_heat_step", "display_heat_started_at",
                "display_heat_random", "display_heat_daily_cap"])
            ->withDisplaySalesMetrics($salesNow);

        if (is_int($commodityId)) {
            $commodity = $commodity->find($commodityId);
        } else {
            $commodity = $commodity->where("code", $commodityId)->first();
        }

        if (!$commodity) {
            throw new JSONException("商品不存在");
        }
        SingleStoreCatalog::assertLocal($commodity);

        if ($commodity->status != 1) {
            throw new JSONException("该商品暂未上架");
        }

        $shared = \App\Model\Shared::query()->find($commodity->shared_id);

        if ($shared) {
            if ($commodity->shared_sync == 1) {
                $this->shared->syncRemoteItem($commodity->id);

                $fresh = Commodity::query()->find($commodity->id);
                if ($fresh) {
                    foreach ([
                        'price', 'user_price', 'config', 'level_price',
                        'draft_status', 'draft_premium', 'widget', 'stock',
                    ] as $field) {
                        $commodity->{$field} = $fresh->{$field};
                    }
                }
            }
        } else if ($commodity->delivery_way == 0) {
            $commodity->stock = Card::query()->where("commodity_id", $commodity->id)->where("status", 0)->count();

        }

        try {
            $this->order->parseConfig($commodity, $group);
        } catch (JSONException $e) {
            throw new JSONException("该商品配置异常，请商家检查商品[{$commodity->id}]的批发/规格/会员价配置：" . $e->getMessage());
        }

        //会员价留空(0)时回退零售价——必须在分站加价之前归一，
        //否则前台会显示 0 元而下单按零售价收费，两边对不上
        $commodity->user_price = $commodity->memberPrice();

        $this->substationPriceIncrease($commodity);

        $commodity->service_url = Config::get("service_url");
        $commodity->service_qq = Config::get("service_qq");

        if ($commodity->draft_status == 1 && $commodity->draft_premium > 0 && $commodity->level_disable != 1) {
            $commodity->draft_premium = $this->order->getValuationPrice($commodity->id, $commodity->draft_premium, $group);
        }

        $array = array_merge($commodity->toArray(), \App\Util\ChannelPrice::display($commodity));

        $array['order_sold'] = (int)($array['order_sold'] ?? 0);
        $array['paid_sales_units'] = min(\App\Util\DisplayHeat::LIMIT, max(0, (int)($array['paid_sales_units'] ?? 0)));
        $array['recent_paid_sales'] = min(\App\Util\DisplayHeat::LIMIT, max(0, (int)($array['recent_paid_sales'] ?? 0)));
        $array['sales_display_configured'] = ($array['display_sales'] ?? null) !== null;
        $array['sales'] = \App\Util\DisplayHeat::total(
            ($array['display_sales'] ?? null) === null ? null : (int)$array['display_sales'],
            (int)($array['display_heat_period'] ?? 0),
            (int)($array['display_heat_step'] ?? 0),
            (int)($array['display_heat_started_at'] ?? 0),
            $array['paid_sales_units'], $salesNow,
            (int)($array['display_heat_random'] ?? 0) === 1,
            (int)($array['display_heat_daily_cap'] ?? 0), (int)$array['id']
        );
        unset($array['display_sales'], $array['display_heat_period'], $array['display_heat_step'], $array['display_heat_started_at'], $array['display_heat_random'], $array['display_heat_daily_cap']);

        if ($array["owner"]) {
            $business = Business::query()->where("user_id", $array["owner"]['id'])->first();
            if ($business) {
                $array['service_url'] = $business->service_url;
                $array['service_qq'] = $business->service_qq;
            }
        }

        if (!$array['cover']) {
            $array['cover'] = "/favicon.ico";
        }

        if (is_int($commodityId)) {
            $array['description'] = \App\Util\RichHtml::sanitize(
                (string)($array['description'] ?? ''),
                (int)$commodity->owner === 0
            );
        }

        $array['share_url'] = Client::getUrl() . "/item/{$array['id']}.html";
        $array['login'] = (bool)$user;

        $array['trade_captcha'] = (int)Config::get("trade_verification");

        if ($commodity->widget) {
            $array['widget'] = json_decode($commodity->widget, true);
        }

        $array['tags'] = Commodity::parseTags($array['tags'] ?? null);

        //出站清洗放在最后一步：description 要等 RichHtml 处理完、cover 要等空值兜底完。
        //这条路同时供免登录的前台商品详情和店铺共享的 item 接口使用，两边都不能看到
        //shared_*（转售身份与上游商品编号）、level_price（会员定价结构）和 config 里的
        //成本段；详情里的上游图片直链也在这里抹掉。见 App\Util\SharedPayload。
        return \App\Util\SharedPayload::detail($array);
    }

    public function getHideStock(int|string|null $stock): string
    {
        $stock = (int)$stock;

        return lang(match (true) {
            $stock <= 0 => "已售罄",
            $stock <= 5 => "即将售罄",
            $stock <= 20 => "一般",
            $stock <= 100 => "充足",
            default => "非常多"
        }, "tpl");
    }

    public function getStockState(int|string|null $stock): int
    {
        $stock = (int)$stock;
        return match (true) {
            $stock <= 0 => 0,
            $stock <= 5 => 1,
            $stock <= 20 => 2,
            $stock <= 100 => 3,
            default => 4
        };
    }

    public function getItemStock(int|Commodity|string $commodity, ?string $race = null, ?array $sku = []): string
    {
        if (is_int($commodity)) {
            $commodity = Commodity::with(['shared'])->find($commodity);
        } elseif (is_string($commodity)) {
            $commodity = Commodity::with(['shared'])->where("code", $commodity)->first();
        }

        if (!$commodity) throw new JSONException("商品不存在");

        // Validate before stock hooks or shared-store calls can leave this installation.
        SingleStoreCatalog::assertLocal($commodity);

        if (($hook = \hook(Hook::SERVICE_SHOP_GET_ITEM_STOCK, $commodity, $race, $sku)) instanceof Stock) return $hook->getStock();

        if ($commodity->shared) {
            return $this->getSharedStock($commodity, $race, $sku);
        } else if ($commodity->delivery_way == 0) {
            $card = Card::query()->where("commodity_id", $commodity->id)->where("status", 0);
            if ($race) $card = $card->where("race", $race);
            if (!empty($sku)) {
                foreach ($sku as $k => $v) {
                    $card = $card->where("sku->{$k}", $v);
                }
            }
            return (string)$card->count();
        }
        return (string)$commodity->stock;
    }

    public function getSharedStockHash(int $id, ?string $race = null, ?array $sku = []): string
    {
        return md5($id . $race . json_encode($sku ?: []));
    }

    public function updateSharedStock(int|Commodity $commodity, ?string $race = null, ?array $sku = []): void
    {
        if (is_int($commodity)) {
            $commodity = Commodity::query()->find($commodity);
        }
        if (!$commodity) throw new JSONException("商品不存在");
        $hash = $this->getSharedStockHash($commodity->id, $race, $sku);
        $stock = is_array($commodity->shared_stock) ? $commodity->shared_stock : [];
        if (!array_key_exists($hash, $stock)) {
            return;
        }
        unset($stock[$hash]);
        Commodity::query()->where("id", $commodity->id)->update(["shared_stock" => $stock]);
        //缓存被判定失效 = 上游那边刚成交过，库存必然变了
        //hook() 的变参按引用接收，字面量传不进去，必须先落成变量
        $ebIds = [(int)$commodity->id];
        $ebAction = 'sync';
        $ebBefore = null;
        hook(Hook::COMMODITY_CHANGE_AFTER, $ebIds, $ebAction, $ebBefore);
    }

    public function getSharedStock(int|Commodity $commodity, ?string $race = null, ?array $sku = []): string|null
    {
        if (is_int($commodity)) {
            $commodity = Commodity::query()->find($commodity);
        }
        if (!$commodity) throw new JSONException("商品不存在");
        $hash = $this->getSharedStockHash($commodity->id, $race, $sku);

        if (!is_array($commodity->shared_stock) || !isset($commodity->shared_stock[$hash])) {
            $stock = $this->shared->getItemStock((clone $commodity), $commodity->shared, $commodity->shared_code, $race, $sku);
            $array = is_array($commodity->shared_stock) ? $commodity->shared_stock : [];
            $array[$hash] = $stock;
            Commodity::query()->where("id", $commodity->id)->update(["shared_stock" => $array]);
            //只有真正回源拿到新数据才广播；命中缓存的分支不走这里，天然自限流
            //hook() 的变参按引用接收，字面量传不进去，必须先落成变量
            $ebIds = [(int)$commodity->id];
            $ebAction = 'sync';
            $ebBefore = null;
            hook(Hook::COMMODITY_CHANGE_AFTER, $ebIds, $ebAction, $ebBefore);
            return $stock;
        }

        return $commodity->shared_stock[$hash];
    }

    public function getDraft(Commodity|int|string $commodity, int $cardId): array
    {
        if (is_int($commodity)) {
            $commodity = Commodity::query()->find($commodity);
        }
        if (!$commodity) throw new JSONException("商品不存在");
        SingleStoreCatalog::assertLocal($commodity);

        $card = Card::query()->where("commodity_id", $commodity->id)->where("id", $cardId)->first();
        if (!$card) {
            throw new JSONException("预选的宝贝不存在");
        }

        if ($commodity->id != $card->commodity_id) {
            throw new JSONException("此预告信息不属于此商品");
        }

        if ($card->status != 0) {
            throw new JSONException("此宝贝已被他人抢走");
        }

        return ["draft_premium" => $card->draft_premium, "cost" => $card->cost];
    }

    public function substationPriceIncrease(Commodity &$commodity): void
    {
        $business = Business::get();

        if (!$business) {
            return;
        }

        $userCommodity = UserCommodity::query()->where("user_id", $business->user_id)->where("commodity_id", $commodity->id)->first();

        if (!$userCommodity) {
            return;
        }

        if ($userCommodity->name) {
            $commodity->name = $userCommodity->name;
        }

        if (trim((string)$userCommodity->description) !== '') {
            $commodity->description = $userCommodity->description;
        }

        $config = $commodity->config ?: [];

        if ($userCommodity->premium > 0) {
            $commodity->price = $userCommodity->markup($commodity->price);
            $commodity->user_price = $userCommodity->markup($commodity->user_price);

            if ($commodity->draft_premium > 0) {
                $commodity->draft_premium = $userCommodity->markup($commodity->draft_premium);
            }

            if (is_array($config['category'])) {
                foreach ($config['category'] as &$price) {
                    $price = $userCommodity->markup($price);
                }
            }

            if (is_array($config['wholesale'])) {
                foreach ($config['wholesale'] as &$price) {
                    $price = $userCommodity->markup($price);
                }
            }

            if (is_array($config['category_wholesale'])) {
                foreach ($config['category_wholesale'] as &$arr) {
                    foreach ($arr as &$price) {
                        $price = $userCommodity->markup($price);
                    }
                }
            }

            if (is_array($config['sku'])) {
                foreach ($config['sku'] as &$arr) {
                    foreach ($arr as &$price) {
                        $price = $userCommodity->markup($price);
                    }
                }
            }
        }

        $commodity->config = $config;
    }

    public function getSubstationPrice(Commodity|int $commodity, int|string|float $amount): string
    {
        if (is_int($commodity)) {
            $commodity = Commodity::query()->find($commodity);
        }

        if (!$commodity) {
            throw new JSONException("商品不存在");
        }

        $business = Business::get();

        if (!$business) {
            return (string)$amount;
        }

        $userCommodity = UserCommodity::query()->where("user_id", $business->user_id)->where("commodity_id", $commodity->id)->first();

        if (!$userCommodity) {
            return (string)$amount;
        }

        if ($userCommodity->premium > 0) {
            return $userCommodity->markup($amount);
        }

        return (string)$amount;
    }
}
