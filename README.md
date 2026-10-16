# Flarum Plugin Scheduler（插件定时启停管理）

![License](https://img.shields.io/badge/license-MIT-blue.svg)

A [Flarum](http://flarum.org) extension. 定时开启 / 关闭已安装的 Flarum 扩展。

在后台为扩展配置定时规则：选择插件 → 选择开启或关闭 → 选择单次或每天重复 → 指定精确到分钟的执行时间。每分钟由 Flarum 调度器扫描一次，到点自动执行启停。

## 功能特性

- **定时规则**：每行规则 = 插件 + 目标状态（开启/关闭）+ 重复类型（单次/每天）+ 执行时间（精确到分）
- **单次规则**：到达指定日期时间后执行一次，执行完自动删除
- **每日规则**：每天到点执行，当天自动防重
- **插件搜索**：插件下拉框支持按名称 / 扩展 ID 搜索，空输入显示全部已安装插件
- **自我保护**：禁止配置禁用本扩展自身的规则，避免定时任务自我锁定
- 仅管理员可见、可操作

## 安装（Install）

```sh
composer require mattoid/flarum-ext-plugin-scheduler:"*"
php flarum migrate
php flarum cache:clear
```

然后在后台启用扩展。

## 配置定时任务（必需）

扩展通过 Flarum 调度器（`php flarum schedule:run`）每分钟触发，需要在系统中注册每分钟任务：

**Linux crontab：**

```cron
* * * * * cd /path-to-flarum && php flarum schedule:run >> /dev/null 2>&1
```

**Windows 计划任务：**

```bat
schtasks /Create /TN "FlarumScheduleRun" /SC MINUTE /MO 1 /TR "\"C:\path-to\php.exe\" \"C:\path-to\flarum\flarum\" schedule:run" /F
```

未注册时规则不会生效；可手动执行 `php flarum schedule:run` 进行测试。

## 更新（Updating）

```sh
composer update mattoid/flarum-ext-plugin-scheduler:"*"
php flarum migrate
php flarum cache:clear
```

## 数据表

- `plugin_schedule_rules`：定时规则（插件 / 动作 / 重复类型 / 执行时间 / 执行标记）

## 链接（Links）

- [GitHub](https://github.com/Mattoids/flarum-ext-plugin-scheduler)
