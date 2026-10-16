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

    public static function typeOf($key)
    {
        return self::isValid($key) ? self::TARGETS[$key]['type'] : 'text';
    }

    public static function groupOf($key)
    {
        return self::isValid($key) ? self::TARGETS[$key]['group'] : 'basics';
    }
}
