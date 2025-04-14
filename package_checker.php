<?php
/**
 * Plugin Name: Paketkontrollerare
 * Plugin URI: https://example.com/paketkontrollerare
 * Description: Visar installerade Krokedil-paket. Vid query-parametern "krokedil" används en fast sökväg; med "all" genomsöks alla installerade plugins efter en "dependencies/krokedil"-mapp.
 * Version: 1.1.0
 * Author: Ditt Namn
 * Author URI: https://example.com
 * License: GPL2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Avsluta om direkt anrop.
}

/**
 * Hämtar den installerade versionen från ett pakets changelog.
 *
 * Funktionen letar efter filerna "changelog.md" eller "CHANGELOG.md" i den angivna katalogen,
 * och plockar ut versionen ur ett mönster "## [X.Y.Z]".
 *
 * @param string $package_path Katalogsökvägen för paketet.
 * @return string Installerad version eller 'Unknown' om ej hittad.
 */
function get_installed_package_version( $package_path ) {
	$version         = 'Unknown';
	$changelog_files = array( 'changelog.md', 'CHANGELOG.md' );

	foreach ( $changelog_files as $file ) {
		$changelog_file = trailingslashit( $package_path ) . $file;
		if ( file_exists( $changelog_file ) ) {
			$changelog_content = file_get_contents( $changelog_file );
			if ( preg_match( '/## \[(\d+\.\d+\.\d+)\]/', $changelog_content, $matches ) ) {
				$version = $matches[1];
				break;
			}
		}
	}

	return $version;
}

/**
 * Hämtar den senaste versionen från en GitHub-repository via dess API.
 *
 * @param string $repository_url GitHub API URL för paketet.
 * @return string Senaste versionen (tag_name) eller 'Unknown' om ej hämtbar.
 */
function get_latest_package_version( $repository_url ) {
	$latest_version = 'Unknown';
	$response       = wp_remote_get( $repository_url );

	if ( is_array( $response ) && ! is_wp_error( $response ) ) {
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( isset( $body['tag_name'] ) ) {
			$latest_version = $body['tag_name'];
		}
	}

	return $latest_version;
}

/**
 * Visar en admin-notis med information om installerade paket.
 *
 * Beroende på query-parametern "show_packages" gör funktionen två olika saker:
 *
 * 1. Om värdet är "krokedil" används en fast sökväg (../dependencies/krokedil/) för att visa Krokedil-paket.
 *
 * 2. Om värdet är "all" så genomsöks alla installerade plugins efter en undermapp "dependencies/krokedil".
 */
function display_packages_notice() {
	if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) || ! isset( $_GET['show_packages'] ) ) {
		return;
	}

	$show_packages = sanitize_text_field( $_GET['show_packages'] );

	// Lista med kända paket och deras GitHub-repositorier.
	$repositories = array(
		'klarna-express-checkout' => 'https://api.github.com/repos/krokedil/klarna-express-checkout/releases/latest',
		'klarna-onsite-messaging' => 'https://api.github.com/repos/krokedil/klarna-onsite-messaging/releases/latest',
		'settings-page'           => 'https://api.github.com/repos/krokedil/settings-page/releases/latest',
		'wp-api'                  => 'https://api.github.com/repos/krokedil/wp-api/releases/latest',
		'woocommerce'             => 'https://api.github.com/repos/krokedil/woocommerce/releases/latest',
		'sign-in-with-klarna'     => 'https://api.github.com/repos/krokedil/sign-in-with-klarna/releases/latest',
	);

	echo '<div style="background: #f9f9f9; padding: 20px; border: 1px solid #ddd; margin: 20px 0;">';

	if ( $show_packages === 'krokedil' ) {

		// Fast sökväg till Krokedil-paket (relativt detta plugin)
		$krokedil_path = plugin_dir_path( __FILE__ ) . '../dependencies/krokedil/';
		if ( ! is_dir( $krokedil_path ) ) {
			echo '<div style="color: red;">Fel: Krokedil-katalogen hittades inte.</div>';
			echo '</div>';
			return;
		}

		$packages = scandir( $krokedil_path );
		if ( $packages === false ) {
			echo '<div style="color: red;">Fel: Kunde inte läsa Krokedil-katalogen.</div>';
			echo '</div>';
			return;
		}

		echo '<h3>Installerade Krokedil Paket</h3>';
		echo '<ul>';
		$found_packages = false;
		foreach ( $packages as $package ) {
			if ( $package === '.' || $package === '..' ) {
				continue;
			}
			$found_packages = true;
			$package_path   = trailingslashit( $krokedil_path ) . $package;
			if ( ! is_dir( $package_path ) ) {
				continue;
			}
			$installed_version = get_installed_package_version( $package_path );
			$latest_version    = 'Unknown';
			if ( isset( $repositories[ $package ] ) ) {
				$latest_version = get_latest_package_version( $repositories[ $package ] );
			}
			$version_status = ( $installed_version === $latest_version ) ? 'Up-to-date' : 'Outdated';
			echo '<li><strong>' . esc_html( $package ) . '</strong> - Installerad version: ' . esc_html( $installed_version ) . ' - Senaste version: ' . esc_html( $latest_version ) . ' (' . esc_html( $version_status ) . ')</li>';
		}
		echo '</ul>';
		if ( ! $found_packages ) {
			echo '<p style="color: orange;">Inga paket hittades i Krokedil-katalogen.</p>';
		}
	} elseif ( $show_packages === 'all' ) {

		// Hämta alla installerade plugins
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins = get_plugins();

		echo '<h3>Installerade Krokedil Paket från Installerade Plugins</h3>';
		$hasPackages = false;

		// Gå igenom alla plugins
		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			// Hämta plugin-katalogen
			$plugin_dir = dirname( WP_PLUGIN_DIR . '/' . $plugin_file );
			// Bygg sökväg till "dependencies/krokedil"-mappen
			$krokedil_dependencies_dir = trailingslashit( $plugin_dir ) . 'dependencies/krokedil';
			if ( is_dir( $krokedil_dependencies_dir ) ) {
				echo '<h4>' . esc_html( $plugin_data['Name'] ) . '</h4>';
				$packages = scandir( $krokedil_dependencies_dir );
				if ( $packages === false ) {
					echo '<p style="color: red;">Kunde inte läsa dependencies-katalogen för ' . esc_html( $plugin_data['Name'] ) . '.</p>';
					continue;
				}
				echo '<ul>';
				foreach ( $packages as $package ) {
					// Hoppa över "." och ".."
					if ( $package === '.' || $package === '..' ) {
						continue;
					}
					$package_path = trailingslashit( $krokedil_dependencies_dir ) . $package;
					if ( ! is_dir( $package_path ) ) {
						continue;
					}
					$hasPackages       = true;
					$installed_version = get_installed_package_version( $package_path );
					$latest_version    = 'Unknown';
					if ( isset( $repositories[ $package ] ) ) {
						$latest_version = get_latest_package_version( $repositories[ $package ] );
					}
					$version_status = ( $installed_version === $latest_version ) ? 'Up-to-date' : 'Outdated';
					echo '<li><strong>' . esc_html( $package ) . '</strong> - Installerad version: ' . esc_html( $installed_version ) . ' - Senaste version: ' . esc_html( $latest_version ) . ' (' . esc_html( $version_status ) . ')</li>';
				}
				echo '</ul>';
			}
		}

		if ( ! $hasPackages ) {
			echo '<p style="color: orange;">Inga Krokedil-paket hittades i några plugins.</p>';
		}
	}

	echo '</div>';
}
add_action( 'admin_notices', 'display_packages_notice' );
