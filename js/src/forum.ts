import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import LinkButton from 'flarum/common/components/LinkButton';
import RecruitingPage from './forum/pages/RecruitingPage';

app.initializers.add('ernestdefoe-recruiting', () => {
  // Register the /recruiting route.
  app.routes.recruiting = { path: '/recruiting', component: RecruitingPage };

  // Add a "Recruiting" link to the IndexSidebar nav (Flarum 2's navItems lives
  // on IndexSidebar, not IndexPage).
  extend(IndexSidebar.prototype, 'navItems', function (items) {
    items.add(
      'ernestdefoe-recruiting',
      m(LinkButton, {
        href: app.route('recruiting'),
        icon: 'fa-solid fa-star',
      }, app.translator.trans('ernestdefoe-recruiting.forum.nav.label')),
      -10
    );
  });
});
