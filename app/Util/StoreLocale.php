<?php
declare(strict_types=1);

namespace App\Util;

/** Locale routing is a presentation boundary; API and payment callback paths stay unchanged. */
final class StoreLocale
{
    public const CODES = ['zh-cn', 'zh-tw', 'en', 'ru', 'vi'];
    public const CHOICE_COOKIE = 'store_locale_choice';
    private static ?string $locale = null;
    private static ?string $path = null;
    private static bool $store = false;

    /** Manual choice wins, then an offline-verified country, then browser language. */
    public static function preferred(mixed $acceptLanguage = '', mixed $choice = '', mixed $country = ''): string
    {
        if (is_string($choice) && in_array(strtolower($choice), self::CODES, true)) return strtolower($choice);
        if (is_string($country) && preg_match('/^[A-Z]{2}$/Di', $country) && !in_array(strtoupper($country), ['XX', 'ZZ'], true)) {
            $regional = match (strtoupper($country)) {
                'CN' => 'zh-cn',
                'HK', 'TW', 'MO' => 'zh-tw',
                'RU', 'UA' => 'ru',
                'VN' => 'vi',
                default => 'en',
            };
            if ($regional !== null) return $regional;
        }
        if (!is_string($acceptLanguage) || strlen($acceptLanguage) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $acceptLanguage)) return 'zh-cn';
        $best = 'zh-cn';
        $bestQuality = 0.0;
        foreach (array_slice(explode(',', $acceptLanguage), 0, 32) as $range) {
            $parts = array_map('trim', explode(';', strtolower($range)));
            $tag = array_shift($parts);
            if (!preg_match('/^[a-z]{1,8}(?:-[a-z0-9]{1,8})*$/D', $tag)) continue;
            $quality = 1.0;
            $seenQuality = false;
            foreach ($parts as $parameter) {
                if ($seenQuality || !preg_match('/^q=(0(?:\.[0-9]{0,3})?|1(?:\.0{0,3})?)$/D', $parameter, $match)) { $quality = 0.0; break; }
                $quality = (float)$match[1];
                $seenQuality = true;
            }
            if ($quality <= $bestQuality) continue;
            $primary = explode('-', $tag)[0];
            $locale = match ($primary) {
                'en' => 'en',
                'ru' => 'ru',
                'vi' => 'vi',
                'zh' => preg_match('/(?:^|-)hant(?:-|$)/D', $tag) ? 'zh-tw'
                    : (preg_match('/(?:^|-)hans(?:-|$)/D', $tag) ? 'zh-cn'
                    : (preg_match('/(?:^|-)(?:tw|hk|mo)(?:-|$)/D', $tag) ? 'zh-tw' : 'zh-cn')),
                default => null,
            };
            if ($locale === null) continue;
            $best = $locale;
            $bestQuality = $quality;
        }
        return $best;
    }

    /** Only the language-neutral bare entry negotiates. Every explicit page, query and machine endpoint stays put. */
    public static function entryRedirect(string $uri, string $method = 'GET', mixed $acceptLanguage = '', mixed $choice = '', mixed $country = ''): ?string
    {
        if ($uri !== '/' || !in_array(strtoupper($method), ['GET', 'HEAD'], true)) return null;
        return self::url('/index.html', self::preferred($acceptLanguage, $choice, $country));
    }

    public static function parse(string $path): array
    {
        $path = '/' . ltrim($path, '/');
        if ($path === '/sitemap.xml') return ['locale' => null, 'path' => $path, 'store' => false];
        if (preg_match('#^/(zh-tw|en|ru|vi)(?=/|$)#D', $path, $m)) {
            $clean = substr($path, strlen($m[0])) ?: '/';
            if ($clean === '/sitemap.xml') return ['locale' => null, 'path' => '/404.html', 'store' => false];
            // Never expose an admin or machine endpoint by adding a storefront prefix.
            if (preg_match('#^/(?:admin|plugin|user/api|user/captcha)(?:/|$)#i', $clean)) {
                return ['locale' => $m[1], 'path' => '/404.html', 'store' => true];
            }
            return ['locale' => $m[1], 'path' => $clean, 'store' => true];
        }
        $isStore = in_array($path, ['/', '/index', '/index.html', '/products.html', '/products'], true) || (bool)preg_match('#^/(?:category|buy|order|help|contact|terms|articles|closed|item|cat|sitemap)(?:/|$|\.)#', $path)
            || (bool)preg_match('#^/user/(?:index|authentication|pay)(?:/|$)#', $path)
            || (bool)preg_match('#^/user/store/(?:help|articles|contact|sitemapPage|closed)/?$#D', $path);
        return ['locale' => $isStore ? 'zh-cn' : null, 'path' => $path, 'store' => $isStore];
    }

    public static function boot(): void
    {
        $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $parsed = self::parse(is_string($path) ? $path : '/');
        self::$path = $parsed['path'];
        self::$store = $parsed['store'];
        $route = is_string($_GET['s'] ?? null) ? '/' . ltrim($_GET['s'], '/') : '';
        $admin = (bool)preg_match('#^/admin(?:/|$)#i', self::$path)
            || (!self::$store && (bool)preg_match('#^/admin(?:/|$)#i', $route));
        if ($admin) {
            self::$store = false;
            // Admin UI is Chinese. Do not change the visitor's storefront language cookie.
            // Leave the explicit request locale unset so delivery services can still
            // temporarily select the purchased order's language and restore it afterwards.
            self::$locale = null;
            \Kernel\Util\Lang::reset('zh-cn');
            return;
        }
        if ($parsed['locale'] !== null) {
            self::$locale = $parsed['locale'];
        } elseif (preg_match('#^/user/(?:api/(?:index|order/(?:trade|check|state)|authentication)|captcha)(?:/|$)#', self::$path)) {
            $requested = strtolower((string)($_GET['lang'] ?? $_SERVER['HTTP_X_STORE_LOCALE'] ?? ''));
            if (in_array($requested, self::CODES, true)) {
                self::$locale = $requested;
            }
        }
        if (self::$locale !== null) {
            \Kernel\Util\Lang::reset(self::$locale);
            // Legacy authentication/account APIs still use the language cookie.
            // The public URL remains authoritative over a previously chosen cookie.
            if (self::$store) {
                $_COOKIE[\Kernel\Util\Lang::COOKIE] = self::$locale;
                if (!headers_sent()) setcookie(\Kernel\Util\Lang::COOKIE, self::$locale, [
                    'expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax',
                    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https',
                ]);
            }
        }
    }

    public static function get(): string
    {
        $lang = self::$locale ?? \Kernel\Util\Lang::get();
        return in_array($lang, self::CODES, true) ? $lang : 'zh-cn';
    }

    public static function useLocale(string $locale): void
    {
        if (!in_array($locale, self::CODES, true)) return;
        self::$locale = $locale;
        \Kernel\Util\Lang::reset($locale);
    }

    public static function prefix(?string $locale = null): string
    {
        $locale ??= self::get();
        return in_array($locale, ['zh-tw', 'en', 'ru', 'vi'], true) ? '/' . $locale : '';
    }

    public static function isStore(): bool { return self::$store; }
    public static function path(): string { return self::$path ?? '/'; }

    /** Canonical addresses are restricted to public presentation pages. */
    public static function publicPath(string $path): string
    {
        $path = rtrim($path, '/') ?: '/';
        if (preg_match('#^/(?:item|buy)/(\d+)(?:\.html)?$#D', $path, $m)) return '/buy/' . $m[1] . '.html';
        if (preg_match('#^/(?:cat|category)/(\d+|recommend)(?:\.html)?$#D', $path, $m)) {
            return $m[1] === 'recommend' ? '/products.html' : '/category/' . $m[1] . '.html';
        }
        if (preg_match('#^/(help|articles|terms)/([a-z0-9][a-z0-9_-]{0,95})(?:\.html)?$#D', $path, $m)) {
            return '/' . ($m[1] === 'articles' ? 'help' : $m[1]) . '/' . $m[2] . '.html';
        }
        return match ($path) {
            '/', '/index', '/index.html', '/user/index/index' => '/index.html',
            '/products', '/products.html' => '/products.html',
            '/order', '/order.html', '/user/index/query' => '/order.html',
            '/help', '/help.html', '/articles', '/articles.html', '/user/store/help', '/user/store/articles' => '/help.html',
            '/contact', '/contact.html', '/user/store/contact' => '/contact.html',
            '/sitemap', '/sitemap.html', '/user/store/sitemapPage' => '/sitemap.html',
            '/closed', '/closed.html', '/user/store/closed' => '/closed.html',
            default => $path,
        };
    }

    /** Only harmless GET/HEAD presentation links may move to a canonical address.
     * Unknown or credential-bearing queries remain compatible without a redirect;
     * order/payment/API endpoints and every POST keep their original request URL.
     */
    public static function canonicalRedirect(string $uri, string $method = 'GET'): ?string
    {
        if (!in_array(strtoupper($method), ['GET', 'HEAD'], true) || !str_starts_with($uri, '/')
            || str_starts_with($uri, '//') || preg_match('/[\x00-\x20\\\\]/', $uri)) return null;
        $parts = parse_url($uri);
        if ($parts === false || isset($parts['host']) || isset($parts['scheme'])) return null;
        $requestPath = (string)($parts['path'] ?? '/');
        $parsed = self::parse($requestPath);
        $path = rtrim($parsed['path'], '/') ?: '/';
        if (!$parsed['store'] || !preg_match('#^(?:/|/index(?:\.html)?|/products(?:\.html)?|/(?:order|help|contact|articles|closed|sitemap)(?:\.html)?|/(?:buy|item)/\d+(?:\.html)?|/(?:cat|category)/(?:\d+|recommend)(?:\.html)?|/(?:help|articles|terms)/[a-z0-9][a-z0-9_-]{0,95}(?:\.html)?)$#D', $path)) return null;
        $query = (string)($parts['query'] ?? '');
        if ($query !== '') {
            parse_str($query, $parameters);
            if (array_diff(array_keys($parameters), ['from', 'price_type', 'race', 'quantity', 'cid'])) return null;
            foreach ($parameters as $value) if (!is_string($value) || strlen($value) > 128) return null;
        }
        // Article bindings own these legacy aliases. Sending them through the
        // suffix redirect first would create an unnecessary second redirect.
        if (preg_match('#^/contact(?:\.html)?$|^/terms/[a-z0-9][a-z0-9_-]{0,95}(?:\.html)?$#D', $path)) return null;
        $destination = self::url($path, $parsed['locale']);
        if ($query !== '') $destination .= '?' . $query;
        return $destination === $uri ? null : $destination;
    }

    public static function url(string $path = '/', ?string $locale = null): string
    {
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//') || preg_match('/[\x00-\x20\\\\]/', $path)) return '/';
        $parts = parse_url($path);
        if ($parts === false || isset($parts['host']) || isset($parts['scheme'])) return '/';
        $clean = self::parse((string)($parts['path'] ?? '/'))['path'];
        if ($clean === '/sitemap.xml' || preg_match('#^/(?:admin|plugin|assets|kernel|user/api|user/captcha|user/pay)(?:/|$)#', $clean)) return $path;
        $clean = self::publicPath($clean);
        return self::prefix($locale) . ($clean === '/' ? (self::prefix($locale) === '' ? '/' : '') : $clean)
            . (isset($parts['query']) ? '?' . $parts['query'] : '') . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }

    /** Localize authored presentation links without touching credentials or machine endpoints. */
    public static function localizePublicLink(string $url, ?string $origin = null, ?string $locale = null): string
    {
        if ($url === '' || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            || str_starts_with($url, '//')) return $url;
        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) return $url;
        $absolute = isset($parts['scheme']) || isset($parts['host']);
        $base = '';
        if ($absolute) {
            if (!isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) return $url;
            if ($origin === null) {
                try { $origin = Client::getUrl(); }
                catch (\Throwable) { return $url; }
            }
            $site = parse_url($origin);
            if ($site === false || !isset($site['scheme'], $site['host']) || isset($site['user']) || isset($site['pass'])
                || !in_array(strtolower($site['scheme']), ['http', 'https'], true)) return $url;
            $port = static fn(array $entry): int => (int)($entry['port'] ?? (strtolower($entry['scheme']) === 'https' ? 443 : 80));
            if (strtolower($parts['scheme']) !== strtolower($site['scheme'])
                || strtolower($parts['host']) !== strtolower($site['host']) || $port($parts) !== $port($site)) return $url;
            $base = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        } elseif (!str_starts_with($url, '/') || isset($parts['scheme'], $parts['host'])) return $url;
        $path = (string)($parts['path'] ?? '/');
        $clean = rtrim(self::parse($path)['path'], '/') ?: '/';
        // Order detail identifiers, callbacks, payment pages and logout actions are excluded.
        if (!preg_match('#^(?:/|/index(?:\.html)?|/products(?:\.html)?|/(?:order|help|contact|articles|closed|sitemap)(?:\.html)?|/(?:buy|item)/\d+(?:\.html)?|/(?:cat|category)/(?:\d+|recommend)(?:\.html)?|/(?:help|articles|terms)/[a-z0-9][a-z0-9_-]{0,95}(?:\.html)?|/user/(?:index/(?:index|query)|store/(?:help|articles|contact|sitemapPage|closed)|authentication/(?:login|register|emailForget|phoneForget)))$#D', $clean)) return $url;
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
            if (array_diff(array_keys($query), ['from', 'price_type', 'race', 'quantity', 'cid'])) return $url;
            foreach ($query as $value) if (!is_string($value) || strlen($value) > 128) return $url;
        }
        $locale ??= self::get();
        if (!in_array($locale, self::CODES, true)) return $url;
        return $base . self::url($path . (isset($parts['query']) ? '?' . $parts['query'] : '')
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : ''), $locale);
    }

    public static function links(): array
    {
        $names = ['zh-cn' => ['简体中文', '简'], 'zh-tw' => ['繁體中文', '繁'], 'en' => ['English', 'EN'], 'ru' => ['Русский', 'RU'], 'vi' => ['Tiếng Việt', 'VI']];
        $path = self::publicPath(self::path());
        // Only harmless display context can appear in a language-switch URL.
        $query = [];
        foreach (['from', 'price_type', 'race', 'quantity'] as $key) {
            $value = $_GET[$key] ?? null;
            if (is_scalar($value) && strlen((string)$value) <= 128) $query[$key] = (string)$value;
        }
        if ($query) $path .= '?' . http_build_query($query);
        $links = [];
        foreach ($names as $code => [$name, $short]) {
            $links[] = ['code' => $code, 'name' => $name, 'short' => $short, 'url' => self::url($path, $code), 'active' => self::get() === $code];
        }
        return $links;
    }

    /** Supported language addresses for the Chinese administration interface. */
    public static function homepages(string $origin): array
    {
        $names = ['zh-cn' => '简体中文', 'zh-tw' => '繁体中文（港台）', 'en' => '英语', 'ru' => '俄语', 'vi' => '越南语'];
        $links = [];
        foreach ($names as $code => $name) {
            $url = self::url('/', $code);
            $links[] = ['code' => $code, 'name' => $name, 'url' => $url, 'address' => rtrim($origin, '/') . $url];
        }
        return $links;
    }

    public static function route(): void
    {
        if (self::path() === '/sitemap.xml') { $_GET['s'] = '/user/store/sitemap'; return; }
        if (self::path() === '/404.html') { $_GET['s'] = '/404.html'; return; }
        if (!self::$store) return;
        $path = self::publicPath(rtrim(self::path(), '/') ?: '/');
        $route = match ($path) {
            '/index.html', '/products.html' => '/user/index/index',
            '/order.html' => '/user/index/query',
            '/help.html' => '/user/store/help',
            '/contact.html' => '/user/store/contact',
            '/sitemap.html' => '/user/store/sitemapPage',
            '/closed.html' => '/user/store/closed',
            default => $path,
        };
        if ($path === '/products.html') $_GET['cid'] = '0';
        if (preg_match('#^/buy/(\d+)\.html$#D', $path, $m)) {
            $route = '/user/index/item'; $_GET['mid'] = $m[1];
        } elseif (preg_match('#^/category/(\d+)\.html$#D', $path, $m)) {
            $route = '/user/index/index'; $_GET['cid'] = $m[1];
        } elseif (preg_match('#^/order/(\d{18})$#D', $path, $m)) {
            $route = '/user/index/query'; $_GET['tradeNo'] = $m[1];
        } elseif (preg_match('#^/terms/([a-z0-9][a-z0-9_-]{0,95})\.html$#D', $path, $m)) {
            $route = '/user/store/policy'; $_GET['slug'] = $m[1];
        } elseif (preg_match('#^/help/([a-z0-9][a-z0-9_-]{0,95})\.html$#D', $path, $m)) {
            $route = '/user/store/helpArticle'; $_GET['slug'] = $m[1];
        }
        $_GET['s'] = $route;
    }
}
