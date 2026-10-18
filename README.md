# ws-chapters

WordPress plugin for Widows Sons chapter records. Each chapter has two separate official images, `back_patch` and `chapter_logo`, and each country can have a `national_logo`. Missing images show a neutral "pending" placeholder. Only users with the `manage_ws_chapter_assets` capability (Administrators by default) can change them.

Accepts PNG, WebP and JPEG. SVG is intentionally not supported.

Template helpers: `ws_chapter_back_patch()`, `ws_chapter_logo()`, `ws_country_logo( $term_id )`. REST: `/wp-json/wp/v2/ws_chapter` includes an `insignia` field with image URLs.

License: GPL-2.0-or-later.

## Try it in WordPress Playground

[Open the demo](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/alex-sashev/ws-chapters/main/playground/blueprint.json) — WordPress in your browser with this plugin, the [ws-europe theme](https://github.com/alex-sashev/ws-europe-theme), sample chapters across Europe and test images marked TEST. Nothing is saved; each visit starts fresh.
