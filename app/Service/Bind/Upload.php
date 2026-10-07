<?php
declare(strict_types=1);

namespace App\Service\Bind;


use Kernel\Util\Date;
use Kernel\Util\File;

class Upload implements \App\Service\Upload
{

    /**
     * New rows use an owner-qualified key under the existing global unique hash index.
     * Old content-MD5 rows remain unchanged and readable by their original owner.
     * A returned path always belongs to the requested owner; callers may discard their new copy.
     */
    public function add(string $path, string $type, ?int $userId = null): ?string
    {
        if (!is_file(BASE_PATH . $path)) return null;
        $owner = max(0, (int)$userId);
        $contentHash = (string)md5_file(BASE_PATH . $path);
        if ($existing = $this->get($contentHash, $owner)) return $existing !== $path ? $existing : null;

        $storedHash = self::ownerHash($contentHash, $owner);
        $upload = new \App\Model\Upload();
        $upload->hash = $storedHash;
        $upload->type = $type;
        $upload->path = $path;
        $upload->create_time = Date::current();
        $upload->user_id = $owner > 0 ? $owner : null;
        try {
            $upload->save();
        } catch (\Throwable $e) {
            if (!self::isDuplicateHash($e)) throw $e;
            if ($existing = $this->get($contentHash, $owner)) return $existing !== $path ? $existing : null;
            // A same-owner scoped record can outlive its missing file. Repair only that record,
            // under a row lock, without changing historical content-MD5 rows or another owner.
            return \App\Model\Upload::query()->getModel()->getConnection()->transaction(function () use ($storedHash, $owner, $path, $type, $e) {
                $query = \App\Model\Upload::query()->where('hash', $storedHash);
                self::restrictOwner($query, $owner);
                $existing = $query->lockForUpdate()->first();
                if (!$existing) throw $e;
                if (is_file(BASE_PATH . $existing->path)) return $existing->path !== $path ? $existing->path : null;
                $existing->path = $path;
                $existing->type = $type;
                $existing->save();
                return null;
            });
        }
        return null;
    }

    private static function ownerHash(string $contentHash, int $owner): string
    {
        return md5('upload-owner-v1:' . $owner . ':' . $contentHash);
    }

    private static function restrictOwner($query, int $owner): void
    {
        if ($owner > 0) {
            $query->where('user_id', $owner);
        } else {
            // Historical administrator uploads use NULL; accept existing zero-owner rows too.
            $query->where(static fn($scope) => $scope->whereNull('user_id')->orWhere('user_id', 0));
        }
    }

    private static function isDuplicateHash(\Throwable $e): bool
    {
        return $e instanceof \Illuminate\Database\QueryException
            && (string)$e->getCode() === '23000'
            && (str_contains($e->getMessage(), '1062') || str_contains($e->getMessage(), 'UNIQUE constraint failed'));
    }

    /** NULL preserves historical global reads; upload/download callers pass explicit 0/admin or user ID. */
    public function get(string $hash, ?int $userId = null): ?string
    {
        $query = \App\Model\Upload::query()->whereIn('hash', [self::ownerHash($hash, max(0, (int)$userId)), $hash]);
        if ($userId !== null) self::restrictOwner($query, max(0, $userId));
        foreach ($query->orderBy('id', 'desc')->get() as $row) {
            // A stale dedup record must never cause deletion of the new, valid upload.
            if (is_file(BASE_PATH . $row->path)) return $row->path;
        }
        return null;
    }

    /** Remove the exact path, never every row with the same content hash. */
    public function remove(string $path, ?int $userId = null): void
    {
        if (!is_file(BASE_PATH . $path)) return;
        $query = \App\Model\Upload::query()->where('path', $path);
        if ($userId !== null) {
            self::restrictOwner($query, max(0, $userId));
            if (!$query->exists()) return;
        }
        $query->delete();
        // An old shared path may still have another owner's record.
        if (\App\Model\Upload::query()->where('path', $path)->exists()) return;
        File::remove(BASE_PATH . $path);
    }

    public function handle($upload, $dir, $type, int $size = 10000, string $fileName = ''): mixed
    {
        if (!is_array($upload)) {
            return \App\Util\UploadUtil::missingFileMessage();
        }

        //单文件处理
        if (count($upload) == count($upload, 1)) {
            $load = self::error($upload, $type, $size);
            if (is_array($load)) {
                //上传文件
                return self::move($load, $dir, $fileName);
            } else {
                return $load;
            }
        } else {
            //多文件初始化
            $list = array();
            //多文件处理
            for ($i = 0; $i < count($upload); $i++) {

                $load = self::error($upload[$i], $type, $size);
                if (is_array($load)) {
                    //上传文件
                    $move = self::move($load, $dir, $fileName);
                    //上传成功加入数组
                    if (is_array($move)) {
                        $list[] = $move;
                    }
                }

            }
            return $list;
        }
    }

    //抛异常
    private static function error($upload, $type, $size)
    {
        $checked = \App\Util\UploadUtil::inspect($upload, $type, $size);
        if (is_array($checked) && !is_uploaded_file($checked['tmp'])) {
            return "上传文件来源不正确";
        }
        return $checked;
    }

    //开始处理文件
    private static function move($array, $dir, $file_name)
    {
        // The configured cache root may be the deployment's persistent-volume symlink.
        // Resolve that trusted root once, but reject symlinks/traversal inside the upload subtree.
        clearstatcache(true);
        $cache = BASE_PATH . '/assets/cache';
        $prefix = $cache . '/';
        $dir = (string)$dir;
        // BASE_PATH itself may contain the kernel's trusted "/../". Validate only
        // the relative upload suffix, after removing its exact configured prefix.
        if (!str_starts_with($dir, $prefix)) return '上传目录不正确';
        $suffix = rtrim(substr($dir, strlen($prefix)), '/');
        if ($suffix === '' || str_starts_with($suffix, '/') || str_contains($suffix, '\\')
            || preg_match('#(?:^|/)(?:\.\.?)(?:/|$)#', $suffix)) {
            return '上传目录不正确';
        }
        if (!is_dir($cache) && (is_link($cache) || (!@mkdir($cache, 0755, true) && !is_dir($cache)))) {
            return '上传失败，服务器无法创建目录';
        }
        $root = realpath($cache);
        if ($root === false) return '上传目录不正确';
        $dir = $root . '/' . $suffix;
        $parent = $dir;
        while (!file_exists($parent) && !is_link($parent)) $parent = dirname($parent);
        $realParent = realpath($parent);
        if ($realParent === false || is_link($parent) || $realParent !== $parent
            || ($realParent !== $root && !str_starts_with($realParent, $root . '/'))) {
            return '上传目录不正确';
        }
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return '上传失败，服务器无法创建目录';
        }
        if (realpath($dir) !== $dir || !is_writable($dir)) {
            return '上传失败，目录无写入权限';
        }
        //随机文件名(CSPRNG)。旧实现是明文时间戳+mt_rand(7位)，上传时刻可推、搜索空间仅 900 万且非密码学随机，
        //公开可读的上传物(头像/封面/凭证)因此可被按时间枚举。与工单上传一致改用 random_bytes。
        $names = bin2hex(random_bytes(16)) . '.' . $array['fix'];
        if ($file_name != '') {
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $file_name)
                || strtolower(pathinfo($file_name, PATHINFO_EXTENSION)) !== $array['fix']) {
                return '上传文件名不正确';
            }
            $names = $file_name;
            $uniqueName = $dir . '/' . $names;
        } else {
            //文件名生成
            $uniqueName = $dir . '/' . $names;
        }
        if (file_exists($uniqueName) || is_link($uniqueName)) return '上传文件已存在';
        if (move_uploaded_file($array['tmp'], $uniqueName)) {
            @chmod($uniqueName, 0644);
            return array('dir' => $uniqueName, 'size' => $array['size'], 'name' => $array['name'], 'new_name' => $names, 'ext' => $array['fix']);
        } else {
            return '文件上传失败';
        }
    }
}
