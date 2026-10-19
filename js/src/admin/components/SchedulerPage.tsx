import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import Button from 'flarum/common/components/Button';
import Select from 'flarum/common/components/Select';
import Mithril from 'mithril';
import ExtensionSettingsForm from './ExtensionSettingsForm';
import ExtensionSettingsEditor from './ExtensionSettingsEditor';

interface ExtensionInfo {
  id: string;
  name: string;
  extra: {
    'flarum-extension': {
      title: string;
    };
  };
}

interface Rule {
  id: number;
  ruleType: string;
  extension: string;
  action: string;
  repeatType: string;
  executeTime: string;
  settingKey: string;
  settingValue: string;
  settingsMap: Record<string, string>;
  settingsOriginal: Record<string, string | null>;
  remark: string;
  isEnabled: boolean;
}

interface SettingTarget {
  group: string;
  type: string;
}

interface DraftRow {
  editingRuleId: number | null;
  ruleType: string;
  extension: string;
  action: string;
  repeatType: string;
  date: string;
  time: string;
  weekday: number;
  text: string;
  settingKey: string;
  settingValue: string;
  // ext-setting：被修改的键值对与保存时记录的原始值（由 ExtensionSettingsForm 回调写入）
  settingValues: Record<string, string>;
  settingOriginals: Record<string, string | null>;
  remark: string;
}

const SELF_ID = 'mattoid-plugin-scheduler';

// 周一到周日（1-7，与 PHP date('N') 一致）
const WEEKDAY_KEYS = ['weekday-mon', 'weekday-tue', 'weekday-wed', 'weekday-thu', 'weekday-fri', 'weekday-sat', 'weekday-sun'];

// 下一次某个星期几（1-7）出现的日期（含今天），按本地日期格式化避免时区回退一天
function nextWeekdayDate(weekday: number): string {
  const now = new Date();
  const diff = (weekday - (now.getDay() || 7) + 7) % 7;
  const d = new Date(now.getFullYear(), now.getMonth(), now.getDate() + diff);
  return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

// 本地时区的 YYYY-MM-DD（toISOString 是 UTC 日期，时区偏东会回退一天）
function localToday(): string {
  const now = new Date();
  return now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
}

// 今天是星期几（1-7）
function todayWeekday(): number {
  return new Date().getDay() || 7;
}

const TIMEZONE_OPTIONS = [
  'Asia/Shanghai',
  'Asia/Tokyo',
  'Asia/Singapore',
  'UTC',
  'Europe/London',
  'Europe/Berlin',
  'America/New_York',
  'America/Los_Angeles',
];

// 与后端 Mattoid\PluginScheduler\SettingsTargets 保持一致
const SETTING_TARGETS: Record<string, SettingTarget> = {
  forum_title: { group: 'basics', type: 'text' },
  forum_description: { group: 'basics', type: 'textarea' },
  welcome_title: { group: 'basics', type: 'text' },
  welcome_message: { group: 'basics', type: 'textarea' },
  logo_path: { group: 'appearance', type: 'image' },
  favicon_path: { group: 'appearance', type: 'image' },
  theme_primary_color: { group: 'appearance', type: 'color' },
  theme_secondary_color: { group: 'appearance', type: 'color' },
  theme_dark_mode: { group: 'appearance', type: 'bool' },
  theme_colored_header: { group: 'appearance', type: 'bool' },
  custom_header: { group: 'appearance', type: 'textarea' },
  custom_footer: { group: 'appearance', type: 'textarea' },
  custom_less: { group: 'appearance', type: 'textarea' },
};

function extensionTitle(ext: ExtensionInfo): string {
  return (ext.extra && ext.extra['flarum-extension'] && ext.extra['flarum-extension'].title) || ext.name || ext.id;
}

export default class SchedulerPage extends ExtensionPage {
  private rules: Rule[] = [];
  private draftRows: DraftRow[] = [];
  private saving: boolean = false;
  private loadingRules: boolean = true;
  private openDropdown: number | null = null;
  private uploadingImage: boolean = false;
  // 正在行内编辑设置的扩展 id（null = 收起），不再跳转到扩展自己的设置页
  private configuringExt: string | null = null;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.loadRules();
  }

  installedExtensions(): ExtensionInfo[] {
    return Object.keys(app.data.extensions)
      .filter((id) => id !== SELF_ID)
      .map((id) => {
        const ext = app.data.extensions[id] as unknown as ExtensionInfo;
        ext.id = id;
        return ext;
      });
  }

  extensionOptions(): Record<string, string> {
    const options: Record<string, string> = {};
    this.installedExtensions().forEach((ext) => {
      options[ext.id] = extensionTitle(ext);
    });
    return options;
  }

  settingOptions(): Record<string, string> {
    const options: Record<string, string> = {};
    Object.keys(SETTING_TARGETS).forEach((key) => {
      const group = SETTING_TARGETS[key].group;
      const label = app.translator.trans(`mattoid-plugin-scheduler.admin.setting-${key}`);
      options[key] = `${app.translator.trans(`mattoid-plugin-scheduler.admin.setting-group-${group}`)} / ${label}`;
    });
    return options;
  }

  // app.data.settings 里以「扩展ID.」开头的设置键（即该扩展的自有配置项）
  extensionSettingKeys(extId: string): string[] {
    if (!extId) {
      return [];
    }
    const prefix = extId + '.';
    return Object.keys(app.data.settings).filter((key) => key.indexOf(prefix) === 0);
  }

  // 只列出注册了后台设置项的扩展（即 /admin#/extension/<id> 页有设置表单的）
  settingExtensionOptions(): Record<string, string> {
    const options: Record<string, string> = {};
    this.installedExtensions().forEach((ext) => {
      const settings = app.extensionData.getSettings(ext.id);
      if (settings && settings.length > 0) {
        options[ext.id] = extensionTitle(ext);
      }
    });
    return options;
  }

  actionOptions(row: DraftRow): Record<string, string> {
    if (row.ruleType === 'setting' || row.ruleType === 'ext-setting') {
      return {
        set: app.translator.trans('mattoid-plugin-scheduler.admin.action-set'),
        reset: app.translator.trans('mattoid-plugin-scheduler.admin.action-reset'),
      };
    }
    return {
      enable: app.translator.trans('mattoid-plugin-scheduler.admin.enable'),
      disable: app.translator.trans('mattoid-plugin-scheduler.admin.disable'),
    };
  }

  loadRules() {
    this.loadingRules = true;

    app
      .request({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/plugin-scheduler/rules',
      })
      .then((result: any) => {
        this.rules = ((result && result.data) || []).map((item: any) => ({
          id: parseInt(item.id, 10),
          ruleType: item.attributes.ruleType || 'extension',
          extension: item.attributes.extension,
          action: item.attributes.action,
          repeatType: item.attributes.repeatType,
          executeTime: item.attributes.executeTime,
          settingKey: item.attributes.settingKey || '',
          settingValue: item.attributes.settingValue || '',
          settingsMap: item.attributes.settingsMap || {},
          settingsOriginal: item.attributes.settingsOriginal || {},
          remark: item.attributes.remark || '',
          isEnabled: item.attributes.isEnabled !== false,
        }));
      })
      .catch(() => {
        this.rules = [];
      })
      .then(() => {
        this.loadingRules = false;
        m.redraw();
      });
  }

  addRow() {
    const options = this.extensionOptions();
    const first = Object.keys(options)[0] || '';
    this.draftRows.push({
      editingRuleId: null,
      ruleType: 'extension',
      extension: first,
      action: 'enable',
      repeatType: 'once',
      date: localToday(),
      time: '08:00',
      weekday: todayWeekday(),
      text: '',
      settingKey: 'welcome_title',
      settingValue: '',
      settingValues: {},
      settingOriginals: {},
      remark: '',
    });
  }

  editRule(rule: Rule) {
    // 同一规则同时只能有一条编辑草稿：先清空所有草稿
    this.draftRows = [];
    this.openDropdown = null;
    const [date, time] = (rule.executeTime || '').split(' ');
    // weekly 规则的日期部分仅作占位，取其星期几作为多选回显
    const weekday = date ? new Date(date + 'T00:00:00').getDay() || 7 : 0;
    this.draftRows.push({
      editingRuleId: rule.id,
      ruleType: rule.ruleType || 'extension',
      extension: rule.extension,
      action: rule.action,
      repeatType: rule.repeatType,
      // daily/weekly 规则的日期部分仅作占位（daily 保存时会被当天日期替换）
      date:
        rule.repeatType === 'daily' || rule.repeatType === 'weekly'
          ? rule.repeatType === 'daily'
            ? localToday()
            : date || localToday()
          : date || localToday(),
      time: time || '08:00',
      weekday: rule.repeatType === 'weekly' && weekday ? weekday : todayWeekday(),
      text: '',
      settingKey: rule.settingKey || 'welcome_title',
      settingValue: rule.settingValue || '',
      settingValues: rule.settingsMap || {},
      settingOriginals: rule.settingsOriginal || {},
      remark: rule.remark || '',
    });
  }

  // 该行扩展下拉的数据源：ext-setting 只列出注册了设置项的扩展
  rowExtensionOptions(row: DraftRow): Record<string, string> {
    return row.ruleType === 'ext-setting' ? this.settingExtensionOptions() : this.extensionOptions();
  }

  filteredExtensionOptions(row: DraftRow): [string, string][] {
    const options = this.rowExtensionOptions(row);
    const keyword = (row.text || '').trim().toLowerCase();
    const entries = Object.keys(options).map((id) => [id, options[id]] as [string, string]);
    if (!keyword) {
      return entries;
    }
    return entries.filter(([id, title]) => id.toLowerCase().indexOf(keyword) !== -1 || title.toLowerCase().indexOf(keyword) !== -1);
  }

  openExtDropdown(index: number) {
    const row = this.draftRows[index];
    const options = this.rowExtensionOptions(row);
    // 已选插件有效时清空过滤文本，展开即显示全部列表；
    // 无效（手输内容）时保留原文本便于继续编辑
    row.text = options[row.extension] ? '' : row.extension;
    this.openDropdown = index;
  }

  closeExtDropdown(index: number) {
    setTimeout(() => {
      if (this.openDropdown === index) {
        this.openDropdown = null;
        m.redraw();
      }
    }, 200);
  }

  // 组装某一行最终提交用的 executeTime 列表（每周可能展开成多条规则）
  buildExecuteTimes(row: DraftRow): string[] | null {
    if (row.repeatType === 'daily') {
      return [localToday() + ' ' + row.time];
    }

    if (row.repeatType === 'weekly') {
      if (!row.weekday) {
        alert(app.translator.trans('mattoid-plugin-scheduler.admin.weekday-required'));
        return null;
      }
      // 日期归一化为该星期几最近一次出现的日期（后端只看星期几+时刻）
      return [nextWeekdayDate(row.weekday) + ' ' + row.time];
    }

    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(row.date || '');
    if (!m) {
      return [row.date + ' ' + row.time];
    }

    if (row.repeatType === 'monthly') {
      // 每月只取“日”：归一化为 2000-01-日，保证每月某日执行
      return ['2000-01-' + m[3] + ' ' + row.time];
    }

    if (row.repeatType === 'yearly') {
      // 每年只取“月-日”：归一化为 2000-月-日，保证每年某月某日执行
      return ['2000-' + m[2] + '-' + m[3] + ' ' + row.time];
    }

    return [row.date + ' ' + row.time];
  }

  // 组装某一行提交的 attributes
  buildAttributes(row: DraftRow, executeTime: string): Record<string, any> | null {
    const base = {
      ruleType: row.ruleType,
      action: row.action,
      repeatType: row.repeatType,
      executeTime,
      remark: row.remark,
    };

    if (row.ruleType === 'setting') {
      return {
        ...base,
        settingKey: row.settingKey,
        settingValue: row.action === 'set' ? row.settingValue : '',
      };
    }

    if (row.ruleType === 'ext-setting') {
      // 至少需要一项被修改的配置
      if (!row.settingValues || Object.keys(row.settingValues).length === 0) {
        alert(app.translator.trans('mattoid-plugin-scheduler.admin.ext-setting-no-changes'));
        return null;
      }
      return {
        ...base,
        extension: row.extension,
        settingsMap: row.settingValues,
        settingsOriginal: row.settingOriginals || {},
      };
    }

    return {
      ruleType: row.ruleType,
      extension: row.extension,
      action: row.action,
      repeatType: row.repeatType,
      executeTime,
      remark: row.remark,
    };
  }

  saveRows() {
    this.saving = true;

    const requests = [];
    for (const row of this.draftRows) {
      const executeTimes = this.buildExecuteTimes(row);
      if (!executeTimes) {
        this.saving = false;
        return;
      }

      const attributes = this.buildAttributes(row, executeTimes[0]);
      if (!attributes) {
        this.saving = false;
        return;
      }

      requests.push(
        app.request({
          method: row.editingRuleId ? 'PUT' : 'POST',
          url: app.forum.attribute('apiUrl') + '/plugin-scheduler/rules' + (row.editingRuleId ? '/' + row.editingRuleId : ''),
          body: {
            data: {
              attributes,
            },
          },
        })
      );
    }

    Promise.all(requests)
      .then(() => {
        this.draftRows = [];
      })
      .then(() => {
        this.saving = false;
        this.loadRules();
      })
      .catch(() => {
        // 保存失败（如 422 校验错误）要复位 saving，否则会永远卡在保存中
        this.saving = false;
        app.alerts.show({ type: 'error' }, app.translator.trans('mattoid-plugin-scheduler.admin.save-failed'));
        m.redraw();
      });
  }

  timezone(): string {
    const settings = app.data.settings as Record<string, string>;
    return settings['mattoid-plugin-scheduler.timezone'] || 'Asia/Shanghai';
  }

  saveTimezone(value: string) {
    app
      .request({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/settings',
        body: { 'mattoid-plugin-scheduler.timezone': value },
      })
      .then(() => {
        // 同步内存中的设置值，否则 Select 会在下次 redraw 时跳回保存前的旧值
        app.data.settings['mattoid-plugin-scheduler.timezone'] = value;
        m.redraw();
      });
  }

  deleteRule(id: number) {
    if (!confirm(app.translator.trans('mattoid-plugin-scheduler.admin.confirm-delete'))) return;

    app
      .request({
        method: 'DELETE',
        url: app.forum.attribute('apiUrl') + '/plugin-scheduler/rules/' + id,
      })
      .then(() => this.loadRules());
  }

  // 列表上的启用/禁用开关：PUT 回完整属性，编辑场景外的纯状态切换
  toggleRule(rule: Rule) {
    const attributes: Record<string, any> =
      rule.ruleType === 'setting'
        ? {
            ruleType: rule.ruleType,
            action: rule.action,
            repeatType: rule.repeatType,
            executeTime: rule.executeTime,
            settingKey: rule.settingKey,
            settingValue: rule.action === 'set' ? rule.settingValue : '',
            remark: rule.remark,
            isEnabled: !rule.isEnabled,
          }
        : rule.ruleType === 'ext-setting'
        ? {
            ruleType: rule.ruleType,
            extension: rule.extension,
            action: rule.action,
            repeatType: rule.repeatType,
            executeTime: rule.executeTime,
            settingsMap: rule.settingsMap,
            settingsOriginal: rule.settingsOriginal,
            remark: rule.remark,
            isEnabled: !rule.isEnabled,
          }
        : {
            ruleType: rule.ruleType,
            extension: rule.extension,
            action: rule.action,
            repeatType: rule.repeatType,
            executeTime: rule.executeTime,
            remark: rule.remark,
            isEnabled: !rule.isEnabled,
          };

    app
      .request({
        method: 'PUT',
        url: app.forum.attribute('apiUrl') + '/plugin-scheduler/rules/' + rule.id,
        body: {
          data: {
            attributes,
          },
        },
      })
      .then(() => this.loadRules());
  }

  // 设置类规则的值编辑器：标题类用单行 input，描述/页眉/页脚/LESS 用大文本框，
  // logo/站点图标用与 core 一致的上传模式，颜色/布尔用各自的专用控件
  settingValueInput(row: DraftRow) {
    const target = SETTING_TARGETS[row.settingKey];
    const onchange = (value: string) => {
      row.settingValue = value;
    };

    if (target && target.type === 'image') {
      return this.settingImageInput(row);
    }

    if (target && target.type === 'text') {
      return (
        <input
          className="FormControl PluginSchedulerSettingValue"
          value={row.settingValue}
          placeholder={app.translator.trans('mattoid-plugin-scheduler.admin.setting-value-placeholder')}
          oninput={(e: InputEvent) => onchange((e.target as HTMLInputElement).value)}
        />
      );
    }

    if (!target || target.type === 'textarea') {
      return (
        <textarea
          className="FormControl PluginSchedulerSettingValue"
          rows={6}
          value={row.settingValue}
          placeholder={app.translator.trans('mattoid-plugin-scheduler.admin.setting-value-placeholder')}
          oninput={(e: InputEvent) => onchange((e.target as HTMLTextAreaElement).value)}
        />
      );
    }

    if (target.type === 'color') {
      return (
        <input
          type="color"
          className="PluginSchedulerColor"
          value={/^#[0-9a-fA-F]{6}$/.test(row.settingValue) ? row.settingValue : '#4d698e'}
          oninput={(e: InputEvent) => onchange((e.target as HTMLInputElement).value)}
        />
      );
    }

    // bool
    return (
      <Select
        value={row.settingValue === '1' ? '1' : '0'}
        options={{
          '1': app.translator.trans('mattoid-plugin-scheduler.admin.bool-on'),
          '0': app.translator.trans('mattoid-plugin-scheduler.admin.bool-off'),
        }}
        onchange={onchange}
      />
    );
  }

  // logo / 站点图标：复刻 core 外观页的上传交互，但只上传存文件、把路径写进规则值，
  // 不立即改动站点当前设置（生效时机由定时规则决定）
  settingImageInput(row: DraftRow) {
    const uploading = !!this.uploadingImage;
    const url = row.settingValue ? app.forum.attribute('baseUrl') + '/assets/' + row.settingValue : '';

    const pick = () => {
      this.uploadingImage = true;
      m.redraw();

      const $input = $('<input type="file" accept="image/*">');
      $input
        .appendTo('body')
        .hide()
        .trigger('click')
        .on('change', (e) => {
          const body = new FormData();
          body.append('file', $(e.target)[0].files[0]);

          app
            .request({
              method: 'POST',
              url: app.forum.attribute('apiUrl') + '/plugin-scheduler/upload-image?key=' + row.settingKey,
              serialize: (raw: any) => raw,
              body,
            })
            .then((response: any) => {
              row.settingValue = response.path;
            })
            .catch(() => {
              alert(app.translator.trans('mattoid-plugin-scheduler.admin.image-upload-failed'));
            })
            .then(() => {
              this.uploadingImage = false;
              m.redraw();
            });
        });
    };

    return (
      <div className="PluginSchedulerImage">
        {url && <img className="PluginSchedulerImage-preview" src={url} alt="" />}
        <Button className="Button" icon="fas fa-upload" disabled={uploading} onclick={pick}>
          {uploading
            ? app.translator.trans('mattoid-plugin-scheduler.admin.image-uploading')
            : url
            ? app.translator.trans('mattoid-plugin-scheduler.admin.image-change')
            : app.translator.trans('mattoid-plugin-scheduler.admin.image-upload')}
        </Button>
        {url && (
          <Button
            className="Button Button--danger"
            onclick={() => {
              row.settingValue = '';
            }}
          >
            {app.translator.trans('mattoid-plugin-scheduler.admin.image-clear')}
          </Button>
        )}
      </div>
    );
  }

  // 每周：星期几单选下拉框（1-7）
  weekdayOptions(): Record<string, string> {
    const options: Record<string, string> = {};
    WEEKDAY_KEYS.forEach((key, i) => {
      options[String(i + 1)] = app.translator.trans(`mattoid-plugin-scheduler.admin.${key}`);
    });
    return options;
  }

  ruleName(rule: Rule, options: Record<string, string>): string {    if (rule.ruleType === 'setting') {
      // 白名单之外的键（扩展设置）没有翻译，直接显示原始 key
      const keyLabel = SETTING_TARGETS[rule.settingKey]
        ? app.translator.trans(`mattoid-plugin-scheduler.admin.setting-${rule.settingKey}`)
        : rule.settingKey;
      const actionLabel =
        rule.action === 'reset'
          ? app.translator.trans('mattoid-plugin-scheduler.admin.action-reset')
          : app.translator.trans('mattoid-plugin-scheduler.admin.action-set');
      const valuePreview = rule.action === 'set' && rule.settingValue ? ` = ${rule.settingValue.slice(0, 40)}` : '';
      return `[${keyLabel}] ${actionLabel}${valuePreview}`;
    }
    if (rule.ruleType === 'ext-setting') {
      const actionLabel =
        rule.action === 'reset'
          ? app.translator.trans('mattoid-plugin-scheduler.admin.action-reset')
          : app.translator.trans('mattoid-plugin-scheduler.admin.action-set');
      const count = Object.keys(rule.settingsMap || {}).length;
      return `${options[rule.extension] || rule.extension} / ${actionLabel} ×${count}`;
    }
    return options[rule.extension] || rule.extension;
  }

  content() {
    const options = this.extensionOptions();

    return (
      <div className="ExtensionPage-body">
        <div className="container">
          <div className="PluginSchedulerPanel">
            <div className="PluginSchedulerPanel-title">
              {app.translator.trans('mattoid-plugin-scheduler.admin.rules-title')}
            </div>

            {this.loadingRules && (
              <div className="PluginSchedulerEmpty">
                {app.translator.trans('core.admin.loading')}
              </div>
            )}

            {!this.loadingRules && this.rules.length === 0 && this.draftRows.length === 0 && (
              <div className="PluginSchedulerEmpty">
                {app.translator.trans('mattoid-plugin-scheduler.admin.no-rules')}
              </div>
            )}

            {!this.loadingRules &&
              this.rules.map((rule) => (
                <div className={'PluginSchedulerRow' + (rule.isEnabled ? '' : ' PluginSchedulerRow--disabled')} key={rule.id}>
                  <span className="PluginSchedulerRow-name">
                    {rule.remark ? rule.remark + '（' + this.ruleName(rule, options) + '）' : this.ruleName(rule, options)}
                  </span>
                  <span className="PluginSchedulerRow-meta">
                    {app.translator.trans(`mattoid-plugin-scheduler.admin.repeat-${rule.repeatType}`)}
                    {' / '}
                    {rule.executeTime}
                  </span>
                  <Button className="Button" onclick={() => this.toggleRule(rule)}>
                    {app.translator.trans(rule.isEnabled ? 'mattoid-plugin-scheduler.admin.rule-disable' : 'mattoid-plugin-scheduler.admin.rule-enable')}
                  </Button>
                  <Button className="Button" onclick={() => this.editRule(rule)}>
                    {app.translator.trans('mattoid-plugin-scheduler.admin.edit')}
                  </Button>
                  <Button className="Button Button--danger" onclick={() => this.deleteRule(rule.id)}>
                    {app.translator.trans('mattoid-plugin-scheduler.admin.delete')}
                  </Button>
                </div>
              ))}

            {this.draftRows.map((row, index) => (
              <div className="PluginSchedulerRow PluginSchedulerRow--draft" key={index}>
                <Select
                  value={row.ruleType}
                  options={{
                    extension: app.translator.trans('mattoid-plugin-scheduler.admin.rule-type-extension'),
                    setting: app.translator.trans('mattoid-plugin-scheduler.admin.rule-type-setting'),
                    'ext-setting': app.translator.trans('mattoid-plugin-scheduler.admin.rule-type-ext-setting'),
                  }}
                  onchange={(value: string) => {
                    row.ruleType = value;
                    row.action = value === 'extension' ? 'enable' : 'set';
                    row.text = '';
                    // 扩展设置不预置插件，避免未选择时直接落入排序第一的扩展
                    if (value === 'ext-setting') {
                      row.extension = '';
                      row.settingValues = {};
                      row.settingOriginals = {};
                    } else if (value === 'extension' && !row.extension) {
                      row.extension = Object.keys(this.extensionOptions())[0] || '';
                    }
                    this.openDropdown = null;
                  }}
                />

                {(row.ruleType === 'extension' || row.ruleType === 'ext-setting') && (
                  <div className="PluginSchedulerExtSelect">
                    <input
                      class="FormControl PluginSchedulerExtSearch"
                      value={this.openDropdown === index ? row.text : this.rowExtensionOptions(row)[row.extension] || row.extension}
                      placeholder={app.translator.trans('mattoid-plugin-scheduler.admin.extension-search-placeholder')}
                      onclick={() => this.openExtDropdown(index)}
                      onfocus={() => this.openExtDropdown(index)}
                      oninput={(e: InputEvent) => {
                        row.text = (e.target as HTMLInputElement).value;
                        const rowOptions = this.rowExtensionOptions(row);
                        const matched = Object.keys(rowOptions).find((id) => id === row.text || rowOptions[id] === row.text);
                        row.extension = matched || row.text;
                      }}
                      onblur={() => this.closeExtDropdown(index)}
                    />
                    <Button
                      className="Button PluginSchedulerExtSelect-toggle"
                      icon="fas fa-caret-down"
                      onclick={() => {
                        if (this.openDropdown === index) {
                          this.openDropdown = null;
                        } else {
                          this.openExtDropdown(index);
                        }
                      }}
                    />
                    {this.openDropdown === index && (
                      <div className="PluginSchedulerExtSelect-menu">
                        {this.filteredExtensionOptions(row).length === 0 && (
                          <div className="PluginSchedulerExtSelect-empty">
                            {app.translator.trans('mattoid-plugin-scheduler.admin.no-extension-matched')}
                          </div>
                        )}
                        {this.filteredExtensionOptions(row).map(([id, title]) => (
                          <div
                            className="PluginSchedulerExtSelect-item"
                            onmousedown={() => {
                              row.extension = id;
                              row.text = title;
                              this.openDropdown = null;
                            }}
                          >
                            <span className="PluginSchedulerExtSelect-item-title">{title}</span>
                          </div>
                        ))}
                      </div>
                    )}
                  </div>
                )}

                {(row.ruleType === 'extension' || row.ruleType === 'ext-setting') &&
                  this.settingExtensionOptions()[row.extension] && (
                    <Button
                      className={'Button' + (this.configuringExt === row.extension ? ' Button--primary' : '')}
                      icon="fas fa-cog"
                      onclick={() => {
                        this.configuringExt = this.configuringExt === row.extension ? null : row.extension;
                      }}
                    >
                      {app.translator.trans('mattoid-plugin-scheduler.admin.configure-extension')}
                    </Button>
                  )}

                {row.ruleType === 'setting' && (
                  <Select
                    value={row.settingKey}
                    options={this.settingOptions()}
                    onchange={(value: string) => {
                      row.settingKey = value;
                      row.settingValue = '';
                    }}
                  />
                )}

                {row.ruleType === 'ext-setting' &&
                  (this.settingExtensionOptions()[row.extension] ? (
                    <ExtensionSettingsForm
                      extensionId={row.extension}
                      ondirty={(values: Record<string, string>, originals: Record<string, string | null>) => {
                        row.settingValues = values;
                        row.settingOriginals = originals;
                      }}
                    />
                  ) : (
                    <span className="PluginSchedulerHint">{app.translator.trans('mattoid-plugin-scheduler.admin.ext-setting-select-first')}</span>
                  ))}

                <Select
                  value={row.action}
                  options={this.actionOptions(row)}
                  onchange={(value: string) => {
                    row.action = value;
                  }}
                />

                {row.ruleType === 'setting' && row.action === 'set' && this.settingValueInput(row)}

                <Select
                  value={row.repeatType}
                  options={{
                    once: app.translator.trans('mattoid-plugin-scheduler.admin.repeat-once'),
                    daily: app.translator.trans('mattoid-plugin-scheduler.admin.repeat-daily'),
                    weekly: app.translator.trans('mattoid-plugin-scheduler.admin.repeat-weekly'),
                    monthly: app.translator.trans('mattoid-plugin-scheduler.admin.repeat-monthly'),
                    yearly: app.translator.trans('mattoid-plugin-scheduler.admin.repeat-yearly'),
                  }}
                  onchange={(value: string) => {
                    row.repeatType = value;
                  }}
                />
                {row.repeatType === 'weekly' && (
                  <Select
                    wrapperAttrs={{ className: 'PluginSchedulerWeekday' }}
                    value={String(row.weekday || '')}
                    options={this.weekdayOptions()}
                    onchange={(value: string) => {
                      row.weekday = parseInt(value, 10);
                    }}
                  />
                )}
                {(row.repeatType === 'once' || row.repeatType === 'monthly' || row.repeatType === 'yearly') && (
                  <input
                    type="date"
                    class="PluginSchedulerDate"
                    value={row.date}
                    onchange={(e: InputEvent) => {
                      row.date = (e.target as HTMLInputElement).value;
                    }}
                  />
                )}
                <input
                  type="time"
                  value={row.time}
                  onchange={(e: InputEvent) => {
                    row.time = (e.target as HTMLInputElement).value;
                  }}
                />
                <input
                  className="FormControl PluginSchedulerRemark"
                  value={row.remark}
                  placeholder={app.translator.trans('mattoid-plugin-scheduler.admin.remark-placeholder')}
                  oninput={(e: InputEvent) => {
                    row.remark = (e.target as HTMLInputElement).value;
                  }}
                />
                <Button
                  className="Button Button--danger"
                  onclick={() => {
                    this.draftRows.splice(index, 1);
                  }}
                >
                  {app.translator.trans('mattoid-plugin-scheduler.admin.delete')}
                </Button>

                {this.configuringExt === row.extension && (
                  <ExtensionSettingsEditor
                    extensionId={row.extension}
                    onclose={() => {
                      this.configuringExt = null;
                    }}
                  />
                )}
              </div>
            ))}

            <div className="PluginSchedulerActions">
              <Button className="Button" onclick={() => this.addRow()}>
                {app.translator.trans('mattoid-plugin-scheduler.admin.add-rule')}
              </Button>
              {this.draftRows.length > 0 && (
                <Button className="Button Button--primary" disabled={this.saving} onclick={() => this.saveRows()}>
                  {app.translator.trans('mattoid-plugin-scheduler.admin.save-rules')}
                </Button>
              )}
              <div className="PluginSchedulerTimezone">
                <span className="PluginSchedulerTimezone-label">
                  {app.translator.trans('mattoid-plugin-scheduler.admin.timezone')}
                </span>
                <Select
                  value={this.timezone()}
                  options={TIMEZONE_OPTIONS.reduce((acc, tz) => ({ ...acc, [tz]: tz }), {} as Record<string, string>)}
                  onchange={(value: string) => this.saveTimezone(value)}
                />
              </div>
            </div>
          </div>
        </div>
      </div>
    );
  }
}
