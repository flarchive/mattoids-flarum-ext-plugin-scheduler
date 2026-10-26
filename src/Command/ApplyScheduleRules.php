<?php

namespace Mattoid\PluginScheduler\Command;

use Flarum\Console\AbstractCommand;
use Flarum\Extension\ExtensionManager;
use Flarum\Forum\ValidateCustomLess;
use Flarum\Frontend\Assets;
use Flarum\Locale\LocaleManager;
use Flarum\Settings\Event\Saving;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use Mattoid\PluginScheduler\Model\ScheduleRule;
use Mattoid\PluginScheduler\SchedulerImage;
use Mattoid\PluginScheduler\SettingsTargets;
use Psr\Log\LoggerInterface;

class ApplyScheduleRules extends AbstractCommand
{
    const SELF_EXTENSION = 'mattoid-plugin-scheduler';

    /**
     * 同一规则连续失败达到该次数后自动禁用，避免每分钟无限重试死循环。
     */
    const MAX_FAILURES = 3;

    /**
     * @var SettingsRepositoryInterface
     */
    protected $settings;

    /**
     * @var ExtensionManager
     */
    protected $extensions;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    public function __construct(
        SettingsRepositoryInterface $settings,
        ExtensionManager $extensions,
        LoggerInterface $logger
    ) {
        parent::__construct();

        $this->settings = $settings;
        $this->extensions = $extensions;
        $this->logger = $logger;
    }

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
        $tzName = $this->settings->get('mattoid-plugin-scheduler.timezone') ?: 'Asia/Shanghai';
        try {
            $tz = new \DateTimeZone($tzName);
        } catch (\Throwable $e) {
            // 非法时区不能让整轮调度崩溃，回退 UTC 并记日志
            $this->logger->warning('[plugin-scheduler] invalid timezone "' . $tzName . '", fallback to UTC: ' . $e->getMessage());
            $tz = new \DateTimeZone('UTC');
        }
        $now = Carbon::now($tz);
        $nowTime = $now->format('H:i');
        $nowDate = $now->format('Y-m-d');

        $rules = ScheduleRule::query()->where('is_enabled', true)->get();

        $applied = 0;
        foreach ($rules as $rule) {
            // shiftTimezone：把库里按设置时区解释的裸时间挂到该时区（不做换算）
            $executeTime = $rule->execute_time->copy()->shiftTimezone($tz);

            $due = false;

            // executed_at 是真实 UTC 时间戳，换算到设置时区要用 setTimezone（按偏移换算）；
            // shiftTimezone 只换时区标签不换墙钟，跨日时会把"今天已执行"误判
            $executedAt = $rule->executed_at ? $rule->executed_at->copy()->setTimezone($tz) : null;

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
            $claimedAt = $this->claim($rule, $now);
            if (!$claimedAt) {
                continue;
            }

            try {
                if ($rule->rule_type === 'setting') {
                    $this->applySettingRule($rule);
                    $this->info('Rule #' . $rule->id . ': ' . $rule->action . ' setting ' . $rule->setting_key);
                } elseif ($rule->rule_type === 'ext-setting') {
                    $this->applyExtSettingRule($rule);
                    $this->info('Rule #' . $rule->id . ': ' . $rule->action . ' ' . count((array) $rule->settings_map) . ' setting(s) of ' . $rule->extension);
                } else {
                    $extensionId = static::toExtensionId($rule->extension);

                    if ($extensionId === static::SELF_EXTENSION) {
                        $this->info('Skipping rule #' . $rule->id . ': cannot schedule the scheduler extension itself.');
                        continue;
                    }

                    if ($rule->action === 'enable') {
                        $this->enableCascade($extensionId);
                    } else {
                        $this->disableCascade($extensionId);
                    }

                    $this->info('Rule #' . $rule->id . ': ' . $rule->action . ' ' . $rule->extension);
                }

                // 成功后清零失败计数；一次性规则执行后自动禁用，保留记录便于查看历史与备注
                $rule->failure_count = 0;
                if ($rule->repeat_type === 'once') {
                    $rule->is_enabled = false;
                }
                $rule->save();

                $applied++;
            } catch (\Throwable $e) {
                $failures = (int) $rule->failure_count + 1;
                $this->logger->error('[plugin-scheduler] rule #' . $rule->id . ' failed (' . $failures . '/' . self::MAX_FAILURES . '): ' . $e->getMessage());
                $this->error('Rule #' . $rule->id . ' failed: ' . $e->getMessage());

                if ($failures >= self::MAX_FAILURES) {
                    // 连续失败熔断：自动禁用规则，不再归还执行权，停止每分钟死循环重试
                    ScheduleRule::query()->where('id', $rule->id)->update([
                        'failure_count' => $failures,
                        'is_enabled' => false,
                    ]);
                    $this->logger->warning('[plugin-scheduler] rule #' . $rule->id . ' auto-disabled after ' . $failures . ' consecutive failures.');
                } else {
                    ScheduleRule::query()->where('id', $rule->id)->update(['failure_count' => $failures]);
                    // 执行失败则归还执行权，下一轮调度重新尝试（保持原失败可重试语义）
                    $this->releaseClaim($rule, $previousExecutedAt, $claimedAt);
                }
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
        // is_enabled 必须写进抢占条件：管理员在遍历期间禁用规则时不得再被抢占执行，
        // 否则执行完还会用内存里的陈旧 is_enabled 覆盖管理员的修改
        $query = ScheduleRule::query()->where('id', $rule->id)->where('is_enabled', true);

        if ($rule->repeat_type === 'once') {
            $query->whereNull('executed_at');
        } else {
            // 本周期起点（设置时区），换算回 UTC 与库中 executed_at 比较
            $periodStart = $this->periodStart($rule, $now)->utc();
            $query->where(function ($q) use ($periodStart) {
                $q->whereNull('executed_at')->orWhere('executed_at', '<', $periodStart);
            });
        }

        $claimedAt = Carbon::now('UTC');

        // 返回抢到的时间戳而不是布尔值：releaseClaim 要靠它做条件归还，
        // 防止手动并发跑命令时 A 的失败回写抹掉 B 已成功写入的执行标记
        return $query->update(['executed_at' => $claimedAt]) === 1 ? $claimedAt : null;
    }

    /**
     * 归还执行权：规则执行失败时恢复抢占前的 executed_at，便于下一轮重试。
     * 条件更新只在 executed_at 仍是本进程抢占值时回写；并发者接过执行权则放弃，
     * 避免同周期被重复执行。
     */
    private function releaseClaim(ScheduleRule $rule, $previousExecutedAt, $claimedAt)
    {
        ScheduleRule::query()->where('id', $rule->id)
            ->where('executed_at', $claimedAt)
            ->update(['executed_at' => $previousExecutedAt]);
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
     * DatabaseSettingsRepository::set 是裸写入、不派发 Saving 事件，custom_less 必须先
     * 经 core 的 ValidateCustomLess 试编译，否则非法 LESS 会在下次前台编译时炸成 500。
     */
    protected function applySettingRule(ScheduleRule $rule)
    {
        if ($rule->action === 'reset') {
            $old = $this->settings->get($rule->setting_key);
            $this->settings->delete($rule->setting_key);
            $this->deleteReplacedImage($rule->setting_key, $old, null);
        } else {
            $value = $rule->setting_value;
            if (SettingsTargets::typeOf($rule->setting_key) === 'bool') {
                $value = in_array($value, ['1', 1, true, 'true', 'on'], true) ? '1' : '0';
            }
            if ($rule->setting_key === 'custom_less') {
                self::validateCustomLess((string) $value);
            }
            $old = $this->settings->get($rule->setting_key);
            $this->settings->set($rule->setting_key, $value);
            $this->deleteReplacedImage($rule->setting_key, $old, $value);
        }
    }

    /**
     * 用 core 的 ValidateCustomLess 对 custom_less 做保存/应用前的试编译校验。
     * 校验过程会把 assets 目录临时换成 NullAdapter、给容器换 OverrideSettingsRepository，
     * finally 里兜底还原，避免校验失败后污染同进程内后续规则与资产操作。
     */
    public static function validateCustomLess($less)
    {
        $container = Container::getInstance();
        /** @var Assets $assets */
        $assets = $container->make('flarum.assets.forum');
        /** @var LocaleManager $locales */
        $locales = $container->make(LocaleManager::class);

        $validator = new ValidateCustomLess(
            $assets,
            $locales,
            $container,
            $container->make('flarum.less.config')
        );

        $assetsDir = $assets->getAssetsDir();
        $settings = $container->make(SettingsRepositoryInterface::class);

        try {
            $eventSettings = ['custom_less' => $less];
            $validator->whenSettingsSaving(new Saving($eventSettings));
        } finally {
            $assets->setAssetsDir($assetsDir);
            $container->instance(SettingsRepositoryInterface::class, $settings);
        }
    }

    /**
     * 图片类设置被替换/清除后，删除不再被引用的 scheduler-* 文件；
     * 非本扩展上传的文件（如 core 上传的 logo）不碰。
     */
    private function deleteReplacedImage($key, $old, $new)
    {
        if (!in_array($key, ['logo_path', 'favicon_path'], true)) {
            return;
        }
        if ($old && $old !== $new && SchedulerImage::isSchedulerFile($old)) {
            SchedulerImage::deleteIfUnreferenced($old, $rule->id);
        }
    }

    /**
     * 扩展设置规则：settings_map 为「键 => 目标值」。
     * set=定时写入目标值；reset=恢复保存规则时记录的原始值（settings_original 中
     * 不存在或为 null 的键直接删除，回落到扩展默认值）。
     */
    protected function applyExtSettingRule(ScheduleRule $rule)
    {
        $settings = $this->settings;

        $map = (array) $rule->settings_map;

        if ($rule->action === 'reset') {
            $original = (array) $rule->settings_original;
            foreach ($map as $key => $value) {
                if (array_key_exists($key, $original) && $original[$key] !== null) {
                    // 存量数据可能含嵌套数组（写入校验收紧前保存的规则），跳过避免写坏设置
                    if (!is_scalar($original[$key])) {
                        continue;
                    }
                    $settings->set($key, (string) $original[$key]);
                } else {
                    $settings->delete($key);
                }
            }
        } else {
            foreach ($map as $key => $value) {
                if (is_bool($value)) {
                    $value = $value ? '1' : '0';
                }
                // 存量数据可能含嵌套数组（写入校验收紧前保存的规则），跳过避免写坏设置
                if (!is_scalar($value)) {
                    continue;
                }
                $settings->set($key, (string) $value);
            }
        }
    }

    /**
     * 参照 Flarum core ClearCacheController：进程内直接运行
     * cache:clear 与 assets:publish 命令对象（不 shell 执行 php flarum）。
     * 非 0 退出码只记 error 日志，不中断规则处理。
     */
    private function clearFlarumCache()
    {
        $failed = false;

        $exitCode = resolve(\Flarum\Foundation\Console\CacheClearCommand::class)->run(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\NullOutput()
        );
        if ($exitCode !== 0) {
            $this->logger->error('[plugin-scheduler] cache:clear exited with code ' . $exitCode);
            $failed = true;
        }

        $exitCode = resolve(\Flarum\Foundation\Console\AssetsPublishCommand::class)->run(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\NullOutput()
        );
        if ($exitCode !== 0) {
            $this->logger->error('[plugin-scheduler] assets:publish exited with code ' . $exitCode);
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
    protected function disableCascade($id)
    {
        $cascade = [];
        $visited = [$id => true];
        $queue = [$id];

        while ($queue) {
            $current = array_shift($queue);
            foreach ($this->extensions->getEnabledExtensions() as $ext) {
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
            $this->logger->warning('[plugin-scheduler] ' . $msg);
            $this->error($msg);
            throw new \RuntimeException($msg);
        }

        // 先禁用依赖方（级联），再禁用目标
        foreach ($cascade as $extId) {
            $this->extensions->disable($extId);
            $this->info('disable ' . $extId . ' (dependency cascade)');
        }
        $this->extensions->disable($id);
    }

    /**
     * 级联启用：递归先启用 $id 缺失的依赖，再启用 $id。
     * 依赖未安装时记录错误并使规则失败；依赖链里的本插件自身直接跳过。
     */
    protected function enableCascade($id, $chain = [])
    {
        if (isset($chain[$id])) {
            throw new \RuntimeException('Circular dependency detected while enabling ' . $id);
        }
        $chain[$id] = true;

        $extension = $this->extensions->getExtension($id);
        if (!$extension) {
            throw new \RuntimeException('Extension not installed: ' . $id);
        }

        foreach ($extension->getExtensionDependencyIds() as $depId) {
            if ($depId === static::SELF_EXTENSION) {
                continue;
            }
            if (!$this->extensions->isEnabled($depId)) {
                // 递归末尾已 enable($depId)，这里只补日志，重复调用属于冗余
                $this->enableCascade($depId, $chain);
                $this->info('enable ' . $depId . ' (dependency cascade)');
            }
        }

        $this->extensions->enable($id);
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
