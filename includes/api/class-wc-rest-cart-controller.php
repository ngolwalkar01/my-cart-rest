<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;
use Automattic\WooCommerce\StoreApi\Utilities\CartController;
use Automattic\WooCommerce\StoreApi\StoreApi;
use Automattic\WooCommerce\StoreApi\SchemaController;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;

require_once __DIR__ . '/../../vendor/autoload.php';

class WC_REST_Cart_Controller {

	protected $namespace = 'fl-cart/v1';

	private static $add_to_subscription_args = array();

	protected $rest_base = 'cart';
	
	

	public function register_routes() {
		// View Cart - fl-cart/v1/cart (GET)
		register_rest_route( $this->namespace, '/' . $this->rest_base, array(
			'methods'  => WP_REST_Server::READABLE,
			'callback' => array( $this, 'get_complete_cart_details' ),
			'args'     => array(
				'thumb' => array(
					'default' => null
				),
			),
		));

		// Count Items in Cart - fl-cart/v1/cart/count-items (GET)
		register_rest_route( $this->namespace, '/' . $this->rest_base  . '/count-items', array(
			'methods'  => WP_REST_Server::READABLE,
			'callback' => array( $this, 'get_cart_contents_count' ),
			'args'     => array(
				'return' => array(
					'default' => 'numeric'
				),
			),
		));

		// Get Cart Totals - fl-cart/v1/cart/totals (GET)
		register_rest_route( $this->namespace, '/' . $this->rest_base  . '/totals', array(
			'methods'  => WP_REST_Server::READABLE,
			'callback' => array( $this, 'get_totals' ),
		));

		// Clear Cart - fl-cart/v1/cart/clear (POST)
		register_rest_route( $this->namespace, '/' . $this->rest_base  . '/clear', array(
			'methods'  => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'clear_cart' ),
		));

		// Add Item - fl-cart/v1/cart/add (POST)
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/add', array(
			'methods'  => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'add_to_cart' ),
			'args'     => array(
				'product_id' => array(
					'validate_callback' => function( $param, $request, $key ) {
						return is_numeric( $param );
					}
				),
				'quantity' => array(
					'validate_callback' => function( $param, $request, $key ) {
						return is_numeric( $param );
					}
				),
				'variation_id' => array(
					'validate_callback' => function( $param, $request, $key ) {
						return is_numeric( $param );
					}
				),
				'variation' => array(
					'validate_callback' => function( $param, $request, $key ) {
						return is_array( $param );
					}
				),
				'cart_item_data' => array(
					'validate_callback' => function( $param, $request, $key ) {
						return is_array( $param );
					}
				),
				'subscription_scheme' => array( // New argument for subscription details
					'validate_callback' => function($param, $request, $key) {
						return is_string($param);
					}
				)
			)
		) );

		// Calculate Cart Total - fl-cart/v1/cart/calculate (POST)
		register_rest_route( $this->namespace, '/' . $this->rest_base  . '/calculate', array(
			'methods'  => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'calculate_totals' ),
		));

		// Update, Remove or Restore Item - fl-cart/v1/cart/cart-item (GET, POST, DELETE)
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/cart-item', array(
			'args' => array(
				'cart_item_key' => array(
					'description' => __( 'The cart item key is what identifies the item in the cart.', 'cart-rest-api-for-woocommerce' ),
					'type'        => 'string',
				),
			),
			array(
				'methods'  => WP_REST_Server::READABLE,
				'callback' => array( $this, 'restore_item' ),
			),
			array(
				'methods'  => WP_REST_Server::CREATABLE,
				'callback' => array( $this, 'update_item' ),
				'args'     => array(
					'quantity' => array(
						'default' => 1,
						'validate_callback' => function( $param, $request, $key ) {
							return is_numeric( $param );
						}
					),
				),
			)			
		) );
		
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/remove-cart-item', array(
			'args' => array(
				'cart_item_key' => array(
					'description' => __( 'The cart item key is what identifies the item in the cart.', 'cart-rest-api-for-woocommerce' ),
					'type'        => 'string',
				),
			),
			array(
				'methods'  => WP_REST_Server::CREATABLE,
				'callback' => array( $this, 'remove_item' ),
			)
		));
		
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/apply-coupon', array(
			'methods'  => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'apply_coupon' ),
			'args'     => array(
				'coupon_code' => array(
					'required' => true,
					'validate_callback' => function( $param, $request, $key ) {
						return is_string( $param );
					}
				)
			)
		));

		// Remove Coupon - fl-cart/v1/cart/remove-coupon (POST)
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/remove-coupon', array(
			'methods'  => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'remove_coupon' ),
			'args'     => array(
				'coupon_code' => array(
					'required' => true,
					'validate_callback' => function( $param, $request, $key ) {
						return is_string( $param );
					}
				)
			)
		));
		
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/shipping-methods', array(
			'methods'  => WP_REST_Server::READABLE,
			'callback' => array( $this, 'get_shipping_methods' ),
		));
		
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/set-shipping-method', array(
			'methods'  => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'set_shipping_method' ),
			'args'     => array(
				'shipping_method' => array(
					'required' => true,
					'validate_callback' => function( $param, $request, $key ) {
						return is_string( $param );
					}
				),
				'package_id' => array(
					'required' => true,
					'validate_callback' => function( $param, $request, $key ) {
						return is_string( $param );
					}
				)
			)
		));
		
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/create-order', array(
			'methods'  => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'add_order_from_cart' ),
			'args'     => array(
				'payment_method' => array(
					'required' => true,
					'validate_callback' => function( $param, $request, $key ) {
						return is_string( $param );
					}
				),
				'payment_method_title' => array(
					'required' => false,
					'validate_callback' => function( $param, $request, $key ) {
						return is_string( $param );
					}
				),
				'set_paid' => array(
					'required' => false,
					'validate_callback' => function( $param, $request, $key ) {
						return is_bool( $param );
					}
				),
				'meta_data' => array(
					'required' => false,
					'validate_callback' => function( $param, $request, $key ) {
						return is_array( $param );
					}
				),
				'custom_delivery_date' => array(
					'required' => false,
					'validate_callback' => function( $param, $request, $key ) {
						return is_string( $param );
					}
				),
			)
		));
		
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/set-cart-customer', array(
			'methods'  => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'set_cart_customer' ),
			'args'     => array(
				'email' => array(
					'required' => true,
					'validate_callback' => function( $param, $request, $key ) {
						return is_string( $param );
					}
				),
			)
		));
		
		
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/set-customer-details', array(
			'methods'  => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'set_customer_details' ),
			'args'     => array(
				'billing' => array(
					'required' => true,
					'validate_callback' => function( $param, $request, $key ) {
						return is_array( $param );
					}
				),
				'shipping' => array(
					'required' => true,
					'validate_callback' => function( $param, $request, $key ) {
						return is_array( $param );
					}
				),
				'personal' => array(
					'required' => false,
					'validate_callback' => function( $param, $request, $key ) {
						return is_array( $param );
					}
				),
			)
		));
		
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/payment-gateways', array(
            'methods'  => WP_REST_Server::READABLE,
            'callback' => array( $this, 'get_payment_gateways' ),
        ));
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/products/(?P<product_identifier>[a-zA-Z0-9-]+)/subscription-options', array(
			'methods'  => WP_REST_Server::READABLE,
			'callback' => array($this, 'get_subscription_options'),
			'args'     => array(
				'product_identifier' => array(
					'required' => true,
					'validate_callback' => function($param, $request, $key) {
						return is_numeric($param) || preg_match('/^[a-zA-Z0-9-]+$/', $param);
					}
				)
			)
		));
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/get_delivery_days', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array($this, 'update_delivery_days' ),
			'args' => array(
				'postcode' => array(
					'required' => true,
					'validate_callback' => function ($param, $request, $key) {
						return is_string($param);
					}
				),
				'lang' => array(
					'required' => true,
					'validate_callback' => function ($param, $request, $key) {
						return is_string($param);
					}
				),
				'localPickUp' => array(
					'required' => false,
					'validate_callback' => function ($param, $request, $key) {
						return is_bool($param);
					}
				)
			),
		));
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/customer_orders', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array( $this, 'get_customer_orders' ),
			'permission_callback' => function () {
				return current_user_can('read'); // Adjust permissions as needed
			}
		));
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/order/(?P<order_id>\d+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_order_details'),
            'permission_callback' => function () {
                return current_user_can('read');
            },
            'args' => array(
                'order_id' => array(
                    'required' => true,
                    'validate_callback' => function ($param, $request, $key) {
                        return is_numeric($param);
                    }
                )
            )
        ));
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/fl-login', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' =>  array( $this, 'handle_login' ),
			'args' => array(
				'username' => array(
					'required' => true,
					'validate_callback' => function ($param, $request, $key) {
						return is_string($param);
					}
				),
				'password' => array(
					'required' => true,
					'validate_callback' => function ($param, $request, $key) {
						return is_string($param);
					}
				)
			)
		));
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/fl-logout', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array($this, 'invalidate_jwt_token_endpoint'),
			'permission_callback' => function () {
				return is_user_logged_in();
			}
		));
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/update-subscription', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array($this, 'update_subscription'),
			'permission_callback' => function () {
                return current_user_can('read');
            }
		));
		
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/add-product-to-subscription', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array($this, 'add_product_to_subscription_endpoint'),
			'permission_callback' => function () {
                return current_user_can('read');
            }
		));
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/update-item-frequency', array(
			'methods' => WP_REST_Server::EDITABLE,
			'callback' => array($this, 'update_cart_item_frequency'),
			'permission_callback' => '__return_true',
			'args' => array(
				'cart_item_key' => array(
					'required' => true,
					'validate_callback' => function ($param, $request, $key) {
						return is_string($param);
					}
				),
				'subscription_scheme' => array(
					'required' => true,
					'validate_callback' => function ($param, $request, $key) {
						return is_string($param);
					}
				)
			)
		));
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/similar-products', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array($this, 'get_products_similar_to_subscription'),
			'permission_callback' => '__return_true',
			'args' => array(
				'subscription_id' => array(
					'required' => true,
					'validate_callback' => function ($param, $request, $key) {
						return is_numeric($param) && $param > 0;
					}
				)
			)
		));
		
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/klarna-update-woo-order', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array($this, 'handle_klarna_authorization_callback'),
			'permission_callback' => '__return_true',
			'args' => array(
				'success' => array(
					'required' => true,
					'validate_callback' => function ($param, $request, $key) {
						return is_bool($param);
					}
				),
				'order' => array(
					'required' => true,
					'validate_callback' => function( $param, $request, $key ) {
						return is_array( $param );
					}
				)
			)
		));
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/klarna-update-order-session', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array($this, 'update_order_klarna_session'),
			'permission_callback' => '__return_true',
			'args' => array(
				'order_id' => array(
					'required' => true,
					'validate_callback' => function ($param, $request, $key) {
						return is_numeric($param);
					}
				),
				'session_id' => array(
					'required' => true,
					'validate_callback' => function( $param, $request, $key ) {
						return is_string( $param );
					}
				)
			)
		));
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/restore-cart', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array($this, 'set_cart_session_by_order_id'),
			'permission_callback' => '__return_true',
			'args' => array(
				'order_id' => array(
					'required' => true,
					'validate_callback' => function ($param, $request, $key) {
						return is_numeric($param);
					}
				),
				'session_id' => array(
					'required' => true,
					'validate_callback' => function ($param, $request, $key) {
						return is_string($param);
					}
				)
			)
		));
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/multi-addresses', 
			array(
				'methods' => WP_REST_Server::READABLE,
				'callback' => array($this, 'get_user_addresses'),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			)
		);
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/add-multi-addresses', 
			array(
				'methods' => WP_REST_Server::CREATABLE,
				'callback' => array($this, 'add_user_addresses'),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
				'args' => array(
					'addresses' => array(
						'required' => true,
						'validate_callback' => function ($param, $request, $key) {
							return is_array($param);
						}
					),
				)
			)
		);
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/multi-addresses/set-main/(?P<index>\d+)', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'set_main_shipping_address' ),
			'permission_callback' => function () {
				return is_user_logged_in();
			},
		));
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/download-invoice/(?P<order_id>\d+)', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array($this, 'generate_pdf_invoice_link'),
			'permission_callback' => function () {
				return is_user_logged_in();
			},
		));
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/customer-addresses', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array($this,'get_customer_addresses'),
			'permission_callback' => function () {
				return is_user_logged_in();
			},
		));
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/customer-profile', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array($this, 'update_customer_profile'),
			'permission_callback' => function () {
				return is_user_logged_in();
			},
			'args' => array(
				'customer_info' => array(
					'required' => true,
					'validate_callback' => function ($param, $request, $key) {
						return is_array($param);
					}
				),
			)
		));
		
		 register_rest_route($this->namespace, '/' . $this->rest_base . '/customer-profile', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array($this, 'get_customer_profile'),
			'permission_callback' => function () {
				return is_user_logged_in();
			},
		));
		
		 register_rest_route($this->namespace, '/' . $this->rest_base . '/order-confirmation/(?P<order_id>\d+)', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array($this, 'get_order_details'),
			'permission_callback' => function ($request) {
				return is_user_logged_in();
			},
			'args' => array(
				'order_id' => array(
					'required' => true,
					'validate_callback' => function ($param, $request, $key) {
						return is_numeric($param);
					}
				),
			)
		));
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/get-order-dates/(?P<order_id>\d+)', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array($this, 'cst_get_order_dates_api'),
			'permission_callback' => '__return_true',
			'args' => array(
				'order_id' => array(
					'required' => true,
					'validate_callback' => function ($param, $request, $key) {
						return is_numeric($param);
					}
				),
			)
		));
		
		register_rest_route($this->namespace, '/' . $this->rest_base .'/sso-login', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array($this, 'handle_google_fb_login'),
			'permission_callback' => '__return_true',
		));
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/search-products', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array($this, 'cst_search_woocommerce_products'),
			'permission_callback' => '__return_true',
			'args' => array(
				'search' => array(
					'required' => true,
					'validate_callback' => function ($param, $request, $key) {
						return is_string($param);
					}
				)
			)
		));
		
		 register_rest_route($this->namespace, '/' . $this->rest_base . '/favorite-product', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array($this, 'add_favorite_product'),
			'permission_callback' => function () {
				return is_user_logged_in();
			},
			'args' => array(
				'product_id' => array(
					'required' => true,
					'validate_callback' => function ($param, $request, $key) {
						return is_numeric($param);
					}
				)
			)
		));

		register_rest_route($this->namespace, '/' . $this->rest_base . '/favorites', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array($this, 'get_favorite_products'),
			'permission_callback' => function () {
				return is_user_logged_in();
			}
		));
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/get-product-categories', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array($this, 'get_product_categories'),
			'permission_callback' => '__return_true'
		));
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/get-product-categories-names', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array($this, 'get_product_categories_names'),
			'permission_callback' => '__return_true'
		));
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/get-product-categories-slug', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array($this, 'get_product_categories_slug'),
			'permission_callback' => '__return_true'
		));
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/products-by-category', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array($this, 'get_products_by_category'),
			'permission_callback' => '__return_true',
			'args' => array(
				'category' => array(
					'required' => true,
					'validate_callback' => function ($param, $request, $key) {
						return is_string($param);
					}
				)
			)
		));
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/vip-page/(?P<id>\d+)', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array($this, 'get_vip_page'),
			'permission_callback' => '__return_true',
			'args' => array(
				'id' => array(
					'required' => true,
					'validate_callback' => function ($param, $request, $key) {
						return is_numeric($param);
					}
				)
			)
		));
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/invalidate_token', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array($this, 'invalidate_jwt_token_endpoint'),
			'permission_callback' => function () {
				return is_user_logged_in();
			}
		));
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/order-received/(?P<order_id>\d+)', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array($this, 'get_received_order_details'),
			'permission_callback' => '__return_true',
			'args' => array(
				'order_id' => array(
					'required' => true,
					'validate_callback' => function ($param, $request, $key) {
						return is_numeric($param);
					}
				),
				'order_key' => array(
					'required' => true,
					'validate_callback' => function ($param, $request, $key) {
						return is_string($param);
					}
				)
			)
		));
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/forgot-password', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array($this, 'handle_forgot_password'),
			'permission_callback' => '__return_true',
			'args' => array(
				'email' => array(
					'required' => true,
					'validate_callback' => function ($param, $request, $key) {
						return is_email($param);
					}
				)
			)
		));
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/reset-password', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array($this, 'handle_reset_password'),
			'permission_callback' => '__return_true',
			'args' => array(
				'key' => array(
					'required' => true,
					'validate_callback' => function ($param, $request, $key) {
						return !empty($param);
					}
				),
				'login' => array(
					'required' => true,
					'validate_callback' => function ($param, $request, $key) {
						return !empty($param);
					}
				),
				'password' => array(
					'required' => true,
					'validate_callback' => function ($param, $request, $key) {
						return strlen($param) >= 6;
					}
				),
				'confirm_password' => array(
					'required' => true,
					'validate_callback' => function ($param, $request, $key) {
						return strlen($param) >= 6;
					}
				),
			)
		));
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/check-zip/', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array($this, 'check_zip_code_availability'),
			'permission_callback' => '__return_true',
			'args' => array(
				'postalcode' => array(
					'required' => true,
					'validate_callback' => function ($param, $request, $key) {
						return is_string($param);
					}
				)
			)
		));
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/nets-easy/confirm-order', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array($this, 'handle_nets_easy_order_confirmation'),
			'permission_callback' => '__return_true',
			'args' => array(
				'easy_confirm' => array(
					'required' => true,
					'validate_callback' => function ($param) {
						return is_string($param) && !empty($param);
					}
				),
				'paymentid' => array(
					'required' => true,
					'validate_callback' => function ($param) {
						return is_string($param) && !empty($param);
					}
				),
				'key' => array(
					'required' => true,
					'validate_callback' => function ($param) {
						return is_string($param) && !empty($param);
					}
				),
			),
		));
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/stripe/confirm-order', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array($this, 'handle_stripe_order_confirmation'),
			'permission_callback' => '__return_true',
			'args' => array(
				'session_id' => array(
					'required' => true,
					'validate_callback' => function ($param) {
						return is_string($param) && !empty($param);
					}
				),
				'key' => array(
					'required' => true,
					'validate_callback' => function ($param) {
						return is_string($param) && !empty($param);
					}
				),
			),
		));
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/pickup-stores', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array($this, 'get_pickup_stores'),
			'permission_callback' => '__return_true',
		));
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/customer_subscriptions', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array($this, 'get_customer_subscriptions'),
			'args' => array(
				'customer_id' => array(
					'required' => true,
					'validate_callback' => function( $param, $request, $key ) {
                return is_numeric($param);
            },
				),
				'page' => array(
					'required' => false,
					'default' => 1,
					'validate_callback' => function( $param, $request, $key ) {
                return is_numeric($param);
            },
				),
				'per_page' => array(
					'required' => false,
					'default' => 10,
					'validate_callback' => function( $param, $request, $key ) {
                return is_numeric($param);
            },
				),
			),
			'permission_callback' => function () {
				return current_user_can('read'); // Or use JWT token verification if headless
			},
		));
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/update-subscription-shipping-rates', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array($this, 'update_subscription_shipping_rates'),
			'permission_callback' => '__return_true',
			'args' => array(
				'orderId' => array(
					'required' => true,
					'validate_callback' => function( $param, $request, $key ) {
                return is_numeric($param);
            },
				),
				'extensionsData' => array(
					'required' => true,
				),
			),
		));
		
		register_rest_route($this->namespace, '/' . $this->rest_base . '/combined-home-data', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array($this, 'get_combined_home_data'),
			'permission_callback' => '__return_true',
			'args' => array(
				'per_page' => array(
					'required' => false,
					'validate_callback' => function($param) { return is_numeric($param); },
					'default' => -1,
				),
			)
		));
		

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/reset-cart', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array($this, 'reset_cart_api_callback'),
			// This makes the endpoint public. For a real site, you might want
			// to add more specific permission checks.
			'permission_callback' => '__return_true', 
		) );


		
		
	} // register_routes()
	

	/**
	 * The callback function for our custom cart reset API endpoint.
	 * This is now tailored to use your specific session handler.
	 *
	 * @param WP_REST_Request $request The incoming API request.
	 * @return WP_REST_Response
	 */
	public function reset_cart_api_callback( WP_REST_Request $request ) {

		// STEP 1: Get the instance of YOUR running session handler.
		// -----------------------------------------------------------
		// IMPORTANT: Replace this with the actual way to get your handler object.
		// Choose ONE of the examples below that matches your project.

		// Example A (if using a singleton pattern):
		// $handler = My_Main_Plugin_Class::instance()->session_handler;

		// Example B (if using a global variable):
		$session_handler = WC()->session;
		
		$old_customer_id = $session_handler->get_customer_id();

		// Fail safely if the handler isn't found.
		if ( ! is_object( $session_handler ) || ! method_exists( $session_handler, 'init_session_cookie' ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Session handler not available.' ), 500 );
		}

		// STEP 2: Initialize your session for this API request.
		// -----------------------------------------------------------
		// This calls your own method to load the cookie and session data
		// so we know which user/cart we are operating on.
		$session_handler->init_session_cookie();

		if ( ! empty( $old_customer_id ) ) {
			$session_handler->delete_cart( $old_customer_id );
		}
		
		$session_handler->set_customer_cart_cookie( false );

		// STEP 3: Perform the full cart clearing action.
		// -----------------------------------------------------------
		// The `true` parameter is critical. It clears all cart-related session data.
		if ( function_exists('WC') && WC()->cart ) {
			WC()->cart->empty_cart( true );
			WC()->cart->calculate_totals();
		} else {
			 return new WP_REST_Response( array( 'success' => false, 'message' => 'WooCommerce Cart not available.' ), 500 );
		}
		
		$session_handler->init_session_cookie();

		// STEP 4: Manually save the now-empty session using YOUR handler's method.
		// -----------------------------------------------------------
		// This ensures the clean data is written to the database immediately.
		$session_handler->save_cart();


		// STEP 5: Return a success response with the clean cart data.
		// -----------------------------------------------------------
		$response_data = array(
			'success' => true,
			'message' => 'Cart has been reset successfully.',
			'cart'    => WC()->cart->get_cart_for_session(), // Get a clean array of the new cart state.
		);

		return new WP_REST_Response( $response_data, 200 );
	}
	
	public function get_combined_home_data($request) {
		$response = array();

		// Reuse existing logic
		$response['product_categories'] = $this->get_product_categories($request)->get_data();
		$response['cutoff_day'] = $this->get_next_cuttof_day_combined($request)->get_data();
		$response['vip_pages'] = $this->get_vip_pages_combined($request)->get_data();
		$response['get_categories_with_products'] = $this->get_categories_with_products_combined($request)->get_data();

		return new WP_REST_Response($response, 200);
	}
	
	public function get_next_cuttof_day_combined($request) {

		$timezone = wp_timezone_string();

		$cutoffday = date("Y-m-d");

		if (in_array('freshland-cycles/freshland-cycles.php', apply_filters('active_plugins', get_option('active_plugins')))) {

			if(class_exists('Freshland_Cycles_Admin')){

			   $freshland_admin = new Freshland_Cycles_Admin( 'Freshland Cycles', '1.0.8' );

			   $cutoffday = $freshland_admin->get_next_cutoff_day();

			}

		}
		
		$cutoff_date = new DateTime($cutoffday, new DateTimeZone($timezone));
		$cutoff_date->modify('+1 day');
		$cutoffday_plus_one = $cutoff_date->format('Y-m-d');

		$response = array(

			'timezone' => $timezone,

			'cutoffday' => $cutoffday_plus_one

		);

		return new WP_REST_Response($response, 200);

    }
	
	public function get_categories_with_products_combined($request) {
		
		$is_logged_in = is_user_logged_in();
		$user_id = $is_logged_in ? get_current_user_id() : 'guest';
		
		$cache_key = "headless:categories_products:user:$user_id";
		$cache_ttl = $is_logged_in ? 300 : HOUR_IN_SECONDS; // 5 min for users, 1 hour for guest
		
		$data = array();
		
		// ✅ Try Redis
		if (class_exists('Redis')) {
			try {
				$redis = new Redis();
				$redis->connect('10.75.96.14', 41960, 2);
				$cached = $redis->get($cache_key);

				if ($cached) {
					return new WP_REST_Response(json_decode($cached, true), 200);
				}
			} catch (Exception $e) {
				error_log("Redis error (read): " . $e->getMessage());
			}
		}
		
		// 💡 Get favorites only for logged-in users
		$favorite_products = $is_logged_in ? get_user_meta(get_current_user_id(), 'cst_favorite_products', true) : array();
		
		// Fetch categories and products if no cache is found
		$term_order = array(18, 20, 112, 318, 212, 332);
		$categories = get_terms('product_cat', array(
			'hide_empty' => false,
			'include'    => $term_order,
			'orderby'    => 'include',
			'exclude'    => array(15),
			'meta_query' => array(
				array(
					'key' => 'is_store_category',
					'value' => '1',
					'compare' => '='
				)
			)
		));

		foreach ($categories as $category) {
			$thumbnail_id = get_term_meta($category->term_id, 'thumbnail_id', true);
			$category_thumbnail_url = '';
			if ($thumbnail_id) {
				$thumbnail_data = wp_get_attachment_image_src($thumbnail_id, 'thumbnail');
				if (!empty($thumbnail_data) && isset($thumbnail_data[0])) {
					$category_thumbnail_url = $thumbnail_data[0];
				}
			}

			$args = array(
				'post_type' => 'product',
				'posts_per_page' => -1,
				'post_status' => 'publish',
				'tax_query' => array(
					array(
						'taxonomy' => 'product_cat',
						'field' => 'term_id',
						'terms' => $category->term_id,
					),
				),
				'meta_query' => array(
					array(
						'key' => '_stock_status',
						'value' => 'instock',
						'compare' => '='
					)
				),
				'orderby' => 'meta_value_num',
				'meta_key' => 'total_sales',
				'order' => 'DESC',
			);

			$query = new WP_Query($args);
			$products = array();

			while ($query->have_posts()) {
				$query->the_post();
				global $product;
				$thumbnail_id = get_post_thumbnail_id($product->get_id());
				$thumbnail_url = '';
				if ($thumbnail_id) {
					$thumbnail_data = wp_get_attachment_image_src($thumbnail_id, 'full');
					if (!empty($thumbnail_data) && isset($thumbnail_data[0])) {
						$thumbnail_url = $thumbnail_data[0];
					}
				}

				$price = wc_format_decimal($product->get_price(), 0) . ' ' . get_woocommerce_currency_symbol();
				$weight = get_post_meta($product->get_id(), 'cst_product_weight', true);
				$price = $weight ? $price ."  •  ". $weight : $price;
				$quantity = $product->get_stock_quantity();

				$organic = get_post_meta(get_the_ID(), 'is_organic_product', true);
				$is_favorite = is_array($favorite_products) ? in_array(get_the_ID(), $favorite_products) : false;
				$post_date = strtotime($product->get_date_created());
				$is_new = has_term('new', 'product_tag', $product->get_id());

				$products[] = array(
					'id' => get_the_ID(),
					'name' => html_entity_decode(removeBracketsContent(get_the_title())),
					'slug' => $product->get_slug(),
					'price' => $price,
					'permalink' => get_the_permalink(),
					'thumbnail' => $thumbnail_url,
					'quantity' => $quantity,
					'is_organic' => !empty($organic) ? (bool) $organic : false,
					'is_favorite' => $is_favorite,
					'is_new' => $is_new,
					'visibility' => $product->get_catalog_visibility(),
				);
			}

			wp_reset_postdata();

			$data[] = array(
				'category' => array(
					'id' => $category->term_id,
					'name' => html_entity_decode($category->name),
					'description' => $category->description,
					'thumbnail' => $category_thumbnail_url,
				),
				'products' => $products,
			);
		}

		// ✅ Cache in Redis
		if (isset($redis)) {
			try {
				$redis->setex($cache_key, $cache_ttl, json_encode($data));
			} catch (Exception $e) {
				error_log("Redis error (write): " . $e->getMessage());
			}
		}

		return new WP_REST_Response($data, 200);
    }

	

	public function get_vip_pages_combined($request) {

		$args = array(

			'post_type' => 'page', // Query pages only

			'meta_query' => array(

				array(

					'key' => 'is_vip_page',

					'value' => '1',

					'compare' => '='

				),

			),

			'posts_per_page' => $request['per_page'], // Number of VIP pages to fetch

			'orderby' => 'meta_value_num', // Order by numeric meta value

			'meta_key' => 'vip_order', // Use the 'vip_order' custom field for ordering

			'order' => 'ASC', // Order in ascending order

		);

		

		$vip_pages_query = new WP_Query($args);

		$vip_pages = array();



		if ($vip_pages_query->have_posts()) {

			while ($vip_pages_query->have_posts()) {

				$vip_pages_query->the_post();

				$post_id = get_the_ID();

				$thumbnail_id = get_post_thumbnail_id($post_id);

				$thumbnail_url = wp_get_attachment_image_url($thumbnail_id, 'full'); // Get the full size of the thumbnail



				$vip_pages[] = array(

					'id' => $post_id,

					'title' => get_the_title(),

					'permalink' => get_the_permalink(),

					'order' => get_post_meta($post_id, 'vip_order', true), // Fetch the order meta value

					'thumbnail' => $thumbnail_url, // Add the thumbnail URL

				);

			}

			wp_reset_postdata();

		}



		return new WP_REST_Response($vip_pages, 200);

	}
	
	public function update_subscription_shipping_rates($request) {
		$order_id = absint($request->get_param('orderId'));
		$extensions_data = $request->get_param('extensionsData');

		if (!wc_get_order($order_id)) {
			return new WP_Error(
				'invalid_order',
				__('Invalid order ID.', 'your-text-domain'),
				array('status' => 400)
			);
		}

		if (!isset($extensions_data['subscriptions']) || !is_array($extensions_data['subscriptions'])) {
			return new WP_Error(
				'invalid_data',
				__('Invalid or missing subscriptions data.', 'your-text-domain'),
				array('status' => 400)
			);
		}

		$subscriptions = wcs_get_subscriptions_for_order($order_id, array('order_type' => 'any'));
		$updated = [];

		foreach ($subscriptions as $subscription) {
			$sub_id = $subscription->get_id();

			$billing_period = get_post_meta($sub_id, '_billing_period', true);
			$billing_interval = get_post_meta($sub_id, '_billing_interval', true);
			$next_payment = get_post_meta($sub_id, '_schedule_next_payment', true);

			if (!$next_payment || !$billing_period || !$billing_interval) {
				continue;
			}

			$date = date('Y_m_d', strtotime($next_payment));
			$interval_suffixes = [
				1 => 'weekly',
				2 => 'every_2nd_' . $billing_period,
				3 => 'every_3rd_' . $billing_period,
				4 => 'every_4th_' . $billing_period,
			];

			$suffix = isset($interval_suffixes[$billing_interval])
				? $interval_suffixes[$billing_interval]
				: 'every_' . $billing_interval . 'th_' . $billing_period;

			$subscription_key = $date . '_' . $suffix;

			// Try to find the matching subscription in the extensionsData payload
			foreach ($extensions_data['subscriptions'] as $sub_data) {
				if ($sub_data['key'] !== $subscription_key) {
					continue;
				}

				if (!isset($sub_data['shipping_rates'][0]['shipping_rates'])) {
					continue;
				}

				foreach ($sub_data['shipping_rates'][0]['shipping_rates'] as $rate) {
					if (!empty($rate['selected'])) {
						$shipping_rate_array = array(
							'id' => sanitize_text_field($rate['rate_id']),
							'method_id' => sanitize_text_field($rate['method_id']),
							'instance_id' => intval($rate['instance_id']),
							'label' => sanitize_text_field($rate['name']),
							'cost' => number_format( $rate['price'] / 100, 2, '.', '' ),
							'taxes' => array_map(
									fn($tax) => number_format( $tax / 100, 2, '.', '' ),
									is_array($rate['taxes']) ? $rate['taxes'] : [$rate['taxes']]
								),
						);

						update_post_meta($sub_id, 'cst_shipping_rate_obj', $shipping_rate_array);
						$updated[] = $sub_id;
						break;
					}
				}
			}
		}

		return rest_ensure_response(array(
			'success' => true,
			'message' => 'Shipping rates updated for subscriptions.',
			'updated_subscriptions' => $updated,
		));
	}
	
	public function get_customer_subscriptions($request) {
		$user_id  = intval($request['customer_id']);
		$page     = intval($request['page']);
		$per_page = intval($request['per_page']);

		$args = array(
			'post_type'      => 'shop_subscription',
			'post_status'    => 'any',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'meta_query'     => array(
				array(
					'key'   => '_customer_user',
					'value' => $user_id,
				),
			),
		);

		$query = new WP_Query($args);
		$subscriptions = array();

		foreach ($query->posts as $post) {
			$post_id = $post->ID;

			// Extract meta data needed
			$meta_data = get_post_meta($post_id);
			
			 $subscription = wcs_get_subscription( $post_id );
			 $latest_renewal_date = null;
			 
			// Step 1: Get all renewal orders for the subscription
			$last_order = $subscription->get_last_order( 'renewal' );
			
			if ( $last_order && $last_order instanceof WC_Order ) {
				$renewal_delivery_date_raw = $last_order->get_meta( 'delivery_date' );

				if ( $renewal_delivery_date_raw ) {
					$renewal_delivery_date = new DateTime( $renewal_delivery_date_raw );

					// Compare with current time
					$now = new DateTime( 'now', new DateTimeZone('Europe/Copenhagen') );
					if ( $renewal_delivery_date > $now ) {
						$latest_renewal_date = $renewal_delivery_date->format('Y-m-d\TH:i:s');
					}
				}
			}
			 
			 $next_payment  = $subscription->get_date( 'next_payment', 'Europe/Copenhagen' );
		
		$next_payment_ = ( $next_payment ? new DateTime( $next_payment ) : '' );
		$next_payment_ = ( $next_payment_ ? $next_payment_->format( 'Y-m-d\TH:i:s' ) : '' );
		$selected_date 	= sanitize_text_field( $next_payment_ );
		$date_available = $this->get_delivery_date_by_selected_date($selected_date);
		$next_delivery_date = $latest_renewal_date ?: ( $post->post_status == "wc-active" ? date_format( $date_available['date_available'], 'Y-m-d\TH:i:s' ) : "-" );
		
		$structured_meta = array(
				array(
					'key'   => 'delivery_date',
					'value' => $next_delivery_date,
				),
				// Add more keys only if needed
			);

			$subscriptions[] = array(
				'id'            => $post_id,
				'number'        => get_post_meta($post_id, '_order_number', true),
				'date_created'  => get_post_time('Y-m-d H:i:s', true, $post),
				'status'        => $post->post_status,
				'currency'      => get_post_meta($post_id, '_order_currency', true),
				'total'         => get_post_meta($post_id, '_order_total', true),
				'meta_data'     => $structured_meta,
			);
		}

		return rest_ensure_response(array(
			'data'        => $subscriptions,
			'total'       => (int) $query->found_posts,
			'totalPages'  => (int) $query->max_num_pages,
			'currentPage' => $page,
		));
	}
	
	public function get_delivery_date_by_selected_date($selected_date){
		$postal_code 	= ( WC()->customer->get_postcode() ? WC()->customer->get_postcode() : false) ;
		$date 			= new DateTime( $selected_date );
		$admin_class    = new Freshland_Cycles_Admin( '', '' );
		$delivery_dates = $admin_class->get_delivery_dates( $postal_code, 4, $date->format( 'Y-m-d' ) );
		switch_to_locale( get_locale() ); // Not working as expected with WordPress settings
		$dayofweek 		= date_i18n( 'l', strtotime( $delivery_dates[0] ) );
		$date_available = new DateTime( $delivery_dates[0] );
		return array(
			"dayofweek" => $dayofweek,
			"date_available" => $date_available,
		);
	}

	
	public function get_pickup_stores( $request ) {
		// Retrieve stores via the wc-pickup-store helper function.
		$stores = $this->cst_store_get_store_admin();
		
		return rest_ensure_response( $stores );
	}
	
	public function cst_store_get_store_admin( $args = array() ) {
		  $args = array(
        'post_type'      => 'store',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
    );
    
    $store_posts = get_posts( $args );
    $stores = array();

    if ( $store_posts ) {
        foreach ( $store_posts as $post ) {
            $store_title = isset( $post->post_title ) ? $post->post_title : '';
            // Here we build an indexed array of objects
            $stores[] = array(
                'id'   => $post->ID,
                'name' => $store_title,
            );
        }
    }
    
    return $stores;
	}
	
	public function handle_stripe_order_confirmation( $request ) {
		$session_id = sanitize_text_field($request['session_id']);
		$order_key = sanitize_text_field($request['key']);
		
		if (empty($session_id) || empty($order_key)) {
			return new WP_REST_Response(['status' => 'error', 'message' => 'Missing parameters'], 400);
		}

		// Get the WooCommerce order using the order key
		$order_id = wc_get_order_id_by_order_key($order_key);
		$order = wc_get_order($order_id);

		if (!$order) {
			return new WP_REST_Response(['status' => 'error', 'message' => 'Order not found'], 404);
		}

		// Retrieve stored Stripe checkout session ID from order meta
		$stored_session_id = $order->get_meta('_stripe_checkout_session_id', true);

		if (!$stored_session_id) {
			return new WP_REST_Response(['status' => 'error', 'message' => 'Session ID not found in order meta'], 400);
		}

		// Compare the received session_id with the stored session ID
		if ($session_id === $stored_session_id) {
			// Update order status to "processing"
			$order->update_status('processing', __('Payment verified via Stripe', 'woocommerce'));
			
			return new WP_REST_Response(['status' => 'success', 'message' => 'Payment verified and order updated'], 200);
		}

		return new WP_REST_Response(['status' => 'error', 'message' => 'Session ID mismatch'], 400);
	}
		
		
	public function handle_nets_easy_order_confirmation( $request ) {
		try{
			$easy_confirm = sanitize_text_field($request['easy_confirm']);
			$payment_id = sanitize_text_field($request['paymentid']);
			$order_key = sanitize_text_field($request['key']);

			if (!class_exists('Nets_Easy_Confirmation')) {
				return new WP_Error('nets_easy_class_missing', __('Nets_Easy_Confirmation class not found.', 'text-domain'), array('status' => 500));
			}

			// Handle redirection-based order confirmation
			if (!empty($payment_id)) {
				Nets_Easy_Logger::log( $payment_id . '. Customer redirected back to checkout. Checking payment status.' );

				$request = Nets_Easy()->api->get_nets_easy_order( $payment_id );

				if ( is_wp_error( $request ) ) {
					 return new WP_REST_Response(array(
						'success' => false,
						'message' => __('Failed to fetch payment status.', 'text-domain'),
						'error'   => $request->get_error_message(),
					), 400);
				}

				if ( isset( $request['payment']['summary']['reservedAmount'] ) || isset( $request['payment']['summary']['chargedAmount'] ) || isset( $request['payment']['subscription']['id'] ) ) {

					$order = nets_easy_get_order_by_purchase_id( $payment_id );

					if ( ! is_object( $order ) ) {
						return new WP_REST_Response(array(
							'success' => false,
							'message' => __('Order not found for the provided payment ID.', 'text-domain'),
						), 404);
					}

					Nets_Easy_Logger::log( $payment_id . '. Customer redirected back to checkout. Payment created. Order ID ' . $order->get_id() );
					
					$order->add_order_note( __( 'Customer redirected back to checkout. Payment created. Order ID ' . $order->get_id(), 'dibs-easy-for-woocommerce' ) );

					if ( empty( $order->get_date_paid() ) ) {

						Nets_Easy_Logger::log( $payment_id . '. Order ID ' . $order->get_id() . '. Confirming the order.' );
						$order->add_order_note( __( 'Order ID ' . $order->get_id().'. Confirming the order.', 'dibs-easy-for-woocommerce' ) );
						// Confirm the order.
						wc_dibs_confirm_dibs_order( $order->get_id() );
						wc_dibs_unset_sessions();
						 return new WP_REST_Response(array(
							'success' => true,
							'message' => __('Order confirmed successfully.', 'text-domain'),
							'order_id' => $order->get_id(),
							'redirect_url' => $order->get_checkout_order_received_url(),
						), 200);

					} else {
						Nets_Easy_Logger::log( $payment_id . '. Order ID ' . $order->get_id() . '. Order already confirmed.' );
						return new WP_REST_Response(array(
							'success' => true,
							'message' => __('Order already confirmed.', 'text-domain'),
							'order_id' => $order->get_id(),
						), 200);
					}
				} else {
					Nets_Easy_Logger::log( $payment_id . '. Customer redirected back to checkout. Payment status is NOT paid.' );
					return new WP_REST_Response(array(
						'success' => false,
						'message' => __('Payment status is not completed or valid.', 'text-domain'),
					), 400);
				}
			}else{
				return new WP_REST_Response(array(
						'success' => false,
						'message' => __('Payment status is not completed or valid.', 'text-domain'),
					), 400);
			}
		}catch(Exception $e){
			return new WP_Error(
				'invalid_request',
				__('Invalid request parameters. Provide either "easy_confirm" and "key", or "paymentId".', 'text-domain'),
				array('status' => 400)
			);
		}
	}


	
	public function check_zip_code_availability($request) {
		$postalcode = sanitize_text_field($request['postalcode']);

		global $wpdb;
		$postcode_locations = $wpdb->get_results("SELECT zone_id, location_code FROM {$wpdb->prefix}woocommerce_shipping_zone_locations WHERE location_type = 'postcode';");
		$postcode = wc_normalize_postcode($postalcode);

		// Handle wildcard cases for Netherlands (NL)
		$wc_countries = new \WC_Countries();
		$countries = array_keys($wc_countries->get_allowed_countries());
		if (in_array('NL', $countries)) {
			$postcode = substr($postcode, 0, 4) . '*';
		}

		$pc_status = false;
		$message = "Sign up for our waiting list, HERE then we will notify you when we deliver to your area.";
		foreach ($postcode_locations as $item) {
			if (is_numeric($postcode) && strpos($item->location_code, '...') !== false) {
				$pc = explode('...', $item->location_code);
				if ((min($pc) <= $postcode) && ($postcode <= max($pc))) {
					$pc_status = true;
				}
			} else {
				if ($item->location_code === $postcode) {
					$pc_status = true;
				}
			}
		}
		
		if($pc_status){
			$message = 'Vi levererar till ditt postnummer.';
		}
		return rest_ensure_response(array('status' => $pc_status, 'message' => $message));
	}
	
	public function handle_reset_password(WP_REST_Request $request) {
		$key = sanitize_text_field($request['key']);
		$login = sanitize_text_field($request['login']);
		$password = $request['password'];
		$confirm_password = $request['confirm_password'];

		// Check if password and confirm_password are the same
		if ($password !== $confirm_password) {
			return new WP_Error('password_mismatch', 'Passwords do not match.', array('status' => 400));
		}

		// Retrieve user data
		$user = check_password_reset_key($key, $login);

		if (is_wp_error($user)) {
			return new WP_Error('invalid_key', 'Invalid key or user login.', array('status' => 400));
		}

		// Validate the password reset key
		if (!isset($user->ID)) {
			return new WP_Error('invalid_user', 'Invalid user.', array('status' => 400));
		}

		// Set the new password
		reset_password($user, $password);

		return rest_ensure_response(array('message' => 'Password has been reset successfully.'));
	}
	
	public function handle_forgot_password(WP_REST_Request $request) {
		$email = sanitize_email($request['email']);
		$user = get_user_by('email', $email);

		if (!$user) {
			return new WP_Error('no_user',  __('Ingen användare hittades med denna e-postadress.', 'woocommerce'), array('status' => 404));
		}

		// Generate a password reset key and link
		$reset_key = get_password_reset_key($user);
		if (is_wp_error($reset_key)) {
			return new WP_Error('reset_key_error', 'Error generating reset key.', array('status' => 500));
		}

		$frontend_url = 'https://fresh.land/dk'; // Replace with your actual frontend URL
		$reset_url = $frontend_url . "/reset-password?key=$reset_key&login=" . rawurlencode($user->user_login);
		
		   // Retrieve the user's first name
		$first_name = get_user_meta($user->ID, 'first_name', true);
		
		$first_name = !empty($first_name) && false != $first_name ? $first_name : $user->user_login;

		// Use WooCommerce email template
		$mailer = WC()->mailer();
		$mails = $mailer->get_emails();
		$subject = __('Anmodning om nulstilling af adgangskode for Fresh.Land DK');
		$email_heading = __('Forespørgsel på nulstilling af adgangskode');
		$message = sprintf(__('Hej %s,'), $first_name) . "\n\n";
		$message .= __('Nogen (måske dig selv) har anmodet om at adgangskoden nulstilles for følgende konto:') . "\n\n";
		$message .= sprintf(__('Brugernavn : %s,'), $email) . "\n\n";
		$message .= __('Hvis dette er en fejl, kan du blot ignorere denne e-mail og intet vil ske.') . "\n\n";
		$message .= __('Du kan nulstille din adgangskode på følgende side:') . "\n\n";
		$message .= '<a href="' . $reset_url . '">' . $reset_url . '</a>' . "\n\n";
		$message .= __('Vänliga hälsningar,') . "\n";
		$message .= __('Fresh.Land DK Team');

		// Use WooCommerce email class to format and send the email
		$mailer->send($email, $subject, $mailer->wrap_message($email_heading, $message));
		
		return rest_ensure_response(array('message' => 'Password reset link sent.'));
	}
	
	// Customize the password reset email message
	public static function retrieve_password_message($user, $reset_key, $reset_url) {
		// Use the default WordPress function to generate the message
		$message = wp_password_change_notification($user, false);

		// Insert your reset URL
		$message .= __('To reset your password, visit the following address:') . "\r\n\r\n";
		$message .= '<a href="' . $reset_url . '">' . $reset_url . '</a>' . "\r\n";

		return $message;
	}
	
	public function get_received_order_details( $request ) {
		$order_id = intval($request['order_id']);
		$order_dates = $this->cst_get_order_dates($order_id);
		$order_key = sanitize_text_field($request['order_key']);
		$order = wc_get_order($order_id);

		if (!$order) {
			return new WP_Error('no_order', 'Order not found', array('status' => 404));
		}
		
		 // Check if the provided order key matches the order's key
		if ($order->get_order_key() !== $order_key) {
			return new WP_Error('invalid_key', 'Invalid order key.', array('status' => 403));
		}

		// Helper function to format prices
		function format_price($price) {
			return intval(round($price * 100));
		}

		// Get order items
		$items = array();
		$ites_subtotals_tax = 0;
		foreach ($order->get_items() as $item_id => $item) {
			$product = $item->get_product();
			$product_id = $product ? $product->get_id() : 0;
			
			$ites_subtotals_tax  += format_price($item->get_subtotal_tax());
			$items[] = array(
				'id' => $item_id,
				'quantity' => $item->get_quantity(),
				'quantity_limits' => array(
					'minimum' => $item->get_quantity(),
					'maximum' => $item->get_quantity(),
					'multiple_of' => 1,
					'editable' => false,
				),
				'name' => html_entity_decode($item->get_name()),
				'short_description' => $product ? $product->get_short_description() : '',
				'description' => $product ? $product->get_description() : '',
				'sku' => $product ? $product->get_sku() : '',
				'permalink' => $product ? get_permalink($product_id) : '',
				'images' => $product ? self::get_cst_product_images($product_id) : array(),
				'totals' => array(
					'line_subtotal' => format_price($item->get_subtotal()),
					'line_subtotal_tax' => format_price($item->get_subtotal_tax()),
					'line_total' => format_price($item->get_total()),
					'line_total_tax' => format_price($item->get_total_tax()),
					'currency_code' => get_woocommerce_currency(),
					'currency_symbol' => get_woocommerce_currency_symbol(),
					'currency_minor_unit' => 2,
					'currency_decimal_separator' => wc_get_price_decimal_separator(),
					'currency_thousand_separator' => wc_get_price_thousand_separator(),
					'currency_prefix' => '',
					'currency_suffix' => ' kr',
				),
				'catalog_visibility' => $product ? $product->get_catalog_visibility() : '',
			);
		}

		// Get shipping address
		$shipping_address = array(
			'first_name' => $order->get_shipping_first_name(),
			'last_name' => $order->get_shipping_last_name(),
			'company' => $order->get_shipping_company(),
			'address_1' => $order->get_shipping_address_1(),
			'address_2' => $order->get_shipping_address_2(),
			'city' => $order->get_shipping_city(),
			'state' => $order->get_shipping_state(),
			'postcode' => $order->get_shipping_postcode(),
			'country' => $order->get_shipping_country(),
			'phone' => $order->get_billing_phone(), // WooCommerce doesn't store shipping phone number
		);

		// Get billing address
		$billing_address = array(
			'first_name' => $order->get_billing_first_name(),
			'last_name' => $order->get_billing_last_name(),
			'company' => $order->get_billing_company(),
			'address_1' => $order->get_billing_address_1(),
			'address_2' => $order->get_billing_address_2(),
			'city' => $order->get_billing_city(),
			'state' => $order->get_billing_state(),
			'postcode' => $order->get_billing_postcode(),
			'country' => $order->get_billing_country(),
			'email' => $order->get_billing_email(),
			'phone' => $order->get_billing_phone(),
		);

		// Get order totals
		$totals = array(
			'subtotal' => format_price($order->get_subtotal()),
			'total_discount' => format_price($order->get_total_discount()),
			'total_shipping' => format_price($order->get_shipping_total()),
			'total_fees' => format_price($order->get_total_fees()),
			'total_tax' => format_price($order->get_total_tax()),
			'total_refund' => format_price($order->get_total_refunded()),
			'total_price' => format_price($order->get_total()),
			'total_items' => format_price($order->get_subtotal()),
			'total_items_tax' => $ites_subtotals_tax,
			'total_fees_tax' => 0, // Modify if you have specific logic for fees tax
			'total_discount_tax' => format_price($order->get_total_discount( false ) - $order->get_total_discount()) , // Modify if you have specific logic for discount tax
			'total_shipping_tax' => format_price($order->get_shipping_tax()),
			'tax_lines' => array_map(function($tax) {
				return array(
					'name' => $tax->get_label(),
					'price' => format_price($tax->get_tax_total()),
					'rate' => $tax->get_rate_percent(),
				);
			}, $order->get_taxes()),
			'currency_code' => get_woocommerce_currency(),
			'currency_symbol' => get_woocommerce_currency_symbol(),
			'currency_minor_unit' => 2,
			'currency_decimal_separator' => wc_get_price_decimal_separator(),
			'currency_thousand_separator' => wc_get_price_thousand_separator(),
			'currency_prefix' => '',
			'currency_suffix' => ' kr',
		);

		// Prepare the response
		$response = array(
			'id' => $order_id,
			'status' => $order->get_status(),
			'items' => $items,
			'coupons' => array(), // Add coupon data if needed
			'fees' => array(), // Add fee data if needed
			'totals' => $totals,
			'shipping_address' => $shipping_address,
			'billing_address' => $billing_address,
			'needs_payment' => $order->needs_payment(),
			'needs_shipping' => $order->needs_shipping_address(),
			'payment_requirements' => array('products'), // Modify if you have specific requirements
			'errors' => array(), // Add error data if needed
			'order_dates' => $order_dates, // Add error data if needed
		);

		return rest_ensure_response($response);
	}

	public static function get_cst_product_images($product_id) {
		$images = array();
		$attachment_ids = get_post_meta($product_id, '_product_image_gallery', true);
		$attachment_ids = explode(',', $attachment_ids);

		foreach ($attachment_ids as $attachment_id) {
			$src = wp_get_attachment_url($attachment_id);
			if ($src) {
				$images[] = array(
					'id' => $attachment_id,
					'src' => $src,
					'thumbnail' => wp_get_attachment_image_url($attachment_id, 'thumbnail'),
					'srcset' => wp_get_attachment_image_srcset($attachment_id),
					'sizes' => wp_get_attachment_image_sizes($attachment_id),
					'name' => get_the_title($attachment_id),
					'alt' => get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
				);
			}
		}

		return $images;
	}
	
	public function invalidate_jwt_token_endpoint( $request ) {
		$auth_header = $request->get_header('Authorization');
		if (empty($auth_header)) {
			return new WP_REST_Response(array('status' => 'error', 'message' => 'Authorization header missing'), 403);
		}
		$token = false;
		// Extract the token from the 'Bearer <token>' format
		if (preg_match('/Bearer\s(\S+)/', $auth_header, $matches)) {
			$token = $matches[1];
		} else {
			return new WP_REST_Response(array('status' => 'error', 'message' => 'Invalid authorization header format'), 403);
		}
		if(!$token){
			return new WP_REST_Response(array('status' => 'error', 'message' => 'Invalid token'), 403);
		}
		$decoded = JWT::decode($token,  new Key(JWT_AUTH_SECRET_KEY, 'HS256'));
		$user_id_from_token = $decoded->data->user->id;

		if (get_current_user_id() !== $user_id_from_token) {
			return new WP_REST_Response(array('status' => 'error', 'message' => 'Invalid token for this user'), 403);
		}

		self::blacklist_jwt_token($token);

		return new WP_REST_Response(array('status' => 'success'), 200);
	}
	
	
	public static function blacklist_jwt_token($token) {
		// Calculate the remaining time until the token would normally expire
		$decoded = JWT::decode($token,  new Key(JWT_AUTH_SECRET_KEY, 'HS256'));
		$exp = $decoded->exp;
		$time_left = $exp - time();

		// Store the token in the transient with the remaining time as expiration
		set_transient('blacklist_' . md5($token), 'blacklisted', $time_left);
	}
	
	
	function get_vip_page( $request ) {
		$page_id = intval($request['id']);

		// Check if the page exists and is marked as a VIP page
		if (get_post_status($page_id) !== 'publish' || get_post_type($page_id) !== 'page') {
			return new WP_Error('no_page', 'Page not found or not a valid page', array('status' => 404));
		}

		$is_vip_page = get_post_meta($page_id, 'is_vip_page', true);
		if ($is_vip_page !== '1') {
			return new WP_Error('not_vip', 'This page is not a VIP page', array('status' => 403));
		}

		// Get the page content, featured image, and klaviyo_list_id
		$page = get_post($page_id);
		$featured_image_url = '';
		$featured_image_id = get_post_thumbnail_id($page_id);
		if ($featured_image_id) {
			$featured_image_data = wp_get_attachment_image_src($featured_image_id, 'full');
			if (!empty($featured_image_data) && isset($featured_image_data[0])) {
				$featured_image_url = $featured_image_data[0];
			}
		}

		$response = array(
			'id' => $page_id,
			'title' => get_the_title($page_id),
			'content' => apply_filters('the_content', $page->post_content),
			'featured_image' => $featured_image_url,
			'klaviyo_list_id' => get_post_meta($page_id, 'klaviyo_list_id', true),
			'vip_order' => get_post_meta($page_id, 'vip_order', true),
		);

		return rest_ensure_response($response);
	}
	
	public function get_products_by_category( $request ) {
		$category_name = sanitize_text_field($request['category']);
		
		//var_dump($category_name);
		
		// Fetch category by name
		$category = get_term_by('slug', $category_name, 'product_cat');
		
		//var_dump($category);

		if (!$category) {
			return new WP_Error('no_category', 'Category not found', array('status' => 404));
		}

		$args = array(
			'post_type' => 'product',
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'tax_query' => array(
				array(
					'taxonomy' => 'product_cat',
					'field' => 'term_id',
					'terms' => $category->term_id,
				)
			),
			'meta_query' => array(
				array(
					'key' => '_stock_status',
					'value' => 'instock',
					'compare' => '='
				)
			),
			'orderby' => 'meta_value_num',
			'meta_key' => 'total_sales',
			'order' => 'DESC',
		);

		$query = new WP_Query($args);
		$products = array();
		
		$user_id = get_current_user_id();
		
		$favorite_products = get_user_meta($user_id, 'cst_favorite_products', true);
		if (empty($favorite_products)) {
			$favorite_products = array();
		}

		if ($query->have_posts()) {
			while ($query->have_posts()) {
				$query->the_post();
				global $product;

				$thumbnail_url = '';
				$thumbnail_id = get_post_thumbnail_id($product->get_id());
				if ($thumbnail_id) {
					$thumbnail_data = wp_get_attachment_image_src($thumbnail_id, 'full');
					if (!empty($thumbnail_data) && isset($thumbnail_data[0])) {
						$thumbnail_url = $thumbnail_data[0];
					}
				}
				
				$organic = get_post_meta($product->get_id(), 'is_organic_product', true);
				$is_favorite = in_array($product->get_id(), $favorite_products);

				// Check if the product has the "new" tag
				$is_new = has_term('new', 'product_tag', $product->get_id());

				$products[] = array(
					'id' => $product->get_id(),
					'name' => html_entity_decode(removeBracketsContent($product->get_name())),
					'slug' => $product->get_slug(),
					'price' => wc_format_decimal($product->get_price(), 0) . ' ' . get_woocommerce_currency_symbol(),
					'permalink' => $product->get_permalink(),
					'thumbnail' => $thumbnail_url,
					'sku' => $product->get_sku(),
					'stock_status' => $product->get_stock_status(),
					'is_favorite' => $is_favorite,
					'is_new' => $is_new,
					'is_organic' => !empty($organic) ? (bool) $organic : false,
				);
			}
			wp_reset_postdata();
		}

		return rest_ensure_response($products);
	}
	
	public function get_product_categories($request) {
		$default_image_url = get_site_url() . '/wp-content/uploads/2024/07/default-fallback-image.png';

		$cache_key = 'headless:home_categories';
		$cache_ttl = 600; // 10 minutes

		// ✅ Connect to Redis
		if (class_exists('Redis')) {
			try {
				$redis = new Redis();
				$redis->connect('10.75.96.14', 41960, 2);
				
				// ✅ Try to get from cache
				$cached = $redis->get($cache_key);
				if ($cached) {
					return rest_ensure_response(json_decode($cached, true));
				}
			} catch (Exception $e) {
				error_log('Redis error (read): ' . $e->getMessage());
			}
		}

		// ❌ Cache miss or Redis not available – proceed normally
		$args = array(
			'taxonomy' => 'product_cat',
			'hide_empty' => false,
			'number' => 4,
			'meta_query' => array(
				array(
					'key' => 'is_home_category',
					'value' => '1',
					'compare' => '='
				)
			)
		);

		$product_categories = get_terms($args);
		$categories = array();

		if (!empty($product_categories) && !is_wp_error($product_categories)) {
			foreach ($product_categories as $category) {
				$thumbnail_id = get_term_meta($category->term_id, 'thumbnail_id', true);
				$image_url = wp_get_attachment_url($thumbnail_id);

				$categories[] = array(
					'id' => $category->term_id,
					'name' => html_entity_decode($category->name),
					'slug' => $category->slug,
					'description' => $category->description,
					'count' => $category->count,
					'image' => $image_url ? $image_url : $default_image_url,
				);
			}
		}

		// ✅ Save to Redis
		if (isset($redis)) {
			try {
				$redis->setex($cache_key, $cache_ttl, json_encode($categories));
			} catch (Exception $e) {
				error_log('Redis error (write): ' . $e->getMessage());
			}
		}

		return rest_ensure_response($categories);
	}

	
	public function get_product_categories_names( $request ) {
		$term_order = array(18, 20, 112, 318, 212, 332);
		$args = array(
			'taxonomy' => 'product_cat',
			'include'    => $term_order, // Specify the terms to include in the order you want
			'orderby'    => 'include', 
			'exclude' => array(15),
			'hide_empty' => false,
			'meta_query' => array(
				array(
					'key' => 'is_store_category',
					'value' => '1',
					'compare' => '='
				)
			)
		);

		$product_categories = get_terms($args);
		$categories = array();

		if (!empty($product_categories) && !is_wp_error($product_categories)) {
			foreach ($product_categories as $category) {
				$categories[$category->slug] = html_entity_decode($category->name);
			}
		}

		return rest_ensure_response($categories);
	}
	
	
	public function get_product_categories_slug( $request ) {
		$term_order = array(18, 20, 112, 318, 212, 332);
		$args = array(
			'taxonomy' => 'product_cat',
			'include'    => $term_order, // Specify the terms to include in the order you want
			'orderby'    => 'include', 
			'exclude' => array(15),
			'hide_empty' => false,
			'meta_query' => array(
				array(
					'key' => 'is_store_category',
					'value' => '1',
					'compare' => '='
				)
			)
		);

		$product_categories = get_terms($args);
		$categories = array();

		if (!empty($product_categories) && !is_wp_error($product_categories)) {
			foreach ($product_categories as $category) {
				$categories[] = html_entity_decode($category->slug);
			}
		}

		return rest_ensure_response($categories);
	}
	
	
	public function add_favorite_product( $request ) {
		$user_id = get_current_user_id();
		$product_id = intval($request['product_id']);

		// Get existing favorite products
		$favorite_products = get_user_meta($user_id, 'cst_favorite_products', true);
		if (empty($favorite_products)) {
			$favorite_products = array();
		}

		// Add or remove the product ID from the favorites array
		if (!in_array($product_id, $favorite_products)) {
			$favorite_products[] = $product_id;
			$message = 'Product added to favorites';
		} else {
			$favorite_products = array_diff($favorite_products, array($product_id));
			$message = 'Product removed from favorites';
		}

		// Update the user meta with the new favorites array
		update_user_meta($user_id, 'cst_favorite_products', $favorite_products);

		return rest_ensure_response(array(
			'status' => 'success',
			'message' => $message,
			'favorite_products' => $favorite_products
		));
	}
	
	public function get_favorite_products( $request ) {
		$user_id = get_current_user_id();

		// Get existing favorite products
		$favorite_products = get_user_meta($user_id, 'cst_favorite_products', true);
		if (empty($favorite_products)) {
			$favorite_products = array();
		}

		if (!empty($favorite_products)) {
			$args = array(
				'post_type' => 'product',
				'post_status' => 'publish',
				'posts_per_page' => -1,
				'post__in' => $favorite_products,
			);

			$query = new WP_Query($args);
			$products = array();
			
			$start_of_week = strtotime('monday this week');

			if ($query->have_posts()) {
				while ($query->have_posts()) {
					$query->the_post();
					global $product;

					$thumbnail_url = '';
					$thumbnail_id = get_post_thumbnail_id($product->get_id());
					if ($thumbnail_id) {
						$thumbnail_data = wp_get_attachment_image_src($thumbnail_id, 'full');
						if (!empty($thumbnail_data) && isset($thumbnail_data[0])) {
							$thumbnail_url = $thumbnail_data[0];
						}
					}
					
					$organic = get_post_meta($product->get_id(), 'is_organic_product', true);
                    $is_favorite = in_array($product->get_id(), $favorite_products);

                    // Check if the product is new this week
                    $post_date = strtotime($product->get_date_created());
                    // Check if the product has the "new" tag
					$is_new = has_term('new', 'product_tag', $product->get_id());
					
					$price = wc_format_decimal($product->get_price(), 0) . ' ' . get_woocommerce_currency_symbol();
					$weight = get_post_meta($product->get_id(), 'cst_product_weight', true);
					$price = $weight ? $price ."  •  ". $weight : $price;

					$products[] = array(
						'id' => $product->get_id(),
						'name' => html_entity_decode(removeBracketsContent($product->get_name())),
						'slug' => $product->get_slug(),
						'price' => $price,
						'permalink' => $product->get_permalink(),
						'thumbnail' => $thumbnail_url,
						'sku' => $product->get_sku(),
						'stock_status' => $product->get_stock_status(),
						'is_favorite' => $is_favorite,
						'is_new' => $is_new,
						'is_organic' => !empty($organic) ? (bool) $organic : false,
					);
				}
				wp_reset_postdata();
			}
		} else {
			$products = array();
		}

		return rest_ensure_response($products);
	}
	
	public static function search_by_title_only($search, $wp_query) {
        global $wpdb;
        if (empty($search)) {
            return $search;
        }
        $q = $wp_query->query_vars;
        $n = !empty($q['exact']) ? '' : '%';
        $search = $searchand = '';
        foreach ((array)$q['search_terms'] as $term) {
            $term = esc_sql($wpdb->esc_like($term));
            $search .= "{$searchand}($wpdb->posts.post_title LIKE '{$n}{$term}{$n}')";
            $searchand = ' AND ';
        }
        if (!empty($search)) {
            $search = " AND ({$search}) ";
            if (!is_user_logged_in()) {
                $search .= " AND ($wpdb->posts.post_password = '') ";
            }
        }
        return $search;
    }
	
	public function cst_search_woocommerce_products($request) {
		$keyword = sanitize_text_field($request['search']);
		$output = array();
		$results = array();

		// Search products
		if ('yes' === get_option('woocommerce_hide_out_of_stock_items')) {
			$tax_query = array(
				'relation' => 'AND',
				array(
					'taxonomy' => 'product_visibility',
					'field'    => 'name',
					'terms'    => 'exclude-from-search',
					'operator' => 'NOT IN',
				),
				array(
					'taxonomy' => 'product_visibility',
					'field'    => 'name',
					'terms'    => 'outofstock',
					'operator' => 'NOT IN',
				),
			);
		} else {
			$tax_query = array(
				array(
					'taxonomy' => 'product_visibility',
					'field'    => 'name',
					'terms'    => 'exclude-from-search',
					'operator' => 'NOT IN',
				),
			);
		}

		$args = array(
			'post_type'           => 'product',
			'posts_per_page'      => 8,
			'post_status'         => 'publish',
			'ignore_sticky_posts' => 1,
			'suppress_filters'    => false,
			'tax_query'           => $tax_query,
			's'                   => $keyword,
		);

		// Modify the query to search titles only
		add_filter('posts_search', array( __CLASS__, 'search_by_title_only' ), 10, 2);

		$query = new WP_Query($args);
		remove_filter('posts_search', array( __CLASS__, 'search_by_title_only' ), 10);

		$output['suggestions'] = array();

		if ($query->have_posts()) {
			while ($query->have_posts()) {
				$query->the_post();
				global $product;

				$thumbnail_id = get_post_thumbnail_id($product->get_id());
				$thumbnail_url = '';
				if ($thumbnail_id) {
					$thumbnail_data = wp_get_attachment_image_src($thumbnail_id, 'full');
					if (!empty($thumbnail_data) && isset($thumbnail_data[0])) {
						$thumbnail_url = $thumbnail_data[0];
					}
				}

				$output['suggestions'][] = array(
					'id'        => $product->get_id(),
					'name'      => html_entity_decode(removeBracketsContent($product->get_name())),
					'slug'      => $product->get_slug(),
					'price'     => $product->get_price(),
					'permalink' => get_the_permalink(),
					'thumbnail' => $thumbnail_url,
					'quantity'  => $product->get_stock_quantity(),
				);
			}
			wp_reset_postdata();
		}

		return rest_ensure_response($output);
	}

	public static function custom_get_search_output($products) {
		$output = array();
		$user_id = get_current_user_id();
		
		$favorite_products = get_user_meta($user_id, 'cst_favorite_products', true);
		if (empty($favorite_products)) {
			$favorite_products = array();
		}


		foreach ($products as $post) {
			setup_postdata($post);
			$product = wc_get_product($post->ID);

			$thumbnail_url = '';
			$thumbnail_id = get_post_thumbnail_id($product->get_id());
			if ($thumbnail_id) {
				$thumbnail_data = wp_get_attachment_image_src($thumbnail_id, 'full');
				if (!empty($thumbnail_data) && isset($thumbnail_data[0])) {
					$thumbnail_url = $thumbnail_data[0];
				}
			}
			
			$organic = get_post_meta($post->ID, 'is_organic_product', true);
			$is_favorite = in_array($post->ID, $favorite_products);

			// Check if the product has the "new" tag
			$is_new = has_term('new', 'product_tag', $post->ID);

			$output[] = array(
				'id'        => $product->get_id(),
				'name'      => html_entity_decode(removeBracketsContent($product->get_name())),
				'slug'      => $product->get_slug(),
				'price'     => $product->get_price_html(),
				'permalink' => $product->get_permalink(),
				'thumbnail' => $thumbnail_url,
				'sku'       => $product->get_sku(),
				'stock_status' => $product->get_stock_status(),
				'is_favorite' => $is_favorite,
				'is_new' => $is_new,
				'is_organic' => !empty($organic) ? (bool) $organic : false,
			);
		}

		wp_reset_postdata();
		return $output;
	}
	
	public function handle_google_fb_login( $request ) {

		if (!isset($request['id_token'])) {
			return new WP_Error('missing_id_token', 'Missing ID token', array('status' => 400));
		}
		
		$google = false;
		$facebook = false;

		$id_token = $request['id_token'];
		
		if( $request['type'] == 'google' ){
			$google = true;
			// Verify the ID token with Google
			$client_id = '846264821168-fiei6p7fggtiqcpp7lb82827co92degv.apps.googleusercontent.com';
			$client = new Google_Client(['client_id' => $client_id]);
			$payload = $client->verifyIdToken($id_token);

			if (!$payload) {
				return new WP_Error('invalid_id_token', 'Invalid ID token', array('status' => 400));
			}

			$google_id = $payload['sub'];
			$email = $payload['email'];
			$name = $payload['name'];
			$picture = $payload['picture'];
			
		} else if( $request['type'] == 'facebook' ){
			$facebook = true;
			$url = "https://graph.facebook.com/me?fields=id,name,email&access_token={$id_token}";
			$response = wp_remote_get($url);
			
			
			if (is_wp_error($response)) {
				return new WP_Error('invalid_id_token', 'Invalid ID token', array('status' => 400));
			}

			$body = wp_remote_retrieve_body($response);
			$data = json_decode($body);

			if (isset($data->error)) {
				return new WP_Error('invalid_id_token', 'Invalid ID token', array('status' => 400));
			}

			$facebook_id = $data->id;
			$email = $data->email;
			$name = $data->name;
		}
		// Check if the user already exists
		$user = get_user_by('email', $email);

		if (!$user) {
			// Create a new user if not exists
			$user_id = wp_create_user($email, wp_generate_password(), $email);

			if (is_wp_error($user_id)) {
				return $user_id;
			}

			// Update user meta with additional information
			wp_update_user(array(
				'ID' => $user_id,
				'display_name' => $name,
			));
			if($google){
				update_user_meta($user_id, 'google_id', $google_id);
				update_user_meta($user_id, 'google_picture', $picture);
			}else if($facebook){
				update_user_meta($user_id, 'facebook_id', $facebook_id);
			}

			// Set the user role
			$user = get_user_by('id', $user_id);
			$user->set_role('customer');
		}

		// Log the user in
		wp_set_current_user($user->ID);
		wp_set_auth_cookie($user->ID);
		
		if(!self::cst_user_has_role( $user, 'administrator')){
			$user->set_role('customer');
		}

		// Generate JWT
        $token = array(
            'iss' => get_bloginfo('url'),
            'iat' => time(),
            'exp' => time() + 3600, // Token expires in 1 hour
            'data' => array(
                'user' => array(
                    'id' => $user->ID,
                ),
            ),
        );
		
		wc_update_new_customer_past_orders( $user->ID );

        $jwt = JWT::encode($token, JWT_AUTH_SECRET_KEY, 'HS256');

        return array(
            'status' => 'success',
            'user' => array(
                'ID' => $user->ID,
                'token' => $jwt,
				'user_email' => $user->user_email,
            ),
        );
	}
	
	public static function cst_user_has_role( $user, $role ) {
		
        if( ! $user || ! $user->roles ){
            return false;
        }

        if( is_array( $role ) ){
            return array_intersect( $role, (array) $user->roles ) ? true : false;
        }

        return in_array( $role, (array) $user->roles );
    }
	
	public function cst_get_order_dates_api( $request ) {
		
		$order_id = $request['order_id'];
		
		if (!$order_id || empty($order_id)) {
			return array(); // Return empty array if order is not found
		}
		
		// Get the order object
		$order = wc_get_order($order_id);

		if (!$order) {
			return array(); // Return empty array if order is not found
		}

		// Get the shipping or billing postcode from the order
		$postcode = $order->get_shipping_postcode() ? $order->get_shipping_postcode() : $order->get_billing_postcode();
		$order_date = $order->get_date_created()->date('Y-m-d');
		
		$delivery_date_meta = get_post_meta($order_id, 'delivery_date', true);

		$delivery_dates = array();
		
		if(empty($delivery_date_meta)){
			return array(
				'order_date' => $order_date,
				'delivery_dates' => $delivery_dates,
				'delivery_date_raw' => $delivery_date_meta
			); 
		}
		
		$week_name_translation = array(
			'Mon'    => __('Monday', 'freshland-cycles'),
			'Tue'    => __('Tuesday', 'freshland-cycles'),
			'Wed'    => __('Wednesday', 'freshland-cycles'),
			'Thu'    => __('Thursday', 'freshland-cycles'),
			'Fri'    => __('Friday', 'freshland-cycles'),
			'Sat'    => __('Saturday', 'freshland-cycles'),
			'Sun'    => __('Sunday', 'freshland-cycles')
		);
		$date = \DateTime::createFromFormat('Y-m-d', $delivery_date_meta);
		$name = $date->format('D');
		$name = $week_name_translation[$name];
		$delivery_dates[$delivery_date_meta] = $name . ' ' . $date->format('d/m');

		return array(
			'order_date' => $order_date,
			'delivery_dates' => $delivery_dates,
			'delivery_date_raw' => $delivery_date_meta
		);
	}
	
	public function cst_get_order_dates( $order_id ) {
		
		if (!$order_id || empty($order_id)) {
			return array(); // Return empty array if order is not found
		}
		
		// Get the order object
		$order = wc_get_order($order_id);

		if (!$order) {
			return array(); // Return empty array if order is not found
		}

		// Get the shipping or billing postcode from the order
		$postcode = $order->get_shipping_postcode() ? $order->get_shipping_postcode() : $order->get_billing_postcode();
		$order_date = $order->get_date_created()->date('Y-m-d');
		
		$delivery_date_meta = get_post_meta($order_id, 'delivery_date', true);

		$delivery_dates = array();
		
		if(empty($delivery_date_meta)){
			return array(
				'order_date' => $order_date,
				'delivery_dates' => $delivery_dates,
				'delivery_date_raw' => $delivery_date_meta
			); 
		}
		
		$week_name_translation = array(
			'Mon'    => __('Monday', 'freshland-cycles'),
			'Tue'    => __('Tuesday', 'freshland-cycles'),
			'Wed'    => __('Wednesday', 'freshland-cycles'),
			'Thu'    => __('Thursday', 'freshland-cycles'),
			'Fri'    => __('Friday', 'freshland-cycles'),
			'Sat'    => __('Saturday', 'freshland-cycles'),
			'Sun'    => __('Sunday', 'freshland-cycles')
		);
		$date = \DateTime::createFromFormat('Y-m-d', $delivery_date_meta);
		$name = $date->format('D');
		$name = $week_name_translation[$name];
		$delivery_dates[$delivery_date_meta] = $name . ' ' . $date->format('d/m');

		return array(
			'order_date' => $order_date,
			'delivery_dates' => $delivery_dates,
			'delivery_date_raw' => $delivery_date_meta
		);
	}
	
	public function get_order_confirmation_details( $request ) {
		$order_id = $request['order_id'];
		$user_id = get_current_user_id();

		// Retrieve the order
		$order = wc_get_order($order_id);

		if (!$order) {
			return new WP_Error('no_order', 'Invalid order', array('status' => 404));
		}

		if ($order->get_user_id() !== $user_id) {
			return new WP_Error('no_permission', 'You do not have permission to view this order', array('status' => 403));
		}

		// Order data
		$order_data = array(
			'order_number' => $order->get_order_number(),
			'order_date' => wc_format_datetime($order->get_date_created()),
			'email' => $order->get_billing_email(),
			'total' => $order->get_formatted_order_total(),
			'currency' => $order->get_currency(),
			'payment_method' => $order->get_payment_method_title(),
		);

		// Order items
		$items = array();
		foreach ($order->get_items() as $item_id => $item) {
			$product = $item->get_product();
			$items[] = array(
				'name' => $item->get_name(),
				'quantity' => $item->get_quantity(),
				'subtotal' => $order->get_formatted_line_subtotal($item),
				'total' => wc_price($item->get_total()),
			);
		}

		// Order totals
		$totals = array(
			'subtotal' => wc_price($order->get_subtotal()),
			'shipping' => wc_price($order->get_shipping_total()),
			'discount' => wc_price($order->get_discount_total()),
			'total' => wc_price($order->get_total()),
		);

		// Billing address
		$billing_address = array(
			'first_name' => $order->get_billing_first_name(),
			'last_name' => $order->get_billing_last_name(),
			'company' => $order->get_billing_company(),
			'address_1' => $order->get_billing_address_1(),
			'address_2' => $order->get_billing_address_2(),
			'city' => $order->get_billing_city(),
			'postcode' => $order->get_billing_postcode(),
			'country' => $order->get_billing_country(),
			'state' => $order->get_billing_state(),
			'phone' => $order->get_billing_phone(),
			'email' => $order->get_billing_email(),
		);

		// Shipping address
		$shipping_address = array(
			'first_name' => $order->get_shipping_first_name(),
			'last_name' => $order->get_shipping_last_name(),
			'company' => $order->get_shipping_company(),
			'address_1' => $order->get_shipping_address_1(),
			'address_2' => $order->get_shipping_address_2(),
			'city' => $order->get_shipping_city(),
			'postcode' => $order->get_shipping_postcode(),
			'country' => $order->get_shipping_country(),
			'state' => $order->get_shipping_state(),
		);

		// Response data
		$response = array(
			'order_data' => $order_data,
			'items' => $items,
			'totals' => $totals,
			'billing_address' => $billing_address,
			'shipping_address' => $shipping_address,
		);

		return rest_ensure_response($response);
	}
	
	public function get_customer_profile( $request ) {
		$user_id = get_current_user_id();
		$user = get_userdata($user_id);

		if (!$user) {
			return new WP_Error('no_user', 'Invalid user', array('status' => 403));
		}

		$customer_data = array(
			'first_name' => $user->first_name,
			'last_name' => $user->last_name,
			'display_name' => $user->display_name,
			'email' => $user->user_email,
		);

		return rest_ensure_response($customer_data);
	}
	
	
	public function update_customer_profile( $request ) {
		$user_id = get_current_user_id();
		$user = get_userdata($user_id);

		if (!$user) {
			return new WP_Error('no_user', 'Usuario invalido', array('status' => 403));
		}

		$params = $request['customer_info'];

		// Update first name, last name, and display name
		if (isset($params['first_name'])) {
			update_user_meta($user_id, 'first_name', sanitize_text_field($params['first_name']));
		}
		if (isset($params['last_name'])) {
			update_user_meta($user_id, 'last_name', sanitize_text_field($params['last_name']));
		}
		if (isset($params['display_name'])) {
			wp_update_user(array(
				'ID' => $user_id,
				'display_name' => sanitize_text_field($params['display_name'])
			));
		}

		// Update password
		if (isset($params['old_password']) && isset($params['new_password']) && isset($params['confirm_password'])) {
			$old_password = $params['old_password'];
			$new_password = $params['new_password'];
			$confirm_password = $params['confirm_password'];

			// Check if the old password is correct
			if (!wp_check_password($old_password, $user->user_pass, $user->ID)) {
				return new WP_Error('incorrect_password', __('La contraseña actual es incorrecta.', 'woocommerce'), array('status' => 400));
			}

			// Check if the new password and confirm password match
			if ($new_password !== $confirm_password) {
				return new WP_Error('password_mismatch', __('La nueva contraseña y la contraseña de confirmación no coinciden.', 'woocommerce'), array('status' => 400));
			}

			// Update the user's password
			wp_set_password($new_password, $user->ID);
		}

		return rest_ensure_response(array('status' => 'success'));
	}
	
	public function get_customer_addresses( $request ){
		$user_id = get_current_user_id();

		if (!$user_id) {
			return new WP_Error('no_user', 'Invalid user', array('status' => 403));
		}

		$customer = new WC_Customer($user_id);

		if (!$customer) {
			return new WP_Error('no_customer', 'Invalid customer', array('status' => 404));
		}

		// Get billing address
		$billing_address = array(
			'first_name'  => $customer->get_billing_first_name(),
			'last_name'   => $customer->get_billing_last_name(),
			'company'     => $customer->get_billing_company(),
			'address_1'   => $customer->get_billing_address_1(),
			'address_2'   => $customer->get_billing_address_2(),
			'city'        => $customer->get_billing_city(),
			'postcode'    => $customer->get_billing_postcode(),
			'country'     => $customer->get_billing_country(),
			'state'       => $customer->get_billing_state(),
			'phone'       => $customer->get_billing_phone(),
			'email'       => $customer->get_billing_email(),
		);

		// Get shipping address
		$shipping_address = array(
			'first_name'  => $customer->get_shipping_first_name(),
			'last_name'   => $customer->get_shipping_last_name(),
			'company'     => $customer->get_shipping_company(),
			'address_1'   => $customer->get_shipping_address_1(),
			'address_2'   => $customer->get_shipping_address_2(),
			'city'        => $customer->get_shipping_city(),
			'postcode'    => $customer->get_shipping_postcode(),
			'country'     => $customer->get_shipping_country(),
			'state'       => $customer->get_shipping_state(),
		);

		return rest_ensure_response(array(
			'billing_address' => $billing_address,
			'shipping_address' => $shipping_address,
		));
	}
	
	public function generate_pdf_invoice_link( $request ){
		if (!class_exists('WC_pdf_functions')) {
			return new WP_Error('plugin_not_active', 'WooCommerce PDF Invoices plugin is not active', array('status' => 500));
		}

		$order_id = $request['order_id'];
		$user_id = get_current_user_id();
		$order = wc_get_order($order_id);

		if (!$order) {
			return new WP_Error('order_not_found', 'Order not found', array('status' => 404));
		}

		if ($order->get_user_id() !== $user_id) {
			return new WP_Error('no_permission', 'You do not have permission to view this order', array('status' => 403));
		}

		// Generate the PDF URL using the plugin's methods
		$pdf_url = self::wc_pdf_invoices_get_invoice_url($order_id);

		if (!$pdf_url) {
			return new WP_Error('invoice_not_found', 'Invoice could not be generated for this order', array('status' => 404));
		}

		return rest_ensure_response(array('download_link' => $pdf_url));
	}

	public static function wc_pdf_invoices_get_invoice_url($order_id) {
		
		if (!class_exists('WC_pdf_functions')) {
			return new WP_Error('plugin_not_active', 'WooCommerce PDF Invoices plugin is not active', array('status' => 500));
		}
		// Instantiate the PDF functions class
		$pdf_functions = new WC_pdf_functions();

		// Use the plugin's method to check and generate the PDF URL
		$pdf_url = self::pdf_url_check($order_id);

		// Check if the PDF exists and return the URL
		if ($pdf_url) {
			return $pdf_url;
		}

		return false;
	}
	
	public static function pdf_url_check($order_id) {
		 global $woocommerce;
		 
		 if ( isset( $order_id ) && !is_admin() ) {

			if( !class_exists('WC_send_pdf') ){
				$invoice_plugin_path = WP_PLUGIN_DIR . '/woocommerce-pdf-invoice/classes/';
				include( $invoice_plugin_path.'class-pdf-send-pdf-class.php' );
			}
			
			$orderid = stripslashes( $order_id );
			$order   = new WC_Order( $orderid );

			// Get the current user
			$current_user = wp_get_current_user();

			// Get the user id from the order
			$user_id = is_callable( array( $order, 'get_user_id' ) ) ? $order->get_user_id() : $order->user_id;

			// Allow $user_id to be filtered
			$user_id = apply_filters( 'pdf_invoice_download_user_id', $user_id, $current_user, $orderid );
			
			// Check the current user ID matches the ID of the user who placed the order
			if ( $user_id == $current_user->ID ) {
				return self::pdf_download_link( $order );
			}
		 
		}

	}
	
	public static function pdf_download_link( $order ) {

	 		// PDF Invoice settings
	 		$settings = get_option( 'woocommerce_pdf_invoice_settings' );

	 		$order_id   	= $order->get_id();
	 		return $download_url 	= site_url( '/?pdfid=' . $order_id . '&pdfnonce=' . wp_hash( $order->get_order_key(), 'nonce' ), 'https' );

	 	}
	
	public function get_user_addresses( $request ) {
		$user_id = get_current_user_id();
		$addresses = get_user_meta($user_id, '_multiple_shipping_address', true);
		
		if (empty($addresses)) {
			$customer = new WC_Customer($user_id);

			// Construct default shipping address
			$default_shipping_address = array(
				'first_name'  => $customer->get_shipping_first_name(),
				'last_name'   => $customer->get_shipping_last_name(),
				'company'     => $customer->get_shipping_company(),
				'address_1'   => $customer->get_shipping_address_1(),
				'address_2'   => $customer->get_shipping_address_2(),
				'city'        => $customer->get_shipping_city(),
				'postcode'    => $customer->get_shipping_postcode(),
				'country'     => $customer->get_shipping_country(),
				'state'       => $customer->get_shipping_state(),
				'phone'       => $customer->get_shipping_phone(),
			);

			// Check if the default shipping address is not empty
			$is_not_empty = array_filter($default_shipping_address, function($value) {
				return !empty($value);
			});
			
			unset($is_not_empty['selected']);

			if (!empty($is_not_empty)) {
				// Save the default shipping address as the multiple shipping address
				$default_shipping_address['selected'] = true;
				$addresses = array($default_shipping_address);
				update_user_meta($user_id, '_multiple_shipping_address', $addresses);
			} else {
				$addresses = array();
			}
		}

		return rest_ensure_response($addresses);
	}

	public function add_user_addresses( $request ) {
		$user_id = get_current_user_id();
		$new_addresses = $request['addresses'];

		// Validate each address in the new_addresses array
		foreach ($new_addresses as $key => $address) {
			if (!is_array($address) ||
				empty($address['address_1']) || !is_string($address['address_1']) ||
				empty($address['city']) || !is_string($address['city']) ||
				empty($address['postcode']) || !is_string($address['postcode']) ||	
				empty($address['phone']) || empty($address['country']) || !is_string($address['country'])) {
				return new WP_Error('invalid_address', 'One or more addresses are invalid.', array('status' => 400));
			}
			
			$new_addresses[$key] = array(
				'address_1' => $address['address_1'],
				'address_2' => $address['address_2'],
				'state' => !empty($address['state']) ? $address['state'] : '',
				'city' => $address['city'],
				'postcode' => $address['postcode'],
				'country' => $address['country'],
				'phone' => !empty($address['phone']) ? $address['phone'] : '',
				'selected' => !empty($address['selected']) ? $address['selected'] : ''
			);
			
		}

		// Override the existing addresses with the new addresses.
		update_user_meta($user_id, '_multiple_shipping_address', $new_addresses);

		return rest_ensure_response($new_addresses);
	}

	public function set_main_shipping_address( $request ) {
		$user_id = get_current_user_id();
		$index = (int) $request['index'];
		
		$addresses = get_user_meta($user_id, '_multiple_shipping_address', true);
		if (!is_array($addresses) || !isset($addresses[$index])) {
			return new WP_Error('address_not_found', 'Address not found', array('status' => 404));
		}
		
		$main_address = $addresses[$index];
		$addresses[$index]['selected'] = true;
		
		foreach( $addresses as $key => $address ){
			if( $key !== $index ){
				$addresses[$key]['selected'] = false;
			}
		}
		
		update_user_meta($user_id, 'shipping_address_1', $main_address['address_1']);
		update_user_meta($user_id, 'shipping_address_2', $main_address['address_2']);
		update_user_meta($user_id, 'shipping_city', $main_address['city']);
		update_user_meta($user_id, 'shipping_postcode', $main_address['postcode']);
		update_user_meta($user_id, 'shipping_country', $main_address['country']);
		update_user_meta($user_id, 'shipping_state', $main_address['state']);
		update_user_meta($user_id, 'shipping_phone', $main_address['phone']);
		
		update_user_meta($user_id, '_multiple_shipping_address', $addresses);
		
		return rest_ensure_response($main_address);
	}
	
	public function update_order_klarna_session( $request ){
		$data = $request;

		if (empty($data['order_id']) || empty($data['session_id'])) {
			return new WP_REST_Response('Invalid data.', 400);
		}
		
		$payment_method_categories = array(
			array(
				"asset_urls" => array(
					"descriptive" => "https://x.klarnacdn.net/payment-method/assets/badges/generic/klarna.svg",
					"standard" => "https://x.klarnacdn.net/payment-method/assets/badges/generic/klarna.svg"
				),
				"identifier" => "pay_later",
				"name" => "Få först. Betala sen."
			),
			array(
				"asset_urls" => array(
					"descriptive" => "https://x.klarnacdn.net/payment-method/assets/badges/generic/klarna.svg",
					"standard" => "https://x.klarnacdn.net/payment-method/assets/badges/generic/klarna.svg"
				),
				"identifier" => "pay_over_time",
				"name" => "Dela upp."
			),
			array(
				"asset_urls" => array(
					"descriptive" => "https://x.klarnacdn.net/payment-method/assets/badges/generic/klarna.svg",
					"standard" => "https://x.klarnacdn.net/payment-method/assets/badges/generic/klarna.svg"
				),
				"identifier" => "pay_now",
				"name" => "Direktbetalning"
			)
		);
		
		$order_id = $data['order_id'];
		$_kp_session_id = $data['session_id'];
		$_kp_client_token = $data['client_token'];
		
		$order = wc_get_order($order_id);
		
		if (!$order) {
			return new WP_REST_Response('Order not found.', 404);
		}
		
		$data = array(
			"klarna_session" => array(
				"client_token" => $_kp_client_token,
				"payment_method_categories" => $payment_method_categories,
				"session_id" => $_kp_session_id
			),
			"session_hash" => $this->get_session_order_hash($order),
			"session_country" => "SE"
		);
		
		add_post_meta( $order_id, '_kp_session_id', $_kp_session_id, true );
		add_post_meta( $order_id, '_kp_session_data', $data, true );
		
		return new WP_REST_Response('Order updated successfully.', 200);
		
	}
	
	private function get_session_order_hash( $order ) {
		// Get values to use for the combined hash calculation.
		$total            = $order->get_total( 'kp_total' );
		$billing_address  = $order->get_address( 'billing' );
		$shipping_address = $order->get_address( 'shipping' );

		// Calculate a hash from the values.
		$hash = md5( wp_json_encode( array( $total, $billing_address, $shipping_address ) ) );

		return $hash;
	}
	
	
	public function handle_klarna_authorization_callback( $request ) {
		$data = $request['order'];

		if (empty($request['success']) || empty($data['fraud_status']) || empty($data['order_id']) || empty($data['woo_order_id']) || empty($data['session_id'])) {
			return new WP_REST_Response('Invalid callback data.', 400);
		}

		// Extract information from the payload
		$fraud_status = sanitize_text_field($data['fraud_status']);
		$klarna_session_id = sanitize_text_field($data['session_id']);

		
		$order = wc_get_order($data['woo_order_id']);

		if (!empty($order->get_date_paid())) {
			return new WP_REST_Response('Order already paid.', 200);
		}
		
		$user_id = $order->get_user_id();

		if ($user_id) {
			$user = get_userdata($user_id);

			if ($user) {
				wp_clear_auth_cookie();
				wp_set_current_user($user_id);
				wp_set_auth_cookie($user_id);

				// Generate JWT token
				$jwt_token = self::generate_jwt_token($user);
			}
		}


		switch ($fraud_status) {
			case 'ACCEPTED':
				// Process accepted order
				kp_process_accepted($order, $data);
				$order->add_order_note(__('The Klarna order was successfully completed', 'klarna-payments-for-woocommerce'));
				break;
			case 'PENDING':
				// Process pending order
				kp_process_pending($order, $data);
				$order->add_order_note(__('The Klarna order is pending approval by Klarna', 'klarna-payments-for-woocommerce'));
				break;
			case 'REJECTED':
				// Process rejected order
				kp_process_rejected($order, $data);
				$order->add_order_note(__('The Klarna order was rejected by Klarna', 'klarna-payments-for-woocommerce'));
				break;
			default:
				$order->add_order_note(__('Failed to complete the order when returning from the hosted payment page.', 'klarna-payments-for-woocommerce'));
				break;
		}

		// Optionally clear session values if needed
		kp_unset_session_values();
		
		$session_handler = WC()->session;
		
		$session_id = $session_handler->get_session_cookie()[0];

        // Clear the session data
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'woocommerce_sessions', array('session_key' => $session_id));

        // Clear WooCommerce session and cart
        WC()->session->set_customer_cart_cookie(true);
        WC()->cart->empty_cart();

		return new WP_REST_Response(array(
			'message' => 'Order updated successfully.',
			'token' => $jwt_token,
			'user_id' => $user_id
		), 200);
	}
	
	
	public static function generate_jwt_token($user) {
		$issued_at = time();
		$expiration_time = $issued_at + (DAY_IN_SECONDS * 7); // jwt valid for 7 days
		$payload = array(
			'iss' => get_bloginfo('url'),
			'iat' => $issued_at,
			'exp' => $expiration_time,
			'data' => array(
				'user' => array(
					'id' => $user->ID,
				),
			),
		);

		$jwt = JWT::encode($payload, JWT_AUTH_SECRET_KEY, "HS256");

		return $jwt;
	}
	
	public function get_products_similar_to_subscription( $request ) {
		$subscription_id = intval($request['subscription_id']);
		$subscription = wcs_get_subscription($subscription_id);

		if (!$subscription) {
			return new WP_Error('no_subscription', __('Invalid subscription ID.', 'cart-rest-api-for-woocommerce'), array('status' => 404));
		}

		// Initialize variables for frequency and interval
		$frequency = $subscription->get_billing_period();
		$interval = $subscription->get_billing_interval();

		// Get the associated order items
		$order_id = $subscription->get_parent_id();
		$order = wc_get_order($order_id);

		/* if ($order) {
			foreach ($order->get_items() as $item) {
				// Check if the item is a subscription product
				$item_frequency = $item->get_meta('_subscription_period');
				$item_interval = $item->get_meta('_subscription_interval');

				// If frequency and interval are found, set them
				if ($item_frequency && $item_interval) {
					$frequency = $item_frequency;
					$interval = $item_interval;
					break; // Found the needed details, no need to loop further
				}
			}
		} */
		
		

		if (empty($frequency) || empty($interval)) {
			return new WP_Error('invalid_subscription', __('Subscription does not have valid frequency or interval.', 'cart-rest-api-for-woocommerce'), array('status' => 400));
		}

		// Fetch products matching the frequency and interval
		$products = wc_get_products(array(
			'limit' => -1,
			'status' => 'publish',
			'stock_status' => 'instock',
		));
		$matching_products = array();
		$thumbnail_id = '';
		$price = '';
		$weight = '';

		foreach ($products as $product) {
			$subscription_schemes = WCS_ATT_Product_Schemes::get_subscription_schemes($product);

			if ($subscription_schemes) {
				foreach ($subscription_schemes as $scheme) {
					$product_details = array();
					if ($scheme->get_period() === $frequency && $scheme->get_interval() == $interval) { // Ensure type match
						$thumbnail_id = get_post_thumbnail_id($product->get_id());
						
						// Get the URL of the thumbnail image
						$thumbnail_url = ''; // Initialize the variable to avoid errors in case there's no thumbnail
						if ($thumbnail_id) {
							$thumbnail_data = wp_get_attachment_image_src($thumbnail_id, 'full');
							if (!empty($thumbnail_data) && isset($thumbnail_data[0])) {
								$thumbnail_url = $thumbnail_data[0]; // URL of the thumbnail
							}
						}
						
						$price = wc_price($product->get_price());
						
						$weight = $product->get_weight() ? ' <span class="cst-weight">('.$product->get_weight().'kg)</span>' : '';
						
						$price = $price.$weight;
						
						$product_details = array(
							'id' => $product->get_id(),
							'name' => $product->get_title(),
							'price' => $price,
							'thumbnail' => $thumbnail_url,
						);
						$matching_products[] = $product_details;
						break;
					}
				}
			}
		}

		return new WP_REST_Response($matching_products, 200);
	}
	
	
	public static function get_posted_data($request) {

		$posted_data = array();

		$posted_data = array(
			'product_id'          => false,
			'subscription_id'     => false,
			'subscription_scheme' => false
		);

		if ( ! empty( $request[ 'add-to-subscription' ] ) && is_numeric( $request[ 'add-to-subscription' ] ) ) {

			if ( ! empty( $request[ 'add-product-to-subscription' ] ) && is_numeric( $request[ 'add-product-to-subscription' ] ) ) {

				$posted_data[ 'product_id' ]          = apply_filters( 'woocommerce_add_to_cart_product_id', absint( $request[ 'add-product-to-subscription' ] ) );
				$product_scheme = WCS_ATT_Product_Schemes::get_posted_subscription_scheme( $posted_data[ 'product_id' ] );
				$posted_data[ 'subscription_id' ]     = absint( $request[ 'add-to-subscription' ] );
				$posted_data[ 'subscription_scheme' ] = $product_scheme ? $product_scheme : $request['subscription_scheme'];
				$posted_data[ 'quantity' ]            = absint( $request[ 'quantity' ] );
			}
		}
		return $posted_data;
	}
	
	public function add_product_to_subscription_endpoint( $request ) {

		$posted_data = self::get_posted_data($request);
		
		//var_dump($posted_data);
		
		$_REQUEST = $request;

		if ( empty( $posted_data[ 'product_id' ] ) ) {
			return new WP_REST_Response( __( 'product_id is required', 'cart-rest-api-for-woocommerce' ), 200 );
		}

		if ( empty( $posted_data[ 'subscription_id' ] ) ) {
			return new WP_REST_Response( __( 'subscription_id is required', 'cart-rest-api-for-woocommerce' ), 200 );
		}

		$product_id      = $posted_data[ 'product_id' ];
		$subscription_id = $posted_data[ 'subscription_id' ];
		$subscription    = wcs_get_subscription( $subscription_id );

		if ( ! $subscription ) {
			return new WP_REST_Response( __( 'Subscription cannot be edited. Please get in touch with us for assistance', 'cart-rest-api-for-woocommerce' ), 200 );
		}

		/*
		 * Relay form validation to 'WC_Form_Handler::add_to_cart_action'.
		 * Use 'woocommerce_add_to_cart_validation' filter to:
		 *
		 * - Let WC validate the form.
		 * - If invalid, stop.
		 * - If valid, add the validated product to the selected subscription.
		 */

		self::$add_to_subscription_args = array(
				'product'      => wc_get_product($product_id),
				'product_id'   => $product_id,
				'quantity'     => $posted_data[ 'subscription_id' ]
			);;

		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'add_to_subscription_validation' ), 9999, 5 );

		/**
		 * 'wcsatt_pre_add_product_to_subscription_validation' action.
		 *
		 * @param  int  $product_id
		 * @param  int  $subscription_id
		 */
		do_action( 'wcsatt_pre_add_product_to_subscription_validation', $product_id, $subscription_id );

		$_REQUEST[ 'add-to-cart' ] = $product_id;

		// No worries, nothing gets added to the cart at this point.
		WC_Form_Handler::add_to_cart_action();

		// Disarm 'WC_Form_Handler::add_to_cart_action'.
		$_REQUEST[ 'add-to-cart' ] = false;

		/**
		 * 'wcsatt_post_add_product_to_subscription_validation' action.
		 *
		 * @param  int  $product_id
		 * @param  int  $subscription_id
		 */
		do_action( 'wcsatt_post_add_product_to_subscription_validation', $product_id, $subscription_id );

		// Remove filter.
		remove_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'add_to_subscription_validation' ), 9999 );

		// Validation passed?
		if ( ! self::$add_to_subscription_args ) {
			return new WP_REST_Response( __( 'Not a valid process', 'cart-rest-api-for-woocommerce' ), 200 );
		}

		// At this point we've got the green light to proceed.
		$subscription_scheme = $posted_data[ 'subscription_scheme' ];
		$product             = self::$add_to_subscription_args[ 'product' ];
		$args                = array_diff_key( self::$add_to_subscription_args, array( 'product' => 1 ) );

		// Keep the sneaky folks out.
		if ( ! WCS_ATT_Product::supports_feature( $product, 'subscription_management_add_to_subscription' ) ) {
			return new WP_REST_Response( __( 'Subscription doesn\'t support product management', 'cart-rest-api-for-woocommerce' ), 200 );
		}

		// A subscription scheme key should be posted already if we are supposed to do any matching.
		if ( WCS_ATT_Product::supports_feature( $product, 'subscription_scheme_options_product_single' ) ) {
			if ( empty( $subscription_scheme ) ) {
				return new WP_REST_Response( __( 'No Subscription scheme found', 'cart-rest-api-for-woocommerce' ), 200 );
			}
		// Extract the scheme details from the subscription and create a dummy scheme.
		} else {

			$subscription_scheme_object = new WCS_ATT_Scheme( array(
				'context' => 'product',
				'data'    => array(
					'subscription_period'          => $subscription->get_billing_period(),
					'subscription_period_interval' => $subscription->get_billing_interval()
				)
			) );

			$subscription_scheme = $subscription_scheme_object->get_key();

			WCS_ATT_Product_Schemes::set_subscription_schemes( $product, array( $subscription_scheme => $subscription_scheme_object ) );
		}

		// Set scheme on product object for later reference.
		WCS_ATT_Product_Schemes::set_subscription_scheme( $product, $subscription_scheme );

		try {

			/**
			 * 'wcsatt_add_product_to_subscription' action.
			 *
			 * @param  WC_Subscription  $subscription
			 * @param  WC_Product       $product
			 * @param  array            $args
			 *
			 * @hooked WCS_ATT_Manage_Add::add_product_to_subscription - 10
			 */
			return self::add_product_to_subscription( $subscription, $product, $args );

		} catch ( Exception $e ) {

			return new WP_REST_Response( __( 'Something went wrong, Please try again!', 'cart-rest-api-for-woocommerce' ), 200 );
		}
	}
	
	public static function add_product_to_subscription( $subscription, $product, $args ) {

		$subscription_scheme = WCS_ATT_Product_Schemes::get_subscription_scheme( $product );
		$default_args        = array(
			'product_id'   => $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id(),
			'variation_id' => $product->is_type( 'variation' ) ? $product->get_id() : 0,
			'quantity'     => 1,
			'variation'    => array()
		);

		$parsed_args = wp_parse_args( $args, $default_args );

		/*
		 * Add the product to cart first to ensure all hooks get fired.
		 */

		// Back up the existing cart contents.
		$add_to_subscription_args = array(
			'adding_product'        => $product,
			'restore_cart_contents' => WC()->cart->get_cart()
		);

		$add_to_subscription_args[ 'restore_cart_contents' ] = empty( $add_to_subscription_args[ 'restore_cart_contents' ] ) ? false : $add_to_subscription_args[ 'restore_cart_contents' ];

		// Empty the cart.
		WC()->cart->empty_cart( false );

		$extra_cart_data = array( 'add_product_to_subscription_schemes' => WCS_ATT_Product_Schemes::get_subscription_schemes( $product ) );
		$cart_item_key   = WC()->cart->add_to_cart( $parsed_args[ 'product_id' ], $parsed_args[ 'quantity' ], $parsed_args[ 'variation_id' ], $parsed_args[ 'variation' ], $extra_cart_data );

		// Add the product to cart.
		if ( ! $cart_item_key ) {

			wc_clear_notices();

			$subscription_url  = $subscription->get_view_order_url();
			$subscription_link = sprintf( _x( '<a href="%1$s">#%2$s</a>', 'link to subscription', 'woocommerce-all-products-for-subscriptions' ), esc_url( $subscription_url ), $subscription->get_id() );

			wc_add_notice( sprintf( __( 'There was a problem adding "%1$s" to subscription %2$s. Please get in touch with us for assistance.', 'woocommerce-all-products-for-subscriptions' ), $product->get_name(), $subscription_link ), 'error' );

			if ( $add_to_subscription_args[ 'restore_cart_contents' ] ) {
				WC()->cart->cart_contents = $parsed_args[ 'restore_cart_contents' ];
				WC()->cart->calculate_totals();
			}

			return new WP_REST_Response( __( 'Product couldn\'t get added to the cart!', 'cart-rest-api-for-woocommerce' ), 200 );
		}

		// Set scheme on product in cart to ensure it gets seen as a subscription by WCS.
		WCS_ATT_Product_Schemes::set_subscription_scheme( WC()->cart->cart_contents[ $cart_item_key ][ 'data' ], $subscription_scheme );

		// Calculate totals.
		WC()->cart->calculate_totals();

		/*
		 * Now -- add the cart contents to our subscription.
		 */

		return self::add_cart_to_subscription( $subscription, $add_to_subscription_args );
	}

	/**
	 * Adds the contents of a (recurring) cart to a subscription.
	 *
	 * @param  WC_Subscription  $subscription
	 * @param  boolean          $args
	 */
	public static function add_cart_to_subscription( $subscription, $args = array() ) {

		// Make sure recurring carts are there.
		if ( ! did_action( 'woocommerce_after_calculate_totals' ) ) {
			WC()->cart->calculate_totals();
		}

		if ( empty( WC()->cart->recurring_carts ) || 1 !== sizeof( WC()->cart->recurring_carts ) ) {
			return new WP_REST_Response( __( 'There are no items in the cart!', 'cart-rest-api-for-woocommerce' ), 200 );
		}

		$default_args = array(
			'adding_product'        => false,
			'restore_cart_contents' => false
		);

		$parsed_args = wp_parse_args( $args, $default_args );

		$cart               = current( WC()->cart->recurring_carts );
		$item_added         = false;
		$found_items        = array();
		$subscription_items = $subscription->get_items();
		$subscription_url   = $subscription->get_view_order_url();
		$subscription_link  = sprintf( _x( '<a href="%1$s">#%2$s</a>', 'link to subscription', 'woocommerce-all-products-for-subscriptions' ), esc_url( $subscription_url ), $subscription->get_id() );

		// First, map out identical items.
		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {

			$product        = $cart_item[ 'data' ];
			$variation_data = $cart_item[ 'variation' ];
			$product_id     = $cart_item[ 'product_id' ];
			$variation_id   = $cart_item[ 'variation_id' ];
			$quantity       = $cart_item[ 'quantity' ];
			$found_item     = false;

			/*
			 * Does an identical line item already exist (hm, what does identical really mean in this context :S)?
			 */
			foreach ( $subscription->get_items() as $item_id => $item ) {

				// Same ID?
				if ( $product_id === $item->get_product_id() && $variation_id === $item->get_variation_id() ) {

					/*
					 * Totals match?
					 */

					$quantity_changed = false;

					// Are we comparing apples to apples?
					if ( $quantity !== $item->get_quantity() ) {

						$cart->set_quantity( $cart_item_key, $item->get_quantity() );
						$cart->calculate_totals();

						$quantity_changed = true;
					}

					// Compare totals.
					if ( $cart->cart_contents[ $cart_item_key ][ 'line_total' ] == $item->get_total() && $cart->cart_contents[ $cart_item_key ][ 'line_subtotal' ] == $item->get_subtotal() ) {
						$found_item = $item;
					}

					// Reset cart item quantity.
					if ( $quantity_changed ) {
						$cart->set_quantity( $cart_item_key, $quantity );
					}

					/*
					 * Variation? Check if attribute values match.
					 */

					if ( $found_item ) {
						if ( $product->is_type( 'variation' ) ) {
							foreach ( $variation_data as $key => $value ) {
								if ( $value !== $item->get_meta( str_replace( 'attribute_', '', $key ), true ) ) {
									$found_item = false;
									break;
								}
							}
						}
					}
				}

				// There's still a chance something else might be different, so let's add a filter here.

				/**
				 * 'wcsatt_add_cart_to_subscription_found_item' filter.
				 *
				 * @param  WC_Order_Item_Product|false  $found_item
				 * @param  array                        $cart_item
				 * @param  WC_Cart                      $cart
				 * @param  WC_Subscription              $subscription
				 */
				$found_item = apply_filters( 'wcsatt_add_cart_to_subscription_found_item', $found_item, $cart_item, $cart, $subscription );

				if ( $found_item ) {
					// Save.
					$found_items[ $cart_item_key ] = $item_id;
					// Break.
					break;
				}
			}
		}

		// If any identical items were found, increment their quantities and recalculate cart totals :)
		if ( ! empty( $found_items ) ) {

			foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {

				if ( isset( $found_items[ $cart_item_key ] ) ) {

					$quantity   = $cart_item[ 'quantity' ];
					$found_item = $subscription_items[ $found_items[ $cart_item_key ] ];

					$cart->set_quantity( $cart_item_key, $quantity + $found_item->get_quantity() );
				}
			}

			$cart->calculate_totals();
		}

		// Now, get to work.
		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {

			$product        = $cart_item[ 'data' ];
			$variation_data = $cart_item[ 'variation' ];
			$quantity       = $cart_item[ 'quantity' ];
			$total          = $cart_item[ 'line_total' ];
			$subtotal       = $cart_item[ 'line_subtotal' ];

			$product_id     = $cart_item[ 'product_id' ];
			$variation_id   = $cart_item[ 'variation_id' ];

			// If an identical line item was found, increase its quantity in the subscription.
			if ( isset( $found_items[ $cart_item_key ] ) ) {

				$found_item    = $subscription_items[ $found_items[ $cart_item_key ] ];
				$existing_item = clone $found_item;

				$item_qty          = $found_item->get_quantity();
				$item_qty_new      = $quantity;
				$item_total_new    = $total;
				$item_subtotal_new = $subtotal;

				$found_item->set_quantity( $item_qty_new );
				$found_item->set_total( $item_total_new );
				$found_item->set_subtotal( $item_subtotal_new );

				$subscription->add_order_note( sprintf( _x( 'Customer increased the quantity of "%1$s" (Product ID: #%2$d) from %3$s to %4$s.', 'used in order note', 'woocommerce-all-products-for-subscriptions' ), $found_item->get_name(), $product_id, $item_qty, $item_qty_new ) );

				/**
				 * 'wcsatt_add_cart_item_to_subscription_item_updated' action.
				 *
				 * Fired when an identical item is found in the subscription.
				 *
				 * @param  WC_Order_Item_Product  $found_item
				 * @param  WC_Order_Item_Product  $existing_item
				 * @param  array                  $cart_item
				 * @param  WC_Cart                $cart
				 * @param  WC_Subscription        $subscription
				 */
				do_action( 'wcsatt_add_cart_item_to_subscription_item_updated', $found_item, $existing_item, $cart_item, $cart, $subscription );

				$found_item->save();

				$item_added = true;

			// Otherwise, add a new line item.
			} else {

				/**
				 * Custom callback for adding cart items to subscriptions.
				 *
				 * @param  array|false  $callback
				 * @param  array        $cart_item
				 * @param  WC_Cart      $cart
				 */
				$add_cart_item_to_subscription_callback = apply_filters( 'wscatt_add_cart_item_to_subscription_callback', false, $cart_item, $cart );

				// Do not add cart item.
				if ( is_null( $add_cart_item_to_subscription_callback ) ) {

					continue;

				// Use custom callback to add cart item.
				} if ( is_callable( $add_cart_item_to_subscription_callback ) ) {

					$added_item_id = call_user_func_array( $add_cart_item_to_subscription_callback, array( $subscription, $cart_item, $cart ) );

				// Use standard method.
				} else {

					$item = apply_filters( 'woocommerce_checkout_create_order_line_item_object', new WC_Order_Item_Product(), $cart_item_key, $cart_item, $subscription );

					$item->set_props(
						array(
							'name'         => $product->get_name(),
							'tax_class'    => $product->get_tax_class(),
							'product_id'   => $product_id,
							'variation_id' => $variation_id,
							'variation'    => $variation_data,
							'quantity'     => $quantity,
							'subtotal'     => $subtotal,
							'total'        => $total
						)
					);

					do_action( 'woocommerce_checkout_create_order_line_item', $item, $cart_item_key, $cart_item, $subscription );

					$item->save();
					$subscription->add_item( $item );

					$added_item_id = $item->get_id();
				}

				if ( ! $added_item_id || is_wp_error( $added_item_id ) ) {

					wc_add_notice( sprintf( __( 'There was a problem adding "%1$s" to subscription %2$s.', 'woocommerce-all-products-for-subscriptions' ), $product->get_name(), $subscription_link ), 'error' );

				} else {

					$item_added = true;
					$added_item = wcs_get_order_item( $added_item_id, $subscription );

					// Save the scheme key!
					$added_item->add_meta_data( '_wcsatt_scheme', WCS_ATT_Product_Schemes::get_subscription_scheme( $product ), true );

					$subscription->add_order_note( sprintf( _x( 'Customer added "%1$s" (Product ID: #%2$d).', 'used in order note', 'woocommerce-all-products-for-subscriptions' ), $added_item->get_name(), $product_id ) );

					/**
					 * 'wcsatt_add_cart_item_to_subscription_item_added' action.
					 *
					 * Fired when a new item is added to the subscription.
					 *
					 * @param  WC_Order_Item_Product  $found_item
					 * @param  array                  $cart_item
					 * @param  WC_Cart                $cart
					 * @param  WC_Subscription        $subscription
					 */
					do_action( 'wcsatt_add_cart_item_to_subscription_item_added', $added_item, $cart_item, $cart, $subscription );

					$added_item->save();
				}
			}
		}

		// Success, something was added. Note that we don't handle partial failures here, maybe we should?
		if ( $item_added ) {

			$subscription->calculate_totals();
			$subscription->save();

			// Adding a product to a subscription from the single-product page?
			if ( is_a( $parsed_args[ 'adding_product' ], 'WC_Product' ) ) {
				$success_message = sprintf( __( 'You have successfully added "%1$s" to subscription %2$s.', 'woocommerce-all-products-for-subscriptions' ), $parsed_args[ 'adding_product' ]->get_name(), $subscription_link );
			} else {
				$success_message = sprintf( __( 'You have successfully added the contents of your cart to subscription %s.', 'woocommerce-all-products-for-subscriptions' ), $subscription_link );
			}

			wc_add_notice( $success_message );

			/**
			 * Filter redirect url.
			 *
			 * @param  string           $url
			 * @param  WC_Subscription  $subscription
			 */
			$redirect_url = apply_filters( 'wcsatt_add_cart_to_subscription_redirect_url', $subscription_url, $subscription );

			// Adding a product to a subscription from the single-product page?
			if ( is_a( $parsed_args[ 'adding_product' ], 'WC_Product' ) ) {
				// Reset cart contents to an earlier state if needed - @see 'add_product_to_subscription'.
				if ( is_array( $parsed_args[ 'restore_cart_contents' ] ) ) {
					WC()->cart->cart_contents = $parsed_args[ 'restore_cart_contents' ];
					WC()->cart->calculate_totals();
				// Otherwise nothing must have been in the cart in the first place.
				} else {
					WC()->cart->empty_cart();
				}
			// Just empty the cart, assuming success at this point... or?
			} else {
				WC()->cart->empty_cart();
			}

			$response = array(
				'status' => 200,
				'message' => 'Product has been added to your subscription'
			);
			
			return new WP_REST_Response($response, 200);
		}
	}
	
	public static function add_to_subscription_validation( $result, $product_id, $quantity, $variation_id = 0, $variation_data = array() ) {

		if ( $result ) {

			$product = wc_get_product( $variation_id ? $variation_id : $product_id );

			/*
			 * Validate stock.
			 */

			if ( ! $product->is_in_stock() ) {
				wc_add_notice( sprintf( __( '&quot;%s&quot; is out of stock.', 'woocommerce-all-products-for-subscriptions' ), $product->get_name() ), 'error' );
				return false;
			}

			if ( ! $product->has_enough_stock( $quantity ) ) {
				/* translators: 1: product name 2: quantity in stock */
				wc_add_notice( sprintf( __( '&quot;%1$s&quot; does not have enough stock (%2$s remaining).', 'woocommerce-all-products-for-subscriptions' ), $product->get_name(), wc_format_stock_quantity_for_display( $product->get_stock_quantity(), $product ) ), 'error' );
				return false;
			}

			/*
			 * Flash the green light.
			 */

			self::$add_to_subscription_args = array(
				'product'      => $product,
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'quantity'     => $quantity,
				'variation'    => $variation_data
			);
		}

		return false;
	}
	
	
	public function update_subscription($request) {
		$status = false;
		$response = array();
		// Get type of action
		$type 		= sanitize_text_field( $request["type"] );

		if ( $type === 'set_to_on-hold' ) {

			$sub_id 		= sanitize_text_field( $request["sub_id"] );
			$user_id		= get_current_user_id();
			$order 			= wc_get_order( $sub_id );
			$user 			= $order->get_user();
			$order_user_id 	= $order->get_user_id();

			if ( $user_id === $order_user_id ) {

				$user_can_suspend = true;
				$subscription = wcs_get_subscription( $sub_id );
				$status = true;
				$new_status = 'on-hold';
				$subscription->update_status( $new_status );
				wc_add_notice( _x( 'Your subscription has been put on hold.', 'Notice displayed to user confirming their action.', 'woocommerce-subscriptions' ), 'success' );
			}

			if($status){
				$response = array(
					'status' => 200,
					'message' => 'Your subscription has been put on hold'
				);
				
				return new WP_REST_Response($response, 200);
			}

		} else if ( $type === 'get_delivery_date' ) {

			$selected_date 	= sanitize_text_field( $request["selected_date"] );
			$postal_code 	= ( WC()->customer->get_postcode() ? WC()->customer->get_postcode() : false) ;
			$date 			= new DateTime( $selected_date );
			$admin_class    = new Freshland_Cycles_Admin( '', '' );
			$delivery_dates = $admin_class->get_delivery_dates( $postal_code, 4, $date->format( 'Y-m-d' ) );
			switch_to_locale( get_locale() ); // Not working as expected with WordPress settings
			$dayofweek 		= date_i18n( 'l', strtotime( $delivery_dates[0] ) );
			$date_available = new DateTime( $delivery_dates[0] );
			$next_date 		= $dayofweek . ' ' . date_format( $date_available, 'd/m/y' );

			if($next_date){
				$response = array(
					'status' => 200,
					'next_date' => $next_date
				);
				
				return new WP_REST_Response($response, 200);
			}

		} else if ( $type === 'set_delivery_date' ) {

			$sub_id 		= sanitize_text_field( $request["sub_id"] );
			$selected_date 	= sanitize_text_field( $request["selected_date"] );
			$delivery_date 	= sanitize_text_field( $request["delivery_date"] );
			$date 			= new DateTime( $selected_date );
			$subscription 	= wcs_get_subscription( $sub_id );
			$subscription->update_status( 'active' );

			// Update post meta with subscription id and next payment date
			$sub_suspend	= get_user_meta( get_current_user_id(), 'sub_suspend' );
			update_post_meta( $sub_id, 'sub_suspend', array( 'sub_id' => $sub_id, 'npd' => $date ) );

			// The new date calculation of +7 days is just an example
			$new_dates = array(
				'next_payment' => date_format( $date, 'Y-m-d H:i:s' )
			);

			// Schedule a cron to run after the scheduled subscription is renewed
			$date_cron  = $date->add( new DateInterval( 'PT15M')); // Add 15 minutes to the next payment date
			$date_ts	= $date_cron->getTimestamp();
			wp_schedule_single_event( $date_ts, 'scheduled_sub_renewed', array( $sub_id ) );

			// Send an email with the user schedule action
			$settings 		= get_option('fl_main_settings');
			$emails_sus 	= $settings['subs_suspens_emails'];
			$subject 		= sprintf( __( 'Subscription %d was suspended', 'freshland' ), $sub_id );
			$headers 		= array('Content-Type: text/html; charset=UTF-8');
			$body			= '<p>' . sprintf( __( 'The subscription %1$d was suspended and scheduled to run in %2$s', 'freshland' ), $sub_id, date_format( $date, 'Y-m-d H:i:s' ) ) . '.</p>';
			$body			.= '<p>' . __( 'You\'ll get an email to confirm if the subscription was successfully renewed.', 'freshland' ) . '</p>';

			if ( $emails_sus ) {
				wp_mail( $emails_sus, $subject, $body, $headers );
			}

			$subscription->update_dates( $new_dates, 'site' );

			// To change the date of the notification
			$plugin_subscriptions = new Freshland_Subscriptions();
			$plugin_subscriptions->as_subscriptions_notifications( $subscription, $date );

			$status = true;
			wc_add_notice( _x( 'Your subscription is now suspended and will be activated on the date selected.', 'Notice displayed to user confirming the subscription suspension.', 'woocommerce-subscriptions' ), 'success' );

			if($status){
				$response = array(
					'status' => 200,
					'message' => 'Your subscription is now suspended and will be activated on the date selected.'
				);
				
				return new WP_REST_Response($response, 200);
			}

		}
		
		$response = array(
			'status' => 200,
			'message' => 'No type were selected.'
		);
		
		return new WP_REST_Response($response, 200);

	}
	
	public function get_order_details($request) {
		$order_id = (int) $request['order_id'];

		// Get the order object
		$order = wc_get_order($order_id);
		if (!$order) {
			return new WP_Error('no_order', __('Order not found', 'cart-rest-api-for-woocommerce'), array('status' => 404));
		}

		// Get the current user ID and check if the order belongs to the user
		$user_id = get_current_user_id();
		if ($order->get_user_id() !== $user_id) {
			return new WP_Error('not_allowed', __('You are not allowed to view this order', 'cart-rest-api-for-woocommerce'), array('status' => 403));
		}
		
		$delivery_date = date('Y/m/d', strtotime(get_post_meta($order_id, 'delivery_date', true)));

		// Prepare order totals
		$totals = array(
			'total_items' => $order->get_subtotal(),
			'total_fees' => $order->get_total_fees(),
			'total_discount' => $order->get_discount_total(),
			'total_discount_tax' => $order->get_discount_tax(),
			'total_shipping' => $order->get_shipping_total(),
			'total_shipping_tax' => $order->get_shipping_tax(),
			'total_price' => $order->get_total(),
			'total_tax' => $order->get_total_tax(),
			'currency_code' => $order->get_currency(),
			'currency_symbol' => get_woocommerce_currency_symbol($order->get_currency()),
			'currency_minor_unit' => 2,
			'currency_decimal_separator' => wc_get_price_decimal_separator(),
			'currency_thousand_separator' => wc_get_price_thousand_separator(),
			'currency_prefix' => '',
			'currency_suffix' => ' ' . get_woocommerce_currency_symbol($order->get_currency())
		);

		// Get order items
		$items = array();
		foreach ($order->get_items() as $item_id => $item) {
			$product = $item->get_product();
			$items[] = array(
				'key' => $item_id,
				'id' => $product ? $product->get_id() : 0,
				'type' => $product ? $product->get_type() : 'unknown',
				'quantity' => $item->get_quantity(),
				'quantity_limits' => array(
					'minimum' => 1,
					'maximum' => $product ? $product->get_max_purchase_quantity() : 9999,
					'multiple_of' => 1,
					'editable' => true
				),
				'name' => $item->get_name(),
				'short_description' => $product ? $product->get_short_description() : '',
				'description' => $product ? $product->get_description() : '',
				'sku' => $product ? $product->get_sku() : '',
				'low_stock_remaining' => $product ? $product->get_stock_quantity() : null,
				'backorders_allowed' => $product ? $product->backorders_allowed() : false,
				'show_backorder_badge' => $product ? $product->is_on_backorder() : false,
				'sold_individually' => $product ? $product->is_sold_individually() : false,
				'permalink' => $product ? $product->get_permalink() : '',
				'images' => $product ? array_map(function($image_id) {
					return wp_get_attachment_image_src($image_id, 'full');
				}, $product->get_gallery_image_ids()) : array(),
				'variation' => $item->get_variation_id(),
				'item_data' => $item->get_meta_data(),
				'prices' => array(
					'price' => $item->get_total(),
					'regular_price' => $product ? $product->get_regular_price() : 0,
					'sale_price' => $product ? $product->get_sale_price() : 0,
					'price_range' => null,
					'currency_code' => $order->get_currency(),
					'currency_symbol' => get_woocommerce_currency_symbol($order->get_currency()),
					'currency_minor_unit' => 2,
					'currency_decimal_separator' => wc_get_price_decimal_separator(),
					'currency_thousand_separator' => wc_get_price_thousand_separator(),
					'currency_prefix' => '',
					'currency_suffix' => ' ' . get_woocommerce_currency_symbol($order->get_currency()),
					'raw_prices' => array(
						'precision' => 6,
						'price' => $item->get_total(),
						'regular_price' => $product ? $product->get_regular_price() : 0,
						'sale_price' => $product ? $product->get_sale_price() : 0
					)
				),
				'totals' => array(
					'line_subtotal' => str_replace('.', '', round($item->get_subtotal(), 2)),
					'line_total' => str_replace('.', '', round($item->get_total(), 2 )),
					'line_total_tax' => str_replace('.', '', round($item->get_total_tax(), 2)),
					'currency_code' => $order->get_currency(),
					'currency_symbol' => get_woocommerce_currency_symbol($order->get_currency()),
					'currency_minor_unit' => 2,
					'currency_decimal_separator' => wc_get_price_decimal_separator(),
					'currency_thousand_separator' => wc_get_price_thousand_separator(),
					'currency_prefix' => '',
					'currency_suffix' => ' ' . get_woocommerce_currency_symbol($order->get_currency())
				),
				'catalog_visibility' => $product ? $product->get_catalog_visibility() : 'hidden',
				'extensions' => array(
					'subscription_schemes' => array(
						'is_subscription' => $product ? $product->is_type('subscription') : false,
						'subscription_schemes' => array()
					)
				)
			);
		}

		// Append custom fields and totals to the order data
		$order_details = array(
			'order_data' => $order->get_data(),
			'totals' => $totals,
			'items' => $items,
			'delivery_date' => $delivery_date,
		);

		return new WP_REST_Response($order_details, 200);
	}
	
	
	public function handle_logout($request) {
		$session_handler = WC()->session;
		$session_handler->set_customer_cart_cookie( false );
		$session_handler->set( 'cart', array() );
		wc_setcookie( $session_handler->_cookie, '', time() - YEAR_IN_SECONDS, wc_site_is_https(), true );
		return new WP_REST_Response(array(
			'message' => __('Logged out successfully.', 'cart-rest-api-for-woocommerce')
		), 200);
	}
	
	
	public function update_cart_item_frequency($request) {
		$cart_item_key = sanitize_text_field($request['cart_item_key']);
		$subscription_scheme = sanitize_text_field($request['subscription_scheme']);
		
		$cart_contents = WC()->cart->get_cart();
        $keys = array_keys ( $cart_contents );
		
		//var_dump($cart_contents[$cart_item_key]['quantity']);
		
		foreach ( $keys as $key ) {
			if($key === $cart_item_key){
				$cart_contents[$key]['wcsatt_data'] = array(
						'active_subscription_scheme' => $subscription_scheme
					);
					break;
				if("one-time" == $subscription_scheme){
					unset($cart_contents[$key]['wcsatt_data']);
				}
			}
		}
	
		// Add to cart right away so the product is visible in woocommerce_get_cart_item_from_session hook.
		WC()->cart->set_cart_contents( $cart_contents );
		//return new WP_REST_Response($cart_contents, 200);
		WC()->cart->calculate_totals();
		//WC_Subscriptions_Cart::calculate_subscription_totals(WC()->cart->get_total('total'), WC()->cart);
		WC()->cart->calculate_shipping();
		
		return new WP_REST_Response($cart_contents, 200);

	}
	
	
	public function handle_login($request) {
		global $wpdb;
		$input = sanitize_text_field( $request['username'] );
		$guest_cart_key = sanitize_text_field($request->get_header('cart-key'));
		$secret_key = defined('JWT_AUTH_SECRET_KEY') ? JWT_AUTH_SECRET_KEY : 'F3Xm43?O~e]}>P6M1;R|eh{ kVYf^iC) PH82JLh%L!,eVPH/q D5@1CU+a<NdNQ'; // Replace with your own secret key
		
		$user = false;
		// First, check if it's a valid username
		if ( $userid = username_exists( $input ) ) {
			$user = get_userdata( $userid );
		}
		// If not found, try checking by email
		elseif ( $user = get_user_by( 'email', $input ) ) {
			$userid = $user->ID;
		}
		
		if ( $user ) {
			$username = $user->user_email; // or $user->user_login if you need username
		} else {
			return new WP_Error(
				'invalid_credentials',
				__( 'Invalid username or password', 'woocommerce' ),
				array( 'status' => 403 )
			);
		}
		
		//var_dump(username_exists($username));
		$password = $request['password'];
		
		// Access the WooCommerce session handler
		$session_handler = WC()->session;
		
		// Get the session data for the specified session ID
		$guest_session = !empty($guest_cart_key) ? $session_handler->get_cart_data_by_key( $guest_cart_key ) : false;

		$user = wp_authenticate($username, $password);
		
		if (is_wp_error($user)) {
			return new WP_Error('invalid_credentials', __('Invalid username or password', 'woocommerce'), array('status' => 403));
		}

		$user_id = $user->ID;
	
		$issued_at = time();
		$expiration_time = $issued_at + (60 * 60); // jwt valid for 1 hour
		$payload = array(
			'iss' => get_bloginfo('url'),
			'iat' => $issued_at,
			'exp' => $expiration_time,
			'data' => array(
				'user' => array(
					'id' => $user_id
				)
			)
		);

		$token = JWT::encode($payload, $secret_key, 'HS256');
		
		$loggedin_session  = $session_handler->get_cart_data_by_key( $user_id );
		
		wc_update_new_customer_past_orders( $user_id );
		
		if ( $guest_session && isset( $guest_session['cart'] ) ) {
			$guest_cart_items = maybe_unserialize( $guest_session['cart'] );
			$user_cart_items  = isset( $loggedin_session['cart'] ) ? maybe_unserialize( $loggedin_session['cart'] ) : array();

			// Merge guest into user cart
			foreach ( $guest_cart_items as $key => $item ) {
				if ( isset( $user_cart_items[ $key ] ) ) {
					$user_cart_items[ $key ]['quantity'] += $item['quantity'];
				} else {
					// Ensure required fields exist
					$item['quantity'] = isset($item['quantity']) ? (int)$item['quantity'] : 1;
					$user_cart_items[ $key ] = $item;
				}
			}


			// Save merged cart
			$loggedin_session['cart'] = maybe_serialize( $user_cart_items );
			$table = $GLOBALS['wpdb']->prefix . 'woocommerce_sessions';
			$cart_expiration = time() + intval( DAY_IN_SECONDS * 7 );
			
			$res = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO $table (`session_key`, `session_value`, `session_expiry`) VALUES (%s, %s, %d)
 					ON DUPLICATE KEY UPDATE `session_value` = VALUES(`session_value`), `session_expiry` = VALUES(`session_expiry`)",
					$user_id,
					maybe_serialize( $loggedin_session ),
					$cart_expiration
				)
			);
			//var_dump($res);
			WC()->session->set_customer_cart_cookie( true ); // Set new cookie for logged-in user
			WC()->session->set( 'cart', $user_cart_items ); // Set cart in current session
			WC()->cart->get_cart_from_session(); 
		}

		return new WP_REST_Response(array(
			'token' => $token,
			'user_id' => $user_id,
			'user_email' => $user->user_email,
		), 200);
	}
	
	public function get_customer_orders($request) {
		// Get the current user ID
		if(!is_user_logged_in()){
			return array();
		}
		$user_id = get_current_user_id();
	
		if (!$user_id) {
			return new WP_Error('no_user', __('User not logged in', 'cart-rest-api-for-woocommerce'), array('status' => 401));
		}
		
		//var_dump($billing_email);
		
		 $page = max(1, intval($request->get_param('page')));
		 $per_page = max(1, intval($request->get_param('per_page')));
		 
		 // Unique transient key per user per page
		$transient_key = "user_{$user_id}_orders_page_{$page}_per_{$per_page}";
		
		 // Try getting from transient
		$cached_response = get_transient($transient_key);
		if ($cached_response !== false) {
			return new WP_REST_Response($cached_response, 200);
		}

		 
		$args = array(
			'customer_id'   => $user_id,
			'orderby'       => 'date',
			'order'         => 'DESC',
			'paginate'      => true,
			'limit'         => $per_page,
			'page'          => $page,
		);
		

		// Get customer orders
		$order_query = wc_get_orders($args);
		
		$orders = $order_query->orders;
		$total = $order_query->total;
		

		// Prepare order details
		$order_details = array();

		foreach ($orders as $order) {
            $order_data = $order->get_data();
            $order_id = $order_data['id'];
            $order_number = $order->get_order_number();
            $order_date = wc_format_datetime($order_data['date_created'], 'Y/m/d');
            $delivery_date = date('Y/m/d', strtotime(get_post_meta($order_id, 'delivery_date', true)));
            $order_status = wc_get_order_status_name($order_data['status']);
            $order_total = $order->get_total();
            $order_items = $order->get_item_count();
            $currency = $order->get_currency();
			// Generate the PDF URL using the plugin's methods
			$pdf_url = self::wc_pdf_invoices_get_invoice_url($order_id);

			$pdf_url = $pdf_url ? $pdf_url : false;


            $order_details[] = array(
                'order_number' => $order_number,
                'order_date' => $order_date,
                'delivery_date' => $delivery_date,
                'order_status' => $order_status,
                'pdf_url' => $pdf_url,
                'order_total' => sprintf(__('%s %s for %d Enheter', 'cart-rest-api-for-woocommerce'), $currency, $order_total, $order_items)
            );
        }

		 $response_data = array(
			'data'         => $order_details,
			'total'        => $total,
			'total_pages'  => ceil($total / $per_page),
			'current_page' => $page
		);

		// Store in transient (cache for 10 minutes)
		set_transient($transient_key, $response_data, 10 * MINUTE_IN_SECONDS);

		return new WP_REST_Response($response_data, 200);
	}
	
	public function update_delivery_days($request) {
		// Extract parameters from the request
		$postcode = sanitize_text_field($request['postcode']);
		$lang = sanitize_text_field($request['lang']);
		$localPickUp = $request['localPickUp'] ? $request['localPickUp'] : false;
		$delivery_dates = array();
		$fc_plugin_path = WP_PLUGIN_DIR . '/freshland-cycles/';

		// Include the admin class file
		require_once $fc_plugin_path . 'admin/class-freshland-cycles-admin.php';
		
		if(class_exists('Freshland_Cycles_Admin')){

			$freshland_cycle = new Freshland_Cycles_Admin( 'freshland-cycles ', '1.0.0' );

			$date_options = $freshland_cycle->get_delivery_dates( $postcode, 4 );

			if ( ! empty( $date_options ) ) {
				$week_name_translation = array(
					'Mon'    => __( 'Monday', 'freshland-cycles' ),
					'Tue'    => __( 'Tuesday', 'freshland-cycles' ),
					'Wed'    => __( 'Wednesday', 'freshland-cycles' ),
					'Thu'    => __( 'Thursday', 'freshland-cycles' ),
					'Fri'    => __( 'Friday', 'freshland-cycles' ),
					'Sat'    => __( 'Saturday', 'freshland-cycles' ),
					'Sun'    => __( 'Sunday', 'freshland-cycles' )
				);
				foreach( $date_options as $string_date ) {
					$date = \DateTime::createFromFormat('Y-m-d', $string_date );
					$name = $date->format('D');
					$name = $week_name_translation[$name];
					$delivery_dates[$string_date] = $name . ' '. $date->format('d/m');
				}
			}
		}

		// Example response data
		$response_data = array(
			'success' => true,
			'data' => $delivery_dates
		);

		// Return the response
		return new WP_REST_Response($response_data, 200);
	}
	
	public function get_delivery_days() {
		// Extract parameters from the request
		WC()->customer = new WC_Customer(get_current_user_id(), true);
		$postcode = WC()->customer->get_shipping_postcode() ? WC()->customer->get_shipping_postcode() : WC()->customer->get_billing_postcode();
		$lang = 'da_DK';
		$localPickUp = false;
		$delivery_dates = array();
		$fc_plugin_path = WP_PLUGIN_DIR . '/freshland-cycles/';
		
		if($postcode){
			// Include the admin class file
			require_once $fc_plugin_path . 'admin/class-freshland-cycles-admin.php';
			
			if(class_exists('Freshland_Cycles_Admin')){

				$freshland_cycle = new Freshland_Cycles_Admin( 'freshland-cycles ', '1.0.0' );

				$date_options = $freshland_cycle->get_delivery_dates( $postcode, 4 );

				if ( ! empty( $date_options ) ) {
					$week_name_translation = array(
						'Mon'    => __( 'Monday', 'freshland-cycles' ),
						'Tue'    => __( 'Tuesday', 'freshland-cycles' ),
						'Wed'    => __( 'Wednesday', 'freshland-cycles' ),
						'Thu'    => __( 'Thursday', 'freshland-cycles' ),
						'Fri'    => __( 'Friday', 'freshland-cycles' ),
						'Sat'    => __( 'Saturday', 'freshland-cycles' ),
						'Sun'    => __( 'Sunday', 'freshland-cycles' )
					);
					foreach( $date_options as $string_date ) {
						$date = \DateTime::createFromFormat('Y-m-d', $string_date );
						$name = $date->format('D');
						$name = $week_name_translation[$name];
						$delivery_dates[$string_date] = $name . ' '. $date->format('d/m');
					}
				}
			}
		}
		
		//Temporary delivery date set for demo
		if(!$delivery_dates)
			$delivery_dates = array("2024-06-05" => "Wednesday 05/06");
		
		return  $delivery_dates;

	}
	
	public function get_subscription_options($request) {
		$identifier = $request['product_identifier'];
		$product = is_numeric($identifier) ? wc_get_product($identifier) : wc_get_product_by_slug($identifier);

		if (!$product || !$product->is_type(array('simple', 'variable'))) {
			return new WP_Error('invalid_product', __('Invalid product.', 'cart-rest-api-for-woocommerce'), array('status' => 404));
		}

		$subscription_options = array('is_subscription' => false);
		if ($this->is_product_subscription($product->get_id())) {
			$subscription_options['is_subscription'] = true;
			$subscription_options['subscription_schemes'] = $this->get_product_subscription_schemes($product->get_id());
		}

		return new WP_REST_Response($subscription_options, 200);
	}
	
	public function is_product_subscription($product_id){
		
		if(!class_exists('WCS_ATT_Product_Schemes')){
			return false;
		}
		
		$product = wc_get_product($product_id);

		if (!$product || !$product->is_type(array('simple', 'variable'))) {
			return false;
		}
		$product_schemes = WCS_ATT_Product_Schemes::get_subscription_schemes($product);
		if($product_schemes){
			return true;
		}
		return false;
	}
	
	
	public function get_product_subscription_schemes($product_id, $active_subscription=false){
		$product = wc_get_product($product_id);

		if (!$product || !$product->is_type(array('simple', 'variable'))) {
			return array();
		}
		$product_schemes = WCS_ATT_Product_Schemes::get_subscription_schemes($product);
		$default_product_scheme = WCS_ATT_Product_Schemes::get_default_subscription_scheme($product, 'key');
		$subscription_options = array();
		if(!$default_product_scheme){
			$subscription_options[] = array(
					'id'                     => $product->get_meta( '_wcsatt_default_status', true ),
					'title'                  => "One time" ,
					'price'                  => wc_price($product->get_price()),
					'billing_period'         => false,
					'interval'               => false,
					'trial_length'           => false,
					'trial_period'           => false,
					'selected_subscription'  => false,
					'default_subscription'   => true
				);
		}
		if($product_schemes){
			foreach ($product_schemes as $scheme) {
				$subscription_options[] = array(
					'id'                     => $scheme->get_key(),
					'title'                  => "Every {$scheme->get_interval()} {$scheme->get_period()}" ,
					'price'                  => wc_price(WCS_ATT_Product_Prices::get_price($product, $scheme->get_key())),
					'billing_period'         => $scheme->get_period(),
					'interval'               => $scheme->get_interval(),
					'trial_length'           => $scheme->get_trial_length(),
					'trial_period'           => $scheme->get_trial_period(),
					'selected_subscription'  => $active_subscription == $scheme->get_key() ? true : false,
					'default_subscription'   => false // it will be false for all subscription scheme in freshland
				);
			}
		}
		return $subscription_options;
	}
	
	public function set_cart_session_by_order_id( $request ) {
		
		$session_id = $request['session_id'];
		$order_id = $request['order_id'];	
		// Get the order
		$order = wc_get_order( $order_id );
		var_dump('here');

		if ( ! $order ) {
			return;
		}
var_dump('here');
		// Access the WooCommerce session handler
		$session_handler = WC()->session;
		
		

		// Get the session data for the specified session ID
		$session_data = $session_handler->get_session( $session_id );

		if ( ! $session_data ) {
			return;
		}

		// Unset the current session to avoid conflicts
		$session_handler->destroy_session();
		
		WC()->cart->empty_cart();
var_dump('here1');
		// Clear the cart data for the specific session ID
		$session_handler->set_customer_cart_cookie( true );
		$session_handler->set( 'cart', array() );
		
		// Loop through the order items and add them to the cart
		foreach ( $order->get_items() as $item_id => $item ) {
			$product_id = $item->get_product_id();
			$quantity = $item->get_quantity();
			$_wcsatt_scheme = $item->get_meta( '_wcsatt_scheme', true );
			$cart_item_data = array();
			if($_wcsatt_scheme){
				$cart_item_data['wcsatt_data'] = array(
					'active_subscription_scheme' => $_wcsatt_scheme
				);
			}

			// Add item to the cart
			WC()->cart->add_to_cart( $product_id, $quantity, 0, array(), $cart_item_data );
		}

		// Apply coupons from the order
		$coupons = $order->get_coupon_codes();
		foreach ( $coupons as $coupon_code ) {
			WC()->cart->apply_coupon( $coupon_code );
		}

		// Set shipping methods and costs
		$shipping_methods = $order->get_shipping_methods();
		foreach ( $shipping_methods as $shipping_item_id => $shipping_item ) {
			$shipping_method_id = $shipping_item->get_method_id();
			$shipping_total = $shipping_item->get_total();
			WC()->session->set( 'chosen_shipping_methods', array( $shipping_method_id ) );
			WC()->session->set( 'shipping_total', $shipping_total );
		}
var_dump('here1');
		// Set the billing and shipping addresses
		WC()->customer->set_props( array(
			'billing_address_1' => $order->get_billing_address_1(),
			'billing_address_2' => $order->get_billing_address_2(),
			'billing_city'      => $order->get_billing_city(),
			'billing_postcode'  => $order->get_billing_postcode(),
			'billing_country'   => $order->get_billing_country(),
			'billing_state'     => $order->get_billing_state(),
			'billing_email'     => $order->get_billing_email(),
			'billing_phone'     => $order->get_billing_phone(),
			'shipping_address_1'=> $order->get_shipping_address_1(),
			'shipping_address_2'=> $order->get_shipping_address_2(),
			'shipping_city'     => $order->get_shipping_city(),
			'shipping_postcode' => $order->get_shipping_postcode(),
			'shipping_country'  => $order->get_shipping_country(),
			'shipping_state'    => $order->get_shipping_state(),
		) );

		// Save the cart session
		WC()->cart->calculate_totals();
		WC()->cart->set_session();
		
		var_dump('here2');
		
		// Cancel and delete the order if it is not completed
		if ( 'completed' !== $order->get_status() ) {
			 // Delete the user only if no one is logged in and the order is associated with a subscription.
			 var_dump(is_user_logged_in());
			 var_dump(wcs_order_contains_subscription( $order_id ));
			if ( ! is_user_logged_in() && wcs_order_contains_subscription( $order_id ) ) {
				$order->update_status( 'cancelled', __( 'Order cancelled and restored to cart.', 'woocommerce' ) );
				wp_delete_post( $order_id, true );
				// Get the customer/user ID associated with the order.
				$user_id = $order->get_customer_id();
				var_dump($user_id);

				// Only delete if a valid user is found.
				if ( $user_id && get_userdata( $user_id ) ) {
					// Ensure that the wp_delete_user() function is available.
					if ( ! function_exists( 'wp_delete_user' ) ) {
						require_once ABSPATH . 'wp-admin/includes/user.php';
					}
					wp_delete_user( $user_id );
				}
			}
		}
	}
	
	public function get_payment_gateways( $request ) {
        // Get available payment gateways
        //$available_gateways0 = WC()->payment_gateways;
        $available_gateways = WC()->payment_gateways->get_available_payment_gateways();
		
		/* var_dump($available_gateways0);
		var_dump($available_gateways); */
        $gateways = array();

        foreach ( $available_gateways as $gateway ) {
			if("bacs" == $gateway->id) continue;
            $gateways[] = array(
                'id' => $gateway->id,
                'title' => html_entity_decode($gateway->get_title()),
                'description' => $gateway->get_description(),
                'enabled' => $gateway->enabled,
            );
        }
	/* 	$stripe = new WC_Payment_Gateway_CC();

		$stripe->id = 'stripe';
		$stripe->title = 'Betal med kreditkort';
		$stripe->description = 'Pay with your credit card via Stripe.';
		$stripe->enabled = 'yes';
		$gateways[] = $stripe; */

        return new WP_REST_Response( $gateways, 200 );
    }
	
	
	public function set_cart_customer( $request ) {
		$customer_id = get_current_user_id();
		//var_dump($customer_id);
		if( !$customer_id ) {
			$customer_id = WC()->session->generate_customer_id();
			WC()->session->set_customer_id($customer_id);
			WC()->customer = new WC_Customer($customer_id);
			WC()->customer->set_email($request['email']);
		} else {
			$current_user = wp_get_current_user();
			WC()->session->set_customer_id($customer_id);
			WC()->customer = new WC_Customer($customer_id);
			WC()->customer->set_email($request['email']);
		}
		// Save the customer data
		WC()->customer->save();

		return new WP_REST_Response( array(
			'message' => __( 'Cart customer set successfully.', 'cart-rest-api-for-woocommerce' ),
		), 200 );
	}
	
	public function get_field_from_session($key) {
		if (WC()->session->__isset($key)) {
			return WC()->session->__get($key);
		}
		return false;
	}
	
	public function set_customer_details( $request ) {
		
		
		$billing_details = $request['billing'];
		$shipping_details = $request['shipping'];
		$personal_details = isset( $request['personal'] ) ? $request['personal'] : array();
		
		WC()->customer = new WC_Customer(get_current_user_id(), true);
		
		WC()->customer->set_billing_location( $billing_details['country'], $billing_details['state'], $billing_details['postcode'], $billing_details['city'] );
		WC()->customer->set_shipping_location( $shipping_details['country'], $shipping_details['state'], $shipping_details['postcode'], $shipping_details['city'] );
		WC()->customer->set_billing_first_name( $billing_details['first_name'] );
		WC()->customer->set_billing_last_name( $billing_details['last_name'] );
		WC()->customer->set_billing_address( $billing_details['address_1'] );
		WC()->customer->set_billing_address_2( 'address_2', 'billing', $billing_details['address_2'] );
		
		WC()->customer->save();

		return new WP_REST_Response( array(
			'message' => __( 'Customer details set successfully.', 'cart-rest-api-for-woocommerce' ),
		), 200 );
	}
	
	
	public function add_order_from_cart( $request = array() ) {
		try {
			WC()->cart->calculate_totals();
			// Retrieve billing and shipping details from the cart
			$billing_details = WC()->customer->get_billing();
			$shipping_details = WC()->customer->get_shipping();

			$payment_method = sanitize_text_field( $request['payment_method'] );
			$payment_method_title = isset( $request['payment_method_title'] ) ? sanitize_text_field( $request['payment_method_title'] ) : '';
			$set_paid = isset( $request['set_paid'] ) ? (bool) $request['set_paid'] : false;
			$meta_data = isset( $request['meta_data'] ) ? $request['meta_data'] : array();

			// Create the order and set details
			$order = wc_create_order();
			$customer_id = WC()->session->get_customer_id();
			if (!$customer_id) {
				$customer_id = 0; // Guest user
			}
			
			/* if( $this->cart_contains_subscription() && !$customer_id ){
				WC()->customer = new WC_Customer(WC()->session->get_customer_id());
				$customer_data = WC()->customer->get_data();
				$user_email = $customer_data['email'];
				$user_name = $customer_data['first_name'] . ' ' . $customer_data['last_name'];
				$user_password = wp_generate_password();
				
				$billing_details = $customer_data['billing'];
				$shipping_details = $customer_data['shipping'];

				$user_id = wp_create_user($user_email, $user_password, $user_email);
				
				if (is_wp_error($user_id)) {
					return new WP_Error('registration_error', __('Could not register user.', 'cart-rest-api-for-woocommerce'), array('status' => 500));
				}

				// Set customer ID to the newly registered user
				$customer_id = $user_id;

				// Update user meta with billing and shipping details
				foreach ($billing_details as $key => $value) {
					update_user_meta($user_id, 'billing_' . $key, $value);
				}
				foreach ($shipping_details as $key => $value) {
					update_user_meta($user_id, 'shipping_' . $key, $value);
				}
				
				wp_set_current_user($user_id);
				
				WC()->session->set_customer_id(WC()->session->generate_customer_id());
				WC()->session->set_customer_cart_cookie(true);
				
				$secret_key = defined('JWT_AUTH_SECRET_KEY') ? JWT_AUTH_SECRET_KEY : 'F3Xm43?O~e]}>P6M1;R|eh{ kVYf^iC) PH82JLh%L!,eVPH/q D5@1CU+a<NdNQ'; // Replace with your own secret key
				$issued_at = time();
				$expiration_time = $issued_at + (60 * 60); // jwt valid for 1 hour
				$payload = array(
					'iss' => get_bloginfo('url'),
					'iat' => $issued_at,
					'exp' => $expiration_time,
					'data' => array(
						'user' => array(
							'id' => $user_id
						)
					)
				);

				$token = JWT::encode($payload, $secret_key, 'HS256');

				// Set the new user as the customer
				WC()->customer = new WC_Customer($user_id);
			} */
			
			$order->set_customer_id($customer_id);
			$order->set_address( $billing_details, 'billing' );
			$order->set_address( $shipping_details, 'shipping' );
			$order->set_payment_method( $payment_method );
			$order->set_payment_method_title( $payment_method_title );

			// Add meta data and cart items
			if ( ! empty( $meta_data ) ) {
				foreach ( $meta_data as $meta_key => $meta_value ) {
					$order->update_meta_data( $meta_key, $meta_value );
				}
			}
			
			// Add items from the cart
			foreach (WC()->cart->get_cart() as $cart_item_key => $values) {
				$product = $values['data'];
				$item = new WC_Order_Item_Product();
				$item->set_product($product);
				$item->set_quantity($values['quantity']);
				$item->set_subtotal($values['line_subtotal']);
				$item->set_total($values['line_total']);

				// Add subscription scheme if applicable
				if (isset($values['wcsatt_data']['active_subscription_scheme'])) {
					$scheme_key = $values['wcsatt_data']['active_subscription_scheme'];
					$scheme = WCS_ATT_Product_Schemes::get_subscription_scheme($product, 'object', $scheme_key);

					if ($scheme) {
						$item->add_meta_data('_subscription_scheme', $scheme_key);
						$item->add_meta_data('_subscription_period', $scheme->get_period());
						$item->add_meta_data('_subscription_interval', $scheme->get_interval());
						$item->add_meta_data('_subscription_trial_length', $scheme->get_trial_length());
						$item->add_meta_data('_subscription_trial_period', $scheme->get_trial_period());
					}
				}

				// Add item to order
				$order->add_item($item);
			}
			
			// Handle shipping methods
			WC_Subscriptions_Cart::calculate_subscription_totals(WC()->cart->get_total( 'total' ), WC()->cart);
		
			$recurring_carts = WC()->cart->recurring_carts;
			$recurring_shipping_packages = array();
			foreach ( $recurring_carts as $recurring_cart_key => $recurring_cart ) {
				$recurring_shipping_packages[$recurring_cart_key] = $recurring_cart->get_shipping_packages();
			}
			$chosen_shipping_methods = WC()->session->get('chosen_shipping_methods', array());
			
			// Get shipping details
			$shipping_packages = WC()->cart->get_shipping_packages();
			$shipping_packages = array_merge( WC()->cart->get_shipping_packages(), $recurring_shipping_packages );
			
			//var_dump($chosen_shipping_methods);
			

			foreach ($shipping_packages as $package_id => $package) {
				$shipping_rates = WC()->shipping->calculate_shipping_for_package( $package );

				if ( isset( $shipping_rates['rates'] ) && ! empty( $shipping_rates['rates'] ) ) {
					foreach ( $shipping_rates['rates'] as $rate_id => $rate ) {
						if ($rate_id === $chosen_shipping_methods[$package_id]) {
							$item = new WC_Order_Item_Shipping();
							$item->set_method_title($rate->get_label());
							$item->set_method_id($rate->get_id());
							$item->set_total($rate->get_cost());
							$item->set_taxes(array('total' => $rate->get_taxes()));
							$order->add_item($item);
						}
					}
				}
			}

			// Calculate totals
			$order->calculate_totals();

			// Set status and save order
			if ($set_paid) {
				$order->set_status('completed');
			} else {
				$order->set_status('pending');
			}

			$order->save();

			// Trigger WooCommerce Subscriptions hooks to process subscriptions
			do_action('woocommerce_checkout_order_processed', $order->get_id(), $request->get_params(), $order);

			// Clear the cart
			WC()->cart->empty_cart();

			return new WP_REST_Response(array(
				'order_id' => $order->get_id(),
				'message' => __('Order created successfully.', 'cart-rest-api-for-woocommerce'),
			), 200);
					
		} catch ( CoCart_Data_Exception $e ) {
			return CoCart_Response::get_error_response( $e->getErrorCode(), $e->getMessage(), $e->getCode(), $e->getAdditionalData() );
		}
	} // END add_to_cart()
	
	public function cart_contains_subscription() {
		if (class_exists('WC_Subscriptions_Cart')) {
			return WC_Subscriptions_Cart::cart_contains_subscription();
		}
		return false;
	}
	
	
	public function set_shipping_method($request) {
		$shipping_method = sanitize_text_field($request['shipping_method']);
		$package_id = sanitize_text_field($request['package_id']);

		// Get the current chosen shipping methods from the session
		$chosen_shipping_methods = WC()->session->get('chosen_shipping_methods', array());

		// Get the shipping packages from the cart
		$packages = WC()->cart->get_shipping_packages();
		
		
		
		WC_Subscriptions_Cart::calculate_subscription_totals(WC()->cart->get_total( 'total' ), WC()->cart);
		
		$recurring_carts = WC()->cart->recurring_carts;
		$recurring_shipping_packages = array();
		foreach ( $recurring_carts as $recurring_cart_key => $recurring_cart ) {
			$recurring_shipping_packages[$recurring_cart_key] = $recurring_cart->get_shipping_packages();
		}
		
		$packages = array_merge( WC()->cart->get_shipping_packages(), $recurring_shipping_packages );

		// Check if the package ID is valid
		if (!isset($packages[$package_id])) {
			return new WP_Error('wc_cart_rest_invalid_package_id', __('Invalid package ID.', 'cart-rest-api-for-woocommerce'), array('status' => 400));
		}

		$package = $packages[$package_id];
		
		$shipping_added = false;

		$shipping_rates = WC()->shipping->calculate_shipping_for_package( $package );


		if ( isset( $shipping_rates['rates'] ) && ! empty( $shipping_rates['rates'] ) ) {
			foreach ( $shipping_rates['rates'] as $rate_id => $rate ) {
				if ($rate_id === $shipping_method) {
					$chosen_shipping_methods[$package_id] = $rate_id;
					WC()->session->set('chosen_shipping_methods', $chosen_shipping_methods);
					WC()->cart->calculate_shipping();
					WC()->cart->calculate_totals();
					$shipping_added = true;
					break;
				}
			}
		}

		if ($shipping_added) {
			return new WP_REST_Response(array(
				'message' => __('Shipping method set successfully.', 'cart-rest-api-for-woocommerce'),
				'chosen_methods' => $chosen_shipping_methods
			), 200);
		} else {
			return new WP_Error('wc_cart_rest_invalid_shipping_method', __('Invalid shipping method.', 'cart-rest-api-for-woocommerce'), array(
			'status' => 400,
			'package' => $package,
			'shipping_rates' => $shipping_rates
			));
		}
	}
	
	public function get_shipping_methods( $request ) {
		
		WC()->customer = new WC_Customer(get_current_user_id(), true);
		
		// Set the shipping destination details
		$country  = ! empty( $request['country'] ) ? sanitize_text_field( $request['country'] ) : WC()->customer->get_shipping_country();
		$state    = ! empty( $request['state'] ) ? sanitize_text_field( $request['state'] ) : WC()->customer->get_shipping_state();
		$postcode = ! empty( $request['postcode'] ) ? sanitize_text_field( $request['postcode'] ) : WC()->customer->get_shipping_postcode();
		$city     = ! empty( $request['city'] ) ? sanitize_text_field( $request['city'] ) : WC()->customer->get_shipping_city();

		WC()->customer->set_shipping_country( $country );
		WC()->customer->set_shipping_state( $state );
		WC()->customer->set_shipping_postcode( $postcode );
		WC()->customer->set_shipping_city( $city );

		// Calculate shipping packages
		WC()->cart->calculate_shipping();

		$packages = WC()->cart->get_shipping_packages();
		$available_methods = array();

		foreach ( $packages as $package_id => $package ) {
			$available_methods[ $package_id ] = array(
				'package_details' => $package,
				'methods' => array()
			);

			$shipping_rates = WC()->shipping->calculate_shipping_for_package( $package );

			if ( isset( $shipping_rates['rates'] ) && ! empty( $shipping_rates['rates'] ) ) {
				foreach ( $shipping_rates['rates'] as $rate_id => $rate ) {
					$available_methods[ $package_id ]['methods'][ $rate_id ] = array(
						'id' => $rate->get_id(),
						'label' => $rate->get_label(),
						'cost' => $rate->get_cost(),
						'taxes' => $rate->get_taxes(),
						'package_id' => $package_id,
					);
				}
			}
		}

		return new WP_REST_Response( $available_methods, 200 );
	}
	
	public function apply_coupon( $request ) {
		$coupon_code = sanitize_text_field( $request['coupon_code'] );

		if ( ! WC()->cart->has_discount( $coupon_code ) ) {
			WC()->cart->add_discount( $coupon_code );
			
			if ( WC()->cart->has_discount( $coupon_code ) ) {
				return new WP_REST_Response( array( 'message' => __( 'Coupon applied successfully.', 'cart-rest-api-for-woocommerce' ) ), 200 );
			} else {
				return new WP_Error( 'wc_cart_rest_apply_coupon_failed', __( 'Failed to apply coupon. Please check the coupon code.', 'cart-rest-api-for-woocommerce' ), array( 'status' => 500 ) );
			}
		} else {
			return new WP_Error( 'wc_cart_rest_coupon_already_applied', __( 'Coupon already applied.', 'cart-rest-api-for-woocommerce' ), array( 'status' => 400 ) );
		}
	}

	public function remove_coupon( $request ) {
		$coupon_code = sanitize_text_field( $request['coupon_code'] );

		if ( WC()->cart->has_discount( $coupon_code ) ) {
			WC()->cart->remove_coupon( $coupon_code );
			
			// Recalculate the cart totals to remove the associated discount
			WC()->cart->calculate_totals();
			
			if ( ! WC()->cart->has_discount( $coupon_code ) ) {
				return new WP_REST_Response( array( 'message' => __( 'Coupon removed successfully.', 'cart-rest-api-for-woocommerce' ) ), 200 );
			} else {
				return new WP_Error( 'wc_cart_rest_remove_coupon_failed', __( 'Failed to remove coupon. Please try again.', 'cart-rest-api-for-woocommerce' ), array( 'status' => 500 ) );
			}
		} else {
			return new WP_Error( 'wc_cart_rest_coupon_not_found', __( 'Coupon not found in cart.', 'cart-rest-api-for-woocommerce' ), array( 'status' => 404 ) );
		}
	}

	public function get_cart( $data = array() ) {
		$cart = WC()->cart->get_cart();

		if ( $this->get_cart_contents_count( array( 'return' => 'numeric' ) ) <= 0 ) {
			return new WP_REST_Response( array(), 200 );
		}

		$show_thumb = ! empty( $data['thumb'] ) ? $data['thumb'] : false;

		foreach ( $cart as $item_key => $cart_item ) {
			$_product = apply_filters( 'wc_cart_rest_api_cart_item_product', $cart_item['data'], $cart_item, $item_key );

			// Adds the product name as a new variable.
			$cart[$item_key]['product_name'] = $_product->get_name();

			// If main product thumbnail is requested then add it to each item in cart.
			if ( $show_thumb ) {
				$thumbnail_id = apply_filters( 'wc_cart_rest_api_cart_item_thumbnail', $_product->get_image_id(), $cart_item, $item_key );

				$thumbnail_src = wp_get_attachment_image_src( $thumbnail_id, 'woocommerce_thumbnail' );

				// Add main product image as a new variable.
				$cart[$item_key]['product_image'] = esc_url( $thumbnail_src[0] );
			}
		}

		return new WP_REST_Response( $cart, 200 );
	} // END get_cart()


	public function get_cart_contents_count( $data = array() ) {
		$count = WC()->cart->get_cart_contents_count();

		$return = ! empty( $data['return'] ) ? $data['return'] : '';

		if ( $return != 'numeric' && $count <= 0 ) {
			return new WP_REST_Response( __( 'There are no items in the cart!', 'cart-rest-api-for-woocommerce' ), 200 );
		}

		return $count;
	} // END get_cart_contents_count()

	public function clear_cart() {
		WC()->cart->empty_cart();
		WC()->session->set('cart', array()); // Empty the session cart data

		if ( WC()->cart->is_empty() ) {
			return new WP_REST_Response( __( 'Cart is cleared.', 'cart-rest-api-for-woocommerce' ), 200 );
		} else {
			return new WP_Error( 'wc_cart_rest_clear_cart_failed', __( 'Clearing the cart failed!', 'cart-rest-api-for-woocommerce' ), array( 'status' => 500 ) );
		}
	} // END clear_cart()

	protected function validate_product_id( $product_id ) {
		if ( $product_id <= 0 ) {
			return new WP_Error( 'wc_cart_rest_product_id_required', __( 'Product ID number is required!', 'cart-rest-api-for-woocommerce' ), array( 'status' => 500 ) );
		}

		if ( ! is_numeric( $product_id ) ) {
			return new WP_Error( 'wc_cart_rest_product_id_not_numeric', __( 'Product ID must be numeric!', 'cart-rest-api-for-woocommerce' ), array( 'status' => 500 ) );
		}
	} // END validate_product_id()


	protected function validate_quantity( $quantity ) {
		if ( ! is_numeric( $quantity ) ) {
			return new WP_Error( 'wc_cart_rest_quantity_not_numeric', __( 'Quantity must be numeric!', 'cart-rest-api-for-woocommerce' ), array( 'status' => 500 ) );
		}
	} // END validate_quantity()


	protected function validate_product( $product_id = null, $quantity = 1 ) {
		$this->validate_product_id( $product_id );

		$this->validate_quantity( $quantity );
	} // END validate_product()


	protected function has_enough_stock( $current_data = array(), $quantity = 1 ) {
		$product_id      = ! isset( $current_data['product_id'] ) ? 0 : absint( $current_data['product_id'] );
		$variation_id    = ! isset( $current_data['variation_id'] ) ? 0 : absint( $current_data['variation_id'] );
		$current_product = wc_get_product( $variation_id ? $variation_id : $product_id );

		$quantity = absint( $quantity );

		if ( ! $current_product->has_enough_stock( $quantity ) ) {
			return new WP_Error( 'wc_cart_rest_not_enough_in_stock', sprintf( __( 'You cannot add that amount of &quot;%1$s&quot; to the cart because there is not enough stock (%2$s remaining).', 'cart-rest-api-for-woocommerce' ), $current_product->get_name(), wc_format_stock_quantity_for_display( $current_product->get_stock_quantity(), $current_product ) ), array( 'status' => 500 ) );
		}

		return true;
	} // END has_enough_stock()


	public function add_to_cart($request) {
		$product_id = !isset($request['product_id']) ? 0 : absint($request['product_id']);
		$quantity = !isset($request['quantity']) ? 1 : absint($request['quantity']);
		$variation_id = !isset($request['variation_id']) ? 0 : absint($request['variation_id']);
		$variation = !isset($request['variation']) ? array() : $request['variation'];
		$cart_item_data = !isset($request['cart_item_data']) ? array() : $request['cart_item_data'];
		$subscription_scheme = !isset($request['subscription_scheme']) ? '' : sanitize_text_field($request['subscription_scheme']);

		// Validate the product
		$this->validate_product($product_id, $quantity);

		$product_data = wc_get_product($variation_id ? $variation_id : $product_id);

		if (!$product_data || 'trash' === $product_data->get_status()) {
			return new WP_Error('wc_cart_rest_product_does_not_exist', __('Warning: This product does not exist!', 'cart-rest-api-for-woocommerce'), array('status' => 500));
		}

		// Force quantity to 1 if sold individually and check for existing item in cart
		if ($product_data->is_sold_individually()) {
			$quantity = 1;

			foreach (WC()->cart->get_cart() as $cart_item_key => $values) {
				$_product = $values['data'];
				if ($_product->get_id() === $product_id) {
					return new WP_Error('wc_cart_rest_product_sold_individually', sprintf(__('You cannot add another "%s" to your cart.', 'cart-rest-api-for-woocommerce'), $product_data->get_name()), array('status' => 500));
				}
			}
		}

		// Product is purchasable check
		if (!$product_data->is_purchasable()) {
			return new WP_Error('wc_cart_rest_cannot_be_purchased', __('Sorry, this product cannot be purchased.', 'cart-rest-api-for-woocommerce'), array('status' => 500));
		}

		// Stock check
		if (!$product_data->is_in_stock()) {
			return new WP_Error('wc_cart_rest_product_out_of_stock', sprintf(__('You cannot add "%s" to the cart because the product is out of stock.', 'cart-rest-api-for-woocommerce'), $product_data->get_name()), array('status' => 500));
		}

		// Stock check - this time accounting for what's already in-cart.
		if ($product_data->managing_stock()) {
			$products_qty_in_cart = WC()->cart->get_cart_item_quantities();
			if (isset($products_qty_in_cart[$product_data->get_stock_managed_by_id()]) && ! $product_data->has_enough_stock($products_qty_in_cart[$product_data->get_stock_managed_by_id()] + $quantity)) {
				return new WP_Error(
					'wc_cart_rest_not_enough_stock_remaining',
					sprintf(
						__('You cannot add that amount to the cart — we have %1$s in stock and you already have %2$s in your cart.', 'cart-rest-api-for-woocommerce'),
						wc_format_stock_quantity_for_display($product_data->get_stock_quantity(), $product_data),
						wc_format_stock_quantity_for_display($products_qty_in_cart[$product_data->get_stock_managed_by_id()], $product_data)
					),
					array('status' => 500)
				);
			}
		}

		// Add subscription details to cart item data if provided
		if (!empty($subscription_scheme)) {
			$cart_item_data['wcsatt_data'] = array(
				'active_subscription_scheme' => $subscription_scheme
			);
		}

		// Add item to cart
		$item_key = WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variation, $cart_item_data);
		
		WC()->cart->calculate_shipping();
		WC()->cart->calculate_totals();
		
		if (!empty($subscription_scheme)) {
			WC_Subscriptions_Cart::calculate_subscription_totals(WC()->cart->get_total( 'total' ), WC()->cart);
		}
		
		//var_dump(WC()->cart->recurring_carts);

		if ($item_key) {
			$data = $this->get_complete_cart_details();

			do_action('wc_cart_rest_add_to_cart', $item_key, $data);

			if (is_array($data)) {
				return new WP_REST_Response($data, 200);
			}
		} else {
			return new WP_Error('wc_cart_rest_cannot_add_to_cart', sprintf(__('You cannot add "%s" to your cart.', 'cart-rest-api-for-woocommerce'), $product_data->get_name()), array('status' => 500));
		}
	}

	public function remove_item( $data = array() ) {
		$cart_item_key = ! isset( $data['cart_item_key'] ) ? '0' : wc_clean( $data['cart_item_key'] );

		if ( $cart_item_key != '0' ) {
			if ( WC()->cart->remove_cart_item( $cart_item_key ) ) {
				return new WP_REST_Response( __( 'Item has been removed from cart.', 'cart-rest-api-for-woocommerce' ), 200 );
			} else {
				return new WP_ERROR( 'wc_cart_rest_can_not_remove_item', __( 'Unable to remove item from cart.', 'cart-rest-api-for-woocommerce' ), array( 'status' => 500 ) );
			}
		} else {
			return new WP_ERROR( 'wc_cart_rest_cart_item_key_required', __( 'Cart item key is required!', 'cart-rest-api-for-woocommerce' ), array( 'status' => 500 ) );
		}
	} // END remove_item()

	public function restore_item( $data = array() ) {
		$cart_item_key = ! isset( $data['cart_item_key'] ) ? '0' : wc_clean( $data['cart_item_key'] );

		if ( $cart_item_key != '0' ) {
			if ( WC()->cart->restore_cart_item( $cart_item_key ) ) {
				return new WP_REST_Response( __( 'Item has been restored to the cart.', 'cart-rest-api-for-woocommerce' ), 200 );
			} else {
				return new WP_ERROR( 'wc_cart_rest_can_not_restore_item', __( 'Unable to restore item to the cart.', 'cart-rest-api-for-woocommerce' ), array( 'status' => 500 ) );
			}
		} else {
			return new WP_ERROR( 'wc_cart_rest_cart_item_key_required', __( 'Cart item key is required!', 'cart-rest-api-for-woocommerce' ), array( 'status' => 500 ) );
		}
	} // END restore_item()

	public function update_item( $data = array() ) {
		$cart_item_key = ! isset( $data['cart_item_key'] ) ? '0' : wc_clean( $data['cart_item_key'] );
		$quantity      = ! isset( $data['quantity'] ) ? 1 : absint( $data['quantity'] );

		// Allows removing of items if quantity is zero should for example the item was with a product bundle.
		if ( $quantity === 0 ) {
			return $this->remove_item( $data );
		}

		$this->validate_quantity( $quantity );

		if ( $cart_item_key != '0' ) {
			$current_data = WC()->cart->get_cart_item( $cart_item_key ); // Fetches the cart item data before it is updated.

			$this->has_enough_stock( $current_data, $quantity ); // Checks if the item has enough stock before updating.

			if ( WC()->cart->set_quantity( $cart_item_key, $quantity ) ) {

				$new_data = WC()->cart->get_cart_item( $cart_item_key );

				$product_id   = ! isset( $new_data['product_id'] ) ? 0 : absint( $new_data['product_id'] );
				$variation_id = ! isset( $new_data['variation_id'] ) ? 0 : absint( $new_data['variation_id'] );

				$product_data = wc_get_product( $variation_id ? $variation_id : $product_id );

				if ( $quantity != $new_data['quantity'] ) {
					do_action( 'wc_cart_rest_item_quantity_changed', $cart_item_key, $new_data );
				}

				// Return response based on product quantity increment.
				if ( $quantity > $current_data['quantity'] ) {
					return new WP_REST_Response( sprintf( __( 'The quantity for "%1$s" has increased to "%2$s".', 'cart-rest-api-for-woocommerce' ), $product_data->get_name(), $new_data['quantity'] ), 200 );
				} else if ( $quantity < $current_data['quantity'] ) {
					return new WP_REST_Response( sprintf( __( 'The quantity for "%1$s" has decreased to "%2$s".', 'cart-rest-api-for-woocommerce' ), $product_data->get_name(), $new_data['quantity'] ), 200 );
				} else {
					return new WP_REST_Response( sprintf( __( 'The quantity for "%s" has not changed.', 'cart-rest-api-for-woocommerce' ), $product_data->get_name() ), 200 );
				}
			} else {
				return new WP_ERROR( 'wc_cart_rest_can_not_update_item', __( 'Unable to update item quantity in cart.', 'cart-rest-api-for-woocommerce' ), array( 'status' => 500 ) );
			}
		} else {
			return new WP_ERROR( 'wc_cart_rest_cart_item_key_required', __( 'Cart item key is required!', 'cart-rest-api-for-woocommerce' ), array( 'status' => 500 ) );
		}
	} // END update_item()

	public function calculate_totals() {
		if ( $this->get_cart_contents_count( array( 'return' => 'numeric' ) ) <= 0 ) {
			return new WP_REST_Response( __( 'No items in cart to calculate totals.', 'cart-rest-api-for-woocommerce' ), 200 );
		}

		WC()->cart->calculate_totals();

		return new WP_REST_Response( __( 'Cart totals have been calculated.', 'cart-rest-api-for-woocommerce' ), 200 );
	} // END calculate_totals()

	public function get_totals() {
		$totals = WC()->cart->get_totals();

		return $totals;
	} // END get_totals()
	
	public function get_complete_cart_details() {
		// Get cart items
		$cart_items = WC()->cart->get_cart();
		
		$recurring_shipping_packages = array();
		if(class_exists('WC_Subscriptions_Cart')){
			WC_Subscriptions_Cart::calculate_subscription_totals(WC()->cart->get_total( 'total' ), WC()->cart);
			$recurring_carts = WC()->cart->recurring_carts;
			foreach ( $recurring_carts as $recurring_cart_key => $recurring_cart ) {
				$recurring_shipping_packages[$recurring_cart_key] = $recurring_cart->get_shipping_packages();
			}
		}
		
		
		//var_dump(WC()->cart->recurring_carts);

		// Get cart totals
		$cart_totals = WC()->cart->get_totals();

		// Get applied coupons
		$applied_coupons = WC()->cart->get_applied_coupons();
		
		// Get the chosen shipping methods
		$chosen_shipping_methods = WC()->session->get('chosen_shipping_methods', array());

		// Get shipping details
		$shipping_packages = WC()->cart->get_shipping_packages();
		$shipping_packages = array_merge( WC()->cart->get_shipping_packages(), $recurring_shipping_packages );

		$shipping_total = WC()->cart->get_shipping_total();
		$available_methods = array();
		foreach ( $shipping_packages as $package_id => $package ) {
			$available_methods[ $package_id ] = array(
				'package_details' => $package,
				'methods' => array()
			);

			$shipping_rates = WC()->shipping->calculate_shipping_for_package( $package );

			if ( isset( $shipping_rates['rates'] ) && ! empty( $shipping_rates['rates'] ) ) {
				foreach ( $shipping_rates['rates'] as $rate_id => $rate ) {
					$available_methods[ $package_id ]['methods'][ $rate_id ] = array(
						'id' => $rate->get_id(),
						'label' => $rate->get_label(),
						'cost' => $rate->get_cost(),
						'taxes' => $rate->get_taxes(),
						'package_id' => $package_id,
						'selected_method' => isset($chosen_shipping_methods[$package_id]) && $chosen_shipping_methods[$package_id] === $rate_id
					);
				}
			}
		}
		
		$customer_keys = array();
		
		$customer_keys['billing'] = array("first_name", "last_name", "company", "address_1", "address_2", "city", "state", "postcode", "country", "email", "phone");
			
		$customer_keys['shipping'] = array("first_name", "last_name", "company", "address_1", "address_2", "city", "state", "postcode", "country");
		
		$customer_keys['personal'] = array("first_name", "last_name", "email", "phone");

		$customer_data = array();
		foreach( $customer_keys as $filed_key => $ckey ){
			if( 'billing' == $filed_key || 'shipping' == $filed_key ){
				$customer_data[$filed_key] = array();
			}
			foreach( $ckey as $key ){
				if( isset( $customer_data[$filed_key] ) ){
					$customer_data[$filed_key][$key] = $this->get_field_from_session($filed_key.'_'.$key);
				}else{
					$customer_data[$key] = $this->get_field_from_session($key);
				}
			}			
		}
		
		//WC()->customer = new WC_Customer(WC()->session->get_customer_id(), true);
		// Assemble the complete cart details
		$complete_cart_details = array(
			'items' => array(),
			'customer_id' => WC()->session->get_customer_id(),
			'customer' => WC()->customer->get_data(),
			'delivery_dates' => $this->get_delivery_days(),
			'totals' => $cart_totals,
			'coupons' => $applied_coupons,
			'currency' => get_woocommerce_currency_symbol(),
			'shipping' => array(
				'packages' => $available_methods,
				'total' => $shipping_total,
			)
		);

		// Process each cart item
		foreach ($cart_items as $cart_item_key => $cart_item) {
			$product = $cart_item['data'];
			$thumbnail_id = get_post_thumbnail_id($product->get_id());
					
			// Get the URL of the thumbnail image
			$thumbnail_url = ''; // Initialize the variable to avoid errors in case there's no thumbnail
			if ($thumbnail_id) {
				$thumbnail_data = wp_get_attachment_image_src($thumbnail_id, array(340, 240));
				if (!empty($thumbnail_data) && isset($thumbnail_data[0])) {
					$thumbnail_url = $thumbnail_data[0]; // URL of the thumbnail
				}
			}
			$item_details = array(
				'item_key' => $cart_item_key,
				'product_id' => $cart_item['product_id'],
				'variation_id' => $cart_item['variation_id'],
				'quantity' => $cart_item['quantity'],
				'line_subtotal' => $cart_item['line_subtotal'],
				'line_subtotal_tax' => $cart_item['line_subtotal_tax'],
				'line_total' => $cart_item['line_total'],
				'line_tax' => $cart_item['line_tax'],
				'product_name' => $product->get_name(),
				'product_price' => $product->get_price(),
				'thumbnail_url' => $thumbnail_url,
				'is_subscription' => false,
			);
			
			if($this->is_product_subscription($cart_item['product_id'])){
				$item_details['is_subscription'] = true;
				$subscription_scheme = isset($cart_item['wcsatt_data']['active_subscription_scheme']) ? $cart_item['wcsatt_data']['active_subscription_scheme'] : false;
				$item_details['subscription_schemes'] = $this->get_product_subscription_schemes($cart_item['product_id'], $subscription_scheme);
			}

			
			$complete_cart_details['items'][] = $item_details;
		}

		return $complete_cart_details;
	}

} // END class
