<?php

namespace Mattoid\PluginScheduler;

use Illuminate\Contracts\Filesystem\Factory;
use Mattoid\PluginScheduler\Model\ScheduleRule;

/**
 * scheduler-* 图片文件的生命周期工具：文件名是上传时随机生成的，
 * 用正则精确匹配，避免误删或路径穿越。
 */
class SchedulerImage
{
    const KEYS = ['logo_path', 'favicon_path'];

    public static function isImageKey($key)
    {
        return in_array($key, self::KEYS, true);
    }

    public static function isSchedulerFile($path)
    {
        return is_string($path) && preg_match('/^scheduler-(?:logo|favicon)-[a-z0-9]{8}\.png$/', $path) === 1;
    }

    /**
     * 删除 scheduler-* 文件，但先确认没有其他规则还在引用它
     * （复制规则/复用同一上传时，两条规则可能指向同一文件）。
     */
    public static function deleteIfUnreferenced($path, $exceptRuleId = null)
    {
        if (!self::isSchedulerFile($path)) {
            return;
        }

        $query = ScheduleRule::query()->where('setting_value', $path);
        if ($exceptRuleId) {
            $query->where('id', '!=', $exceptRuleId);
        }
        if ($query->exists()) {
            return;
        }

        resolve(Factory::class)->disk('flarum-assets')->delete($path);
    }
}
