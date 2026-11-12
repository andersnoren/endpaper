=== Endpaper – Book Blocks for Hardcover ===
Contributors: anlino
Tags: books, reading, hardcover, block, reading list
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show books from Hardcover on your site: a single book, or the books you are reading. Unofficial, not maintained or endorsed by Hardcover.

== Description ==

Endpaper adds two blocks that show books from [Hardcover](https://hardcover.app), the book tracking site.

**Endpaper is an unofficial plugin.** It is created independently, using Hardcover’s public API, and is not maintained or endorsed by Hardcover. Hardcover does not provide support for it: for help, use the plugin’s support forum here on WordPress.org.

**The Book block** shows one book as a card: the cover, title, authors, a short description, the publication year, the page count and the rating on Hardcover. Search by title, author or ISBN, or paste a link to the book’s page on Hardcover. It can also show which of your own shelves the book is on, and how far you have read. The cover is the one of your own edition when the book is in your library, and you can pick the cover of any other edition.

**The Reading List block** shows books from your own Hardcover library: the ones you are reading now, have read, or want to read. Show them as a list or as a grid of covers, with your reading progress, your ratings and the dates you finished.

The blocks are made to fit your theme:

* Sizes follow the block’s font size, and colors follow the text color, so the blocks work on light and dark designs alike.
* The accent color comes from your theme. You can change it for a single block.
* The standard color, typography, spacing, border and shadow settings are supported, in the editor and in `theme.json`.
* Only books that are public on Hardcover are shown. A book you keep private there never appears on your site.

In a hardcover book, the endpapers are what attach the book block to the cover. This plugin does the same for WordPress blocks and Hardcover.

== External services ==

Endpaper gets all its book data from Hardcover, through the Hardcover API. The plugin cannot show anything without it. You need a Hardcover account and an API key, which you add yourself on the plugin’s settings screen. Nothing is sent to Hardcover before you have done that.

**What is sent to Hardcover, and when**

* Your API key, with every request, so Hardcover knows whose account is asking.
* What you type in the book search, while you search for a book in the editor.
* The ID of a book, when a Book block is shown and the stored copy of the book is more than a week old.
* A request for your own public reading list, shelf or reading progress, when a block that shows them is displayed and the stored copy is more than an hour old.
* A request for your username, when you save a key or open the settings screen, to confirm whose key it is.

These requests are made by your site’s server. No information about your visitors is sent in them.

**What your visitors’ browsers load from Hardcover**

Book covers are loaded directly from Hardcover’s servers. When a visitor views a page with a cover on it, their browser asks Hardcover for the image, and Hardcover receives the visitor’s IP address and browser details, as with any image on the web.

**Links to Hardcover**

The title and cover of a book link to its page on Hardcover. You can switch that off for each block. Each block can also show the Hardcover logo, linked to Hardcover, as a credit. The logo is off until you switch it on.

The service is provided by Hardcover: [terms of service](https://hardcover.app/pages/terms-of-service), [privacy policy](https://hardcover.app/pages/privacy-policy-2).

== Installation ==

1. Install and activate Endpaper.
2. Go to Settings, then Endpaper.
3. Use the button on that screen to create an API key on Hardcover, with the right permissions already ticked, and paste it in.
4. Add a Book or a Reading List block to a post or a page.

To keep the key out of the database, you can define it in `wp-config.php` instead:

`define( 'ENDPAPER_HARDCOVER_TOKEN', 'your-api-key' );`

== Frequently Asked Questions ==

= Is this an official Hardcover plugin? =

No. Endpaper is created independently, using Hardcover’s public API. Hardcover does not maintain or endorse it. For questions about the plugin, use its support forum on WordPress.org, not Hardcover’s own channels.

= Do I need a Hardcover account? =

Yes. The plugin reads from Hardcover with an API key from your own account. Accounts are free.

= Which permissions does the API key need? =

`read:catalog`, `read:library:public` and `read:me:content`. The settings screen also suggests `read:goals:public` and `read:lists:public`, for blocks that are planned, because a key cannot be changed once it is created. None of these let the plugin change anything on Hardcover, or read anything you have not made public.

= Is my API key safe? =

The key is only ever sent to Hardcover. Your server makes all the requests, so the key never reaches your visitors’ browsers, the page source or the editor, and once it is saved the settings screen never shows it again. The permissions the plugin asks for can only read, and only what you have made public. Deleting the plugin deletes the key.

The key is stored in your site’s database as it is, not encrypted, which is how WordPress stores settings. Anyone who can read the database, or a backup of it, can read the key. If you think it has got out, delete it on Hardcover and create a new one. Developers can keep it out of the database by defining `ENDPAPER_HARDCOVER_TOKEN` in `wp-config.php` instead.

= Will the blocks slow my site down? =

No. Endpaper saves what it fetches on your site, so your pages do not wait for Hardcover each time they are viewed. Books are saved for a week, and reading lists, shelves and progress for an hour. If Hardcover cannot be reached, the blocks show the last copy they have.

= Why is a book, my shelf or my rating missing? =

Endpaper only shows what is public. Your shelf, rating and progress for a book appear when your activity on that book is public on Hardcover, and the Reading List leaves out books where it is not. After changing it on Hardcover, clear the cache on the settings screen to see the change right away.

= I changed something on Hardcover. Why has my site not updated? =

Endpaper saves what it fetches for a while, so a change can take some time to appear. To fetch everything again now, use the “Clear the cache” button on the settings screen.

= Can I choose which cover a book has? =

Yes. A book shows the cover of the edition in your library, if it is there, and otherwise Hardcover’s default cover for the book. To pick another edition’s cover, select the Book block and open “Cover” in the block settings.

= Can I change how the blocks look? =

Yes. The blocks take their fonts and colors from your theme, and they support the color, typography, spacing, border and shadow settings in the editor. Theme authors can set defaults in `theme.json`, including the accent color and the corner radius of covers.

= What happens when I delete the plugin? =

The API key and everything the plugin has stored are removed. Book and Reading List blocks left in your posts stop showing.

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
