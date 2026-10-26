import app from 'flarum/forum/app';
import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import { t, text, img, pad, date, runtime } from '../util';
import { startDiscussion } from '../startDiscussion';

/**
 * One episode: still, air date, runtime, score, crew, guest stars and synopsis,
 * with previous / next to move through the season.
 */
export default class EpisodeModal extends Modal {
  oninit(vnode) {
    super.oninit(vnode);
    this.episode = this.attrs.episode;
  }

  className() {
    return 'MarqueeEpisodeModal Modal--large';
  }

  title() {
    return text('modal.title', { season: pad(this.attrs.season.number), episode: pad(this.episode.n), name: this.episode.title });
  }

  content() {
    const { link, season } = this.attrs;
    const e = this.episode;
    const list = season.episodes;
    const index = list.indexOf(e);
    const still = img(e.still, 'w780');

    return [
      <div className="MarqueeEpisode-still" style={still ? { backgroundImage: `url(${still})` } : undefined}>
        <span className="MarqueeEpisode-badge">{t('modal.badge', { title: link.title, season: season.number, episode: e.n })}</span>
      </div>,
      <div className="Modal-body MarqueeEpisode-body">
        <ul className="MarqueeEpisode-meta">
          {[date(e.date), runtime(e.runtime)].filter(Boolean).map((part) => (
            <li>{part}</li>
          ))}
          {e.score ? (
            <li>
              <i className="fas fa-star" aria-hidden="true" /> {t('modal.score', { score: e.score })}
            </li>
          ) : null}
        </ul>

        {e.overview ? <p className="MarqueeEpisode-overview">{e.overview}</p> : null}

        <dl className="MarqueeEpisode-credits">
          {e.directors.length ? (
            <div>
              <dt>{t('credits.directed_by')}</dt>
              <dd>{e.directors.join(', ')}</dd>
            </div>
          ) : null}
          {e.writers.length ? (
            <div>
              <dt>{t('credits.written_by')}</dt>
              <dd>{e.writers.join(', ')}</dd>
            </div>
          ) : null}
        </dl>

        {e.guests.length ? (
          <div>
            <h4 className="MarqueeEpisode-heading">{t('hero.guest_stars')}</h4>
            <ul className="MarqueeEpisode-guests">
              {e.guests.map((g) => (
                <li>
                  {g.photo ? <img src={img(g.photo, 'w185')} alt="" loading="lazy" decoding="async" /> : <span className="Marquee-noPhoto" />}
                  <span>
                    <b>{g.name}</b>
                    <small>{g.role}</small>
                  </span>
                </li>
              ))}
            </ul>
          </div>
        ) : null}

        <div className="MarqueeEpisode-actions">
          <Button className="Button" icon="fas fa-chevron-left" disabled={index === 0} onclick={() => (this.episode = list[index - 1])}>
            {t('modal.previous')}
          </Button>
          <Button
            className="Button"
            icon="fas fa-chevron-right"
            disabled={index === list.length - 1}
            onclick={() => (this.episode = list[index + 1])}
          >
            {t('modal.next')}
          </Button>
          <span className="MarqueeEpisode-spacer" />
          <a className="Button" href={`${season.url}/episode/${e.n}`} target="_blank" rel="noopener noreferrer">
            {t('hero.view_on', { source: 'TMDB' })}
          </a>
          {app.session.user ? (
            <Button
              className="Button Button--primary"
              icon="fas fa-edit"
              onclick={() => {
                app.modal.close();
                startDiscussion({ ...link, season: season.number, episode: e });
              }}
            >
              {t('modal.discuss_episode')}
            </Button>
          ) : null}
        </div>
      </div>,
    ];
  }
}
