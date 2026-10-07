<?php
declare(strict_types=1);

namespace App\Util;

/**
 * 全站客服浮窗。
 *
 * 浮窗在前台统一渲染出口注入，避免每个主题、每张页面各复制一份；这样切换
 * 商城模板或会员中心模板后，客服入口仍然存在。组件没有内联脚本，手机端利用
 * checkbox/label 原生交互展开，不会被 CSP 拦截。
 */
final class CustomerServiceWidget
{
    private const STYLESHEET = '/assets/user/css/customer-service-widget.css?v=1.0.3';

    public static function inject(string $html, array $config): string
    {
        if ((int)($config['contact_widget_enabled'] ?? 0) !== 1
            || str_contains($html, 'data-customer-service-widget')) {
            return $html;
        }

        $widget = self::render($config);
        $bodyEnd = strripos($html, '</body>');
        if ($bodyEnd === false) {
            return $html . $widget;
        }

        return substr($html, 0, $bodyEnd) . $widget . substr($html, $bodyEnd);
    }

    private static function render(array $config): string
    {
        $title = self::configLabel($config, 'contact_widget_title', '联系客服');
        $name = self::configLabel($config, 'contact_widget_name', '在线客服');
        $tip = self::configLabel($config, 'contact_widget_tip', '扫码添加客服微信');
        $wechat = self::text((string)($config['contact_widget_wechat'] ?? ''));
        $qrcode = self::url((string)($config['contact_widget_qrcode'] ?? ''));
        $link = self::url((string)($config['contact_widget_link'] ?? ''));

        $qrMarkup = '<div class="cs-widget__qr-placeholder" aria-hidden="true">'
            . '<span class="cs-widget__qr-placeholder-icon"></span>'
            . '<small>' . self::label('请在后台上传二维码') . '</small></div>';
        if ($qrcode !== '') {
            $qrMarkup = '<a class="cs-widget__qr-link" href="' . $qrcode
                . '" target="_blank" rel="noopener noreferrer" title="' . self::label('查看客服二维码原图') . '">'
                . '<img class="cs-widget__qr" src="' . $qrcode . '" alt="' . $name
                . ' ' . self::label('微信二维码') . '" loading="lazy" decoding="async"></a>';
        }

        $wechatMarkup = '';
        if ($wechat !== '') {
            $wechatMarkup = '<div class="cs-widget__wechat"><span>' . self::label('微信号') . '</span><strong>'
                . $wechat . '</strong></div>';
        }

        $linkMarkup = '';
        if ($link !== '') {
            $linkMarkup = '<a class="cs-widget__action" href="' . $link
                . '" target="_blank" rel="noopener noreferrer">' . self::label('立即联系') . '</a>';
        }

        return "\n<link rel=\"stylesheet\" href=\"" . self::STYLESHEET . "\" data-customer-service-widget-style>\n"
            . "<aside class=\"cs-widget\" data-customer-service-widget>\n"
            . "  <input class=\"cs-widget__toggle\" type=\"checkbox\" id=\"cs-widget-toggle\" aria-hidden=\"true\">\n"
            . '  <label class="cs-widget__summary" for="cs-widget-toggle" aria-label="' . $title . "\">\n"
            . "    <span class=\"cs-widget__summary-icon\" aria-hidden=\"true\"></span>\n"
            . '    <span>' . $title . "</span>\n"
            . "  </label>\n"
            . '  <section class="cs-widget__panel" aria-label="' . $title . "\">\n"
            . '    <div class="cs-widget__head"><span class="cs-widget__status"></span><div><small>' . self::label('在线客服') . '</small><h2>'
            . $title . "</h2></div></div>\n"
            . '    ' . $qrMarkup . "\n"
            . '    <p class="cs-widget__tip">' . $tip . "</p>\n"
            . '    ' . $wechatMarkup . "\n"
            . '    ' . $linkMarkup . "\n"
            . "  </section>\n"
            . "</aside>\n";
    }

    private static function text(string $value, string $fallback = ''): string
    {
        $value = trim($value);
        if ($value === '') {
            $value = $fallback;
        }
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function label(string $value, string $fallback = ''): string
    {
        $value = trim($value) === '' ? $fallback : trim($value);
        return self::text(\Kernel\Util\Lang::trans($value, 'dyn'));
    }

    private static function configLabel(array $config, string $field, string $fallback): string
    {
        $source = (string)(\App\Model\Config::get($field) ?? '');
        $manual = StoreContentService::entityValue('config', 'site', $field, $source);
        if ($manual !== null) return self::text($manual);
        return self::label((string)($config[$field] ?? $source), $fallback);
    }

    private static function url(string $value): string
    {
        $value = ViewSafe::url(trim($value));
        return $value === '' ? '' : htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
