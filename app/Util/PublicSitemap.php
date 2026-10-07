<?php
declare(strict_types=1);

namespace App\Util;

use App\Model\Category;
use App\Model\Commodity;
use App\Model\ContentTranslation;
use Illuminate\Database\Eloquent\Builder;

/** Live public discovery. Reads published records only; never writes schema, orders, cards or settings. */
final class PublicSitemap
{
    public static function generate(string $origin): string
    {
        return self::xml($origin, self::catalog());
    }

    /** The XML and human-readable maps share one live published-page projection. */
    public static function catalog(): array
    {
        $local = static function (Builder $query): void {
            $query->where('shared_id', 0)->orWhereNull('shared_id');
        };
        $categories = Category::query()->where('owner', 0)->where('status', 1)->where('hide', 0)
            ->orderBy('sort')->orderBy('id')->get(['id', 'name', 'pid', 'owner', 'status', 'hide'])->toArray();
        $categoryIds = array_column($categories, 'id');
        $products = $categoryIds === [] ? [] : Commodity::query()->where('owner', 0)->where('status', 1)->where('hide', 0)
            ->where($local)->where(static function (Builder $query): void {
                $query->where('only_user', 0)->orWhereNull('only_user');
            })->whereIn('category_id', $categoryIds)->orderBy('sort')->orderBy('id')
            ->get(['id', 'name', 'description', 'category_id', 'owner', 'status', 'hide', 'shared_id', 'only_user'])->toArray();
        $editions = [];
        if (StoreContentService::ready()) {
            $ids = array_values(array_unique(array_map('strval', array_merge($categoryIds, array_column($products, 'id')))));
            if ($ids !== []) $editions = ContentTranslation::query()->whereIn('entity_type', ['category', 'commodity'])
                ->whereIn('entity_id', $ids)->whereIn('locale', StoreLocale::CODES)->whereIn('status', [1, 2])
                ->whereIn('field', ['name', 'description'])->get(['entity_type', 'entity_id', 'locale', 'field', 'text', 'source_hash', 'status'])->toArray();
        }
        $bundles = [];
        foreach (StoreLocale::CODES as $locale) $bundles[$locale] = StoreContentService::bundle($locale);
        return self::pages($categories, $products, $editions, $bundles);
    }

    /** Pure eligibility projection used by both live generation and acceptance fixtures. */
    public static function pages(array $categories, array $products, array $editions, array $bundles): array
    {
        $pages = [];
        foreach (['/index.html' => 'home', '/products.html' => 'products', '/help.html' => 'help', '/sitemap.html' => 'title'] as $path => $label) {
            $titles = [];
            foreach (StoreLocale::CODES as $locale) $titles[$locale] = self::labels($locale)[$label];
            $pages['fixed:' . $path] = ['path' => $path, 'locales' => StoreLocale::CODES, 'group' => 'basic', 'titles' => $titles];
        }
        $translationMap = [];
        foreach ($editions as $edition) {
            if (!is_array($edition) || !in_array($edition['entity_type'] ?? '', ['category', 'commodity'], true)
                || !in_array($edition['locale'] ?? '', StoreLocale::CODES, true) || !in_array((int)($edition['status'] ?? 0), [1, 2], true)) continue;
            $translationMap[$edition['entity_type']][(string)($edition['entity_id'] ?? '')][$edition['locale']][(string)($edition['field'] ?? '')] = $edition;
        }
        $visibleCategories = [];
        foreach ($categories as $row) {
            if (!is_array($row) || !self::publicRecord($row) || (int)($row['id'] ?? 0) <= 0 || trim((string)($row['name'] ?? '')) === '') continue;
            $visibleCategories[(int)$row['id']] = $row;
        }
        // Public descendants of a private/missing ancestor are not advertised in a crawler index.
        foreach ($visibleCategories as $id => $row) {
            $ancestor = (int)($row['pid'] ?? 0); $seen = [$id => true]; $valid = true;
            while ($ancestor > 0) {
                if (!isset($visibleCategories[$ancestor]) || isset($seen[$ancestor]) || count($seen) > 100) { $valid = false; break; }
                $seen[$ancestor] = true; $ancestor = (int)($visibleCategories[$ancestor]['pid'] ?? 0);
            }
            if (!$valid) unset($visibleCategories[$id]);
        }
        foreach ($visibleCategories as $id => $row) {
            $locales = self::entityLocales('category', $row, ['name'], $translationMap);
            $pages['category:' . $id] = ['path' => '/category/' . $id . '.html', 'locales' => $locales, 'group' => 'categories',
                'titles' => self::entityTitles('category', $row, $locales, $translationMap)];
        }
        foreach ($products as $row) {
            $id = (int)($row['id'] ?? 0);
            if (!is_array($row) || !self::publicRecord($row) || $id <= 0 || !isset($visibleCategories[(int)($row['category_id'] ?? 0)])
                || (int)($row['only_user'] ?? 0) !== 0 || trim((string)($row['name'] ?? '')) === '') continue;
            $fields = trim((string)($row['description'] ?? '')) === '' ? ['name'] : ['name', 'description'];
            $locales = self::entityLocales('commodity', $row, $fields, $translationMap);
            $pages['commodity:' . $id] = ['path' => '/buy/' . $id . '.html', 'locales' => $locales, 'group' => 'products',
                'titles' => self::entityTitles('commodity', $row, $locales, $translationMap)];
        }
        foreach (StoreLocale::CODES as $locale) {
            $bundle = is_array($bundles[$locale] ?? null) ? $bundles[$locale] : [];
            // Contact and policy references resolve to these same articles. Their
            // legacy aliases must never duplicate a canonical page in either map.
            foreach ($bundle['helpArticles'] ?? [] as $entry) {
                    if (!is_array($entry) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,95}$/D', (string)($entry['slug'] ?? ''))
                        || (int)($entry['id'] ?? 0) <= 0 || trim((string)($entry['title'] ?? '')) === '') continue;
                    // Language alternates must identify the same source record. Historical
                    // FAQ/article slug collisions may resolve to different records by language.
                    $key = 'help:' . (int)$entry['id'];
                    $pages[$key] ??= ['path' => '/help/' . $entry['slug'] . '.html', 'locales' => [],
                        'group' => 'articles', 'titles' => []];
                    // Reachable fallback pages remain in the human map, but are
                    // not advertised as complete editions in XML or hreflang.
                    if (($entry['contentLocale'] ?? $locale) !== $locale) {
                        $pages[$key]['fallbacks'][$locale] = (string)$entry['title'];
                        continue;
                    }
                    $pages[$key]['locales'][] = $locale;
                    $pages[$key]['titles'][$locale] = (string)$entry['title'];
            }
        }
        return array_values($pages);
    }

    private static function publicRecord(array $row): bool
    {
        return (int)($row['owner'] ?? -1) === 0 && (int)($row['status'] ?? 0) === 1
            && (int)($row['hide'] ?? 1) === 0 && (int)($row['shared_id'] ?? 0) === 0;
    }

    /** Match a rendered entity page's crawler alternates to the XML edition rules. */
    public static function entityEditionLocales(string $type, array $source): array
    {
        if (!in_array($type, ['category', 'commodity'], true) || (int)($source['id'] ?? 0) <= 0) return ['zh-cn'];
        $fields = $type === 'commodity' && trim((string)($source['description'] ?? '')) !== '' ? ['name', 'description'] : ['name'];
        $map = [];
        if (StoreContentService::ready()) {
            foreach (ContentTranslation::query()->where('entity_type', $type)->where('entity_id', (string)$source['id'])
                ->whereIn('locale', StoreLocale::CODES)->whereIn('status', [1, 2])->whereIn('field', $fields)
                ->get(['locale', 'field', 'text', 'source_hash', 'status'])->toArray() as $edition) {
                $map[$type][(string)$source['id']][$edition['locale']][$edition['field']] = $edition;
            }
        }
        return self::entityLocales($type, $source, $fields, $map);
    }

    private static function entityLocales(string $type, array $source, array $fields, array $translations): array
    {
        $locales = ['zh-cn'];
        foreach (array_diff(StoreLocale::CODES, ['zh-cn']) as $locale) {
            $available = true;
            foreach ($fields as $field) {
                $edition = $translations[$type][(string)$source['id']][$locale][$field] ?? null;
                if (!$edition || trim((string)($edition['text'] ?? '')) === ''
                    || !hash_equals(ContentRules::hash((string)($source[$field] ?? '')), (string)($edition['source_hash'] ?? ''))) { $available = false; break; }
            }
            if ($available) $locales[] = $locale;
        }
        return $locales;
    }

    private static function entityTitles(string $type, array $source, array $locales, array $translations): array
    {
        $titles = ['zh-cn' => (string)$source['name']];
        foreach ($locales as $locale) {
            if ($locale !== 'zh-cn') $titles[$locale] = (string)$translations[$type][(string)$source['id']][$locale]['name']['text'];
        }
        return $titles;
    }

    /** Fixed interface labels only. Published names and article text remain database content. */
    public static function labels(string $locale): array
    {
        return match ($locale) {
            'zh-tw' => ['title' => '網站地圖', 'basic' => '主要頁面', 'home' => '首頁', 'categories' => '商品分類', 'products' => '全部商品',
                'help' => '幫助中心', 'articles' => '幫助文章', 'policies' => '政策與條款', 'contact' => '聯絡我們', 'xml' => 'XML 網站地圖'],
            'en' => ['title' => 'Sitemap', 'basic' => 'Main pages', 'home' => 'Home', 'categories' => 'Product categories', 'products' => 'All products',
                'help' => 'Help center', 'articles' => 'Help articles', 'policies' => 'Policies and terms', 'contact' => 'Contact us', 'xml' => 'XML sitemap'],
            'ru' => ['title' => 'Карта сайта', 'basic' => 'Основные страницы', 'home' => 'Главная', 'categories' => 'Категории товаров', 'products' => 'Все товары',
                'help' => 'Центр помощи', 'articles' => 'Статьи помощи', 'policies' => 'Политики и условия', 'contact' => 'Связаться с нами', 'xml' => 'XML-карта сайта'],
            'vi' => ['title' => 'Sơ đồ trang web', 'basic' => 'Trang chính', 'home' => 'Trang chủ', 'categories' => 'Danh mục sản phẩm', 'products' => 'Tất cả sản phẩm',
                'help' => 'Trung tâm trợ giúp', 'articles' => 'Bài viết trợ giúp', 'policies' => 'Chính sách và điều khoản', 'contact' => 'Liên hệ', 'xml' => 'Sơ đồ trang web XML'],
            default => ['title' => '网站地图', 'basic' => '主要页面', 'home' => '首页', 'categories' => '商品分类', 'products' => '全部商品',
                'help' => '帮助中心', 'articles' => '帮助文章', 'policies' => '政策与条款', 'contact' => '联系我们', 'xml' => 'XML 网站地图'],
        };
    }

    private static function publicPath(string $path): bool
    {
        return (bool)preg_match('#^/(?:index|products|help|sitemap)\\.html$|^/(?:category|buy)/[1-9][0-9]*\\.html$|^/help/[a-z0-9][a-z0-9_-]{0,95}\\.html$#D', $path);
    }

    /** Escape saved names; category menus retain reachable pages with Chinese fallback. */
    public static function html(array $pages, string $locale): string
    {
        if (!in_array($locale, StoreLocale::CODES, true)) $locale = 'zh-cn';
        $labels = self::labels($locale);
        $escape = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $groups = array_fill_keys(['basic', 'categories', 'products', 'articles'], []);
        $seen = [];
        foreach ($pages as $page) {
            $path = (string)($page['path'] ?? '');
            $group = (string)($page['group'] ?? '');
            $hasEdition = in_array($locale, is_array($page['locales'] ?? null) ? $page['locales'] : [], true);
            // The human category menu follows the reachable page: an absent
            // edition shows its saved Chinese name. XML/hreflang eligibility
            // remains strict so an untranslated page is not advertised as an
            // available language edition to search engines.
            $categoryFallback = $group === 'categories' && (bool)preg_match('#^/category/[1-9][0-9]*\\.html$#D', $path)
                && trim((string)($page['titles']['zh-cn'] ?? '')) !== '';
            $articleFallback = $locale === 'vi' && $group === 'articles'
                && trim((string)($page['fallbacks'][$locale] ?? '')) !== '';
            if (!self::publicPath($path) || (!$hasEdition && !$categoryFallback && !$articleFallback) || isset($seen[$path])) continue;
            $title = (string)($hasEdition ? ($page['titles'][$locale] ?? '')
                : ($articleFallback ? $page['fallbacks'][$locale] : $page['titles']['zh-cn']));
            if (!array_key_exists($group, $groups) || trim($title) === '') continue;
            $groups[$group][] = '<li><a href="' . $escape(StoreLocale::url($path, $locale)) . '">' . $escape($title) . '</a></li>';
            $seen[$path] = true;
        }
        $html = '<div class="store-sitemap-links" data-store-sitemap-links>';
        foreach ($groups as $key => $items) {
            if ($items === []) continue;
            $html .= '<section class="store-sitemap-group"><h2>' . $escape($labels[$key]) . '</h2><ul>' . implode('', $items) . '</ul></section>';
        }
        return $html . '</div><p class="store-sitemap-xml"><a href="/sitemap.xml">' . $escape($labels['xml']) . '</a></p>';
    }

    /** XML contains only canonical allowlisted page URLs, without private query strings. */
    public static function xml(string $origin, array $pages): string
    {
        $origin = rtrim($origin, '/'); $parts = parse_url($origin);
        if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !empty($parts['path'])) throw new \InvalidArgumentException('Invalid sitemap origin');
        $escape = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
        $seen = [];
        foreach ($pages as $page) {
            $path = (string)($page['path'] ?? '');
            if (!self::publicPath($path)) continue;
            $locales = array_values(array_intersect(StoreLocale::CODES, is_array($page['locales'] ?? null) ? $page['locales'] : []));
            foreach ($locales as $locale) {
                $url = $origin . StoreLocale::url($path, $locale);
                if (isset($seen[$url]) || count($seen) >= 50000) continue;
                $seen[$url] = true;
                $xml .= '  <url><loc>' . $escape($url) . '</loc>';
                foreach ($locales as $alternate) $xml .= '<xhtml:link rel="alternate" hreflang="' . $escape($alternate) . '" href="' . $escape($origin . StoreLocale::url($path, $alternate)) . '"/>';
                if (in_array('zh-cn', $locales, true)) $xml .= '<xhtml:link rel="alternate" hreflang="x-default" href="' . $escape($origin . StoreLocale::url($path, 'zh-cn')) . '"/>';
                $xml .= "</url>\n";
            }
        }
        return $xml . '</urlset>' . "\n";
    }
}
