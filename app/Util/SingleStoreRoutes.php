<?php
declare(strict_types=1);

namespace App\Util;

/** The public surface of this single-shop deployment. */
final class SingleStoreRoutes
{
    private const CONTROLLERS = [
        'user/authentication', 'user/captcha', 'user/index', 'user/pay',
        'user/dashboard', 'user/personal', 'user/security',
        'user/api/authentication', 'user/api/index', 'user/api/upload',
        'user/api/purchaserecord', 'user/api/lang',
        'admin/authentication', 'admin/dashboard', 'admin/storecontent',
        'admin/category', 'admin/commodity', 'admin/card', 'admin/order', 'admin/pay', 'admin/lang',
        'admin/api/authentication', 'admin/api/storecontent',
        'admin/api/category', 'admin/api/commodity', 'admin/api/card', 'admin/api/order',
        'admin/api/pay', 'admin/api/lang', 'admin/api/upload', 'admin/api/dict',
    ];

    private const ACTIONS = [
        // The native installation wizard needs these routes before the Lock exists.
        // Controller guards still reject reinstallation and non-POST writes.
        'install' => ['step', 'env', 'rewrite', 'testdatabase', 'submit'],
        'admin/visitstats' => ['index'],
        'admin/api/visitstats' => ['summary', 'collection'],
        'admin/autotranslate' => ['index'],
        'admin/api/autotranslate' => ['settings', 'save'],
        'admin/fxsettings' => ['index'],
        'admin/api/fxsettings' => ['settings', 'save', 'refresh'],
        'user/store' => ['help', 'helparticle', 'contact', 'policy', 'closed', 'sitemap', 'sitemappage'],
        'user/api/order' => ['trade', 'state', 'callback', 'callbacktest'],
        // Historical recharge notifications remain routable; starting a new
        // recharge and the wallet UI are absent from this deployment.
        'user/api/rechargenotification' => ['callback'],
        'user/api/security' => ['personal', 'password', 'email', 'phone', 'emailbindnew', 'phonebindnew'],
        'admin/config' => ['index', 'storefront', 'security', 'email', 'sms'],
        'admin/manage' => ['set'],
        'admin/api/manage' => ['set', 'devicesessions', 'revokedevicesession', 'revokeotherdevicesessions',
            'revokealldevicesessions', 'googlestatus', 'googlesecret', 'googlebind', 'googleunbind'],
        'admin/api/config' => ['setting', 'maintenance', 'security', 'email', 'sms', 'emailtest', 'smstest',
            'requestlogclear', 'cspclear', 'cspallow', 'cspallowremove'],
        'admin/api/app' => ['upgrade'],
        'csp' => ['report'],
    ];

    public static function allows(string $controller, string $action): bool
    {
        $prefix = 'app\\controller\\';
        $controller = strtolower(ltrim($controller, '\\'));
        $action = strtolower($action);
        if (!str_starts_with($controller, $prefix) || !preg_match('/^[a-z][a-z0-9]*$/D', $action)) return false;
        $name = str_replace('\\', '/', substr($controller, strlen($prefix)));
        if (isset(self::ACTIONS[$name])) return in_array($action, self::ACTIONS[$name], true);
        return in_array($name, self::CONTROLLERS, true);
    }
}
