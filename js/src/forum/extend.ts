import Extend from 'flarum/common/extenders';
import RecruitingBoard from './models/RecruitingBoard';

export default [
  new Extend.Store().add('recruiting-boards', RecruitingBoard),

  // The page is its own chunk, loaded only when /recruiting is opened.
  new Extend.Routes().add('recruiting', '/recruiting', () => import('./pages/RecruitingPage')),
];
