import app from 'flarum/admin/app';
import AdminPage from 'flarum/admin/components/AdminPage';
import type Mithril from 'mithril';
import type { IPageAttrs } from 'flarum/common/components/Page';

export interface ExtensionSettingsFormAttrs extends IPageAttrs {
  extensionId: string;
  // 任一设置项被修改（或恢复）后回调脏数据与原始值，供草稿行收集
  ondirty: (values: Record<string, string>, originals: Record<string, string | null>) => void;
}

/**
 * 只读复用 core AdminPage 的设置表单构建逻辑（buildSettingComponent / setting / dirty），
 * 渲染某扩展自己在 ExtensionPage 上注册的设置项；不做任何保存提交。
 * 初始值取 app.data.settings，与 /admin#/extension/<id> 页面完全一致。
 */
export default class ExtensionSettingsForm extends AdminPage<ExtensionSettingsFormAttrs> {
  className() {
    return '';
  }

  view(vnode: Mithril.Vnode<ExtensionSettingsFormAttrs, this>) {
    const settings = app.extensionData.getSettings(this.attrs.extensionId);

    if (!settings) {
      return null;
    }

    return <div className="Form PluginSchedulerExtSettings">{settings.map(this.buildSettingComponent.bind(this))}</div>;
  }

  onupdate(vnode: Mithril.VnodeDOM<ExtensionSettingsFormAttrs, this>) {
    super.onupdate(vnode);
    this.pushDirty();
  }

  pushDirty() {
    const values = this.dirty();
    const originals: Record<string, string | null> = {};

    Object.keys(values).forEach((key) => {
      originals[key] = (app.data.settings as Record<string, any>)[key] ?? null;
    });

    this.attrs.ondirty(values, originals);
  }
}
