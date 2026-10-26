<?php

namespace Mattoid\PluginScheduler\Model;

use Flarum\Database\AbstractModel;

class ScheduleRule extends AbstractModel
{
    protected $table = 'plugin_schedule_rules';

    protected $fillable = ['extension', 'action', 'repeat_type', 'execute_time', 'executed_at', 'rule_type', 'setting_key', 'setting_value', 'settings_map', 'settings_original', 'remark', 'is_enabled', 'failure_count'];

    protected $attributes = ['rule_type' => 'extension'];

    protected $casts = ['settings_map' => 'array', 'settings_original' => 'array', 'executed_at' => 'datetime', 'execute_time' => 'datetime', 'is_enabled' => 'boolean', 'failure_count' => 'integer'];
}
