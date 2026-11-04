import app from 'flarum/admin/app';
import AdminPage from 'flarum/admin/components/AdminPage';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import type { IPageAttrs } from 'flarum/common/components/Page';

export interface ExtensionSettingsEditorAttrs extends IPageAttrs {
  extensionId: string;
  // 保存成功或点击收起后由父组件关闭编辑器
  onclose: () => void;
}

/**
 * 内嵌扩展设置编辑器：在调度页直接查看、修改并保存某扩展注册的后台设置项，
 * 不再跳转到 /admin#/extension/<id> 页面。
 * 复用 core AdminPage 的 buildSettingComponent / dirty / saveSettings 逻辑，
 * 与扩展自己的设置页行为一致（POST /settings，成功后清空脏状态）。
 */
export default class ExtensionSettingsEditor extends AdminPage<ExtensionSettingsEditorAttrs> {
  // core AdminPage 的 saveSettings 会读写 this.saving，d.ts 未声明，这里补齐
  saving: boolean = false;

  className() {
    return '';
  }

  view(vnode: Mithril.Vnode<ExtensionSettingsEditorAttrs, this>) {
    const settings = app.extensionData.getSettings(this.attrs.extensionId);

    if (!settings || settings.length === 0) {
      return null;
    }

    return (
      <div className="Form PluginSchedulerExtSettings PluginSchedulerExtSettings--editor">
        {settings.map(this.buildSettingComponent.bind(this))}
        <div className="PluginSchedulerExtSettings-actions">
          <Button
            className="Button Button--primary"
            disabled={this.saving || this.isChanged() === 0}
            onclick={(e: Event) => this.saveSettings(e as any)}
          >
            {app.translator.trans('mattoid-plugin-scheduler.admin.ext-settings-save')}
          </Button>
          <Button className="Button" onclick={() => this.attrs.onclose()}>
            {app.translator.trans('mattoid-plugin-scheduler.admin.ext-settings-collapse')}
          </Button>
        </div>
      </div>
    );
  }

  onsaved() {
    super.onsaved();
    this.attrs.onclose();
  }

  onsavefailed() {
    super.onsavefailed();
    m.redraw();
  }
}
