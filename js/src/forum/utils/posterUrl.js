import { img } from '../util';

/**
 * The URL of a stored poster or image path (TMDB, IGDB, Deezer or Open Library)
 * at one of the TMDB sizes: w92, w185, w300, w342, w780, w1280. Exported for
 * companion extensions: `import posterUrl from 'ext:ernestdefoe/marquee/forum/utils/posterUrl'`.
 */
export default function posterUrl(path, size) {
  return img(path, size);
}
