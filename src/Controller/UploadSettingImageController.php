<?php

namespace Mattoid\PluginScheduler\Controller;

use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Laminas\Diactoros\Response\JsonResponse;
use Mattoid\PluginScheduler\SettingsTargets;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 为定时设置规则上传图片（logo / 站点图标）。
 * 与 core 的 UploadLogoController 处理方式一致，但只把文件写进 assets、
 * 返回相对路径存入规则值，不触碰 logo_path/favicon_path 当前设置——
 * 图片真正生效的时刻由定时规则决定。
 */
class UploadSettingImageController implements RequestHandlerInterface
{
    /**
     * 上传大小上限（字节），超过抛 422，避免超大图片打爆内存/磁盘。
     */
    const MAX_SIZE = 2097152; // 2 MB

    protected $uploadDir;

    protected $imageManager;

    public function __construct(Factory $filesystemFactory, ImageManager $imageManager)
    {
        $this->uploadDir = $filesystemFactory->disk('flarum-assets');
        $this->imageManager = $imageManager;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $key = (string) Arr::get($request->getQueryParams(), 'key', '');
        if (!in_array($key, ['logo_path', 'favicon_path'], true)) {
            throw new ValidationException(['key' => SaveRuleController::trans('unsupported_image_key')]);
        }

        $file = Arr::get($request->getUploadedFiles(), 'file');
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException(['file' => SaveRuleController::trans('invalid_upload')]);
        }

        if ($file->getSize() > static::MAX_SIZE) {
            throw new ValidationException(['file' => SaveRuleController::trans('image_too_large')]);
        }

        try {
            $image = $this->imageManager->make($file->getStream()->getMetadata('uri'));
        } catch (\Throwable $e) {
            // 非图片文件等解析失败转 422，不让 Intervention 的异常冒成 500
            throw new ValidationException(['file' => SaveRuleController::trans('invalid_image')]);
        }

        if ($key === 'logo_path') {
            $image = $image->heighten(60, function ($constraint) {
                $constraint->upsize();
            });
        } else {
            // favicon 与 core 一致缩到 64x64 内
            $image = $image->resize(64, 64, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
        }

        $prefix = $key === 'logo_path' ? 'logo' : 'favicon';
        $uploadName = 'scheduler-'.$prefix.'-'.Str::lower(Str::random(8)).'.png';

        $this->uploadDir->put($uploadName, $image->encode('png'));

        return new JsonResponse(['path' => $uploadName]);
    }
}
