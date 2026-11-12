# Endpaper

Book blocks for WordPress, powered by [Hardcover](https://hardcover.app).

Endpaper lets you show books from Hardcover in your posts and pages. Embed a single book with its cover, author, rating and description, or show a list of the books you are reading from your own Hardcover library.

In a hardcover book, the endpapers are what attach the book block to the cover. This plugin does the same for WordPress blocks and Hardcover.

> [!NOTE]
> Endpaper is an unofficial plugin. It is created independently, using Hardcover’s public API, and is not maintained or endorsed by Hardcover. Please bring questions and requests about the plugin to this repository’s issues, not to Hardcover.

## The Book block

- Search Hardcover by title, author or ISBN, or paste a link to a book’s Hardcover page. A link pasted on a line of its own in the editor becomes a Book block.
- Shows the book as a card: the cover, title, authors, a description, and a row with the publication year, page count and Hardcover rating.
- Can also show which of your own Hardcover shelves the book is on, and your reading progress while you are reading it.
- Can show your own review in place of the description, with its headings, bold text, quotes and lists, and a link to the review on Hardcover. A review that Hardcover marks as having spoilers is closed until it is opened. The review can be limited in length, and is cut on a whole paragraph where it can.
- Uses the cover of the edition in your Hardcover library when the book is public there. You can pick the cover of another edition in the block’s settings.
- Layout settings: the cover can be small, medium or large, and sit on the left, on the right or above the text. Text alignment (left, center or right) is in the block toolbar and applies to everything in the card. When a narrow card puts the cover above the text, the cover lines up with the text.
- The Card style gives the book a light background, rounded corners and padding. Under Settings, Endpaper, you can choose which style new Book blocks start with. Blocks that already have a style keep it.
- Can credit Hardcover in the card, with their logo linked to the book there. The logo is off until you switch it on.
- The other parts can be switched on or off. The description can be limited in length, and ends on a whole sentence where it can.
- Sizes follow the block’s font size, and colors follow the text color, so the card fits light and dark designs alike.
- Supports the color, typography, spacing, border and shadow settings in the block editor. The border, padding, background and shadow apply to the card.

## The Reading List block

- Shows books from your own Hardcover library: the ones you are currently reading, have read, or want to read.
- Comes with ready-made Currently Reading, Recently Read and Want to Read variations in the block inserter.
- Can be shown as a list or as a grid of covers, with up to six columns.
- Shows the cover of your edition, the title, authors and your rating for each book, and your reading progress for books you are currently reading. Each part can be switched on or off.
- Only shows books that are public on Hardcover, so a book you keep private there never appears on your site.

## Patterns

Two patterns are included, in the Endpaper category of the block inserter: **Currently reading**, a heading with the books you are reading now, and **Recently read**, a heading with the books you last finished. Both are lists of three.

## For theme authors

The blocks have very little styling of their own, and support the standard color, typography, spacing, border and shadow settings. A theme can style them in `theme.json` like any core block, under `styles.blocks` with `endpaper/book`, `endpaper/reading-list` and `endpaper/reading-goal`.

Both blocks have a margin above and below by default: 1.5em, and never less than 32px. A theme changes it the same way as any other style:

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

A few settings are specific to Endpaper. A theme sets their defaults under `settings.custom`:

```json
{
	"settings": {
		"custom": {
			"endpaper": {
				"accent": "var(--wp--preset--color--primary)",
				"coverRadius": "4px",
				"mutedText": 0.75
			}
		}
	}
}
```

- `accent` is the accent color, used for the reading progress bar, the rating stars and the shelf label. Without it, the blocks pick one from the theme’s palette: the first of `accent`, `accent-1` to `accent-6` and `primary` that stands out from the background, with a contrast of at least 3:1. If none does, or the theme has no accent colors, they use the text color.
- `coverRadius` is the corner radius of book covers. Without it, the radius is 4px.
- `mutedText` is the opacity of secondary text, such as the date of a review. The default is 0.75.
- `mutedElement` is the opacity of small marks, such as the dots between facts and the line beside a quote. The default is 0.33.
- `hover` is the opacity links and logos fade to under the pointer. The default is 0.75.
- `columnMin` is the narrowest a column in the Reading List grid gets. The default is 10em. A narrow card gets fewer columns, down to one.

The same values can be set as CSS variables, `--endpaper-muted-text`, `--endpaper-muted-element`, `--endpaper-hover` and `--endpaper-column-min`, on the block or anywhere above it.

To set any of them for one block only, put the same `custom` settings under `settings.blocks`:

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

The accent color and cover radius can also be changed for a single block in the editor, which takes precedence over the theme.

## Book covers

Covers are downloaded from Hardcover once and shown from the site itself, so visitors’ browsers don’t contact Hardcover. They are saved in `wp-content/uploads/endpaper/covers`, not in the media library, in three widths (160, 320 and 640px, never larger than the original), as WebP where the server can write it and JPEG where it can’t. A cover that isn’t saved yet shows as a placeholder: it is saved in the background, usually within a minute. Covers no block has shown for a month are removed, and all of them when the cache is cleared or the plugin is deleted.

If the site can’t save files, covers are loaded from Hardcover instead, and Site Health says so.

Developers can change this with filters:

| Filter | What it does |
| --- | --- |
| `endpaper_cover_widths` | The widths covers are saved in, in pixels. Default `[ 160, 320, 640 ]`. |
| `endpaper_cover_quality` | The quality they are saved with, from 1 to 100. Default `80`. |
| `endpaper_import_covers` | Return `false` to load covers from Hardcover and save nothing. |
| `endpaper_allow_remote_covers` | Return `false` to never load a cover from Hardcover. A cover that can’t be saved is then a placeholder. |
| `endpaper_cover_hosts` | The hosts covers may be downloaded from. Default `[ 'assets.hardcover.app' ]`. |

Returning `false` from both `endpaper_import_covers` and `endpaper_allow_remote_covers` means no covers are shown at all. The edition picker in the editor always loads its thumbnails from Hardcover, in the browser of the person editing.

The format follows WordPress’s own `image_editor_output_format` filter, so a site that saves its images as AVIF gets AVIF covers.

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

4. Go to Settings, then Endpaper, and add your Hardcover API key. A button there opens Hardcover’s form for a new key with the right permissions already ticked.

To keep the key out of the database, define it in `wp-config.php` instead, above the line that says to stop editing. It then takes precedence over the settings screen:

```php
define( 'ENDPAPER_HARDCOVER_TOKEN', 'your-api-key' );
```

### Permissions for the API key

The button on the settings screen asks for these permissions and nothing else. A key cannot be changed after it is created, which is why the last two are included now.

| Permission | Used for |
| --- | --- |
| `read:catalog` | Looking up and searching for books. |
| `read:library:public` | Your reading lists, shelves and progress. Public entries only. |
| `read:me:content` | Your username, to confirm whose key it is. |
| `read:goals:public` | Your reading goals, for a block to come. |
| `read:lists:public` | Your lists, for a block to come. |

None of these let Endpaper change anything on Hardcover, or read anything you haven’t made public.

## How it uses the Hardcover API

- **Your API key is only sent to Hardcover.** All requests to Hardcover are made from PHP. The key is never sent to the browser, and the settings screen never shows a saved key. It is stored in the site’s database as it is, not encrypted, or you can define it as `ENDPAPER_HARDCOVER_TOKEN` in `wp-config.php` to keep it out of the database. Deleting the plugin removes the key and everything it has cached.
- **Requests are cached.** A book is fetched once and stored for a week, and a reading list is refreshed at most once an hour, so showing either doesn’t call Hardcover on every page view. When a saved copy gets old, visitors still get it at once and a new one is fetched in the background through WP-Cron, so nobody waits for Hardcover (without WP-Cron, the next visitor waits instead). The update button in a block’s toolbar fetches that block’s data again at once: your shelf, rating, progress and review, a reading list or the goals. Each person can use it once every ten seconds. Search requests are only made while you are searching in the editor, are cached for an hour, and are limited to 20 a minute for each person.
- **It keeps working if Hardcover can’t be reached.** If a request fails, the block shows the last copy it has.
- **Cover images are saved on your site.** Each cover is downloaded from Hardcover once and shown from your own site after that. See “Book covers” above.

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
