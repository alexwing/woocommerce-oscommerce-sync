<?php
/*
  Plugin Name: Woocommerce osCommerce Sync
  Plugin URI: http://aaranda.es/woocommerce-oscommerce-sync/
  Description: Import products, categories, customers and orders from osCommerce to Woocommerce
  Author: Alejandro Aranda
  Version: 2.0.6
  Author URI: http://www.aaranda.es
  Original Author: David Barnes
  Original Author URI: http://www.advancedstyle.com/
 */


$output = '';
$debug = false;

function otw_plugin_scripts() {
    if (is_admin()) {
        wp_enqueue_script('admin_js_bootstrap', plugins_url('js/bootstrap.min.js', __FILE__), false, '3.3.7', false);
        wp_enqueue_style('admin_css_bootstrap', plugins_url('css/bootstrap.min.css', __FILE__), true, '3.3.7', 'all');
    }
}

add_action('admin_enqueue_scripts', 'otw_plugin_scripts');
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {

    function otw_submenu_page() {
        add_submenu_page('woocommerce', 'osCommerce Sync', 'osCommerce Sync', 'manage_options', 'woocommerce-osc-sync', 'otw_submenu_page_callback');
    }

    function otw_cartesian_product($a) {
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

    function otw_import_image($url) {
        $attach_id = 0;
        $wp_upload_dir = wp_upload_dir();

        $filename = $wp_upload_dir['path'] . '/' . sanitize_file_name(basename($url));

        if (file_exists($filename)) {
            $url = $filename;
        } else {
            //Encode the URL
            $base = basename($url);
            $url = str_replace($base, urlencode($base), $url);
        }

        if ($f = @file_get_contents($url)) {
            file_put_contents($filename, $f);

            $wp_filetype = wp_check_filetype(basename($filename), null);

            $attachment = array(
                'guid' => $wp_upload_dir['url'] . '/' . basename($filename),
                'post_mime_type' => $wp_filetype['type'],
                'post_title' => preg_replace('/\.[^.]+$/', '', basename($filename)),
                'post_content' => '',
                'post_status' => 'inherit'
            );
            $attach_id = wp_insert_attachment($attachment, $filename, 37);
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attach_data = wp_generate_attachment_metadata($attach_id, $filename);
            wp_update_attachment_metadata($attach_id, $attach_data);
        }
        return $attach_id;
    }

    function otw_run_cats($parent = 0, $parent_term_id = 0) {
        global $wpdb, $lang, $oscdb, $import_cat_counter;


        $sql = "SELECT c.*,
               cd.* 
               FROM categories c,
               categories_description cd 
               WHERE c.categories_id=cd.categories_id " . $lang . " AND c.parent_id='" . (int) $parent . "'";
        //otw_log("importCategories", "Sql: " . $sql);
        $categories = $oscdb->get_results($sql, ARRAY_A);
        if (!empty($categories)) {
            otw_log("importCategories", "Categories total: " . count($categories));
            foreach ($categories as $category) {
                if (!is_wp_error($category)) {
                    $term = term_exists($category['categories_name'], 'product_cat', (int) $parent_term_id); // array is returned if taxonomy is given
                    if ((int) $term['term_id'] == 0) {
                        otw_log("importCategories", "New Categorie: " . json_encode($category));
                        $term = wp_insert_term(
                                $category['categories_name'], // the term 
                                'product_cat', // the taxonomy
                                array(
                            //'description'=> $category['categories_id'],
                            'parent' => $parent_term_id
                                )
                        );
                        delete_option('product_cat_children'); // clear the cache

                        $attach_id = 0;
                        if ($category['categories_image'] != '') {
                            if (esc_url($_POST['store_url'])) {

                                if (!empty($_POST['images_url'])) {
                                    //otw_log("importCategories", "Image: " . rtrim($_POST['store_url'], '/') . '/image/' . urlencode($category['categories_image']));
                                    $url = rtrim($_POST['store_url'], '/') . '/' . rtrim($_POST['images_url'], '/') . '/' . urlencode($category['categories_image']);
                                } else {                                                                                   //otw_log("importCategories", "Image: " . rtrim($_POST['store_url'], '/') . '/image/' . urlencode($category['categories_image']));
                                    $url = rtrim($_POST['store_url'], '/') . '/image/' . urlencode($category['categories_image']);
                                }
                                otw_log("importCategories", "Image: " .$url);
                                $attach_id = otw_import_image($url);
                            }
                        }
                        add_woocommerce_term_meta($term['term_id'], 'order', $category['sort_order']);
                        add_woocommerce_term_meta($term['term_id'], 'display_type', '');
                        add_woocommerce_term_meta($term['term_id'], 'thumbnail_id', (int) $attach_id);
                        add_woocommerce_term_meta($term['term_id'], 'osc_id', $category['categories_id']);
                        otw_run_cats($category['categories_id'], $term['term_id']);
                        $import_cat_counter ++;
                    } else {
                        otw_log("importCategories", "Edit Categorie: ".$term['term_id'] .".-." . json_encode($category));
                        
                        delete_option('product_cat_children'); // clear the cache

                        $attach_id = 0;
                        if ($category['categories_image'] != '') {
                            if (esc_url($_POST['store_url'])) {

                                if (!empty($_POST['images_url'])) {
                                    //otw_log("importCategories", "Image: " . rtrim($_POST['store_url'], '/') . '/image/' . urlencode($category['categories_image']));
                                    $url = rtrim($_POST['store_url'], '/') . '/' . rtrim($_POST['images_url'], '/') . '/' . urlencode($category['categories_image']);
                                } else {                                                                                   //otw_log("importCategories", "Image: " . rtrim($_POST['store_url'], '/') . '/image/' . urlencode($category['categories_image']));
                                    $url = rtrim($_POST['store_url'], '/') . '/image/' . urlencode($category['categories_image']);
                                }
                                 otw_log("importCategories", "Image: " .$url);
                                $attach_id = otw_import_image($url);
                            }
                        }
                        delete_woocommerce_term_meta($term['term_id'], 'thumbnail_id');
                        add_woocommerce_term_meta($term['term_id'], 'thumbnail_id', (int) $attach_id);
                        otw_run_cats($category['categories_id'], $term['term_id']);
                    }
                }
            }
        }
    }

    function otw_submenu_page_callback() {
        global $debug, $output, $wpdb, $oscdb, $lang, $import_cat_counter, $import_prod_counter, $import_img_counter, $import_gallery_counter;

        if (isset($_POST['sync_submit']) && wp_verify_nonce($_POST['sync_submit'], 'otw_sync') && !empty($_POST)) {
            if (isset($_POST['debug'])) {
                $debug = true;
            } else {
                $debug = false;
            }
            if (isset($_POST['lang'])) {
                if (!empty($_POST['lang'])) {
                    $lang = ' AND language_id=' . (int) sanitize_text_field($_POST['lang']);
                } else {
                    $lang = ' AND language_id=3';
                }
            } else {
                $lang = ' AND language_id=3';
            }

            $oscdb = new wpdb(sanitize_text_field(trim($_POST['store_user'])), trim(sanitize_text_field($_POST['store_pass'])), trim(sanitize_text_field($_POST['store_dbname'])), trim(sanitize_text_field($_POST['store_host'])));
            if ($oscdb->ready) {
                ob_start();
                // echo '<p>Starting...<em>(If the page stops loading or shows a timeout error, then just refresh the page and the importer will continue where it left off.  If you are using a shared server and are importing a lot of products you may need to refresh several times)</p>';
                // Do customer import

                if ((int) $_POST['dtype']['customers'] == 1) {
                    otw_log_delete("importCustomer");
                    otw_log("importCustomer", "Start Import");
                    $country_data = $oscdb->get_results("SELECT * FROM countries", ARRAY_A);
                    $countries_id = array();
                    foreach ($country_data as $cdata) {
                        $countries_id[$cdata['countries_id']] = $cdata;
                    }
                    $zones = array();
                    $zone_data = $oscdb->get_results("SELECT zone_id, zone_code FROM zones", ARRAY_A);
                    foreach ($zone_data as $z) {
                        $zones[$z['zone_id']] = $z['zone_code'];
                    }
                    $sql = "SELECT c.customers_id,
                              c.customers_firstname,
                              c.customers_lastname,
                              c.customers_telephone,
                              c.customers_email_address,
                              ab.entry_country_id,
                              ab.entry_lastname,
                              ab.entry_firstname,
                              ab.entry_street_address,
                              ab.entry_suburb,
                              ab.entry_postcode,
                              ab.entry_city,
                              ab.entry_state,
                              ab.entry_zone_id 
                              FROM customers c
                              INNER JOIN address_book ab ON  c.customers_id=ab.customers_id AND c.customers_default_address_id=ab.address_book_id
                              ";
                    if ($customers = $oscdb->get_results($sql, ARRAY_A)) {
                        otw_log("importCustomer", "Customers total: " . count($customers));
                        foreach ($customers as $customer) {
                            otw_log("importCustomer", "Customer import: " . json_encode($customer));
                            if (!email_exists($customer['customers_email_address'])) {
                                $original = strtolower(preg_replace("/[^A-Za-z0-9]/", '', $customer['customers_firstname'] . $customer['customers_lastname']));
                                $user_name = $original;

                                $i = 1;
                                while ($user_id = username_exists($user_name)) {
                                    $user_name = $original . $i;
                                    $i++;
                                }

                                $random_password = wp_generate_password();
                                $user_id = wp_create_user($user_name, $random_password, $customer['customers_email_address']);

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
                                foreach ($data as $k => $v) {
                                    update_user_meta($user_id, $k, $v);
                                }

                                if ($user_id > 1) {
                                    wp_update_user(array('ID' => $user_id, 'role' => 'customer'));
                                }

                                $import_customer_counter++;
                            }
                        }
                    }
                    otw_log("importCustomer", "End Import");
                }
                if ((int) $_POST['dtype']['categories'] == 1) {
                    otw_log_delete("importCategories");
                    $import_cat_counter = 0;
                    otw_log("importCategories", "Start Import");
                    otw_run_cats();
                    otw_log("importCategories", "End Import");
                }
                if ((int) $_POST['dtype']['taxes'] == 1) {
                    otw_log_delete("importtaxes");
                    $import_tax_counter = 0;
                    otw_log("importtaxes", "Start Import");
                    $sql = "SELECT
                        tr.tax_rates_id,
                        tr.tax_rate,												
                        tc.tax_class_title,
                        tc.tax_class_description,
                        tr.tax_priority,
                        tr.tax_rate,
                        tr.tax_description,
                        z.*,
                        c.*
                        
                      FROM
                        tax_rates as tr
                        INNER JOIN tax_class as tc ON tr.tax_class_id = tc.tax_class_id
                        INNER JOIN zones as z ON z.zone_id = tr.tax_zone_id
                        INNER JOIN countries as c ON c.countries_id = z.zone_country_id
                    ";
                    //Import the taxes
                    if ($taxes = $oscdb->get_results($sql, ARRAY_A)) {
                        otw_log("importtaxes", "taxes origin total: " . count($taxes));
                        foreach ($taxes as $tax) {
                             otw_log("importtaxes", json_encode($tax));
                             
                            $sql = "
                                ";                             
                             $import_tax_counter++;
                        }
                    }
                    
                    otw_log("importtaxes", "End Import");
                }
                if ((int) $_POST['dtype']['products'] == 1) {
                    otw_log_delete("importProduct");
                    otw_log("importProduct", "Start Import");
                    otw_log("importProduct", "Lang: " . $lang);

                    if (isset($_POST['offset'])) {
                        $offset = (int) sanitize_text_field($_POST['offset']);
                    } else {
                        $offset = 0;
                    }

                    if (isset($_POST['limit'])) {
                        $limit = (int) sanitize_text_field($_POST['limit']);
                    } else {
                        $limit = 0;
                    }
                    //otw_run_cats();
                    // Get all categories by OSC cat ID
                    $categories = array();
                    $terms = get_terms('product_cat', array('hide_empty' => 0));
                    foreach ($terms as $term) {
                        $o = get_woocommerce_term_meta($term->term_id, 'osc_id', true);
                        $categories[$o] = (int) $term->term_id;
                    }
                    $sql = "
                    SELECT p.*, pd.*,
                     p2c.categories_id 
                     FROM products p  
                     LEFT JOIN products_description pd ON p.products_id=pd.products_id
                     LEFT JOIN products_to_categories p2c ON p.products_id=p2c.products_id 
							" . $lang . " 
                    GROUP BY p.products_id    
                    ORDER BY p.products_id
                    
                    ";
                    if (!empty($limit)) {
                        $sql .= "LIMIT " . $limit . " OFFSET " . $offset;
                        otw_log("importProduct", "Offset: " . $offset . " Limit:" . $limit);
                    }
                    // Import the products


                    if ($products = $oscdb->get_results($sql, ARRAY_A)) {
                        otw_log("importProduct", "Products origin total: " . count($products));
                        foreach ($products as $product) {

                            $existing_product = get_posts(array('post_type' => 'product', 'posts_per_page' => 1, 'post_status' => 'any',
                                'meta_query' => array(
                                    array(
                                        'key' => 'osc_id',
                                        'value' => $product['products_id'],
                                    )
                            )));
                            //otw_log("importProduct", "exist".json_encode($existing_product));
                            if (empty($existing_product) && !empty($product['products_name'])) {
                                otw_log("importProduct", json_encode($product));
                                $product_id = wp_insert_post(array(
                                    'post_title' => $product['products_name'],
                                    'post_content' => $product['products_description'],
                                    'post_status' => 'publish',
                                    'post_type' => 'product',
                                    'post_author' => 1
                                ));
                                update_post_meta($product_id, 'osc_id', $product['products_id']);
                                wp_set_object_terms($product_id, 'simple', 'product_type');
                                wp_set_object_terms($product_id, (int) $categories[$product['categories_id']], 'product_cat');
                                update_post_meta($product_id, '_sku', $product['products_model']);
                                update_post_meta($product_id, '_regular_price', $product['products_price']);
                                update_post_meta($product_id, '_price', $product['products_price']);
                                update_post_meta($product_id, '_visibility', 'visible');
                                update_post_meta($product_id, '_stock_status', ($product['products_status'] ? 'instock' : 'outofstock'));
                                update_post_meta($product_id, '_manage_stock', '1');
                                update_post_meta($product_id, '_stock', $product['products_quantity']);
                                update_post_meta($product_id, '_weight', $product['products_weight']);
                                $import_prod_counter++;

                                if ($special = $oscdb->get_row("SELECT specials_new_products_price, expires_date FROM specials WHERE status=1 AND products_id='" . $product_id . "' LIMIT 1", ARRAY_A)) {
                                    update_post_meta($product_id, '_sale_price', $special['specials_new_products_price']);
                                    $special['expires_date'] = strtotime($special['expires_date']);
                                    if ($special['expires_date'] > time()) {
                                        update_post_meta($product_id, '_sale_price_dates_to', date("Y-m-d", $special['expires_date']));
                                        update_post_meta($product_id, '_sale_price_dates_from', date("Y-m-d"));
                                    }
                                }
                                /*
                                  $attach_id = 0;
                                  if($product['products_image'] != ''){
                                  $url = rtrim($_POST['store_url'],'/').'/images/'.urlencode($product['products_image']);
                                  $attach_id = otw_import_image($url);
                                  }
                                  if($attach_id > 0){
                                  set_post_thumbnail($product_id, $attach_id);
                                  }
                                 */
                                // Handle attributes
                                if ($attributes = $oscdb->get_results("SELECT po.products_options_name, pov.products_options_values_name FROM products_attributes pa, products_options po, products_options_values pov WHERE pa.products_id='" . $product['products_id'] . "' AND  pov.products_options_values_id = pa.options_values_id AND pov.language_id=po.language_id AND pa.options_id=products_options_id", ARRAY_A)) {
                                    wp_set_object_terms($product_id, 'variable', 'product_type');

                                    $attrib_array = array();
                                    $attrib_combo = array();
                                    $max_price = $product['products_price'];
                                    $min_price = $product['products_price'];
                                    foreach ($attributes as $attribute) {
                                        $slug = sanitize_title($attribute['products_options_name']);
                                        $attrib_array[$slug] = array('name' => $attribute['products_options_name'],
                                            'value' => ltrim($attrib_array[$slug]['value'] . ' | ' . $attribute['products_options_values_name'], ' | '),
                                            'position' => 0,
                                            'is_visible' => 1,
                                            'is_variation' => 1,
                                            'is_taxonomy' => 0);
                                        $attrib_combo[$slug][] = array($attribute['products_options_values_name'], ($attribute['price_prefix'] == '-' ? '-' : '') . $attribute['options_values_price']);
                                    }
                                    // Now it gets tricky...
                                    $combos = otw_cartesian_product($attrib_combo);
                                    foreach ($combos as $combo) {
                                        $variation_id = wp_insert_post(array(
                                            'post_title' => 'Product ' . $product_id . ' Variation',
                                            'post_content' => '',
                                            'post_status' => 'publish',
                                            'post_type' => 'product_variation',
                                            'post_author' => 1,
                                            'post_parent' => $product_id
                                        ));

                                        $opt_price = $product['products_price'];

                                        $special_price = $special['specials_new_products_price'];

                                        foreach ($combo as $k => $v) {
                                            update_post_meta($variation_id, 'attribute_' . $k, $v[0]);
                                            $opt_price += $v[1];
                                            $special_price += $v[1];
                                        }
                                        update_post_meta($variation_id, '_sku', $product['products_model']);
                                        update_post_meta($variation_id, '_regular_price', $opt_price);
                                        update_post_meta($variation_id, '_price', $opt_price);
                                        update_post_meta($variation_id, '_thumbnail_id', 0);
                                        update_post_meta($variation_id, '_stock', $product['products_quantity']);
                                        if ($special) {
                                            update_post_meta($variation_id, '_sale_price', $special_price);
                                            if ($special['expires_date'] > time()) {
                                                update_post_meta($variation_id, '_sale_price_dates_to', date("Y-m-d", $special['expires_date']));
                                                update_post_meta($variation_id, '_sale_price_dates_from', date("Y-m-d"));
                                            }
                                        }
                                        if ($opt_price > $max_price) {
                                            $max_price = $opt_price;
                                        }
                                        if ($opt_price < $min_price) {
                                            $min_price = $opt_price;
                                        }
                                    }

                                    update_post_meta($product_id, '_product_attributes', $attrib_array);
                                    update_post_meta($product_id, '_max_variation_regular_price', $max_price);
                                    update_post_meta($product_id, '_min_variation_regular_price', $min_price);
                                    update_post_meta($product_id, '_max_variation_price', $max_price);
                                    update_post_meta($product_id, '_min_variation_price', $min_price);
                                }
                            }
                        }
                    }
                    otw_log("importProduct", "End Import");
                }

                if ((int) $_POST['dtype']['delete'] == 1) {
                    otw_log("importDelete", "Start delete");
                    // Delete post thumbs and gallery asociation
                    if ($products = $oscdb->get_results("SELECT * FROM products", ARRAY_A)) {
                        foreach ($products as $product) {
                            $existing_product = get_posts(array('post_type' => 'product', 'posts_per_page' => 1, 'post_status' => 'any',
                                'meta_query' => array(
                                    array(
                                        'key' => 'osc_id',
                                        'value' => $product['products_id'],
                                    )
                            )));
                            if (!empty($existing_product)) {
                                otw_log("importDelete", "Delete image " . $product['products_image']);
                                $product_id = $existing_product[0]->ID;
                                $attach_id = -1;
                                delete_post_thumbnail($product_id);
                                delete_post_meta($product_id, '_product_image_gallery', '');
                            }
                        }
                    }
                    otw_log("importDelete", "End delete");
                }


                if ((int) $_POST['dtype']['image'] == 1) {
                    otw_log_delete("importImage");
                    otw_log("importImage", "Start Import");
                    // Import the IMAGES
                    if ($products = $oscdb->get_results("SELECT * FROM products", ARRAY_A)) {
                        foreach ($products as $product) {
                            $existing_product = get_posts(array('post_type' => 'product', 'posts_per_page' => 1, 'post_status' => 'any',
                                'meta_query' => array(
                                    array(
                                        'key' => 'osc_id',
                                        'value' => $product['products_id'],
                                    )
                            )));
                            //IF PROBLEMS UPDATES IN DATABASE AND RENAME FILES				
                            /* update products set products_image = replace(products_image, ' ', '-');
                              update products set products_image = replace(products_image, '(', '-');
                              update products set products_image = replace(products_image, ')', '-');
                              update products set products_image = replace(products_image, '+', '-'); */
                            if (!empty($existing_product)) {
                                $product_id = $existing_product[0]->ID;
                                $attach_id = 0;
                                if ($product['products_image'] != '') {
                                    if (esc_url($_POST['store_url'])) {

                                        if (!empty($_POST['images_url'])) {
                                            $url = rtrim($_POST['store_url'], '/') . '/' . rtrim($_POST['store_url'], '/') . '/' . ($product['products_image']);
                                        } else {                                                                                   //otw_log("importCategories", "Image: " . rtrim($_POST['store_url'], '/') . '/image/' . urlencode($category['categories_image']));
                                            $url = rtrim($_POST['store_url'], '/') . '/images/' . ($product['products_image']);
                                        }
                                        $url = rtrim($_POST['store_url'], '/') . '/images/' . ($product['products_image']);

                                        global $wpdb;
                                        $filename = ($product['products_image']);
                                        $image_src = $wp_upload_dir['baseurl'] . '/' . _wp_relative_upload_path($filename);
                                        $query = "SELECT ID FROM {$wpdb->posts} WHERE guid like '%$image_src'";
                                        //echo $query;
                                        $count = $wpdb->get_var($query);
                                        if ($count != 0 && $count != null) {
                                            //otw_log ("importImage","Product image: ".$_POST['store_url'], '/' . '/images/' . $product['products_image']);
                                            $attach_id = $count;
                                            //echo ("Product Exist: " . $existing_product[0] -> ID . " Media Exist" . $attach_id ." [".$product['products_image']."]</br>");
                                            otw_log("importImage", "Image edit: " . $existing_product[0]->ID . " New Media [" . $url . "]");
                                        } else {
                                            $import_img_counter++;
                                            $attach_id = otw_import_image($url);
                                            otw_log("importImage", "Image new: " . $existing_product[0]->ID . " New Media [" . $url . "]");
                                        }
                                    }
                                }
                                if ($attach_id > 0) {
                                    set_post_thumbnail($product_id, $attach_id);
                                }
                            }
                        }
                    }
                    otw_log("importImage", "End Import");
                }

                if ((int) $_POST['dtype']['gallery'] == 1) {
                    otw_log_delete("importGallery");
                    otw_log("importGallery", "Start Import");
                    // Import the IMAGES
                    if ($products = $oscdb->get_results("SELECT * FROM products_images ORDER BY sort_order,image", ARRAY_A)) {
                        foreach ($products as $product) {
                            $existing_product = get_posts(array('post_type' => 'product', 'posts_per_page' => 1, 'post_status' => 'any',
                                'meta_query' => array(
                                    array(
                                        'key' => 'osc_id',
                                        'value' => $product['products_id'],
                                    )
                            )));

                            //IF PROBLEMS UPDATES IN DATABASE AND RENAME FILES 
                            /* update products_images set image = replace(image, ' ', '-');
                              update products_images set image = replace(image, '(', '-');
                              update products_images set image = replace(image, ')', '-');
                              update products_images set image = replace(image, '+', '-'); */
                            if (!empty($existing_product)) {
                                $product_id = $existing_product[0]->ID;
                                $attach_id = 0;
                                if ($product['image'] != '') {
                                    if (esc_url($_POST['store_url'])) {

                                        if (!empty($_POST['images_url'])) {
                                            $url = rtrim($_POST['store_url'], '/') . '/' . rtrim($_POST['images_url'], '/') . '/' . ($product['image']);
                                        } else {                                                                                  
                                            $url = rtrim($_POST['store_url'], '/') . '/images/' . ($product['image']);
                                        }

                                        global $wpdb;
                                        $filename = ($product['image']);
                                        $image_src = $wp_upload_dir['baseurl'] . '/' . _wp_relative_upload_path($filename);
                                        $query = "SELECT ID FROM {$wpdb->posts} WHERE guid like '%$image_src'";
                                        $count = $wpdb->get_var($query);
                                        if ($count != 0 && $count != null) {
                                            $attach_id = $count;
                                            otw_log("importGallery", "Image edit: " . $existing_product[0]->ID . " New Media [" . $url . "]");
                                        } else {
                                            $import_gallery_counter++;
                                            $attach_id = otw_import_image($url);
                                            otw_log("importGallery", "Image new: " . $existing_product[0]->ID . " New Media [" . $url . "]");
                                        }
                                    }
                                }
                                if ($attach_id > 0) {

                                    update_post_meta($product_id, '_product_image_gallery', $attach_id);
                                }
                            }
                        }
                    }
                    otw_log("importGallery", "End Import");
                }
                if ((int) $_POST['dtype']['orders'] == 1) {
                    otw_log_delete("importOrders");
                    otw_log("importOrders", "Start Import");
                    $customers = $wpdb->get_results("SELECT ID FROM $wpdb->users", ARRAY_A);
                    $customer_id = array();
                    foreach ($customers as $c) {
                        $osc_id = get_user_meta($c['ID'], 'osc_id', true);
                        $customer_id[$osc_id] = $c['ID'];
                    }

                    $product_id = array();
                    $products_query = $wpdb->get_results("SELECT ID FROM $wpdb->posts WHERE post_type='product'", ARRAY_A);
                    foreach ($products_query as $p) {
                        $osc_id = get_post_meta($p['ID'], 'osc_id', true);
                        $product_id[$osc_id] = $p['ID'];
                    }


                    $country_data = $oscdb->get_results("SELECT * FROM countries", ARRAY_A);
                    $countries_name = array();
                    foreach ($country_data as $cdata) {
                        $countries_name[$cdata['countries_name']] = $cdata;
                    }

                    if ($orders = $oscdb->get_results("SELECT * FROM orders ORDER BY orders_id", ARRAY_A)) {
                        foreach ($orders as $order) {
                            $existing_order = get_posts(array('post_type' => 'shop_order', 'posts_per_page' => 1, 'post_status' => 'any',
                                'meta_query' => array(
                                    array(
                                        'key' => 'osc_id',
                                        'value' => $order['orders_id'],
                                    )
                            )));
                            if (empty($existing_order)) {
                                $totals = array();
                                if ($total_query = $oscdb->get_results("SELECT * FROM orders_total WHERE orders_id='" . $order['orders_id'] . "'", ARRAY_A)) {
                                    foreach ($total_query as $t) {
                                        $totals[$t['class']] = $t;
                                    }
                                }

                                $order_key = 'order_' . wp_generate_password(13);

                                $data = array('post_type' => 'shop_order',
                                    'post_date' => $order['date_purchased'],
                                    'post_author' => $customer_id[$order['customers_id']],
                                    'post_password' => $order_key,
                                    'post_title' => 'Order &ndash; ' . date("M d, Y @ h:i A", strtotime($order['date_purchased'])),
                                    'post_status' => 'wc-completed'
                                );
                                otw_log("importOrders", "Header: " . json_encode($data));
                                $order_id = wp_insert_post($data);


                                $billing_namebits = explode(' ', $order['billing_name']);
                                $billing_firstname = $billing_namebits[0];
                                $billing_lastname = trim(str_replace($billing_namebits[0], '', $order['billing_name']));

                                $shipping_namebits = explode(' ', $order['delivery_name']);
                                $shipping_firstname = $shipping_namebits[0];
                                $shipping_lastname = trim(str_replace($shipping_namebits[0], '', $order['delivery_name']));

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
                                otw_log("importOrders", "address: " . json_encode($meta_data));
                                foreach ($meta_data as $k => $v) {
                                    update_post_meta($order_id, $k, $v);
                                }

                                $order_import_counter++;

                                if ($order_products = $oscdb->get_results("SELECT * FROM orders_products WHERE orders_id='" . $order['orders_id'] . "'", ARRAY_A)) {
                                    foreach ($order_products as $product) {
                                        $item_id = woocommerce_add_order_item($order_id, array(
                                            'order_item_name' => $product['products_name'],
                                            'order_item_type' => 'line_item'
                                        ));

                                        if ($item_id) {
                                            $item_meta = array('_qty' => $product['products_quantity'],
                                                '_product_id' => $product_id[$product['products_id']],
                                                '_line_subtotal' => $product['final_price'] * $product['products_quantity'],
                                                '_line_total' => $product['final_price'] * $product['products_quantity']);

                                            otw_log("importOrders", "product: " . json_encode($item_meta));
                                            foreach ($item_meta as $k => $v) {
                                                woocommerce_add_order_item_meta($item_id, $k, $v);
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                    otw_log("importOrders", "End Import");
                }


                if ((int) $_POST['dtype']['pages'] == 1) {
                    $page_import_counter = 0;
                    if ($information_table = $oscdb->get_results("SHOW TABLES LIKE 'information'", ARRAY_A)) {
                        if ($information_pages = $oscdb->get_results("SELECT * FROM information", ARRAY_A)) {
                            foreach ($information_pages as $information) {
                                $existing_page = $wpdb->get_results("SELECT ID FROM $wpdb->posts WHERE post_type='page' AND LOWER(post_title)='" . strtolower(esc_sql($information['information_title'])) . "'", ARRAY_A);
                                if (!$existing_page) {
                                    $existing_page = get_posts(array('post_type' => 'page', 'posts_per_page' => 1, 'post_status' => 'any',
                                        'meta_query' => array(
                                            array(
                                                'key' => 'osc_id',
                                                'value' => $information['information_id'],
                                            )
                                    )));
                                    if (!$existing_page) {
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
                    } else {
                        echo '<p class="notice">The information (pages) table does not exist in this osCommerce installation.</p>';
                    }
                }
                $output = ob_get_contents();
                ob_end_clean();
                $success = true;
            } else {
                echo '<p class="notice">Could not connect to the osCommerce database</p>';
            }
        }
        ?>
        <form method="post" action="">

            <?php
            wp_nonce_field('otw_sync', 'sync_submit');
            if ($success) {
                ?>
                <div class="row">
                    <div class="col-lg-12">
                        <div class="alert alert-success">
                            <h3>The oscommerce data was successfully imported</h3>
                            <?php
                            if ((int) $_POST['dtype']['customers'] == 1) {
                                echo '<p>Customers Imported: ' . $import_customer_counter . '</p>';
                            }
                            if ((int) $_POST['dtype']['orders'] == 1) {
                                echo '<p>Orders Imported: ' . $order_import_counter . '</p>';
                            }
                            if ((int) $_POST['dtype']['categories'] == 1) {
                                echo '<p>Categories Imported: ' . $import_cat_counter . '</p>';
                            }
                            if ((int) $_POST['dtype']['taxes'] == 1) {
                                echo '<p>Taxes Imported: ' . $import_tax_counter . '</p>';
                            }
                            if ((int) $_POST['dtype']['products'] == 1) {
                                echo '<p>Products Imported: ' . $import_prod_counter . '</p>';
                            }
                            if ((int) $_POST['dtype']['image'] == 1) {
                                echo '<p>Images Imported: ' . $import_img_counter . '</p>';
                            }
                            if ((int) $_POST['dtype']['gallery'] == 1) {
                                echo '<p>Images gallery Imported: ' . $import_gallery_counter . '</p>';
                            }
                            if ((int) $_POST['dtype']['pages'] == 1) {
                                echo '<p>Pages Imported: ' . $page_import_counter . '</p>';
                            }
                            ?>
                        </div>
                    </div>
                </div>
                <?php
            }
            ?>
            <div class="row">
                <div class="col-md-6 form-control" style="background-color: #f1f1f1">
                    <h3>Import data from osCommerce</h3>
                    <p>For big products database can import in steps</p>		
                    <p><label class="control-label">Debug: <input type="checkbox" name="debug" value="1"  <?php
                            if ($_POST['debug']) {
                                echo " checked ";
                            }
                            ?>></label><small>(Display and save a log file in WordPress root folder)</small></p>
                    <p><label class="control-label">Language (Id from osCommerce lang table): <input type="text" name="lang" value="<?php echo sanitize_text_field($_POST['lang']); ?>"></label></p>
                    <p><label class="control-label">Offset (Last product imported): <input type="text" name="offset" value="<?php echo sanitize_text_field($_POST['offset']); ?>"></label></p>
                    <p><label class="control-label">Limit (how many produts imported): <input type="text" name="limit" value="<?php echo sanitize_text_field($_POST['limit']); ?>"></label></p>
                    <p>Enter your oscommerce database information (you will need remote access to your oscommerce database)</p>
                    <p><label class="control-label">osCommerce store URL: <input type="text" name="store_url" value="<?php echo sanitize_text_field($_POST['store_url']); ?>"></label><label class="control-label">&nbsp;&nbsp;&nbsp;Images directory: <small>(empty directory "image") </small><input type="text" name="images_url" value="<?php echo sanitize_text_field($_POST['images_url']); ?>"></label></p>
                    <p><label class="control-label">osCommerce Database Host: <input type="text" name="store_host" value="localhost"></label></p>
                    <p><label class="control-label">osCommerce Database User: <input type="text" name="store_user" value="<?php echo sanitize_text_field($_POST['store_user']); ?>"></label></p>
                    <p><label class="control-label">osCommerce Database Password: <input type="text" name="store_pass" value="<?php echo sanitize_text_field($_POST['store_pass']); ?>"></label></p>
                    <p><label class="control-label">osCommerce Database Name: <input type="text" name="store_dbname" value="<?php echo sanitize_text_field($_POST['store_dbname']); ?>"></label></p>
                </div>
                <div class="col-md-6 form-control" style="background-color: #f1f1f1">
                    <h3>Data to Import:</h3>
                    <h5>It is recommended to follow the order of the list</h5>
                    <label class="control-label"><input type="checkbox" name="dtype[customers]" value="1" <?php
                        if ((int) $_POST['dtype']['customers']) {
                            echo " checked ";
                        }
                        ?>>Customers (passwords will not be transferred)</label><br>

                    <label class="control-label"><input type="checkbox" name="dtype[categories]" value="1" <?php
                        if ((int) $_POST['dtype']['categories']) {
                            echo " checked ";
                        }
                        ?>>Categories</label><br>
                   <!-- <label class="control-label"><input type="checkbox" name="dtype[taxes]" value="1" <?php
                        if ((int) $_POST['dtype']['taxes']) {
                            echo " checked ";
                        }
                        ?>>Taxes</label><br>-->
                    <label class="control-label"><input type="checkbox" name="dtype[products]" value="1"  <?php
                        if ((int) $_POST['dtype']['products']) {
                            echo " checked ";
                        }
                        ?>>Products</label><br>
                    <label class="control-label"><input type="checkbox" name="dtype[delete]" value="1"  <?php
                        if ((int) $_POST['dtype']['delete']) {
                            echo " checked ";
                        }
                        ?>>Products delete images </label><br>
                    <label class="control-label"><input type="checkbox" name="dtype[image]" value="1"  <?php
                        if ((int) $_POST['dtype']['image']) {
                            echo " checked ";
                        }
                        ?>>Products Prefered image</label><br>
                    <label class="control-label"><input type="checkbox" name="dtype[gallery]" value="1"  <?php
                        if ((int) $_POST['dtype']['gallery']) {
                            echo " checked ";
                        }
                        ?>>Products gallery</label><br>
                    <label class="control-label"><input type="checkbox" name="dtype[orders]" value="1" <?php
                        if ((int) $_POST['dtype']['orders']) {
                            echo " checked ";
                        }
                        ?>>Orders</label><br>
                    <label class="control-label"><input type="checkbox" name="dtype[pages]" value="1"  <?php
                        if ((int) $_POST['dtype']['pages']) {
                            echo " checked ";
                        }
                        ?>>Information Pages</label>

                    <p><input type="submit" value="Import Data" class="button button-primary button-large"></p>
                </div>
            </div>
        </form>
        <?php
        if ($output) {
            echo '<div class="col-md-12"><h3>Debug</h3><pre style="padding:10px;max-height:300px;overflow-y:auto;background-color:#cdcdcd;">';
            echo $output;
            echo '</pre></div>';
        }
    }

    add_action('admin_menu', 'otw_submenu_page', 99);
}

// function remove Log
function otw_log_delete($log) {
    @unlink(get_home_path() . $log . '.log');
}

// function write Log
function otw_log($log, $data) {
    global $debug;
    if ($debug) {
        $fp = fopen(get_home_path() . $log . '.log', 'a+');
        fwrite($fp, date('Y-m-d H:i:s') . ' ' . $data . "\r\n");
        fclose($fp);
        echo "$data" . PHP_EOL;
    }
}
?>
