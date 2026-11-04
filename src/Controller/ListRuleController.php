<?php

namespace Mattoid\PluginScheduler\Controller;

use Flarum\Api\Controller\AbstractListController;
use Mattoid\PluginScheduler\Model\ScheduleRule;
use Mattoid\PluginScheduler\Serializer\ScheduleRuleSerializer;
use Psr\Http\Message\ServerRequestInterface;
use Tobscure\JsonApi\Document;

class ListRuleController extends AbstractListController
{
    public $serializer = ScheduleRuleSerializer::class;

    protected function data(ServerRequestInterface $request, Document $document)
    {
        $request->getAttribute('actor')->assertAdmin();

        return ScheduleRule::query()->orderBy('execute_time')->get();
    }
}
