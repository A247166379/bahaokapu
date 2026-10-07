<?php
declare(strict_types=1);

namespace App\Service\Bind;

use App\Service\Upload;
use App\Util\Http;
use App\Util\Str;
use GuzzleHttp\Exception\GuzzleException;
use Kernel\Annotation\Inject;
use Kernel\Exception\JSONException;
use Kernel\Util\File;


class Image implements \App\Service\Image
{

    #[Inject]
    private Upload $upload;

    /** Center the complete icon on a transparent square; never enlarge the source pixels. */
    public function optimizeProductIcon(
        string $imagePath,
        int $maxSize = 512,
        int $quality = 88,
        string $basePath = BASE_PATH
    ): bool|string {
        if ($maxSize < 32 || $maxSize > 512 || !function_exists('imagewebp')) return false;
        $diskPath = $basePath . $imagePath;
        $info = @getimagesize($diskPath);
        if (!is_array($info)) return false;
        [$width, $height] = $info;
        if ($width < 1 || $height < 1 || $width > 20000 || $height > 20000 || $width * $height > 40000000) return false;
        $source = $this->openRasterImage($diskPath, (int)($info[2] ?? 0));
        if (!$source) return false;

        // Small icons keep their native resolution instead of being padded into a large canvas.
        $size = min($maxSize, max($width, $height));
        $scale = min(1.0, $size / $width, $size / $height);
        $drawWidth = max(1, (int)round($width * $scale));
        $drawHeight = max(1, (int)round($height * $scale));
        $canvas = imagecreatetruecolor($size, $size);
        if (!$canvas) return false;
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        if (!imagecopyresampled($canvas, $source, (int)floor(($size - $drawWidth) / 2),
            (int)floor(($size - $drawHeight) / 2), 0, 0, $drawWidth, $drawHeight, $width, $height)) return false;

        $pathInfo = pathinfo($diskPath);
        $destination = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '.webp';
        // Only the just-uploaded input may be replaced; preserve an unrelated existing WebP.
        if ($destination !== $diskPath && file_exists($destination)) return false;
        $temporary = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '.icon-' . bin2hex(random_bytes(6)) . '.webp';
        $saved = imagewebp($canvas, $temporary, max(72, min(94, $quality)));
        unset($canvas, $source);
        if (!$saved || !is_file($temporary) || filesize($temporary) < 1) {
            File::remove($temporary);
            return false;
        }
        @chmod($temporary, 0644);
        if (!@rename($temporary, $destination)) {
            File::remove($temporary);
            return false;
        }
        if ($destination !== $diskPath) File::remove($diskPath);
        $directory = rtrim(str_replace('\\', '/', (string)pathinfo($imagePath, PATHINFO_DIRNAME)), '/');
        return ($directory === '' || $directory === '.' ? '' : $directory . '/') . $pathInfo['filename'] . '.webp';
    }

    /**
     * 商品主图采用一张母图兼容两种展示比例：详情页 6:5、首页卡片 8:5。
     * 背景层以 cover 方式铺满并柔化，前景层完整放入中间安全区。这样即便上传的是
     * 带文字的横幅，也不会因首页裁切而丢掉标题，同时不产生传统 contain 的黑边。
     */
    public function optimizeProductCover(
        string $imagePath,
        int $targetWidth = 1200,
        int $targetHeight = 1000,
        int $quality = 88,
        string $basePath = BASE_PATH
    ): bool|string {
        if ($targetWidth < 320 || $targetHeight < 240 || !function_exists('imagewebp')) {
            return false;
        }

        $diskPath = $basePath . $imagePath;
        $imageInfo = @getimagesize($diskPath);
        if (!is_array($imageInfo)) {
            return false;
        }

        [$sourceWidth, $sourceHeight] = $imageInfo;
        if ($sourceWidth < 1 || $sourceHeight < 1) {
            return false;
        }

        $source = $this->openRasterImage($diskPath, (int)($imageInfo[2] ?? 0));
        if (!$source) {
            return false;
        }

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        if (!$canvas) {
            imagedestroy($source);
            return false;
        }

        // 背景层取原图上下边缘的平均色做平滑渐变。它比传统黑色 letterbox 更自然，
        // 也不会把横幅里的文字放大成干扰阅读的“重影”。
        $topColor = $this->averageEdgeColor($source, $sourceWidth, $sourceHeight, true);
        $bottomColor = $this->averageEdgeColor($source, $sourceWidth, $sourceHeight, false);
        $lastRow = max(1, $targetHeight - 1);
        for ($y = 0; $y < $targetHeight; $y++) {
            $progress = $y / $lastRow;
            $red = (int)round($topColor[0] + ($bottomColor[0] - $topColor[0]) * $progress);
            $green = (int)round($topColor[1] + ($bottomColor[1] - $topColor[1]) * $progress);
            $blue = (int)round($topColor[2] + ($bottomColor[2] - $topColor[2]) * $progress);
            $color = imagecolorallocate($canvas, $red, $green, $blue);
            imageline($canvas, 0, $y, $targetWidth, $y, $color);
        }

        // 前景安全区：首页把 6:5 母图裁为 8:5 时只裁上下背景，不会碰到主体。
        $safeWidth = $targetWidth;
        $safeHeight = (int)round($targetHeight * 0.72);
        $foregroundScale = min($safeWidth / $sourceWidth, $safeHeight / $sourceHeight);
        $foregroundWidth = max(1, (int)round($sourceWidth * $foregroundScale));
        $foregroundHeight = max(1, (int)round($sourceHeight * $foregroundScale));
        $foregroundX = (int)floor(($targetWidth - $foregroundWidth) / 2);
        $foregroundY = (int)floor(($targetHeight - $foregroundHeight) / 2);

        if (!imagecopyresampled(
            $canvas,
            $source,
            $foregroundX,
            $foregroundY,
            0,
            0,
            $foregroundWidth,
            $foregroundHeight,
            $sourceWidth,
            $sourceHeight
        )) {
            imagedestroy($canvas);
            imagedestroy($source);
            return false;
        }

        $quality = max(72, min(94, $quality));
        $pathInfo = pathinfo($diskPath);
        $webpDiskPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '.webp';
        $temporaryPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '.cover-' . bin2hex(random_bytes(5)) . '.webp';

        $saved = imagewebp($canvas, $temporaryPath, $quality);
        imagedestroy($canvas);
        imagedestroy($source);

        if (!$saved || !is_file($temporaryPath)) {
            File::remove($temporaryPath);
            return false;
        }

        if (is_file($webpDiskPath) && $webpDiskPath !== $diskPath) {
            File::remove($webpDiskPath);
        }
        if (!@rename($temporaryPath, $webpDiskPath)) {
            File::remove($temporaryPath);
            return false;
        }

        if ($webpDiskPath !== $diskPath) {
            File::remove($diskPath);
        }

        $relativeDirectory = rtrim(str_replace('\\', '/', (string)pathinfo($imagePath, PATHINFO_DIRNAME)), '/');
        return ($relativeDirectory === '' || $relativeDirectory === '.' ? '' : $relativeDirectory . '/')
            . $pathInfo['filename'] . '.webp';
    }

    /**
     * @return \GdImage|resource|false
     */
    private function openRasterImage(string $diskPath, int $imageType): mixed
    {
        return match ($imageType) {
            IMAGETYPE_JPEG => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($diskPath) : false,
            IMAGETYPE_PNG => function_exists('imagecreatefrompng') ? @imagecreatefrompng($diskPath) : false,
            IMAGETYPE_GIF => function_exists('imagecreatefromgif') ? @imagecreatefromgif($diskPath) : false,
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($diskPath) : false,
            IMAGETYPE_BMP => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($diskPath) : false,
            default => false,
        };
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function averageEdgeColor(mixed $image, int $width, int $height, bool $top): array
    {
        $red = 0.0;
        $green = 0.0;
        $blue = 0.0;
        $weightTotal = 0.0;
        $xSamples = min(32, max(2, $width));
        $ySamples = min(4, max(1, $height));

        for ($yi = 0; $yi < $ySamples; $yi++) {
            $edgeProgress = $ySamples === 1 ? 0.0 : $yi / ($ySamples - 1);
            $yRatio = $top ? $edgeProgress * 0.04 : 0.96 + $edgeProgress * 0.04;
            $sampleY = min($height - 1, max(0, (int)round(($height - 1) * $yRatio)));

            for ($xi = 0; $xi < $xSamples; $xi++) {
                $sampleX = min($width - 1, max(0, (int)round(($width - 1) * ($xi / ($xSamples - 1)))));
                $rgba = imagecolorat($image, $sampleX, $sampleY);
                $alpha = ($rgba >> 24) & 0x7F;
                $opacity = 1.0 - ($alpha / 127.0);
                $weight = max(0.08, $opacity);
                $sourceRed = ($rgba >> 16) & 0xFF;
                $sourceGreen = ($rgba >> 8) & 0xFF;
                $sourceBlue = $rgba & 0xFF;

                // 透明像素与站点的浅色画布混合，避免透明 PNG 被解释成黑色。
                $red += ($sourceRed * $opacity + 245 * (1.0 - $opacity)) * $weight;
                $green += ($sourceGreen * $opacity + 247 * (1.0 - $opacity)) * $weight;
                $blue += ($sourceBlue * $opacity + 251 * (1.0 - $opacity)) * $weight;
                $weightTotal += $weight;
            }
        }

        if ($weightTotal <= 0) {
            return [245, 247, 251];
        }

        return [
            max(0, min(255, (int)round($red / $weightTotal))),
            max(0, min(255, (int)round($green / $weightTotal))),
            max(0, min(255, (int)round($blue / $weightTotal))),
        ];
    }

    /**
     * @param string $imagePath
     * @param int $newHeight
     * @param string $basePath
     * @return bool|string
     */
    public function createThumbnail(string $imagePath, int $newHeight, string $basePath = BASE_PATH): bool|string
    {
        if ($newHeight < 1) {
            return $imagePath;
        }

        $baseImagePathInfo = pathinfo($imagePath);
        $thumbPath = $baseImagePathInfo['dirname'] . '/thumb/' . $baseImagePathInfo['basename'];

        if (is_file($basePath . $thumbPath)) {
            return $thumbPath;
        }

        $imageDiskPath = $basePath . $imagePath;

        $imageInfo = @getimagesize($imageDiskPath);
        if (!is_array($imageInfo)) {
            return false;
        }
        [$width, $height] = $imageInfo;
        if ($width < 1 || $height < 1) {
            return false;
        }

        if ($newHeight >= $height) {
            return $imagePath;
        }

        $imageType = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));

        $source = null;
        switch ($imageType) {
            case 'jpg':
            case 'jpeg':
                if (!function_exists('imagecreatefromjpeg') || !function_exists('imagejpeg')) {
                    return $imagePath;
                }
                $source = @imagecreatefromjpeg($imageDiskPath);
                break;
            case 'gif':
                if (!function_exists('imagecreatefromgif') || !function_exists('imagegif')) {
                    return $imagePath;
                }
                $source = @imagecreatefromgif($imageDiskPath);
                break;
            case 'png':
                if (!function_exists('imagecreatefrompng') || !function_exists('imagepng')) {
                    return $imagePath;
                }
                $source = @imagecreatefrompng($imageDiskPath);
                break;
            case 'webp':
                if (!function_exists('imagecreatefromwebp') || !function_exists('imagewebp')) {
                    return $imagePath;
                }
                $source = @imagecreatefromwebp($imageDiskPath);
                break;
            case 'ico':
                return $imagePath;
            default:
                //不支持缩略的类型(如 bmp/tiff)不算失败：与上面缺 GD 扩展、ico 等分支一致返回原图路径。
                //返回 false 会被上传控制器当作「缩略失败」→删除该文件，配合全局去重可删到他人文件。
                return $imagePath;
        }

        if (!$source) {
            return $imagePath;
        }

        $newWidth = max(1, (int)($width / $height * $newHeight));

        $thumb = imagecreatetruecolor($newWidth, $newHeight);
        if (!$thumb) {
            imagedestroy($source);
            return $imagePath;
        }

        if (!imagecopyresampled($thumb, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height)) {
            imagedestroy($thumb);
            imagedestroy($source);
            return $imagePath;
        }

        $pathInfo = pathinfo($imageDiskPath);
        $thumbnailDirectory = $pathInfo['dirname'] . '/thumb/';

        if (!file_exists($thumbnailDirectory)) {
            if (!mkdir($thumbnailDirectory, 0755, true)) {
                imagedestroy($thumb);
                imagedestroy($source);
                return $imagePath;
            }
        }

        $thumbnailPath = $thumbnailDirectory . $pathInfo['basename'];
        switch ($imageType) {
            case 'jpg':
            case 'jpeg':
                if (!imagejpeg($thumb, $thumbnailPath)) {
                    File::remove($thumbnailPath);
                    imagedestroy($thumb);
                    imagedestroy($source);
                    return $imagePath;
                }
                break;
            case 'gif':
                if (!imagegif($thumb, $thumbnailPath)) {
                    File::remove($thumbnailPath);
                    imagedestroy($thumb);
                    imagedestroy($source);
                    return $imagePath;
                }
                break;
            case 'png':
                if (!imagepng($thumb, $thumbnailPath)) {
                    File::remove($thumbnailPath);
                    imagedestroy($thumb);
                    imagedestroy($source);
                    return $imagePath;
                }
                break;
            case 'webp':
                if (!imagewebp($thumb, $thumbnailPath)) {
                    File::remove($thumbnailPath);
                    imagedestroy($thumb);
                    imagedestroy($source);
                    return $imagePath;
                }
                break;
        }

        imagedestroy($thumb);
        imagedestroy($source);

        return $thumbPath;
    }


    /**
     * @param string $filePath
     * @return bool
     */
    public function isRealImage(string $filePath): bool
    {
        $imageInfo = @getimagesize($filePath);
        if ($imageInfo !== false) {
            return true;
        } else {
            return false;
        }
    }

    private function realImageExtension(string $filePath): ?string
    {
        $imageInfo = @getimagesize($filePath);
        if (!is_array($imageInfo)) {
            return null;
        }

        return match ((int)($imageInfo[2] ?? 0)) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_GIF => 'gif',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_WEBP => 'webp',
            IMAGETYPE_ICO => 'ico',
            default => null,
        };
    }

    private function normalizeImageExtension(
        string $imagePath,
        string $actualExtension,
        string $basePath = BASE_PATH
    ): bool|string
    {
        $currentExtension = strtolower((string)pathinfo($imagePath, PATHINFO_EXTENSION));
        $expectedExtension = $currentExtension === 'jpeg' ? 'jpg' : $currentExtension;
        if ($actualExtension === $expectedExtension) {
            return $imagePath;
        }

        $pathInfo = pathinfo($imagePath);
        $normalizedPath = rtrim($pathInfo['dirname'], '/') . '/' . $pathInfo['filename'] . '.' . $actualExtension;
        $normalizedDiskPath = $basePath . $normalizedPath;
        if (is_file($normalizedDiskPath) || !@rename($basePath . $imagePath, $normalizedDiskPath)) {
            return false;
        }
        return $normalizedPath;
    }

    /**
     * @param string $url
     * @return string
     */
    public function getImageExtensionFromURL(string $url): string
    {
        // 解析 URL 获取路径部分
        $path = parse_url($url, PHP_URL_PATH);
        return strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
    }

    /**
     * @param $url
     * @return bool
     * @throws GuzzleException
     */
    public function isRealImageFromURL($url): bool
    {
        $response = Http::make()->head($url, [
            'allow_redirects' => false,
            'connect_timeout' => 5,
            'timeout' => 10,
        ]);
        $mimeType = $response->getHeaderLine('Content-Type');
        $validImageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/x-icon'];
        if (in_array($mimeType, $validImageTypes)) {
            return true;
        }
        return false;
    }

    /**
     * @param string $url
     * @param bool $isCreateThumbnail
     * @param int|null $userId
     * @return array
     * @throws GuzzleException
     * @throws JSONException
     */
    public function downloadRemoteImage(string $url, bool $isCreateThumbnail = true, ?int $userId = null): array
    {
        $extension = $this->getImageExtensionFromURL($url);

        if (!in_array($extension, ['jpg', 'jpeg', 'gif', 'png', 'webp', 'ico'])) {
            throw new JSONException("检测到[$url]不是一张有效的图片");
        }

        $imagePath = "/assets/cache/" . ($userId > 0 ? $userId : "general") . "/image/";
        $unique = $imagePath . date("Y-m-d/") . Str::generateRandStr() . ".{$extension}";

        $dir = dirname(BASE_PATH . $unique);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        try {
            Http::make()->get($url, [
                "sink" => BASE_PATH . $unique,
                'allow_redirects' => false,
                'connect_timeout' => 5,
                'timeout' => 20,
                'progress' => static function (int $downloadTotal, int $downloadedBytes): void {
                    if ($downloadTotal > 10485760 || $downloadedBytes > 10485760) {
                        throw new JSONException('远端图片超过 10MB，已停止下载');
                    }
                },
            ]);
        } catch (\Throwable $throwable) {
            if (is_file(BASE_PATH . $unique)) {
                File::remove(BASE_PATH . $unique);
            }
            if ($throwable instanceof JSONException) {
                throw $throwable;
            }
            throw new JSONException('远端图片下载失败');
        }
        if (!is_file(BASE_PATH . $unique)) {
            throw new JSONException("图片下载失败：$url");
        }

        $actualExtension = $this->realImageExtension(BASE_PATH . $unique);
        if ($actualExtension === null) {
            File::remove(BASE_PATH . $unique);
            throw new JSONException("检测到[{$url}]伪造成一张图片诱导本程序进行远程下载，风险极高，此文件已删除并粉碎！");
        }

        $normalizedUnique = $this->normalizeImageExtension($unique, $actualExtension);
        if ($normalizedUnique === false) {
            File::remove(BASE_PATH . $unique);
            throw new JSONException('远端图片真实格式识别成功，但本地文件格式修正失败');
        }
        $unique = $normalizedUnique;

        $hash = md5_file(BASE_PATH . $unique);
        $cache = $this->upload->get($hash, $userId ?? 0);

        if ($cache && is_file(BASE_PATH . $cache)) {
            File::remove(BASE_PATH . $unique);
            if ($isCreateThumbnail) {
                $baseImagePathInfo = pathinfo($cache);
                $thumbPath = $baseImagePathInfo['dirname'] . '/thumb/' . $baseImagePathInfo['basename'];
                return [$cache, file_exists(BASE_PATH . $thumbPath) ? $thumbPath : $cache];
            }
            return [$cache];
        }

        if ($isCreateThumbnail) {
            $thumbUrl = $this->createThumbnail($unique, 128);
            if (!$thumbUrl) {
                if (is_file(BASE_PATH . $unique)) {
                    File::remove(BASE_PATH . $unique);
                }
                throw new JSONException("缩略图生成失败：{$url}");
            }

            $this->upload->add($unique, "image", $userId);
            return [$unique, $thumbUrl];
        }
        return [$unique];
    }
}
