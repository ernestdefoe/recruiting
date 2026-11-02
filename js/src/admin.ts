// Settings are registered declaratively via the Admin extender in extend.ts.
// Re-exporting `extend` here is what causes flarum-webpack-config /
// bootExtensions to pick up and apply the extender array.
import extend from './extend';
export { extend };
