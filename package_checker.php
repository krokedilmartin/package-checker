<?php
/**
 * Plugin Name: Paketkontrollerare
 * Plugin URI: https://example.com/paketkontrollerare
 * Description: Visar installerade paket – antingen från en specifik katalog (exempelvis Krokedil) eller från alla plugins (via en "dependencies"-mapp) – med versionsinformation.
 * Version: 1.0.0
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
 * Genom URL-query-parametern "show_packages" kan du styra vad som visas:
 *
 * - ?show_packages=krokedil
 *   Visar paket i en specifik katalog (här: ../dependencies/krokedil/).
 *
 * - ?show_packages=all
 *   Letar igenom alla installerade plugins. Om ett plugin innehåller en undermapp
 *   "dependencies" visas de paket (mappar) som hittas där och deras installerade version.
 */
function display_packages_notice() {
	if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) || ! isset( $_GET['show_packages'] ) ) {
		return;
	}

	$show_packages = sanitize_text_field( $_GET['show_packages'] );

	if ( $show_packages === 'krokedil' ) {
		// Ange sökvägen till den specifika katalogen med exempelvis Krokedil-paket.
		$krokedil_path = plugin_dir_path( __FILE__ ) . '../dependencies/krokedil/';
		// Lista med GitHub-repository URL:er för de kända paketen.
		$repositories = array(
			'klarna-express-checkout' => 'https://api.github.com/repos/krokedil/klarna-express-checkout/releases/latest',
			'klarna-onsite-messaging' => 'https://api.github.com/repos/krokedil/klarna-onsite-messaging/releases/latest',
			'settings-page'           => 'https://api.github.com/repos/krokedil/settings-page/releases/latest',
			'wp-api'                  => 'https://api.github.com/repos/krokedil/wp-api/releases/latest',
			'woocommerce'             => 'https://api.github.com/repos/krokedil/woocommerce/releases/latest',
			'sign-in-with-klarna'     => 'https://api.github.com/repos/krokedil/sign-in-with-klarna/releases/latest',
		);

		if ( ! is_dir( $krokedil_path ) ) {
			echo '<div style="color: red;">Fel: Krokedil-katalogen hittades inte.</div>';
			return;
		}

		$packages = scandir( $krokedil_path );
		if ( $packages === false ) {
			echo '<div style="color: red;">Fel: Kunde inte läsa Krokedil-katalogen.</div>';
			return;
		}

		$found_packages = false;
		echo '<div style="background: #f9f9f9; padding: 20px; border: 1px solid #ddd; margin: 20px 0;">';
		echo '<h3>Installerade Krokedil Paket</h3>';
		echo '<ul>';

		foreach ( $packages as $package ) {
			// Hoppa över aktuella och överordnade kataloger.
			if ( $package === '.' || $package === '..' ) {
				continue;
			}

			$found_packages    = true;
			$package_path      = trailingslashit( $krokedil_path ) . $package;
			$installed_version = get_installed_package_version( $package_path );
			$latest_version    = 'Unknown';

			// Om paketet finns i repository-listan, hämta den senaste versionen.
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
		echo '</div>';

	} elseif ( $show_packages === 'all' ) {
		// Visa paket från alla installerade plugins genom att leta efter en "dependencies"-mapp.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins = get_plugins();
		echo '<div style="background: #f9f9f9; padding: 20px; border: 1px solid #ddd; margin: 20px 0;">';
		echo '<h3>Installerade Paket från Alla Plugins</h3>';

		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			// Bestäm sökvägen till plugin-mappen
			$plugin_dir       = dirname( WP_PLUGIN_DIR . '/' . $plugin_file );
			$dependencies_dir = $plugin_dir . '/dependencies';

			// Om en "dependencies"-mapp finns, lista de paket (mappar) som ligger där.
			if ( is_dir( $dependencies_dir ) ) {
				echo '<h4>' . esc_html( $plugin_data['Name'] ) . '</h4>';
				$packages = scandir( $dependencies_dir );
				if ( $packages === false ) {
					echo '<p style="color: red;">Kunde inte läsa dependencies-katalogen.</p>';
					continue;
				}
				echo '<ul>';
				foreach ( $packages as $package ) {
					if ( $package === '.' || $package === '..' ) {
						continue;
					}
					$package_path      = trailingslashit( $dependencies_dir ) . $package;
					$installed_version = get_installed_package_version( $package_path );
					echo '<li><strong>' . esc_html( $package ) . '</strong> - Installerad version: ' . esc_html( $installed_version ) . '</li>';
				}
				echo '</ul>';
			}
		}
		echo '</div>';
	}
}
add_action( 'admin_notices', 'display_packages_notice' );
