# WP Menu Cache

Caches `wp_nav_menu()` output in transients so your menus are rendered once, not on every single page load. On a site with a big mega-menu, that is real work saved on every request.

## Features

- **Transparent caching** — hooks `pre_wp_nav_menu` / `wp_nav_menu`; themes and plugins need no changes.
- **Correct cache keys** — the key covers menu, theme location, classes, container, depth, walker class and all wrap arguments, so two different menus never collide.
- **Auto-invalidation** — cache is purged whenever a menu or menu item is created, updated or deleted, when the theme switches, or when the Customizer saves.
- **Safe by default** — logged-in users are never served cached menus (menus often differ per user: login/logout links, member-only items). Opt in with the `wmc_cache_for_logged_in_users` filter if your menus are identical for everyone.
- **Admin tools** — **Settings → Menu Cache** lists every cached menu with size and expiry, plus a one-click purge; an admin-bar "Purge Menu Cache" shortcut is available everywhere.

## Filters

```php
// Cache for 6 hours instead of 12
add_filter( 'wmc_cache_ttl', function() { return 6 * HOUR_IN_SECONDS; } );

// Cache menus for logged-in users too (only if your menus are identical for all users)
add_filter( 'wmc_cache_for_logged_in_users', '__return_true' );

// Skip caching a specific menu
add_filter( 'wmc_skip_menu_cache', function( $skip, $args ) {
    return 'header' === $args->theme_location ? true : $skip;
}, 10, 2 );
```

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it from **Plugins**.
3. Visit the front end once — menus are cached from the first render.

## Notes

- Menus are stored as transients, so they work with any object-cache drop-in (Redis/Memcached) or fall back to the options table.
- Uninstalling purges all cached menus; the plugin stores no options of its own.

## License

MIT — see [LICENSE](LICENSE).
