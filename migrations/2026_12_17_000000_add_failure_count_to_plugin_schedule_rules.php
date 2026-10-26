<?php

use Flarum\Database\Migration;

return Migration::addColumns(
    'plugin_schedule_rules',
    [
        'failure_count' => ['integer', 'default' => 0, 'comment' => '连续失败次数，达到阈值自动禁用规则，避免每分钟无限重试'],
    ]
);
