<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( ! class_exists( 'Putler_Connector' ) ) {
    
    class Putler_Connector {

        private $email_address = '';
        private $api_token = '';
        private $version = 1.0;
        private $batch_size = 100;
        public $text_domain;
        private $api_url;
        public $settings_url;

        protected static $instance = null;
        
        /**
        * Call this method to get singleton
        *
        * @return Putler_Connector
        */
        public static function getInstance() {
            if(is_null(self::$instance))
            {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /**
         * Putler_Connector Constructor.
         *
         * @access public
         * @return void
         */
        private function __construct() {

            $this->text_domain = 'putler_connector';

            $this->api_url = 'https://api.putler.com/inbound/';
            
            if ( is_admin() ) {
                $this->settings_url = admin_url('tools.php?page=putler_connector');
                add_action( 'admin_menu', array(&$this, 'add_admin_menu_page') );
                add_action ( 'wp_ajax_putler_connector_save', array (&$this, 'validate_and_save_settings') );
            }

            $settings = get_option('putler_connector_settings', null);
            if ( $settings != null ) {
                $this->api_token = (!empty($settings['api_token'])) ? $settings['api_token'] : null;
                $this->email_address = (!empty($settings['email_address'])) ? $settings['email_address'] : null; 
                if (is_admin() ) {
                    add_action ( 'wp_ajax_putler_connector_send_batch', array (&$this, 'send_batch') );       
                }
            }

            if (empty($this->api_token) || empty($this->email_address) ) {
                // Show a message to add API information
                add_action ( 'admin_notices', array (&$this, 'admin_notices_api_not_configured_yet') );
            }


        }
        
        public function add_admin_menu_page() {
            add_management_page( __('Putler Connector',  $this->text_domain) , __('Putler Connector',  $this->text_domain), 'manage_options', 'putler_connector', array(&$this, 'display_settings_page') );
        }

        public function admin_notices_api_not_configured_yet() {
           echo '<div id="putler_configure_message" class="updated fade"><p>'.sprintf( __('Please <a href="%s">configure your Putler Email Address and API Token</a> to begin transaction sync.', $this->text_domain ), $this->settings_url ).'</p></div>'; 
        }

        public function display_settings_page() {
            
            // Enque JS file
            wp_enqueue_script( 'putler-connector-js', plugins_url( '../assets/putler-connector.js', __FILE__) , array( 'jquery', 'jquery-ui-progressbar' ), $this->version, true );            
            
            $putler_params = array ('image_url' => plugins_url('../assets/images/', __FILE__) );
            wp_localize_script( 'putler-connector-js', 'putler_params', $putler_params );
            
            // Enque CSS file
            wp_enqueue_style( 'putler-connector-css', plugins_url( '../assets/putler-connector.css', __FILE__) , array( ) );            
            
            // Now show form
            ?>
            <div class="wrap" id="putler_connector_settings_page">
                <h2><?php _e("Putler Connector Settings", $this->text_domain); ?></h2>
                <div id="putler_message" class="updated fade" style="display:none;"><p></p></div>
                <form id="putler_connector_settings_form" action="" method="post">
                    <table cellspacing="15" cellpadding="5">
                        <tr><th><label for="putler_email_address"><?php _e("Your Putler Email Address", $this->text_domain); ?></label></th>
                            <td><input id="putler_email_address" placeholder="test@test.com" type="text" size="50" name="putler_email_address" value="<?php echo $this->email_address; ?>"/></td>
                        </tr>
                        <tr><th><label for="putler_api_token"><?php _e("Your Putler API Token", $this->text_domain); ?></label></th>
                            <td><input id="putler_api_token" placeholder="API Token1, API Token2, ..." type="text" size="50" name="putler_api_token" value="<?php echo $this->api_token; ?>"/></td>
                        </tr>
                        <tr><th>&nbsp;</th>
                            <td><input type="submit" id="putler_connector_submit" class="button-primary" value="<?php _e("Save &amp; Send Past Orders to Putler", $this->text_domain); ?>"><br/><br/>
                                    <div id="putler_connector_progressbar" class="putler_connector_progressbar" style="display:none;"><div id="putler_connector_progress_label" ><?php _e('Saving Settings...', $this->text_domain );?></div></div>
                            </td>
                        </tr>
                        <tr><th>&nbsp;</th>
                            <td></td>
                        </tr>
                    </table>
                </form>
            </div>
            <?php
        }

        public function validate_and_save_settings () {
            $response = array( 'status' => 'ERR', 'message' => __('Invalid Token or Email Address. Please try again.', $this->text_domain) ) ;

            if ( !empty($_POST['putler_api_token']) && !empty($_POST['putler_email_address']) ) {
                $token = trim($_POST['putler_api_token']);
                $email = trim($_POST['putler_email_address']);
                $result = $this->validate_api_info( $token, $email );
                if ( $result === true ) {

                    $token_unique = implode(",",array_map('trim',(array_unique(array_filter(explode(",",trim($token))))))); // to save only unique token keys
                    
                    $this->api_token = $settings['api_token'] = $token_unique;

                    $this->email_address = $settings['email_address'] = $email;
                    // Save settings
                    update_option( 'putler_connector_settings', $settings );
                    
                    // Get total orders
                    $order_count = apply_filters( 'putler_connector_get_order_count', 0 );
                                        
                    // Send response
                    $response = array( 'status' => 'OK', 'message' => '', 'order_count' => $order_count ) ;
                } else if ( is_wp_error( $result ) ) {
                    $response['message'] = $result->get_error_message();

                    $err_data = $result->get_error_data();

                    if (!empty($err_data)) {
                        $response['message'] .= ". '". $err_data ."' token(s) are unauthorized.";
                    }
                    
                }
            }
            die( json_encode( $response ));
        }

        private function validate_api_info( $token, $email ) {
            // Validate with API server
            $result = wp_remote_head( $this->api_url, 
                        array( 'headers' => array(
                                'Authorization' => 'Basic ' . base64_encode( $email . ':' . $token ),
                                'User-Agent' => 'Putler Connector/'.$this->version
                                )
                            )
                        );

            if (is_wp_error( $result )) {
                return $result;
            }
            else if ( !empty($result['response']['code']) && $result['response']['code'] == 200 ) {
                return true;
            } else {
                if ( !empty($result['response']['code']) && !empty($result['response']['message'])) {
                    $unauthorized_tokens = (!empty($result['headers']['x-putler-invalid-token'])) ? $result['headers']['x-putler-invalid-token'] : '';
                    return new WP_Error( $result['response']['code'], $result['response']['message'], $unauthorized_tokens );
                }
            }
            return false;
        }

        public function post_order ( $order_id ) {

            $params ['order_id'] = $order_id; 
            $params = apply_filters( 'putler_connector_get_orders', $params );
            $post_result = $this->post_orders_to_putler( $params['data'] );
        }

        public function send_batch ( ) {
            $response = array( 'status' => 'OK', 'message' => '', 'results' => array() ) ;  

            $params = (!empty($_POST['params'])) ? json_decode( stripslashes($_POST['params']), true) : array();
            $params['limit'] = $this->batch_size;

            //Getting the data from ecommerce plugins
            $params = apply_filters( 'putler_connector_get_orders', $params );
            
            // Check if all orders are received...
            foreach ( (array) $params as $connector => $orders ) {
                
                // Send one batch to the server
                if (!empty($orders['data']) && is_array($orders['data']) && count($orders['data']) > 0)  {
                    
                    $count += $orders['count'];
                    $start_limit = $this->batch_size + $orders['last_start_limit'];
                    
                    $post_result = $this->post_orders_to_putler( $orders['data'] );
                    
                    $error_response= array();
                    
                    if (is_wp_error( $post_result ) ) {
                        $error_response[ $connector ]['status'] = 'ERR'; 
                        $error_response[ $connector ]['message'] = $post_result->get_error_message();
                        $response = array( 'status' => 'ERR', 'message' => '', 'results' => $error_response ); 
                    } else {
                        $response['results'][ $connector ] = array('status' => 'OK', 'start_limit' => $start_limit );
                    }
                }

                if ( $count < $this->batch_size ) {
                    $all_done = true;
                } else {
                    $all_done = false;
                    break;
                }
            }
            
            if ($all_done === true) {
                $response = array( 'status' => 'ALL_DONE', 'message' => '' ) ;    
            } else {
                $response['sent_count'] = $count;
            }

            die( json_encode( $response ));
        }

        public function post_orders_to_putler ( &$orders ) {

            if (empty($orders)) {
                return true;
            }

            $oid = ob_start();
            $fp = fopen('php://output', 'a+');
            foreach ( (array) $orders as $index  => $row) {
                if ($index == 0) {
                    fputcsv($fp, array_keys($row));    
                }
                fputcsv($fp, $row);
            }
            fclose($fp);
            $csv_data = ob_get_clean();
            if( ob_get_clean() > 0 ){ ob_end_clean(); }
            
            $result = wp_remote_post( $this->api_url, 
                        array('headers' => array(
                                'Content-Type' => 'text/csv',
                                'Authorization' => 'Basic ' . base64_encode( $this->email_address . ':' . $this->api_token ),
                                'User-Agent' => 'Putler Connector/'.$this->version
                                ),
                               'timeout' => 120, 
                               'body' => $csv_data
                            )
                        );

            if (is_wp_error( $result )) {
                return $result;
            }
            else {
                $server_response_default = array('ACK' => 'Failure', 'MESSAGE' => __('Unknown Response', $this->text_domain) );
                $server_response = json_decode( $result['body'], true);
                $server_response = array_merge( $server_response_default, $server_response );
                $server_response_code = $result['response']['code'];

                if ($server_response_code == 200 && $server_response['ACK'] == "Success") {
                    return true;
                }
                return new WP_Error( $server_response_code, $server_response['MESSAGE']);
            }
        }
    }
}