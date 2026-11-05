import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import classList from 'flarum/common/utils/classList';
import extractText from 'flarum/common/utils/extractText';
import MediaHero from './MediaHero';
import { t, text, img, linkFor } from '../util';

// The card's own buttons. The menu offers "details" in their place; what other
// extensions add to the card (Curio's collection, wishlist and owners) it keeps.
const OWN = ['source', 'episode', 'trailer', 'discussShow', 'attribution'];

// Fixed to the bottom of the screen: the button sits above whichever is there.
const BELOW = [
  '.Quickdraw-fab, .MobileTab-bar, .Composer.minimized, .AlertManager .Alert, #bespoke-totop, .Bespoke-toTop',
  '.pl-heads > *, .LobbyShell-dock > *, .ConcourseShell-tabbar, .CookieBanner, .cc-window, #onetrust-banner-sdk',
].join(', ');

/** The bottom of the bar fixed at the top. On phones #header is the drawer; the bar is #app-navigation. */
function topBar() {
  let bottom = 0;
  document.querySelectorAll('#header, #app-navigation').forEach((el) => {
    const r = el.getBoundingClientRect();
    if (r.top <= 1 && r.bottom > 0 && r.height < 160) bottom = Math.max(bottom, r.bottom);
  });
  return bottom;
}

const ICONS = { movie: 'fas fa-film', tv: 'fas fa-tv', game: 'fas fa-gamepad', music: 'fas fa-compact-disc', book: 'fas fa-book' };

/**
 * Once the card has scrolled away, a button in the corner opens a menu for
 * each of the discussion's titles: back to its card, and what other
 * extensions put on it. Attrs: page (the DiscussionPage), show(media).
 */
export default class TitleDock extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.open = false;
    this.away = false;
  }

  oncreate(vnode) {
    super.oncreate(vnode);

    let frame = 0;
    this.onMove = () => {
      if (frame) return;
      frame = requestAnimationFrame(() => {
        frame = 0;
        const hero = document.querySelector('.MarqueeHero');
        // Gone once it has slid under the bar at the top.
        const away = !!hero && hero.getBoundingClientRect().bottom < topBar();
        if (away !== this.away) {
          this.away = away;
          if (!away) this.open = false;
          m.redraw();
        }
        this.lift();
      });
    };
    this.onKey = (e) => {
      if (e.key === 'Escape' && this.open) {
        this.open = false;
        m.redraw();
      }
    };
    this.outside = (e) => {
      if (this.open && !this.element.contains(e.target)) {
        this.open = false;
        m.redraw();
      }
    };

    window.addEventListener('scroll', this.onMove, { passive: true });
    window.addEventListener('resize', this.onMove);
    document.addEventListener('keydown', this.onKey);
    document.addEventListener('pointerdown', this.outside);
    this.onMove();
  }

  onupdate(vnode) {
    super.onupdate(vnode);
    this.lift();
  }

  onremove(vnode) {
    super.onremove(vnode);
    window.removeEventListener('scroll', this.onMove);
    window.removeEventListener('resize', this.onMove);
    document.removeEventListener('keydown', this.onKey);
    document.removeEventListener('pointerdown', this.outside);
  }

  /** Clear of anything fixed to the bottom right: tab bars, banners, Quickdraw's button. */
  lift() {
    if (!this.element) return;
    const height = window.innerHeight;
    let lift = 0;
    document.querySelectorAll(BELOW).forEach((el) => {
      const r = el.getBoundingClientRect();
      if (r.width && r.height && r.top > height / 2 && r.right > window.innerWidth - 80) lift = Math.max(lift, height - r.top);
    });
    // acpl/mobile-tab slides away while scrolling down: go by its class, not where it is mid-slide.
    const tab = document.querySelector('.MobileTab:not(.MobileTab--hidden)');
    if (tab) lift = Math.max(lift, tab.offsetHeight);
    this.element.style.setProperty('--marquee-dock-lift', `${lift}px`);

    // The menu opens upward, into the room between the top bar and the button.
    const header = topBar();
    const button = this.element.querySelector('.MarqueeDock-button');
    if (button) this.element.style.setProperty('--marquee-dock-room', `${Math.max(160, button.getBoundingClientRect().top - header - 20)}px`);
  }

  titles() {
    const discussion = this.attrs.page.discussion;
    const main = discussion && linkFor(discussion);
    if (!main || !main.media.details()) return null;

    const related = (discussion.marqueeRelatedMedia() || []).filter((work) => work && work.details());
    return { discussion, main, related };
  }

  view() {
    const titles = this.titles();
    const composing = ['normal', 'fullScreen'].includes(app.composer.position);
    if (!titles || !this.away || composing) return <div className="MarqueeDock is-hidden" />;

    const { main } = titles;
    const label = text('dock.open', { title: main.media.title() });
    const icon = app.forum.attribute('marqueeDockIcon');

    return (
      <div className={classList('MarqueeDock', { 'is-open': this.open })}>
        {this.open ? this.menu(titles) : null}
        <button
          type="button"
          className="MarqueeDock-button"
          aria-expanded={String(this.open)}
          aria-controls="MarqueeDock-menu"
          aria-label={label}
          title={label}
          onclick={() => (this.open = !this.open)}
        >
          {icon || !main.media.poster() ? (
            this.open ? null : (
              <Icon name={icon || ICONS[main.media.kind()] || 'fas fa-film'} />
            )
          ) : (
            <img src={img(main.media.poster(), 'w92')} alt="" />
          )}
          {this.open ? <Icon name="fas fa-xmark" className="MarqueeDock-close" /> : null}
        </button>
      </div>
    );
  }

  menu({ discussion, main, related }) {
    const works = [main, ...related.map((media) => ({ media, season: null, episode: null, seasonData: null }))];

    return (
      <div className="MarqueeDock-menu Dropdown-menu" id="MarqueeDock-menu" role="menu">
        {works.map((link) => (
          <section className="MarqueeDock-title">
            <h3>
              {link.media.poster() ? <img src={img(link.media.poster(), 'w92')} alt="" /> : null}
              <span>
                {link.media.title()}
                {link.media.year() ? <small>{link.media.year()}</small> : null}
              </span>
            </h3>
            <ul>
              <li>
                <button type="button" className="Button" role="menuitem" onclick={() => this.details(link.media)}>
                  <Icon name="fas fa-circle-info" className="Button-icon" />
                  <span className="Button-label">{t('dock.details')}</span>
                </button>
              </li>
              {this.extras(discussion, link, main).map((item) => (
                <li onclick={() => (this.open = false)}>{item}</li>
              ))}
            </ul>
          </section>
        ))}
      </div>
    );
  }

  /**
   * What other extensions add to this title's card, from the card's own list,
   * so each keeps working as it does there. An icon-only button gets its label.
   */
  extras(discussion, link, main) {
    const card = Object.create(MediaHero.prototype);
    card.attrs = { discussion, link, main };
    card.seasonData = null;

    const items = card.actionItems();
    OWN.forEach((key) => items.has(key) && items.remove(key));

    // Plain buttons and links only: a menu row can't hold a dropdown.
    const plain = (vnode) =>
      vnode && (typeof vnode.tag === 'string' ? ['a', 'button'].includes(vnode.tag) : vnode.tag === Button || vnode.tag?.prototype instanceof Button);

    return items
      .toArray()
      .filter(plain)
      .map((vnode) => {
        const attrs = vnode.attrs || {};
        if (vnode.children && vnode.children.length) return vnode;
        const label = attrs.title || attrs['aria-label'];
        return label ? m(vnode.tag, { ...attrs, className: (attrs.className || '').replace('Button--icon', '') }, extractText(label)) : vnode;
      });
  }

  /** Back to the card, showing this title. */
  details(media) {
    this.open = false;
    this.attrs.show(media);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
}
