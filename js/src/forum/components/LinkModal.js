import app from 'flarum/forum/app';
import FormModal from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Switch from 'flarum/common/components/Switch';
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
