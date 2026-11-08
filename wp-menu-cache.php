<?php
/**
 * Plugin Name: WP Menu Cache
 * Plugin URI: https://github.com/Azanumer/wp-menu-cache
 * Description: Caches wp_nav_menu() output in transients so menus are rendered once, not on every page load. Auto-invalidates when menus change; logged-in users are never served cached menus by default.
 * Version: 1.0.0
 * Author: Azan Umer
 * Author URI: https://azanumer.cv
 * License: MIT
 * Text Domain: wp-menu-cache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WMC_VERSION', '1.0.0' );
/** Transient key prefix — used by the admin list and purge-all. */
define( 'WMC_PREFIX', 'wmc_menu_' );

/**
 * Default cache lifetime in seconds (12 hours).
 * Override: add_filter( 'wmc_cache_ttl', function() { return 6 * HOUR_IN_SECONDS; } );
 */
function wmc_ttl() {
	return (int) apply_filters( 'wmc_cache_ttl', 12 * HOUR_IN_SECONDS );
}

/**
 * Should this menu call be cached?
 * Logged-in users are skipped by default — menus often differ per user
 * (login/logout links, member-only items). Opt in with the filter.
 */
function wmc_should_cache( $args ) {
	if ( is_admin() || is_customize_preview() ) {
		return false;
	}
	if ( is_user_logged_in() && ! apply_filters( 'wmc_cache_for_logged_in_users', false ) ) {
		return false;
	}
	// Per-menu opt-out: add_filter( 'wmc_skip_menu_cache', '__return_true' ) in a
	// conditional, or filter on $args (e.g. skip a specific theme_location).
	return ! apply_filters( 'wmc_skip_menu_cache', false, $args );
}

/**
 * Build a cache key from the arguments that actually affect the output.
 * Anything that changes the HTML must be in here, or the wrong menu
 * could be served.
 */
function wmc_cache_key( $args ) {
	$relevant = array(
		'menu'            => isset( $args->menu ) ? $args->menu : '',
		'menu_id'         => isset( $args->menu_id ) ? $args->menu_id : '',
		'menu_class'      => isset( $args->menu_class ) ? $args->menu_class : '',
		'container'       => isset( $args->container ) ? $args->container : '',
		'container_class' => isset( $args->container_class ) ? $args->container_class : '',
		'container_id'    => isset( $args->container_id ) ? $args->container_id : '',
		'theme_location'  => isset( $args->theme_location ) ? $args->theme_location : '',
		'depth'           => isset( $args->depth ) ? $args->depth : 0,
		'items_wrap'      => isset( $args->items_wrap ) ? $args->items_wrap : '',
		'walker'          => isset( $args->walker ) && is_object( $args->walker ) ? get_class( $args->walker ) : '',
		'fallback_cb'     => isset( $args->fallback_cb ) ? ( is_string( $args->fallback_cb ) ? $args->fallback_cb : 'callable' ) : '',
		'before'          => isset( $args->before ) ? $args->before : '',
		'after'           => isset( $args->after ) ? $args->after : '',
		'link_before'     => isset( $args->link_before ) ? $args->link_before : '',
		'link_after'      => isset( $args->link_after ) ? $args->link_after : '',
	);
	return WMC_PREFIX . md5( serialize( $relevant ) );
}

/**
 * Serve a cached menu before WordPress renders it.
 * Returning a non-null string short-circuits wp_nav_menu().
 */
add_filter( 'pre_wp_nav_menu', 'wmc_serve_cached_menu', 10, 2 );
function wmc_serve_cached_menu( $output, $args ) {
	if ( ! wmc_should_cache( $args ) ) {
		return $output;
	}
	$key    = wmc_cache_key( $args );
	$cached = get_transient( $key );
	if ( false !== $cached ) {
		return $cached;
	}
	// Remember which menu this request is rendering so we can store it after.
	$GLOBALS['wmc_pending_key'] = $key;
	return $output;
}

/**
 * Store the freshly rendered menu for the next request.
 * Only stores when pre_wp_nav_menu marked this request as cacheable.
 */
add_filter( 'wp_nav_menu', 'wmc_store_menu', 10, 2 );
function wmc_store_menu( $nav_menu, $args ) {
	if ( empty( $GLOBALS['wmc_pending_key'] ) ) {
		return $nav_menu;
	}
	$key = $GLOBALS['wmc_pending_key'];
	unset( $GLOBALS['wmc_pending_key'] );
	if ( '' !== $nav_menu ) {
		set_transient( $key, $nav_menu, wmc_ttl() );
	}
	return $nav_menu;
}

/** Purge every cached menu. Returns the number of entries removed. */
function wmc_purge_all() {
	global $wpdb;
	$like = $wpdb->esc_like( '_transient_' . WMC_PREFIX ) . '%';
	$keys = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$like
		)
	);
	$count = 0;
	foreach ( $keys as $option_name ) {
		$transient = substr( $option_name, strlen( '_transient_' ) );
		if ( delete_transient( $transient ) ) {
			$count++;
		}
	}
	return $count;
}

/** Invalidate on anything that changes a menu. */
add_action( 'wp_update_nav_menu', 'wmc_invalidate' );
add_action( 'wp_delete_nav_menu', 'wmc_invalidate' );
add_action( 'wp_create_nav_menu', 'wmc_invalidate' );
add_action( 'wp_update_nav_menu_item', 'wmc_invalidate' );
add_action( 'save_post_nav_menu_item', 'wmc_invalidate' );
add_action( 'delete_post', 'wmc_invalidate_on_menu_item_delete' );
add_action( 'switch_theme', 'wmc_invalidate' );
add_action( 'update_option_nav_menu_options', 'wmc_invalidate' );
add_action( 'customize_save_after', 'wmc_invalidate' );

function wmc_invalidate() {
	wmc_purge_all();
}

function wmc_invalidate_on_menu_item_delete( $post_id ) {
	if ( 'nav_menu_item' === get_post_type( $post_id ) ) {
		wmc_purge_all();
	}
}

add_action( 'admin_menu', 'wmc_register_menu' );
function wmc_register_menu() {
	add_options_page(
		__( 'Menu Cache', 'wp-menu-cache' ),
		__( 'Menu Cache', 'wp-menu-cache' ),
		'manage_options',
		'wmc-menu-cache',
		'wmc_render_page'
	);
}

/** Admin-bar shortcut to purge the menu cache from anywhere. */
add_action( 'admin_bar_menu', 'wmc_admin_bar_purge', 100 );
function wmc_admin_bar_purge( $wp_admin_bar ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$wp_admin_bar->add_node( array(
		'id'    => 'wmc-purge',
		'title' => __( 'Purge Menu Cache', 'wp-menu-cache' ),
		'href'  => wp_nonce_url( admin_url( 'admin-post.php?action=wmc_purge' ), 'wmc_purge' ),
	) );
}

add_action( 'admin_post_wmc_purge', 'wmc_handle_purge' );
function wmc_handle_purge() {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'wmc_purge' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'wp-menu-cache' ) );
	}
	$count = wmc_purge_all();
	$redirect = add_query_arg(
		array(
			'page'       => 'wmc-menu-cache',
			'wmc_purged' => $count,
		),
		admin_url( 'options-general.php' )
	);
	wp_safe_redirect( $redirect );
	exit;
}

function wmc_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-menu-cache' ) );
	}

	global $wpdb;
	$purged = isset( $_GET['wmc_purged'] ) ? (int) $_GET['wmc_purged'] : -1;

	$like = $wpdb->esc_like( '_transient_' . WMC_PREFIX ) . '%';
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name",
			$like
		)
	);
	$entries = array();
	foreach ( $rows as $row ) {
		$key      = substr( $row->option_name, strlen( '_transient_' ) );
		$timeout  = get_option( '_transient_timeout_' . $key );
		$entries[] = array(
			'key'     => $key,
			'size'    => strlen( (string) $row->option_value ),
			'expires' => $timeout ? (int) $timeout : 0,
		);
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Menu Cache', 'wp-menu-cache' ); ?></h1>
		<?php if ( $purged >= 0 ) : ?>
			<div class="notice notice-success is-dismissible"><p>
				<?php
				/* translators: %d: number of cached menus purged */
				printf( esc_html__( 'Menu cache purged — %d cached menu(s) removed.', 'wp-menu-cache' ), $purged );
				?>
			</p></div>
		<?php endif; ?>

		<p><?php esc_html_e( 'Menus are cached for 12 hours (filter wmc_cache_ttl to change) and purged automatically whenever a menu, menu item or theme changes. Logged-in users are never served cached menus by default.', 'wp-menu-cache' ); ?></p>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wmc_purge' ), 'wmc_purge' ) ); ?>"><?php esc_html_e( 'Purge all cached menus', 'wp-menu-cache' ); ?></a>
		</p>

		<h2>
			<?php
			/* translators: %d: number of cached menus */
			printf( esc_html__( 'Cached menus (%d)', 'wp-menu-cache' ), count( $entries ) );
			?>
		</h2>
		<?php if ( empty( $entries ) ) : ?>
			<p><?php esc_html_e( 'Nothing cached yet — visit the front end once and menus will appear here.', 'wp-menu-cache' ); ?></p>
		<?php else : ?>
			<table class="widefat fixed striped">
				<thead><tr>
					<th><?php esc_html_e( 'Cache key', 'wp-menu-cache' ); ?></th>
					<th><?php esc_html_e( 'Size', 'wp-menu-cache' ); ?></th>
					<th><?php esc_html_e( 'Expires', 'wp-menu-cache' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $entries as $entry ) : ?>
						<tr>
							<td><code><?php echo esc_html( $entry['key'] ); ?></code></td>
							<td><?php echo esc_html( size_format( $entry['size'] ) ); ?></td>
							<td>
								<?php
								if ( $entry['expires'] > 0 ) {
									echo esc_html( human_time_diff( time(), $entry['expires'] ) ) . ' ' . esc_html__( 'from now', 'wp-menu-cache' );
								} else {
									esc_html_e( 'No expiry set', 'wp-menu-cache' );
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}
