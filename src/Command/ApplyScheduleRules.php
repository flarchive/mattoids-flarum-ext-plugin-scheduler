<?php

namespace Mattoid\PluginScheduler\Command;

use Flarum\Console\AbstractCommand;
use Flarum\Extension\ExtensionManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Carbon;
use Mattoid\PluginScheduler\Model\ScheduleRule;
use Mattoid\PluginScheduler\SettingsTargets;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ApplyScheduleRules extends AbstractCommand
{
    const SELF_EXTENSION = 'mattoid-plugin-scheduler';

    /**
     * Flarum 的 ExtensionManager 使用扩展 ID（如 mattoid-store），
     * 这里同时兼容 Composer 包名（如 mattoid/flarum-ext-store）。
     */
    public static function toExtensionId($name)
    {
        if (strpos($name, '/') === false) {
            return $name;
        }

        [$vendor, $package] = explode('/', $name);
        $package = str_replace(['flarum-ext-', 'flarum-'], '', $package);

        return $vendor.'-'.$package;
    }

    protected static $defaultName = 'plugin-scheduler:apply';

    protected function configure()
    {
        $this->setName(static::$defaultName)
            ->setDescription('Apply due plugin schedule rules (enable/disable extensions).');
    }

    protected function fire()
    {
        // 规则时间是按设置时区解释的裸墙钟时间（管理端按本地时间选择直接存库），
        // 而 PHP CLI/Flarum 运行时时区是 UTC，比较前必须把 now 转换到设置时区。
        $tzName = resolve(SettingsRepositoryInterface::class)->get('mattoid-plugin-scheduler.timezone') ?: 'Asia/Shanghai';
        try {
            $tz = new \DateTimeZone($tzName);
        } catch (\Throwable $e) {
            // 非法时区不能让整轮调度崩溃，回退 UTC 并记日志
            /** @var LoggerInterface $logger */
            $logger = resolve(LoggerInterface::class);
            $logger->warning('[plugin-scheduler] invalid timezone "' . $tzName . '", fallback to UTC: ' . $e->getMessage());
            $tz = new \DateTimeZone('UTC');
        }
        $now = Carbon::now($tz);
        $nowTime = $now->format('H:i');
        $nowDate = $now->format('Y-m-d');

        $rules = ScheduleRule::query()->where('is_enabled', true)->get();

        /** @var ExtensionManager $extensions */
        $extensions = resolve(ExtensionManager::class);

        $applied = 0;
        foreach ($rules as $rule) {
            // shiftTimezone：把库里按设置时区解释的裸时间挂到该时区（不做换算）
            $executeTime = $rule->execute_time->copy()->shiftTimezone($tz);

            $due = false;

            $executedAt = $rule->executed_at ? $rule->executed_at->copy()->shiftTimezone($tz) : null;

            // 时刻比较一律用 <=：cron 每分钟跑一次，错过了设定分钟当周期内仍要补执行
            if ($rule->repeat_type === 'daily') {
                $executedToday = $executedAt && $executedAt->format('Y-m-d') === $nowDate;
                $due = ($executeTime->format('H:i') <= $nowTime) && !$executedToday;
            } elseif ($rule->repeat_type === 'weekly') {
                // 每周：星期几相同 + 时刻已到，且本周未执行过
                $executedThisWeek = $executedAt && $executedAt->format('oW') === $now->format('oW');
                $due = ($executeTime->format('N') === $now->format('N')) && ($executeTime->format('H:i') <= $nowTime) && !$executedThisWeek;
            } elseif ($rule->repeat_type === 'monthly') {
                // 每月：几号 + 时刻已到，且本月未执行过（31 号规则在短月不触发）
                $executedThisMonth = $executedAt && $executedAt->format('Y-m') === $now->format('Y-m');
                $due = ($executeTime->format('d') === $now->format('d')) && ($executeTime->format('H:i') <= $nowTime) && !$executedThisMonth;
            } elseif ($rule->repeat_type === 'yearly') {
                // 每年：月-日 + 时刻已到，且今年未执行过（2-29 规则在平年不触发）
                $executedThisYear = $executedAt && $executedAt->format('Y') === $now->format('Y');
                $due = ($executeTime->format('m-d') === $now->format('m-d')) && ($executeTime->format('H:i') <= $nowTime) && !$executedThisYear;
            } else {
                // once：到达或超过设定时间即执行
                $due = $executeTime <= $now;
            }

            if (!$due) {
                continue;
            }

            // 原子抢占：条件更新抢到执行权（affected rows = 1）的进程才真正执行，
            // 并发多进程下同一周期只会执行一次
            $previousExecutedAt = $rule->executed_at;
            if (!$this->claim($rule, $now)) {
                continue;
            }

            try {
                if ($rule->rule_type === 'setting') {
                    $this->applySettingRule($rule);
                    $this->info('Rule #' . $rule->id . ': ' . $rule->action . ' setting ' . $rule->setting_key);
                } else {
                    $extensionId = static::toExtensionId($rule->extension);

                    if ($extensionId === static::SELF_EXTENSION) {
                        $this->info('Skipping rule #' . $rule->id . ': cannot schedule the scheduler extension itself.');
                        continue;
                    }

                    if ($rule->action === 'enable') {
                        $this->enableCascade($extensions, $extensionId);
                    } else {
                        $this->disableCascade($extensions, $extensionId);
                    }

                    $this->info('Rule #' . $rule->id . ': ' . $rule->action . ' ' . $rule->extension);
                }

                if ($rule->repeat_type === 'once') {
                    // 一次性规则执行后自动禁用，保留记录便于查看历史与备注
                    $rule->is_enabled = false;
                    $rule->save();
                }

                $applied++;
            } catch (\Throwable $e) {
                // 执行失败则归还执行权，下一轮调度重新尝试（保持原失败可重试语义）
                $this->releaseClaim($rule, $previousExecutedAt);
                /** @var LoggerInterface $logger */
                $logger = resolve(LoggerInterface::class);
                $logger->error('[plugin-scheduler] rule #' . $rule->id . ' failed: ' . $e->getMessage());
                $this->error('Rule #' . $rule->id . ' failed: ' . $e->getMessage());
            }
        }

        if ($applied > 0) {
            $this->clearFlarumCache();
        }

        $this->info('Plugin scheduler: ' . $applied . ' rule(s) applied.');
    }

    /**
     * 原子抢占执行权：条件 UPDATE 只在「本周期尚未执行」时写入 executed_at，
     * 抢到（affected rows = 1）的进程才允许执行该规则。
     */
    private function claim(ScheduleRule $rule, Carbon $now)
    {
        $query = ScheduleRule::query()->where('id', $rule->id);

        if ($rule->repeat_type === 'once') {
            $query->whereNull('executed_at');
        } else {
            // 本周期起点（设置时区），换算回 UTC 与库中 executed_at 比较
            $periodStart = $this->periodStart($rule, $now)->utc();
            $query->where(function ($q) use ($periodStart) {
                $q->whereNull('executed_at')->orWhere('executed_at', '<', $periodStart);
            });
        }

        return $query->update(['executed_at' => Carbon::now('UTC')]) === 1;
    }

    /**
     * 归还执行权：规则执行失败时恢复抢占前的 executed_at，便于下一轮重试。
     */
    private function releaseClaim(ScheduleRule $rule, $previousExecutedAt)
    {
        ScheduleRule::query()->where('id', $rule->id)->update(['executed_at' => $previousExecutedAt]);
    }

    /**
     * 重复规则的周期起点（设置时区）：daily=当天 0 点，weekly=本周一（ISO），
     * monthly=本月 1 号，yearly=今年 1 月 1 日。
     */
    private function periodStart(ScheduleRule $rule, Carbon $now)
    {
        $start = $now->copy()->startOfDay();

        if ($rule->repeat_type === 'weekly') {
            $start->startOfWeek();
        } elseif ($rule->repeat_type === 'monthly') {
            $start->startOfMonth();
        } elseif ($rule->repeat_type === 'yearly') {
            $start->startOfYear();
        }

        return $start;
    }

    /**
     * 设置类规则：action=set 写入目标值，action=reset 删除设置项回落到 Flarum 默认。
     * custom_less 等设置由 core 的 Saving 监听器做合法性校验，校验失败会抛异常使规则失败并记日志。
     */
    protected function applySettingRule(ScheduleRule $rule)
    {
        /** @var SettingsRepositoryInterface $settings */
        $settings = resolve(SettingsRepositoryInterface::class);

        if ($rule->action === 'reset') {
            $settings->delete($rule->setting_key);
        } else {
            $value = $rule->setting_value;
            if (SettingsTargets::typeOf($rule->setting_key) === 'bool') {
                $value = in_array($value, ['1', 1, true, 'true', 'on'], true) ? '1' : '0';
            }
            $settings->set($rule->setting_key, $value);
        }
    }

    /**
     * 参照 Flarum core ClearCacheController：进程内直接运行
     * cache:clear 与 assets:publish 命令对象（不 shell 执行 php flarum）。
     * 非 0 退出码只记 error 日志，不中断规则处理。
     */
    private function clearFlarumCache()
    {
        /** @var LoggerInterface $logger */
        $logger = resolve(LoggerInterface::class);

        $failed = false;

        $exitCode = resolve(\Flarum\Foundation\Console\CacheClearCommand::class)->run(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\NullOutput()
        );
        if ($exitCode !== 0) {
            $logger->error('[plugin-scheduler] cache:clear exited with code ' . $exitCode);
            $failed = true;
        }

        $exitCode = resolve(\Flarum\Foundation\Console\AssetsPublishCommand::class)->run(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\NullOutput()
        );
        if ($exitCode !== 0) {
            $logger->error('[plugin-scheduler] assets:publish exited with code ' . $exitCode);
            $failed = true;
        }

        if (!$failed) {
            $this->info('Flarum cache cleared');
        }
    }

    /**
     * 级联禁用：先深度收集所有「已启用且依赖 $id（含传递依赖）」的扩展并禁用，
     * 再禁用 $id 本身。若级联列表包含本插件自身，整个规则中止。
     */
    protected function disableCascade(ExtensionManager $extensions, $id)
    {
        $cascade = [];
        $visited = [$id => true];
        $queue = [$id];

        while ($queue) {
            $current = array_shift($queue);
            foreach ($extensions->getEnabledExtensions() as $ext) {
                $extId = $ext->getId();
                if (isset($visited[$extId])) {
                    continue;
                }
                if (in_array($current, $ext->getExtensionDependencyIds(), true)) {
                    $visited[$extId] = true;
                    $cascade[] = $extId;
                    $queue[] = $extId;
                }
            }
        }

        if (in_array(static::SELF_EXTENSION, $cascade, true)) {
            $msg = 'Aborting disable of ' . $id . ': cascade would disable the scheduler extension itself.';
            /** @var LoggerInterface $logger */
            $logger = resolve(LoggerInterface::class);
            $logger->warning('[plugin-scheduler] ' . $msg);
            $this->error($msg);
            throw new \RuntimeException($msg);
        }

        // 先禁用依赖方（级联），再禁用目标
        foreach ($cascade as $extId) {
            $extensions->disable($extId);
            $this->info('disable ' . $extId . ' (dependency cascade)');
        }
        $extensions->disable($id);
    }

    /**
     * 级联启用：递归先启用 $id 缺失的依赖，再启用 $id。
     * 依赖未安装时记录错误并使规则失败；依赖链里的本插件自身直接跳过。
     */
    protected function enableCascade(ExtensionManager $extensions, $id, $chain = [])
    {
        if (isset($chain[$id])) {
            throw new \RuntimeException('Circular dependency detected while enabling ' . $id);
        }
        $chain[$id] = true;

        $extension = $extensions->getExtension($id);
        if (!$extension) {
            throw new \RuntimeException('Extension not installed: ' . $id);
        }

        foreach ($extension->getExtensionDependencyIds() as $depId) {
            if ($depId === static::SELF_EXTENSION) {
                continue;
            }
            if (!$extensions->isEnabled($depId)) {
                $this->enableCascade($extensions, $depId, $chain);
                $extensions->enable($depId);
                $this->info('enable ' . $depId . ' (dependency cascade)');
            }
        }

        $extensions->enable($id);
    }

    protected function info($message)
    {
        if ($this->output) {
            $this->output->writeln('<info>' . $message . '</info>');
        }
    }

    protected function error($message)
    {
        if ($this->output) {
            $this->output->writeln('<error>' . $message . '</error>');
        }
    }
}
