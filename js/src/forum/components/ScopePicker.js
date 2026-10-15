import Component from 'flarum/common/Component';
import { text, pad, titleFor } from '../util';
import { loadSeason } from '../api';

/**
 * For a show: narrow the discussion to one season, or one episode of it.
 * Changing either rewrites the title to match.
 */
export default class ScopePicker extends Component {
  view() {
    const fields = this.attrs.composer.fields;
    const link = fields.marquee;
    const seasonData = this.seasonData?.number === link.season ? this.seasonData : null;

    if (link.season && !seasonData && link.id) this.load(link.id, link.season);

    return (
      <span className="Marquee-scope">
        <select
          className="FormControl"
          aria-label={text('scope.season_label')}
          value={link.season ? String(link.season) : ''}
          onchange={(e) => this.setSeason(Number(e.target.value) || null)}
        >
          <option value="">{text('scope.whole_show')}</option>
          {link.seasons.map((s) => (
            <option value={String(s.number)}>{text('scope.season_option', { number: s.number, year: s.year || '' })}</option>
          ))}
        </select>
        {link.season ? (
          <select
            className="FormControl"
            aria-label={text('scope.episode_label')}
            disabled={!seasonData}
            value={link.episode ? String(link.episode.n) : ''}
            onchange={(e) => this.setEpisode(seasonData.episodes.find((ep) => String(ep.n) === e.target.value) || null)}
          >
            <option value="">{seasonData ? text('scope.whole_season') : text('scope.loading')}</option>
            {(seasonData?.episodes || []).map((ep) => (
              <option value={String(ep.n)}>{text('scope.episode_option', { number: pad(ep.n), title: ep.title })}</option>
            ))}
          </select>
        ) : null}
      </span>
    );
  }

  load(mediaId, number) {
    if (this.loading === number) return;
    this.loading = number;
    loadSeason(mediaId, number).then(
      (data) => {
        this.seasonData = data;
        this.loading = null;
        m.redraw();
      },
      () => (this.loading = null)
    );
  }

  setSeason(number) {
    const fields = this.attrs.composer.fields;
    Object.assign(fields.marquee, { season: number, episode: null });
    fields.title(titleFor(fields.marquee, number));
  }

  setEpisode(episode) {
    const fields = this.attrs.composer.fields;
    fields.marquee.episode = episode;
    fields.title(titleFor(fields.marquee, fields.marquee.season, episode));
  }
}
