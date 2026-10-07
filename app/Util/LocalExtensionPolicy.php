<?php
declare(strict_types=1);

namespace App\Util;

use App\Consts\Manage;
use Kernel\Exception\JSONException;

/** Keeps this site's installed extensions under local release control. */
final class LocalExtensionPolicy
{
    public static function installedDirectory(string $key, int $type): ?string
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $key) || !in_array($type, [0, 1, 2], true)) {
            return null;
        }
        $relative = match ($type) {
            0 => '/app/Plugin',
            1 => '/app/Pay',
            2 => '/app/View/User/Theme',
        };
        $root = realpath(BASE_PATH . $relative);
        if ($root === false) return null;
        $candidate = $root . DIRECTORY_SEPARATOR . $key;
        if (is_link($candidate)) return null;
        $directory = realpath($candidate);
        if ($directory === false || !is_dir($directory) || dirname($directory) !== $root || basename($directory) !== $key) {
            return null;
        }
        $metadata = $directory . ($type === 2 ? '/Config.php' : '/Config/Info.php');
        if (!is_file($metadata) || is_link($metadata)) return null;
        // Payment registration must describe an implementation actually on disk.
        if ($type === 1 && (!is_file($directory . '/Impl/Pay.php') || is_link($directory . '/Impl/Pay.php'))) {
            return null;
        }
        foreach ([$metadata, ...($type === 1 ? [$directory . '/Impl/Pay.php'] : [])] as $file) {
            $resolved = realpath($file);
            if ($resolved === false || !str_starts_with($resolved, $directory . DIRECTORY_SEPARATOR)) return null;
        }
        return $directory;
    }

    public static function assertInstall(string $key, int $type): void
    {
        throw new JSONException('本站已关闭扩展安装，请通过站点维护部署更新');
    }

    public static function assertUpgrade(string $key, int $type): void
    {
        $owner = Context::get(Manage::SESSION);
        if (!is_object($owner) || !isset($owner->type) || (int)$owner->type !== 0) {
            throw new JSONException('仅站长可以更新已安装的支付插件');
        }
        // Custom themes and general extensions are released with the application.
        if ($type !== 1 || self::installedDirectory($key, $type) === null) {
            throw new JSONException('仅允许更新本站已安装的支付插件');
        }
    }

    public static function assertUninstall(string $key, int $type): void
    {
        throw new JSONException('本站已关闭扩展卸载，请通过站点维护部署清理');
    }

    public static function assertCoreUpgrade(): void
    {
        throw new JSONException('本站已关闭远程整站更新，请通过站点维护部署更新');
    }
}
