import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Select from 'flarum/common/components/Select';
import tagIcon from 'ext:flarum/tags/common/helpers/tagIcon';

const t = (key) => app.translator.trans(`ernestdefoe-marquee.admin.${key}`);

/**
 * Which tags search which source. A discussion started under a mapped tag gets
 * the title picker; every other tag keeps the normal composer.
 */
export default class TagSources extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.tags = null;
    app.store.find('tags').then((tags) => {
      this.tags = tags;
      m.redraw();
    });
  }

  view() {
    const map = this.map();
    const options = { '': t('source_none'), movie: t('source_movie'), tv: t('source_tv'), game: t('source_game'), music: t('source_music') };

    return (
      <div className="Form-group MarqueeTagSources">
        <label>{t('tags_label')}</label>
        <div className="helpText">{t('tags_help')}</div>
        {!this.tags ? (
          <LoadingIndicator size="small" display="inline" />
        ) : (
          <table className="MarqueeTagSources-table">
            <tbody>
              {this.tags.map((tag) => (
                <tr>
                  <td>
                    {tagIcon(tag)} {tag.name()}
                  </td>
                  <td>
                    <Select value={map[tag.id()] || ''} options={options} onchange={(value) => this.set(tag.id(), value)} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    );
  }

  map() {
    try {
      return JSON.parse(this.attrs.stream() || '{}') || {};
    } catch (e) {
      return {};
    }
  }

  set(tagId, kind) {
    const map = this.map();
    if (kind) map[tagId] = kind;
    else delete map[tagId];
    this.attrs.stream(JSON.stringify(map));
  }
}
