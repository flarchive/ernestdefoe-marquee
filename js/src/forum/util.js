import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';

export const t = (key, params) => app.translator.trans(`ernestdefoe-marquee.forum.${key}`, params);
export const text = (key, params) => extractText(t(key, params));

const TMDB_IMAGES = 'https://image.tmdb.org/t/p/';
const IGDB_IMAGES = 'https://images.igdb.com/igdb/image/upload/';

// The nearest IGDB size for each TMDB size used here (api-docs.igdb.com#images).
const IGDB_SIZES = { w92: 'cover_small_2x', w185: 'thumb_2x', w300: 'screenshot_med', w342: 'cover_big_2x', w780: 'screenshot_big', w1280: '1080p' };

/**
 * An image URL at one of the TMDB sizes (w92, w185, w300, w342, w780, w1280).
 * IGDB images are stored as `igdb:{image_id}` and mapped to IGDB's own sizes.
 */
export const img = (path, size) => {
  if (!path) return null;
  if (path.startsWith('igdb:')) return `${IGDB_IMAGES}t_${IGDB_SIZES[size] || 'cover_big'}/${path.slice(5)}.jpg`;
  return TMDB_IMAGES + size + path;
};

export const pad = (n) => String(n).padStart(2, '0');

export const runtime = (minutes) => {
  if (!minutes) return null;
  if (minutes < 60) return text('meta.runtime_minutes', { minutes });
  if (minutes % 60 === 0) return text('meta.runtime_hours_only', { hours: minutes / 60 });
  return text('meta.runtime_hours', { hours: Math.floor(minutes / 60), minutes: minutes % 60 });
};

export const date = (value) => (value ? dayjs(value).format('LL') : null);

export const sourceName = (kind) => (kind === 'game' ? 'IGDB' : 'TMDB');

/** Kind (movie | tv | game) for the first tag that has a source, if any. */
export const kindForTags = (tags) => {
  const map = app.forum.attribute('marqueeTags') || {};
  return (tags || []).map((tag) => map[tag.id()]).find(Boolean);
};

export const tagForKind = (kind) => {
  const map = app.forum.attribute('marqueeTags') || {};
  const id = Object.keys(map).find((tagId) => map[tagId] === kind);
  return id ? app.store.getById('tags', id) : null;
};

/** The discussion title for a title, one of its seasons, or one episode. */
export const titleFor = ({ title, year }, season, episode) => {
  const base = year ? `${title} (${year})` : title;
  if (season && episode) return text('title.episode', { base, season: pad(season), episode: pad(episode.n), name: episode.title });
  if (season) return text('title.season', { base, number: season });
  return base;
};

/** What a discussion is linked to, or null. */
export const linkFor = (discussion) => {
  const media = discussion.marqueeMedia && discussion.marqueeMedia();
  if (!media) return null;

  return {
    media,
    season: discussion.marqueeSeason() || null,
    episode: discussion.marqueeEpisode() || null,
    seasonData: discussion.marqueeSeasonData() || null,
  };
};

/** The plain object the composer keeps for a picked title. */
export const pickFromMedia = (media) => ({
  id: media.id(),
  kind: media.kind(),
  externalId: media.externalId(),
  title: media.title(),
  year: media.year(),
  poster: media.poster(),
  seasons: media.details()?.seasons || null,
  season: null,
  episode: null,
});
