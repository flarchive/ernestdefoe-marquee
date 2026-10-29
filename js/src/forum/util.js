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
// Deezer serves any square size; these match the TMDB sizes used here.
const DEEZER_SIZES = { w92: 120, w185: 250, w300: 500, w342: 500, w780: 1000, w1280: 1000 };

// Open Library covers come in S, M and L; M is about 180px wide, L about 500px.
const OPEN_LIBRARY_SIZES = { w92: 'M', w185: 'M' };

export const img = (path, size) => {
  if (!path) return null;
  if (path.startsWith('ol:')) {
    const [, type, id] = path.split(':');
    return `https://covers.openlibrary.org/${type}/id/${id}-${OPEN_LIBRARY_SIZES[size] || 'L'}.jpg`;
  }
  if (path.startsWith('igdb:')) return `${IGDB_IMAGES}t_${IGDB_SIZES[size] || 'cover_big'}/${path.slice(5)}.jpg`;
  if (path.startsWith('deezer:')) {
    const [, type, md5] = path.split(':');
    const px = DEEZER_SIZES[size] || 500;
    return `https://cdn-images.dzcdn.net/images/${type}/${md5}/${px}x${px}-000000-80-0-0.jpg`;
  }
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

export const sourceName = (kind) => ({ game: 'IGDB', music: 'Deezer', book: 'Open Library' })[kind] || 'TMDB';

/** Kind (movie | tv | game | music | book) for the first tag that has a source, if any. */
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
export const titleFor = ({ title, year, artist }, season, episode) => {
  const name = artist ? `${artist} – ${title}` : title;
  const base = year ? `${name} (${year})` : name;
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
  artist: media.artist() || null,
  seasons: media.details()?.seasons || null,
  season: null,
  episode: null,
});

/**
 * IGDB's genres and game modes are a short fixed list, translated by key
 * (forum.genre.<slug>, forum.mode.<slug>). Titles stored before the slugs were
 * kept get them from the English name; anything unknown stays as it came.
 */
export const igdbTerms = (group, names, slugs) =>
  (names || []).map((name, i) => {
    const slug =
      (slugs && slugs[i]) ||
      String(name)
        .toLowerCase()
        .replace(/&/g, 'and')
        .replace(/'/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '');
    const key = `ernestdefoe-marquee.forum.${group}.${slug}`;
    return app.translator.translations[key] ? extractText(app.translator.trans(key)) : name;
  });

/** Admin display switches (on unless turned off). */
export const showScores = () => app.forum.attribute('marqueeScores') !== false;
export const showSourceLink = () => app.forum.attribute('marqueeSourceLink') !== false;
