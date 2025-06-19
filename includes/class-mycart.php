<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;
final class My_Cart{
	
	public static function init(){
		self::setup_constants();
		add_filter( 'determine_current_user', array( __CLASS__, 'authenticate' ), 16 );
		add_filter( 'plugins_loaded', array( __CLASS__, 'mycart_load_hooks' ), 10 );
		add_filter( 'woocommerce_session_handler', array( __CLASS__, 'mycart_session_handler' ) );
		add_action( 'woocommerce_load_cart_from_session', array(  __CLASS__, 'load_cart_from_session' ), 0 );
		add_action( 'rest_api_init', array( __CLASS__, 'cst_allow_all_cors' ), 15 );
		add_action( 'init', array( __CLASS__, 'load_rest_api' ) );
		add_action( 'init', array( __CLASS__, 'my_cart_load_textdomain' ) );
	}
	
	public static function my_cart_load_textdomain() {
		load_plugin_textdomain('cart-rest-api-for-woocommerce', false, dirname(plugin_basename(__FILE__)) . '/languages');
	}
	
	 public static function cst_allow_all_cors() {
    	// If not enabled via filter then return.
    	/* if ( apply_filters( 'cocart_disable_all_cors', true ) ) {
    		return;
    	} */
    
    	// Remove the default cors server headers.
    	remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
    
    	// Adds new cors server headers.
    	add_filter( 'rest_pre_serve_request', array( __CLASS__, 'cst_cors_headers' ), 100, 4 );
    }
    
    public static function cst_cors_headers( $served, $result, $request, $server ) {

    	if ( strpos( $request->get_route(), 'fl-cart/' ) !== false || strpos( $request->get_route(), 'wc/' ) !== false ) {
    		$origin = get_http_origin();
    
    		// Requests from file:// and data: URLs send "Origin: null".
    		if ( 'null' !== $origin ) {
    			$origin = esc_url_raw( $origin );
    		}
    
    		$allow_headers = array(
    			'Authorization',
    			'X-Requested-With',
    			'Content-Disposition',
    			'Content-MD5',
    			'Content-Type',
    			'X-Wc-Store-Api-Nonce',
				'Cart-Token',
				'Nonce',
				'CKey',
    		);
    
    		$expose_headers = array(
				'X-WP-Total',
    			'X-WP-TotalPages',
    			'Link',
    			'Cart-Token',
				'Nonce',
				'X-Wc-Store-Api-Nonce',
				'CKey',
    		);
    
    		if ( method_exists( $server, 'send_header' ) ) {
    			$server->send_header( 'Access-Control-Allow-Origin', $_SERVER['HTTP_ORIGIN'] );
    			$server->send_header( 'Access-Control-Allow-Methods', 'OPTIONS, GET, POST, PUT, PATCH, DELETE' );
    			$server->send_header( 'Access-Control-Allow-Credentials', 'true' );
    			$server->send_header( 'Access-Control-Allow-Headers', implode( ', ', $allow_headers ) );
    			$server->send_header( 'Access-Control-Expose-Headers', implode( ', ', $expose_headers ) );
    		}
    	}
    
    	return $served;
    }
	
	public static function setup_constants() {
		define( 'My_CART_ABSPATH', dirname( My_CART_FILE ) . '/' );
	}
	
	public static function is_jwt_token_blacklisted($token) {
		return get_transient('blacklist_' . md5($token)) === 'blacklisted';
	}
	
	public static function perform_basic_authentication() {
		
		$auth_header = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : false;
		if ($auth_header) {
			list($token) = sscanf($auth_header, 'Bearer %s');
			if ($token) {
				
				if (self::is_jwt_token_blacklisted($token)) {
					return new WP_Error('invalid_token', __('Invalid token', 'text-domain'), array('status' => 403));
				}
				
				try {
					$secret_key = defined('JWT_AUTH_SECRET_KEY') ? JWT_AUTH_SECRET_KEY : 'F3Xm43?O~e]}>P6M1;R|eh{ kVYf^iC) PH82JLh%L!,eVPH/q D5@1CU+a<NdNQ';
					$decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
					if ($decoded && isset($decoded->data->user->id)) {
						return $decoded->data->user->id;
					}
				} catch (Exception $e) {
					return new WP_Error('invalid_token', __('Invalid token', 'text-domain'), array('status' => 403));
				}
			}
		}
			
			// Check that we're trying to authenticate via headers.
			if ( ! empty( $_SERVER['PHP_AUTH_USER'] ) && ! empty( $_SERVER['PHP_AUTH_PW'] ) ) {
				$username = trim( sanitize_user( $_SERVER['PHP_AUTH_USER'] ) );
				$password = trim( sanitize_text_field( $_SERVER['PHP_AUTH_PW'] ) );

				// Check if the username provided was an email address and get the username if true.
				if ( is_email( $_SERVER['PHP_AUTH_USER'] ) ) {
					$user     = get_user_by( 'email', $_SERVER['PHP_AUTH_USER'] );
					$username = $user->user_login;
				}
			} elseif ( ! empty( $_REQUEST['username'] ) && ! empty( $_REQUEST['password'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				// Fallback to check if the username and password was passed via URL.
				$username = trim( sanitize_user( $_REQUEST['username'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$password = trim( sanitize_text_field( $_REQUEST['password'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

				// Check if the username provided was an email address and get the username if true.
				if ( is_email( $_REQUEST['username'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$user     = get_user_by( 'email', $_REQUEST['username'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$username = $user->user_login; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				}
			}

			// Only authenticate if a username and password is available to check.
			if ( ! empty( $username ) && ! empty( $password ) ) {
				$user = wp_authenticate( $username, $password );
			} else {
				return false;
			}

			if ( is_wp_error( $user ) ) {
				return  new WP_Error( 'cocart_authentication_error', __( 'Authentication is invalid. Please check the authentication information is correct and try again. Authentication may also only work on a secure connection.', 'cart-rest-api-for-woocommerce' ), array( 'status' => 401 ) );
			}

			return $user->ID;
		}
	
	public static function authenticate( $user_id ) {
		// Do not authenticate twice.
		if ( ! empty( $user_id ) ) {
			return $user_id;
		}
		$user_id = self::perform_basic_authentication();

		return $user_id;
	} // END authenticate()
	
	public static function mycart_session_handler(){
		if ( class_exists( 'WC_Session' ) ) {
			include_once My_CART_ABSPATH . 'includes/abstracts/abstract-my-cart-session.php';
			require_once My_CART_ABSPATH . 'includes/class-my-cart-session-handler.php';
			$handler = 'My_Cart_Session_Handler';
		}

		return $handler;
	}
	
	public static function load_rest_api(){
		if ( is_null( WC()->cart ) && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		require_once My_CART_ABSPATH . 'includes/class-my-cart-rest-api.php';
	}
	
	public static function mycart_load_hooks(){
		require_once My_CART_ABSPATH . 'includes/hooks/class-my-cart-hooks.php';
	}
	
	public static function initialize_session() {
		$session_class = 'My_Cart_Session_Handler';
		
		if(!class_exists($session_class)){
			include_once My_CART_ABSPATH . 'includes/abstracts/abstract-my-cart-session.php';
			require_once My_CART_ABSPATH . 'includes/class-my-cart-session-handler.php';
		}

		if ( is_null( WC()->session ) || ! WC()->session instanceof $session_class ) {
			if ( false === strpos( $session_class, '\\' ) ) {
				$session_class = '\\' . $session_class;
			}
			WC()->session = new $session_class();
			WC()->session->init();
		}
	}
	
	public static function load_cart_from_session() {
		 if (defined('REST_REQUEST') && REST_REQUEST) {
			self::initialize_session();
			$cookie = WC()->session->get_session_cookie();

			$cart_key = '';

			// If cookie exists then return cart key from it.
			if ( $cookie ) {
				$cart_key = $cookie[0];
			}

			// Check if we requested to load a specific cart.
			if ( isset( $_REQUEST['cart_key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$cart_key = trim( esc_html( wp_unslash( $_REQUEST['cart_key'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			}

			// Check if the user is logged in.
			if ( is_user_logged_in() ) {
				$customer_id = strval( get_current_user_id() );

				// Compare the customer ID with the requested cart key. If they match then return error message.
				if ( isset( $_REQUEST['cart_key'] ) && $customer_id === $_REQUEST['cart_key'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$error = new WP_Error( 'cocart_already_authenticating_user', __( 'You are already authenticating as the customer. Cannot set cart key as the user.', 'cart-rest-api-for-woocommerce' ), array( 'status' => 403 ) );
					wp_send_json_error( $error, 403 );
					exit;
				}
			} else {
				$user = get_user_by( 'id', $cart_key );

				// If the user exists then return error message.
				if ( ! empty( $user ) ) {
					$error = new WP_Error( 'cocart_must_authenticate_user', __( 'Must authenticate customer as the cart key provided is a registered customer.', 'cart-rest-api-for-woocommerce' ), array( 'status' => 403 ) );
					wp_send_json_error( $error, 403 );
					exit;
				}
			}

			// Get requested cart.
			$cart = WC()->session->get_session( $cart_key );

			// Get current cart contents.
			$cart_contents = WC()->session->get( 'cart', array() );

			// Merge requested cart. - ONLY ITEMS, COUPONS AND FEES THAT ARE NOT APPLIED TO THE CART IN SESSION WILL MERGE!!!
			if ( ! empty( $cart_key ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$merge_cart = array();

				$applied_coupons       = WC()->session->get( 'applied_coupons', array() );
				$removed_cart_contents = WC()->session->get( 'removed_cart_contents', array() );
				$cart_fees             = WC()->session->get( 'cart_fees', array() );

				$merge_cart['cart']                  = isset( $cart['cart'] ) ? maybe_unserialize( $cart['cart'] ) : array();
				$merge_cart['applied_coupons']       = isset( $cart['applied_coupons'] ) ? maybe_unserialize( $cart['applied_coupons'] ) : array();
				$merge_cart['applied_coupons']       = array_unique( array_merge( $applied_coupons, $merge_cart['applied_coupons'] ) ); // Merge applied coupons.
				$merge_cart['removed_cart_contents'] = isset( $cart['removed_cart_contents'] ) ? maybe_unserialize( $cart['removed_cart_contents'] ) : array();
				$merge_cart['removed_cart_contents'] = array_merge( $removed_cart_contents, $merge_cart['removed_cart_contents'] ); // Merge removed cart contents.
				$merge_cart['cart_fees']             = isset( $cart['cart_fees'] ) ? maybe_unserialize( $cart['cart_fees'] ) : array();

				// Check cart fees return as an array so not to crash if PHP 8 or higher is used.
				if ( is_array( $merge_cart['cart_fees'] ) ) {
					$merge_cart['cart_fees'] = array_merge( $cart_fees, $merge_cart['cart_fees'] ); // Merge cart fees.
				}

				// Checking if there is cart content to merge.
				if ( ! empty( $merge_cart['cart'] ) ) {
					$cart_contents = array_merge( $merge_cart['cart'], $cart_contents ); // Merge carts.
				}
			}


			// Set cart for customer if not empty.
			if ( ! empty( $cart ) ) {
				WC()->session->set( 'cart', $cart_contents );
				WC()->session->set( 'cart_totals', maybe_unserialize( $cart['cart_totals'] ) );
				WC()->session->set( 'applied_coupons', ! empty( $merge_cart['applied_coupons'] ) ? $merge_cart['applied_coupons'] : maybe_unserialize( $cart['applied_coupons'] ) );
				WC()->session->set( 'coupon_discount_totals', maybe_unserialize( $cart['coupon_discount_totals'] ) );
				WC()->session->set( 'coupon_discount_tax_totals', maybe_unserialize( $cart['coupon_discount_tax_totals'] ) );
				WC()->session->set( 'removed_cart_contents', ! empty( $merge_cart['removed_cart_contents'] ) ? $merge_cart['removed_cart_contents'] : maybe_unserialize( $cart['removed_cart_contents'] ) );

				if ( ! empty( $cart['chosen_shipping_methods'] ) ) {
					WC()->session->set( 'chosen_shipping_methods', maybe_unserialize( $cart['chosen_shipping_methods'] ) );
				}

				if ( ! empty( $cart['cart_fees'] ) ) {
					WC()->session->set( 'cart_fees', ! empty( $merge_cart['cart_fees'] ) ? $merge_cart['cart_fees'] : maybe_unserialize( $cart['cart_fees'] ) );
				}
			}
		}
	}
	
}