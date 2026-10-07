<?php
declare(strict_types=1);

namespace App\Util;

use Kernel\Exception\JSONException;

/**
 * 助手
 */
class Helper
{
    /**
     * 通用插件
     */
    const TYPE_GENERAL = 0;

    /**
     * 支付扩展
     */
    const TYPE_PAY = 1;

    /**
     * 网站模板
     */
    const TYPE_THEME = 2;

    /**
     * 当前正在渲染的前台主题（由 Base\View\User::theme() 写入 Context）。
     * 商城和账户页面共享当前模板。
     */
    const CURRENT_THEME = "CURRENT_USER_THEME";


    /**
     * 获取主题目录所在的URL地址
     * @throws \ReflectionException
     * @throws JSONException
     */
    public static function themeUrl(string $path, bool $debug = false): string
    {
        $theme = 'LocalStar';
        return "/app/View/User/Theme/" . $theme . "/{$path}?v=" . Theme::getConfig($theme)["info"]["VERSION"] . (!$debug ? "" : "&debug=" . Str::generateRandStr(16));
    }

    /**
     * @param string $key
     * @param int $type
     * @return bool|array
     */
    public static function isInstall(string $key, int $type): bool|array
    {

        $path = match ($type) {
            self::TYPE_GENERAL => BASE_PATH . "/app/Plugin/{$key}",
            self::TYPE_PAY => BASE_PATH . "/app/Pay/{$key}",
            self::TYPE_THEME => BASE_PATH . "/app/View/User/Theme/{$key}",
        };

        if (!is_dir($path)) {
            return false;
        }

        switch ($type) {
            case self::TYPE_GENERAL:
                if (!file_exists($path . "/Config/Info.php")) {
                    return false;
                }
                $config = require($path . "/Config/Info.php");
                if (!is_array($config)) {
                    return false;
                }
                if (!array_key_exists(\App\Consts\Plugin::VERSION, $config)) {
                    return false;
                }
                return $config;
                break;
            case self::TYPE_PAY:
                if (!file_exists($path . "/Config/Info.php")) {
                    return false;
                }
                $config = require($path . "/Config/Info.php");
                if (!is_array($config)) {
                    return false;
                }
                if (!array_key_exists("version", $config)) {
                    return false;
                }
                return $config;
                break;
            case self::TYPE_THEME:
                if (!file_exists($path . "/Config.php")) {
                    return false;
                }
                $namespace = "App\\View\\User\\Theme\\{$key}\\Config";
                if (!interface_exists($namespace)) {
                    return false;
                }
                return $namespace::INFO;
                break;
        }

        return false;
    }
}
