<?php
/**
 * Clean addresses (owner, 2026-10-16): staff screens at /staff instead of /wp-admin
 * (BSA screens shortest: /staff/dashboard for admin.php?page=bsa-dashboard) and signing
 * in at /sign-in instead of /wp-login.php, so the address bar does not say WordPress.
 *
 * - The WordPress files do not move. A labelled block in the site's .htaccess maps the
 *   new addresses onto them internally (install_rules(); remove_rules() undoes it).
 * - With the bsa_sm_clean_urls option on, links are written with the new addresses,
 *   old addresses typed or bookmarked are redirected (GET only), and the sign-in cookie
 *   that WordPress keeps for /wp-admin is also kept for /staff.
 * - Switched on by scripts/12-clean-urls.php after the rules are checked; off again by
 *   the same script with "off" (links and redirects stop at once; the rules can stay).
 *
 * @package BSA_School_Management
 */

defined( 'ABSPATH' ) || exit;

/**
 * Clean addresses.
 */
class BSA_Clean_Urls {

	const OPTION = 'bsa_sm_clean_urls';
	const MARKER = 'BSA clean addresses';
	const STAFF  = 'staff';
	const SIGNIN = 'sign-in';

	/**
	 * wp-admin scripts that are never redirected (requests from pages and scripts).
	 */
	const NO_REDIRECT = array( 'admin-ajax.php', 'admin-post.php', 'async-upload.php', 'load-scripts.php', 'load-styles.php', 'media-upload.php', 'customize.php', 'update.php', 'update-core.php', 'upgrade.php' );

	/**
	 * Registers hooks (when switched on).
	 */
	public static function init() {
		if ( ! self::enabled() ) {
			return;
		}
		add_filter( 'admin_url', array( __CLASS__, 'filter_url' ), 99 );
		add_filter( 'site_url', array( __CLASS__, 'filter_site_url' ), 99, 2 );
		add_filter( 'network_site_url', array( __CLASS__, 'filter_site_url' ), 99, 2 );
		add_filter( 'wp_redirect', array( __CLASS__, 'filter_url' ), 99 );
		add_action( 'set_auth_cookie', array( __CLASS__, 'set_staff_cookie' ), 10, 6 );
		add_action( 'clear_auth_cookie', array( __CLASS__, 'clear_staff_cookie' ) );
		add_action( 'init', array( __CLASS__, 'redirect_old' ), 0 );
		add_action( 'admin_print_footer_scripts', array( __CLASS__, 'pretty_links_script' ) );
	}

	/**
	 * Whether clean addresses are on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return '1' === (string) get_option( self::OPTION, '' );
	}

	/**
	 * The .htaccess block. Substitutions are full paths from the site root: on the
	 * school's LiteSpeed server relative ones were not picked up for /staff/<screen>.
	 *
	 * @return string[]
	 */
	public static function rules() {
		$base = trailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
		return array(
			'# Staff screens at /' . self::STAFF . ' (WordPress wp-admin) and signing in at /' . self::SIGNIN . ' (wp-login.php).',
			'# Added by the BSA School Management plugin; delete this block to switch it off.',
			'<IfModule mod_rewrite.c>',
			'RewriteEngine On',
			'RewriteBase ' . $base,
			'RewriteRule ^' . self::SIGNIN . '/?$ ' . $base . 'wp-login.php [QSA,L]',
			'RewriteRule ^' . self::STAFF . '/?$ ' . $base . 'wp-admin/index.php [QSA,L]',
			'RewriteRule ^' . self::STAFF . '/([a-z0-9-]+)/?$ ' . $base . 'wp-admin/admin.php?page=bsa-$1 [QSA,L]',
			'RewriteRule ^' . self::STAFF . '/(.+)$ ' . $base . 'wp-admin/$1 [QSA,L]',
			'</IfModule>',
		);
	}

	/**
	 * Path of the site's .htaccess.
	 *
	 * @return string
	 */
	private static function htaccess() {
		return ABSPATH . '.htaccess';
	}

	/**
	 * The .htaccess text without the BSA block (exactly what with_block() added: the
	 * block and the blank line after it).
	 *
	 * @param string $text File text.
	 * @return string
	 */
	public static function without_block( $text ) {
		return (string) preg_replace( '/# BEGIN ' . preg_quote( self::MARKER, '/' ) . '\n.*?# END ' . preg_quote( self::MARKER, '/' ) . '\n\n?/s', '', $text );
	}

	/**
	 * The .htaccess text with the BSA block placed before WordPress's own rules (which
	 * would otherwise send /staff and /sign-in to the front end).
	 *
	 * @param string $text File text.
	 * @return string
	 */
	public static function with_block( $text ) {
		$text  = self::without_block( $text );
		$block = '# BEGIN ' . self::MARKER . "\n" . implode( "\n", self::rules() ) . "\n# END " . self::MARKER . "\n";
		$pos   = strpos( $text, '# BEGIN WordPress' );
		return false === $pos ? $block . "\n" . $text : substr( $text, 0, $pos ) . $block . "\n" . substr( $text, $pos );
	}

	/**
	 * Writes the block into .htaccess (a copy of the file is kept first).
	 *
	 * @param string $backup Path for the copy.
	 * @return bool
	 */
	public static function install_rules( $backup ) {
		$file = self::htaccess();
		$text = file_exists( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( $backup && ! copy( $file, $backup ) ) {
			return false;
		}
		return false !== file_put_contents( $file, self::with_block( $text ), LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Removes the block from .htaccess.
	 *
	 * @return bool
	 */
	public static function remove_rules() {
		$file = self::htaccess();
		$text = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return false !== file_put_contents( $file, self::without_block( $text ), LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * An address on this site with /wp-admin as /staff, and BSA screens shortest
	 * (/staff/admin.php?page=bsa-results&tab=risk → /staff/results?tab=risk).
	 *
	 * @param string $url Address.
	 * @return string
	 */
	public static function pretty( $url ) {
		$url   = (string) $url;
		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url() );
		if ( ! $parts || empty( $parts['path'] ) || ( isset( $parts['host'] ) && strtolower( $parts['host'] ) !== strtolower( (string) ( $home['host'] ?? '' ) ) ) ) {
			return $url;
		}
		$path = preg_replace( '#^/wp-admin(/|$)#', '/' . self::STAFF . '$1', $parts['path'] );
		$query = $parts['query'] ?? '';
		if ( '/' . self::STAFF . '/admin.php' === $path && '' !== $query ) {
			parse_str( $query, $q );
			if ( isset( $q['page'] ) && is_string( $q['page'] ) && preg_match( '/^bsa-([a-z0-9-]+)$/', $q['page'], $m ) ) {
				$path = '/' . self::STAFF . '/' . $m[1];
				// Keep the other arguments exactly as written.
				$query = implode(
					'&',
					array_filter(
						explode( '&', $query ),
						static function ( $pair ) {
							return '' !== $pair && 0 !== strpos( $pair, 'page=' );
						}
					)
				);
			}
		}
		if ( $path === $parts['path'] && ( $parts['query'] ?? '' ) === $query ) {
			return $url;
		}
		$out = ( isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '' ) . ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		return $out . $path . ( '' !== $query ? '?' . $query : '' ) . ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
	}

	/**
	 * admin_url / wp_redirect filter.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	public static function filter_url( $url ) {
		return self::pretty( $url );
	}

	/**
	 * site_url / network_site_url filter: wp-login.php → /sign-in.
	 *
	 * @param string $url  Address.
	 * @param string $path Path asked for.
	 * @return string
	 */
	public static function filter_site_url( $url, $path ) {
		if ( 0 === strpos( ltrim( (string) $path, '/' ), 'wp-login.php' ) ) {
			return (string) preg_replace( '#/wp-login\.php#', '/' . self::SIGNIN, (string) $url, 1 );
		}
		return $url;
	}

	/**
	 * Cookie name for a scheme.
	 *
	 * @param string $scheme auth or secure_auth.
	 * @return string
	 */
	private static function cookie_name( $scheme ) {
		return 'secure_auth' === $scheme ? SECURE_AUTH_COOKIE : AUTH_COOKIE;
	}

	/**
	 * The admin sign-in cookie, also for /staff (WordPress keeps it for /wp-admin only).
	 *
	 * @param string $cookie     Cookie value.
	 * @param int    $expire     When the cookie expires (0: browser session).
	 * @param int    $expiration Session expiry.
	 * @param int    $user_id    User.
	 * @param string $scheme     auth or secure_auth.
	 */
	public static function set_staff_cookie( $cookie, $expire, $expiration, $user_id, $scheme ) {
		if ( headers_sent() ) {
			return;
		}
		setcookie( self::cookie_name( $scheme ), $cookie, $expire, SITECOOKIEPATH . self::STAFF, COOKIE_DOMAIN, 'secure_auth' === $scheme, true );
	}

	/**
	 * Signing out clears the /staff copy too.
	 */
	public static function clear_staff_cookie() {
		if ( headers_sent() ) {
			return;
		}
		foreach ( array( AUTH_COOKIE, SECURE_AUTH_COOKIE ) as $name ) {
			setcookie( $name, ' ', time() - YEAR_IN_SECONDS, SITECOOKIEPATH . self::STAFF, COOKIE_DOMAIN );
		}
	}

	/**
	 * Old addresses (typed, bookmarked, or written by code that does not use admin_url)
	 * go to the new ones: GET requests only. A visit to /wp-admin with a valid sign-in
	 * also gives the /staff cookie, so people already signed in stay signed in.
	 */
	public static function redirect_old() {
		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		$uri  = (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only compared and rebuilt.
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$base = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		$rel  = substr( $path, strlen( $base ) );
		if ( 0 === strpos( $rel, '/wp-login.php' ) ) {
			$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );
			wp_safe_redirect( home_url( '/' . self::SIGNIN . ( '' !== $query ? '?' . $query : '' ) ), 301 );
			exit;
		}
		$is_old  = 0 === strpos( $rel, '/wp-admin' );
		$is_long = 0 === strpos( $rel, '/' . self::STAFF . '/admin.php' ) && false !== strpos( $uri, 'page=bsa-' );
		if ( ! $is_old && ! $is_long ) {
			return;
		}
		$script = basename( $path );
		if ( in_array( $script, self::NO_REDIRECT, true ) || ( '' !== pathinfo( $script, PATHINFO_EXTENSION ) && 'php' !== pathinfo( $script, PATHINFO_EXTENSION ) ) ) {
			return;
		}
		if ( $is_old ) {
			foreach ( array( 'secure_auth' => SECURE_AUTH_COOKIE, 'auth' => AUTH_COOKIE ) as $scheme => $name ) {
				$value = isset( $_COOKIE[ $name ] ) ? (string) wp_unslash( $_COOKIE[ $name ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated next.
				if ( '' !== $value && wp_validate_auth_cookie( $value, $scheme ) ) {
					$bits = explode( '|', $value );
					self::set_staff_cookie( $value, (int) ( $bits[1] ?? 0 ), (int) ( $bits[1] ?? 0 ), 0, $scheme );
				}
			}
		}
		$target = self::pretty( ( is_ssl() ? 'https://' : 'http://' ) . wp_parse_url( home_url(), PHP_URL_HOST ) . $uri );
		if ( $target !== ( is_ssl() ? 'https://' : 'http://' ) . wp_parse_url( home_url(), PHP_URL_HOST ) . $uri ) {
			wp_safe_redirect( $target, 301 );
			exit;
		}
	}

	/**
	 * On staff screens, links written as admin.php?page=bsa-… (WordPress's own menu) are
	 * shown and followed as the short address.
	 */
	public static function pretty_links_script() {
		$staff = esc_js( '/' . self::STAFF . '/' );
		echo "<script>(function(){var re=/(^|\\/)admin\\.php\\?page=bsa-([a-z0-9-]+)(&|&amp;)?/;document.querySelectorAll('a[href*=\"page=bsa-\"]').forEach(function(a){var h=a.getAttribute('href');var m=h.match(re);if(!m){return;}var rest=h.slice(h.indexOf(m[0])+m[0].length);a.setAttribute('href','{$staff}'+m[2]+(rest?'?'+rest:''));});})();</script>"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed script.
	}
}
