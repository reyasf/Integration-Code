<?php
/*
Template Name: SAP Handler
*/

define("ESHOP_ID","SIT");
define("LOG_JSON","JSON");
define("LOG_ERROR","ERROR");
define("LOG_SUCCESS","SUCCESS");
define("LOG_DISCOUNT","DISCOUNT");

?>

<?php
              
        $values = $_REQUEST;
        
        $json_data = json_decode(file_get_contents('php://input'), true);
        $line_details = array();
        
        /*
         * Request should contain the secret key shared with SAP team
         */
        if(isset($values["access_key"])) {
            if(check_sap_hash($values["access_key"])) {                
                $action = $_REQUEST["action"];
                /*
                 * switch based on the action value
                 */
                switch($action) {
                    /*
                     * New Customer request from SAP
                     * response: the user_id
                     */
                    case "new_customer":                        
                        sap_sync_log("New customer request from SAP [Data :".serialize($json_data)."]",LOG_JSON);
                        $customer_received = $json_data;
                        
                        if ( email_exists($customer_received["email"]) == false ) {
                            $random_password = wp_generate_password( $length=12, $include_standard_special_chars=false );
                            $created_user = wp_create_user( $customer_received["email"], $random_password, $customer_received["email"] );
                        } else {
                            $created_user = email_exists($customer_received["email"]);
                        }
                                                
                        if( is_wp_error( $created_user ) ) {
                            $message = "New customer request from SAP failed [Email :".$customer_received["email"]."][Error:".$created_user->get_error_message()."]";
                            sap_sync_log($message,LOG_ERROR);                            
                            echo json_encode(array("Code" => 1000, "Status" => $created_user->get_error_message()));
                            return;
                        }
                        else {
                            $message = "New customer request from SAP processed [Email :".$customer_received["email"]."][Password:".$random_password."]";
                            sap_sync_log($message,LOG_SUCCESS);                            
                            update_user_meta( $created_user, "SAP Code", $customer_received["code"] ); 
                            echo json_encode(array("Code" => 200, "Status" => "Success","WebCode" => $created_user));
                            return;
                        }
                    break;
                    /*
                     * New Customer request to SAP
                     * response: the SAP Customer code
                     */
                    case "new_customer_eshop":                                                
                        $customer_received = $json_data["customer"];
                        $send_json_to_sap = json_encode(array(
                                                                                    "company" => ESHOP_ID, 
                                                                                    "email" => $customer_received["email"], 
                                                                                    "code" => $customer_received["id"], 
                                                                                    "name" => $customer_received["first_name"]." ".$customer_received["last_name"],
                                                                                    "telno" => "",                                                                                    
                                                                                    "currency"=>"AED",
                                                                                    "address" => array(),
                                                                                    "siteref" => ESHOP_ID
                                                                                )
                        );
                        sap_sync_log("New customer request to SAP [Data :".serialize($send_json_to_sap)."]",LOG_JSON);                        
                        $sync_response = sap_sync('{SERVICE_URL}/bp', $send_json_to_sap);
                        $status_string = utf8_encode($sync_response["body"]);
                        $status_decode = json_decode($status_string);
                        if($status_decode->Code == 200 && $status_decode->Status === "Success") {
                            sap_sync_log("New customer request to SAP processed [Customer id :".$status_decode->SapCode."]",LOG_SUCCESS);                            
                            update_user_meta( $customer_received["id"], "SAP Code", $status_decode->SapCode );
                            echo json_encode(array("Code" => 200, "Status" => "Success","WebCode" => $customer_received["id"]));
                            return;
                        }
                        else {
                            if($status_decode->SapCode !== "")
                                update_user_meta( $customer_received["id"], "SAP Code", $status_decode->SapCode );
                            else
                                update_user_meta( $customer_received["id"], "SAP Code", "Error Creating User in SAP" );
                            
                            sap_sync_log("New customer request to SAP failed [ERROR :".$status_decode->Status." Response String:".serialize($status_decode)."]",LOG_ERROR);
                            echo json_encode(array("Code" => 1000, "Status" => "Error"));
                            sap_failed_requests("New customer request to SAP failed", '{SERVICE_URL}/bp', $send_json_to_sap,"customer");
                            return;
                        }                                              
                    break;
                    /*
                     * New product request to SAP
                     * response: 200 on successfull product creation
                     * This handles both simple and variable product creation
                     */
                    case "new_item":
                        sap_sync_log("New product request from SAP [Data :".serialize($json_data)."]",LOG_JSON);                        
                        $sample_json_data = $json_data;
                        $items_received = $sample_json_data["details"];                        
                        foreach($items_received as $single_item =>$single_item_data)
                        {
                            $sku_code = $single_item_data["skucode"];
                            $sku_name = $single_item_data["skuname"];
                            $landing_cost = $single_item_data["landcost"];
                            $sale_price = $single_item_data["saleprice"];
                            $av_quantity = intval($single_item_data["availableqty"]);
                            $action_to_perform = intval($single_item_data["action"]);
                            $parent_id = $single_item_data["parentId"];
                            $brand = $single_item_data["brand"];
                            $season = $single_item_data["season"];
                            $group = $single_item_data["group"];
                            
                            $find_product = get_product_by_sku($sku_code);
                            
                            if($find_product != NULL && ($action_to_perform == 1 || $action_to_perform == 0)) {
                                    $found_product = $find_product;
                                    $current_stock = $found_product->get_stock_quantity();
                                    $after_update_stock = wc_update_product_stock($found_product,$av_quantity,'set');
                                    
                                    $parent = get_parent_object_by_sku($sku_code);
                                
                                    switch ( $parent->product_type ) {
                                        case "variable" :
                                            $variations = $parent->get_available_variations();
                                            if(isset($variations)) {
                                                $prodcal_stock = 0;
                                                foreach($variations as $variation){
                                                    $variation_id = $variation['variation_id'];
                                                    $variation_obj = new WC_Product_variation($variation_id);
                                                    $prodcal_stock += $variation_obj->get_stock_quantity();
                                                }
                                            }
                                            if($prodcal_stock == 0) {
                                                $parent->set_stock_status("outofstock");
                                            }
                                            elseif($prodcal_stock > 0) {
                                                update_post_meta( $parent->id, '_stock_status', "instock" );
                                            }
                                        break;
                                        case "simple" :
                                            $prodcal_stock = 0;
                                            $prodcal_stock = $parent->get_stock_quantity();
                                            if($prodcal_stock == 0) {
                                                $parent->set_stock_status("outofstock");
                                            }
                                            elseif($prodcal_stock > 0) {
                                                $parent->set_stock_status("instock");
                                            }
                                        break;
                                    }

                                    $existing_sale = get_post_meta($found_product->id,"_sale_price",true);
                                    if(!($existing_sale > 0) && $sale_price > 0)
                                    {
                                        update_post_meta($found_product->id,"_regular_price",$sale_price);
                                        update_post_meta($found_product->id,"_price", $sale_price );
                                    }

                                    sap_sync_log("New product request [UPDATE] from SAP processed [SKU:".$sku_code."] [ProductID:".$found_product->id."] [Received Stock:".$av_quantity."] [Existing Stock:".$current_stock."] [Available Stock:".$after_update_stock."]",LOG_SUCCESS);                                
                            
                            }
                            elseif(($action_to_perform == 1 || $action_to_perform == 0) && ($find_product == NULL)) {
                                $find_product = get_product_by_sku($sku_code);
                                
                                    if(($sku_code === $parent_id || $parent_id === ""))
                                    {
                                            $post = array(
                                                'post_author' => 9,
                                                'post_content' => '',
                                                'post_status' => "draft",
                                                'post_title' => $sku_name,
                                                'post_parent' => '',
                                                'post_type' => "product",
                                            );
                                            //Create post
                                            $post_id = wp_insert_post( $post );

                                            wp_set_object_terms($post_id, 'simple', 'product_type');                                           
                                            
                                            if($av_quantity > 0) {
                                                $stock_status = "instock";
                                            }
                                            else {
                                                $stock_status = "outofstock";
                                            }

                                            update_post_meta( $post_id, '_stock_status', $stock_status);                                   
                                            update_post_meta( $post_id, '_regular_price', $sale_price );                                   
                                            update_post_meta( $post_id, '_sku', $sku_code);                                   
                                            update_post_meta( $post_id, '_price', $sale_price );                                   
                                            update_post_meta( $post_id, '_manage_stock', "yes" );
                                            update_post_meta( $post_id, '_backorders', "no" );
                                            update_post_meta( $post_id, '_stock', $av_quantity );
                                            
                                            sap_sync_log("New product request [INSERT] from SAP processed [SKU:".$sku_code."] [ProductID:".$post_id."] [Stock:".$av_quantity."]",LOG_SUCCESS);
                                            
                                            populate_product_taxonomies($brand,$season,$group,$sku_code);
                                    }
                                    else {
                                           $parent_product = get_product_by_sku($parent_id);
                              
                                           if($parent_product == null)
                                           {
                                                $post = array(
                                                    'post_author' => 9,
                                                    'post_content' => '',
                                                    'post_status' => "draft",
                                                    'post_title' => "Parent of ".$sku_name,
                                                    'post_parent' => '',
                                                    'post_type' => "product",
                                                );
                                                //Create post
                                                $post_id = wp_insert_post( $post );

                                                wp_set_object_terms($post_id, 'variable', 'product_type');                                           
                                                update_post_meta( $post_id, '_sku', $parent_id);
                                                
                                                $parent_product = get_product_by_sku($parent_id);
                                                
                                                sap_sync_log("New Variable request [INSERT] from SAP processed [SKU:".$sku_code."][ProductID:".$parent_product->id."]",LOG_SUCCESS);
                                                
                                                
                                                
                                                
                                           }
                                           else {
                                               wp_set_object_terms ($parent_product->id,'variable','product_type');
                                           }
                    
                                           $avail_attributes = array('S','M','L','XL','XS-S','M-L');
                                           wp_set_object_terms($parent_product->id, $avail_attributes, 'pa_size');

                                           $thedata1 = Array('pa_size'=>Array(
                                                'name'=>'pa_size',
                                                'value'=>'',
                                                'is_visible' => '1', 
                                                'is_variation' => '1',
                                                'is_taxonomy' => '1'
                                            ));
                                            update_post_meta($parent_product->id,'_product_attributes',$thedata1);

                                            $post = array(
                                                'post_author' => 9,
                                                'post_content' => '',
                                                'post_status' => "publish",
                                                'post_title' => $sku_name,
                                                'post_parent' => $parent_product->id,
                                                'post_type' => "product_variation",
                                             );

                                             $variation_id = wp_insert_post( $post );
                                             
                                             $rand_size = array('S','M','L','XL','XS-S','M-L');
                                                                                          
                                             update_post_meta($variation_id, 'attribute_pa_size', $rand_size[$rand_keys[0]]);
                                             update_post_meta($variation_id, '_price', $sale_price);
                                             update_post_meta($variation_id, '_regular_price', $sale_price);

                                             wp_set_object_terms($variation_id, $avail_attributes, 'pa_size');
                                             $thedata2 = Array('pa_size'=>Array(
                                                'name'=>$rand_size,
                                                'value'=>'',
                                                'is_visible' => '1', 
                                                'is_variation' => '1',
                                                'is_taxonomy' => '1'
                                             ));
                                             update_post_meta( $variation_id,'_product_attributes',$thedata2);                        
                                             if($av_quantity > 0)
                                                update_post_meta( $variation_id, '_stock_status', 'instock');
                                             else
                                                update_post_meta( $variation_id, '_stock_status', 'outofstock');

                                             update_post_meta($variation_id, '_sku', $sku_code);                        
                                             update_post_meta($variation_id, '_manage_stock', "yes" );                        
                                             update_post_meta($variation_id, '_stock', $av_quantity);
                                             sap_sync_log("New variation request [INSERT] from SAP processed [SKU:".$sku_code."][ParentID:".$parent_product->id."] [ProductID:".$variation_id."] [Stock:".$av_quantity."]",LOG_SUCCESS);
                             
                                             populate_product_taxonomies($brand,$season,$group,$parent_id);
                                    }
                                }                            
                        }
                        //wp_cache_flush();
                        echo json_encode(array("Code" => 200, "Status" => "Success"));
                        return;
                    break;
                    /*
                     * New order placed in EShop
                     * request: for A/R invoice
                     * response: A/R invoice code from SAP
                     */
                    case "new_eshop_order":                                                
                        $response = $json_data;
                        $line_items = $response["line_items"];
                        $order_id = $response["id"];
                        if(!$order_id) {
                            exit();
                        }
                        $order_total = $response["total"];
                        $order_freight = $response["total_shipping"];
                        foreach($line_items as $line_name => $line_value)
                        {                            
                            $details = array();
                            foreach($line_value as $line_meta => $meta_value)
                            { 
                                switch($line_meta)
                                {
                                    case "sku":
                                        $details["skucode"] = $meta_value;
                                        break;
                                    case "quantity":
                                        $details["qty"] = $meta_value;
                                        break;
                                    case "price":
                                        $details["price"] = $meta_value;
                                        break;                                
                                }                                
                            }
                            $details["discount"] = "0";
                            $line_details[] = $details;
                        }
                        
                        $customer_details = $response["customer"];                        
                        $customer_id = $customer_details["id"];
                        $sap_code = get_user_meta($customer_details["id"], "SAP Code", true);
                        if($sap_code) {
                            $sap_c_code = $sap_code;
                        }
                        else {
                            //Guest Checkout
                            
                            if ( email_exists($customer_details["email"]) == false ) {
                                $random_password = wp_generate_password( $length=12, $include_standard_special_chars=false );
                                $created_user = wp_create_user( $customer_details["email"], $random_password, $customer_details["email"] );
                            } else {
                                $created_user = email_exists($customer_details["email"]);
                            }
                            
                            $send_json_to_sap = json_encode(array(
                                                                                    "company" => ESHOP_ID, 
                                                                                    "email" => $customer_details["email"], 
                                                                                    "code" => $created_user, 
                                                                                    "name" => $response["shipping_address"]["first_name"]." ".$response["shipping_address"]["last_name"],
                                                                                    "telno" => $response["billing_address"]["phone"],                                                                                    
                                                                                    "currency"=>"AED",
                                                                                    "address" => array(),
                                                                                    "siteref" => ESHOP_ID
                                                                                )
                            );
                            sap_sync_log("New guest customer request to SAP [Data :".serialize($send_json_to_sap)."]",LOG_JSON);                            
                            $sync_response = sap_sync('{SERVICE_URL}/bp',$send_json_to_sap);
                            $status_string = utf8_encode($sync_response["body"]);
                            $status_decode = json_decode($status_string);
                            $sap_c_code = $status_decode->SapCode;
                            sap_sync_log("New guest customer request to SAP processed [Customer id :".$sap_c_code."]",LOG_SUCCESS);
                            if($created_user !== 0)
                                update_user_meta( $created_user, "SAP Code", $sap_c_code );
                        }
                            
                        $payment_details = $response["payment_details"];
                        switch($payment_details["method_id"])
                        {
                            case "cod":
                                $payment_method = "COD";
                                break;
                            case "cybersource_SA":
                                $payment_method = "CC";
                                break;
                            case "paypal":
                                $payment_method = "PP";
                                break;
                        }                        
                                                                                                                                              
                        if($payment_details["method_id"] === "paypal" || $payment_details["method_id"] === "cybersource_SA") {
                            if($payment_details["method_id"] === "paypal") {
                                $voucher_no = get_post_meta( $order_id, '_transaction_id', true );
                                if(!$voucher_no)
                                    $voucher_no = get_post_meta( $order_id, 'transaction_id', true );
                            }
                            else if($payment_details["method_id"] === "cybersource_SA") {
                                $voucher_no = get_post_meta( $order_id, 'Payment Card Type',true);
                            }
                            $send_payment_details = array("paid" => $order_total,"voucherno" => $voucher_no);
                            $send_json_to_sap = json_encode(array(
                                                                                    "company" => ESHOP_ID, 
                                                                                    "sapcode" => $sap_c_code, 
                                                                                    "name" => $customer_details["email"], 
                                                                                    "webref" => $response["id"],
                                                                                    "paymode" => $payment_method,
                                                                                    "remarks"=>"Online Transaction",
                                                                                    "currency"=>"AED",
                                                                                    "freight"=>$order_freight,
                                                                                    "details" => $line_details,
                                                                                    "paymentdetails" => $send_payment_details,
                                                                                    "siteref" =>ESHOP_ID
                                                                                )
                                                                );
                        }
                        else {                         
                        
                            $send_json_to_sap = json_encode(array(
                                                                                        "company" => ESHOP_ID, 
                                                                                        "sapcode" => $sap_c_code, 
                                                                                        "name" => $customer_details["email"], 
                                                                                        "webref" => $response["id"],
                                                                                        "paymode" => $payment_method,
                                                                                        "remarks"=>"Online Transaction",
                                                                                        "currency"=>"AED",
                                                                                        "freight"=>$order_freight,
                                                                                        "details" => $line_details,
                                                                                        "siteref" =>ESHOP_ID
                                                                                    )
                                                                 );
                        }
                        sap_sync_log("New eshop order request to SAP [Data :".serialize($send_json_to_sap)."]",LOG_JSON);                        
                        $sync_response = sap_sync('{SERVICE_URL}/ri', $send_json_to_sap);                        
                        $status_string = utf8_encode($sync_response["body"]);
                        $status_decode = json_decode($status_string);
                        if($status_decode->Code == 200 && $status_decode->Status === "Success") {
                            sap_sync_log("New eshop order request to SAP processed [Order ID :".$response["id"]."][A/R invoice :".$status_decode->SapCode."]",LOG_SUCCESS);                            
                            update_post_meta($response["id"], "SAP A/R Invoice Code", $status_decode->SapCode);
                        } 
                        else if($status_decode->Code == 200 && $status_decode->Status === "Reserve Invoice Already Created") {
                            sap_sync_log("New eshop order request to SAP processed [Order ID :".$response["id"]."][A/R invoice :".$status_decode->SapCode."]",LOG_SUCCESS);                            
                            update_post_meta($response["id"], "SAP A/R Invoice Code", $status_decode->SapCode);
                        }
                        else {
                            sap_sync_log("New eshop order request to SAP failed [Order ID :".$response["id"]." Response String:".serialize($status_decode)."]",LOG_ERROR);
                            update_post_meta($response["id"], "SAP A/R Invoice Code","Error Creating A/R Invoice");
                            sap_failed_requests("Error Creating A/R Invoice", '{SERVICE_URL}/ri', $send_json_to_sap,"a/r_invoice");
                        }                        
                    break;
                    /*
                     * Order updated in eshop, like shipped, completed, cancelled
                     * request: for Delivery Note, payment, credit note
                     * response: Delivery Note code,Payment Code, Credit Note code
                     */
                    case "eshop_order_updated":
                                                
                        $order_updated = $json_data;
                        
                        $updated_order_id = $order_updated["id"];
                        $updated_order_status = $order_updated["status"];
                        $customer_details = $order_updated["customer"];
                        $sap_order_number = get_post_meta($updated_order_id,"SAP A/R Invoice Code",true);
                        $sap_order_delivery_number = get_post_meta($updated_order_id,"SAP DN Code",true);                        
                        $sap_c_code = get_user_meta($customer_details["id"],"SAP Code",true);
                        if(!$sap_c_code) {
                            $existing_customer_id = email_exists($customer_details["email"]);
                            $sap_c_code = get_user_meta($existing_customer_id,"SAP Code",true);
                        }
                        $payment_details = $order_updated["payment_details"];
                        switch($payment_details["method_id"])
                        {
                            case "cod":
                                $payment_method = "COD";
                                break;
                            case "cybersource_SA":
                                $payment_method = "CC";
                                break;
                            case "paypal":
                                $payment_method = "PP";
                                break;
                        }
                                                                                                
                        $line_items = $order_updated["line_items"];                        
                        $order_total = $order_updated["total"];
                        foreach($line_items as $line_name => $line_value)
                        {                            
                            $details = array();
                            foreach($line_value as $line_meta => $meta_value)
                            {                                
                                switch($line_meta)
                                {
                                    case "sku":
                                        $details["skucode"] = $meta_value;
                                        break;
                                    case "quantity":
                                        $details["qty"] = $meta_value;
                                        break;
                                    case "price":
                                        $details["price"] = $meta_value;
                                        break;                                
                                }                                
                            }
                            $details["discount"] = "0";
                            $line_details[] = $details;
                        }                        
                        
                        if($payment_details["method_id"] === "paypal" || $payment_details["method_id"] === "cybersource_SA") {
                            if($payment_details["method_id"] === "paypal") {
                                $voucher_no = get_post_meta( $updated_order_id, '_transaction_id',true);
                            }
                            else if($payment_details["method_id"] === "cybersource_SA") {
                                $voucher_no = get_post_meta( $updated_order_id, 'Payment Card Type',true);
                            }
                            $send_payment_details = array("paid" => $order_total,"voucherno" => $voucher_no);                            
                        }
                        else {
                            $send_payment_details = array("paid" => $order_total,"voucherno" => "COD Order");
                        }
                        if($updated_order_status === "cancelled" || $updated_order_status === "refunded") {
                            if($sap_order_delivery_number !== "") {
                                $send_json_to_sap = json_encode(array(
                                                                                        "company" => ESHOP_ID, 
                                                                                        "sapcode" => $sap_c_code, 
                                                                                        "name" => $customer_details["email"], 
                                                                                        "webref" => $sap_order_delivery_number,
                                                                                        "paymode" => $payment_method,
                                                                                        "remarks" => "Completed Order Cancelled",
                                                                                        "currency" => "AED",
                                                                                        "details" => $line_details,
                                                                                        "siteref" => ESHOP_ID
                                                                                    )
                                                                            );
                                sap_sync_log("Eshop order [CANCELLED] request to SAP [Data :".serialize($send_json_to_sap)."]",LOG_JSON);                                
                                $sync_response = sap_sync('{SERVICE_URL}/cn',$send_json_to_sap);                                
                                $status_string = utf8_encode($sync_response["body"]);
                                $status_decode = json_decode($status_string);
                                if($status_decode->Code == 200 && $status_decode->Status === "Success") {
                                    sap_sync_log("Eshop order [CANCELLED] request to SAP processed [Credit note document :".$status_decode->SapCode."]",LOG_SUCCESS);
                                    update_post_meta($updated_order_id,"Credit Note",$status_decode->SapCode);                                    
                                }
                                else {
                                    sap_sync_log("Eshop order [CANCELLED] request to SAP failed [Order id :".$updated_order_id." Response String:".serialize($status_decode)."]",LOG_ERROR);
                                    sap_failed_requests("Error Cancelling A/R Invoice", '{SERVICE_URL}/cn', $send_json_to_sap,"create_credit_note");
                                }                                
                            }
                            else {
                                $send_json_to_sap = json_encode(array(
                                                                                        "company" => ESHOP_ID, 
                                                                                        "sapcode" => $sap_c_code, 
                                                                                        "name" => $customer_details["email"], 
                                                                                        "weborder" => $sap_order_number,
                                                                                        "siteref" => ESHOP_ID
                                                                                    )
                                                                            );

                                sap_sync_log("Eshop order [UPDATED] request to SAP [Data :".serialize($send_json_to_sap)."]",LOG_JSON);                                
                                $sync_response = sap_sync('{SERVICE_URL}/cri',$send_json_to_sap);                                
                                $status_string = utf8_encode($sync_response["body"]);
                                $status_decode = json_decode($status_string);
                                if($status_decode->Code == 200 && $status_decode->Status === "Success") {
                                    sap_sync_log("Eshop order [UPDATED] request to SAP processed [A/R Cancellation :".$status_decode->SapCode."]",LOG_SUCCESS);
                                    update_post_meta($updated_order_id,"A/R Invoice Cancelled",$status_decode->SapCode);                                                                 
                                }
                                else {
                                    sap_sync_log("Eshop order [UPDATED] request to SAP failed [Order id :".$updated_order_id." Response String:".serialize($status_decode)."]",LOG_ERROR);
                                    sap_failed_requests("Error Cancelling A/R Invoice", '{SERVICE_URL}/cri', $send_json_to_sap,"cancel_a/r_invoce");
                                }
                            }
                        }
                        if($updated_order_status === "completed") {
                            if($payment_method === "COD")
                            {
                                $send_json_to_sap = json_encode(array(
                                                                                    "company" => ESHOP_ID, 
                                                                                    "sapcode" => $sap_c_code, 
                                                                                    "name" => $customer_details["email"], 
                                                                                    "weborder" => $sap_order_number,
                                                                                    "paymode" => $payment_method,                                                                                    
                                                                                    "currency" => "AED",                                                                                    
                                                                                    "paymentdetails" => $send_payment_details,
                                                                                    "siteref" => ESHOP_ID
                                                                                )
                                                                        );
                                sap_sync_log("Eshop order [COD COMPLETED] request to SAP [Data :".serialize($send_json_to_sap)."]",LOG_JSON);                                
                                $sync_response = sap_sync('{SERVICE_URL}/ip',$send_json_to_sap);                                
                                $status_string = utf8_encode($sync_response["body"]);
                                $status_decode = json_decode($status_string);
                                if($status_decode->Code == 200 && $status_decode->Status === "Success") {
                                    sap_sync_log("Eshop order [COD COMPLETED] request to SAP processed [Payment document :".$status_decode->SapCode."]",LOG_SUCCESS);
                                    update_post_meta($updated_order_id,"SAP Payment Code",$status_decode->SapCode);                                                                  
                                }
                                else {
                                    sap_sync_log("Eshop order [COD COMPLETED] request to SAP failed [Order id :".$updated_order_id." Response String:".serialize($status_decode)."]",LOG_ERROR);
                                    sap_failed_requests("Error Creating Payment", '{SERVICE_URL}/ip', $send_json_to_sap,"payment");
                                }
                            }
                                                     
                            $send_json_to_sap = json_encode(array(
                                                                                    "company" => ESHOP_ID, 
                                                                                    "sapcode" => $sap_c_code,
                                                                                    "name" => $customer_details["email"], 
                                                                                    "webref" => $sap_order_number,
                                                                                    "paymode" => $payment_method,
                                                                                    "remarks" => "Items Successfully Delivered",
                                                                                    "currency" => "AED",
                                                                                    "details" => $line_details,
                                                                                    "paymentdetails" => $send_payment_details,
                                                                                    "siteref" => ESHOP_ID
                                                                                )
                                                                        );
                            
                            sap_sync_log("Eshop order [COMPLETED] Delivery note request to SAP [Data :".serialize($send_json_to_sap)."]",LOG_JSON);                             
                            $sync_response = sap_sync('{SERVICE_URL}/dn',$send_json_to_sap);                            
                            $status_string = utf8_encode($sync_response["body"]);
                            $status_decode = json_decode($status_string);                            
                            if($status_decode->Code == 200 && $status_decode->Status === "Success") {
                                sap_sync_log("Eshop order [COMPLETED] Delivery note request to SAP processed [Delivery note document :".$status_decode->SapCode."]",LOG_SUCCESS);
                                update_post_meta($updated_order_id,"SAP DN Code",$status_decode->SapCode);
                            }
                            else if($status_decode->Code == 200 && $status_decode->Status === "Delivery Already Created") {
                                sap_sync_log("Eshop order [COMPLETED] Delivery note request to SAP processed [Delivery note document :".$status_decode->SapCode."]",LOG_SUCCESS);                            
                                update_post_meta($updated_order_id, "SAP DN Code", $status_decode->SapCode);
                            }
                            else {
                                sap_sync_log("Eshop order [COMPLETED] Delivery note request to SAP failed [Order id :".$updated_order_id." Response String:".serialize($status_decode)."]",LOG_ERROR);
                                sap_failed_requests("Error Creating Delivery Note", '{SERVICE_URL}/dn', $send_json_to_sap,"delivery_note");
                            }
                        }
                    break;
                    /*
                     * Transfer response from SAP, after generating an A/R invoice
                     * response: Warehouse ID from where we reserve the quantity
                     */
                    case "new_transfer_document":                        
                        sap_sync_log("New Transfer Document request from SAP [Data :".serialize($json_data)."]",LOG_JSON);
                        $document_received = $json_data;
                        
                        $warehouse = array( 11 => array("name"=>"xx","email"=>"xx@xxx.com"),
                                            12 => array("name"=>"yy","email"=>"yy@yyy.com"),
                                            13 => array("name"=>"zz","email"=>"zz@zz.com")
                                     );
                        
                        $order_no = $document_received["webref"];
                        $sku_code = $document_received["skucode"];
                        $product_details = get_parent_object_by_sku($sku_code);
                        
                        $order = new WC_Order($order_no);
                        $send_email = new WC_Emails();
        
                        $subject = sprintf( __('New EShop Order #%s', 'woocommerce'), $order->get_order_number());
                        $email_heading = sprintf( __('New EShop Order #%s', 'woocommerce'), $order->get_order_number());
                        
                        foreach($document_received["details"] as $details => $warehouse_details)
                        {
                            $warehouse_number = intval($warehouse_details["warehouse"]);
                            
                            $storename = $warehouse[$warehouse_number]["name"];
                            $storeemail = $warehouse[$warehouse_number]["email"];
                            $quantity = $warehouse_details["qty"];
                            
                            if($warehouse_details["remarks"] === "Success") {
                                // Buffer
                                ob_start();

                                // Get mail template
                                woocommerce_get_template('emails/store-order-invoiced.php', array(
                                'order' => $order,
                                'email_heading' => $email_heading,
                                'SAP_code' => $sku_code,
                                'qty' => $quantity,    
                                'item' => $product_details,
                                'store_name' => $storename    
                                ));

                                // Get contents
                                $message = ob_get_clean();

                                //$message = rewrite_email_url($message);

                                // CC, BCC, additional headers
                                $headers = apply_filters('woocommerce_email_headers', '', 'customer_completed_order');

                                // Attachments
                                $attachments = apply_filters('woocommerce_email_attachments', '', 'customer_completed_order');

                                // Send the mail 
                                $send_email->send( $storeemail, $subject, $message, $headers, $attachments );
                                $send_email->send( "eshop@shopatsauce.com", $subject, $message, $headers, $attachments );

                                ob_end_clean();
                            }
                        }
                        
                        echo json_encode(array("Code" => 200, "Status" => "Success"));
                    break;
                }                
            }
            else {
                return array("code" => 1004, "message" => "Access Key Invalid...");
            }
        }       
        
        /*
         * Actual sync triggering function
         * $url: The url value to which we have to send the request
         * $data: Actual data need to be sent to the url
         */
        
        function sap_sync($url,$data) {
                $response = wp_remote_post($url, array(
                        'method' => 'POST',
                        'timeout' => 200,
                        'redirection' => 120,
                        'httpversion' => '1.0',
                        'blocking' => true,
                         'headers' => array(
                            'Content-Type' => 'application/json'                            
                        ),
                        'body' => $data,
                        'cookies' =>  array(),
                        'sslverify' => false
                        )
                );
                
                if ( is_wp_error( $response ) ) {                   
                    sap_failed_requests($response, $url, $data);
                }                
                return $response;            
         }
         
         /*
         * Get the actual product object using sku
         * $sku: SKU value for which we want the product data
         */
         function get_product_by_sku( $sku ) {
            global $wpdb;
            
            $product_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_sku' AND meta_value='%s' LIMIT 1", $sku));
            $product_type = $wpdb->get_var($wpdb->prepare("SELECT post_type FROM $wpdb->posts WHERE ID='%s' LIMIT 1", $product_id));
            
            if ($product_id && ($product_type === "product" || $product_type === "product_variation")) {
                return wc_get_product($product_id);
            } else {
                return NULL;
            }
            
            return null;
        }
        
        /*
         * Get the actual parent product of a product using SKU
         * $sku: SKU value for which we want the parent product
         */
        function get_parent_object_by_sku( $sku ) {
            global $wpdb;
            
            $product_type = '';
            $product_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_sku' AND meta_value='%s' LIMIT 1", $sku));
            
            if ($product_id) {
                $product_type = $wpdb->get_var($wpdb->prepare("SELECT post_type FROM $wpdb->posts WHERE ID='%s' LIMIT 1", $product_id));
                
                if($product_type === "product") {
                    return wc_get_product($product_id);
                }
                else if($product_type === "product_variation"){
                    $variation = new WC_Product_Variation($product_id);
                    $parent = $variation->parent->id;
                    return wc_get_product($parent);
                }
            }
            return null;
        }
        
        /*
         * to store all the failed requests
         * $response: response code from SAP
         * $url: url to which the request got sent
         * $data: data sent with the request
         * $request_for: enum{bp,ai,cr...}
         */
        function sap_failed_requests($response, $url, $data, $request_for) {
            
            if(is_object($response)) {
                $error_message = $response->get_error_message();
            }
            else {
                $error_message = $response;
            }
            
            $failed_request["url"] = $url;
            $failed_request["data"] = $data;
            $failed_request["error"] = $error_message;
            $failed_request["request_for"] = $request_for;
                    
            global $wpdb;
            $table_name = $wpdb->prefix . 'sap_failed_requests';
            $wpdb->insert( 
                     $table_name, 
                     array( 
                           'url' => $url, 
                           'data' => $data,
                           'error_message' => $error_message,
                           'date_created' => time(),
                           'status' => "failed",
                           'request_for' => $request_for
                     ) 
            );
        }
        
        /*
         * to log all the requests
         * $message: data sent to the request
         * $type: enum{JSON,ERROR,SUCCESS,DISCOUNT}
         */
        
        function sap_sync_log($message,$type) {
            
            switch($type) {
                case "JSON":
                    $file_name = "sap_sync_json_log.txt";
                    break;
                case "ERROR":
                    $file_name = "sap_sync_error_log.txt";
                    break;
                case "SUCCESS":
                    $file_name = "sap_sync_log.txt";
                    break;
                case "DISCOUNT":
                    $file_name = "sap_sync_discount_log.txt";
                    break;
            }
            
            file_put_contents("{file_path}/".$file_name,date("d-m-Y H:i")." ".$message.PHP_EOL,FILE_APPEND);
            
        }
        
?>