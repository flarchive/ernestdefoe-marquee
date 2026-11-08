import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';
import { t, text, img, sourceName } from '../util';
import { search, lookup } from '../api';

/**
 * A title input that, given a `kind`, searches that source and offers a
 * dropdown of matches; without one it is a plain input. Used for the composer's
 * discussion title and in the change-link window.
 *
 * Attrs: value, oninput(value), kind, linked (stop searching), onpick(link),
 * onkeydown (keys the list does not use), placeholder, kindPlaceholder (while searching), disabled, inputClassName,
 * focusOnKind (move the cursor here once a kind appears), wrapper (element, default h3),
 * onlookup(link, before) (the stored title came back: year, seasons, artist).
 */
export default class TitlePicker extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.results = [];
    this.active = 0;
    this.open = false;
    this.loading = false;
    this.failed = false;
    this.seq = 0;
  }

  view() {
    const { disabled } = this.attrs;
    const kind = this.kind();
    const open = !!kind && this.open && (this.loading || this.failed || this.results.length > 0);

    const Tag = this.attrs.wrapper || 'h3';

    return (
      <Tag className="Marquee-titleField">
        <input
          className={classList('FormControl', this.attrs.inputClassName)}
          value={this.attrs.value}
          placeholder={kind ? this.attrs.kindPlaceholder || text(`picker.placeholder_${kind}`) : this.attrs.placeholder}
          disabled={disabled}
          role={kind ? 'combobox' : undefined}
          aria-expanded={kind ? String(open) : undefined}
          aria-autocomplete={kind ? 'list' : undefined}
          aria-controls={kind ? 'Marquee-results' : undefined}
          aria-activedescendant={open && this.results.length ? `Marquee-result-${this.active}` : undefined}
          oninput={(e) => this.oninput(e.target.value)}
          onkeydown={(e) => this.onkeydown(e)}
          onfocus={() => this.results.length && (this.open = true)}
          onblur={() => this.refit()}
        />
        {open ? this.dropdown(kind) : null}
      </Tag>
    );
  }

  oncreate(vnode) {
    super.oncreate(vnode);

    /*
     * Closed by a press somewhere else, not by the field losing focus. On a
     * phone, closing the keyboard blurs the field, and the list went with it
     * just as it finally had the room to be scrolled.
     */
    this.outside = (e) => {
      if (this.open && !this.element.contains(e.target)) {
        this.open = false;
        m.redraw();
      }
    };
    document.addEventListener('pointerdown', this.outside);

    // The keyboard opening or closing changes the room the list has.
    // iOS reports the new size before the keyboard has finished moving, so
    // fit again once it has.
    this.refit = () => {
      this.fit();
      clearTimeout(this.refitTimer);
      this.refitTimer = setTimeout(() => this.element && this.fit(), 350);
    };
    window.visualViewport?.addEventListener('resize', this.refit);

    /*
     * On iOS, core locks the page behind a window by cancelling any drag the
     * window itself can't scroll. A short window (nothing linked yet) never
     * scrolls, so the list inside it couldn't either: keep its drags to itself.
     */
    this.element.addEventListener(
      'touchmove',
      (e) => {
        const list = e.target.closest('.Marquee-results');
        if (list && list.scrollHeight > list.clientHeight) e.stopPropagation();
      },
      { passive: true }
    );
  }

  onremove(vnode) {
    super.onremove(vnode);
    document.removeEventListener('pointerdown', this.outside);
    window.visualViewport?.removeEventListener('resize', this.refit);
    clearTimeout(this.refitTimer);
  }

  onupdate(vnode) {
    super.onupdate(vnode);

    // Once a sourced tag is chosen, put the cursor in the search box. The tag
    // modal focuses the post body as it closes, so wait for it.
    const kind = this.kind();
    if (this.attrs.focusOnKind && kind && kind !== this.lastKind && !this.attrs.value) {
      setTimeout(() => this.element.querySelector('input')?.focus(), 50);
    }
    this.lastKind = kind;

    const list = this.fit();

    // Arrow keys keep the highlighted result in view inside the list.
    if (list && this.scrollActive) {
      this.scrollActive = false;
      list.querySelector('.Marquee-result.active')?.scrollIntoView({ block: 'nearest' });
    }
  }

  /**
   * Fit the list to the room under the field: the composer clips its content,
   * and on a phone the keyboard covers the bottom of the screen. A list that
   * ran on under the keyboard couldn't be scrolled, and iOS moved the whole
   * window up to make room for it.
   */
  fit() {
    const list = this.element.querySelector('.Marquee-results');
    if (!list) return null;

    const bottoms = [];
    const composer = this.element.closest('.Composer');
    if (composer) bottoms.push(composer.getBoundingClientRect().bottom);
    const viewport = window.visualViewport;
    // The keyboard is up: the field has focus and a good part of the screen is gone
    // (Safari's own toolbar takes a little even without it).
    const typing = document.activeElement === this.element.querySelector('input');
    if (viewport && typing && viewport.height < window.innerHeight * 0.75) bottoms.push(viewport.offsetTop + viewport.height);

    if (bottoms.length) {
      const room = Math.min(...bottoms) - list.getBoundingClientRect().top - 8;
      list.style.maxHeight = `${Math.max(160, Math.min(420, room))}px`;
    } else {
      list.style.maxHeight = '';
    }

    return list;
  }

  dropdown(kind) {
    let rows;

    if (this.loading && !this.results.length) {
      rows = [
        <li className="Marquee-status">
          <LoadingIndicator size="small" display="inline" />
        </li>,
      ];
    } else if (this.failed) {
      rows = [<li className="Marquee-status">{t('picker.unavailable', { source: sourceName(kind) })}</li>];
    } else {
      rows = this.results.map((r, i) => (
        <li
          id={`Marquee-result-${i}`}
          role="option"
          aria-selected={String(i === this.active)}
          className={classList('Marquee-result', { active: i === this.active })}
          onmousedown={(e) => (e.preventDefault(), this.pick(r))}
          onmouseenter={() => (this.active = i)}
        >
          {r.poster ? <img src={img(r.poster, 'w92')} alt="" loading="lazy" decoding="async" /> : <span className="Marquee-noPoster" />}
          <div className="Marquee-resultText">
            <b>
              {r.title}
              {r.year ? <span className="Marquee-resultYear"> ({r.year})</span> : null}
            </b>
            {this.byline(kind, r) ? <small>{this.byline(kind, r)}</small> : null}
            {r.type || (r.platforms && r.platforms.length) ? (
              <ul className="Marquee-resultChips">
                {r.type ? <li className="Marquee-resultType">{t(`picker.type.${r.type}`)}</li> : null}
                {(r.platforms || []).slice(0, 6).map((p) => (
                  <li>{p}</li>
                ))}
                {r.platforms && r.platforms.length > 6 ? <li>+{r.platforms.length - 6}</li> : null}
              </ul>
            ) : null}
          </div>
        </li>
      ));
    }

    rows.push(
      <li className="Marquee-foot">
        <span>{t('picker.results_from', { source: sourceName(kind) })}</span>
        <span>{t('picker.escape_hint')}</span>
      </li>
    );

    return (
      <ul className={`Dropdown-menu Marquee-results Marquee-results--${kind}`} id="Marquee-results" role="listbox">
        {rows}
      </ul>
    );
  }

  /** The line under a result: a game's developer; an album's artist and tracks; a book's authors; a film's genres. */
  byline(kind, r) {
    if (kind === 'game') return r.developer || (r.platforms ? null : r.subtitle);
    return [r.subtitle, r.tracks ? text('meta.tracks_count', { count: r.tracks }) : null].filter(Boolean).join(' · ');
  }

  kind() {
    return this.attrs.kind;
  }

  oninput(value) {
    this.attrs.oninput(value);
    clearTimeout(this.timer);

    // Once a title is linked, typing only edits the text.
    const kind = this.kind();
    if (this.attrs.linked || !kind || value.trim().length < 2) {
      this.results = [];
      this.open = false;
      return;
    }

    this.timer = setTimeout(() => this.find(kind, value.trim()), 300);
  }

  find(kind, query) {
    const seq = ++this.seq;
    this.loading = true;
    this.failed = false;
    this.open = true;
    m.redraw();

    search(kind, query).then(
      (results) => {
        if (seq !== this.seq) return;
        this.results = results;
        this.active = 0;
        this.loading = false;
        m.redraw();
      },
      () => {
        if (seq !== this.seq) return;
        this.results = [];
        this.loading = false;
        this.failed = true;
        m.redraw();
      }
    );
  }

  pick(result) {
    const kind = this.kind();
    const link = {
      id: null,
      kind,
      externalId: result.id,
      title: result.title,
      year: result.year,
      poster: result.poster,
      artist: result.artist || null,
      seasons: null,
      season: null,
      episode: null,
    };

    this.attrs.onpick(link);
    this.open = false;
    this.results = [];
    this.seq++;

    // Store it now: posting is then instant, and a show's seasons become pickable.
    lookup(kind, result.id).then(
      (media) => {
        const before = { ...link };
        Object.assign(link, { id: media.id, seasons: media.seasons, year: media.year ?? link.year, artist: media.artist ?? link.artist });
        if (this.attrs.onlookup) this.attrs.onlookup(link, before);
        m.redraw();
      },
      () => {}
    );
  }

  onkeydown(e) {
    const open = this.kind() && this.open && this.results.length;

    if (open && e.key === 'ArrowDown') this.active = (this.active + 1) % this.results.length;
    else if (open && e.key === 'ArrowUp') this.active = (this.active - 1 + this.results.length) % this.results.length;
    else if (open && e.key === 'Enter') this.pick(this.results[this.active]);
    else if (this.open && e.key === 'Escape') this.open = false;
    else return this.attrs.onkeydown && this.attrs.onkeydown(e);

    this.scrollActive = true;
    e.preventDefault();
    e.stopPropagation();
  }
}
