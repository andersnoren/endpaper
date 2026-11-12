=== Endpaper – Book Blocks for Hardcover ===
Contributors: anlino
Tags: books, reading, hardcover, block, reading list
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show books from Hardcover on your site: a single book, your reading lists or a reading goal. Unofficial, not maintained or endorsed by Hardcover.

== Description ==

Endpaper brings your reading life from [Hardcover](https://hardcover.app), the book tracking site, to your WordPress site. Put a book in a post, show what you are reading right now, or share how far along you are on your reading goal. Everything is a block, and everything looks like it belongs on your site.

**Endpaper is an unofficial plugin.** It is created independently, using Hardcover’s public API, and is not maintained or endorsed by Hardcover. Hardcover does not provide support for it: for help, use the plugin’s support forum here on WordPress.org.

= Show off the books you love =

* **Embed any book.** Search Hardcover by title, author or ISBN, or paste a link to the book’s page on a line of its own, and it becomes a Book block. You get the cover, title, authors, a description, the year, the page count and the Hardcover rating.
* **Add your own take.** Show your rating, which of your shelves the book is on and how far you have read. Or let your own review take the place of the description, with a link to it on Hardcover. Reviews keep their headings, quotes and lists, and a review with spoilers stays closed until a visitor opens it.
* **Choose the right cover.** A book shows the cover of your own edition when it is in your library, and you can pick the cover of any other edition.
* **Make it fit the page.** Let a book sit quietly in your text, or lift it onto a soft card with rounded corners. Put the cover where it reads best, beside the text or above it, in the size you like. Then make it yours in the editor: the colors and the typography are all yours to change.

= Show what you are reading =

* **Share your shelves.** The Reading List block shows the books you are reading now, have read or want to read, as a list or as a grid of covers with up to six columns. Ready-made versions are in the block inserter, and two patterns get you started.
* **Show your progress.** Add how far you have read, your star ratings and the dates you finished. Each part is optional.
* **Track a reading goal.** The Reading Goal block shows how far along you are on one of your Hardcover goals, counted in books, pages or hours, as a progress bar or a half-circle gauge. It shows the days left, and says so when you have reached the goal.

= Made to fit your theme =

* **Looks like your site.** Sizes follow the block’s font size, and colors follow the text color, so the blocks work on light and dark designs alike. The accent color comes from your theme, and you can change it for a single block.
* **The controls you know.** The standard color, typography, spacing, border and shadow settings are supported, in the editor and in `theme.json`, along with wide and full alignment.

= Fast, and respectful of your visitors =

* **Nobody waits for Hardcover.** What the plugin fetches is saved on your site and refreshed in the background, so your pages load as fast as any other page.
* **Covers from your own site.** They are saved in the right sizes and loaded lazily, and your visitors’ browsers never contact Hardcover. The plugin sets no cookies.
* **Only what is public.** A book you keep private on Hardcover never appears on your site.
* **Your key stays on your server.** It is only ever sent to Hardcover, and the permissions it needs can only read.

= For developers =

* **Style it in `theme.json`.** Set the accent color, the corner radius of covers and how faded secondary text is, for all blocks or for one block, or use the CSS variables.
* **Filters for covers.** Change the sizes and quality covers are saved in, or switch saving off.

In a hardcover book, the endpapers are what attach the book block to the cover. This plugin does the same for WordPress blocks and Hardcover.

== External services ==

Endpaper gets all its book data from Hardcover, through the Hardcover API. The plugin cannot show anything without it. You need a Hardcover account and an API key, which you add yourself on the plugin’s settings screen. Nothing is sent to Hardcover before you have done that.

**What is sent to Hardcover, and when**

* Your API key, with every request, so Hardcover knows whose account is asking.
* What you type in the book search, while you search for a book in the editor.
* The ID of a book, when a Book block is shown and the stored copy of the book is more than a week old, or there is none yet.
* A request for your own public reading list, shelf, reading progress or reading goals, when a block that shows them is displayed and the stored copy is more than an hour old, or there is none yet.
* A request for your username, when you save a key or open the settings screen, to confirm whose key it is.
* A request for the cover of a book, once for each cover a block shows. The cover is saved on your site, in a folder of the plugin’s own under uploads, and shown from there.

These requests are made by your site’s server. No information about your visitors is sent in them.

**What your visitors’ browsers load from Hardcover**

Nothing, normally. Book covers are saved on your site and shown from there.

There are two exceptions. If your site cannot save files, or a developer has switched saving off, covers are loaded directly from Hardcover’s servers instead. A visitor’s browser then asks Hardcover for each image, and Hardcover receives the visitor’s IP address and browser details, though not the address of the page. Site Health tells you when this is the case, and a developer can switch this fallback off with the `endpaper_allow_remote_covers` filter. And in the editor, when you choose a cover for a Book block, the covers to choose from are loaded from Hardcover by the browser of the person editing.

**Links to Hardcover**

The title and cover of a book link to its page on Hardcover. You can switch that off for each block. Each block can also show the Hardcover logo, linked to Hardcover, as a credit. The logo is off until you switch it on.

The service is provided by Hardcover: [terms of service](https://hardcover.app/pages/terms-of-service), [privacy policy](https://hardcover.app/pages/privacy-policy-2).

== Installation ==

1. Install and activate Endpaper.
2. Go to Settings, then Endpaper.
3. Use the button on that screen to create an API key on Hardcover, with the right permissions already ticked, and paste it in.
4. Add a Book, Reading List or Reading Goal block to a post or a page.

To keep the key out of the database, you can define it in `wp-config.php` instead:

`define( 'ENDPAPER_HARDCOVER_TOKEN', 'your-api-key' );`

== Frequently Asked Questions ==

= Is this an official Hardcover plugin? =

No. Endpaper is created independently, using Hardcover’s public API. Hardcover does not maintain or endorse it. For questions about the plugin, use its support forum on WordPress.org, not Hardcover’s own channels.

= Do I need a Hardcover account? =

Yes. The plugin reads from Hardcover with an API key from your own account. Accounts are free.

= Which permissions does the API key need? =

`read:catalog`, `read:library:public` and `read:me:content`, and `read:goals:public` for the Reading Goal block. The settings screen also suggests `read:lists:public`, for a block that is planned, because a key cannot be changed once it is created. None of these let the plugin change anything on Hardcover, or read anything you have not made public.

= Is my API key safe? =

The key is only ever sent to Hardcover. Your server makes all the requests, so the key never reaches your visitors’ browsers, the page source or the editor, and once it is saved the settings screen never shows it again. The permissions the plugin asks for can only read, and only what you have made public. Deleting the plugin deletes the key.

The key is stored in your site’s database as it is, not encrypted, which is how WordPress stores settings. Anyone who can read the database, or a backup of it, can read the key. If you think it has got out, delete it on Hardcover and create a new one. Developers can keep it out of the database by defining `ENDPAPER_HARDCOVER_TOKEN` in `wp-config.php` instead.

= Will the blocks slow my site down? =

No. Endpaper saves what it fetches on your site, so your pages do not wait for Hardcover each time they are viewed. Books are saved for a week, and reading lists, shelves and progress for an hour. When a saved copy gets old, visitors still get it at once, and a new one is fetched in the background, so nobody waits for Hardcover. The same goes for something that is not saved yet, such as the first view after you clear the cache: that visitor sees nothing in its place, and the next one has it. (This uses WP-Cron. On a site where WP-Cron is switched off, the visitor waits for the data instead.) If Hardcover cannot be reached, the blocks show the last copy they have.

= Why is a book, my shelf or my rating missing? =

Endpaper only shows what is public. Your shelf, rating and progress for a book appear when your activity on that book is public on Hardcover, and the Reading List leaves out books where it is not. After changing it on Hardcover, use the update button in the block’s toolbar to see the change right away.

= I changed something on Hardcover. Why has my site not updated? =

Endpaper saves what it fetches for a while, so a change can take some time to appear. To fetch a block’s data again now, use the update button in its toolbar. It fetches your own activity: your shelf, rating, progress and review, the reading lists and your goals. To fetch everything again, use the “Clear the cache” button on the settings screen.

= Why is my reading goal missing? =

Endpaper only shows goals that are public on Hardcover and not archived, and only goals counted in books, pages or hours. Choose the goal in the block settings. If the goal was archived, made private or deleted on Hardcover, the block shows nothing to your visitors, and people who can edit the page see a note.

= Can I choose which cover a book has? =

Yes. A book shows the cover of the edition in your library, if it is there, and otherwise Hardcover’s default cover for the book. To pick another edition’s cover, select the Book block and open “Cover” in the block settings.

= Where are book covers stored? =

In a folder of the plugin’s own, `wp-content/uploads/endpaper/covers`. Each cover is downloaded from Hardcover once and shown from your site after that. Covers are not added to your media library. A cover is removed when no block has shown it for a month, and all of them when you clear the cache or delete the plugin.

Covers are saved 160, 320 and 640 pixels wide, never larger than the cover Hardcover has, as WebP where your server can write it and as JPEG where it cannot. If your site is set up to save its images in another format, such as AVIF, covers follow that.

= Why is a cover a gray box? =

The cover is being saved. That happens in the background, usually within a minute, so that your visitors do not wait for it. The box is also shown for a book that has no cover on Hardcover, and when Hardcover could not be reached, in which case the plugin tries again after an hour.

= Do my visitors’ browsers contact Hardcover? =

No. Covers are shown from your own site, and nothing else is loaded from Hardcover. The plugin sets no cookies and stores nothing about your visitors. The one exception is a site that cannot save covers: see “External services” above. A developer can rule that out with the `endpaper_allow_remote_covers` filter.

= Can developers change how covers are saved? =

Yes, with these filters:

* `endpaper_cover_widths`: the widths covers are saved in, in pixels.
* `endpaper_cover_quality`: the quality they are saved with, from 1 to 100. It is 80 to begin with.
* `endpaper_import_covers`: return false to load covers from Hardcover and save nothing.
* `endpaper_allow_remote_covers`: return false to never load a cover from Hardcover. A cover that cannot be saved is then a gray box.

The format follows WordPress’s own `image_editor_output_format` filter.

= Can I change how the blocks look? =

Yes. A Book block has layout settings for the size and position of the cover and the alignment of the text, and a Reading List block can be a list or a grid. The Book block comes in two styles, Plain and Card, and you choose which one new Book blocks start with under Settings, Endpaper. The Reading Goal block comes as a bar or a gauge. The blocks take their fonts and colors from your theme, and they support the color, typography, spacing, border and shadow settings in the editor. Theme authors can set defaults in `theme.json`, including the accent color and the corner radius of covers.

= What happens when I delete the plugin? =

The API key and everything the plugin has stored, the book covers included, are removed. Book, Reading List and Reading Goal blocks left in your posts stop showing.

= What happens when my API key expires? =

Hardcover keys can have an end date. When Hardcover stops accepting the key, the blocks keep showing what they last fetched, for as long as the cached version lasts, and administrators see a notice in the admin with a link to the settings. Site Health reports it too. Save a new key and everything picks up again.

== Screenshots ==

1. The Book block.
2. The Reading List block, as a list.
3. The Reading List block, as a grid.
4. The settings screen.

== Changelog ==

= 1.0.0 =

* First version.
