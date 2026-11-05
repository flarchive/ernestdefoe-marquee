<?php

namespace Ernestdefoe\Marquee;

final class Settings
{
    public const TMDB_TOKEN = 'ernestdefoe-marquee.tmdb_token';
    public const IGDB_CLIENT_ID = 'ernestdefoe-marquee.igdb_client_id';
    public const IGDB_CLIENT_SECRET = 'ernestdefoe-marquee.igdb_client_secret';
    /** Optional: translates game and book text into the data language. */
    public const DEEPL_KEY = 'ernestdefoe-marquee.deepl_key';
    /** Empty = follow the forum's default language. */
    public const LANGUAGE = 'ernestdefoe-marquee.language';
    public const LIST_POSTERS = 'ernestdefoe-marquee.list_posters';
    /** Score rings and ratings on the card (TMDB, IGDB, Open Library). */
    public const SHOW_SCORES = 'ernestdefoe-marquee.show_scores';
    /** The card's "View on TMDB/IGDB…" button. The attribution line always shows. */
    public const SHOW_SOURCE_LINK = 'ernestdefoe-marquee.show_source_link';
    /** What the discussion list says under a linked discussion: kind_year | year | none. */
    public const LIST_META = 'ernestdefoe-marquee.list_meta';
    public const LIST_META_CHOICES = ['kind_year', 'year', 'none'];
    /** A game card's background: artwork_screenshot (a screenshot when there is no artwork) | artwork | none. */
    public const GAME_BACKDROP = 'ernestdefoe-marquee.game_backdrop';
    public const GAME_BACKDROP_CHOICES = ['artwork_screenshot', 'artwork', 'none'];
    /** A card with no background image is this colour (#rrggbb); empty = the forum's banner colour. */
    public const BACKDROP_COLOR = 'ernestdefoe-marquee.backdrop_color';
    /** Without a colour set: the discussion's tag colour when it has one, before the banner's. */
    public const BACKDROP_TAG_COLOR = 'ernestdefoe-marquee.backdrop_tag_color';
    /** "Title (2020)" in discussion titles and on the card; off = the title alone. */
    public const TITLE_YEAR = 'ernestdefoe-marquee.title_year';
    /** A corner button with the card's actions once the card has scrolled away. */
    public const DOCK = 'ernestdefoe-marquee.dock';
    /** Font Awesome classes for that button, such as "fas fa-film"; empty = the cover art. */
    public const DOCK_ICON = 'ernestdefoe-marquee.dock_icon';
    /** JSON object: tag id => kind (movie | tv | game). */
    public const TAGS = 'ernestdefoe-marquee.tags';
}
