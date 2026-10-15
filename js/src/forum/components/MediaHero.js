import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import listItems from 'flarum/common/helpers/listItems';
import classList from 'flarum/common/utils/classList';
import tagsLabel from 'ext:flarum/tags/common/helpers/tagsLabel';
import SeasonBrowser from './SeasonBrowser';
import EpisodeModal from './EpisodeModal';
import { t, img, date, runtime, sourceName, pickFromMedia } from '../util';
import { loadSeason, primeSeason } from '../api';
import { startDiscussion } from '../startDiscussion';

const ring = (value, label, kind) =>
  value ? (
    <div className={classList('MarqueeHero-score', kind && `MarqueeHero-score--${kind}`)}>
      <span className="MarqueeHero-ring" style={{ '--v': value }}>
        <b>{value}</b>
      </span>
      <span className="MarqueeHero-scoreLabel">{label}</span>
    </div>
  ) : null;

const credits = (pairs) => pairs.filter(([, names]) => names && names.length).map(([label, names]) => [label, [].concat(names).join(', ')]);

const years = (d) => {
  if (d.endYear && d.endYear !== d.year) return `${d.year}–${d.endYear}`;
  return d.status === 'Returning Series' ? `${d.year}–` : d.year;
};

const timeToBeat = (ttb) =>
  ttb ? (
    <div className="MarqueeHero-ttb">
      <h4 className="MarqueeHero-sectionTitle">{t('hero.time_to_beat')}</h4>
      <dl>
        {['hastily', 'normally', 'completely']
          .filter((key) => ttb[key])
          .map((key) => (
            <div>
              <dt>{t(`hero.ttb_${key}`)}</dt>
              <dd>{t('hero.hours', { count: ttb[key] })}</dd>
            </div>
          ))}
      </dl>
    </div>
  ) : null;

const screenshots = (list) =>
  list && list.length ? (
    <div>
      <h4 className="MarqueeHero-sectionTitle">{t('hero.screenshots')}</h4>
      <ul className="MarqueeHero-shots">
        {list.map((shot) => (
          <li>
            <a href={img(shot, 'w1280')} target="_blank" rel="noopener noreferrer">
              <img src={img(shot, 'w300')} alt="" loading="lazy" decoding="async" />
            </a>
          </li>
        ))}
      </ul>
    </div>
  ) : null;

/** What the hero is about: the whole title, one season, or one episode. */
function subject(media, d, season, episode, seasonData) {
  const summary = season && (d.seasons || []).find((s) => s.number === season);
  const ep = episode && seasonData && seasonData.episodes.find((e) => e.n === episode);

  if (ep) {
    return {
      poster: seasonData.poster || summary?.poster || d.poster,
      backdrop: ep.still ? img(ep.still, 'w1280') : img(d.backdrop, 'w1280'),
      meta: [t('meta.season_episode', { season, episode }), date(ep.date), runtime(ep.runtime)],
      score: ring(ep.score, t('hero.episode_score')),
      credits: credits([
        [t('credits.directed_by'), ep.directors],
        [t('credits.written_by'), ep.writers],
        [t('credits.network'), d.networks],
      ]),
      overview: ep.overview,
      people: [t('hero.guest_stars'), ep.guests],
      url: `${seasonData.url}/episode/${ep.n}`,
    };
  }

  if (summary) {
    return {
      poster: seasonData?.poster || summary.poster || d.poster,
      backdrop: img(d.backdrop, 'w1280'),
      meta: [summary.name, summary.year, t('meta.episodes_count', { count: summary.episodes }), d.genres.join(', ')],
      score: ring(summary.score, t('hero.season_score')),
      credits: credits([
        [t('credits.creator'), d.crew.creator],
        [t('credits.network'), d.networks],
      ]),
      overview: seasonData?.overview || d.overview,
      people: [t('hero.cast'), d.cast],
      url: `${d.url}/season/${season}`,
    };
  }

  if (media.kind() === 'tv') {
    return {
      poster: d.poster,
      backdrop: img(d.backdrop, 'w1280'),
      meta: [years(d), d.certification, d.networks.join(', '), t('meta.seasons_count', { count: (d.seasons || []).length }), d.genres.join(', ')],
      score: [ring(d.score, t('hero.user_score')), d.tagline && <p className="MarqueeHero-tagline">{d.tagline}</p>],
      credits: credits([
        [t('credits.creator'), d.crew.creator],
        [t('credits.network'), d.networks],
        [t('credits.status'), d.status],
      ]),
      overview: d.overview,
      people: [t('hero.cast'), d.cast],
      url: d.url,
    };
  }

  if (media.kind() === 'game') {
    return {
      poster: d.poster,
      backdrop: img(d.backdrop, 'w1280'),
      meta: [date(d.date), d.modes.join(', '), d.genres.join(', ')],
      score: [
        ring(d.critic, t('hero.critic_rating'), 'critic'),
        ring(d.users, t('hero.member_rating'), 'users'),
        d.platforms.length ? (
          <ul className="MarqueeHero-chips">
            {d.platforms.map((p) => (
              <li>{p}</li>
            ))}
          </ul>
        ) : null,
      ],
      credits: credits([
        [t('credits.developer'), d.crew.developer],
        [t('credits.publisher'), d.crew.publisher],
        [t('credits.series'), d.crew.series],
      ]),
      overview: d.overview,
      extra: [timeToBeat(d.timeToBeat), screenshots(d.screenshots)],
      url: d.url,
    };
  }

  return {
    poster: d.poster,
    backdrop: img(d.backdrop, 'w1280'),
    meta: [date(d.date), d.certification, runtime(d.runtime), d.genres.join(', ')],
    score: [ring(d.score, t('hero.user_score')), d.tagline && <p className="MarqueeHero-tagline">{d.tagline}</p>],
    credits: credits([
      [t('credits.director'), d.crew.director],
      [t('credits.writer'), d.crew.writer],
      [t('credits.based_on'), d.crew.basedOn],
    ]),
    overview: d.overview,
    people: [t('hero.cast'), d.cast],
    url: d.url,
  };
}

/**
 * Replaces the discussion hero when the discussion is linked to a movie, show
 * (or one season or episode of it) or game. Keeps the core title and badge
 * classes so themes still apply.
 */
export default class MediaHero extends Component {
  oninit(vnode) {
    super.oninit(vnode);

    const { media, season, seasonData } = this.attrs.link;
    primeSeason(media.id(), seasonData);
    this.seasonData = seasonData;

    if (season && !seasonData) {
      loadSeason(media.id(), season).then(
        (data) => {
          this.seasonData = data;
          m.redraw();
        },
        () => {}
      );
    }
  }

  view() {
    const { discussion, link } = this.attrs;
    const { media, season, episode } = link;
    const d = media.details();
    const s = subject(media, d, season, episode, this.seasonData);
    const badges = discussion.badges().toArray();
    const tags = discussion.tags && discussion.tags();
    const pick = pickFromMedia(media);
    const ep = episode && this.seasonData?.episodes.find((e) => e.n === episode);
    const source = sourceName(media.kind());

    return (
      <header className={classList('Hero DiscussionHero MarqueeHero', `MarqueeHero--${media.kind()}`, { 'MarqueeHero--episode': ep })}>
        {s.backdrop ? <div className="MarqueeHero-backdrop" style={{ backgroundImage: `url(${s.backdrop})` }} /> : null}
        <div className="container MarqueeHero-inner">
          {s.poster ? (
            <img className="MarqueeHero-poster" src={img(s.poster, 'w342')} alt="" fetchpriority="high" />
          ) : (
            <span className="MarqueeHero-poster" />
          )}
          <div className="MarqueeHero-body">
            <ul className="DiscussionHero-items">
              {tags && tags.length ? <li className="item-tags">{tagsLabel(tags, { link: true })}</li> : null}
              {badges.length ? (
                <li className="item-badges">
                  <ul className="DiscussionHero-badges badges">{listItems(badges)}</ul>
                </li>
              ) : null}
              {season ? (
                <li className="MarqueeHero-parent">
                  <i className="fas fa-tv" aria-hidden="true" /> {media.year() ? `${media.title()} (${media.year()})` : media.title()}
                </li>
              ) : null}
            </ul>
            <h1 className="DiscussionHero-title">{discussion.title()}</h1>
            <ul className="MarqueeHero-meta">
              {s.meta.filter(Boolean).map((part) => (
                <li>{part}</li>
              ))}
            </ul>

            <div className="MarqueeHero-scores">{s.score}</div>

            {s.credits.length ? (
              <dl className="MarqueeHero-credits">
                {s.credits.map(([k, v]) => (
                  <div>
                    <dt>{k}</dt>
                    <dd>{v}</dd>
                  </div>
                ))}
              </dl>
            ) : null}

            {s.overview ? <p className="MarqueeHero-overview">{s.overview}</p> : null}

            {s.extra || null}

            {s.people && s.people[1] && s.people[1].length ? (
              <div>
                <h4 className="MarqueeHero-sectionTitle">{s.people[0]}</h4>
                <ul className="MarqueeHero-cast">
                  {s.people[1].map((c) => (
                    <li>
                      {c.photo ? <img src={img(c.photo, 'w185')} alt="" loading="lazy" decoding="async" /> : <span className="Marquee-noPhoto" />}
                      <b>{c.name}</b>
                      <span>{c.role}</span>
                    </li>
                  ))}
                </ul>
              </div>
            ) : null}

            {pick.seasons && pick.seasons.length ? <SeasonBrowser link={pick} season={season} episode={episode} /> : null}

            <div className="MarqueeHero-links">
              <a className="Button Button--primary" href={s.url} target="_blank" rel="noopener noreferrer">
                {t('hero.view_on', { source })}
              </a>
              {ep ? (
                <button
                  type="button"
                  className="Button"
                  onclick={() => app.modal.show(EpisodeModal, { link: pick, season: this.seasonData, episode: ep })}
                >
                  <i className="fas fa-info-circle" aria-hidden="true" /> {t('hero.episode_details')}
                </button>
              ) : d.trailer ? (
                <a className="Button" href={`https://www.youtube.com/watch?v=${d.trailer}`} target="_blank" rel="noopener noreferrer">
                  <i className="fas fa-play" aria-hidden="true" /> {t('hero.trailer')}
                </a>
              ) : null}
              {season && app.session.user ? (
                <button type="button" className="Button" onclick={() => startDiscussion(pick)}>
                  <i className="fas fa-tv" aria-hidden="true" /> {t('hero.discuss_show')}
                </button>
              ) : null}
              <span className="MarqueeHero-attribution">{t(`hero.attribution_${source.toLowerCase()}`)}</span>
            </div>
          </div>
        </div>
      </header>
    );
  }
}
