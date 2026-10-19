<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::addColumns(
    'plugin_schedule_rules',
    [
        'remark' => ['string', 'length' => 190, 'nullable' => true, 'comment' => '备注，标识规则作用'],
        'is_enabled' => ['boolean', 'default' => true, 'comment' => '启用/禁用开关；一次性规则执行后自动置为禁用'],
    ]
);
