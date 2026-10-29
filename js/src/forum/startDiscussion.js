import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import { tagForKind, titleFor } from './util';

let pending = null;

/**
 * Open the composer already tagged, titled and linked. The composer loads
 * lazily and starts with empty fields, so the prefill is applied when it is
 * created (applyPrefill), before the tag picker would open.
 */
export function startDiscussion(link) {
  pending = link;
  return IndexSidebar.prototype.newDiscussionAction.call({}).catch(() => (pending = null));
}

export function applyPrefill(composer) {
  if (!pending) return;
  const link = pending;
  pending = null;

  const tag = tagForKind(link.kind);
  if (tag) composer.fields.tags = [tag];
  composer.fields.title(titleFor(link, link.season, link.episode));
  composer.fields.marquee = link;
}
