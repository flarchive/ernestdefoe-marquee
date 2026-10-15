# Marquee

![License](https://img.shields.io/badge/license-MIT-blue.svg) [![Latest Stable Version](https://img.shields.io/packagist/v/ernestdefoe/marquee.svg)](https://packagist.org/packages/ernestdefoe/marquee) [![Total Downloads](https://img.shields.io/packagist/dt/ernestdefoe/marquee.svg)](https://packagist.org/packages/ernestdefoe/marquee)

A [Flarum 2](https://flarum.org) extension that links a discussion to a movie, a TV show (down to a single episode), a game or a music album, and turns the discussion's header into that title's card: poster, backdrop, scores, cast and crew, tracklist, synopsis and more. Movies and TV come from [TMDB](https://www.themoviedb.org), games from [IGDB](https://www.igdb.com) and albums from [Deezer](https://www.deezer.com).

![A movie discussion](https://raw.githubusercontent.com/ernestdefoe/marquee/main/.github/screenshots/movie-hero.jpg)

## Features

- **Pick the title while you write the title.** Choose a Movies, TV Shows or Games tag and the discussion title becomes a search box for that source, with posters in the results. Use the arrow keys and Enter, or ignore the list and type a normal title.
- **The tag decides.** You choose which tags use which source. Every other tag keeps the normal composer, untouched.
- **A card in the discussion header.** The poster, a full-width backdrop, scores, release date, rating, runtime, genres, director and writers (or developer and publisher), the synopsis, the cast with photos, and links to the source and the trailer.
- **TV down to the episode.** Link a discussion to a whole show, one season, or one episode. Every show's card lists its seasons; open a season to see its episodes, and open an episode for its still, air date, runtime, score, director, writers, guest stars and synopsis, with previous and next buttons. **Discuss this episode** starts a discussion already titled and linked.
- **Talk about episodes in any post.** In a discussion about a show, write S02E04, S2E4, "Season 2 Episode 4", S02 or "Season 2" and it becomes a chip: an episode chip shows the episode's name, previews its still when you hover and opens its details when clicked; a season chip opens that season in the header. Typing S2E in the editor suggests that season's episodes. Outside show discussions the text stays exactly as typed.
- **Music albums,** with no key to set up: the cover, the artist, release date, label, genres, length, fan count and the full tracklist.
- **Games.** Critic and member ratings, platforms, developer, publisher, series, how long it takes to beat (main story, main plus extras, completionist) and screenshots.
- **Posters in the discussion list,** with the title's year, or the season and episode, under each discussion.
- **Change it later.** Anyone who can rename a discussion gets **Change linked title** in its controls menu: pick a different title, a different season or episode, or remove the link, and optionally rename the discussion to match.
- **Works on any theme.** Built and tested on Flarum's default theme and [Bespoke](https://github.com/ernestdefoe/bespoke). The card keeps the core header's classes, so themes that style the header still apply where it makes sense.
- **Translatable.** Every word on screen comes from the language files, and the language TMDB answers in is a setting.

![Searching while you type the title](https://raw.githubusercontent.com/ernestdefoe/marquee/main/.github/screenshots/composer-search.png)

### TV shows, seasons and episodes

![An episode discussion](https://raw.githubusercontent.com/ernestdefoe/marquee/main/.github/screenshots/episode-hero.jpg)

![Seasons and their episodes](https://raw.githubusercontent.com/ernestdefoe/marquee/main/.github/screenshots/season-browser.jpg)

![One episode's details](https://raw.githubusercontent.com/ernestdefoe/marquee/main/.github/screenshots/episode-details.jpg)

### Seasons and episodes in posts

![Season and episode chips in a post, with a preview](https://raw.githubusercontent.com/ernestdefoe/marquee/main/.github/screenshots/episode-references.png)

![Episode suggestions while typing](https://raw.githubusercontent.com/ernestdefoe/marquee/main/.github/screenshots/episode-autocomplete.png)

### Music

![An album discussion](https://raw.githubusercontent.com/ernestdefoe/marquee/main/.github/screenshots/music-hero.jpg)

![Searching for an album](https://raw.githubusercontent.com/ernestdefoe/marquee/main/.github/screenshots/music-search.png)

### Changing the linked title

![Changing what a discussion is linked to](https://raw.githubusercontent.com/ernestdefoe/marquee/main/.github/screenshots/change-link.png)

### Games

![A game discussion](https://raw.githubusercontent.com/ernestdefoe/marquee/main/.github/screenshots/game-hero.jpg)

### The discussion list

![Posters in the discussion list](https://raw.githubusercontent.com/ernestdefoe/marquee/main/.github/screenshots/discussion-list.png)

### On a phone

<img src="https://raw.githubusercontent.com/ernestdefoe/marquee/main/.github/screenshots/mobile-episode.jpg" alt="An episode discussion on a phone" width="320">

## Installation

```sh
composer require ernestdefoe/marquee
php flarum migrate
```

Then enable **Marquee** in your admin panel. It needs the bundled Tags extension.

Season and episode chips apply to posts written or edited after installing; older posts keep their plain text until they are edited.

## Setting it up

![The settings page](https://raw.githubusercontent.com/ernestdefoe/marquee/main/.github/screenshots/admin-settings.png)

1. **Movies and TV (TMDB).** Create a free account at themoviedb.org, open [Settings → API](https://www.themoviedb.org/settings/api), and copy the long **API Read Access Token** into Marquee's settings.
2. **Games (IGDB).** Register an app in the [Twitch developer console](https://dev.twitch.tv/console/apps) (Twitch runs IGDB). Use `https://localhost` as the OAuth redirect URL (Marquee never uses it, but the form needs one), choose **Confidential** as the client type, then create a client secret. Copy the Client ID and secret into Marquee's settings.
3. **Music (Deezer)** needs nothing: Deezer's album search is public.
4. **Choose your tags.** In **Which tags use which source**, set your movie tag to Movies, your TV tag to TV shows, your games tag to Games and your music tag to Music albums.

A source without its keys stays off: its tags behave like any other tag until the keys are added.

## Updating

```sh
composer update ernestdefoe/marquee
php flarum migrate
php flarum cache:clear
```

## Good to know

- **Page views never call TMDB or IGDB.** A title's details are fetched once, when someone links it, and stored on your forum. They refresh at most once a week, and only when someone links that title again. A TV season is fetched the first time anyone opens it.
- **Lean on every page.** The discussion list adds one database query in total, however many linked discussions it shows, and sends only each title's name, year and poster. The full details travel only with the discussion that shows them.
- **Searches are cached and capped.** Each search is kept for a day, so ten members typing the same title cost one call. Each member is limited to 40 searches a minute, and IGDB calls are paced to stay under its limit of four a second.
- **Your keys stay on the server.** The TMDB token and the Twitch secret are never sent to browsers. Searches go through your forum.
- **Who can link.** Anyone who can start discussions. Changing or removing the link on an existing discussion (**Change linked title** in its controls menu) takes the same permission as renaming it.

## Data, attribution and licensing

- Movie and TV data and images come from TMDB. This product uses the TMDB API but is not endorsed or certified by TMDB. TMDB's API is free for non-commercial use; a forum that makes money should check [TMDB's terms](https://www.themoviedb.org/api-terms-of-use).
- Album data and covers come from Deezer's public API.
- Game data and images come from IGDB.com. The IGDB API is free for non-commercial use under the Twitch Developer Services Agreement; commercial use needs a [partnership with IGDB](https://api-docs.igdb.com/#partnership).
- Each card shows the attribution its source asks for.

## Links

- [Packagist](https://packagist.org/packages/ernestdefoe/marquee)
- [GitHub](https://github.com/ernestdefoe/marquee)
- [Report an issue](https://github.com/ernestdefoe/marquee/issues)

☕ If you like the things I have built, consider [buying me a coffee](https://buymeacoffee.com/ernestdefoe).
