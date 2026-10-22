<?php
/**
 * Plugin Name:       SERU Dropdown Blanks
 * Description:       Dropdown fill-in-the-blank questions for LearnDash. Converts the old "Jumbled Sentence" questions to LearnDash's own Fill in the blank type (with backup and revert) and shows every blank as a dropdown.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Borun Studios
 * License:           GPL-2.0-or-later
 * Text Domain:       seru-dropdown-blanks
 * GitHub Plugin URI: bayonsa/dropdown-blanks
 * Primary Branch:    main
 *
 * @package SERU_Dropdown_Blanks
 */

defined( 'ABSPATH' ) || exit;

define( 'SDDB_VERSION', '0.1.0' );
define( 'SDDB_DIR', plugin_dir_path( __FILE__ ) );
define( 'SDDB_URL', plugin_dir_url( __FILE__ ) );

require_once SDDB_DIR . 'class-sddb-transform.php';
require_once SDDB_DIR . 'class-sddb-converter.php';
require_once SDDB_DIR . 'class-sddb-frontend.php';
require_once SDDB_DIR . 'class-sddb-admin.php';

add_action(
	'plugins_loaded',
	function () {
		SDDB_Frontend::init();
		if ( is_admin() ) {
			SDDB_Admin::init();
		}
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'tools.php?page=seru-dropdown-blanks' ) ) . '">Open</a>' );
		return $links;
	}
);
