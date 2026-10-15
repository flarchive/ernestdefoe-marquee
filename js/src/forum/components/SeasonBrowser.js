import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';
import EpisodeModal from './EpisodeModal';
import { t, img, pad, date, runtime } from '../util';
import { loadSeason } from '../api';
import { seasonRequest } from '../refs';

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

    const { link, episode: current } = this.attrs;
    const season = this.data[this.openNumber];

    return (
      <section className="MarqueeSeasons">
        <h4 className="MarqueeHero-sectionTitle">{t('hero.seasons')}</h4>
        <ul className="MarqueeSeasons-list" role="tablist">
          {link.seasons.map((s) => (
            <li>
              <button
                type="button"
                role="tab"
                aria-selected={String(s.number === this.openNumber)}
                className={classList('MarqueeSeasons-season', { active: s.number === this.openNumber })}
                onclick={() => this.toggle(s.number)}
              >
                {s.poster ? <img src={img(s.poster, 'w92')} alt="" loading="lazy" decoding="async" /> : <span className="Marquee-noPoster" />}
                <span>
                  <b>{s.name}</b>
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
                      className={classList('MarqueeSeasons-episode', { current: season.number === this.attrs.season && e.n === current })}
                      onclick={() => app.modal.show(EpisodeModal, { link, season, episode: e })}
                    >
                      {e.still ? <img src={img(e.still, 'w300')} alt="" loading="lazy" decoding="async" /> : <span className="Marquee-noStill" />}
                      <span>
                        <b>
                          <em>E{pad(e.n)}</em> {e.title}
                        </b>
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
