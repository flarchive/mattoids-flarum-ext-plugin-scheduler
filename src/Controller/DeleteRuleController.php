<?php

namespace Mattoid\PluginScheduler\Controller;

use Flarum\Api\Controller\AbstractDeleteController;
use Illuminate\Support\Arr;
use Mattoid\PluginScheduler\Model\ScheduleRule;
use Mattoid\PluginScheduler\SchedulerImage;
use Psr\Http\Message\ServerRequestInterface;

class DeleteRuleController extends AbstractDeleteController
{
    protected function delete(ServerRequestInterface $request)
    {
        $request->getAttribute('actor')->assertAdmin();

        $id = Arr::get($request->getQueryParams(), 'id');

        $rule = ScheduleRule::query()->findOrFail($id);

        // 图片回收：从未执行过的图片规则，其文件还没被设为站点当前图，随规则删除；
        // 已执行过的文件可能正被站点使用，保留
        if ($rule->rule_type === 'setting' && $rule->executed_at === null
            && SchedulerImage::isImageKey($rule->setting_key) && SchedulerImage::isSchedulerFile($rule->setting_value)) {
            SchedulerImage::deleteIfUnreferenced($rule->setting_value, $rule->id);
        }

        $rule->delete();
    }
}
