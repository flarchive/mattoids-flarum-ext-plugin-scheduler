<?php

namespace Mattoid\PluginScheduler\Controller;

use Flarum\Api\Controller\AbstractCreateController;
use Flarum\Foundation\ValidationException;
use Flarum\Settings\SettingsRepositoryInterface;
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

    /**
     * 扩展名字段长度上限（对应 extension 列 varchar 191），超出抛 422 而不是 SQL 500；
     * settingsMap 条目数上限，防超大映射拖慢保存/执行。
     */
    const MAX_EXTENSION_LENGTH = 190;
    const MAX_SETTINGS_MAP = 100;

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

        if (!in_array($ruleType, ['extension', 'setting', 'ext-setting'], true) || !in_array($repeatType, ['once', 'daily', 'weekly', 'monthly', 'yearly'], true)) {
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
        } elseif ($ruleType === 'ext-setting') {
            $this->applyExtSettingAttributes($rule, $attributes);
        } else {
            $extension = trim((string) Arr::get($attributes, 'extension', ''));
            $action = Arr::get($attributes, 'action', '');

            if ($extension === '' || mb_strlen($extension) > self::MAX_EXTENSION_LENGTH || !in_array($action, ['enable', 'disable'], true)) {
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

        // setting 类型只允许核心白名单内的键；扩展设置键一律走 ext-setting 规则
        if (!SettingsTargets::isValid($settingKey)) {
            throw new ValidationException(['settingKey' => SaveRuleController::trans('unsupported_setting_key').': '.$settingKey]);
        }

        $rule->extension = '';
        $rule->action = $action;
        $rule->setting_key = $settingKey;
        $rule->setting_value = $action === 'set' ? (string) Arr::get($attributes, 'settingValue', '') : null;
        // 规则从 ext-setting 类型切换过来时清掉残留映射，避免脏数据
        $rule->settings_map = null;
        $rule->settings_original = null;

        // custom_less 保存前试编译校验，非法 LESS 当场 422，不等到执行期才炸前台
        if ($settingKey === 'custom_less' && $action === 'set') {
            try {
                ApplyScheduleRules::validateCustomLess($rule->setting_value);
            } catch (ValidationException $e) {
                throw new ValidationException(['settingValue' => $e->getMessage()]);
            }
        }
    }

    /**
     * 扩展设置规则：extension 列存扩展 ID，settings_map 存「被修改的键 => 目标值」，
     * settings_original 存「键 => 保存规则时的原始值」（null 表示原本不存在）。
     * set=定时写入目标值；reset=定时恢复保存时记录的原始值（原本不存在的键则删除）。
     */
    public static function applyExtSettingAttributes(ScheduleRule $rule, array $attributes)
    {
        $extension = trim((string) Arr::get($attributes, 'extension', ''));
        $action = Arr::get($attributes, 'action', '');

        if ($extension === '' || mb_strlen($extension) > self::MAX_EXTENSION_LENGTH || !in_array($action, ['set', 'reset'], true)) {
            throw new ValidationException(['extension' => SaveRuleController::trans('invalid_rule_parameters')]);
        }

        if (ApplyScheduleRules::toExtensionId($extension) === ApplyScheduleRules::SELF_EXTENSION) {
            throw new ValidationException(['extension' => SaveRuleController::trans('cannot_schedule_self')]);
        }

        $map = Arr::get($attributes, 'settingsMap', []);

        if (!is_array($map) || !$map) {
            throw new ValidationException(['settingsMap' => SaveRuleController::trans('empty_settings_map')]);
        }

        if (count($map) > self::MAX_SETTINGS_MAP) {
            throw new ValidationException(['settingsMap' => SaveRuleController::trans('too_many_settings')]);
        }

        // 键必须是「该扩展 ID.」前缀的扩展设置键；值必须是标量（嵌套数组强转字符串会写坏设置）
        $prefix = ApplyScheduleRules::toExtensionId($extension).'.';
        foreach ($map as $key => $value) {
            if (!SettingsTargets::isExtensionKey($key) || strpos($key, $prefix) !== 0) {
                throw new ValidationException(['settingsMap' => SaveRuleController::trans('unsupported_setting_key').': '.$key]);
            }
            if (!is_scalar($value) && $value !== null) {
                throw new ValidationException(['settingsMap' => SaveRuleController::trans('invalid_settings_value').': '.$key]);
            }
        }

        // 原始值从服务端现读（不信任客户端提交的 settingsOriginal），
        // 保证 reset 恢复的是保存规则时刻服务器上的真实值
        $settings = resolve(SettingsRepositoryInterface::class);
        $original = [];
        foreach (array_keys($map) as $key) {
            $original[$key] = $settings->get($key);
        }

        $rule->extension = $extension;
        $rule->action = $action;
        $rule->setting_key = null;
        $rule->setting_value = null;
        $rule->settings_map = $map;
        $rule->settings_original = $original;
    }
}
