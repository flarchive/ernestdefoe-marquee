import { Admin } from 'flarum/common/extenders';
import app from 'flarum/admin/app';
import extractText from 'flarum/common/utils/extractText';
import TagSources from './TagSources';

const t = (key, params) => app.translator.trans(`ernestdefoe-marquee.admin.${key}`, params);

export default [
  new Admin()
    // Custom so the help can carry a link: a setting's help is not rendered as rich text.
    .customSetting(function () {
      return (
        <div className="Form-group">
          <label for="marquee-tmdb-token">{t('tmdb_token_label')}</label>
          <div className="helpText">
            {t('tmdb_token_help', { a: <a href="https://www.themoviedb.org/settings/api" target="_blank" rel="noopener noreferrer" /> })}
          </div>
          <input
            id="marquee-tmdb-token"
            className="FormControl"
            type="password"
            autocomplete="off"
            bidi={this.setting('ernestdefoe-marquee.tmdb_token')}
          />
        </div>
      );
    }, 100)
    // Games: IGDB uses Twitch app credentials, exchanged on the server for a token.
    .customSetting(function () {
      return (
        <div className="Form-group">
          <label for="marquee-igdb-id">{t('igdb_label')}</label>
          <div className="helpText">
            {t('igdb_help', { a: <a href="https://dev.twitch.tv/console/apps" target="_blank" rel="noopener noreferrer" /> })}
          </div>
          <div className="MarqueeIgdbFields">
            <input
              id="marquee-igdb-id"
              className="FormControl"
              placeholder={extractText(t('igdb_client_id'))}
              autocomplete="off"
              bidi={this.setting('ernestdefoe-marquee.igdb_client_id')}
            />
            <input
              className="FormControl"
              type="password"
              aria-label={extractText(t('igdb_client_secret'))}
              placeholder={extractText(t('igdb_client_secret'))}
              autocomplete="off"
              bidi={this.setting('ernestdefoe-marquee.igdb_client_secret')}
            />
          </div>
        </div>
      );
    }, 95)
    // Optional: translates game and book text, which only comes in English.
    .customSetting(function () {
      return (
        <div className="Form-group">
          <label for="marquee-deepl-key">{t('deepl_label')}</label>
          <div className="helpText">
            {t('deepl_help', { a: <a href="https://www.deepl.com/pro-api" target="_blank" rel="noopener noreferrer" /> })}
          </div>
          <input
            id="marquee-deepl-key"
            className="FormControl"
            type="password"
            autocomplete="off"
            bidi={this.setting('ernestdefoe-marquee.deepl_key')}
          />
        </div>
      );
    }, 92)
    .setting(
      () => ({
        setting: 'ernestdefoe-marquee.language',
        type: 'text',
        label: t('language_label'),
        help: t('language_help'),
        placeholder: t('language_placeholder'),
      }),
      90
    )
    .setting(
      () => ({
        setting: 'ernestdefoe-marquee.list_posters',
        type: 'switch',
        label: t('list_posters_label'),
        help: t('list_posters_help'),
      }),
      85
    )
    .setting(
      () => ({
        setting: 'ernestdefoe-marquee.list_meta',
        type: 'select',
        label: t('list_meta_label'),
        help: t('list_meta_help'),
        options: { kind_year: t('list_meta_kind_year'), year: t('list_meta_year'), none: t('list_meta_none') },
        default: 'kind_year',
      }),
      84
    )
    .setting(
      () => ({
        setting: 'ernestdefoe-marquee.show_scores',
        type: 'switch',
        label: t('show_scores_label'),
        help: t('show_scores_help'),
      }),
      83
    )
    .setting(
      () => ({
        setting: 'ernestdefoe-marquee.show_source_link',
        type: 'switch',
        label: t('show_source_link_label'),
        help: t('show_source_link_help'),
      }),
      82
    )
    .customSetting(function () {
      return <TagSources stream={this.setting('ernestdefoe-marquee.tags', '{}')} />;
    }, 80),
];
