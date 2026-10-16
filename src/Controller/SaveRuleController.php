<?php

namespace Mattoid\PluginScheduler\Controller;

use Flarum\Api\Controller\AbstractCreateController;
use Flarum\Foundation\ValidationException;
use Illuminate\Support\Arr;
use Mattoid\PluginScheduler\Command\ApplyScheduleRules;
use Mattoid\PluginScheduler\Model\ScheduleRule;
use Mattoid\PluginScheduler\Serializer\ScheduleRuleSerializer;
use Mattoid\PluginScheduler\SettingsTargets;
use Psr\Http\Message\ServerRequestInterface;
use Tobscure\JsonApi\Document;

class SaveRuleController extends AbstractCreateController
{
    public $serializer = ScheduleRuleSerializer::class;

    /**
     * 解析 executeTime（Y-m-d H:i 或前端 datetime-local 的 Y-m-d\TH:i），
     * 用 getLastErrors() 拦截 2026-02-30 这类归一化日期，非法输入抛 422。
     */
    public static function parseExecuteTime($executeTime)
    {
        $executeTime = (string) $executeTime;

        $time = \DateTime::createFromFormat('Y-m-d H:i', $executeTime);
        if (!$time) {
            $time = \DateTime::createFromFormat('Y-m-d\TH:i', $executeTime);
        }

        $errors = $time ? \DateTime::getLastErrors() : false;
        $valid = $time && (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));

        if (!$valid) {
            throw new ValidationException(['executeTime' => self::trans('invalid_execute_time')]);
        }

        return $time;
    }

    public static function trans($key, $params = [])
    {
        return resolve(\Symfony\Contracts\Translation\TranslatorInterface::class)->trans('mattoid-plugin-scheduler.api.'.$key, $params);
    }

    protected function data(ServerRequestInterface $request, Document $document)
    {
        $request->getAttribute('actor')->assertAdmin();

        $attributes = Arr::get($request->getParsedBody(), 'data.attributes', []);

        $ruleType = Arr::get($attributes, 'ruleType', 'extension');
        $repeatType = Arr::get($attributes, 'repeatType', 'once');
        $executeTime = Arr::get($attributes, 'executeTime', '');

        if (!in_array($ruleType, ['extension', 'setting'], true) || !in_array($repeatType, ['once', 'daily', 'weekly', 'monthly', 'yearly'], true)) {
            throw new ValidationException(['ruleType' => self::trans('invalid_rule_parameters')]);
        }

        $time = self::parseExecuteTime($executeTime);

        $rule = new ScheduleRule();
        $rule->rule_type = $ruleType;
        $rule->repeat_type = $repeatType;
        $rule->execute_time = $time;
        $rule->remark = mb_substr(trim((string) Arr::get($attributes, 'remark', '')), 0, 190) ?: null;
        $rule->is_enabled = Arr::get($attributes, 'isEnabled', true) ? true : false;

        if ($ruleType === 'setting') {
            $this->applySettingAttributes($rule, $attributes);
        } else {
            $extension = trim((string) Arr::get($attributes, 'extension', ''));
            $action = Arr::get($attributes, 'action', '');

            if ($extension === '' || !in_array($action, ['enable', 'disable'], true)) {
                throw new ValidationException(['extension' => self::trans('invalid_rule_parameters')]);
            }

            if (ApplyScheduleRules::toExtensionId($extension) === ApplyScheduleRules::SELF_EXTENSION) {
                throw new ValidationException(['extension' => self::trans('cannot_schedule_self')]);
            }

            $rule->extension = $extension;
            $rule->action = $action;
        }

        $rule->save();

        return $rule;
    }

    public static function applySettingAttributes(ScheduleRule $rule, array $attributes)
    {
        $action = Arr::get($attributes, 'action', '');
        $settingKey = trim((string) Arr::get($attributes, 'settingKey', ''));

        if (!in_array($action, ['set', 'reset'], true)) {
            throw new ValidationException(['action' => SaveRuleController::trans('invalid_action')]);
        }

        if (!SettingsTargets::isValid($settingKey)) {
            throw new ValidationException(['settingKey' => SaveRuleController::trans('unsupported_setting_key').': '.$settingKey]);
        }

        $rule->extension = '';
        $rule->action = $action;
        $rule->setting_key = $settingKey;
        $rule->setting_value = $action === 'set' ? (string) Arr::get($attributes, 'settingValue', '') : null;
    }
}
