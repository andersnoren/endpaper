# Endpaper

Book blocks for WordPress, powered by [Hardcover](https://hardcover.app).

Endpaper lets you show books from Hardcover in your posts and pages. Embed a single book with its cover, author, rating and description, or show a list of the books you are reading from your own Hardcover library.

In a hardcover book, the endpapers are what attach the book block to the cover. This plugin does the same for WordPress blocks and Hardcover.

> [!NOTE]
> Endpaper is an early work in progress. It hasn’t been released yet, and it will change. It is not affiliated with or endorsed by Hardcover.

## The Book block

- Search Hardcover by title, author or ISBN, or paste a link to a book’s Hardcover page.
- Shows the book as a card: the cover, title, authors, a description, and a row with the publication year, page count and Hardcover rating.
- Can also show which of your own Hardcover shelves the book is on, and your reading progress while you are reading it.
- Always credits Hardcover under the card, with a link to the book there.
- The other parts can be switched on or off. The description can be limited in length, and ends on a whole sentence where it can.
- Sizes follow the block’s font size, and colors follow the text color, so the card fits light and dark designs alike.
- Supports the color, typography, spacing, border and shadow settings in the block editor. The border, padding, background and shadow apply to the card.

## The Reading List block

- Shows books from your own Hardcover library: the ones you are currently reading, have read, or want to read.
- Comes with ready-made Currently Reading, Recently Read and Want to Read variations in the block inserter.
- Can be shown as a list or as a grid of covers.
- Shows the cover, title, authors and your rating for each book, and your reading progress for books you are currently reading. Each part can be switched on or off.
- Only shows books that are public on Hardcover, so a book you keep private there never appears on your site.

## For theme authors

The blocks have very little styling of their own, and support the standard color, typography, spacing, border and shadow settings. A theme can style them in `theme.json` like any core block, under `styles.blocks` with `endpaper/book` and `endpaper/reading-list`.

Both blocks have a margin of 1.5em above and below by default. A theme changes it the same way as any other style:

```json
{
	"styles": {
		"blocks": {
			"endpaper/book": {
				"spacing": {
					"margin": {
						"top": "3rem",
						"bottom": "3rem"
					}
				}
			}
		}
	}
}
```

The Margin setting of a single block in the editor takes precedence over the theme.

Two settings are specific to Endpaper. A theme sets their defaults under `settings.custom`:

```json
{
	"settings": {
		"custom": {
			"endpaper": {
				"accent": "var(--wp--preset--color--primary)",
				"coverRadius": "4px"
			}
		}
	}
}
```

- `accent` is the accent color, used for the reading progress bar, the rating stars and the shelf label. Without it, the blocks pick one from the theme’s palette: the first of `accent` and `accent-1` to `accent-6` that stands out from the background, with a contrast of at least 3:1. If none does, they use the text color. A theme without accent colors gets indigo (`#4f46e5`).
- `coverRadius` is the corner radius of book covers. Without it, the radius is 8px in the Book block and 4px in the Reading List.

To set either for one block only, put the same `custom` settings under `settings.blocks`:

```json
{
	"settings": {
		"blocks": {
			"endpaper/reading-list": {
				"custom": {
					"endpaper": {
						"accent": "#c2410c"
					}
				}
			}
		}
	}
}
```

Both can also be changed for a single block in the editor, which takes precedence over the theme.

## Requirements

- WordPress 6.6 or later
- PHP 7.4 or later
- A [Hardcover](https://hardcover.app) account and an API key, which you can create in your Hardcover account settings

## Installation

Endpaper isn’t in the WordPress.org plugin directory yet, so for now it has to be installed from source.

1. Clone this repository into `wp-content/plugins/`:

	```sh
	git clone https://github.com/andersnoren/endpaper.git
	```

2. Build the block assets from the plugin folder:

	```sh
	npm install
	npm run build
	```

3. Activate Endpaper on the Plugins screen in WordPress.

4. Go to Settings, then Endpaper, and add your Hardcover API key. The screen explains how to create one, and which permissions it needs.

To keep the key out of the database, define it in `wp-config.php` instead, above the line that says to stop editing. It then takes precedence over the settings screen:

```php
define( 'ENDPAPER_HARDCOVER_TOKEN', 'your-api-key' );
```

### Permissions for the API key

Create the key without a preset, with these permissions and nothing else. A key cannot be changed after it is created, so the last two are worth ticking now.

| Permission | Used for |
| --- | --- |
| `read:catalog` | Looking up and searching for books. |
| `read:library:public` | Your reading lists, shelves and progress. Public entries only. |
| `read:me:content` | Your username, to confirm whose key it is. |
| `read:goals:public` | Your reading goals, for a block to come. |
| `read:lists:public` | Your lists, for a block to come. |

None of these let Endpaper change anything on Hardcover, or read anything you haven’t made public.

## How it uses the Hardcover API

- **Your API key stays on your server.** All requests to Hardcover are made from PHP. The key is never sent to the browser, and the settings screen never shows a saved key. Deleting the plugin removes the key and everything it has cached.
- **Requests are cached.** A book is fetched once and stored for a week, and a reading list is refreshed at most once an hour, so showing either doesn’t call Hardcover on every page view. Search requests are only made while you are searching in the editor.
- **It keeps working if Hardcover can’t be reached.** If a request fails, the block shows the last copy it has.
- **Cover images are loaded from Hardcover.** They are not copied to your media library.

## Development

Install the tools once:

```sh
npm install
composer install
```

| Command | What it does |
|---|---|
| `npm run build` | Builds the blocks from `src/` into `build/` |
| `npm start` | Rebuilds on every change |
| `npm run lint:js` | Checks the JavaScript |
| `npm run lint:css` | Checks the styles |
| `npm run format` | Fixes JavaScript formatting |
| `composer run phpcs` | Checks the PHP against the WordPress coding standards |
| `composer run phpcbf` | Fixes the PHP issues that can be fixed automatically |

The code is organized like this:

- `endpaper.php` is the main plugin file.
- `includes/` holds the Hardcover API client, caching and the REST route the editor uses.
- `src/blocks/` holds the source of each block.
- `build/` is generated by `npm run build` and isn’t part of the repository.

## License

Endpaper is free software, released under the [GNU General Public License, version 2 or later](https://www.gnu.org/licenses/gpl-2.0.html).
