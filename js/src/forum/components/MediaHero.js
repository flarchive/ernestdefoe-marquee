import Component from 'flarum/common/Component';
import ItemList from 'flarum/common/utils/ItemList';
import listItems from 'flarum/common/helpers/listItems';
import classList from 'flarum/common/utils/classList';
import abbreviateNumber from 'flarum/common/utils/abbreviateNumber';
import tagsLabel from 'ext:flarum/tags/common/helpers/tagsLabel';
import SeasonBrowser from './SeasonBrowser';
import EpisodeModal from './EpisodeModal';
import app from 'flarum/forum/app';
import { t, text, img, date, runtime, sourceName, pickFromMedia, titleFor, igdbTerms, showScores, showSourceLink } from '../util';
import { loadSeason, primeSeason } from '../api';
import { startDiscussion } from '../startDiscussion';

// The admin can hide every score and rating (on by default).
const ring = (value, label, kind) =>
  value && showScores() ? (
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
      <h2 className="MarqueeHero-sectionTitle">{t('hero.time_to_beat')}</h2>
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
      <h2 className="MarqueeHero-sectionTitle">{t('hero.screenshots')}</h2>
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

const clock = (seconds) => `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;

const tracklist = (tracks) =>
  tracks && tracks.length ? (
    <div>
      <h2 className="MarqueeHero-sectionTitle">{t('hero.tracklist')}</h2>
      <ol className="MarqueeTracks">
        {tracks.map((track) => (
          <li>
            <em>{track.n}</em>
            <span className="MarqueeTracks-title">
              {track.title}
              {track.artist ? <small> · {track.artist}</small> : null}
            </span>
            {track.explicit ? (
              <abbr className="MarqueeTracks-explicit" title={text('hero.explicit')}>
                E
              </abbr>
            ) : null}
            <time>{clock(track.duration)}</time>
          </li>
        ))}
      </ol>
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

  if (media.kind() === 'music') {
    return {
      poster: d.poster,
      backdrop: img(d.backdrop || d.poster, 'w1280'),
      meta: [
        date(d.date),
        t(`meta.record_${d.recordType}`),
        t('meta.tracks_count', { count: d.trackCount }),
        runtime(Math.round(d.duration / 60)),
        d.genres.join(', '),
      ],
      score: d.fans ? (
        <p className="MarqueeHero-fans">
          <i className="fas fa-heart" aria-hidden="true" /> {t('hero.fans', { count: abbreviateNumber(d.fans) })}
        </p>
      ) : null,
      credits: credits([
        [t('credits.artist'), d.artist],
        [t('credits.label'), d.label],
      ]),
      overview: null,
      extra: [tracklist(d.tracks)],
      url: d.url,
      linkLabel: t('hero.listen_on', { source: 'Deezer' }),
    };
  }

  if (media.kind() === 'book') {
    return {
      poster: d.poster,
      backdrop: img(d.poster, 'w1280'),
      meta: [
        d.year ? t('meta.first_published', { year: d.year }) : null,
        d.pages ? t('meta.pages_count', { count: d.pages }) : null,
        d.editions > 1 ? t('meta.editions_count', { count: d.editions }) : null,
      ],
      score: [
        d.subtitle && <p className="MarqueeHero-tagline">{d.subtitle}</p>,
        d.rating && showScores() ? (
          <p className="MarqueeHero-fans">
            <i className="fas fa-star" aria-hidden="true" /> {t('hero.book_rating', { rating: d.rating, count: abbreviateNumber(d.ratingCount) })}
          </p>
        ) : null,
        d.readers ? (
          <p className="MarqueeHero-fans">
            <i className="fas fa-bookmark" aria-hidden="true" /> {t('hero.want_to_read', { count: abbreviateNumber(d.readers) })}
          </p>
        ) : null,
        d.subjects.length ? (
          <ul className="MarqueeHero-chips">
            {d.subjects.map((subject) => (
              <li>{subject}</li>
            ))}
          </ul>
        ) : null,
      ],
      credits: credits([
        [t('credits.author'), d.authors],
        [t('credits.original_title'), d.originalTitle],
        [t('credits.publisher'), [d.publisher, d.published].filter(Boolean).join(', ')],
        [t('credits.isbn'), d.isbn],
      ]),
      overview: d.overview,
      untranslated: d.untranslated,
      people: [t('hero.authors'), d.people.some((p) => p.photo) ? d.people : []],
      url: d.url,
    };
  }

  if (media.kind() === 'game') {
    return {
      poster: d.poster,
      backdrop: img(d.backdrop, 'w1280'),
      meta: [date(d.date), igdbTerms('mode', d.modes, d.modeSlugs).join(', '), igdbTerms('genre', d.genres, d.genreSlugs).join(', ')],
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
      untranslated: d.untranslated,
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

  /**
   * The buttons under the card. Other extensions add to it: each gets the
   * discussion, the linked media and what this card is about (season, episode).
   */
  actionItems() {
    const { media, season } = this.attrs.link;
    const d = media.details();
    const s = subject(...this.subjectArgs());
    const pick = pickFromMedia(media);
    const ep = this.episode();
    const source = sourceName(media.kind());
    const items = new ItemList();

    // The admin can hide the button; the attribution below always stays (TMDB and IGDB ask for it).
    if (showSourceLink()) {
      items.add(
        'source',
        <a className="Button Button--primary" href={s.url} target="_blank" rel="noopener noreferrer">
          {s.linkLabel || t('hero.view_on', { source })}
        </a>,
        100
      );
    }

    if (ep) {
      items.add(
        'episode',
        <button type="button" className="Button" onclick={() => app.modal.show(EpisodeModal, { link: pick, season: this.seasonData, episode: ep })}>
          <i className="fas fa-info-circle" aria-hidden="true" /> {t('hero.episode_details')}
        </button>,
        90
      );
    } else if (d.trailer) {
      items.add(
        'trailer',
        <a className="Button" href={`https://www.youtube.com/watch?v=${d.trailer}`} target="_blank" rel="noopener noreferrer">
          <i className="fas fa-play" aria-hidden="true" /> {t('hero.trailer')}
        </a>,
        90
      );
    }

    if (season && app.session.user) {
      items.add(
        'discussShow',
        <button type="button" className="Button" onclick={() => startDiscussion(pick)}>
          <i className="fas fa-tv" aria-hidden="true" /> {t('hero.discuss_show')}
        </button>,
        80
      );
    }

    items.add(
      'attribution',
      <span className="MarqueeHero-attribution">
        {t(`hero.attribution_${source.toLowerCase().replace(' ', '_')}`)}
        {d.wikipedia?.url
          ? [
              ' · ',
              t('hero.from_wikipedia', {
                a: <a href={d.wikipedia.url} target="_blank" rel="noopener noreferrer" />,
                license: <a href="https://creativecommons.org/licenses/by-sa/4.0/" target="_blank" rel="noopener noreferrer license" />,
              }),
            ]
          : null}
        {d.translation?.by === 'deepl' ? [' · ', t('hero.translated_deepl')] : null}
      </span>,
      -100
    );

    return items;
  }

  /**
   * No description in the data language (no Wikipedia article, no DeepL): say
   * so, and keep the source's English behind a toggle, as Curio does.
   */
  untranslated(s) {
    const names = new Intl.DisplayNames([app.translator.getLocale() || 'en'], { type: 'language' });
    const name = (code) => {
      try {
        return names.of(code);
      } catch (e) {
        return code;
      }
    };
    const other = name(s.untranslated.have);

    return (
      <div className="MarqueeHero-overview MarqueeHero-overview--missing">
        <p>{t('hero.no_description', { language: name(s.untranslated.want.split('-')[0]) })}</p>
        <button
          type="button"
          className="Button Button--link"
          aria-expanded={String(!!this.showOther)}
          onclick={() => (this.showOther = !this.showOther)}
        >
          {t(this.showOther ? 'hero.hide_other' : 'hero.show_other', { language: other })}
        </button>
        {this.showOther ? <p lang={s.untranslated.have}>{s.overview}</p> : null}
      </div>
    );
  }

  /** The discussion's titles (linked one first): picking one shows its card here. */
  series(shown) {
    return (
      <ul className="MarqueeHero-series" aria-label={text('hero.series')}>
        {this.attrs.series.map((work) => (
          <li>
            <button
              type="button"
              className="MarqueeHero-seriesItem"
              aria-pressed={String(work === shown)}
              onclick={() => work !== shown && this.attrs.onswitch(work)}
            >
              {work.title()}
              {work.year() ? <small>{work.year()}</small> : null}
            </button>
          </li>
        ))}
      </ul>
    );
  }

  episode() {
    const { episode } = this.attrs.link;
    return (episode && this.seasonData?.episodes.find((e) => e.n === episode)) || null;
  }

  subjectArgs() {
    const { media, season, episode } = this.attrs.link;
    return [media, media.details(), season, episode, this.seasonData];
  }

  view() {
    const { discussion, link } = this.attrs;
    const { media, season, episode } = link;
    const s = subject(...this.subjectArgs());
    const badges = discussion.badges().toArray();
    const tags = discussion.tags && discussion.tags();
    const pick = pickFromMedia(media);
    const ep = this.episode();
    // The card is titled by the work itself. A discussion renamed to something
    // else ("This indie game is one of my favourites") keeps that as a headline
    // above it. Until an episode's data arrives its title isn't known yet.
    const title = discussion.title();
    const workTitle = episode && !ep ? title : titleFor(pick, season, ep);
    const renamed = workTitle !== title;

    return (
      <header
        className={classList('Hero DiscussionHero MarqueeHero', `MarqueeHero--${media.kind()}`, {
          'MarqueeHero--episode': ep,
          'MarqueeHero--renamed': renamed,
          'MarqueeHero--backdrop': !!s.backdrop,
        })}
      >
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
            {renamed ? <h1 className="MarqueeHero-headline">{title}</h1> : null}
            {renamed ? <h2 className="DiscussionHero-title">{workTitle}</h2> : <h1 className="DiscussionHero-title">{title}</h1>}
            <ul className="MarqueeHero-meta">
              {s.meta.filter(Boolean).map((part) => (
                <li>{part}</li>
              ))}
            </ul>

            {this.attrs.series ? this.series(media) : null}

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

            {s.overview && s.untranslated ? this.untranslated(s) : s.overview ? <p className="MarqueeHero-overview">{s.overview}</p> : null}

            {s.extra || null}

            {s.people && s.people[1] && s.people[1].length ? (
              <div>
                <h2 className="MarqueeHero-sectionTitle">{s.people[0]}</h2>
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

            <div className="MarqueeHero-links">{this.actionItems().toArray()}</div>
          </div>
        </div>
      </header>
    );
  }
}
