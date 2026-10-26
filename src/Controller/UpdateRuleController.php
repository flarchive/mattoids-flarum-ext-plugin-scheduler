<?php

namespace Mattoid\PluginScheduler\Controller;

use Flarum\Api\Controller\AbstractShowController;
use Flarum\Foundation\ValidationException;
use Illuminate\Support\Arr;
use Mattoid\PluginScheduler\Command\ApplyScheduleRules;
use Mattoid\PluginScheduler\Model\ScheduleRule;
use Mattoid\PluginScheduler\SchedulerImage;
use Mattoid\PluginScheduler\Serializer\ScheduleRuleSerializer;
use Psr\Http\Message\ServerRequestInterface;
use Tobscure\JsonApi\Document;

class UpdateRuleController extends AbstractShowController
{
    public $serializer = ScheduleRuleSerializer::class;

    protected function data(ServerRequestInterface $request, Document $document)
    {
        $request->getAttribute('actor')->assertAdmin();

        $id = Arr::get($request->getQueryParams(), 'id');
        $rule = ScheduleRule::query()->findOrFail($id);

        // 旧图片信息在覆盖前捕获：从未执行过的规则（文件未成为站点当前图）被替换时回收旧文件
        $oldImageValue = $rule->rule_type === 'setting' && SchedulerImage::isImageKey($rule->setting_key)
            ? $rule->setting_value
            : null;
        $wasExecuted = $rule->executed_at !== null;

        $attributes = Arr::get($request->getParsedBody(), 'data.attributes', []);

        $ruleType = Arr::get($attributes, 'ruleType', 'extension');
        $repeatType = Arr::get($attributes, 'repeatType', 'once');
        $executeTime = Arr::get($attributes, 'executeTime', '');

        if (!in_array($ruleType, ['extension', 'setting', 'ext-setting'], true) || !in_array($repeatType, ['once', 'daily', 'weekly', 'monthly', 'yearly'], true)) {
            throw new ValidationException(['ruleType' => SaveRuleController::trans('invalid_rule_parameters')]);
        }

        $time = SaveRuleController::parseExecuteTime($executeTime);

        $rule->rule_type = $ruleType;
        $rule->repeat_type = $repeatType;
        $rule->execute_time = $time;
        $rule->remark = mb_substr(trim((string) Arr::get($attributes, 'remark', '')), 0, 190) ?: null;
        if (Arr::has($attributes, 'isEnabled')) {
            $rule->is_enabled = $attributes['isEnabled'] ? true : false;
        }

        if ($ruleType === 'setting') {
            SaveRuleController::applySettingAttributes($rule, $attributes);
        } elseif ($ruleType === 'ext-setting') {
            SaveRuleController::applyExtSettingAttributes($rule, $attributes);
        } else {
            $extension = trim((string) Arr::get($attributes, 'extension', ''));
            $action = Arr::get($attributes, 'action', '');

            if ($extension === '' || mb_strlen($extension) > SaveRuleController::MAX_EXTENSION_LENGTH || !in_array($action, ['enable', 'disable'], true)) {
                throw new ValidationException(['extension' => SaveRuleController::trans('invalid_rule_parameters')]);
            }

            if (ApplyScheduleRules::toExtensionId($extension) === ApplyScheduleRules::SELF_EXTENSION) {
                throw new ValidationException(['extension' => SaveRuleController::trans('cannot_schedule_self')]);
            }

            $rule->extension = $extension;
            $rule->action = $action;
            $rule->setting_key = null;
            $rule->setting_value = null;
            $rule->settings_map = null;
            $rule->settings_original = null;
        }

        // daily 规则执行时间可能改到当天更晚时刻，清掉当天防重标记；
        // once 规则同理由调用方决定（更新即视为未执行）
        $rule->executed_at = null;

        // 重新启用给满失败配额：熔断禁用后管理员修复问题再打开，计数不应停留在阈值
        if ($rule->is_enabled) {
            $rule->failure_count = 0;
        }

        $rule->save();

        // 图片回收：旧规则从未执行（旧文件未被设为站点当前图）且新值不再引用它时删除
        $newImageValue = $rule->rule_type === 'setting' && SchedulerImage::isImageKey($rule->setting_key)
            ? $rule->setting_value
            : null;
        if (!$wasExecuted && SchedulerImage::isSchedulerFile($oldImageValue) && $oldImageValue !== $newImageValue) {
            SchedulerImage::deleteIfUnreferenced($oldImageValue, $rule->id);
        }

        return $rule;
    }
}
