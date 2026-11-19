import Extend from 'flarum/common/extenders';

export default [
  // The page is its own chunk, loaded only when /recruiting is opened.
  new Extend.Routes().add('recruiting', '/recruiting', () => import('./pages/RecruitingPage')),
];
