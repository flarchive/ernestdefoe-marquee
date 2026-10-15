<?php

namespace Ernestdefoe\Marquee;

final class Settings
{
    public const TMDB_TOKEN = 'ernestdefoe-marquee.tmdb_token';
    public const IGDB_CLIENT_ID = 'ernestdefoe-marquee.igdb_client_id';
    public const IGDB_CLIENT_SECRET = 'ernestdefoe-marquee.igdb_client_secret';
    /** Empty = follow the forum's default language. */
    public const LANGUAGE = 'ernestdefoe-marquee.language';
    public const LIST_POSTERS = 'ernestdefoe-marquee.list_posters';
    /** JSON object: tag id => kind (movie | tv | game). */
    public const TAGS = 'ernestdefoe-marquee.tags';
}
