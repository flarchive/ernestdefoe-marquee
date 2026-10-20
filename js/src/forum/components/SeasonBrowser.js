import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';
import EpisodeModal from './EpisodeModal';
import { t, img, pad, date, runtime } from '../util';
import { loadSeason } from '../api';
import { seasonRequest } from '../refs';

/** Bring the linked episode into view inside the episode list, without moving the page. */
const scrollIntoList = (el) => {
  const list = el.closest('.MarqueeSeasons-episodes');
  if (list) list.scrollTop = el.offsetTop - list.offsetTop - (list.clientHeight - el.offsetHeight) / 2;
};

/**
 * A show's seasons inside the hero. Pick a season to list its episodes (fetched
 * the first time anyone opens it); pick an episode to open its details.
 */
export default class SeasonBrowser extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.data = {};
    this.toggle(this.attrs.season);
  }

  toggle(number) {
    this.openNumber = this.openNumber === number ? null : number;
    if (!number || this.data[number]) return;

    loadSeason(this.attrs.link.id, number).then(
      (data) => {
        this.data[number] = data;
        m.redraw();
      },
      () => {
        this.data[number] = 'failed';
        m.redraw();
      }
    );
  }

  view() {
    // A season chip in a post asked for this season.
    if (seasonRequest.number && seasonRequest.number !== this.openNumber) {
      const number = seasonRequest.number;
      seasonRequest.number = null;
      this.openNumber = null;
      this.toggle(number);
    }

    const { link, season: linked, episode: current } = this.attrs;
    const season = this.data[this.openNumber];
    const here = <span className="MarqueeSeasons-here">{t('hero.this_discussion')}</span>;

    return (
      <section className="MarqueeSeasons">
        <h2 className="MarqueeHero-sectionTitle">{t('hero.seasons')}</h2>
        <ul className="MarqueeSeasons-list" role="tablist">
          {link.seasons.map((s) => (
            <li>
              <button
                type="button"
                role="tab"
                aria-selected={String(s.number === this.openNumber)}
                aria-current={s.number === linked ? 'true' : undefined}
                className={classList('MarqueeSeasons-season', { active: s.number === this.openNumber, linked: s.number === linked })}
                onclick={() => this.toggle(s.number)}
              >
                {s.poster ? <img src={img(s.poster, 'w92')} alt="" loading="lazy" decoding="async" /> : <span className="Marquee-noPoster" />}
                <span>
                  <b>{s.name}</b>
                  {s.number === linked && !current ? here : null}
                  <small>{[s.year, t('meta.episodes_count', { count: s.episodes })].filter(Boolean).map((p, i) => [i ? ' · ' : '', p])}</small>
                  {s.score ? (
                    <small>
                      <i className="fas fa-star" aria-hidden="true" /> {s.score}%
                    </small>
                  ) : null}
                </span>
              </button>
            </li>
          ))}
        </ul>

        {this.openNumber ? (
          <div className="MarqueeSeasons-panel" role="tabpanel">
            {!season ? (
              <LoadingIndicator size="small" display="block" />
            ) : season === 'failed' ? (
              <p className="MarqueeSeasons-failed">{t('hero.season_failed')}</p>
            ) : (
              <ol className="MarqueeSeasons-episodes">
                {season.episodes.map((e) => (
                  <li>
                    <button
                      type="button"
                      aria-current={season.number === linked && e.n === current ? 'true' : undefined}
                      className={classList('MarqueeSeasons-episode', { current: season.number === linked && e.n === current })}
                      oncreate={(vnode) => vnode.dom.classList.contains('current') && scrollIntoList(vnode.dom)}
                      onclick={() => app.modal.show(EpisodeModal, { link, season, episode: e })}
                    >
                      {e.still ? <img src={img(e.still, 'w300')} alt="" loading="lazy" decoding="async" /> : <span className="Marquee-noStill" />}
                      <span>
                        <b>
                          <em>{t('meta.episode_code', { episode: pad(e.n) })}</em> {e.title}
                        </b>
                        {season.number === linked && e.n === current ? here : null}
                        <small>
                          {[date(e.date), runtime(e.runtime)].filter(Boolean).join(' · ')}
                          {e.score ? [' · ', <i className="fas fa-star" aria-hidden="true" />, ` ${e.score}%`] : null}
                        </small>
                        {e.overview ? <span className="MarqueeSeasons-overview">{e.overview}</span> : null}
                      </span>
                    </button>
                  </li>
                ))}
              </ol>
            )}
          </div>
        ) : null}
      </section>
    );
  }
}
