<?php

namespace Mattoid\PluginScheduler\Model;

use Flarum\Database\AbstractModel;

class ScheduleRule extends AbstractModel
{
    protected $table = 'plugin_schedule_rules';

    protected $fillable = ['extension', 'action', 'repeat_type', 'execute_time', 'executed_at', 'rule_type', 'setting_key', 'setting_value', 'remark', 'is_enabled'];

    protected $attributes = ['rule_type' => 'extension'];

    protected $casts = ['rule_value' => 'array', 'executed_at' => 'datetime', 'execute_time' => 'datetime', 'is_enabled' => 'boolean'];
}
