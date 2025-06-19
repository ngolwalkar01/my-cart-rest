<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class My_Cart_Hooks {
	
    public function __construct() {
        // Register all hooks here
        add_action('woocommerce_checkout_order_processed', array($this, 'send_order_emails'), 10, 3);
    }

    // Example method to send order emails
    public function send_order_emails($order_id, $posted_data, $order) {
        WC()->mailer()->emails['WC_Email_New_Order']->trigger($order_id);
        WC()->mailer()->emails['WC_Email_Customer_Processing_Order']->trigger($order_id);
    }
	
}

new My_Cart_Hooks();