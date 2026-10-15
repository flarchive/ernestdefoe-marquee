import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import Model from 'flarum/common/Model';
import Discussion from 'flarum/common/models/Discussion';
import DiscussionHero from 'flarum/forum/components/DiscussionHero';
import DiscussionListItem from 'flarum/forum/components/DiscussionListItem';
import Link from 'flarum/common/components/Link';
import Button from 'flarum/common/components/Button';
import DiscussionControls from 'flarum/forum/utils/DiscussionControls';
import LinkModal from './components/LinkModal';
import Media from './models';
import MediaHero from './components/MediaHero';
import TitlePicker from './components/TitlePicker';
import ScopePicker from './components/ScopePicker';
import { t, img, pad, sourceName, kindForTags, linkFor, titleFor } from './util';
import { applyPrefill } from './startDiscussion';

app.initializers.add('ernestdefoe-marquee', () => {
  app.store.models['marquee-media'] = Media;

  Object.assign(Discussion.prototype, {
    marqueeMedia: Model.hasOne('marqueeMedia'),
    marqueeSeason: Model.attribute('marqueeSeason'),
    marqueeEpisode: Model.attribute('marqueeEpisode'),
    marqueeSeasonData: Model.attribute('marqueeSeasonData'),
  });

  // The composer is a lazy chunk in Flarum 2, so extend it by module path.
  // The tag comes first: it decides whether the title field searches a source.
  extend('flarum/forum/components/DiscussionComposer', 'oncreate', function () {
    applyPrefill(this.composer);
    app.tagList.load(['parent']).then(() => {
      const tags = this.composer.fields.tags || [];
      if (!tags.length && this.chooseTags) this.chooseTags();
    });
  });

  extend('flarum/forum/components/DiscussionComposer', 'headerItems', function (items) {
    const fields = this.composer.fields;

    // A link only stands while its tag does.
    if (fields.marquee && fields.marquee.kind !== kindForTags(fields.tags)) fields.marquee = null;

    items.setContent(
      'discussionTitle',
      <TitlePicker
        value={fields.title()}
        oninput={(value) => fields.title(value)}
        kind={kindForTags(fields.tags)}
        linked={!!fields.marquee}
        onpick={(picked) => {
          fields.marquee = picked;
          fields.title(titleFor(picked));
        }}
        onkeydown={this.onkeydown.bind(this)}
        placeholder={this.attrs.titlePlaceholder}
        disabled={!!this.attrs.disabled}
        inputClassName="DiscussionComposer-title"
        focusOnKind
      />
    );

    const link = fields.marquee;
    if (!link) return;

    items.add(
      'marquee',
      <span className="Marquee-linked">
        {link.poster ? <img src={img(link.poster, 'w92')} alt="" /> : null}
        {t('composer.linked', { title: <b>{link.title}</b>, source: sourceName(link.kind) })}
        <button
          type="button"
          className="Button Button--icon Button--link"
          aria-label={t('composer.remove_link')}
          onclick={() => (fields.marquee = null)}
        >
          <i className="fas fa-times" aria-hidden="true" />
        </button>
      </span>,
      -10
    );

    if (link.seasons && link.seasons.length) {
      items.add('marqueeScope', <ScopePicker link={link} onchange={(l) => fields.title(titleFor(l, l.season, l.episode))} />, -20);
    }
  });

  extend('flarum/forum/components/DiscussionComposer', 'data', function (data) {
    const link = this.composer.fields.marquee;
    if (link) {
      data.marquee = { kind: link.kind, id: link.externalId, season: link.season || null, episode: link.episode ? link.episode.n : null };
    }
  });

  // Change or remove the link on a posted discussion: whoever may rename it.
  extend(DiscussionControls, 'moderationControls', function (items, discussion) {
    if (!discussion.canRename() || !(kindForTags(discussion.tags()) || linkFor(discussion))) return;

    items.add(
      'marquee',
      <Button icon="fas fa-film" onclick={() => app.modal.show(LinkModal, { discussion })}>
        {t(linkFor(discussion) ? 'link.change' : 'link.add')}
      </Button>,
      -10
    );
  });

  // The full card needs the details the discussion page loads; until they
  // arrive (or for a discussion with no link) the normal hero shows.
  override(DiscussionHero.prototype, 'view', function (original) {
    const link = linkFor(this.attrs.discussion);
    return link && link.media.details() ? (
      <MediaHero key={[link.media.id(), link.season, link.episode].join(':')} discussion={this.attrs.discussion} link={link} />
    ) : (
      original()
    );
  });

  extend(DiscussionListItem.prototype, 'authorItems', function (items) {
    const discussion = this.attrs.discussion;
    const link = linkFor(discussion);
    if (!link || !link.media.poster()) return;

    items.add(
      'marqueePoster',
      <Link className="Marquee-listPoster" href={app.route.discussion(discussion)} tabindex="-1" aria-hidden="true">
        <img src={img(link.media.poster(), 'w92')} alt="" loading="lazy" decoding="async" />
      </Link>,
      95
    );
  });

  extend(DiscussionListItem.prototype, 'infoItems', function (items) {
    const link = linkFor(this.attrs.discussion);
    if (!link) return;

    let label;
    if (link.episode) label = t('list.episode', { season: pad(link.season), episode: pad(link.episode) });
    else if (link.season) label = t('list.season', { number: link.season });
    else label = [t(`kind.${link.media.kind()}`), link.media.year() ? ` · ${link.media.year()}` : ''];

    items.add('marquee', <span className="Marquee-listMeta">{label}</span>, 5);
  });
});
