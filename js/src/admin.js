import app from 'flarum/admin/app';

// Settings are registered declaratively via the Admin extender in extend.js.
// Re-exporting `extend` here is what causes flarum-webpack-config /
// bootExtensions to pick up and apply the extender array.
export { default as extend } from './extend';

app.initializers.add('ernestdefoe-recruiting', () => {});
