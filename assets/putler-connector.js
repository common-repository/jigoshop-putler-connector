;jQuery(function( $ ) {

	$("#putler_connector_settings_form").on('submit', function( event ) {

		var form = $(this), inputs = form.find("input, select, button, textarea"), 
                data = form.serialize();
                
                var email_address = $('#putler_email_address', form).val();
                var token = $('#putler_api_token', form).val();
                
                event.preventDefault();
                
                if( email_address == '' || token == '' ) {
                    var msg = 'Email Address or API Token cannot be empty.';
                    show_message( '', msg );
                } else {

                    $('#putler_connector_progress_label').removeClass('putler_connector_progressbar_label');
                    $('#putler_connector_progressbar').addClass('putler_connector_progressbar');
                    $('#putler_connector_progress_label').text('Saving Settings...');

                    $("#putler_connector_progressbar").show();
                    inputs.prop("disabled", true);
                    
                    request = $.ajax({
                        url: ajaxurl + '?action=putler_connector_save',
                        type: "post",
                        data: data
                    });
                    
                    request.done(function ( response ){

                        response = JSON.parse(response);

                        if( response.status == "OK" ){
                            
                            var total_order_count = remaining_order_count = response.order_count;
                            var params = Array();
                            var per_orders_sent = 0;
                            var all_done = false; // Flag for handling all done message

                            // to hide div showing messages.
                            $("#putler_configure_message").hide();
                            $("#putler_message").hide();

                            var remaining_order_count = response.order_count;

                            if ( remaining_order_count > 0 ) {
                                $('#putler_connector_progress_label').text('Loading Past Orders...');
                                setTimeout(function() { send_data(remaining_order_count, params); }, 2000);
                            } else {
                                show_data_loaded_msg( 'Settings Saved! No Past orders to send to Putler.' );
                            }

                            function send_data( remaining_order_count, params ) {
                                
                                if ( remaining_order_count == response.order_count ) {
                                    $('#putler_connector_progress_label').empty();
                                }
                                
                                $('#putler_connector_progress_label').addClass('putler_connector_progressbar_label');
                                
                                    send_batch_request = $.ajax({
                                        url: ajaxurl + '?action=putler_connector_send_batch',
                                        type: "post",
                                        async: 'false',
                                        data: {
                                            'params': params                           
                                        }
                                    });

                                send_batch_request.done(function ( send_batch_response, textStatus, jqXHR ){

                                    send_batch_response = JSON.parse( send_batch_response );
                                    
                                    if( send_batch_response.status == 'OK' ){

                                        params = send_batch_response.results;
                                        remaining_order_count = remaining_order_count - send_batch_response.sent_count;

                                        if ( total_order_count != remaining_order_count ) {
                                            per_orders_sent = Math.round(( total_order_count - remaining_order_count ) / total_order_count * 100);
                                            progress( per_orders_sent, $('#putler_connector_progressbar') );
                                        }

                                        if (remaining_order_count > 0) {
                                            send_data(remaining_order_count,JSON.stringify(params));
                                        } else {
                                            all_done = true;
                                        }

                                    } else if ( send_batch_response.status == 'ALL_DONE' ) {
                                        all_done = true;
                                    } else {

                                        var status = send_batch_response.results.jigoshop.status;
                                        var msg = send_batch_response.results.jigoshop.message;
                                        setTimeout( function() { show_message( status, msg ); }, 1500 );
                                    }

                                    if ( all_done === true ) {

                                        per_orders_sent = 100;
                                        progress( per_orders_sent, $('#putler_connector_progressbar') );
                                        show_data_loaded_msg('Past orders were sent to Putler successfully.');    
                                        
                                    }

                                });
                            }


                        } else {

                            // Show error message if credential were not vaidated.
                            $("#putler_connector_progressbar").fadeOut(100);
                            var status = response.status;
                            var msg = response.message;
                            show_message( status, msg );
                        }


                    });
                
                    request.fail(function (jqXHR, textStatus, errorThrown){
                        console.error(
                            "The following error occured: "+
                            textStatus, errorThrown
                        );
                    });

                    request.always(function () {
                        inputs.prop("disabled", false);
                    });
                    
                }
		
                

                function show_message( status, msg ){
                    $("#putler_message").show();
                    if( status ){
                        $("#putler_message p").text( status + ':' + msg );
                    } else {
                        $("#putler_message p").text( msg );
                    }

                }
                
                function progress(percent, $element) {
                    
                    var progressBarWidth = percent * $element.width() / 100;
                    $element.find('div').css({ width: percent + '%' }).html(percent + "%&nbsp;");

                }
                
                function show_data_loaded_msg( msg ){
                    
                    var img_url = putler_params.image_url + 'green-tick.png';
                    
                    setTimeout( function() {
                        
                            $('#putler_connector_progress_label').removeClass('putler_connector_progressbar_label');
                            $('#putler_connector_progressbar').removeClass('putler_connector_progressbar');
                            
                            
                            $('#putler_connector_progress_label').html('<img src="' + img_url + '"alt="Complete" height="16" width="16" class="alignleft"><h3>'+ msg +'</h3><p>New orders will sync automatically.</p>');
                        }, 300 );
                }
                
	});
} );