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
    // The page knows the linked episode's number; its name comes from the season.
    if (current && current.season && current.episode) {
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

    this.query = '';
    this.rename = true;
    this.saving = false;
  }

  className() {
    return 'MarqueeLinkModal Modal--small';
  }

  title() {
    return t('link.title');
  }

  content() {
    const link = this.link;

    return (
      <div className="Modal-body">
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
                // The page knows the linked episode's number; its name comes from the season.
                if (current && current.season && current.episode) {
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

                this.query = '';
              }}
            />
          </div>
        ) : null}

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
    );
  }

  onsubmit(e) {
    e.preventDefault();
    const { discussion } = this.attrs;
    const link = this.link;
    const attributes = {
      marquee: link ? { kind: link.kind, id: link.externalId, season: link.season || null, episode: link.episode ? link.episode.n : null } : null,
    };

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
