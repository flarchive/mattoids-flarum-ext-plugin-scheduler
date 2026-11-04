<?php

/*
 * This file is part of mattoid/flarum-ext-plugin-scheduler.
 *
 * Copyright (c) 2026 mattoid.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

use Flarum\Extend;
use Mattoid\PluginScheduler\Command\ApplyScheduleRules;
use Mattoid\PluginScheduler\Controller\DeleteRuleController;
use Mattoid\PluginScheduler\Controller\ListRuleController;
use Mattoid\PluginScheduler\Controller\SaveRuleController;
use Mattoid\PluginScheduler\Controller\UpdateRuleController;
use Mattoid\PluginScheduler\Controller\UploadSettingImageController;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),
    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Routes('api'))
        ->get('/plugin-scheduler/rules', 'plugin-scheduler.rules.list', ListRuleController::class)
        ->post('/plugin-scheduler/rules', 'plugin-scheduler.rules.save', SaveRuleController::class)
        ->put('/plugin-scheduler/rules/{id}', 'plugin-scheduler.rules.update', UpdateRuleController::class)
        ->delete('/plugin-scheduler/rules/{id}', 'plugin-scheduler.rules.delete', DeleteRuleController::class)
        ->post('/plugin-scheduler/upload-image', 'plugin-scheduler.upload-image', UploadSettingImageController::class),

    (new Extend\Console())
        ->command(ApplyScheduleRules::class)
        ->schedule(ApplyScheduleRules::class, function ($event) {
            // withoutOverlapping：应用规则涉及扩展启停/资产编译等重操作，
            // 执行超一分钟时不允许下一个 cron 进程重叠跑（core 已绑 CacheEventMutex）。
            // 锁 TTL 显式给 30 分钟：进程异常死亡后最多停摆 30 分钟，而不是默认的 24 小时
            $event->everyMinute()->withoutOverlapping(30);
        }),

    (new Extend\Settings())
        ->default('mattoid-plugin-scheduler.timezone', 'Asia/Shanghai'),
];
