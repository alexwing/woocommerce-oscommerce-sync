<?php
/*
Plugin Name: Woocommerce osCommerce Import
Plugin URI: http://www.advancedstyle.com/
Description: Import products, categories, customers and orders from osCommerce to Woocommerce
Author: David Barnes
Version: 1.2.1
Author URI: http://www.advancedstyle.com/
*/

if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	
	function woocommerce_osc_submenu_page() {
		add_submenu_page( 'woocommerce', 'osCommerce Import', 'osCommerce Import', 'manage_options', 'woocommerce-osc-import', 'woocommerce_osc_submenu_page_callback' ); 
	}
	
	function woocommerce_osc_cartesian_product($a) {
	  $result = array(array());
	  foreach ($a as $k => $list) {
		$_tmp = array();
		foreach ($result as $result_item) {
		  foreach ($list as $list_item) {
			$_tmp[] = array_merge($result_item, array($k => $list_item));
		  }
		}
		$result = $_tmp;
	  }
	  return $result;
	}
	
	function woocommerce_osc_import_image($url){
		$attach_id = 0;
		$wp_upload_dir = wp_upload_dir();
		
		$filename = $wp_upload_dir['path'].'/'.sanitize_file_name(basename($url));
		
		if(file_exists($filename)){
			$url = $filename;
		}else{
			//Encode the URL
			$base = basename($url);
			$url = str_replace($base,urlencode($base),$url);
		}
		
		if($f = @file_get_contents($url)){
			file_put_contents($filename,$f);
			
			$wp_filetype = wp_check_filetype(basename($filename), null );
			
			$attachment = array(
			'guid' => $wp_upload_dir['url'] . '/' . basename( $filename ), 
			'post_mime_type' => $wp_filetype['type'],
			'post_title' => preg_replace('/\.[^.]+$/', '', basename($filename)),
			'post_content' => '',
			'post_status' => 'inherit'
			);
			$attach_id = wp_insert_attachment( $attachment, $filename, 37 );
			require_once(ABSPATH . 'wp-admin/includes/image.php');
			$attach_data = wp_generate_attachment_metadata( $attach_id, $filename );
			wp_update_attachment_metadata( $attach_id, $attach_data );
		}
		return $attach_id;
	}
	
	function woocommerce_osc_run_cats($parent=0, $parent_term_id=0){
		global $wpdb, $oscdb, $import_cat_counter;
		
		$categories = $oscdb->get_results("SELECT c.*, cd.* FROM categories c, categories_description cd WHERE c.categories_id=cd.categories_id AND cd.language_id=1 AND c.parent_id='".(int)$parent."'", ARRAY_A);
		if(!empty($categories)){
			foreach($categories as $category){
				if(!is_wp_error($category)){
					$term = term_exists($category['categories_name'], 'product_cat', (int)$parent_term_id); // array is returned if taxonomy is given
					if ((int)$term['term_id'] == 0) {
						$term = wp_insert_term(
						  $category['categories_name'], // the term 
						  'product_cat', // the taxonomy
						  array(
							//'description'=> $category['categories_id'],
							'parent'=> $parent_term_id
						  )
						);
						delete_option('product_cat_children'); // clear the cache
						
						$attach_id = 0;
						if($category['categories_image'] != ''){
							$url = rtrim($_POST['store_url'],'/').'/images/'.urlencode($category['categories_image']);
							$attach_id = woocommerce_osc_import_image($url);
						}
						add_woocommerce_term_meta($term['term_id'], 'order',$category['sort_order']);
						add_woocommerce_term_meta($term['term_id'], 'display_type','');
						add_woocommerce_term_meta($term['term_id'], 'thumbnail_id',(int)$attach_id);
						add_woocommerce_term_meta($term['term_id'], 'osc_id',$category['categories_id']);
						woocommerce_osc_run_cats($category['categories_id'], $term['term_id']);
						$import_cat_counter ++;
					}else{
						woocommerce_osc_run_cats($category['categories_id'], $term['term_id']);
					}
				}
			}
		}
	}
	
	function woocommerce_osc_submenu_page_callback() {
		global $wpdb, $oscdb, $import_cat_counter, $import_prod_counter;
		
		if(!empty($_POST)){
			$oscdb = new wpdb(trim($_POST['store_user']),trim($_POST['store_pass']),trim($_POST['store_dbname']),trim($_POST['store_host']));
			if($oscdb->ready){
				echo '<p>Starting...<em>(If the page stops loading or shows a timeout error, then just refresh the page and the importer will continue where it left off.  If you are using a shared server and are importing a lot of products you may need to refresh several times)</p>';
				// Do customer import
				if($_POST['dtype']['customers'] == 1){
					$country_data = $oscdb->get_results("SELECT * FROM countries", ARRAY_A);
					$countries_id = array();
					foreach($country_data as $cdata){
						$countries_id[$cdata['countries_id']] = $cdata;
					}
					$zones = array();
					$zone_data = $oscdb->get_results("SELECT zone_id, zone_code FROM zones", ARRAY_A);
					foreach($zone_data as $z){
						$zones[$z['zone_id']] = $z['zone_code'];
					}
					
					if($customers = $oscdb->get_results("SELECT c.customers_id, c.customers_firstname, c.customers_lastname, c.customers_telephone, c.customers_email_address, ab.entry_country_id, ab.entry_lastname, ab.entry_firstname, ab.entry_street_address, ab.entry_suburb, ab.entry_postcode, ab.entry_city, ab.entry_state, ab.entry_zone_id FROM customers c, address_book ab WHERE c.customers_id=ab.customers_id AND c.customers_default_address_id=ab.address_book_id", ARRAY_A)){
						foreach($customers as $customer){
							if ( !email_exists($customer['customers_email_address'])) {
								$original = strtolower(preg_replace("/[^A-Za-z0-9]/", '', $customer['customers_firstname'].$customer['customers_lastname']));
								$user_name = $original;
								
								$i = 1;
								while($user_id = username_exists( $user_name )){
									$user_name = $original.$i;
									$i++;
								}
								
								$random_password = wp_generate_password();
								$user_id = wp_create_user( $user_name, $random_password, $customer['customers_email_address'] );
								
								$data = array('first_name' => $customer['customers_firstname'],
											  'last_name' => $customer['customers_lastname'],
											  'billing_country' => $countries_id[$customer['entry_country_id']]['countries_iso_code_2'],
											  'billing_first_name' => $customer['entry_firstname'],
											  'billing_last_name' => $customer['entry_lastname'],
											  'billing_address_1' => $customer['entry_street_address'],
											  'billing_address_2' => $customer['entry_suburb'],
											  'billing_city' => $customer['entry_city'],
											  'billing_state' => ($customer['entry_state'] != '' ? $customer['entry_state'] : $zones[$customer['entry_zone_id']]),
											  'billing_postcode' => $customer['entry_postcode'],
											  'billing_email' => $customer['customers_email_address'],
											  'billing_phone' => $customer['customers_telephone'],
											  'shipping_country' => $countries_id[$customer['entry_country_id']]['countries_iso_code_2'],
											  'shipping_first_name' => $customer['entry_firstname'],
											  'shipping_last_name' => $customer['entry_lastname'],
											  'shipping_address_1' => $customer['entry_street_address'],
											  'shipping_address_2' => $customer['entry_suburb'],
											  'shipping_city' => $customer['entry_city'],
											  'shipping_state' => ($customer['entry_state'] != '' ? $customer['entry_state'] : $zones[$customer['entry_zone_id']]),
											  'shipping_postcode' => $customer['entry_postcode'],
											  'osc_id' => $customer['customers_id']);
								foreach($data as $k => $v){
									update_user_meta( $user_id, $k, $v);
								}
								
								if($user_id > 1){
									wp_update_user(array('ID' => $user_id, 'role' => 'customer'));
								}
								
								$import_customer_counter++;
							}
						}
					}
				}
				
				if($_POST['dtype']['products'] == 1){
					woocommerce_osc_run_cats();
					
					// Get all categories by OSC cat ID
					$categories = array();
					$terms = get_terms('product_cat',array('hide_empty' => 0));
					foreach ( $terms as $term ) {
						$o = get_woocommerce_term_meta($term->term_id,'osc_id',true);
						$categories[$o] = (int)$term->term_id;
					}
					
					// Import the products
					if($products = $oscdb->get_results("SELECT p.*, pd.*, p2c.categories_id FROM products p, products_description pd, products_to_categories p2c WHERE p.products_id=pd.products_id AND pd.language_id=1 AND p.products_id=p2c.products_id GROUP BY p.products_id", ARRAY_A)){
						foreach($products as $product){
							$existing_product = get_posts(array('post_type' => 'product','posts_per_page' => 1,'post_status' => 'any',
														'meta_query' => array(
															array(
																'key' => 'osc_id',
																'value' => $product['products_id'],
															)
												)));
							if(empty($existing_product)){
								$product_id = wp_insert_post(array(
								  'post_title'    => $product['products_name'],
								  'post_content'  => $product['products_description'],
								  'post_status'   => 'publish',
								  'post_type' 	  => 'product',
								  'post_author'   => 1
								));
								update_post_meta($product_id, 'osc_id', $product['products_id']);
								wp_set_object_terms($product_id, 'simple', 'product_type');
								wp_set_object_terms($product_id, (int)$categories[$product['categories_id']], 'product_cat');
								update_post_meta($product_id, '_sku', $product['products_model']);
								update_post_meta($product_id, '_regular_price', $product['products_price']);
								update_post_meta($product_id, '_price', $product['products_price']);
								update_post_meta($product_id, '_visibility', 'visible' );
								update_post_meta($product_id, '_stock_status', ($product['products_status'] ? 'instock' : 'outofstock'));
								update_post_meta($product_id, '_manage_stock', '1' );
								update_post_meta($product_id, '_stock', $product['products_quantity']);
								$import_prod_counter++;
								
								if($special = $oscdb->get_row("SELECT specials_new_products_price, expires_date FROM specials WHERE status=1 AND products_id='".$product_id."' LIMIT 1", ARRAY_A)){
									update_post_meta($product_id, '_sale_price', $special['specials_new_products_price']);
									$special['expires_date'] = strtotime($special['expires_date']);
									if($special['expires_date'] > time()){
										update_post_meta($product_id, '_sale_price_dates_to', date("Y-m-d",$special['expires_date']));
										update_post_meta($product_id, '_sale_price_dates_from', date("Y-m-d"));
									}
								}
								
								$attach_id = 0;
								if($product['products_image'] != ''){
									$url = rtrim($_POST['store_url'],'/').'/images/'.urlencode($product['products_image']);
									$attach_id = woocommerce_osc_import_image($url);
								}
								if($attach_id > 0){
									set_post_thumbnail($product_id, $attach_id);
								}
								
								// Handle attributes
								if($attributes = $oscdb->get_results("SELECT po.products_options_name, pov.products_options_values_name FROM products_attributes pa, products_options po, products_options_values pov WHERE pa.products_id='".$product['products_id']."' AND  pov.products_options_values_id = pa.options_values_id AND pov.language_id=po.language_id AND po.language_id=1 AND pa.options_id=products_options_id", ARRAY_A)){
									wp_set_object_terms($product_id, 'variable', 'product_type');
									
									$attrib_array = array();
									$attrib_combo = array();
									$max_price = $product['products_price'];
									$min_price = $product['products_price'];
									foreach($attributes as $attribute){
										$slug = sanitize_title($attribute['products_options_name']);
										$attrib_array[$slug] = array('name' => $attribute['products_options_name'],
																   'value' => ltrim($attrib_array[$slug]['value'].' | '.$attribute['products_options_values_name'],' | '),
																   'position' => 0,
																   'is_visible' => 1,
																   'is_variation' => 1,
																   'is_taxonomy' => 0);
										$attrib_combo[$slug][] = array($attribute['products_options_values_name'], ($attribute['price_prefix'] == '-' ? '-' : '').$attribute['options_values_price']);
									}
									// Now it gets tricky...
									$combos = woocommerce_osc_cartesian_product($attrib_combo);
									foreach($combos as $combo){
										$variation_id = wp_insert_post(array(
										  'post_title'    => 'Product '.$product_id.' Variation',
										  'post_content'  => '',
										  'post_status'   => 'publish',
										  'post_type' => 'product_variation',
										  'post_author'   => 1,
										  'post_parent' => $product_id
										));
										
										$opt_price = $product['products_price'];
										
										$special_price = $special['specials_new_products_price'];
										
										foreach($combo as $k => $v){
											update_post_meta($variation_id, 'attribute_'.$k, $v[0]);
											$opt_price += $v[1];
											$special_price += $v[1];
										}
										update_post_meta($variation_id, '_sku', $product['products_model']);
										update_post_meta($variation_id, '_regular_price', $opt_price);
										update_post_meta($variation_id, '_price', $opt_price);
										update_post_meta($variation_id, '_thumbnail_id', 0);
										update_post_meta($variation_id, '_stock', $product['products_quantity'] );
										if($special){
											update_post_meta($variation_id, '_sale_price', $special_price);
											if($special['expires_date'] > time()){
												update_post_meta($variation_id, '_sale_price_dates_to', date("Y-m-d",$special['expires_date']));
												update_post_meta($variation_id, '_sale_price_dates_from', date("Y-m-d"));
											}
										}
										if($opt_price > $max_price){
											$max_price = $opt_price;
										}
										if($opt_price < $min_price){
											$min_price = $opt_price;
										}
									}
									
									update_post_meta($product_id, '_product_attributes', $attrib_array);
									update_post_meta($product_id, '_max_variation_regular_price',$max_price);
									update_post_meta($product_id, '_min_variation_regular_price', $min_price);
									update_post_meta($product_id, '_max_variation_price', $max_price);
									update_post_meta($product_id, '_min_variation_price', $min_price);
								}
								
							}
						}
					}
				}
				
				
				if($_POST['dtype']['orders'] == 1){
					$customers = $wpdb->get_results("SELECT ID FROM $wpdb->users", ARRAY_A);
					$customer_id = array();
					foreach($customers as $c){
						$osc_id = get_user_meta($c['ID'],'osc_id',true);
						$customer_id[$osc_id] = $c['ID'];
					}
					
					$product_id = array();
					$products_query = $wpdb->get_results("SELECT ID FROM $wpdb->posts WHERE post_type='product'", ARRAY_A);
					foreach($products_query as $p){
						$osc_id = get_post_meta($p['ID'],'osc_id',true);
						$product_id[$osc_id] = $p['ID'];
					}
					
					
					$country_data = $oscdb->get_results("SELECT * FROM countries", ARRAY_A);
					$countries_name = array();
					foreach($country_data as $cdata){
						$countries_name[$cdata['countries_name']] = $cdata;
					}
					
					if($orders = $oscdb->get_results("SELECT * FROM orders ORDER BY orders_id", ARRAY_A)){
						foreach($orders as $order){
							$existing_order = get_posts(array('post_type' => 'shop_order','posts_per_page' => 1,'post_status' => 'any',
														'meta_query' => array(
															array(
																'key' => 'osc_id',
																'value' => $order['orders_id'],
															)
												)));
							if(empty($existing_order)){
								$totals = array();
								if($total_query = $oscdb->get_results("SELECT * FROM orders_total WHERE orders_id='".$order['orders_id']."'", ARRAY_A)){
									foreach($total_query as $t){
										$totals[$t['class']] = $t;
									}
								}
								
								$order_key = 'order_'.wp_generate_password(13);
								
								$data = array('post_type' => 'shop_order',
											  'post_date' => $order['date_purchased'],
											  'post_author' => $customer_id[$order['customers_id']],
											  'post_password' => $order_key,
											  'post_title' => 'Order &ndash; '.date("M d, Y @ h:i A",strtotime($order['date_purchased'])),
											  'post_status' => 'publish'
											  );
								$order_id = wp_insert_post($data);
								
								
								$billing_namebits = explode(' ',$order['billing_name']);
								$billing_firstname = $billing_namebits[0];
								$billing_lastname = trim(str_replace($billing_namebits[0],'',$order['billing_name']));
								
								$shipping_namebits = explode(' ',$order['delivery_name']);
								$shipping_firstname = $shipping_namebits[0];
								$shipping_lastname = trim(str_replace($shipping_namebits[0],'',$order['delivery_name']));
								
								$meta_data = array('_billing_address_1' => $order['billing_street_address'],
												   '_billing_address_2' => $order['billing_suburb'],
												   '_wpas_done_all' => 1,
												   '_billing_country' => $countries_name[$order['billing_country']]['countries_iso_code_2'],
												   '_billing_first_name' => $billing_firstname,
												   '_billing_last_name' => $billing_lastname,
												   '_billing_company' => $order['billing_company'],
												   '_billing_city' => $order['billing_city'],
												   '_billing_state' => $order['billing_state'],
												   '_billing_postcode' => $order['billing_postcode'],
												   '_billing_phone' => $order['customers_telephone'],
												   '_billing_email' => $order['customers_email_address'],
												   '_shipping_country' => $countries_name[$order['delivery_country']]['countries_iso_code_2'],
												   '_shipping_first_name' => $shipping_firstname,
												   '_shipping_last_name' => $shipping_lastname,
												   '_shipping_company' => $order['delivery_company'],
												   '_shipping_address_1' => $order['delivery_street_address'],
												   '_shipping_address_2' => $order['delivery_suburb'],
												   '_shipping_city' => $order['delivery_city'],
												   '_shipping_state' => $order['delivery_state'],
												   '_shipping_postcode' => $order['delivery_postcode'],
												   '_shipping_method_title' => $totals['ot_shipping']['title'],
												   '_payment_method_title' => $order['payment_method'],
												   '_order_shipping' => $totals['ot_shipping']['value'],
												   '_order_discount' => $totals['ot_coupon']['value'] + $totals['ot_discount']['value'],
												   '_order_tax' => $totals['ot_tax']['value'],
												   '_order_shipping_tax' => 0,
												   '_order_total' => $totals['ot_total']['value'],
												   '_order_key' => $order_key,
												   '_customer_user' => $customer_id[$order['customers_id']],
												   '_order_currency' => $order['currency'],
												   '_prices_include_tax' => 'no',
												   'osc_id' => $order['orders_id']);
								foreach($meta_data as $k => $v){
									update_post_meta($order_id, $k, $v);
								}
								
								$order_import_counter++;
								
								if($order_products = $oscdb->get_results("SELECT * FROM orders_products WHERE orders_id='".$order['orders_id']."'", ARRAY_A)){
									foreach($order_products as $product){
										$item_id = woocommerce_add_order_item( $order_id, array(
													'order_item_name'       => $product['products_name'],
													'order_item_type'       => 'line_item'
													) );
										
										if ( $item_id ) {
											$item_meta = array('_qty' => $product['products_quantity'],
															   '_product_id' => $product_id[$product['products_id']],
															   '_line_subtotal' => $product['final_price'] * $product['products_quantity'],
															   '_line_total' => $product['final_price'] * $product['products_quantity']);
											foreach($item_meta as $k=>$v){
												woocommerce_add_order_item_meta($item_id,$k,$v);
											}
										}    
										
									}
								}
							}
						}
					}
				}
				
				
				if($_POST['dtype']['pages'] == 1){
					$page_import_counter = 0;
					if($information_table = $oscdb->get_results("SHOW TABLES LIKE 'information'", ARRAY_A)){
						if($information_pages = $oscdb->get_results("SELECT * FROM information WHERE language_id=1", ARRAY_A)){
							foreach($information_pages as $information){
								$existing_page = $wpdb->get_results("SELECT ID FROM $wpdb->posts WHERE post_type='page' AND LOWER(post_title)='".strtolower(esc_sql($information['information_title']))."'", ARRAY_A);
								if(!$existing_page){
									$existing_page = get_posts(array('post_type' => 'page','posts_per_page' => 1,'post_status' => 'any',
																'meta_query' => array(
																	array(
																		'key' => 'osc_id',
																		'value' => $information['information_id'],
																	)
														)));
									if(!$existing_page){
										$data = array('post_type' => 'page',
													  'post_title' => $information['information_title'],
													  'post_content' => $information['information_description'],
													  'post_status' => 'publish'
													  );
										$page_id = wp_insert_post($data);
										update_post_meta($page_id, 'osc_id', $information['information_id']);
										$page_import_counter++;
									}
								}
							}
						}
					}else{
						echo '<p class="notice">The information (pages) table does not exist in this osCommerce installation.</p>';
					}
				}
				
				$success = true;
			}else{
				echo '<p class="notice">Could not connect to the osCommerce database</p>';
			}
		}
		if($success){
			echo '<h3>The oscommerce data was successfully imported</h3>';
			if($_POST['dtype']['customers'] == 1){
				echo '<p><strong>Customers Imported: '.$import_customer_counter.'</p>';
			}
			if($_POST['dtype']['orders'] == 1){
				echo '<p><strong>Orders Imported: '.$order_import_counter.'</p>';
			}
			if($_POST['dtype']['products'] == 1){
				echo '<p><strong>Categories Imported: '.$import_cat_counter.'</p>';
				echo '<p><strong>Products Imported: '.$import_prod_counter.'</p>';
			}
			if($_POST['dtype']['pages'] == 1){
				echo '<p><strong>Pages Imported: '.$page_import_counter.'</p>';
			}
		}else{
		?>
		<form action="<?php echo $_SERVER['REQUEST_URI'];?>" method="post">
		<h3>Import data from osCommerce</h3>
		<p>Enter your oscommerce database information (you will need remote access to your oscommerce database)</p>
		<p><label>osCommerce store URL: <input type="text" name="store_url" value="<?php echo $_POST['store_url'];?>"></label></p>
		<p><label>osCommerce Database Host: <input type="text" name="store_host" value="localhost"></label></p>
		<p><label>osCommerce Database User: <input type="text" name="store_user" value="<?php echo $_POST['store_user'];?>"></label></p>
		<p><label>osCommerce Database Password: <input type="text" name="store_pass" value="<?php echo $_POST['store_pass'];?>"></label></p>
		<p><label>osCommerce Database Name: <input type="text" name="store_dbname" value="<?php echo $_POST['store_dbname'];?>"></label></p>
        <p>Data to Import:<br>
		<label><input type="checkbox" name="dtype[customers]" value="1"> Customers (passwords will not be transferred)</label><br>
        <label><input type="checkbox" name="dtype[orders]" value="1"> Orders</label><br>
        <label><input type="checkbox" name="dtype[products]" value="1"> Categories/Products</label><br>
        <label><input type="checkbox" name="dtype[pages]" value="1"> Information Pages</label>
        </p>
		<p><input type="submit" value="Import Data" class="button button-primary button-large"></p>
		</form>
		<?php
		}
	}
	add_action('admin_menu', 'woocommerce_osc_submenu_page',99);
}
?>