<?php

use Illuminate\Database\Schema\Builder;

/*
 * 商店商品规则：store-goods 类型规则的目标商品 id（mattoid-store 的 store 表）
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('plugin_schedule_rules', function ($table) use ($schema) {
            if (!$schema->hasColumn('plugin_schedule_rules', 'store_id')) {
                $table->unsignedInteger('store_id')->nullable()->after('extension')->comment('store-goods 规则的目标商品 id');
            }
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('plugin_schedule_rules', function ($table) {
            $table->dropColumn('store_id');
        });
    },
];
