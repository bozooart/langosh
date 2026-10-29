<?php
/**
 * Plugin Name: Langosh
 * Description: Višejezičnost u jednom dodatku: prijevodi sadržaja, pojmova, izbornika i tekstova te SEO oznake (hreflang, sitemap) – bez dodatnih dodataka i bez usporavanja stranice.
 * Version: 1.3.3
 * Author: BozooArt
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Text Domain: langosh
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'LANGOSH_VERSION', '1.3.3' );
define( 'LANGOSH_FILE', __FILE__ );
define( 'LANGOSH_DIR', __DIR__ );

require LANGOSH_DIR . '/inc/core.php';
require LANGOSH_DIR . '/inc/api.php';
require LANGOSH_DIR . '/inc/strings.php';

register_activation_hook( __FILE__, array( 'Langosh', 'install' ) );

/*
 * Two plugins cannot both decide the language of a request. While WPML is active Langosh
 * stays out of the way: only its settings screen (with the WPML import) is available.
 */
if ( ! Langosh::$blocked ) {
	Langosh::detect();

	require LANGOSH_DIR . '/inc/urls.php';
	require LANGOSH_DIR . '/inc/query.php';
	require LANGOSH_DIR . '/inc/seo.php';
	require LANGOSH_DIR . '/inc/acf.php';
	require LANGOSH_DIR . '/inc/media.php';
	require LANGOSH_DIR . '/inc/compat.php';
}

if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	require LANGOSH_DIR . '/admin/import-wpml.php';
}

if ( is_admin() ) {
	require LANGOSH_DIR . '/admin/admin.php';
} elseif ( defined( 'WP_CLI' ) && WP_CLI ) {
	require LANGOSH_DIR . '/admin/settings.php'; // the tools the commands run
	require LANGOSH_DIR . '/inc/cli.php';
}
