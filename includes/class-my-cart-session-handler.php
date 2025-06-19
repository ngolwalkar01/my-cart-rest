<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'My_Cart_Session' ) ) {
	return;
}

class My_Cart_Session_Handler extends My_Cart_Session {

	protected $_cookie;
	protected $_cart_expiring;
	protected $_cart_expiration;
	protected $_cart_source;
	protected $_has_cookie = false;
	protected $_table;
	public $counter = 0;

	public function __construct() {
		$this->_cookie = 'wp_my_cart_session_' . COOKIEHASH;
		$this->_table = $GLOBALS['wpdb']->prefix . 'woocommerce_sessions';
	}
	
	public function init() {
		/* if ( is_null( WC()->customer ) || ! WC()->customer instanceof WC_Customer ) {
			WC()->customer = new WC_Customer( get_current_user_id(), true );
			// Customer should be saved during shutdown.
			add_action( 'shutdown', array( WC()->customer, 'save' ), 10 );
		} */
		$this->init_session_cookie();
		add_action( 'woocommerce_set_cart_cookies', array( $this, 'set_customer_cart_cookie' ), 20 );
		add_action( 'shutdown', array( $this, 'save_cart' ), 20 );
		add_action( 'wp_logout', array( $this, 'destroy_session' ) );
	}
	
	public function maybe_update_nonce_user_logged_out( $uid, $action ) {
		if ( Automattic\WooCommerce\Utilities\StringUtil::starts_with( $action, 'woocommerce' ) ) {
			return $this->has_session() && $this->_customer_id ? $this->_customer_id : $uid;
		}

		return $uid;
	}
	
	public function destroy_session() {
		$this->delete_cart( $this->_customer_id );
		//$this->forget_session();
	}
	
	public function get_session_cookie() {
		$cookie_value = isset( $_COOKIE[ $this->_cookie ] ) ? wp_unslash( $_COOKIE[ $this->_cookie ] ) : false; // @codingStandardsIgnoreLine.

		if ( empty( $cookie_value ) || ! is_string( $cookie_value ) ) {
			return false;
		}

		list( $customer_id, $session_expiration, $session_expiring, $cookie_hash ) = explode( '||', $cookie_value );

		if ( empty( $customer_id ) ) {
			return false;
		}

		// Validate hash.
		$to_hash = $customer_id . '|' . $session_expiration;
		$hash    = hash_hmac( 'md5', $to_hash, wp_hash( $to_hash ) );

		if ( empty( $cookie_hash ) || ! hash_equals( $hash, $cookie_hash ) ) {
			return false;
		}

		return array( $customer_id, $session_expiration, $session_expiring, $cookie_hash );
	}
	
	public function get_session_data() {
		return $this->has_session() ? (array) $this->get_session( $this->_customer_id, array() ) : array();
	}
	
	public function get_session( $cart_key, $default_value = false ) {
		return $this->get_cart_data();
	}
	
	private function is_customer_guest( $customer_id ) {
		$customer_id = strval( $customer_id );

		if ( empty( $customer_id ) ) {
			return true;
		}

		if ( 't_' === substr( $customer_id, 0, 2 ) ) {
			return true;
		}

		/**
		 * Legacy checks. This is to handle sessions that were created from a previous release.
		 * Maybe we can get rid of them after a few releases.
		 */

		// Almost all random $customer_ids will have some letters in it, while all actual ids will be integers.
		if ( strval( (int) $customer_id ) !== $customer_id ) {
			return true;
		}

		// Performance hack to potentially save a DB query, when same user as $customer_id is logged in.
		if ( is_user_logged_in() && strval( get_current_user_id() ) === $customer_id ) {
			return false;
		} else {
			$customer = new WC_Customer( $customer_id );

			if ( 0 === $customer->get_id() ) {
				return true;
			}
		}

		return false;
	}
	
	private function is_cart_cookie_valid() {
		// If session is expired, session cookie is invalid.
		if ( time() > $this->_cart_expiration ) {
			//var_dump($this->_cart_expiration);
			return false;
		}

		// If user has logged out, session cookie is invalid.
		if ( ! is_user_logged_in() && ! $this->is_customer_guest( $this->_customer_id ) ) {
			//var_dump('Yaha2');
			return false;
		}

		// Session from a different user is not valid. (Although from a guest user will be valid)
		if ( is_user_logged_in() && ! $this->is_customer_guest( $this->_customer_id ) && strval( get_current_user_id() ) !== $this->_customer_id ) {
			//var_dump('Yaha3');
			return false;
		}

		return true;
	}
	
	public function get_requested_cart() {
		$cart_key = '';

		// Are we requesting via url parameter?
		if ( isset( $_REQUEST['cart_key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$cart_key = (string) trim( sanitize_key( wp_unslash( $_REQUEST['cart_key'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		// Are we requesting via custom header?
		if ( ! empty( $_SERVER['HTTP_CART_KEY'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$cart_key = (string) trim( sanitize_key( wp_unslash( $_SERVER['HTTP_CART_KEY'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		/**
		 * Filter allows the cart key to be overridden.
		 *
		 * Developer Note: Really only here so I don't have to create
		 * a new session handler to inject a customer ID with the POS Support Add-on.
		 *
		 * @since 4.2.0 Introduced.
		 *
		 * @ignore Function ignored when parsed into Code Reference.
		 */
		return apply_filters( 'cocart_requested_cart_key', $cart_key );
	}
	
	public function init_session_cookie() {
		// Current user ID. If user is NOT logged in then the customer is a guest.
		$current_user_id = 0;

		if ( is_user_logged_in() ) {
			$current_user_id = strval( get_current_user_id() );
		}

		$this->_customer_id = $this->get_requested_cart();

		// Get cart session requested.
		if ( ! empty( $this->_customer_id ) ) {
			// Get cart.
			$this->_data = $this->get_cart_data();
			
			// If the user logs in, just delete the guest cart, the cart sync is already happening at fl-login API.
			// Temporarily disabled, not sure how the guest cart will get deleted at the moment.
			/* if ( is_user_logged_in() && strval( get_current_user_id() ) !== $this->_customer_id ) {
				$guest_session_id   = $this->_customer_id;
				$this->delete_cart( $guest_session_id );
			} */

			// Update cart if its close to expiring.
			if ( time() > $this->_cart_expiring || empty( $this->_cart_expiring ) ) {
				$this->set_cart_expiration();
				$this->update_cart_timestamp( $this->_customer_id, $this->cart_expiration );
			}
		} else {
			// New cart session created or authenticated user.
			$this->set_cart_expiration();
			$this->_customer_id = 0 === $current_user_id ? $this->generate_customer_id() : $current_user_id;
			$this->_data        = $this->get_cart_data();
			$this->set_customer_cart_cookie( true );
		}
	}
	
	public function generate_customer_id() {
		$customer_id = '';

		if ( is_user_logged_in() ) {
			$customer_id = strval( get_current_user_id() );
		}

		if ( empty( $customer_id ) ) {
			require_once ABSPATH . 'wp-includes/class-phpass.php';
			$hasher      = new PasswordHash( 8, false );
			$customer_id = 't_' . substr( md5( $hasher->get_random_bytes( 32 ) ), 2 );
		}

		return $customer_id;
	}
	
	
	public function get_cart_data(){
		global $wpdb;
		$data = false;
		if( $this->has_session() ){
			$value = $wpdb->get_var( $wpdb->prepare( "SELECT session_value FROM $this->_table WHERE session_key = %s", $this->_customer_id ) );
			if ( !is_null( $value ) ) {
				$data = maybe_unserialize( $value );
			}
		}
		return $data;
	}
	
	public function get_cart_data_by_key( $key ) {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT session_value FROM {$this->_table} WHERE session_key = %s", $key ) );
		return $value ? maybe_unserialize( $value ) : array();
	}
	
	public function set_cart_expiration() {
		$this->_cart_expiring   = time() + intval( DAY_IN_SECONDS * 6 ); // 6 Days.
		$this->_cart_expiration = time() + intval( DAY_IN_SECONDS * 7 ); // 7 Days.
	}
	
	public function update_cart_timestamp( $cart_key, $timestamp ) {
		global $wpdb;

		$wpdb->update(
			$this->_table,
			array( 'session_expiry' => $timestamp ),
			array( 'session_key' => $cart_key ),
			array( '%d' ),
			array( '%s' )
		);
	} 
	
	public function has_session() {
		if ( isset( $_COOKIE[ $this->_cookie ] ) ) {
			return true;
		}

		// Current user ID. If value is above zero then user is logged in.
		$current_user_id = strval( get_current_user_id() );
		if ( is_numeric( $current_user_id ) && $current_user_id > 0 ) {
			return true;
		}

		if ( ! empty( $this->_customer_id ) ) {
			return true;
		}

		return false;
	}
	
	public function set_customer_cart_cookie( $set = true ) {
		if ( $set ) {
			$to_hash           = $this->_customer_id . '|' . $this->_cart_expiration;
			$cookie_hash       = hash_hmac( 'md5', $to_hash, wp_hash( $to_hash ) );
			$cookie_value      = $this->_customer_id . '||' . $this->_cart_expiration . '||' . $this->_cart_expiring . '||' . $cookie_hash;
			$this->_has_cookie = true;

			// If no cookie exists then create a new.
			if ( ! isset( $_COOKIE[ $this->_cookie ] ) || $_COOKIE[ $this->_cookie ] !== $cookie_value ) {
				$this->flcart_setcookie($this->_cookie, $cookie_value, $this->_cart_expiration, $this->use_secure_cookie(), $this->use_httponly());
			}
			/* if ( isset( $_COOKIE[ 'woocommerce_cart_hash' ] ) ) {
				$this->flcart_setcookie('woocommerce_cart_hash', $_COOKIE[ 'woocommerce_cart_hash' ], $this->_cart_expiration, $this->use_secure_cookie(), $this->use_httponly());
			}
			if ( isset( $_COOKIE[ 'woocommerce_items_in_cart' ] ) ) {
				$this->flcart_setcookie('woocommerce_items_in_cart', $_COOKIE[ 'woocommerce_items_in_cart' ], $this->_cart_expiration, $this->use_secure_cookie(), $this->use_httponly());
			} */
		} else {
			// If cookies exists, destroy it.
			if ( isset( $_COOKIE[ $this->_cookie ] ) ) {
				$this->flcart_setcookie( $this->_cookie, '', time() - YEAR_IN_SECONDS, $this->use_secure_cookie(), $this->use_httponly());
				unset( $_COOKIE[ $this->_cookie ] );
			}
		}
	}
	
	public function flcart_setcookie( $name, $value, $expire = 0, $secure = false, $httponly = false ) {
		if ( ! headers_sent() ) {
			// samesite - Set to None by default and only available to those using PHP 7.3 or above. @since 2.9.1.
			if ( version_compare( PHP_VERSION, '7.3.0', '>=' ) ) {
				setcookie( $name, $value, array( 'expires' => $expire, 'secure' => $secure, 'path' => COOKIEPATH ? COOKIEPATH : '/', 'domain' => COOKIE_DOMAIN, 'httponly' => $httponly, 'samesite' => 'None' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			} else {
				setcookie( $name, $value, $expire, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, $secure, $httponly );
			}
		} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			headers_sent( $file, $line );
			trigger_error( "{$name} cookie cannot be set - headers already sent by {$file} on line {$line}", E_USER_NOTICE ); // @codingStandardsIgnoreLine
		}
	}
	
	protected function use_secure_cookie() {
		return apply_filters( 'cocart_cart_use_secure_cookie', wc_site_is_https() && is_ssl() );
	} // END use_secure_cookie()

	protected function use_httponly() {
		$httponly = true;
		return $httponly;
	} // END use_httponly()
	
	public function save_data(){
		$this->save_cart();
	}

	public function save_cart( $old_cart_key = 0 ) {
		$this->counter++;
		if ( $this->has_session() ) {
			global $wpdb;
			
			$data = $this->_data;
			
			$cart = $wpdb->get_var( $wpdb->prepare( "SELECT session_value FROM $this->_table WHERE session_key = %s", $this->_customer_id ) );
			
			if ( ! empty( $data ) && empty( $cart ) ) {
				if ( ! isset( $data['cart'] ) || empty( maybe_unserialize( $data['cart'] ) ) ) {
					$data = false;
				}
			}

			$this->_data = $data;

			if ( ! $this->_data || empty( $this->_data ) || is_null( $this->_data ) ) {
				return true;
			}
			
			//$this->set_cart_hash();

			// Save or update cart data.
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO $this->_table (`session_key`, `session_value`, `session_expiry`) VALUES (%s, %s, %d)
 					ON DUPLICATE KEY UPDATE `session_value` = VALUES(`session_value`), `session_expiry` = VALUES(`session_expiry`)",
					$this->_customer_id,
					maybe_serialize( $this->_data ),
					$this->_cart_expiration
				)
			);
			if ( get_current_user_id() !== $old_cart_key && ! is_object( get_user_by( 'id', $old_cart_key ) ) ) {
				$this->delete_cart( $old_cart_key );
			}
		}
	}
	
	public function delete_cart( $cart_key ) {
		global $wpdb;
		$wpdb->delete( $this->_table, array( 'session_key' => $cart_key ), array( '%s' ) );
	}
	
	public function forget_session() {
		wc_setcookie( $this->_cookie, '', time() - YEAR_IN_SECONDS, $this->use_secure_cookie(), true );

		if ( ! is_admin() ) {
			include_once WC_ABSPATH . 'includes/wc-cart-functions.php';

			wc_empty_cart();
		}

		$this->_data        = array();
		$this->_dirty       = false;
		$this->_customer_id = $this->generate_customer_id();
	}


}