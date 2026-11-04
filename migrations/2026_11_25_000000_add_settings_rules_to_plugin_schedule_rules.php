<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::addColumns(
    'plugin_schedule_rules',
    [
        'rule_type' => ['string', 'length' => 20, 'default' => 'extension', 'comment' => '规则类型 extension/setting'],
        'setting_key' => ['string', 'length' => 190, 'nullable' => true, 'comment' => '设置项 key（rule_type=setting 时使用）'],
        'setting_value' => ['text', 'nullable' => true, 'comment' => '设置项目标值（rule_type=setting 时使用）'],
    ]
);
