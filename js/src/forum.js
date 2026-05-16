import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import IndexPage from 'flarum/forum/components/IndexPage';
import RecruitingWidget from './forum/components/RecruitingWidget';

/**
 * Walk a Mithril vnode tree looking for the first DOM element whose
 * className includes `targetClass`, then push `injectedVnode` into its
 * children.  Component vnodes (function/object tags) are skipped.
 */
function injectInto(node, targetClass, injectedVnode) {
  if (!node || typeof node !== 'object') return false;
  if (typeof node.tag === 'function' || typeof node.tag === 'object') return false;

  const cls = (node.attrs && (node.attrs.className || node.attrs['class'])) || '';
  if (typeof cls === 'string' && cls.split(' ').includes(targetClass)) {
    if (Array.isArray(node.children)) {
      node.children.push(injectedVnode);
    } else {
      node.children = node.children != null
        ? [node.children, injectedVnode]
        : [injectedVnode];
    }
    return true;
  }

  if (Array.isArray(node.children)) {
    return node.children.some((child) => injectInto(child, targetClass, injectedVnode));
  }
  return false;
}

app.initializers.add('ernestdefoe-recruiting', () => {
  /**
   * Inject the RecruitingWidget into the sidebar.
   *
   * Priority 1 — If the GN theme (fbsfb) is installed it will have already
   * created a .GN-widgetSidebar.  We append into that so all widgets sit in
   * one coherent column.
   *
   * Priority 2 — If fbsfb is not installed we create a .GN-widgetSidebar
   * ourselves inside .sideNavContainer (Flarum 2's native flex container).
   * The recruiting LESS file adds the necessary flex + sticky styles.
   */
  extend(IndexPage.prototype, 'view', function (vnode) {
    if (!vnode) return;

    const widget = m(RecruitingWidget);

    // Try the GN theme sidebar first.
    const injected = injectInto(vnode, 'GN-widgetSidebar', widget);

    // Fallback: create a standalone sidebar next to the discussion list.
    if (!injected) {
      injectInto(vnode, 'sideNavContainer', m('.GN-widgetSidebar', [widget]));
    }
  });
});
