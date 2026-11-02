import app from 'flarum/forum/app';

const url = (path) => `${app.forum.attribute('apiUrl')}/marquee/${path}`;
const quiet = () => {};

export const search = (kind, q) => app.request({ method: 'GET', url: url('search'), params: { kind, q }, errorHandler: quiet }).then((r) => r.data);

export const lookup = (kind, id) => app.request({ method: 'POST', url: url('media'), body: { kind, id }, errorHandler: quiet }).then((r) => r.data);

const seasons = new Map();

/** One season with its episodes, fetched at most once per page load. */
export const loadSeason = (mediaId, number) => {
  const key = `${mediaId}:${number}`;
  if (!seasons.has(key)) {
    seasons.set(
      key,
      app.request({ method: 'GET', url: url(`media/${mediaId}/seasons/${number}`), errorHandler: quiet }).then(
        (r) => r.data,
        (e) => {
          seasons.delete(key);
          throw e;
        }
      )
    );
  }
  return seasons.get(key);
};

/** A season that arrived with the discussion needs no request. */
export const primeSeason = (mediaId, data) => {
  if (data) seasons.set(`${mediaId}:${data.number}`, Promise.resolve(data));
};
