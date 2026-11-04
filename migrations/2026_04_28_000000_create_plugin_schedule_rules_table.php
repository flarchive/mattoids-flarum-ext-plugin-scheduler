<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable(
    'plugin_schedule_rules',
    function (Blueprint $table) {
        $table->increments('id');
        $table->string('extension')->comment('插件 id，如 mattoid/flarum-ext-store')->index();
        $table->string('action', 16)->comment('动作 enable/disable');
        $table->string('repeat_type', 16)->comment('重复类型 once/daily');
        $table->dateTime('execute_time')->comment('执行时间（once：精确到分；daily：比较时分）')->index();
        $table->dateTime('executed_at')->nullable()->comment('上次执行时间（daily 用于当天防重）');
        $table->timestamps();
    }
);
