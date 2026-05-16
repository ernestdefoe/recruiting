// Export the Admin extender array as the named 'extend' export.
// Flarum reads flarum.extensions['id'].extend at boot and calls
// each extender's .extend(app, id) to register settings fields.
export { default as extend } from './src/extend';
export * from './src/admin';
