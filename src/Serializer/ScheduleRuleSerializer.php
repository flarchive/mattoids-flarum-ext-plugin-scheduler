<?php

namespace Mattoid\PluginScheduler\Serializer;

use Flarum\Api\Serializer\AbstractSerializer;
use Mattoid\PluginScheduler\Model\ScheduleRule;

class ScheduleRuleSerializer extends AbstractSerializer
{
    protected $type = 'plugin-schedule-rules';

    protected function getDefaultAttributes($data)
    {
        if (!$data instanceof ScheduleRule) {
            return [];
        }

        return [
            'ruleType' => $data->rule_type ?: 'extension',
            'extension' => $data->extension,
            'action' => $data->action,
            'repeatType' => $data->repeat_type,
            'executeTime' => $data->execute_time ? $data->execute_time->format('Y-m-d H:i') : null,
            'executedAt' => $data->executed_at ? $data->executed_at->format('Y-m-d H:i') : null,
            'settingKey' => $data->setting_key,
            'settingValue' => $data->setting_value,
            'settingsMap' => $data->settings_map,
            'settingsOriginal' => $data->settings_original,
            'remark' => $data->remark,
            'isEnabled' => (bool) $data->is_enabled,
            'failureCount' => (int) $data->failure_count,
            'storeId' => $data->store_id ? (int) $data->store_id : null,
            'storeTitle' => $this->storeTitle($data),
        ];
    }

    /**
     * store-goods 规则附带商品标题便于前端展示；商品已物理删除或 mattoid-store
     * 未安装时返回 null，前端回退显示 #id
     */
    private function storeTitle(ScheduleRule $rule): ?string
    {
        if ($rule->rule_type !== 'store-goods' || !$rule->store_id) {
            return null;
        }

        $storeClass = 'Mattoid\\Store\\Model\\StoreModel';
        if (!class_exists($storeClass)) {
            return null;
        }

        $store = $storeClass::withTrashed()->find($rule->store_id);

        return $store ? $store->title : null;
    }
}
