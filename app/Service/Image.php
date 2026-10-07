<?php
declare (strict_types=1);

namespace App\Service;

use Kernel\Annotation\Bind;

#[Bind(class: \App\Service\Bind\Image::class)]
interface Image
{
    /** Produce a centered transparent square WebP icon, at most maxSize, without upscaling. */
    public function optimizeProductIcon(
        string $imagePath,
        int $maxSize = 512,
        int $quality = 88,
        string $basePath = BASE_PATH
    ): bool|string;

    /**
     * 将商品主图整理为适合首页卡片和商品详情页共用的 6:5 WebP 母图。
     *
     * 主体完整保留在安全区，画布由同图裁切、模糊后铺满，因此不会产生黑边或空白；
     * 首页可继续用 object-fit: cover 裁成 8:5，图片中的标题和卖点仍保持可读。
     *
     * @param string $imagePath 站点根目录下的图片路径
     * @param int $targetWidth
     * @param int $targetHeight
     * @param int $quality WebP 质量（1-100）
     * @param string $basePath
     * @return bool|string 成功时返回处理后的 WebP 路径
     */
    public function optimizeProductCover(
        string $imagePath,
        int $targetWidth = 1200,
        int $targetHeight = 1000,
        int $quality = 88,
        string $basePath = BASE_PATH
    ): bool|string;

    /**
     * 生成缩略图
     * @param string $imagePath
     * @param int $newHeight
     * @param string $basePath
     * @return bool|string
     */
    public function createThumbnail(string $imagePath, int $newHeight, string $basePath = BASE_PATH): bool|string;


    /**
     * @param string $url
     * @param bool $isCreateThumbnail
     * @param int|null $userId
     * @return array
     */
    public function downloadRemoteImage(string $url, bool $isCreateThumbnail = true, ?int $userId = null): array;

    /**
     * @param $url
     * @return bool
     */
    public function isRealImageFromURL($url): bool;


    /**
     * @param string $url
     * @return string
     */
    public function getImageExtensionFromURL(string $url): string;


    /**
     * @param string $filePath
     * @return bool
     */
    public function isRealImage(string $filePath): bool;
}
