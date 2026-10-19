<?php

namespace Mattoid\PluginScheduler\Controller;

use Flarum\Api\Controller\AbstractDeleteController;
use Illuminate\Support\Arr;
use Mattoid\PluginScheduler\Model\ScheduleRule;
use Psr\Http\Message\ServerRequestInterface;

class DeleteRuleController extends AbstractDeleteController
{
    protected function delete(ServerRequestInterface $request)
    {
        $request->getAttribute('actor')->assertAdmin();

        $id = Arr::get($request->getQueryParams(), 'id');

        $rule = ScheduleRule::query()->findOrFail($id);
        $rule->delete();
    }
}
