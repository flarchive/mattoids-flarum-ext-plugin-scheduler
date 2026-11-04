<?php

namespace Mattoid\PluginScheduler;

/**
 * 支持定时修改的 Flarum 核心设置项清单。
 * 前端管理页与后端校验共用同一份 key 定义。
 */
class SettingsTargets
{
    /**
     * key => [
     *   'group'  => 分组标识（basics/appearance，前端翻译用）,
     *   'type'   => 值类型 text/textarea/color/bool,
     *   'reset'  => reset 动作删除设置后回落到的默认值,
     * ]
     */
    const TARGETS = [
        // 常规
        'forum_title' => ['group' => 'basics', 'type' => 'text'],
        'forum_description' => ['group' => 'basics', 'type' => 'textarea'],
        'welcome_title' => ['group' => 'basics', 'type' => 'text'],
        'welcome_message' => ['group' => 'basics', 'type' => 'textarea'],

        // 外观
        'logo_path' => ['group' => 'appearance', 'type' => 'text'],
        'favicon_path' => ['group' => 'appearance', 'type' => 'text'],
        'theme_primary_color' => ['group' => 'appearance', 'type' => 'color'],
        'theme_secondary_color' => ['group' => 'appearance', 'type' => 'color'],
        'theme_dark_mode' => ['group' => 'appearance', 'type' => 'bool'],
        'theme_colored_header' => ['group' => 'appearance', 'type' => 'bool'],
        'custom_header' => ['group' => 'appearance', 'type' => 'textarea'],
        'custom_footer' => ['group' => 'appearance', 'type' => 'textarea'],
        'custom_less' => ['group' => 'appearance', 'type' => 'textarea'],
    ];

    public static function isValid($key)
    {
        return isset(self::TARGETS[$key]);
    }

    /**
     * 扩展设置键：形如「扩展ID.键名」（如 mattoid-store.goods-title），
     * 扩展 ID 为小写字母/数字/连字符，键名允许点号。仅供 ext-setting 规则
     * 校验「键属于声明的扩展」使用，setting 规则不经过此判断。
     */
    public static function isExtensionKey($key)
    {
        return is_string($key) && preg_match('/^[a-z0-9-]+\.[a-z0-9_.-]+$/', $key) === 1;
    }

    public static function typeOf($key)
    {
        return self::isValid($key) ? self::TARGETS[$key]['type'] : 'text';
    }

    public static function groupOf($key)
    {
        return self::isValid($key) ? self::TARGETS[$key]['group'] : 'basics';
    }
}
