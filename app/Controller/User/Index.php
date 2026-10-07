<?php
declare(strict_types=1);

namespace App\Controller\User;


use App\Consts\Hook;
use App\Controller\Base\View\User;
use App\Interceptor\UserVisitor;
use App\Interceptor\Waf;
use App\Model\Config;
use App\Service\Shop;
use App\Util\Tree;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Exception\RuntimeException;
use Kernel\Exception\ViewException;

#[Interceptor([Waf::class, UserVisitor::class])]
class Index extends User
{
    #[Inject]
    private Shop $shop;

    /**
     * @return string
     * @throws RuntimeException
     * @throws ViewException
     * @throws JSONException
     * @throws \ReflectionException
     */
    public function index(): string
    {
        if (\App\Util\StoreMaintenance::closed()) {
            \App\Util\StoreMaintenance::responseHeaders();
            return $this->theme("店铺正在维护", "CLOSED", "Index/Closed.html", ['robots' => 'noindex,nofollow']);
        }
        $from = 0;

        $requestedCategory = is_scalar($_GET['cid'] ?? null) ? (string)$_GET['cid'] : '';
        $_GET['cid'] = \App\Util\StoreLocale::publicPath(\App\Util\StoreLocale::path()) === '/products.html' ? '0' : ($requestedCategory ?: Config::get("default_category"));
        $priceType = 'retail';

        //获取所有分类
        //分类名同样是动态文案，与 API 侧 Api\Index::data() 的处理保持一致
        $sourceCategories = $this->shop->getCategory($this->getUserGroup());
        $categoryList = \App\Util\CommodityLang::categories($sourceCategories);
        $category = Tree::generate($categoryList);
        hook(Hook::USER_API_INDEX_CATEGORY_LIST, $category);

        $baseUrl = rtrim(\App\Util\Client::getUrl(), '/');
        $requestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $categoryName = '';
        if (preg_match('#^/(?:cat|category)/(\d+)(?:\.html)?/?$#D', \App\Util\StoreLocale::path(), $categoryRoute)) {
            foreach ($categoryList as $entry) {
                if ((string)$entry['id'] === $categoryRoute[1]) {
                    $categoryName = trim((string)$entry['name']);
                    break;
                }
            }
        }
        $isCategoryPage = $categoryName !== '';
        if (isset($categoryRoute[1]) && !$isCategoryPage) return $this->unavailablePage('内容不存在', 404);
        $alternateLocales = \App\Util\StoreLocale::CODES;
        if ($isCategoryPage) foreach ($sourceCategories as $sourceCategory) {
            if ((string)$sourceCategory['id'] === $categoryRoute[1]) {
                $alternateLocales = \App\Util\PublicSitemap::entityEditionLocales('category', $sourceCategory);
                break;
            }
        }
        $isProductsPage = \App\Util\StoreLocale::publicPath(\App\Util\StoreLocale::path()) === '/products.html';
        // Homepage metadata must reflect the saved basic settings, including
        // changes made through the visual editor. Category metadata stays distinct.
        $homeTitle = trim((string)(Config::get('title') ?? ''));
        $homeKeywords = trim((string)(Config::get('keywords') ?? ''));
        $homeDescription = trim((string)(Config::get('description') ?? ''));
        foreach (['title' => &$homeTitle, 'keywords' => &$homeKeywords, 'description' => &$homeDescription] as $field => &$value) {
            $translated = \App\Util\StoreContentService::entityValue('config', 'site', $field, (string)Config::get($field));
            if ($translated !== null) $value = $translated;
        }
        unset($value);

        return $this->theme($isCategoryPage ? $categoryName : ($isProductsPage ? lang('全部商品') : ($homeTitle !== '' ? $homeTitle : "首页")), "INDEX", "Index/Index.html", [
            'user' => $this->getUser(),
            'from' => $from,
            "categoryId" => $_GET['cid'],
            "category" => \App\Util\CommodityLang::categoryView($category),
            // Already selected from the saved category edition; do not send it
            // through the generic interface dictionary again in the template.
            'categoryPageTitle' => $categoryName,
            'isCategoryPage' => $isCategoryPage,
            'publicProducts' => \App\Util\PublicCatalog::firstPage($categoryList, $this->getUserGroup(), is_scalar($_GET['cid'] ?? null) ? $_GET['cid'] : 0, $priceType),
            'publicProductsAreChannel' => $priceType === 'channel',
            "canonical" => $baseUrl . \App\Util\StoreLocale::url($isCategoryPage ? '/category/' . $categoryRoute[1] : ($isProductsPage ? '/products' : '/')),
            "robots" => in_array(\App\Util\StoreLocale::get(), $alternateLocales, true) ? "index,follow,max-image-preview:large" : "noindex,follow",
            'storeAlternateLocales' => $alternateLocales,
            "homepageSeo" => !$isCategoryPage && !$isProductsPage,
            "seoExactTitle" => $homeTitle !== '' ? $homeTitle : lang("首页"),
            "seoKeywords" => $isCategoryPage ? $categoryName . ',ChatGPT,Plus,Pro' : ($homeKeywords !== '' ? $homeKeywords : 'ChatGPT,Plus,Pro'),
            "seoDescription" => $isCategoryPage || $homeDescription === '' ? lang("选择商品，查看价格与购买说明。") : $homeDescription,
            "ogType" => "website"
        ]);
    }

    /**
     * @return string
     * @throws JSONException
     * @throws ViewException
     * @throws \ReflectionException
     */
    public function item(): string
    {
        if (\App\Util\StoreMaintenance::closed()) {
            \App\Util\StoreMaintenance::responseHeaders();
            return $this->theme("店铺正在维护", "CLOSED", "Index/Closed.html", ['robots' => 'noindex,nofollow']);
        }
        try {
            $item = $this->shop->getItem((int)$_GET['mid'], $this->getUser(), $this->getUserGroup());
        } catch (JSONException $error) {
            if (in_array($error->getMessage(), ['商品不存在', '该商品暂未上架'], true)) return $this->unavailablePage('商品不存在', 404);
            return $this->unavailablePage('商品价格配置异常，暂时无法下单，请联系商家', 503);
        }
        $alternateLocales = \App\Util\PublicSitemap::entityEditionLocales('commodity', $item);
        hook(Hook::USER_API_INDEX_COMMODITY_DETAIL_INFO, $item);

        $item['is_stock'] = $item['stock'] > 0;
        if ($item['inventory_hidden'] == 1) {
            //模糊库存文案直接渲染进模板，就地翻译
            $item['stock'] = lang(match (true) {
                $item['stock'] <= 0 => "已售罄",
                $item['stock'] <= 5 => "所剩无几",
                $item['stock'] <= 20 => "数量有限",
                $item['stock'] <= 100 => "现货充足",
                default => "库存爆棚"
            }, "tpl");
        }

        //商品展示文案统一在控制器出口翻译：主题各自记得加 lang() 是靠不住的，
        //19 个主题里只有 Cartoon 加了一部分(issue #832)。字段清单见 CommodityLang。
        $item = \App\Util\CommodityLang::detail($item);

        $baseUrl = rtrim(\App\Util\Client::getUrl(), '/');
        $cover = (string)($item['cover'] ?? '');
        $ogImage = preg_match('/^https?:\/\//i', $cover) ? $cover : ($cover !== '' ? $baseUrl . '/' . ltrim($cover, '/') : '');
        $itemName = trim(strip_tags((string)$item['name']));

        return $this->theme($itemName, "ITEM", "Index/Item.html", [
            'user' => $this->getUser(),
            'from' => 0,
            "commodityId" => (int)$_GET['mid'],
            'item' => $item,
            "canonical" => $baseUrl . \App\Util\StoreLocale::url('/buy/' . (int)$_GET['mid']),
            "robots" => in_array(\App\Util\StoreLocale::get(), $alternateLocales, true) ? "index,follow,max-image-preview:large" : "noindex,follow",
            'storeAlternateLocales' => $alternateLocales,
            "seoKeywords" => $itemName . ",ChatGPT,Plus,Pro",
            "seoDescription" => $itemName . ' — ' . lang("价格、库存与购买说明。"),
            "ogType" => "product",
            "ogImage" => $ogImage
        ]);
    }

    private function unavailablePage(string $message, int $status): string
    {
        http_response_code($status);
        return $this->theme($message, 'CONTENT', 'Index/Content.html', [
            'page' => ['type' => 'missing', 'title' => $message, 'body' => '', 'missing' => true],
            'entries' => [], 'robots' => 'noindex,nofollow', 'seoDescription' => $message, 'ogType' => 'website',
        ]);
    }

    /**
     * @return string
     * @throws JSONException
     * @throws ViewException
     * @throws \ReflectionException
     */
    public function query(): string
    {
        return $this->theme("订单查询", "QUERY", "Index/Query.html", [
            'user' => $this->getUser(),
            'tradeNo' => (string)($_GET['tradeNo'] ?? ''),
            'robots' => 'noindex,nofollow',
            'seoDescription' => lang('订单状态与发货信息查询页面。')
        ]);
    }
}
