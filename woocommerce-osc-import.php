<?php
/*
Plugin Name: Woocommerce osCommerce Import
Plugin URI: http://www.advancedstyle.com/
Description: Import products and categories from osCommerce to Woocommerce
Author: David Barnes
Version: 1.0
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
	
	function woocommerce_osc_submenu_page_callback() {
		global $wpdb, $oscdb, $import_cat_counter, $import_prod_counter;
		if(!empty($_POST)){
			$oscdb = new wpdb($_POST['store_user'],$_POST['store_pass'],$_POST['store_dbname'],$_POST['store_host']);
			if($oscdb->ready){
				echo '<p>Starting...<em>(If the page stops loading or shows a timeout error, then just refresh the page and the importer will continue where it left off.  If you are using a shared server and are importing a lot of products you may need to refresh several times)</p>';
				woocommerce_osc_run_cats();
				
				// Get all categories by OSC cat ID
				$categories = array();
				$terms = get_terms('product_cat',array('hide_empty' => 0));
				foreach ( $terms as $term ) {
					$o = get_woocommerce_term_meta($term->term_id,'osc_id',true);
					$categories[$o] = (int)$term->term_id;
				}
				
				// Import the products
				if($products = $oscdb->get_results("SELECT p.*, pd.*, p2c.categories_id FROM products p, products_description pd, products_to_categories p2c WHERE p.products_id=pd.products_id AND pd.language_id=1 AND p.products_id=p2c.products_id", ARRAY_A)){
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
							wp_set_object_terms($product_id, $categories[$product['categories_id']], 'product_cat');
							update_post_meta($product_id, '_sku', $product['products_model']);
							update_post_meta($product_id, '_regular_price', $product['products_price']);
							update_post_meta($product_id, '_price', $product['products_price']);
							update_post_meta($product_id, '_visibility', 'visible' );
							update_post_meta($product_id, '_stock_status', ($product['products_status'] ? 'instock' : 'outofstock'));
							update_post_meta($product_id, '_manage_stock', '1' );
							update_post_meta($product_id, '_stock', $product['products_quantity']);
							
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
							set_post_thumbnail($product_id, $attach_id);
							
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
				
				$success = true;
			}else{
				echo '<p class="notice">Could not connect to the osCommerce database</p>';
			}
		}
		if($success){
			echo '<h3>The oscommerce data was successfully imported</h3>';
			echo '<p><strong>Categories Imported: '.$import_cat_counter.'</p>';
			echo '<p><strong>Products Imported: '.$import_prod_counter.'</p>';
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
		<p><input type="submit" value="Import Data" class="button button-primary button-large"></p>
		</form>
		<?php
		}
	}
	add_action('admin_menu', 'woocommerce_osc_submenu_page',99);
}
?>