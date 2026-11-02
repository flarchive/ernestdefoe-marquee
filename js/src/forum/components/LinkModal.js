import app from 'flarum/forum/app';
import FormModal from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Switch from 'flarum/common/components/Switch';
import classList from 'flarum/common/utils/classList';
import TitlePicker from './TitlePicker';
import ScopePicker from './ScopePicker';
import { t, text, img, sourceName, kindForTags, linkFor, pickFromMedia, titleFor } from '../util';
import { loadSeason } from '../api';

/**
 * Change what a posted discussion is linked to: pick a different title (and,
 * for a show, a season or episode), or remove the link. Optionally renames the
 * discussion to match. Uses the same permission as renaming.
 */
export default class LinkModal extends FormModal {
  oninit(vnode) {
    super.oninit(vnode);

    const discussion = this.attrs.discussion;
    const current = linkFor(discussion);

    this.kind = kindForTags(discussion.tags()) || current?.media.kind();
    this.link = current
      ? { ...pickFromMedia(current.media), season: current.season, episode: current.episode ? { n: current.episode } : null }
      : null;
    this.current = current;
    this.nameEpisode();

    // The rest of a series, shown as a row on the card (picked by hand).
    this.related = (discussion.marqueeRelatedMedia() || []).filter(Boolean).map(pickFromMedia);
    this.relatedQuery = '';

    this.query = '';
    this.rename = true;
    this.saving = false;

    // Each title's background: 'none' or an uploaded image's address; a title
    // not in it has the source's. Opened from the card: the title it shows.
    this.savedBackdrops = discussion.attribute('marqueeBackdrops') || {};
    this.backdrops = { ...this.savedBackdrops };
    this.backdropFor = (this.attrs.media || current?.media)?.id();
    this.uploading = false;
    this.deleting = false;
  }

  /** The page knows the linked episode's number; its name comes from the season. */
  nameEpisode() {
    const current = this.current;
    if (!current || !current.season || !current.episode) return;

    loadSeason(current.media.id(), current.season).then(
      (season) => {
        const episode = season.episodes.find((e) => e.n === current.episode);
        if (episode && this.link && this.link.episode && this.link.episode.n === episode.n) {
          this.link.episode = episode;
          m.redraw();
        }
      },
      () => {}
    );
  }

  className() {
    // Wide enough for a result's full title, developer and platforms.
    return 'MarqueeLinkModal';
  }

  title() {
    return t('link.title');
  }

  content() {
    const link = this.link;

    return (
      <div className="Modal-body">
        <div className="Form">
          <div className="Form-group">
            {link ? (
              <div className="MarqueeLinkModal-current">
                {link.poster ? <img src={img(link.poster, 'w92')} alt="" /> : <span className="Marquee-noPoster" />}
                <span>
                  <b>{link.year ? `${link.title} (${link.year})` : link.title}</b>
                  <small>{sourceName(link.kind)}</small>
                </span>
                <Button className="Button Button--link" icon="fas fa-times" onclick={() => (this.link = null)}>
                  {t('link.remove')}
                </Button>
              </div>
            ) : (
              <p className="helpText">{t('link.none')}</p>
            )}
          </div>

          {this.kind ? (
            <div className="Form-group">
              <label>{t('link.search_label')}</label>
              <TitlePicker
                wrapper="div"
                value={this.query}
                oninput={(value) => (this.query = value)}
                kind={this.kind}
                linked={false}
                onpick={(picked) => {
                  this.link = picked;
                  this.nameEpisode();
                  this.query = '';
                }}
              />
            </div>
          ) : null}

          {link && this.kind ? this.relatedField() : null}

          {link && link.seasons && link.seasons.length ? (
            <div className="Form-group">
              <ScopePicker link={link} onchange={() => m.redraw()} />
            </div>
          ) : null}

          {link && this.current && link.externalId === pickFromMedia(this.current.media).externalId ? this.backdropField() : null}

          {link ? (
            <div className="Form-group">
              <Switch state={this.rename} onchange={(value) => (this.rename = value)}>
                {t('link.rename', { title: <b>{titleFor(link, link.season, link.episode)}</b> })}
              </Switch>
            </div>
          ) : null}

          <div className="Form-group">
            <Button className="Button Button--primary Button--block" type="submit" loading={this.saving}>
              {t('link.save')}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  /** The titles on the card as saved, still on it: the linked one, then its related titles. */
  backdropTitles() {
    const kept = (media) => this.related.some((r) => r.kind === media.kind() && r.externalId === media.externalId());
    return [this.current.media, ...(this.attrs.discussion.marqueeRelatedMedia() || []).filter((media) => media && kept(media))];
  }

  /** A title's background: the source's, none, or an uploaded image. */
  backdropField() {
    const titles = this.backdropTitles();
    if (!titles.some((media) => media.id() === this.backdropFor)) this.backdropFor = titles[0].id();
    const id = this.backdropFor;
    const value = this.backdrops[id] ?? null;
    const own = value && value !== 'none';
    const choice = (choice, label) => (
      <Button
        className={classList('Button', { active: choice === 'own' ? own : value === choice })}
        aria-pressed={String(choice === 'own' ? own : value === choice)}
        onclick={() => {
          if (choice === 'own') this.element.querySelector('.MarqueeLinkModal-file').click();
          else if (choice === null) delete this.backdrops[id];
          else this.backdrops[id] = choice;
        }}
      >
        {label}
      </Button>
    );

    return (
      <div className="Form-group MarqueeLinkModal-backdrop">
        <label>{t('link.backdrop_label')}</label>
        {titles.length > 1 ? (
          <select className="FormControl" aria-label={text('link.backdrop_for')} value={id} onchange={(e) => (this.backdropFor = e.target.value)}>
            {titles.map((media) => (
              <option value={media.id()}>{media.year() ? `${media.title()} (${media.year()})` : media.title()}</option>
            ))}
          </select>
        ) : null}
        <div className="ButtonGroup">
          {choice(null, t('link.backdrop_default'))}
          {choice('none', t('link.backdrop_none'))}
          {choice('own', t('link.backdrop_own'))}
        </div>
        {own ? <img className="MarqueeLinkModal-backdropPreview" src={value} alt="" /> : null}
        <input
          className="MarqueeLinkModal-file"
          type="file"
          accept="image/jpeg,image/png,image/webp"
          hidden
          onchange={(e) => this.upload(e.target, id)}
        />
        <div className="MarqueeLinkModal-backdropActions">
          <Button
            className="Button"
            icon="fas fa-upload"
            loading={this.uploading}
            onclick={() => this.element.querySelector('.MarqueeLinkModal-file').click()}
          >
            {own ? t('link.backdrop_replace') : t('link.backdrop_upload')}
          </Button>
          {own && this.savedBackdrops[id] === value ? (
            <Button className="Button Button--danger" icon="fas fa-trash-alt" loading={this.deleting} onclick={() => this.deleteImage(id)}>
              {t('link.backdrop_delete')}
            </Button>
          ) : null}
        </div>
        <p className="helpText">{t('link.backdrop_help')}</p>
      </div>
    );
  }

  /** The background as saved, now that an upload or a delete has changed it. */
  savedAs(backdrops, id) {
    this.savedBackdrops = backdrops || {};
    if (this.savedBackdrops[id]) this.backdrops[id] = this.savedBackdrops[id];
    else delete this.backdrops[id];
  }

  upload(input, id) {
    const file = input.files && input.files[0];
    input.value = '';
    if (!file) return;

    const body = new FormData();
    body.append('backdrop', file);
    body.append('media', id);
    this.uploading = true;
    m.redraw();

    app
      .request({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/marquee/discussions/${this.attrs.discussion.id()}/backdrop`,
        serialize: (raw) => raw,
        body,
      })
      .then(
        (response) => {
          this.savedAs(response.data.backdrops, id);
          this.attrs.discussion.pushAttributes({ marqueeBackdrops: this.savedBackdrops });
          app.alerts.show({ type: 'success' }, text('link.backdrop_uploaded'));
        },
        () => {}
      )
      .finally(() => {
        this.uploading = false;
        m.redraw();
      });
  }

  /** Deleted at once, as an upload is saved at once: the file goes with it. */
  deleteImage(id) {
    this.deleting = true;
    this.attrs.discussion
      .save({ marqueeBackdrops: { [id]: null } })
      .then(
        () => {
          this.savedAs(this.attrs.discussion.attribute('marqueeBackdrops'), id);
          app.alerts.show({ type: 'success' }, text('link.backdrop_deleted'));
        },
        () => {}
      )
      .finally(() => {
        this.deleting = false;
        m.redraw();
      });
  }

  /** Related titles: the other games (films, seasons…) of a series, in the card's order. */
  relatedField() {
    const link = this.link;
    const taken = (r) =>
      (r.kind === link.kind && r.externalId === link.externalId) || this.related.some((o) => o.kind === r.kind && o.externalId === r.externalId);

    return (
      <div className="Form-group MarqueeLinkModal-related">
        <label>{t('link.related_label')}</label>
        <p className="helpText">{t('link.related_help')}</p>
        {this.related.length ? (
          <ul className="MarqueeLinkModal-relatedList">
            {this.related.map((r, i) => (
              <li>
                {r.poster ? <img src={img(r.poster, 'w92')} alt="" /> : <span className="Marquee-noPoster" />}
                <span>{r.year ? `${r.title} (${r.year})` : r.title}</span>
                <Button
                  className="Button Button--icon Button--link"
                  icon="fas fa-times"
                  aria-label={text('link.related_remove', { title: r.title })}
                  onclick={() => this.related.splice(i, 1)}
                />
              </li>
            ))}
          </ul>
        ) : null}
        <TitlePicker
          wrapper="div"
          value={this.relatedQuery}
          oninput={(value) => (this.relatedQuery = value)}
          kind={this.kind}
          linked={false}
          kindPlaceholder={text('link.related_placeholder')}
          onpick={(picked) => {
            if (!taken(picked)) this.related.push(picked);
            this.relatedQuery = '';
          }}
        />
      </div>
    );
  }

  onsubmit(e) {
    e.preventDefault();
    const { discussion } = this.attrs;
    const link = this.link;
    const attributes = {
      marquee: link ? { kind: link.kind, id: link.externalId, season: link.season || null, episode: link.episode ? link.episode.n : null } : null,
    };
    if (link) attributes.marqueeRelated = this.related.map((r) => ({ kind: r.kind, id: r.externalId }));
    // Uploads are already saved; only switching back to the source's, or to none, is sent.
    const backdrops = {};
    Object.keys({ ...this.savedBackdrops, ...this.backdrops }).forEach((id) => {
      const value = this.backdrops[id] ?? null;
      if ((value === null || value === 'none') && value !== (this.savedBackdrops[id] ?? null)) backdrops[id] = value;
    });
    if (link && Object.keys(backdrops).length) attributes.marqueeBackdrops = backdrops;

    // Only rename once the episode's name is known (see oninit).
    if (link && this.rename && (!link.episode || link.episode.title)) {
      attributes.title = titleFor(link, link.season, link.episode);
    }

    this.saving = true;
    discussion.save(attributes).then(
      () => {
        app.alerts.show({ type: 'success' }, text('link.saved'));
        this.hide();
      },
      (error) => {
        this.saving = false;
        this.onerror(error);
      }
    );
  }
}
