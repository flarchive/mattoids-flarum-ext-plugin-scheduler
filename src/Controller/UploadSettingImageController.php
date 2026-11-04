<?php

namespace Mattoid\PluginScheduler\Controller;

use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Laminas\Diactoros\Response\JsonResponse;
use Mattoid\PluginScheduler\Model\ScheduleRule;
use Mattoid\PluginScheduler\SchedulerImage;
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

    /**
     * 像素尺寸上限：Intervention 解码前先用 getimagesize 拦截超高分辨率图片，
     * 防 2MB 高压缩 PNG 解码成 GB 级位图的解压炸弹。
     */
    const MAX_DIMENSION = 10000;

    protected $uploadDir;

    protected $imageManager;

    protected $settings;

    public function __construct(Factory $filesystemFactory, ImageManager $imageManager, SettingsRepositoryInterface $settings)
    {
        $this->uploadDir = $filesystemFactory->disk('flarum-assets');
        $this->imageManager = $imageManager;
        $this->settings = $settings;
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

        $uri = $file->getStream()->getMetadata('uri');
        $imageSize = is_string($uri) ? @getimagesize($uri) : false;
        if ($imageSize === false || $imageSize[0] > static::MAX_DIMENSION || $imageSize[1] > static::MAX_DIMENSION) {
            throw new ValidationException(['file' => SaveRuleController::trans('invalid_image')]);
        }

        try {
            $image = $this->imageManager->make($uri);
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

        // 草稿内重复试传时回收上一张：被规则引用或仍是站点当前图的文件不能删；
        // replace 前缀必须与 key 对应（logo_path 只回收 scheduler-logo-*），防跨 key 误删
        $replace = (string) Arr::get($request->getQueryParams(), 'replace', '');
        $expectedPrefix = 'scheduler-'.($key === 'logo_path' ? 'logo' : 'favicon').'-';
        if (SchedulerImage::isSchedulerFile($replace) && strpos($replace, $expectedPrefix) === 0
            && $replace !== $this->settings->get($key)
            && !ScheduleRule::query()->where('setting_value', $replace)->exists()) {
            $this->uploadDir->delete($replace);
        }

        return new JsonResponse(['path' => $uploadName]);
    }
}
