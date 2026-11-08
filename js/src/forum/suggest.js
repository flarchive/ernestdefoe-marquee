import KeyboardNavigatable from 'flarum/common/utils/KeyboardNavigatable';
import classList from 'flarum/common/utils/classList';
import { text, img, pad, date, runtime } from './util';
import { loadSeason } from './api';
import { showFor } from './refs';

// "S2", "s02e", "S2E1"… right before the cursor, at the start of a word.
const TYPED = /(?:^|[\s(["'])([Ss](\d{1,2})(?:[Ee](\d{0,3}))?)$/;

/** Which show the post being written is about, as { mediaId, seasons }. */
function showContext(composer) {
  const attrs = composer.body?.attrs || {};
  const discussion = attrs.discussion || attrs.post?.discussion?.();
  const link = showFor(discussion);
  if (link) return { mediaId: link.media.id(), seasons: link.media.details().seasons || [] };

  // A new discussion about a show picked in the composer.
  const picked = composer.fields?.marquee;
  if (picked && picked.kind === 'tv' && picked.id && picked.seasons) return { mediaId: picked.id, seasons: picked.seasons };

  return null;
}

/**
 * Season and episode autocomplete in the post editor: typing S2E in a
 * discussion about a show lists that season's episodes.
 */
export function attachSuggest(editor) {
  const state = { items: [], index: 0, active: false, start: 0 };
  const $editor = editor.$('.TextEditor-editor');
  const container = document.createElement('div');
  container.className = 'MarqueeSuggestContainer';
  $editor.after(container);

  const hide = () => {
    state.active = false;
    state.typed = null;
    m.render(container, null);
  };

  const choose = (item) => {
    if (!item) return;
    editor.attrs.composer.editor.replaceBeforeCursor(state.start, item.insert + ' ');
    hide();
  };

  const render = () => {
    // Nothing to show yet (episodes still loading): close the list but keep
    // what was typed, so the arrow-key guard below still recognises it.
    if (!state.items.length) {
      state.active = false;
      return m.render(container, null);
    }
    state.active = true;
    m.render(
      container,
      <ul className="Dropdown-menu MarqueeSuggest" role="listbox">
        {state.items.map((item, i) => (
          <li
            role="option"
            aria-selected={String(i === state.index)}
            className={classList('MarqueeSuggest-item', { active: i === state.index })}
            onmousedown={(e) => (e.preventDefault(), choose(item))}
            onmouseenter={() => ((state.index = i), render())}
          >
            {item.image ? <img src={item.image} alt="" loading="lazy" decoding="async" /> : <span className="Marquee-noStill" />}
            <span>
              <b>{item.label}</b>
              <small>{item.detail}</small>
            </span>
          </li>
        ))}
      </ul>
    );

    const caret = editor.attrs.composer.editor.getCaretCoordinates(state.start);
    const menu = container.firstChild;
    const parent = container.offsetParent || container.parentNode;
    const top = caret.top + 18 + menu.offsetHeight > parent.clientHeight ? caret.top - menu.offsetHeight - 6 : caret.top + 18;
    menu.style.left = `${Math.max(0, Math.min(caret.left, parent.clientWidth - menu.offsetWidth))}px`;
    menu.style.top = `${top}px`;
  };

  new KeyboardNavigatable()
    .when(() => state.active)
    .onUp(() => ((state.index = (state.index - 1 + state.items.length) % state.items.length), render()))
    .onDown(() => ((state.index = (state.index + 1) % state.items.length), render()))
    .onSelect(() => choose(state.items[state.index]))
    .onCancel(hide)
    .bindTo($editor);

  return () => {
    const api = editor.attrs.composer.editor;
    const context = showContext(editor.attrs.composer);
    const [cursor, end] = api.getSelectionRange();
    const match = context && cursor === end ? TYPED.exec(api.getLastNChars(12)) : null;
    if (!match) return hide();

    const [, typed, seasonDigits, episodeDigits] = match;

    // Arrow keys also reach this listener: same text, keep the highlighted row.
    if (state.active && typed === state.typed && cursor === state.cursor) return;
    state.typed = typed;
    state.cursor = cursor;
    const season = context.seasons.find((s) => s.number === parseInt(seasonDigits, 10));
    if (!season) return hide();

    state.start = cursor - typed.length;
    state.index = 0;

    const seasonItem = {
      insert: `S${pad(season.number)}`,
      label: season.name,
      detail: text('meta.episodes_count', { count: season.episodes }),
      image: img(season.poster, 'w92'),
    };

    // Until the season's episodes arrive, offer the season itself.
    state.items = episodeDigits === undefined ? [seasonItem] : [];
    render();

    loadSeason(context.mediaId, season.number).then(
      (data) => {
        const prefix = episodeDigits || '';
        const episodes = data.episodes
          .filter((e) => !prefix || String(e.n).startsWith(String(parseInt(prefix, 10))))
          .slice(0, 8)
          .map((e) => ({
            insert: `S${pad(season.number)}E${pad(e.n)}`,
            label: text('meta.episode_label', { episode: pad(e.n), name: e.title }),
            detail: [date(e.date), runtime(e.runtime)].filter(Boolean).join(' · '),
            image: img(e.still, 'w300'),
          }));

        // Still typing the same reference?
        const again = TYPED.exec(api.getLastNChars(12));
        if (!again || again[1] !== typed) return;

        state.items = episodeDigits === undefined ? [seasonItem, ...episodes] : episodes;
        render();
      },
      () => {}
    );
  };
}
