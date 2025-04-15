<?php
/**
 * Plugin Name: Krokedil Paketöversikt
 * Plugin URI: https://example.com/krokedil-paketoversikt
 * Description: Visar översikt över installerade Krokedil-paket med förbättrad användarupplevelse.
 * Version: 1.2.1
 * Author: Ditt Namn
 * Author URI: https://example.com
 * License: GPL2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Avsluta om direkt anrop
}

/**
 * Hämtar den installerade versionen från ett pakets changelog.
 *
 * Funktionen letar efter filerna "changelog.md" eller "CHANGELOG.md" i den angivna katalogen
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
 * Hämtar den senaste versionen från ett repository via GitHub API.
 *
 * @param string $repository_url GitHub API-URL för paketet.
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
 * Registrerar en adminmeny så att pluginets översikt kan nås från adminpanelen.
 */
function krokedil_packages_admin_menu() {
	add_menu_page(
		'Krokedil Paketöversikt',  // Sidans titel
		'Krokedil Paket',          // Menynamn
		'manage_options',
		'krokedil-packages',
		'krokedil_packages_admin_page',
		'dashicons-admin-plugins', // Ikon (kan ändras om så önskas)
		81                         // Position i menyn
	);
}
add_action( 'admin_menu', 'krokedil_packages_admin_menu' );

/**
 * Funktion som renderar admin-sidan med översikt över Krokedil-paket.
 */
function krokedil_packages_admin_page() {
	?>
	<div class="wrap">
		<h1>Krokedil Paketöversikt</h1>
		<p>Denna sida visar översikt över installerade Krokedil-paket från installerade plugins.</p>
		
		<!-- Enkel inline CSS för att förbättra tabellutseendet -->
		<style>
			.krokedil-packages-table {
				margin-top: 20px;
			}
			.krokedil-packages-table table {
				width: 100%;
				border-collapse: collapse;
			}
			.krokedil-packages-table th,
			.krokedil-packages-table td {
				padding: 8px;
				border: 1px solid #ddd;
				text-align: left;
			}
			.krokedil-packages-table th {
				background-color: #f7f7f7;
			}
			.status-up-to-date {
				color: green;
				font-weight: bold;
			}
			.status-outdated {
				color: red;
				font-weight: bold;
			}
		</style>
		
		<?php
		// Lista med kända paket och deras GitHub-repositorier
		$repositories = array(
			'klarna-express-checkout' => 'https://api.github.com/repos/krokedil/klarna-express-checkout/releases/latest',
			'klarna-onsite-messaging' => 'https://api.github.com/repos/krokedil/klarna-onsite-messaging/releases/latest',
			'settings-page'           => 'https://api.github.com/repos/krokedil/settings-page/releases/latest',
			'wp-api'                  => 'https://api.github.com/repos/krokedil/wp-api/releases/latest',
			'woocommerce'             => 'https://api.github.com/repos/krokedil/woocommerce/releases/latest',
			'sign-in-with-klarna'     => 'https://api.github.com/repos/krokedil/sign-in-with-klarna/releases/latest',
			'shipping'                => 'https://api.github.com/repos/krokedil/shipping/releases/latest',
		);

		echo '<h2>Krokedil Paket från Installerade Plugins</h2>';
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins  = get_plugins();
		$has_packages = false;

		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			// Hämta plugin-katalogen
			$plugin_dir = plugin_dir_path( WP_PLUGIN_DIR . '/' . $plugin_file );
			// Bygg sökväg till "dependencies/krokedil"-mappen
			$krokedil_dependencies = trailingslashit( $plugin_dir ) . 'dependencies/krokedil';
			if ( is_dir( $krokedil_dependencies ) ) {
				echo '<h3>' . esc_html( $plugin_data['Name'] ) . '</h3>';
				$packages = scandir( $krokedil_dependencies );
				if ( $packages === false ) {
					echo '<p style="color: red;">Kunde inte läsa dependencies-katalogen för ' . esc_html( $plugin_data['Name'] ) . '.</p>';
					continue;
				}
				// Starta tabellen för detta plugin
				echo '<div class="krokedil-packages-table">';
				echo '<table class="wp-list-table widefat fixed striped">';
				echo '<thead><tr><th>Paket</th><th>Installerad version</th><th>Senaste version</th><th>Status</th></tr></thead>';
				echo '<tbody>';

				foreach ( $packages as $package ) {
					if ( $package === '.' || $package === '..' ) {
						continue;
					}
					$package_path = trailingslashit( $krokedil_dependencies ) . $package;
					if ( ! is_dir( $package_path ) ) {
						continue;
					}
					$has_packages      = true;
					$installed_version = get_installed_package_version( $package_path );
					$latest_version    = 'Unknown';
					if ( isset( $repositories[ $package ] ) ) {
						$latest_version = get_latest_package_version( $repositories[ $package ] );
					}
					$status       = ( $installed_version === $latest_version ) ? 'Up-to-date' : 'Outdated';
					$status_class = ( $status === 'Up-to-date' ) ? 'status-up-to-date' : 'status-outdated';

					echo '<tr>';
						echo '<td>' . esc_html( $package ) . '</td>';
						echo '<td>' . esc_html( $installed_version ) . '</td>';
						echo '<td>' . esc_html( $latest_version ) . '</td>';
						echo '<td class="' . esc_attr( $status_class ) . '">' . esc_html( $status ) . '</td>';
					echo '</tr>';
				}

				echo '</tbody>';
				echo '</table>';
				echo '</div>';
			}
		}
		if ( ! $has_packages ) {
			echo '<p style="color: orange;">Inga Krokedil-paket hittades i installerade plugins.</p>';
		}
		?>
	</div>
	<?php
}
?>
