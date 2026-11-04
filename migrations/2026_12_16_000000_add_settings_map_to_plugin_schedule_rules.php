<?php

use Flarum\Database\Migration;

return Migration::addColumns(
    'plugin_schedule_rules',
    [
        'settings_map' => ['text', 'nullable' => true, 'comment' => '扩展设置规则（rule_type=ext-setting）：JSON map key=>目标值'],
        'settings_original' => ['text', 'nullable' => true, 'comment' => '扩展设置规则：JSON map key=>保存规则时的原始值（null 表示原本不存在，reset 时删除）'],
    ]
);
