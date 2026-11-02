import Model from 'flarum/common/Model';

/** A linked movie, show or game. `details` is only sent on the discussion page. */
export default class Media extends Model {}

Object.assign(Media.prototype, {
  kind: Model.attribute('kind'),
  externalId: Model.attribute('externalId'),
  title: Model.attribute('title'),
  year: Model.attribute('year'),
  poster: Model.attribute('poster'),
  artist: Model.attribute('artist'),
  details: Model.attribute('details'),
});
