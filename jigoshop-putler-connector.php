<?php
/*
 * Plugin Name: Jigoshop Putler Connector
 * Plugin URI: http://putler.com/connector/jigoshop/
 * Description: Track Jigoshop transactions data with Putler. Insightful reporting that grows your business.
 * Version: 2.1
 * Author: putler, storeapps
 * Author URI: http://putler.com/
 * License: GPL 3.0
*/

add_action( 'plugins_loaded', 'jigoshop_putler_connector_pre_init' );

function jigoshop_putler_connector_pre_init () {

	// Simple check for Jigoshop being active...
	if ( class_exists('jigoshop') ) {

		// Init admin menu for settings etc if we are in admin
		if ( is_admin() ) {
			jigoshop_putler_connector_init();
		} 
                
		// If configuration not done, can't track anything...
		if ( null != get_option('putler_connector_settings', null) ) {
                    
                    // On these events, send order data to Putler
                    if ( is_admin() && ( !defined( 'DOING_AJAX' ) || !DOING_AJAX ) ) {
                        add_action( 'jigoshop_process_shop_order_meta', 'jigoshop_putler_connector_order_updated', 10 );
                    } else {
                        // On these events, send order data to Putler
			$order_events = array('pending', 'failed', 'refunded', 'cancelled', 'on-hold', 'processing', 'completed');
			foreach ($order_events as $status) {
				add_action( 'order_status_'.$status, 'jigoshop_putler_connector_post_order' );
			}
                                                
                    }
		}
	}
}

function jigoshop_putler_connector_init() {
	
	include_once 'classes/class.putler-connector.php';
	$GLOBALS['putler_connector'] = Putler_Connector::getInstance();

        include_once 'classes/class.putler-connector-jigoshop.php';
        if ( !isset( $GLOBALS['jigoshop_putler_connector'] ) ) {
            $GLOBALS['jigoshop_putler_connector'] = new Jigoshop_Putler_Connector();
	}
}

function jigoshop_putler_connector_order_updated( $post_id ) {
	if ( get_post_type( $post_id ) === 'shop_order' ) {
		jigoshop_putler_connector_post_order($post_id);
	}
}

function jigoshop_putler_connector_post_order( $order_id ) {
	jigoshop_putler_connector_init();
	if (method_exists($GLOBALS['putler_connector'], 'post_order') ) {
		$GLOBALS['putler_connector']->post_order( $order_id );	
	}
}