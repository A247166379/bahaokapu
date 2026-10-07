<?php
declare(strict_types=1);

namespace App\Util;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager as DB;

/** Optional origin attribution. Stores only anonymous counters and normalized DNS hostnames. */
final class VisitSources
{
    public const TABLE = 'visit_sources_minute';
    public const COOKIE = 'store_visit_source';
    public const TTL = 1800;
    private static bool $recorded = false;
    private const LABELS = [
        'google' => 'Google', 'baidu' => '百度', 'bing' => 'Bing', 'sogou' => '搜狗',
        'so360' => '360 搜索', 'yandex' => 'Yandex', 'duckduckgo' => 'DuckDuckGo', 'yahoo' => 'Yahoo',
        'other_search' => '其他搜索引擎', 'referral' => '其他网站', 'direct' => '直接访问／未提供来源', 'unknown' => '无法识别',
    ];
    // Explicit official Google country-domain snapshot, fetched 2026-10-03.
    // https://www.google.com/supported_domains (never use a wildcard google.* rule).
    private const GOOGLE_DOMAINS = [
        'google.ad',
        'google.ae',
        'google.al',
        'google.am',
        'google.as',
        'google.at',
        'google.az',
        'google.ba',
        'google.be',
        'google.bf',
        'google.bg',
        'google.bi',
        'google.bj',
        'google.bs',
        'google.bt',
        'google.by',
        'google.ca',
        'google.cat',
        'google.cd',
        'google.cf',
        'google.cg',
        'google.ch',
        'google.ci',
        'google.cl',
        'google.cm',
        'google.cn',
        'google.co.ao',
        'google.co.bw',
        'google.co.ck',
        'google.co.cr',
        'google.co.id',
        'google.co.il',
        'google.co.in',
        'google.co.jp',
        'google.co.ke',
        'google.co.kr',
        'google.co.ls',
        'google.co.ma',
        'google.co.mz',
        'google.co.nz',
        'google.co.th',
        'google.co.tz',
        'google.co.ug',
        'google.co.uk',
        'google.co.uz',
        'google.co.ve',
        'google.co.vi',
        'google.co.za',
        'google.co.zm',
        'google.co.zw',
        'google.com',
        'google.com.af',
        'google.com.ag',
        'google.com.ar',
        'google.com.au',
        'google.com.bd',
        'google.com.bh',
        'google.com.bn',
        'google.com.bo',
        'google.com.br',
        'google.com.bz',
        'google.com.co',
        'google.com.cu',
        'google.com.cy',
        'google.com.do',
        'google.com.ec',
        'google.com.eg',
        'google.com.et',
        'google.com.fj',
        'google.com.gh',
        'google.com.gi',
        'google.com.gt',
        'google.com.hk',
        'google.com.jm',
        'google.com.kh',
        'google.com.kw',
        'google.com.lb',
        'google.com.ly',
        'google.com.mm',
        'google.com.mt',
        'google.com.mx',
        'google.com.my',
        'google.com.na',
        'google.com.ng',
        'google.com.ni',
        'google.com.np',
        'google.com.om',
        'google.com.pa',
        'google.com.pe',
        'google.com.pg',
        'google.com.ph',
        'google.com.pk',
        'google.com.pr',
        'google.com.py',
        'google.com.qa',
        'google.com.sa',
        'google.com.sb',
        'google.com.sg',
        'google.com.sl',
        'google.com.sv',
        'google.com.tj',
        'google.com.tr',
        'google.com.tw',
        'google.com.ua',
        'google.com.uy',
        'google.com.vc',
        'google.com.vn',
        'google.cv',
        'google.cz',
        'google.de',
        'google.dj',
        'google.dk',
        'google.dm',
        'google.dz',
        'google.ee',
        'google.es',
        'google.fi',
        'google.fm',
        'google.fr',
        'google.ga',
        'google.ge',
        'google.gg',
        'google.gl',
        'google.gm',
        'google.gr',
        'google.gy',
        'google.hn',
        'google.hr',
        'google.ht',
        'google.hu',
        'google.ie',
        'google.im',
        'google.iq',
        'google.is',
        'google.it',
        'google.je',
        'google.jo',
        'google.kg',
        'google.ki',
        'google.kz',
        'google.la',
        'google.li',
        'google.lk',
        'google.lt',
        'google.lu',
        'google.lv',
        'google.md',
        'google.me',
        'google.mg',
        'google.mk',
        'google.ml',
        'google.mn',
        'google.mu',
        'google.mv',
        'google.mw',
        'google.ne',
        'google.nl',
        'google.no',
        'google.nr',
        'google.nu',
        'google.pl',
        'google.pn',
        'google.ps',
        'google.pt',
        'google.ro',
        'google.rs',
        'google.ru',
        'google.rw',
        'google.sc',
        'google.se',
        'google.sh',
        'google.si',
        'google.sk',
        'google.sm',
        'google.sn',
        'google.so',
        'google.sr',
        'google.st',
        'google.td',
        'google.tg',
        'google.tl',
        'google.tm',
        'google.tn',
        'google.to',
        'google.tt',
        'google.vu',
        'google.ws',
    ];
    private const DOMAINS = [
        'baidu' => ['baidu.com'], 'bing' => ['bing.com'], 'sogou' => ['sogou.com'],
        'so360' => ['so.com', 'so.com.cn'],
        'yandex' => ['yandex.com', 'yandex.ru', 'yandex.ua', 'yandex.by', 'yandex.kz', 'yandex.com.tr'],
        'duckduckgo' => ['duckduckgo.com'], 'yahoo' => ['yahoo.com', 'yahoo.co.jp', 'yahoo.co.uk'],
        'other_search' => ['search.brave.com', 'startpage.com', 'ecosia.org', 'qwant.com', 'aol.com', 'ask.com', 'naver.com', 'daum.net', 'search.seznam.cz'],
    ];

    private static function hostname(mixed $host): ?string
    {
        if (!is_string($host) || $host === '' || strlen($host) > 254 || preg_match('/[^a-zA-Z0-9.\-]/', $host)) return null;
        $host = strtolower(rtrim($host, '.'));
        while (str_starts_with($host, 'www.')) $host = substr($host, 4);
        if ($host === '' || strlen($host) > 253 || filter_var($host, FILTER_VALIDATE_IP) || !str_contains($host, '.')) return null;
        $labels = explode('.', $host);
        foreach ($labels as $label) if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $label)) return null;
        if (!preg_match('/[a-z]/', end($labels))) return null;
        return $host;
    }

    private static function ownHostname(string $ownHost): ?string
    {
        if ($ownHost === '' || strlen($ownHost) > 260 || preg_match('/[\x00-\x20\\\\@\/#?]/', $ownHost)) return null;
        $parsed = parse_url('http://' . $ownHost);
        if (!is_array($parsed) || !isset($parsed['host']) || isset($parsed['user'], $parsed['pass']) || (isset($parsed['port']) && ($parsed['port'] < 1 || $parsed['port'] > 65535))) return null;
        $host = strtolower(rtrim((string)$parsed['host'], '.'));
        // Local/isolated same-origin navigation may use an IP, but it is never stored as an external source.
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) || $host === 'localhost') return $host;
        return self::hostname($host);
    }

    private static function matches(string $host, string $base): bool { return $host === $base || str_ends_with($host, '.' . $base); }

    public static function sourceForReferrer(mixed $referer, string $ownHost): array
    {
        $direct = ['key' => 'direct', 'domain' => ''];
        $unknown = ['key' => 'unknown', 'domain' => ''];
        if ($referer === null || $referer === '') return $direct;
        if (!is_string($referer) || strlen($referer) > 4096 || preg_match('/[\x00-\x20\\\\]/', $referer)) return $unknown;
        $parsed = parse_url($referer);
        if (!is_array($parsed) || !isset($parsed['scheme'], $parsed['host']) || !in_array(strtolower($parsed['scheme']), ['http', 'https'], true)
            || isset($parsed['user']) || isset($parsed['pass']) || (isset($parsed['port']) && ($parsed['port'] < 1 || $parsed['port'] > 65535))) return $unknown;
        $rawHost = strtolower(rtrim((string)$parsed['host'], '.'));
        $own = self::ownHostname($ownHost);
        if ($own !== null && $rawHost === $own) return $direct;
        $host = self::hostname($rawHost);
        if ($host === null) return $unknown;
        if ($host === 'gptvip8.com' || ($own !== null && $host === $own)) return $direct;
        foreach (self::GOOGLE_DOMAINS as $base) if (self::matches($host, $base)) return ['key' => 'google', 'domain' => $host];
        foreach (self::DOMAINS as $key => $domains) foreach ($domains as $base) if (self::matches($host, $base)) return ['key' => $key, 'domain' => $host];
        return ['key' => 'referral', 'domain' => $host];
    }

    private static function validSource(array $source): bool
    {
        if (!isset($source['key']) || !is_string($source['key']) || !array_key_exists($source['key'], self::LABELS) || !isset($source['domain']) || !is_string($source['domain'])) return false;
        if (in_array($source['key'], ['direct', 'unknown'], true)) return $source['domain'] === '';
        return self::hostname($source['domain']) === $source['domain'];
    }

    private static function signature(string $body, string $visitorToken, string $secret): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $visitorToken) || !preg_match('/^[a-f0-9]{64}$/D', $secret)) throw new \InvalidArgumentException('匿名来源标识不正确');
        return hash_hmac('sha256', "visit-source-v1\0" . $body . "\0" . $visitorToken, hex2bin($secret));
    }

    public static function sourceCookieEncode(array $source, string $visitorToken, string $secret, int $now): string
    {
        if (!self::validSource($source) || $now < 0) throw new \InvalidArgumentException('来源信息不正确');
        $issued = $source['issued_at'] ?? $now;
        if (!is_int($issued)) throw new \InvalidArgumentException('来源会话时间不正确');
        $expires = $source['expires_at'] ?? ($issued + self::TTL);
        if (!is_int($issued) || !is_int($expires) || $issued < 0 || $issued > $now || $expires - $issued !== self::TTL || $expires <= $now) throw new \InvalidArgumentException('来源会话时间不正确');
        $json = json_encode(['v' => 1, 'key' => $source['key'], 'domain' => $source['domain'], 'issued_at' => $issued, 'expires_at' => $expires], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $body = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        return $body . '.' . self::signature($body, $visitorToken, $secret);
    }

    public static function sourceCookieDecode(mixed $cookie, string $visitorToken, string $secret, int $now): ?array
    {
        try {
            if (!is_string($cookie) || strlen($cookie) > 1024 || $now < 0 || !preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D', $cookie, $match)) return null;
            if (!hash_equals(self::signature($match[1], $visitorToken, $secret), $match[2])) return null;
            $json = base64_decode(strtr($match[1], '-_', '+/'), true);
            if (!is_string($json)) return null;
            $source = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($source) || array_keys($source) !== ['v', 'key', 'domain', 'issued_at', 'expires_at'] || $source['v'] !== 1 || !self::validSource($source)
                || !is_int($source['issued_at']) || !is_int($source['expires_at']) || $source['issued_at'] < 0 || $source['issued_at'] > $now
                || $source['expires_at'] - $source['issued_at'] !== self::TTL || $source['expires_at'] <= $now) return null;
            return ['key' => $source['key'], 'domain' => $source['domain'], 'issued_at' => $source['issued_at'], 'expires_at' => $source['expires_at']];
        } catch (\Throwable) { return null; }
    }

    public static function resolveSource(mixed $referer, mixed $cookie, string $ownHost, string $visitorToken, string $secret, int $now): array
    {
        $source = self::sourceForReferrer($referer, $ownHost);
        if ($source['key'] === 'direct') {
            $previous = self::sourceCookieDecode($cookie, $visitorToken, $secret, $now);
            if ($previous !== null) return $previous;
        }
        return $source + ['issued_at' => $now, 'expires_at' => $now + self::TTL];
    }

    private static function started(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone(VisitStatistics::TIMEZONE));
        return $date && $date->format('Y-m-d H:i:s') === $value ? $value : null;
    }

    /** Called only after the original pageview insert succeeded; every failure is isolated. */
    public static function recordCurrentRequest(string $visitorToken, string $secret, DateTimeImmutable $now): void
    {
        if (self::$recorded) return;
        self::$recorded = true;
        try {
            $started = self::started(DB::table(VisitStatistics::META_TABLE)->where('meta_key', 'sources_started_at')->value('meta_value'));
            $now = $now->setTimezone(new DateTimeZone(VisitStatistics::TIMEZONE));
            if ($started === null || $started > $now->format('Y-m-d H:i:s')) return;
            $source = self::resolveSource($_SERVER['HTTP_REFERER'] ?? null, $_COOKIE[self::COOKIE] ?? null, (string)($_SERVER['HTTP_HOST'] ?? ''), $visitorToken, $secret, $now->getTimestamp());
            $row = [$now->format('Y-m-d'), (int)$now->format('H'), (int)$now->format('i'), VisitStatistics::visitorKey($visitorToken, $secret),
                $source['key'], $source['domain'], 1, $now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')];
            $connection = DB::connection(); $table = $connection->getQueryGrammar()->wrapTable(self::TABLE);
            $connection->insert('INSERT INTO ' . $table . ' (`stat_date`,`stat_hour`,`stat_minute`,`visitor_key`,`source_key`,`source_domain`,`pageviews`,`first_seen`,`last_seen`) VALUES (?,?,?,?,?,?,?,?,?)'
                . ' ON DUPLICATE KEY UPDATE `pageviews`=`pageviews`+1,`first_seen`=LEAST(`first_seen`,VALUES(`first_seen`)),`last_seen`=GREATEST(`last_seen`,VALUES(`last_seen`))', $row);
            $cookie = self::sourceCookieEncode($source, $visitorToken, $secret, $now->getTimestamp());
            $existingCookie = $_COOKIE[self::COOKIE] ?? null;
            if (!headers_sent() && (!is_string($existingCookie) || !hash_equals($cookie, $existingCookie))) {
                $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
                if (setcookie(self::COOKIE, $cookie, ['expires' => $source['expires_at'], 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax'])) $_COOKIE[self::COOKIE] = $cookie;
            }
        } catch (\Throwable) { /* Optional attribution never breaks the original counters or response. */ }
    }

    private static function queryFor(string $date, ?int $endMinute = null, bool $includeEnd = false): mixed
    {
        $query = DB::table(self::TABLE)->where('stat_date', $date);
        if ($endMinute !== null) $query->whereRaw('(stat_hour * 60 + stat_minute) ' . ($includeEnd ? '<=' : '<') . ' ?', [$endMinute]);
        return $query;
    }

    private static function totals(string $date, ?int $endMinute = null, bool $includeEnd = false): array
    {
        $row = self::queryFor($date, $endMinute, $includeEnd)->selectRaw('COALESCE(SUM(pageviews),0) AS pv,COUNT(DISTINCT visitor_key) AS uv')->first();
        return ['pv' => (int)$row->pv, 'uv' => (int)$row->uv];
    }

    private static function emptyReport(string $date, DateTimeImmutable $now): array
    {
        $sources = [];
        foreach (self::LABELS as $key => $label) $sources[] = ['key' => $key, 'label' => $label, 'pv' => null, 'uv' => null, 'share' => null];
        return ['sources_started_at' => null, 'source_coverage' => VisitStatistics::coverage($date, null, $now),
            'source_summary' => ['pv' => null, 'uv' => null, 'previous_pv' => null, 'previous_uv' => null,
                'current_comparison_pv' => null, 'current_comparison_uv' => null, 'comparison_pv' => null, 'comparison_uv' => null,
                'pv_change_percent' => null, 'uv_change_percent' => null, 'comparison_until' => VisitStatistics::comparisonWindow($date, $now)['comparison_until']],
            'sources' => $sources, 'source_domains' => [], 'source_domains_total' => 0];
    }

    public static function report(string $date, ?DateTimeImmutable $now = null): array
    {
        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone(VisitStatistics::TIMEZONE)))->setTimezone(new DateTimeZone(VisitStatistics::TIMEZONE));
        VisitStatistics::validateDate($date, $now);
        $empty = self::emptyReport($date, $now);
        try {
            $started = self::started(DB::table(VisitStatistics::META_TABLE)->where('meta_key', 'sources_started_at')->value('meta_value'));
            if ($started === null) return $empty;
            $coverage = VisitStatistics::coverage($date, $started, $now);
            $window = VisitStatistics::comparisonWindow($date, $now);
            $previousDate = $window['previous_start']->format('Y-m-d');
            $minute = $date === $now->format('Y-m-d') ? (int)$now->format('H') * 60 + (int)$now->format('i') : null;
            $cutoff = $minute ?? 1440;
            $unknown = ['pv' => null, 'uv' => null];
            $current = $coverage['current_status'] === 'unavailable' ? $unknown : self::totals($date, $minute, true);
            $previous = $coverage['previous_status'] === 'unavailable' ? $unknown : self::totals($previousDate);
            $currentComparison = $coverage['comparison'] ? self::totals($date, $cutoff) : $unknown;
            $previousComparison = $coverage['comparison'] ? self::totals($previousDate, $cutoff) : $unknown;
            $change = static fn(?int $value, ?int $old): ?float => $value === null || $old === null || $old === 0 ? null : round(($value - $old) * 100 / $old, 2);
            $summary = ['pv' => $current['pv'], 'uv' => $current['uv'], 'previous_pv' => $previous['pv'], 'previous_uv' => $previous['uv'],
                'current_comparison_pv' => $currentComparison['pv'], 'current_comparison_uv' => $currentComparison['uv'], 'comparison_pv' => $previousComparison['pv'], 'comparison_uv' => $previousComparison['uv'],
                'pv_change_percent' => $change($currentComparison['pv'], $previousComparison['pv']), 'uv_change_percent' => $change($currentComparison['uv'], $previousComparison['uv']), 'comparison_until' => $window['comparison_until']];
            $rows = [];
            if ($current['pv'] !== null) {
                foreach (self::queryFor($date, $minute, true)->select('source_key')->selectRaw('SUM(pageviews) AS pv,COUNT(DISTINCT visitor_key) AS uv')->groupBy('source_key')->get() as $row) $rows[$row->source_key] = $row;
            }
            $sources = [];
            foreach (self::LABELS as $key => $label) {
                $pv = $current['pv'] === null ? null : (isset($rows[$key]) ? (int)$rows[$key]->pv : 0);
                $uv = $current['pv'] === null ? null : (isset($rows[$key]) ? (int)$rows[$key]->uv : 0);
                $sources[] = ['key' => $key, 'label' => $label, 'pv' => $pv, 'uv' => $uv, 'share' => $pv === null ? null : ($current['pv'] > 0 ? round($pv * 100 / $current['pv'], 2) : 0.0)];
            }
            $domains = []; $domainTotal = 0;
            if ($current['pv'] !== null) {
                $domainTotal = self::queryFor($date, $minute, true)->where('source_domain', '<>', '')->distinct()->count('source_domain');
                foreach (self::queryFor($date, $minute, true)->where('source_domain', '<>', '')->select('source_domain', 'source_key')->selectRaw('SUM(pageviews) AS pv,COUNT(DISTINCT visitor_key) AS uv')->groupBy('source_domain', 'source_key')->orderByDesc('pv')->orderBy('source_domain')->limit(50)->get() as $row) {
                    $domains[] = ['domain' => $row->source_domain, 'source_key' => $row->source_key, 'label' => self::LABELS[$row->source_key] ?? self::LABELS['unknown'],
                        'pv' => (int)$row->pv, 'uv' => (int)$row->uv, 'share' => $current['pv'] > 0 ? round((int)$row->pv * 100 / $current['pv'], 2) : 0.0];
                }
            }
            return ['sources_started_at' => $started, 'source_coverage' => $coverage, 'source_summary' => $summary, 'sources' => $sources, 'source_domains' => $domains, 'source_domains_total' => $domainTotal];
        } catch (\Throwable) { return $empty; }
    }
}
