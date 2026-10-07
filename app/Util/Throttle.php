<?php
declare(strict_types=1);

namespace App\Util;

/**
 * 轻量限流器（服务端计数，按 key 独立窗口）。
 * 基于文件缓存实现，无需 Redis；用于挡住未登录接口的暴力枚举/爆破
 * （如卡密查询密码爆破、后台登录爆破）。计数存于项目内 runtime/throttle，
 * 不含任何敏感信息，且受 nginx /runtime 拦截保护。
 */
class Throttle
{
    /**
     * 记一次访问并判断是否已超过窗口内允许的次数。
     * @param string $key 唯一标识，如 "secret:{tradeNo}:{ip}"
     * @param int $limit 窗口内允许的最大次数
     * @param int $window 窗口秒数
     * @return bool true=已超限（调用方应拦截）
     */
    public static function tooMany(string $key, int $limit, int $window): bool
    {
        try {
            return self::locked($key, static function ($file) use ($limit, $window): bool {
                // 读取、过期判断、计数和写入必须使用同一把锁，避免并发丢失计数。
                $now = time();
                $count = 0;
                $reset = $now + $window;
                $contents = stream_get_contents($file, 257);
                if ($contents === false || strlen($contents) > 256) {
                    throw new \RuntimeException('Invalid throttle record');
                }

                if ($contents !== '') {
                    $decoded = base64_decode($contents, true);
                    $record = $decoded === false ? null : json_decode($decoded, true);
                    if (!is_array($record) || !isset($record['c'], $record['r'])
                        || !is_int($record['c']) || $record['c'] < 0 || !is_int($record['r'])) {
                        throw new \RuntimeException('Invalid throttle record');
                    }
                    if ($record['r'] > $now) {
                        $count = $record['c'];
                        $reset = $record['r'];
                    }
                }

                if ($count === PHP_INT_MAX || !is_int($reset)) {
                    throw new \RuntimeException('Throttle counter overflow');
                }
                $count++;
                // 保留旧版 md5 文件名和 base64 JSON 格式，升级不会清空现有窗口。
                $contents = base64_encode(json_encode(['c' => $count, 'r' => $reset], JSON_THROW_ON_ERROR));
                if (!rewind($file)) {
                    throw new \RuntimeException('Cannot rewind throttle record');
                }
                $offset = 0;
                $length = strlen($contents);
                while ($offset < $length) {
                    $written = fwrite($file, substr($contents, $offset));
                    if ($written === false || $written === 0) {
                        throw new \RuntimeException('Cannot write throttle record');
                    }
                    $offset += $written;
                }
                if (!ftruncate($file, $length) || !fflush($file)) {
                    throw new \RuntimeException('Cannot persist throttle record');
                }
                return $count > $limit;
            });
        } catch (\Throwable $e) {
            // 存储故障时不能放开受保护接口；日志不包含联系方式、IP 或查询密码。
            error_log('[Throttle] counter unavailable; request blocked');
            return true;
        }
    }

    /**
     * 清除某个 key 的计数（如登录/验证成功后重置）。
     * @param string $key
     */
    public static function clear(string $key): void
    {
        try {
            self::locked($key, static function ($file): void {
                // 保留锁所在的 inode，避免等待旧锁的请求与新文件各自计数。
                if (!ftruncate($file, 0) || !fflush($file)) {
                    throw new \RuntimeException('Cannot reset throttle record');
                }
            });
        } catch (\Throwable $e) {
            error_log('[Throttle] counter reset unavailable');
        }
    }

    /** 使用稳定的键文件锁；runtime 父目录可沿用部署时的软链。 */
    private static function locked(string $key, callable $operation): mixed
    {
        $directory = BASE_PATH . '/runtime/throttle';
        if (!is_dir($directory) && !@mkdir($directory, 0755, true)) {
            // 首次并发访问时，另一个进程可能已经创建目录。
            clearstatcache(true, $directory);
            if (!is_dir($directory)) {
                throw new \RuntimeException('Cannot create throttle directory');
            }
        }
        $path = $directory . '/' . md5($key);
        clearstatcache(true, $path);
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new \RuntimeException('Invalid throttle file');
        }
        $file = @fopen($path, 'c+b');
        if ($file === false) {
            throw new \RuntimeException('Cannot open throttle file');
        }
        try {
            if (!flock($file, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock throttle file');
            }
            clearstatcache(true, $path);
            $opened = fstat($file);
            $current = @lstat($path);
            if ($opened === false || $current === false || ($opened['mode'] & 0170000) !== 0100000
                || ($current['mode'] & 0170000) !== 0100000
                || $opened['dev'] !== $current['dev'] || $opened['ino'] !== $current['ino']) {
                throw new \RuntimeException('Throttle file changed');
            }
            return $operation($file);
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }
}
