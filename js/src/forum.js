import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import IndexPage from 'flarum/forum/components/IndexPage';
import LinkButton from 'flarum/common/components/LinkButton';
import RecruitingPage from './forum/pages/RecruitingPage';

app.initializers.add('ernestdefoe-recruiting', () => {
  // Register the /recruiting route.
  app.routes.recruiting = { path: '/recruiting', component: RecruitingPage };

  // Add a "Recruiting" link to the IndexPage sidebar nav so users can reach
  // the page from the forum home screen without needing to know the URL.
  extend(IndexPage.prototype, 'navItems', function (items) {
    items.add(
      'recruiting',
      m(LinkButton, {
        href: app.route('recruiting'),
        icon: 'fas fa-star',
      }, app.translator.trans('ernestdefoe-recruiting.forum.nav.label')),
      // Insert below the standard nav items (lower priority = lower position).
      -10
    );
  });
});
