<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Entity\Query\Get;
use App\Interceptor\ManageSession;
use App\Service\Image;
use App\Service\Query;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Util\File;
use App\Util\UploadUtil;

/**
 * Class Upload
 * @package App\Controller\Admin\Api
 */
#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class Upload extends Manage
{
    private const IMAGE_PRESET_PRODUCT_COVER = 'product-cover';

    #[Inject]
    private \App\Service\Upload $upload;

    #[Inject]
    private Query $query;

    #[Inject]
    private Image $image;


    const MIME = ['image', 'video', 'doc', 'other'];


    /**
     * @param Request $request
     * @return array
     * @throws JSONException
     */
    public function send(Request $request): array
    {
        $type = strtolower((string)$request->get("mime"));
        $thumbHeight = (int)$request->get("thumb_height");
        $preset = strtolower(trim((string)$request->get("preset")));

        //前端上传地址永远带 ?mime=；到这里为空基本都是服务器环境把 URL 参数吞了（issue #794）
        if ($type === '') {
            throw new JSONException("上传参数丢失：服务器未收到 mime 参数，请检查伪静态规则(try_files 是否带 \$args)、WAF 或 CDN 是否丢弃了链接参数");
        }

        if ($thumbHeight < 0 || $thumbHeight > 2000) {
            throw new JSONException("缩略图高度不正确，最大支持2000像素");
        }
        if (!in_array($type, self::MIME, true)) {
            throw new JSONException("mime not supported");
        }
        if ($preset !== '' && $preset !== self::IMAGE_PRESET_PRODUCT_COVER) {
            throw new JSONException("图片处理方案不受支持");
        }
        if ($preset === self::IMAGE_PRESET_PRODUCT_COVER && $type !== self::MIME[0]) {
            throw new JSONException("商品主图处理方案仅支持图片");
        }
        $static_path = "/assets/cache/general/{$type}/";
        $handle = $this->upload->handle($_FILES['file'] ?? null, BASE_PATH . $static_path, UploadUtil::extensions($type), UploadUtil::MAX_KIB);
        if (!is_array($handle)) {
            throw new JSONException($handle);
        }

        $fileName = $static_path . $handle['new_name'];

        if ($preset === self::IMAGE_PRESET_PRODUCT_COVER) {
            $optimizedPath = $this->image->optimizeProductIcon($fileName);
            if (!$optimizedPath) {
                File::remove(BASE_PATH . $fileName);
                throw new JSONException("商品图标处理失败，请上传 JPG、PNG 或 WebP 图片");
            }
            $fileName = $optimizedPath;
        }

        if ($tmp = $this->upload->get(md5_file(BASE_PATH . $fileName), 0)) {
            File::remove(BASE_PATH . $fileName);
            $fileName = $tmp;
        } else {
            $existing = $this->upload->add($fileName, $type, 0);
            if ($existing !== null && $existing !== $fileName) {
                File::remove(BASE_PATH . $fileName);
                $fileName = $existing;
            }
        }

        $append = [];
        if ($preset === self::IMAGE_PRESET_PRODUCT_COVER) {
            $append['preset'] = self::IMAGE_PRESET_PRODUCT_COVER;
            $iconSize = getimagesize(BASE_PATH . $fileName);
            $append['width'] = (int)$iconSize[0];
            $append['height'] = (int)$iconSize[1];
            $append['bytes'] = (int)filesize(BASE_PATH . $fileName);
        }
        //生成缩略图
        if ($type == self::MIME[0] && $thumbHeight > 0) {
            $thumbUrl = $this->image->createThumbnail($fileName, $thumbHeight);
            if (!$thumbUrl) {
                throw new JSONException("图片上传失败，原因：生成缩略图失败");
            }
            $append['thumb_url'] = $thumbUrl;
        }

        return $this->json(200, '上传成功', ["url" => $fileName, "append" => $append]);
    }

    /**
     * @param Request $request
     * @return array
     */
    public function get(Request $request): array
    {
        $map = $request->post();
        $get = new Get(\App\Model\Upload::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setWhere($map);
        $get->setOrderBy('id', "desc");
        $data = $this->query->get($get);

        foreach ($data['list'] as &$item) {
            $baseImagePathInfo = pathinfo($item['path']);
            $thumbPath = $baseImagePathInfo['dirname'] . '/thumb/' . $baseImagePathInfo['basename'];
            if (is_file(BASE_PATH . $thumbPath)) {
                $item['thumb_url'] = $thumbPath;
            }
        }

        return $this->json(data: $data);
    }


    /**
     * 文件上传
     * @return array
     * @throws JSONException
     */
    public function handle(): array
    {
        if (!isset($_FILES['file'])) {
            throw new JSONException(UploadUtil::missingFileMessage());
        }

        $handle = $this->upload->handle($_FILES['file'], BASE_PATH . '/assets/cache/images', UploadUtil::IMAGES, UploadUtil::MAX_KIB);
        if (!is_array($handle)) {
            throw new JSONException($handle);
        }

        return $this->json(200, '上传成功', ['path' => '/assets/cache/images/' . $handle['new_name']]);
    }


    /**
     * 获取图像列表
     * @return array
     */
    public function images(): array
    {
        $page = max(1, (int)($_POST['page'] ?? 1));
        $limit = max(1, min(100, (int)($_POST['limit'] ?? 20)));


        $path = BASE_PATH . '/assets/cache/images/';

        $list = is_dir($path) ? (array)scandir($path, SCANDIR_SORT_DESCENDING) : [];
        $list = array_values(array_filter($list, static fn($name) => $name !== '.' && $name !== '..' && is_file($path . $name) && !is_link($path . $name)));

        $ext = UploadUtil::IMAGES;
        foreach ($list as $index => $val) {
            $exp = explode(".", $val);
            if (!in_array(strtolower((string)end($exp)), $ext, true)) {
                unset($list[$index]);
            }
        }

        $list = array_values($list);
        $count = count($list);
        $offset = ($page - 1) * $limit;
        $data = array_slice($list, $offset, $limit);
        $json = $this->json(200, "success", $data);
        $json['count'] = $count;
        return $json;
    }
}
