<?php
declare(strict_types=1);

namespace App\Util;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager as DB;

/* Territory-name data from Unicode CLDR 46:
 * UNICODE LICENSE V3
 * 
 * COPYRIGHT AND PERMISSION NOTICE
 * 
 * Copyright © 2004-2024 Unicode, Inc.
 * 
 * NOTICE TO USER: Carefully read the following legal agreement. BY
 * DOWNLOADING, INSTALLING, COPYING OR OTHERWISE USING DATA FILES, AND/OR
 * SOFTWARE, YOU UNEQUIVOCALLY ACCEPT, AND AGREE TO BE BOUND BY, ALL OF THE
 * TERMS AND CONDITIONS OF THIS AGREEMENT. IF YOU DO NOT AGREE, DO NOT
 * DOWNLOAD, INSTALL, COPY, DISTRIBUTE OR USE THE DATA FILES OR SOFTWARE.
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a
 * copy of data files and any associated documentation (the "Data Files") or
 * software and any associated documentation (the "Software") to deal in the
 * Data Files or Software without restriction, including without limitation
 * the rights to use, copy, modify, merge, publish, distribute, and/or sell
 * copies of the Data Files or Software, and to permit persons to whom the
 * Data Files or Software are furnished to do so, provided that either (a)
 * this copyright and permission notice appear with all copies of the Data
 * Files or Software, or (b) this copyright and permission notice appear in
 * associated Documentation.
 * 
 * THE DATA FILES AND SOFTWARE ARE PROVIDED "AS IS", WITHOUT WARRANTY OF ANY
 * KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT OF
 * THIRD PARTY RIGHTS.
 * 
 * IN NO EVENT SHALL THE COPYRIGHT HOLDER OR HOLDERS INCLUDED IN THIS NOTICE
 * BE LIABLE FOR ANY CLAIM, OR ANY SPECIAL INDIRECT OR CONSEQUENTIAL DAMAGES,
 * OR ANY DAMAGES WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS,
 * WHETHER IN AN ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION,
 * ARISING OUT OF OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THE DATA
 * FILES OR SOFTWARE.
 * 
 * Except as contained in this notice, the name of a copyright holder shall
 * not be used in advertising or otherwise to promote the sale, use or other
 * dealings in these Data Files or Software without prior written
 * authorization of the copyright holder.
 * 
 * SPDX-License-Identifier: Unicode-3.0
 */

/** Anonymous public-page counters; never store IP, UA, URL, query or order data. */
final class VisitStatistics
{
    public const COOKIE = 'store_visit_token';
    public const TABLE = 'visit_statistics_minute';
    public const META_TABLE = 'visit_statistics_meta';
    public const TIMEZONE = 'Asia/Shanghai';
    private static bool $recorded = false;
    private const DEVICES = ['pc' => '电脑', 'android' => '安卓手机／平板', 'ios' => '苹果手机／平板', 'other' => '其他设备'];
    // Chinese territory names: Unicode CLDR 46, common/main/zh.xml (Unicode License V3).
    // https://github.com/unicode-org/cldr/blob/release-46/common/main/zh.xml
    private const COUNTRIES = [
        'AC' => '阿森松岛',
        'AD' => '安道尔',
        'AE' => '阿拉伯联合酋长国',
        'AF' => '阿富汗',
        'AG' => '安提瓜和巴布达',
        'AI' => '安圭拉',
        'AL' => '阿尔巴尼亚',
        'AM' => '亚美尼亚',
        'AO' => '安哥拉',
        'AQ' => '南极洲',
        'AR' => '阿根廷',
        'AS' => '美属萨摩亚',
        'AT' => '奥地利',
        'AU' => '澳大利亚',
        'AW' => '阿鲁巴',
        'AX' => '奥兰群岛',
        'AZ' => '阿塞拜疆',
        'BA' => '波斯尼亚和黑塞哥维那',
        'BB' => '巴巴多斯',
        'BD' => '孟加拉国',
        'BE' => '比利时',
        'BF' => '布基纳法索',
        'BG' => '保加利亚',
        'BH' => '巴林',
        'BI' => '布隆迪',
        'BJ' => '贝宁',
        'BL' => '圣巴泰勒米',
        'BM' => '百慕大',
        'BN' => '文莱',
        'BO' => '玻利维亚',
        'BQ' => '荷属加勒比区',
        'BR' => '巴西',
        'BS' => '巴哈马',
        'BT' => '不丹',
        'BV' => '布韦岛',
        'BW' => '博茨瓦纳',
        'BY' => '白俄罗斯',
        'BZ' => '伯利兹',
        'CA' => '加拿大',
        'CC' => '科科斯（基林）群岛',
        'CD' => '刚果（金）',
        'CF' => '中非共和国',
        'CG' => '刚果（布）',
        'CH' => '瑞士',
        'CI' => '科特迪瓦',
        'CK' => '库克群岛',
        'CL' => '智利',
        'CM' => '喀麦隆',
        'CN' => '中国',
        'CO' => '哥伦比亚',
        'CP' => '克利珀顿岛',
        'CR' => '哥斯达黎加',
        'CU' => '古巴',
        'CV' => '佛得角',
        'CW' => '库拉索',
        'CX' => '圣诞岛',
        'CY' => '塞浦路斯',
        'CZ' => '捷克',
        'DE' => '德国',
        'DG' => '迪戈加西亚岛',
        'DJ' => '吉布提',
        'DK' => '丹麦',
        'DM' => '多米尼克',
        'DO' => '多米尼加共和国',
        'DZ' => '阿尔及利亚',
        'EA' => '休达及梅利利亚',
        'EC' => '厄瓜多尔',
        'EE' => '爱沙尼亚',
        'EG' => '埃及',
        'EH' => '西撒哈拉',
        'ER' => '厄立特里亚',
        'ES' => '西班牙',
        'ET' => '埃塞俄比亚',
        'EU' => '欧盟',
        'EZ' => '欧元区',
        'FI' => '芬兰',
        'FJ' => '斐济',
        'FK' => '福克兰群岛',
        'FM' => '密克罗尼西亚',
        'FO' => '法罗群岛',
        'FR' => '法国',
        'GA' => '加蓬',
        'GB' => '英国',
        'GD' => '格林纳达',
        'GE' => '格鲁吉亚',
        'GF' => '法属圭亚那',
        'GG' => '根西岛',
        'GH' => '加纳',
        'GI' => '直布罗陀',
        'GL' => '格陵兰',
        'GM' => '冈比亚',
        'GN' => '几内亚',
        'GP' => '瓜德罗普',
        'GQ' => '赤道几内亚',
        'GR' => '希腊',
        'GS' => '南乔治亚和南桑威奇群岛',
        'GT' => '危地马拉',
        'GU' => '关岛',
        'GW' => '几内亚比绍',
        'GY' => '圭亚那',
        'HK' => '中国香港特别行政区',
        'HM' => '赫德岛和麦克唐纳群岛',
        'HN' => '洪都拉斯',
        'HR' => '克罗地亚',
        'HT' => '海地',
        'HU' => '匈牙利',
        'IC' => '加纳利群岛',
        'ID' => '印度尼西亚',
        'IE' => '爱尔兰',
        'IL' => '以色列',
        'IM' => '马恩岛',
        'IN' => '印度',
        'IO' => '英属印度洋领地',
        'IQ' => '伊拉克',
        'IR' => '伊朗',
        'IS' => '冰岛',
        'IT' => '意大利',
        'JE' => '泽西岛',
        'JM' => '牙买加',
        'JO' => '约旦',
        'JP' => '日本',
        'KE' => '肯尼亚',
        'KG' => '吉尔吉斯斯坦',
        'KH' => '柬埔寨',
        'KI' => '基里巴斯',
        'KM' => '科摩罗',
        'KN' => '圣基茨和尼维斯',
        'KP' => '朝鲜',
        'KR' => '韩国',
        'KW' => '科威特',
        'KY' => '开曼群岛',
        'KZ' => '哈萨克斯坦',
        'LA' => '老挝',
        'LB' => '黎巴嫩',
        'LC' => '圣卢西亚',
        'LI' => '列支敦士登',
        'LK' => '斯里兰卡',
        'LR' => '利比里亚',
        'LS' => '莱索托',
        'LT' => '立陶宛',
        'LU' => '卢森堡',
        'LV' => '拉脱维亚',
        'LY' => '利比亚',
        'MA' => '摩洛哥',
        'MC' => '摩纳哥',
        'MD' => '摩尔多瓦',
        'ME' => '黑山',
        'MF' => '法属圣马丁',
        'MG' => '马达加斯加',
        'MH' => '马绍尔群岛',
        'MK' => '北马其顿',
        'ML' => '马里',
        'MM' => '缅甸',
        'MN' => '蒙古',
        'MO' => '中国澳门特别行政区',
        'MP' => '北马里亚纳群岛',
        'MQ' => '马提尼克',
        'MR' => '毛里塔尼亚',
        'MS' => '蒙特塞拉特',
        'MT' => '马耳他',
        'MU' => '毛里求斯',
        'MV' => '马尔代夫',
        'MW' => '马拉维',
        'MX' => '墨西哥',
        'MY' => '马来西亚',
        'MZ' => '莫桑比克',
        'NA' => '纳米比亚',
        'NC' => '新喀里多尼亚',
        'NE' => '尼日尔',
        'NF' => '诺福克岛',
        'NG' => '尼日利亚',
        'NI' => '尼加拉瓜',
        'NL' => '荷兰',
        'NO' => '挪威',
        'NP' => '尼泊尔',
        'NR' => '瑙鲁',
        'NU' => '纽埃',
        'NZ' => '新西兰',
        'OM' => '阿曼',
        'PA' => '巴拿马',
        'PE' => '秘鲁',
        'PF' => '法属波利尼西亚',
        'PG' => '巴布亚新几内亚',
        'PH' => '菲律宾',
        'PK' => '巴基斯坦',
        'PL' => '波兰',
        'PM' => '圣皮埃尔和密克隆群岛',
        'PN' => '皮特凯恩群岛',
        'PR' => '波多黎各',
        'PS' => '巴勒斯坦领土',
        'PT' => '葡萄牙',
        'PW' => '帕劳',
        'PY' => '巴拉圭',
        'QA' => '卡塔尔',
        'QO' => '大洋洲边远群岛',
        'RE' => '留尼汪',
        'RO' => '罗马尼亚',
        'RS' => '塞尔维亚',
        'RU' => '俄罗斯',
        'RW' => '卢旺达',
        'SA' => '沙特阿拉伯',
        'SB' => '所罗门群岛',
        'SC' => '塞舌尔',
        'SD' => '苏丹',
        'SE' => '瑞典',
        'SG' => '新加坡',
        'SH' => '圣赫勒拿',
        'SI' => '斯洛文尼亚',
        'SJ' => '斯瓦尔巴和扬马延',
        'SK' => '斯洛伐克',
        'SL' => '塞拉利昂',
        'SM' => '圣马力诺',
        'SN' => '塞内加尔',
        'SO' => '索马里',
        'SR' => '苏里南',
        'SS' => '南苏丹',
        'ST' => '圣多美和普林西比',
        'SV' => '萨尔瓦多',
        'SX' => '荷属圣马丁',
        'SY' => '叙利亚',
        'SZ' => '斯威士兰',
        'TA' => '特里斯坦-达库尼亚群岛',
        'TC' => '特克斯和凯科斯群岛',
        'TD' => '乍得',
        'TF' => '法属南部领地',
        'TG' => '多哥',
        'TH' => '泰国',
        'TJ' => '塔吉克斯坦',
        'TK' => '托克劳',
        'TL' => '东帝汶',
        'TM' => '土库曼斯坦',
        'TN' => '突尼斯',
        'TO' => '汤加',
        'TR' => '土耳其',
        'TT' => '特立尼达和多巴哥',
        'TV' => '图瓦卢',
        'TW' => '台湾',
        'TZ' => '坦桑尼亚',
        'UA' => '乌克兰',
        'UG' => '乌干达',
        'UM' => '美国本土外小岛屿',
        'UN' => '联合国',
        'US' => '美国',
        'UY' => '乌拉圭',
        'UZ' => '乌兹别克斯坦',
        'VA' => '梵蒂冈',
        'VC' => '圣文森特和格林纳丁斯',
        'VE' => '委内瑞拉',
        'VG' => '英属维尔京群岛',
        'VI' => '美属维尔京群岛',
        'VN' => '越南',
        'VU' => '瓦努阿图',
        'WF' => '瓦利斯和富图纳',
        'WS' => '萨摩亚',
        'XA' => '伪地区',
        'XB' => '伪双向语言地区',
        'XK' => '科索沃',
        'YE' => '也门',
        'YT' => '马约特',
        'ZA' => '南非',
        'ZM' => '赞比亚',
        'ZW' => '津巴布韦',
        'ZZ' => '未知地区',
    ];

    public static function eligibleRequest(string $uri, string $method = 'GET', int $status = 200): bool
    {
        if ($method !== 'GET' || $status !== 200 || $uri === '' || strlen($uri) > 4096
            || !str_starts_with($uri, '/') || str_starts_with($uri, '//') || preg_match('/[\x00-\x20\\\\]/', $uri)) return false;
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || !preg_match('#^/(?:zh-tw/|en/|ru/|vi/)?(?:index|products|help|sitemap|order|contact)\.html$|^/(?:zh-tw/|en/|ru/|vi/)?(?:category|buy)/[1-9][0-9]*\.html$|^/(?:zh-tw/|en/|ru/|vi/)?help/[a-z0-9][a-z0-9_-]{0,95}\.html$#D', $path)) return false;
        // Empty order lookup is public; a prefilled private lookup is not counted.
        if (preg_match('#/(?:order)\.html$#D', $path)) {
            parse_str((string)(parse_url($uri, PHP_URL_QUERY) ?? ''), $query);
            foreach ($query as $key => $value) {
                if (in_array(strtolower((string)$key), ['tradeno', 'trade_no', 'keywords', 'contact', 'password'], true)
                    && ($value !== '' || !is_string($value))) return false;
            }
        }
        return true;
    }

    public static function isKnownBot(string $ua): bool
    {
        return (bool)preg_match('/bot\b|spider|crawler|slurp|bingpreview|facebookexternalhit|headlesschrome|lighthouse|pingdom|uptimerobot|monitoring|healthcheck|curl\/|wget\/|python-requests|go-http-client/i', $ua);
    }

    public static function isPrefetch(array $server): bool
    {
        return (bool)preg_match('/prefetch|prerender/i', (string)($server['HTTP_SEC_PURPOSE'] ?? '') . ' ' . (string)($server['HTTP_PURPOSE'] ?? ''));
    }

    public static function device(string $ua): string
    {
        if (preg_match('/android/i', $ua)) return 'android';
        if (preg_match('/iphone|ipad|ipod|macintosh.*mobile/i', $ua)) return 'ios';
        if (preg_match('/windows nt|macintosh|x11|linux (?:x86|i[3-6]86)/i', $ua)) return 'pc';
        return 'other';
    }

    public static function validVisitorToken(mixed $token): bool
    {
        return is_string($token) && (bool)preg_match('/^[a-f0-9]{64}$/D', $token);
    }

    public static function visitorKey(string $token, string $secret): string
    {
        if (!self::validVisitorToken($token) || !self::validVisitorToken($secret)) throw new \InvalidArgumentException('匿名访问标识不正确');
        return hash_hmac('sha256', $token, hex2bin($secret));
    }

    public static function countryForPeer(array $server): string
    {
        $country = StoreCountry::lookup($server['REMOTE_ADDR'] ?? '');
        return $country !== '' ? $country : 'ZZ';
    }

    private static function now(): DateTimeImmutable { return new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE)); }

    public static function validateDate(mixed $date, ?DateTimeImmutable $now = null): string
    {
        $now = ($now ?? self::now())->setTimezone(new DateTimeZone(self::TIMEZONE));
        if (!is_string($date) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date)) throw new \InvalidArgumentException('日期格式应为 YYYY-MM-DD');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone(self::TIMEZONE));
        if (!$parsed || $parsed->format('Y-m-d') !== $date || (int)$parsed->format('Y') < 1970 || $date > $now->format('Y-m-d')) throw new \InvalidArgumentException('请选择有效日期，不能选择未来日期');
        return $date;
    }

    public static function comparisonWindow(string $date, DateTimeImmutable $now): array
    {
        $zone = new DateTimeZone(self::TIMEZONE);
        $now = $now->setTimezone($zone);
        self::validateDate($date, $now);
        $start = new DateTimeImmutable($date . ' 00:00:00', $zone);
        $today = $date === $now->format('Y-m-d');
        $end = $today ? $now->setTime((int)$now->format('H'), (int)$now->format('i'), 0) : $start->modify('+1 day');
        return ['current_start' => $start, 'current_end' => $end, 'previous_start' => $start->modify('-1 day'),
            'previous_end' => $end->modify('-1 day'), 'comparison_until' => $today ? $end->format('H:i') : '24:00'];
    }

    private static function started(?string $value): ?DateTimeImmutable
    {
        if ($value === null) return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone(self::TIMEZONE));
        return $date && $date->format('Y-m-d H:i:s') === $value ? $date : null;
    }

    private static function periodStatus(?DateTimeImmutable $started, DateTimeImmutable $from, DateTimeImmutable $until): string
    {
        if ($started === null || $started >= $until) return 'unavailable';
        return $started <= $from ? 'full' : 'partial';
    }

    public static function coverage(string $date, ?string $startedAt, ?DateTimeImmutable $now = null, array $pauseHistory = ['intervals' => [], 'valid' => true]): array
    {
        $now = ($now ?? self::now())->setTimezone(new DateTimeZone(self::TIMEZONE));
        $window = self::comparisonWindow($date, $now);
        $started = self::started($startedAt);
        $currentEnd = $date === $now->format('Y-m-d') ? $now->modify('+1 second') : $window['current_end'];
        $current = self::periodStatus($started, $window['current_start'], $currentEnd);
        $previous = self::periodStatus($started, $window['previous_start'], $window['current_start']);
        $comparison = $started !== null && $started <= $window['previous_start'];
        $hasPauses = !empty($pauseHistory['intervals']) || !($pauseHistory['valid'] ?? false);
        $currentPaused = $hasPauses && $started !== null && VisitCollection::intersects($pauseHistory, max($started, $window['current_start']), $currentEnd);
        $previousPaused = $hasPauses && $started !== null && VisitCollection::intersects($pauseHistory, max($started, $window['previous_start']), $window['current_start']);
        if ($currentPaused && $current !== 'unavailable') $current = 'partial';
        if ($previousPaused && $previous !== 'unavailable') $previous = 'partial';
        if ($hasPauses && $comparison && (VisitCollection::intersects($pauseHistory, $window['current_start'], $window['current_end'])
            || VisitCollection::intersects($pauseHistory, $window['previous_start'], $window['previous_end']))) $comparison = false;
        return ['current' => $current === 'full', 'previous' => $previous === 'full', 'comparison' => $comparison,
            'current_status' => $current, 'previous_status' => $previous,
            'current_paused' => $currentPaused, 'previous_paused' => $previousPaused, 'pause_history_valid' => (bool)($pauseHistory['valid'] ?? false),
            'reason' => $started === null ? 'not_started' : (($currentPaused || $previousPaused) ? 'collection_paused' : ($comparison ? '' : 'insufficient_history'))];
    }

    public static function ready(): bool
    {
        return DB::schema()->hasTable(self::TABLE) && DB::schema()->hasTable(self::META_TABLE);
    }

    private static function metadata(): array
    {
        return DB::table(self::META_TABLE)->whereIn('meta_key', ['started_at', 'hmac_secret', VisitCollection::ENABLED, VisitCollection::CHANGED, VisitCollection::OPEN])->pluck('meta_value', 'meta_key')->all();
    }

    public static function setCollectionEnabled(bool $enabled, ?DateTimeImmutable $now = null): array
    {
        return DB::connection()->transaction(static function () use ($enabled, $now): array {
            // Shared by every collector; a completed disable waits for all older pageviews.
            $barrier = DB::table(self::META_TABLE)->where('meta_key', 'hmac_secret')->lockForUpdate()->first();
            if (!$barrier || !self::validVisitorToken($barrier->meta_value)) throw new \RuntimeException('访问统计尚未初始化');
            DB::table(self::META_TABLE)->insertOrIgnore([
                ['meta_key' => VisitCollection::ENABLED, 'meta_value' => '1'],
                ['meta_key' => VisitCollection::CHANGED, 'meta_value' => ''],
                ['meta_key' => VisitCollection::OPEN, 'meta_value' => ''],
            ]);
            $lock = DB::table(self::META_TABLE)->where('meta_key', VisitCollection::ENABLED)->lockForUpdate()->first();
            if (!$lock || !in_array($lock->meta_value, ['0', '1'], true)) throw new \RuntimeException('统计开关状态不正确');
            $clock = ($now ?? self::now())->setTimezone(new DateTimeZone(self::TIMEZONE));
            $metadata = self::metadata();
            $state = VisitCollection::state($metadata);
            if ($state['enabled'] === $enabled) return $state;
            $stamp = $clock->format('Y-m-d H:i:s');
            if (!$enabled) {
                $key = VisitCollection::PAUSE_PREFIX . $clock->format('YmdHis') . '_' . bin2hex(random_bytes(4));
                $pause = json_encode(['from' => $stamp, 'until' => null], JSON_THROW_ON_ERROR);
                DB::table(self::META_TABLE)->insert(['meta_key' => $key, 'meta_value' => $pause]);
                DB::table(self::META_TABLE)->where('meta_key', VisitCollection::OPEN)->update(['meta_value' => $key]);
            } else {
                $key = (string)($metadata[VisitCollection::OPEN] ?? '');
                if (!VisitCollection::pauseKey($key)) throw new \RuntimeException('暂停记录不完整');
                $row = DB::table(self::META_TABLE)->where('meta_key', $key)->lockForUpdate()->first();
                $pause = $row ? json_decode((string)$row->meta_value, true, 4, JSON_THROW_ON_ERROR) : null;
                if (!is_array($pause) || array_keys($pause) !== ['from', 'until'] || VisitCollection::time($pause['from']) === null || $pause['until'] !== null || $pause['from'] > $stamp) throw new \RuntimeException('暂停记录不正确');
                DB::table(self::META_TABLE)->where('meta_key', $key)->update(['meta_value' => json_encode(['from' => $pause['from'], 'until' => $stamp], JSON_THROW_ON_ERROR)]);
                DB::table(self::META_TABLE)->where('meta_key', VisitCollection::OPEN)->update(['meta_value' => '']);
            }
            DB::table(self::META_TABLE)->where('meta_key', VisitCollection::ENABLED)->update(['meta_value' => $enabled ? '1' : '0']);
            DB::table(self::META_TABLE)->where('meta_key', VisitCollection::CHANGED)->update(['meta_value' => $stamp]);
            $saved = VisitCollection::state(self::metadata());
            if ($saved['enabled'] !== $enabled || $saved['changed_at'] !== $stamp) throw new \RuntimeException('统计开关保存失败');
            return $saved;
        });
    }

    /** Caller invokes only after successful HTML rendering; analytics always fail open. */
    public static function recordCurrentRequest(): void
    {
        if (self::$recorded) return;
        self::$recorded = true;
        try {
            $status = http_response_code() ?: 200;
            if (!self::eligibleRequest((string)($_SERVER['REQUEST_URI'] ?? ''), (string)($_SERVER['REQUEST_METHOD'] ?? ''), $status)
                || self::isKnownBot((string)($_SERVER['HTTP_USER_AGENT'] ?? '')) || self::isPrefetch($_SERVER)) return;
            foreach (headers_list() as $header) {
                if (str_starts_with(strtolower($header), 'content-type:') && !str_contains(strtolower($header), 'text/html')) return;
            }
            DB::connection()->transaction(static function (): void {
                $barrier = DB::table(self::META_TABLE)->where('meta_key', 'hmac_secret')->sharedLock()->first();
                if (!$barrier) return;
                $meta = self::metadata();
                if (!VisitCollection::state($meta)['enabled']) return;
                if (!self::validVisitorToken($meta['hmac_secret'] ?? null) || self::started($meta['started_at'] ?? null) === null) return;
                $token = $_COOKIE[self::COOKIE] ?? null;
                if (!self::validVisitorToken($token)) {
                    if (headers_sent()) return;
                    $token = bin2hex(random_bytes(32));
                    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
                    if (!setcookie(self::COOKIE, $token, ['expires' => time() + 31536000, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax'])) return;
                    $_COOKIE[self::COOKIE] = $token;
                }
                $now = self::now();
                $row = ['stat_date' => $now->format('Y-m-d'), 'stat_hour' => (int)$now->format('H'), 'stat_minute' => (int)$now->format('i'),
                    'visitor_key' => self::visitorKey($token, $meta['hmac_secret']), 'device' => self::device((string)($_SERVER['HTTP_USER_AGENT'] ?? '')),
                    'country' => self::countryForPeer($_SERVER), 'pageviews' => 1, 'first_seen' => $now->format('Y-m-d H:i:s'), 'last_seen' => $now->format('Y-m-d H:i:s')];
                // Illuminate 7 has no upsert(); one bound MySQL 5.7-compatible atomic statement.
                $connection = DB::connection();
                $table = $connection->getQueryGrammar()->wrapTable(self::TABLE);
                $connection->insert('INSERT INTO ' . $table . ' (`stat_date`, `stat_hour`, `stat_minute`, `visitor_key`, `device`, `country`, `pageviews`, `first_seen`, `last_seen`)'
                    . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE `pageviews` = `pageviews` + 1,'
                    . ' `first_seen` = LEAST(`first_seen`, VALUES(`first_seen`)), `last_seen` = GREATEST(`last_seen`, VALUES(`last_seen`))', array_values($row));
                try { VisitSources::recordCurrentRequest($token, $meta['hmac_secret'], $now); }
                catch (\Throwable) { /* Optional origin collection must not affect a successful primary count. */ }
            });
        } catch (\Throwable) { /* Statistics never interrupt a page, order or payment. */ }
    }

    private static function queryFor(string $date, ?int $endMinute = null, bool $includeEnd = false): mixed
    {
        $query = DB::table(self::TABLE)->where('stat_date', $date);
        if ($endMinute !== null) $query->whereRaw('(stat_hour * 60 + stat_minute) ' . ($includeEnd ? '<=' : '<') . ' ?', [$endMinute]);
        return $query;
    }

    private static function totals(string $date, ?int $endMinute = null, bool $includeEnd = false): array
    {
        $row = self::queryFor($date, $endMinute, $includeEnd)->selectRaw('COALESCE(SUM(pageviews), 0) AS pv, COUNT(DISTINCT visitor_key) AS uv')->first();
        return ['pv' => (int)$row->pv, 'uv' => (int)$row->uv];
    }

    public static function countryLabel(string $code): string { return self::COUNTRIES[$code] ?? ($code === 'ZZ' ? '未知地区' : $code); }

    private static function grouped(string $date, string $field, int $total, ?int $endMinute): array
    {
        $rows = self::queryFor($date, $endMinute, true)->select($field)->selectRaw('SUM(pageviews) AS pv, COUNT(DISTINCT visitor_key) AS uv')->groupBy($field)->orderByDesc('pv')->get();
        $result = [];
        foreach ($rows as $row) {
            $key = (string)$row->{$field};
            $result[] = [$field === 'device' ? 'key' : 'code' => $key,
                'label' => $field === 'device' ? (self::DEVICES[$key] ?? '其他设备') : self::countryLabel($key),
                'pv' => (int)$row->pv, 'uv' => (int)$row->uv, 'share' => $total > 0 ? round((int)$row->pv * 100 / $total, 2) : 0.0];
        }
        return $result;
    }

    private static function devices(string $date, ?int $total, ?int $endMinute): array
    {
        $grouped = $total === null ? [] : array_column(self::grouped($date, 'device', $total, $endMinute), null, 'key');
        $result = [];
        foreach (self::DEVICES as $key => $label) {
            $result[] = $grouped[$key] ?? ['key' => $key, 'label' => $label,
                'pv' => $total === null ? null : 0, 'uv' => $total === null ? null : 0, 'share' => $total === null ? null : 0.0];
        }
        return $result;
    }

    public static function report(string $date, ?DateTimeImmutable $now = null): array
    {
        $now = ($now ?? self::now())->setTimezone(new DateTimeZone(self::TIMEZONE));
        self::validateDate($date, $now);
        $window = self::comparisonWindow($date, $now);
        $ready = self::ready();
        $meta = $ready ? self::metadata() : [];
        $collection = VisitCollection::state($meta);
        $pauseRows = $ready ? DB::table(self::META_TABLE)->where('meta_key', 'like', 'collection_pause_%')->pluck('meta_value', 'meta_key')->all() : [];
        $pauseHistory = VisitCollection::history($pauseRows + $meta);
        $startedAt = isset($meta['started_at']) && self::started($meta['started_at']) !== null ? $meta['started_at'] : null;
        $coverage = self::coverage($date, $startedAt, $now, $pauseHistory);
        $previousDate = $window['previous_start']->format('Y-m-d');
        $today = $date === $now->format('Y-m-d');
        $currentMinute = $today ? (int)$now->format('H') * 60 + (int)$now->format('i') : null;
        $cutoff = $today ? $currentMinute : 1440;
        $unknown = ['pv' => null, 'uv' => null];
        $current = $coverage['current_status'] === 'unavailable' ? $unknown : self::totals($date, $currentMinute, true);
        $previous = $coverage['previous_status'] === 'unavailable' ? $unknown : self::totals($previousDate);
        $currentComparison = $coverage['comparison'] ? self::totals($date, $cutoff) : $unknown;
        $previousComparison = $coverage['comparison'] ? self::totals($previousDate, $cutoff) : $unknown;
        $change = static fn(?int $value, ?int $old): ?float => $value === null || $old === null || $old === 0 ? null : round(($value - $old) * 100 / $old, 2);
        $summary = ['pv' => $current['pv'], 'uv' => $current['uv'], 'previous_pv' => $previous['pv'], 'previous_uv' => $previous['uv'],
            'comparison_pv' => $previousComparison['pv'], 'comparison_uv' => $previousComparison['uv'],
            'current_comparison_pv' => $currentComparison['pv'], 'current_comparison_uv' => $currentComparison['uv'],
            'comparison_until' => $window['comparison_until'], 'pv_change_percent' => $change($currentComparison['pv'], $previousComparison['pv']),
            'uv_change_percent' => $change($currentComparison['uv'], $previousComparison['uv'])];
        $started = self::started($startedAt);
        $hourlyMaps = [];
        foreach ([$date, $previousDate] as $i => $day) {
            $status = $i === 0 ? $coverage['current_status'] : $coverage['previous_status'];
            $hourlyMaps[$i] = [];
            if ($status !== 'unavailable') {
                foreach (self::queryFor($day, $i === 0 ? $currentMinute : null, true)->select('stat_hour')->selectRaw('SUM(pageviews) AS pv, COUNT(DISTINCT visitor_key) AS uv')->groupBy('stat_hour')->get() as $row) {
                    $hourlyMaps[$i][(int)$row->stat_hour] = ['pv' => (int)$row->pv, 'uv' => (int)$row->uv];
                }
            }
        }
        $hourly = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $values = [];
            foreach ([$date, $previousDate] as $i => $day) {
                $from = new DateTimeImmutable($day . sprintf(' %02d:00:00', $hour), new DateTimeZone(self::TIMEZONE));
                $until = $from->modify('+1 hour');
                $periodKnown = ($i === 0 ? $coverage['current_status'] : $coverage['previous_status']) !== 'unavailable';
                $available = $periodKnown && $started !== null && $started < $until && ($i !== 0 || !$today || $from <= $now);
                $observedUntil = ($i === 0 && $today) ? min($until, $now->modify('+1 second')) : $until;
                $knownFrom = $started === null ? $from : max($from, $started);
                $paused = $available && VisitCollection::intersects($pauseHistory, $knownFrom, $observedUntil);
                if ($available && !isset($hourlyMaps[$i][$hour]) && VisitCollection::fullyPaused($pauseHistory, $knownFrom, $observedUntil)) $available = false;
                $values['paused_' . $i] = $paused;
                $values[$i] = $available ? ($hourlyMaps[$i][$hour] ?? ['pv' => 0, 'uv' => 0]) : $unknown;
            }
            $hourly[] = ['hour' => $hour, 'label' => sprintf('%02d:00', $hour), 'pv' => $values[0]['pv'], 'uv' => $values[0]['uv'],
                'previous_pv' => $values[1]['pv'], 'previous_uv' => $values[1]['uv'], 'paused' => $values['paused_0'], 'previous_paused' => $values['paused_1']];
        }
        $report = ['date' => $date, 'previous_date' => $previousDate, 'timezone' => self::TIMEZONE, 'started_at' => $startedAt, 'coverage' => $coverage,
            'summary' => $summary, 'hourly' => $hourly, 'collection' => $collection,
            'collection_enabled' => $collection['enabled'], 'collection_changed_at' => $collection['changed_at'],
            'devices' => self::devices($date, $current['pv'], $currentMinute),
            'countries' => $current['pv'] === null ? [] : self::grouped($date, 'country', $current['pv'], $currentMinute)];
        try {
            $sources = VisitSources::report($date, $now);
            $sources['source_coverage'] = self::coverage($date, $sources['sources_started_at'] ?? null, $now, $pauseHistory);
            if (!$sources['source_coverage']['comparison']) {
                foreach (['current_comparison_pv', 'current_comparison_uv', 'comparison_pv', 'comparison_uv', 'pv_change_percent', 'uv_change_percent'] as $field) $sources['source_summary'][$field] = null;
            }
            $report += $sources;
        }
        catch (\Throwable) { /* Compatibility while attribution is being installed or unavailable. */ }
        return $report;
    }
}
