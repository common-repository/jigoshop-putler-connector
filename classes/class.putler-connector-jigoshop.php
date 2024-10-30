<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( ! class_exists( 'Jigoshop_Putler_Connector' ) ) {
    
    class Jigoshop_Putler_Connector {
        
        private $name = 'jigoshop';

        public function __construct() {
            add_filter('putler_connector_get_order_count', array( &$this, 'get_order_count') );
            add_filter('putler_connector_get_orders', array( &$this, 'get_orders') );
        }

        public function get_order_count( $count )  {
            global $wpdb;
            $order_count = 0;
            
            $query_to_fetch_order_count = "SELECT COUNT(posts.ID) as id
                                            FROM {$wpdb->prefix}posts AS posts 
                                            WHERE posts.post_type LIKE 'shop_order' 
                                                AND posts.post_status IN ('publish','draft') ";
            
            $order_count_result = $wpdb->get_col( $query_to_fetch_order_count );
            
            if( !empty( $order_count_result ) ) {
                    $order_count = $order_count_result[0];
            }
            
            return $count + $order_count;
        }

        public function get_orders( $params )  {
            global $wpdb;
            
            $jigoshop_options = get_option('jigoshop_options', true);
            $currency = $jigoshop_options['jigoshop_currency'];
            
            //Code to get the last order sent
            
            $cond = '';

            if ( empty($params['order_id']) ) {
                $start_limit = (isset($params[ $this->name ]['start_limit'])) ? $params[ $this->name ]['start_limit'] : 0;
                $batch_limit = (isset($params['limit'])) ? $params['limit'] : 50;    
            } else {
                $start_limit = 0;
                $batch_limit = 1;
                $cond = 'AND posts.ID IN(' .intval($params['order_id']). ')'; 
            }
            
            
            //Code to get all the term_names along with the term_taxonomy_id in an array
            $query_order_status = "SELECT terms.name as order_status,
                                    term_taxonomy.term_taxonomy_id 
                                    FROM {$wpdb->prefix}term_taxonomy AS term_taxonomy
                                    JOIN {$wpdb->prefix}terms AS terms ON (terms.term_id = term_taxonomy.term_id)
                                    WHERE taxonomy LIKE 'shop_order_status'";
                                    
            $results_order_status = $wpdb->get_results( $query_order_status, 'ARRAY_A' );

            $order_status = array();

            foreach ($results_order_status as $results_order_status1) {
                    $order_status[$results_order_status1['term_taxonomy_id']] = $results_order_status1['order_status'];
            }
            
            $query_order_details = "SELECT posts.ID as id,
                                        posts.post_excerpt as order_note,
                                        date_format(posts.post_date_gmt,'%Y-%m-%d %T') AS date,
                                        date_format(posts.post_modified_gmt,'%Y-%m-%d %T') AS modified_date,
                                        term_relationships.term_taxonomy_id AS term_taxonomy_id
                                        FROM {$wpdb->prefix}posts AS posts 
                                            JOIN {$wpdb->prefix}term_relationships AS term_relationships 
                                                ON term_relationships.object_id = posts.ID 
                                        WHERE posts.post_type LIKE 'shop_order' 
                                                AND posts.post_status IN ('publish','draft')
                                                $cond
                                        GROUP BY posts.ID
                                        LIMIT ". $start_limit .",". $batch_limit;

             $results_order_details = $wpdb->get_results( $query_order_details, 'ARRAY_A' );
             $results_order_details_count = $wpdb->num_rows;
                          
             if ( $results_order_details_count > 0 ) {
                 
                 $order_ids = array(); 
                 
                 foreach ( $results_order_details as $results_order_detail ) {
                     $order_ids[] = $results_order_detail['id'];
                 }
                 
                    //Query to get the Order_items
                                     
                    $query_cart_items = "SELECT postmeta.post_id,
                                                postmeta.meta_key AS meta_key,
                                                postmeta.meta_value AS meta_value
                                        FROM {$wpdb->prefix}postmeta AS postmeta
                                        WHERE postmeta.meta_key IN ('order_data', 'order_items')
                                        AND postmeta.post_id IN (". implode(",",$order_ids) .")
                                        GROUP BY postmeta.post_id, meta_key
                                            ";
                    
                    $results_cart_items = $wpdb->get_results ( $query_cart_items, 'ARRAY_A' );
                    
                    $results_cart_items_count = $wpdb->num_rows;

                    $order_items = array();
                    
                    if ( $results_cart_items_count > 0 ) {
                        
                        foreach ( $results_cart_items as $cart_item ) {
                            $order_id = $cart_item['post_id']; 
                            
                            if( !isset( $order_items[$order_id] ) ){
                                $order_items[$order_id] = array();
                                $order_items[$order_id]['tot_qty'] = 0;
                                $order_items[$order_id]['cart_items'] = array();
                                $order_items[$order_id]['order_data'] = array();
                            }
                            
                            if( $cart_item['meta_key'] == 'order_data' ){
                                $order_meta_data = maybe_unserialize( $cart_item['meta_value'] );
                                $order_items[$order_id]['order_data'] = $order_meta_data;
                            }
                            
                            if( $cart_item['meta_key'] == 'order_items' ){
                                $order_items_meta = maybe_unserialize( $cart_item['meta_value'] );
                                $order_items[$order_id]['cart_items'] = $order_items_meta;
                                $order_items[$order_id]['tot_qty'] = count( $order_items_meta );
                            }
                        }  
                    }
                    
                    if( $results_order_details > 0 ){
                        
                        //Code for Data Mapping as per Putler
                        foreach( $results_order_details as $order_detail ){
                            
                            $order_id = $order_detail['id'];
                            $order_total = round ( $order_items[$order_id]['order_data']['order_total'], 2 );
                            $date_gmt  = $order_detail['date'];
                            $dateInGMT = date('m/d/Y', (int)strtotime($date_gmt));
                            $timeInGMT = date('H:i:s', (int)strtotime($date_gmt));
                            
                            $status_taxonomy_id = $order_detail['term_taxonomy_id']; 
                            $status = $order_status[$status_taxonomy_id];

                            if ($status == "on-hold" || $status == "pending"  || $status == "failed") {
                                    $order_status_display = 'Pending';
                            } else if ($status == "completed" || $status == "processing" || $status == "refunded" ) {
                                    $order_status_display = 'Completed';
                            } else if ($status == "cancelled") {
                                    $order_status_display = 'Cancelled';
                            } 
                            
                            // $response['date_time'] = $date_gmt;
                            $response ['Date'] = $dateInGMT;
                            $response ['Time'] = $timeInGMT;
                            $response ['Time_Zone'] = 'GMT';
                            
                            $response ['Source'] = $this->name;
                            $response ['Name'] = $order_items[$order_id]['order_data']['billing_first_name'] . ' ' . $order_items[$order_id]['order_data']['billing_last_name'];
                            // $response ['Type'] = ( $status == "refunded") ? 'Refund' : 'Shopping Cart Payment Received';
                            $response ['Type'] = 'Shopping Cart Payment Received';



                            $response ['Status'] = ucfirst( $order_status_display );

                            $response ['Currency'] = $currency;

                            $response ['Gross'] = $order_total;
                            $response ['Fee'] = 0.00;
                            $response ['Net'] = $order_total;

                            $response ['From_Email_Address'] = $order_items[$order_id]['order_data']['billing_email'] ;
                            $response ['To_Email_Address'] = '';
                            $response ['Transaction_ID'] = $order_id ;
                            $response ['Counterparty_Status'] = '';
                            $response ['Address_Status'] = '';
                            $response ['Item_Title'] = 'Shopping Cart';
                            $response ['Item_ID'] = 0; // Set to 0 for main Order Transaction row
                            $response ['Shipping_and_Handling_Amount'] = ( isset( $order_items[$order_id]['order_data']['order_shipping'] ) ) ? round ( $order_items[$order_id]['order_data']['order_shipping'], 2 ) : 0.00;
                            $response ['Insurance_Amount'] = '';
                            $response ['Discount'] = isset( $order_items[$order_id]['order_data']['order_discount'] ) ? round ( $order_items[$order_id]['order_data']['order_discount'], 2 ) : 0.00;
                            
                            $response ['Sales_Tax'] = isset( $order_items[$order_id]['order_data']['order_tax_total'] ) ? round ( $order_items[$order_id]['order_data']['order_tax_total'], 2 ) : 0.00;

                            $response ['Option_1_Name'] = '';
                            $response ['Option_1_Value'] = '';
                            $response ['Option_2_Name'] = '';
                            $response ['Option_2_Value'] = '';
                            
                            $response ['Auction_Site'] = '';
                            $response ['Buyer_ID'] = '';
                            $response ['Item_URL'] = '';
                            $response ['Closing_Date'] = '';
                            $response ['Escrow_ID'] = '';
                            $response ['Invoice_ID'] = '';
                            $response ['Reference_Txn_ID'] = '';
                            $response ['Invoice_Number'] = '';
                            $response ['Custom_Number'] = '';
                            $response ['Quantity'] = $order_items[$order_id]['tot_qty']; 
                            $response ['Receipt_ID'] = '';

                            $response ['Balance'] = '';
                            $response ['Note'] = $order_detail['order_note'] ;
                            $response ['Address_Line_1'] = ( isset( $order_items['order_data']['billing_address_1'] ) ) ? $order_items['order_data']['billing_address_1'] : '';
                            $response ['Address_Line_2'] = isset( $order_items[$order_id]['order_data']['billing_address_2'] ) ? $order_items[$order_id]['order_data']['billing_address_2'] : '';
                            $response ['Town_City'] = isset( $order_items[$order_id]['order_data']['billing_city'] ) ? $order_items[$order_id]['order_data']['billing_city'] : '' ;
                            $response ['State_Province'] = $order_items[$order_id]['order_data']['billing_state'];
                            $response ['Zip_Postal_Code'] = isset( $order_items[$order_id]['order_data']['billing_postcode'] ) ? $order_items[$order_id]['order_data']['billing_postcode'] : '';
                            $response ['Country'] = isset( $order_items[$order_id]['order_data']['billing_country'] ) ? $order_items[$order_id]['order_data']['billing_country'] : '';
                            $response ['Contact_Phone_Number'] = isset( $order_items[$order_id]['order_data']['billing_phone']) ? $order_items[$order_id]['order_data']['billing_phone'] : '';
                            $response ['Subscription_ID'] = '';

                            $transactions [] = $response;
                            
                            foreach( $order_items[$order_id]['cart_items'] as $cart_item ) {
                                
                                    $order_item = array();
                                    $order_item ['Type'] = 'Shopping Cart Item';
                                    $order_item ['Item_Title'] = $cart_item['name'];
                                    
                                    $product_id = ( !empty( $cart_item['variation_id'] ) ) ? $cart_item['variation_id'] : $cart_item['id'] ;
                                    $order_item ['Item_ID'] = $product_id;
                                    
                                    $order_item ['Gross'] = round ( $cart_item['cost'], 2 );
                                    $order_item ['Quantity'] = $cart_item['qty'];
                                    
                                    
                                    if( !empty( $cart_item['variation'] ) ){
                                        $attributes_name = $attributes_values = $attributes = array();
                                        $attributes_name = array_keys( $cart_item['variation'] );
                                        
                                        foreach( $attributes_name as $key => $attribute_name ){
                                            $pos = strpos( $attribute_name, '_' ) + 1;
                                            $attributes_name[$key] = ucfirst( substr( $attribute_name, $pos ) );
                                        }
                                        
                                        $attributes_values = array_values( $cart_item['variation'] );
                                        $attributes = array_combine( $attributes_name, $attributes_values );
                                        
                                        if( count( $cart_item['variation'] ) == 1 ){
                                            
                                            $order_item['Option_1_Name'] = $attributes_name[0];
                                            $order_item['Option_1_Value'] = $attributes_values[0];
                                            
                                        } elseif( count( $cart_item['variation'] ) == 2 ) {
                                            
                                            $order_item['Option_1_Name'] = $attributes_name[0];
                                            $order_item['Option_1_Value'] = $attributes_values[0];
                                            $order_item['Option_2_Name'] = $attributes_name[1];
                                            $order_item['Option_2_Value'] = $attributes_values[1];
                                            
                                        } elseif( count( $cart_item['variation'] ) >= 2 ) {
                                            $str = '';
                                            foreach( $attributes as $attr_name => $attr_value ) {
                                                $str .= $attr_name . ':' . $attr_value . ',' ;
                                            }
                                            
                                            $str = rtrim( $str, ',' );
                                            $order_item['Option_1_Name'] = '';
                                            $order_item['Option_1_Value'] = $str;
                                        }
                                    } 
                                    
                                    $transactions [] = array_merge ( $response, $order_item );

                                    if( $status == "refunded"){

                                        $date_gmt_modified = $order_detail['modified_date'];

                                        $response ['Date'] = date('m/d/Y', (int)strtotime($date_gmt_modified));
                                        $response ['Time'] = date('H:i:s', (int)strtotime($date_gmt_modified));

                                        $response ['Type'] = 'Refund';
                                        $response ['Status'] = 'Completed';
                                        $response ['Gross'] = -$order_total;
                                        $response ['Net'] = -$order_total;
                                        $response ['Transaction_ID'] = $order_id . '_R';
                                        $response ['Reference_Txn_ID'] = $order_id;

                                        $transactions [] = $response;
                                    }

                            }
                            
                        }
                        
                    } else {
                        
                    }
            
                    if ( empty($params['order_id']) ) {
                        $order_count = (is_array($results_order_details)) ? count($results_order_details) : 0 ;              
                        $params[ $this->name ] = array('count' => $order_count, 'last_start_limit' => $start_limit, 'data' => $transactions );
                    } else {
                        $params['data'] = $transactions;
                    }
                    
             } else {
                
             }
             
            return $params;
        }
    }
}