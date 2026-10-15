import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';
import { t, text, img, sourceName, kindForTags, titleFor } from '../util';
import { search, lookup } from '../api';

/**
 * The discussion title input. Under a tag linked to a source it searches that
 * source and offers a dropdown of matches; under any other tag, or none, it is
 * the normal title field.
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
    const { composer, disabled } = this.attrs;
    const kind = this.kind();
    const open = !!kind && this.open && (this.loading || this.failed || this.results.length > 0);

    return (
      <h3 className="Marquee-titleField">
        <input
          className="FormControl DiscussionComposer-title"
          value={composer.fields.title()}
          placeholder={kind ? text(`picker.placeholder_${kind}`) : this.attrs.placeholder}
          disabled={disabled}
          role={kind ? 'combobox' : undefined}
          aria-expanded={kind ? String(open) : undefined}
          aria-autocomplete={kind ? 'list' : undefined}
          aria-controls={kind ? 'Marquee-results' : undefined}
          aria-activedescendant={open && this.results.length ? `Marquee-result-${this.active}` : undefined}
          oninput={(e) => this.oninput(e.target.value)}
          onkeydown={(e) => this.onkeydown(e)}
          onfocus={() => this.results.length && (this.open = true)}
          onblur={() => setTimeout(() => ((this.open = false), m.redraw()), 150)}
        />
        {open ? this.dropdown(kind) : null}
      </h3>
    );
  }

  onupdate(vnode) {
    super.onupdate(vnode);

    // Once a sourced tag is chosen, put the cursor in the search box. The tag
    // modal focuses the post body as it closes, so wait for it.
    const kind = this.kind();
    if (kind && kind !== this.lastKind && !this.attrs.composer.fields.title()) {
      setTimeout(() => this.element.querySelector('input')?.focus(), 50);
    }
    this.lastKind = kind;

    // The composer clips its content, so fit the list to the room under the title.
    const list = this.element.querySelector('.Marquee-results');
    const composer = this.element.closest('.Composer');
    if (list && composer) {
      const room = composer.getBoundingClientRect().bottom - list.getBoundingClientRect().top - 8;
      list.style.maxHeight = `${Math.max(160, Math.min(360, room))}px`;
    }
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
          <span>
            <b>{r.title}</b>
            <small>{[r.year, r.subtitle].filter(Boolean).join(' · ')}</small>
          </span>
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
      <ul className="Dropdown-menu Marquee-results" id="Marquee-results" role="listbox">
        {rows}
      </ul>
    );
  }

  kind() {
    return kindForTags(this.attrs.composer.fields.tags);
  }

  oninput(value) {
    const fields = this.attrs.composer.fields;
    fields.title(value);
    clearTimeout(this.timer);

    // Once a title is linked, typing only edits the discussion title.
    const kind = this.kind();
    if (fields.marquee || !kind || value.trim().length < 2) {
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
    const fields = this.attrs.composer.fields;
    const kind = this.kind();
    const link = {
      id: null,
      kind,
      externalId: result.id,
      title: result.title,
      year: result.year,
      poster: result.poster,
      seasons: null,
      season: null,
      episode: null,
    };

    fields.marquee = link;
    fields.title(titleFor(link));
    this.open = false;
    this.results = [];
    this.seq++;

    // Store it now: posting is then instant, and a show's seasons become pickable.
    lookup(kind, result.id).then(
      (media) => {
        Object.assign(link, { id: media.id, seasons: media.seasons });
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
    else return this.attrs.onkeydown(e);

    e.preventDefault();
    e.stopPropagation();
  }
}
