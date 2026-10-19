# ws-chapters (1.0.0)

WordPress plugin behind the Widows Sons Europe site (https://wseurope.org). Works with the [ws-europe theme](https://github.com/alex-sashev/ws-europe-theme).

## What it does

- **Chapters and countries:** post type `ws_chapter` (`/chapters/<name>/`) and taxonomy `ws_country` (`/country/<name>/`). Each chapter has two separate official images, `back_patch` and `chapter_logo`; each country a `national_logo` and a flag. Missing images show the Widows Sons emblem. Insignia are shown as uploaded: never cropped, recoloured or substituted. PNG, WebP and JPEG; no SVG.
- **Map of Europe:** Leaflet with country shapes, no map tiles; pins on a chapter's coordinates, its city, or its country's capital.
- **Events and news,** filed by country; event and join forms with spam protection and no third-party CAPTCHA.
- **Roles:** Super Admin (administrator), Country Admin, Chapter Editor and Member, each limited to their own country or chapters.
- **Editing on the site:** pencils on chapter and country pages for the people who manage them; news submit and delete.
- **Members' area:** Library (private documents), Members (contact book), My profile (sign in, set a password, profile and title), Member management (invite, titles, chapters, remove, activity).
- **Branded HTML emails** for invitations and new passwords.
- **Private mode** before launch, and **daily data retention** as stated in the privacy policy.

## Code map

`ws-chapters.php` registers the data model and loads `includes/`:
`access` (roles and scopes) · `accounts` · `profile-fields` · `location` · `display` · `events` · `pages` (shortcodes for templates) · `edit-form` · `forms` · `members` (library) · `links` · `retention` · `upload-folders` · `auto-purge` (cache) · `login-box` · `emails` · `member-management` · `member-account` · `front-edit` · `site-setup` (install of a new live site) · `private-mode`.

Template helpers: `ws_chapter_back_patch()`, `ws_chapter_logo()`, `ws_country_logo( $term_id )`. REST: `/wp-json/wp/v2/ws_chapter` includes an `insignia` field.

## Try it in WordPress Playground

[Open the demo](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/alex-sashev/ws-chapters/main/playground/blueprint.json): WordPress in your browser with this plugin, the theme and sample content. Nothing is saved; each visit starts fresh.

License: GPL-2.0-or-later.
