<?php

/*******************************************************************************
 *
 *  Copyrights 2017 to Present - Sellergize Web Technology Services Pvt. Ltd. - ALL RIGHTS RESERVED
 *
 * All information contained herein is, and remains the
 * property of Sellergize Web Technology Services Pvt. Ltd.
 *
 * The intellectual and technical concepts & code contained herein are proprietary
 * to Sellergize Web Technology Services Pvt. Ltd. (India), and are covered and protected
 * by copyright law. Reproduction of this material is strictly forbidden unless prior
 * written permission is obtained from Sellergize Web Technology Services Pvt. Ltd.
 * 
 * ******************************************************************************/
global $coupons_to_be_inserted;
if (!defined('ABSPATH')) exit; // Exit if accessed directly

function linkmydeals_pull_feed()
{

	set_time_limit(0);

	global $wpdb;
	$wp_prefix = $wpdb->prefix;

	$config = $wpdb->get_row("SELECT
								(SELECT value FROM {$wp_prefix}linkmydeals_config WHERE name = 'API_KEY') API_KEY,
								(SELECT value FROM {$wp_prefix}linkmydeals_config WHERE name = 'last_extract') last_extract
							FROM dual");

	if (empty($config->API_KEY)) {
		wp_clear_scheduled_hook('linkmydeals_pull_feed_event');
		return '<div class="notice notice-error is-dismissible"><p>Cannot pull feed without API Key.</p></div>';
	}

	if (empty($config->last_extract)) {
		$config->last_extract = '978307200';
	}

	$usage = json_decode(file_get_contents("http://feed.linkmydeals.com/getUsageDetails/?API_KEY={$config->API_KEY}"), true);
	$default_format = (strpos($usage['default_format'], 'csv') !== false) ? 'json' : $usage['default_format'];

	$feedFile = "http://feed.linkmydeals.com/getOffers/?API_KEY={$config->API_KEY}&incremental=1&last_extract={$config->last_extract}&format={$default_format}";

	$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'info','Pulling Feed using LinkMyDeals')");
	$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','$feedFile')");
	$wpdb->query('SET autocommit = 0;');

	$result = linkmydeals_save_json_to_db($feedFile);

	if ($result['couponCount'] == 0) {
		// If the account is temporarily inactive, we do not get any offers in the file.
		// Not updating the last_extract time in such situations, prevents loss of data after re-activation.
		$wpdb->query('SET autocommit = 1;');
		$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'success','No updates found in this extract')");
		return '<div class="notice notice-info is-dismissible"><p>No updates found in this extract.</p></div>';
	} elseif (!$result['error']) {
		$wpdb->query("REPLACE INTO " . $wp_prefix . "linkmydeals_config (name,value) VALUES ('last_extract','" . time() . "') ");
		$wpdb->query('COMMIT;');
		$wpdb->query('SET autocommit = 1;');
		$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'info','Starting upload process. This may take several minutes...') ");
		wp_schedule_single_event(time(), 'linkmydeals_process_batch_event'); // process next batch
		return '<div class="notice notice-info is-dismissible"><p>Upload process is running in background. Refresh Logs to see current status.</p></div>';
	} else {
		$wpdb->query('ROLLBACK');
		$wpdb->query('SET autocommit = 1;');
		$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','" . esc_sql($result['error_msg']) . "'), (" . microtime(true) . ",'error','Error uploading feed to local database')");
		return '<div class="notice notice-error is-dismissible"><p>Error uploading feed to local database.</p></div>';
	}
}


function linkmydeals_save_json_to_db($feedURL)
{
	global $coupons_to_be_inserted;
	$coupons_to_be_inserted = array();

	global $wpdb;
	$wp_prefix = $wpdb->prefix;

	$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'info','Preparing to Save to DB')");

	$response = json_decode(file_get_contents($feedURL), true);

	if (!$response['result']) return array('error' => true, 'error_msg' => $response['error']);

	if (!isset($response['offers']) or count($response['offers']) === 0) return array('error' => false, 'couponCount' => 0);

	$couponCount = 0;
	foreach ($response['offers'] as $id => $coupon) {			//coupon as key=>value array
		$result = linkmydeals_save_coupon_to_queue($coupon);
		if ($result['error']) return $result;
		$couponCount++; //keeps track of total coupons
	}

	return array_merge(linkmydeals_insert_coupons_to_db(), array('couponCount' => $couponCount));
}


function linkmydeals_save_csv_to_db($feedFile)
{
	global $coupons_to_be_inserted;
	global $wpdb;

	$coupons_to_be_inserted = array(); //initialize the queue
	$wp_prefix = $wpdb->prefix;

	$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'info','Preparing to Save to DB')");

	if (($handle = fopen($feedFile, 'r')) === false)  return array('error' => true, 'error_msg' => "cannot open  $feedFile file");

	// $feedFile is set by API or File Upload
	$topheader = fgetcsv($handle, 10000, ','); //gets the header (key)
	$topheader_db = array('lmd_id', 'store', 'merchant_homepage', 'long_offer', 'title', 'description', 'code', 'terms_and_conditions', 'categories', 'featured', 'url', 'smartlink', 'image_url', 'type', 'offer', 'offer_value', 'status', 'start_date', 'end_date');
	if(in_array('locations', $topheader)) {
		$topheader_db = array('lmd_id', 'store', 'merchant_homepage', 'long_offer', 'title', 'description', 'code', 'terms_and_conditions', 'categories', 'featured', 'url', 'smartlink', 'image_url', 'type', 'offer', 'offer_value', 'locations', 'status', 'start_date', 'end_date');
	}
	$topheader_diff = array_diff($topheader_db, $topheader);
	if (!empty($topheader_diff))  return array('error' => true, 'error_msg' => "header error - missing colums (" . implode(",", $topheader_diff) . ")");

	$couponCount = 0;
	while (($row = fgetcsv($handle, 10000, ',')) !== false) {

		$coupon = array_combine($topheader, $row); //coupon as key=>value array

		$couponCount++;	//keeps track of total coupons
		$msg  = "for coupon number $couponCount (row number " . ($couponCount + 1) . ")";

		if (empty($coupon['lmd_id']))														return array('error' => true, 'error_msg' => "lmd_id missing $msg");
		elseif (empty($coupon['title']))													return array('error' => true, 'error_msg' => "title missing $msg");
		elseif (!empty($coupon['start_date']) and empty(strtotime($coupon['start_date'])))	return array('error' => true, 'error_msg' => "invalid start date $msg");
		elseif (!empty($coupon['end_date']) and empty(strtotime($coupon['end_date'])))		return array('error' => true, 'error_msg' => "invalid end date $msg");

		$result  = linkmydeals_save_coupon_to_queue($coupon);
		if ($result['error']) {
			return $result;
		}
	}

	return linkmydeals_insert_coupons_to_db();
}


function linkmydeals_save_coupon_to_queue($coupon)
{
	global $coupons_to_be_inserted;
	array_push($coupons_to_be_inserted, $coupon);

	//Fire Query to save coupons to db if no. of coupons >500 else return
	return count($coupons_to_be_inserted) >= 500 ? linkmydeals_insert_coupons_to_db() : array('error' => false);
}


function linkmydeals_insert_coupons_to_db()
{
	global $coupons_to_be_inserted;
	global $wpdb;

	$wp_prefix = $wpdb->prefix;


	if (count($coupons_to_be_inserted) === 0) return array('error' => false);

	$sql_insert = "INSERT INTO {$wp_prefix}linkmydeals_upload (lmd_id, status, title, description, excerpt, badge, type, code, categories, locations, store, homepage_url, url, image_url, terms_and_conditions, start_date, end_date, featured, upload_date) VALUES ";
	$sep = '';
	$config = $wpdb->get_row("SELECT
								(SELECT value FROM {$wp_prefix}linkmydeals_config WHERE name = 'cashback_mode') cashback_mode,
								(SELECT value FROM {$wp_prefix}linkmydeals_config WHERE name = 'cashback_id') cashback_id
							FROM dual");


	foreach ($coupons_to_be_inserted as $coupon) {

		$coupon['smartLink'] = ((isset($coupon['smartLink']) and (!empty($coupon['smartLink'])))
			? $coupon['smartLink']
			: ((isset($coupon['smartlink']) and (!empty($coupon['smartlink'])))
				? $coupon['smartlink']
				: null));
				
		if ($config->cashback_mode == 'On') {
			$coupon['smartLink'] .= "&s1=" . $config->cashback_id;
		}
		$locations = "";
		if(isset($coupon['locations']) and $coupon['locations'] != 'Worldwide') {
			$locations = $coupon['locations'];
		}
		$sql_insert .= $sep . "(" . $coupon['lmd_id'] . ",
									'" . $coupon['status'] . "',
									'" . esc_sql($coupon['title']) . "',
									'" . esc_sql($coupon['description']) . "',
									'" . esc_sql($coupon['offer text'] ?? $coupon['offer_text'] ?? $coupon['long_offer']) . "',
									'" . esc_sql($coupon['offer value'] ?? $coupon['offer_value']) . "',
									'" . esc_sql($coupon['type']) . "',
									'" . esc_sql($coupon['code']) . "',
									'" . esc_sql($coupon['categories']) . "',
									'" . esc_sql($locations) . "',
									'" . esc_sql($coupon['store']) . "',
									'" . esc_sql($coupon['merchant_homepage']) . "',
									'" . esc_sql($coupon['smartLink']) . "',
									'" . esc_sql($coupon['image_url']) . "',
									'" . esc_sql($coupon['terms_and_conditions']) . "',
									'" . (empty($coupon['start_date']) ? '1970-01-01' : date('Y-m-d', strtotime($coupon['start_date']))) . "',
									'" . (empty($coupon['end_date']) ? '1970-01-01' : date('Y-m-d', strtotime($coupon['end_date']))) . "',
									'" . esc_sql($coupon['featured']) . "',
									NOW())";
		$sep = ',';
	}

	if ($wpdb->query($sql_insert) === false) {

		$error_msg = $wpdb->last_error . PHP_EOL . 'Query: ' . $sql_insert;

		$wpdb->print_error();
		$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','" . esc_sql($sql_insert) . "')");
		$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'error','" . esc_sql($error_msg) . "')");

		return array('error' => true, 'error_msg' => $error_msg);
	}

	$coupons_to_be_inserted = array(); //reset coupon array
	return array('error' => false);
}


function linkmydeals_process_batch()
{
	global $wpdb;

	$wp_prefix = $wpdb->prefix;

	$theme = get_template();

	$result = $wpdb->get_results("SELECT * FROM {$wp_prefix}linkmydeals_config WHERE name IN ('import_images','batch_size' ,'code_text', 'store', 'category', 'location','featured_image')");

	$config = array('batch_size' => 20, 'import_images' => 'Off','code_text' => '(not required)', 'store' => 'post_tag' , 'category' => 'category' , 'location' => 'none','featured_image' => 'Off');
	foreach ($result as $row) {
		$config[$row->name] = $row->value;
	}

	$batch_start_time = microtime(true);
	wp_defer_term_counting(true);
	$wpdb->query('SET autocommit = 0;');

	$coupons = $wpdb->get_results("SELECT * FROM {$wp_prefix}linkmydeals_upload ORDER BY upload_date LIMIT 0, {$config['batch_size']}");

	if ($theme == 'clipmydeals')																	linkmydeals_clipmydeals_process_batch($coupons, $config);
	elseif ($theme == 'clipper')																	linkmydeals_clipper_process_batch($coupons);
	elseif ($theme == 'couponxl')																	linkmydeals_couponxl_process_batch($coupons, $config);
	elseif ($theme == 'couponxxl')																	linkmydeals_couponxxl_process_batch($coupons, $config);
	elseif ($theme == 'couponer')																	linkmydeals_couponer_process_batch($coupons);
	elseif ($theme == 'rehub' or $theme == 'rehub-theme')											linkmydeals_rehub_process_batch($coupons, $config);
	elseif ($theme == 'wpcoupon' or $theme == 'wp-coupon' or $theme == 'wp-coupon-pro')				linkmydeals_wpcoupon_process_batch($coupons, $config);
	elseif ($theme == 'CP' or $theme == 'cp' or $theme == 'CPq' or substr($theme, 0, 2) === "CP" or str_contains(wp_get_theme()->get( 'AuthorURI' ),'premiumpress'))	linkmydeals_couponpress_process_batch($coupons, $config);
	elseif ($theme == 'mts_coupon')																	linkmydeals_mtscoupon_process_batch($coupons, $config);
	elseif ($theme == 'couponis')																	linkmydeals_couponis_process_batch($coupons, $config);
	elseif ($theme == 'couponhut')																	linkmydeals_couponhut_process_batch($coupons, $config);
	elseif ($theme == 'coupon-mart')																linkmydeals_couponmart_process_batch($coupons,$config);
	elseif ($theme == 'coupon-press')																linkmydeals_coupon_press_process_batch($coupons,$config);
	else																							linkmydeals_generic_theme_process_batch($coupons,$config);

	wp_defer_term_counting(false);
	$wpdb->query('COMMIT;');
	$wpdb->query('SET autocommit = 1;');
	$batch_end_time = microtime(true);

	$remainingCoupons = $wpdb->get_var("SELECT count(1) FROM {$wp_prefix}linkmydeals_upload");
	if ($remainingCoupons > 0) {
		$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message,duration) VALUES (" . microtime(true) . ",'debug','Batch - Successfully Processed', " . ($batch_end_time - $batch_start_time) . ")");
		wp_schedule_single_event(time(), 'linkmydeals_process_batch_event'); // process next batch
	} else {
		$wpdb->query("DELETE FROM {$wp_prefix}linkmydeals_logs WHERE logtime < CURDATE() - INTERVAL 30 DAY");
		$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'success','All offers processed successfully.')");
	}
}


function linkmydeals_clipmydeals_process_batch($coupons, &$config)
{
	global $wpdb;
	$wp_prefix = $wpdb->prefix;

	$categories = array();
	$categoryTerms = get_terms(array(
		'taxonomy' => 'offer_categories',
		'hide_empty' => false
	));
	foreach ($categoryTerms as $term) {
		$categories[$term->name] = $term->slug;
	}

	$stores = array();
	$storeTerms = get_terms(array(
		'taxonomy' => 'stores',
		'hide_empty' => false
	));
	foreach ($storeTerms as $term) {
		$stores[$term->name] = $term->slug;
	}

	if (get_theme_mod('location_taxonomy', false)) {
		$locations = array();
		$locationTerms = get_terms(array(
			'taxonomy' => 'locations',
			'hide_empty' => false
		));
		foreach ($locationTerms as $term) {
			$locations[$term->name] = $term->slug;
		}
	}

	$count_new = $count_suspended = $count_updated = 0;
	$found_count = is_array($coupons) ? count($coupons) : 0;

	$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'info','Found $found_count coupons to process')");

	foreach ($coupons as $coupon) {

		if ($coupon->status == 'new' or $coupon->status == '' or $coupon->status == 'active') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Adding New Coupon ({$coupon->lmd_id})')");

			$post_data = array(
				'ID'             => '',
				'post_title'     => $coupon->title,
				'post_content'   => $coupon->description,
				'post_status'    => 'publish',
				'post_type'      => 'coupons',
				'post_author'    => get_current_user_id()
			);

			$post_id = wp_insert_post($post_data);

			$cat_names = explode(',', $coupon->categories);
			foreach ($cat_names as $cat) {
				wp_set_object_terms($post_id, $cat, 'offer_categories', true);
			}

			$store_names = explode(',', $coupon->store);
			foreach ($store_names as $str) {
				// Create New Store
				$term = wp_insert_term($str, 'stores'); 							// , $args third parameter
				if (!is_wp_error($term)) { 											// Term did not exist. Got inserted now.
					$stores[$str] = get_term($term['term_id'], "stores")->slug;
					$meta_args = array("store_url"	=> $coupon->homepage_url); 		//store taxonomy args in wp_options
					update_option("taxonomy_term_{$term['term_id']}", $meta_args);	// Update Meta Info
					$domain = str_replace("www.", "", parse_url($meta_args["store_url"], PHP_URL_HOST));
					$wpdb->query("INSERT INTO {$wp_prefix}cmd_store_to_domain (store_id, domain) VALUES ({$term['term_id']},'{$domain}');");
				}
				wp_set_object_terms($post_id, $str, 'stores', true);
			}

			if (!empty($coupon->locations) && get_theme_mod('location_taxonomy', false)) {
				$loc_names = explode(',', $coupon->locations);
				$append = false;
				foreach ($loc_names as $loc) {
					wp_set_object_terms($post_id, $loc, 'locations', $append);
					$append = true;
				}
			}

			if (!empty($coupon->image_url) and $config['import_images'] != 'Off' and (empty($coupon->lmd_id) or clipmydeals_validImgDimensions($coupon->image_url))) {
				update_post_meta($post_id, 'cmd_image_url', $coupon->image_url);
			}

			update_post_meta($post_id, 'lmd_id', $coupon->lmd_id);
			update_post_meta($post_id, 'cmd_type', $coupon->type == 'Code' ? 'code' : 'deal');
			update_post_meta($post_id, 'cmd_code', $coupon->code);
			update_post_meta($post_id, 'cmd_badge', $coupon->badge);
			update_post_meta($post_id, 'cmd_url', $coupon->url);
			update_post_meta($post_id, 'cmd_start_date', $coupon->start_date == '1970-01-01' ? '' : $coupon->start_date);
			update_post_meta($post_id, 'cmd_valid_till', $coupon->end_date == '1970-01-01' ? '' : $coupon->end_date);
			update_post_meta($post_id, 'cmd_verified_on', current_time('Y-m-d'));
			update_post_meta($post_id, 'cmd_display_priority', $coupon->featured == 'Yes' ? 10 : 0);

			$count_new = $count_new + 1;
		} elseif ($coupon->status == 'updated') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Updating Coupon ({$coupon->lmd_id})')");

			$post_id = $wpdb->get_var("SELECT post_id FROM {$wp_prefix}postmeta WHERE meta_key = 'lmd_id' AND meta_value = '{$coupon->lmd_id}' LIMIT 0,1");

			$post_data = array(
				'ID'             => $post_id,
				'post_title'     => $coupon->title,
				'post_content'   => $coupon->description,
				'post_status'    => 'publish',
				'post_author'    => get_current_user_id()
			);

			wp_update_post($post_data);

			$cat_names = explode(',', $coupon->categories);
			$append = false;
			foreach ($cat_names as $cat) {
				wp_set_object_terms($post_id, $cat, 'offer_categories', $append);
				$append = true;
			}

			$store_names = explode(',', $coupon->store);
			$append = false;
			foreach ($store_names as $str) {
				wp_set_object_terms($post_id, $str, 'stores', $append);
				$append = true;
			}

			if (!empty($coupon->locations) && get_theme_mod('location_taxonomy', false)) {
				$loc_names = explode(',', $coupon->locations);
				$append = false;
				foreach ($loc_names as $loc) {
					wp_set_object_terms($post_id, $loc, 'locations', $append);
					$append = true;
				}
			}

			if (!empty($coupon->image_url) and $config['import_images'] != 'Off' and (empty($coupon->lmd_id) or clipmydeals_validImgDimensions($coupon->image_url))) {
				update_post_meta($post_id, 'cmd_image_url', $coupon->image_url);
			}

			update_post_meta($post_id, 'cmd_type', $coupon->type == 'Code' ? 'code' : 'deal');
			update_post_meta($post_id, 'cmd_code', $coupon->code);
			update_post_meta($post_id, 'cmd_badge', $coupon->badge);
			update_post_meta($post_id, 'cmd_url', $coupon->url);
			update_post_meta($post_id, 'cmd_start_date', $coupon->start_date == '1970-01-01' ? '' : $coupon->start_date);
			update_post_meta($post_id, 'cmd_valid_till', $coupon->end_date == '1970-01-01' ? '' : $coupon->end_date);
			update_post_meta($post_id, 'cmd_verified_on', current_time('Y-m-d'));
			update_post_meta($post_id, 'cmd_display_priority', $coupon->featured == 'Yes' ? 10 : 0);

			$count_updated = $count_updated + 1;
		} elseif ($coupon->status == 'suspended') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Suspending Coupon ({$coupon->lmd_id})')");

			$post_id = $wpdb->get_var("SELECT post_id FROM {$wp_prefix}postmeta WHERE meta_key = 'lmd_id' AND meta_value = '{$coupon->lmd_id}' LIMIT 0,1");

			wp_delete_post($post_id, true);

			$count_suspended = $count_suspended + 1;
		}

		$wpdb->query("DELETE FROM {$wp_prefix}linkmydeals_upload WHERE lmd_id = {$coupon->lmd_id}");
	}

	$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'info','Processed Offers - $count_new New , $count_updated Updated , $count_suspended Suspended.')");
}


function linkmydeals_clipper_process_batch($coupons)
{
	global $wpdb;
	$wp_prefix = $wpdb->prefix;

	$categories = array();
	$categoryTerms = get_terms(array(
		'taxonomy' => 'coupon_category',
		'hide_empty' => false
	));
	foreach ($categoryTerms as $term) {
		$categories[$term->name] = $term->slug;
	}

	$stores = array();
	$storeTerms = get_terms(array(
		'taxonomy' => 'stores',
		'hide_empty' => false
	));
	foreach ($storeTerms as $term) {
		$stores[$term->name] = $term->slug;
	}

	$count_new = $count_suspended = $count_updated = 0;
	$found_count = is_array($coupons) ? count($coupons) : 0;

	$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'info','Found $found_count coupons to process')");

	foreach ($coupons as $coupon) {

		if ($coupon->status == 'new' or $coupon->status == '' or $coupon->status == 'active') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Adding New Coupon ({$coupon->lmd_id})')");

			$post_data = array(
				'ID'             => '',
				'post_title'     => $coupon->title,
				'post_content'   => $coupon->description,
				'post_status'    => 'publish',
				'post_type'      => 'coupon',
				'post_author'    => get_current_user_id()
			);

			$post_id = wp_insert_post($post_data);

			$cat_names = explode(',', $coupon->categories);
			foreach ($cat_names as $cat) {
				wp_set_object_terms($post_id, $cat, 'coupon_category', true);
				wp_set_object_terms($post_id, $cat, 'coupon_tag', true);
			}

			$store_names = explode(',', $coupon->store);
			foreach ($store_names as $str) {
				// Create New Store
				$term = wp_insert_term($str, 'stores');
				if (!is_wp_error($term)) {
					$stores[$str] = get_term($term['term_id'], "stores")->slug;
					$wpdb->query("INSERT INTO {$wp_prefix}clpr_storesmeta (stores_id, meta_key, meta_value) VALUES ({$term['term_id']},'clpr_store_url','{$coupon->homepage_url}');");
				}
				wp_set_object_terms($post_id, $str, 'stores', true);
			}

			wp_set_object_terms($post_id, ($coupon->type == 'Code' ? 'Coupon Code' : 'Promotion'), 'coupon_type', true);

			update_post_meta($post_id, 'lmd_id', $coupon->lmd_id);
			update_post_meta($post_id, 'coupon_type', $coupon->type == 'Code' ? 'coupon-code' : 'promotion');
			update_post_meta($post_id, 'clpr_coupon_aff_url', $coupon->url);
			update_post_meta($post_id, 'clpr_coupon_code', $coupon->code);
			update_post_meta($post_id, 'clpr_expire_date', $coupon->end_date);
			update_post_meta($post_id, 'clpr_featured', $coupon->featured == 'Yes' ? 1 : '');
			update_post_meta($post_id, 'clpr_votes_percent', '100');
			update_post_meta($post_id, 'clpr_coupon_aff_clicks', '0');
			update_post_meta($post_id, 'clpr_daily_count', '0');
			update_post_meta($post_id, 'clpr_total_count', '0');
			update_post_meta($post_id, 'clpr_votes_up', '0');
			update_post_meta($post_id, 'clpr_votes_down', '0');
			update_post_meta($post_id, 'clpr_sys_userIP', '::1');
			update_post_meta($post_id, 'clpr_print_url', '');
			update_post_meta($post_id, 'clpr_print_imageid', '0');

			$count_new = $count_new + 1;
		} elseif ($coupon->status == 'updated') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Updating Coupon ({$coupon->lmd_id})')");

			$post_id = $wpdb->get_var("SELECT post_id FROM {$wp_prefix}postmeta WHERE meta_key = 'lmd_id' AND meta_value = '{$coupon->lmd_id}' LIMIT 0,1");

			$post_data = array(
				'ID'             => $post_id,
				'post_title'     => $coupon->title,
				'post_content'   => $coupon->description,
				'post_status'    => 'publish',
				'post_author'    => get_current_user_id()
			);

			wp_update_post($post_data);

			$cat_names = explode(',', $coupon->category);
			$append = false;
			foreach ($cat_names as $cat) {
				wp_set_object_terms($post_id, $cat, 'coupon_category', $append);
				wp_set_object_terms($post_id, $cat, 'coupon_tag', $append);
				$append = true;
			}

			$store_names = explode(',', $coupon->store);
			$append = false;
			foreach ($store_names as $str) {
				wp_set_object_terms($post_id, $str, 'stores', $append);
				$append = true;
			}

			wp_set_object_terms($post_id, ($coupon->type == 'Code' ? 'Coupon Code' : 'Promotion'), 'coupon_type', true);

			update_post_meta($post_id, 'coupon_type', $coupon->type == 'Code' ? 'coupon-code' : 'promotion');
			update_post_meta($post_id, 'clpr_coupon_aff_url', $coupon->url);
			update_post_meta($post_id, 'clpr_coupon_code', $coupon->code);
			update_post_meta($post_id, 'clpr_expire_date', $coupon->end_date);
			update_post_meta($post_id, 'clpr_featured', $coupon->featured == 'Yes' ? 1 : '');

			$count_updated = $count_updated + 1;
		} elseif ($coupon->status == 'suspended') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Suspending Coupon ({$coupon->lmd_id})')");

			$post_id = $wpdb->get_var("SELECT post_id FROM {$wp_prefix}postmeta WHERE meta_key = 'lmd_id' AND meta_value = '{$coupon->lmd_id}' LIMIT 0,1");

			wp_delete_post($post_id, true);

			$count_suspended = $count_suspended + 1;
		}

		$wpdb->query("DELETE FROM {$wp_prefix}linkmydeals_upload WHERE lmd_id = {$coupon->lmd_id}");
	}

	$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'info','Processed Offers - $count_new New , $count_updated Updated , $count_suspended Suspended.')");
}


function linkmydeals_couponxl_process_batch($coupons, &$config)
{
	global $wpdb;
	$wp_prefix = $wpdb->prefix;

	$categories = array();
	$categoryTerms = get_terms(array(
		'taxonomy' => 'offer_cat',
		'hide_empty' => false
	));
	foreach ($categoryTerms as $term) {
		$categories[$term->name] = $term->slug;
	}

	$stores = array();
	$storeTerms = $wpdb->get_results("SELECT ID,post_title FROM {$wp_prefix}posts WHERE post_type = 'store' AND post_status = 'publish'");
	foreach ($storeTerms as $str) {
		$stores[$str->ID] = $str->post_title;
	}

	$locations = array();
	$locationTerms = get_terms(array(
		'taxonomy' => 'location',
		'hide_empty' => false
	));
	foreach ($locationTerms as $term) {
		$locations[$term->name] = $term->slug;
	}

	$count_new = $count_suspended = $count_updated = 0;
	$found_count = is_array($coupons) ? count($coupons) : 0;

	$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'info','Found $found_count coupons to process')");

	foreach ($coupons as $coupon) {

		if ($coupon->status == 'new' or $coupon->status == '' or $coupon->status == 'active') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Adding New Coupon ({$coupon->lmd_id})')");

			$post_data = array(
				'ID'             => '',
				'post_title'     => $coupon->title,
				'post_content'   => $coupon->description,
				'post_excerpt'   => $coupon->excerpt,
				'post_status'    => 'publish',
				'post_type'      => 'offer',
				'post_author'    => get_current_user_id()
			);

			$post_id = wp_insert_post($post_data);

			$cat_names = explode(',', $coupon->categories);
			foreach ($cat_names as $cat) {
				wp_set_object_terms($post_id, $cat, 'offer_cat', true);
			}

			if (array_search($coupon->store, array_values($stores)) !== false) {
				update_post_meta($post_id, 'offer_store', array_search($coupon->store, $stores));
			} else {
				$store_data = array(
					'ID'             => '',
					'post_title'     => $coupon->store,
					'post_status'    => 'publish',
					'post_type'      => 'store',
					'post_author'    => get_current_user_id()
				);
				$store_id = wp_insert_post($store_data);
				update_post_meta($store_id, 'store_link', $coupon->homepage_url);

				$stores[$store_id] = $coupon->store;
				update_post_meta($post_id, 'offer_store', $store_id);
			}

			if (!empty($coupon->locations)) {
				$loc_names = explode(',', $coupon->locations);
				foreach ($loc_names as $loc) {
					wp_set_object_terms($post_id, $loc, 'location', true);
				}
			}

			update_post_meta($post_id, 'lmd_id', $coupon->lmd_id);
			update_post_meta($post_id, 'coupon_code', $coupon->code);
			update_post_meta($post_id, 'coupon_link', $coupon->url);
			update_post_meta($post_id, 'coupon_sale', $coupon->url);
			// update_post_meta($post_id, 'coupon_link_with_code', $coupon->url);
			update_post_meta($post_id, 'offer_start', strtotime($coupon->start_date));
			update_post_meta($post_id, 'offer_expire', empty($coupon->end_date) ? '99999999999' : strtotime("{$coupon->end_date} + 1 day"));
			update_post_meta($post_id, 'coupon_type', ($coupon->type == 'Code' ? 'code' : 'sale'));

			set_post_thumbnail($post_id, $config['import_images'] != 'Off' ? linkmydeals_import_image($coupon->image_url, $coupon->lmd_id) : 0);

			update_post_meta($post_id, 'offer_in_slider', 'yes');
			update_post_meta($post_id, 'offer_initial_payment', 'paid');
			update_post_meta($post_id, 'offer_type', 'coupon');
			update_post_meta($post_id, 'deal_status', 'has_items');
			update_post_meta($post_id, 'deal_type', 'shared');

			$count_new = $count_new + 1;
		} elseif ($coupon->status == 'updated') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Updating Coupon ({$coupon->lmd_id})')");

			$post_id = $wpdb->get_var("SELECT post_id FROM {$wp_prefix}postmeta WHERE meta_key = 'lmd_id' AND meta_value = '{$coupon->lmd_id}' LIMIT 0,1");

			$post_data = array(
				'ID'             => $post_id,
				'post_title'     => $coupon->title,
				'post_content'   => $coupon->description,
				'post_excerpt'   => $coupon->excerpt,
				'post_status'    => 'publish',
				'post_author'    => get_current_user_id()
			);

			wp_update_post($post_data);

			$cat_names = explode(',', $coupon->categories);
			$append = false;
			foreach ($cat_names as $cat) {
				wp_set_object_terms($post_id, $cat, 'offer_cat', $append);
				$append = true;
			}

			if (array_search($coupon->store, array_values($stores)) !== false) {
				update_post_meta($post_id, 'offer_store', array_search($coupon->store, $stores));
			} else {
				$store_data = array(
					'ID'             => '',
					'post_title'     => $coupon->store,
					'post_status'    => 'publish',
					'post_type'      => 'store',
					'post_author'    => get_current_user_id()
				);
				$store_id = wp_insert_post($store_data);
				update_post_meta($store_id, 'store_link', $coupon->homepage_url);

				$stores[$store_id] = $coupon->store;
				update_post_meta($post_id, 'offer_store', $store_id);
			}

			if (!empty($coupon->locations)) {
				$loc_names = explode(',', $coupon->locations);
				$append = false;
				foreach ($loc_names as $loc) {
					wp_set_object_terms($post_id, $loc, 'location', $append);
					$append = true;
				}
			}

			update_post_meta($post_id, 'coupon_code', $coupon->code);
			update_post_meta($post_id, 'coupon_link', $coupon->url);
			update_post_meta($post_id, 'coupon_sale', $coupon->url);
			// update_post_meta($post_id, 'coupon_link_with_code', $coupon->url);
			update_post_meta($post_id, 'offer_start', strtotime($coupon->start_date));
			update_post_meta($post_id, 'offer_expire', empty($coupon->end_date) ? '99999999999' : strtotime("{$coupon->end_date} + 1 day"));
			update_post_meta($post_id, 'coupon_type', ($coupon->type == 'Code' ? 'code' : 'sale'));

			$count_updated = $count_updated + 1;
		} elseif ($coupon->status == 'suspended') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Suspending Coupon ({$coupon->lmd_id})')");

			$post_id = $wpdb->get_var("SELECT post_id FROM {$wp_prefix}postmeta WHERE meta_key = 'lmd_id' AND meta_value = '{$coupon->lmd_id}' LIMIT 0,1");

			wp_delete_post($post_id, true);

			$count_suspended = $count_suspended + 1;
		}

		$wpdb->query("DELETE FROM {$wp_prefix}linkmydeals_upload WHERE lmd_id = {$coupon->lmd_id}");
	}

	$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'info','Processed Offers - $count_new New , $count_updated Updated , $count_suspended Suspended.')");
}


function linkmydeals_couponxxl_process_batch($coupons, &$config)
{
	global $wpdb;
	$wp_prefix = $wpdb->prefix;

	$categories = array();
	$categoryTerms = get_terms(array(
		'taxonomy' => 'offer_cat',
		'hide_empty' => false
	));
	foreach ($categoryTerms as $term) {
		$categories[$term->name] = $term->slug;
	}

	$stores = array();
	$storeTerms = $wpdb->get_results("SELECT ID,post_title FROM {$wp_prefix}posts WHERE post_type = 'store' AND post_status = 'publish'");
	foreach ($storeTerms as $str) {
		$stores[$str->ID] = $str->post_title;
	}

	$locations = array();
	$locationTerms = get_terms(array(
		'taxonomy' => 'location',
		'hide_empty' => false
	));
	foreach ($locationTerms as $term) {
		$locations[$term->name] = $term->slug;
	}

	$count_new = $count_suspended = $count_updated = 0;
	$found_count = is_array($coupons) ? count($coupons) : 0;

	$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'info','Found $found_count coupons to process')");

	foreach ($coupons as $coupon) {

		if ($coupon->status == 'new' or $coupon->status == '' or $coupon->status == 'active') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Adding New Coupon ({$coupon->lmd_id})')");

			$post_data = array(
				'ID'             => '',
				'post_title'     => $coupon->title,
				'post_content'   => $coupon->description,
				'post_excerpt'   => $coupon->excerpt,
				'post_status'    => 'publish',
				'post_type'      => 'offer',
				'post_author'    => get_current_user_id()
			);

			$post_id = wp_insert_post($post_data);

			$wpdb->query($wpdb->prepare("INSERT INTO {$wp_prefix}offers (post_id,offer_type,offer_start,offer_expire,offer_in_slider,offer_has_items,offer_thumbs_recommend,offer_clicks) VALUES ($post_id,'coupon',%s,%s,'yes','1','1','1')", strtotime($coupon->start_date), empty($coupon->end_date) ? '99999999999' : strtotime("{$coupon->end_date} + 1 day")));

			$cat_names = explode(',', $coupon->categories);
			foreach ($cat_names as $cat) {
				wp_set_object_terms($post_id, $cat, 'offer_cat', true);
			}

			if (array_search($coupon->store, array_values($stores)) !== false) {
				update_post_meta($post_id, 'offer_store', array_search($coupon->store, $stores));
			} else {
				$store_data = array(
					'ID'             => '',
					'post_title'     => $coupon->store,
					'post_status'    => 'publish',
					'post_type'      => 'store',
					'post_author'    => get_current_user_id()
				);
				$store_id = wp_insert_post($store_data);
				update_post_meta($store_id, 'store_link', $coupon->homepage_url);

				$stores[$store_id] = $coupon->store;
				update_post_meta($post_id, 'offer_store', $store_id);
			}
			if (!empty($coupon->locations)) {
				$loc_names = explode(',', $coupon->locations);
				foreach ($loc_names as $loc) {
					wp_set_object_terms($post_id, $loc, 'location', true);
				}
			}
			update_post_meta($post_id, 'lmd_id', $coupon->lmd_id);
			update_post_meta($post_id, 'coupon_code', $coupon->code);
			update_post_meta($post_id, 'coupon_link', $coupon->url);
			update_post_meta($post_id, 'coupon_sale', $coupon->url);
			update_post_meta($post_id, 'coupon_type', ($coupon->type == 'Code' ? 'code' : 'sale'));

			set_post_thumbnail($post_id, $config['import_images'] != 'Off' ? linkmydeals_import_image($coupon->image_url, $coupon->lmd_id) : 0);

			// 'code' and 'deal' form feed is mapped to 'code' and 'sale' in this theme
			// other postmetas are removed as we are not importing 'deals' that are used in CouponXL

			$count_new = $count_new + 1;
		} elseif ($coupon->status == 'updated') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Updating Coupon ({$coupon->lmd_id})')");

			$post_id = $wpdb->get_var("SELECT post_id FROM {$wp_prefix}postmeta WHERE meta_key = 'lmd_id' AND meta_value = '{$coupon->lmd_id}' LIMIT 0,1");

			$post_data = array(
				'ID'             => $post_id,
				'post_title'     => $coupon->title,
				'post_content'   => $coupon->description,
				'post_excerpt'   => $coupon->excerpt,
				'post_status'    => 'publish',
				'post_author'    => get_current_user_id()
			);

			wp_update_post($post_data);

			$cat_names = explode(',', $coupon->categories);
			$append = false;
			foreach ($cat_names as $cat) {
				wp_set_object_terms($post_id, $cat, 'offer_cat', $append);
				$append = true;
			}
			if (!empty($coupon->locations)) {
				$loc_names = explode(',', $coupon->locations);
				foreach ($loc_names as $loc) {
					wp_set_object_terms($post_id, $loc, 'location', true);
				}
			}
			if (array_search($coupon->store, array_values($stores)) !== false) {
				update_post_meta($post_id, 'offer_store', array_search($coupon->store, $stores));
			} else {
				$store_data = array(
					'ID'             => '',
					'post_title'     => $coupon->store,
					'post_status'    => 'publish',
					'post_type'      => 'store',
					'post_author'    => get_current_user_id()
				);
				$store_id = wp_insert_post($store_data);
				update_post_meta($store_id, 'store_link', $coupon->homepage_url);

				$stores[$store_id] = $coupon->store;
				update_post_meta($post_id, 'offer_store', $store_id);
			}

			$wpdb->query($wpdb->prepare("UPDATE {$wp_prefix}offers SET offer_start=%s, offer_expire=%s WHERE post_id = $post_id", strtotime($coupon->start_date), empty($coupon->end_date) ? '99999999999' : strtotime("{$coupon->end_date} + 1 day")));

			update_post_meta($post_id, 'coupon_code', $coupon->code);
			update_post_meta($post_id, 'coupon_link', $coupon->url);
			update_post_meta($post_id, 'coupon_sale', $coupon->url);
			update_post_meta($post_id, 'coupon_type', ($coupon->type == 'Code' ? 'code' : 'sale'));

			$count_updated = $count_updated + 1;
		} elseif ($coupon->status == 'suspended') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Suspending Coupon ({$coupon->lmd_id})')");

			$post_id = $wpdb->get_var("SELECT post_id FROM {$wp_prefix}postmeta WHERE meta_key = 'lmd_id' AND meta_value = '{$coupon->lmd_id}' LIMIT 0,1");

			wp_delete_post($post_id, true);

			$count_suspended = $count_suspended + 1;
		}

		$wpdb->query("DELETE FROM {$wp_prefix}linkmydeals_upload WHERE lmd_id = {$coupon->lmd_id}");
	}

	$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'info','Processed Offers - $count_new New , $count_updated Updated , $count_suspended Suspended.')");
}


function linkmydeals_couponer_process_batch($coupons)
{
	global $wpdb;
	$wp_prefix = $wpdb->prefix;

	$categories = array();
	$categoryTerms = get_terms(array(
		'taxonomy' => 'code_category',
		'hide_empty' => false
	));
	foreach ($categoryTerms as $term) {
		$categories[$term->name] = $term->slug;
	}

	$stores = array();
	$storeTerms = $wpdb->get_results("SELECT ID,post_title FROM {$wp_prefix}posts WHERE post_type = 'shop' AND post_status = 'publish'");
	foreach ($storeTerms as $str) {
		$stores[$str->ID] = $str->post_title;
	}

	$count_new = $count_suspended = $count_updated = 0;
	$found_count = is_array($coupons) ? count($coupons) : 0;

	$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'info','Found $found_count coupons to process')");

	foreach ($coupons as $coupon) {

		if ($coupon->status == 'new' or $coupon->status == '' or $coupon->status == 'active') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Adding New Coupon ({$coupon->lmd_id})')");

			$post_data = array(
				'ID'             => '',
				'post_title'     => $coupon->title,
				'post_content'   => $coupon->description,
				'post_excerpt'   => $coupon->excerpt,
				'post_status'    => 'publish',
				'post_type'      => 'code',
				'post_author'    => get_current_user_id()
			);

			$post_id = wp_insert_post($post_data);

			$cat_names = explode(',', $coupon->categories);
			foreach ($cat_names as $cat) {
				wp_set_object_terms($post_id, $cat, 'code_category', true);
			}

			if (array_search($coupon->store, array_values($stores)) !== false) {
				update_post_meta($post_id, 'code_shop_id', array_search($coupon->store, $stores));
			} else {
				$store_data = array(
					'ID'             => '',
					'post_title'     => $coupon->store,
					'post_status'    => 'publish',
					'post_type'      => 'shop',
					'post_author'    => get_current_user_id()
				);
				$store_id = wp_insert_post($store_data);
				update_post_meta($store_id, 'shop_link', $coupon->homepage_url);

				$stores[$store_id] = $coupon->store;
				update_post_meta($post_id, 'code_shop_id', $store_id);
			}

			update_post_meta($post_id, 'lmd_id', $coupon->lmd_id);
			update_post_meta($post_id, 'pending_shop_url', $coupon->url);
			update_post_meta($post_id, 'code_couponcode', $coupon->code);
			update_post_meta($post_id, 'code_type', ($coupon->featured == 'Yes' ? 1 : 0));
			update_post_meta($post_id, 'code_conditions', $coupon->terms_and_conditions);
			update_post_meta($post_id, 'code_expire', empty($coupon->end_date) ? '99999999999' : strtotime("{$coupon->end_date} + 1 day"));
			update_post_meta($post_id, 'code_api', $coupon->url);
			update_post_meta($post_id, 'coupon_label', $coupon->type == 'Code' ? 'couponer' : 'discount');
			update_post_meta($post_id, 'code_discount', $coupon->badge);
			update_post_meta($post_id, 'code_text', $coupon->title);
			update_post_meta($post_id, 'code_for', 'all_users');
			update_post_meta($post_id, 'code_clicks', 0);

			$count_new = $count_new + 1;
		} elseif ($coupon->status == 'updated') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Updating Coupon ({$coupon->lmd_id})')");

			$post_id = $wpdb->get_var("SELECT post_id FROM {$wp_prefix}postmeta WHERE meta_key = 'lmd_id' AND meta_value = '{$coupon->lmd_id}' LIMIT 0,1");

			$post_data = array(
				'ID'             => $post_id,
				'post_title'     => $coupon->title,
				'post_content'   => $coupon->description,
				'post_excerpt'   => $coupon->excerpt,
				'post_status'    => 'publish',
				'post_author'    => get_current_user_id()
			);

			wp_update_post($post_data);

			$cat_names = explode(',', $coupon->categories);
			$append = false;
			foreach ($cat_names as $cat) {
				wp_set_object_terms($post_id, $cat, 'code_category', $append);
				$append = true;
			}

			if (array_search($coupon->store, array_values($stores)) !== false) {
				update_post_meta($post_id, 'code_shop_id', array_search($coupon->store, $stores));
			} else {
				$store_data = array(
					'ID'             => '',
					'post_title'     => $coupon->store,
					'post_status'    => 'publish',
					'post_type'      => 'shop',
					'post_author'    => get_current_user_id()
				);
				$store_id = wp_insert_post($store_data);
				update_post_meta($store_id, 'shop_link', $coupon->homepage_url);

				$stores[$store_id] = $coupon->store;
				update_post_meta($post_id, 'code_shop_id', $store_id);
			}

			update_post_meta($post_id, 'lmd_id', $coupon->lmd_id);
			update_post_meta($post_id, 'pending_shop_url', $coupon->url);
			update_post_meta($post_id, 'code_couponcode', $coupon->code);
			update_post_meta($post_id, 'code_type', ($coupon->featured == 'Yes' ? 1 : 0));
			update_post_meta($post_id, 'code_conditions', $coupon->terms_and_conditions);
			update_post_meta($post_id, 'code_expire', empty($coupon->end_date) ? '99999999999' : strtotime("{$coupon->end_date} + 1 day"));
			update_post_meta($post_id, 'code_api', $coupon->url);
			update_post_meta($post_id, 'coupon_label', $coupon->type == 'Code' ? 'couponer' : 'discount');
			update_post_meta($post_id, 'code_discount', $coupon->badge);
			// update_post_meta($post_id, 'code_text', $coupon->title);

			$count_updated = $count_updated + 1;
		} elseif ($coupon->status == 'suspended') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Suspending Coupon ({$coupon->lmd_id})')");

			$post_id = $wpdb->get_var("SELECT post_id FROM {$wp_prefix}postmeta WHERE meta_key = 'lmd_id' AND meta_value = '{$coupon->lmd_id}' LIMIT 0,1");

			wp_delete_post($post_id, true);

			$count_suspended = $count_suspended + 1;
		}

		$wpdb->query("DELETE FROM {$wp_prefix}linkmydeals_upload WHERE lmd_id = {$coupon->lmd_id}");
	}

	$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'info','Processed Offers - $count_new New , $count_updated Updated , $count_suspended Suspended.')");
}


function linkmydeals_couponpress_process_batch($coupons, &$config)
{
	global $wpdb;
	$wp_prefix = $wpdb->prefix;

	$categories = array();
	$categoryTerms = get_terms(array(
		'taxonomy' => 'listing',
		'hide_empty' => false
	));
	foreach ($categoryTerms as $term) {
		$categories[$term->name] = $term->slug;
	}

	$stores = array();
	$storeTerms = get_terms(array(
		'taxonomy' => 'store',
		'hide_empty' => false
	));
	foreach ($storeTerms as $term) {
		$stores[$term->name] = $term->slug;
	}

	$count_new = $count_suspended = $count_updated = 0;
	$found_count = is_array($coupons) ? count($coupons) : 0;

	$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'info','Found $found_count coupons to process')");

	foreach ($coupons as $coupon) {

		if ($coupon->status == 'new' or $coupon->status == '' or $coupon->status == 'active') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Adding New Coupon ({$coupon->lmd_id})')");

			$post_data = array(
				'ID'             => '',
				'post_title'     => $coupon->title,
				'post_content'   => $coupon->description,
				'post_excerpt'   => $coupon->excerpt,
				'post_status'    => 'publish',
				'post_type'      => 'listing_type',
				'post_author'    => get_current_user_id()
			);

			$post_id = wp_insert_post($post_data);

			$cat_names = explode(',', $coupon->categories);
			foreach ($cat_names as $cat) {
				wp_set_object_terms($post_id, $cat, 'listing', true);
			}

			$store_names = explode(',', $coupon->store);
			foreach ($store_names as $str) {
				// Create New Store
				$term = wp_insert_term($str, 'store'); 										// , $args third parameter
				if (!is_wp_error($term)) { 													// Term does not exist. Got inserted now.
					$stores[$str] = get_term($term['term_id'], "store")->slug;
					$cav = get_option('core_admin_values', array());
					$cav["category_website_{$term['term_id']}"] = $coupon->homepage_url;
					update_option("core_admin_values", $cav);								// Update Meta Info
				}
				wp_set_object_terms($post_id, $str, 'store', true);
			}

			update_post_meta($post_id, 'lmd_id', $coupon->lmd_id);
			update_post_meta($post_id, 'type', ($coupon->type == 'Code' ? '1' : '2'));
			update_post_meta($post_id, 'code', $coupon->code);
			update_post_meta($post_id, 'link', $coupon->url);
			update_post_meta($post_id, 'expiry_date', $coupon->end_date);
			update_post_meta($post_id, 'featured', strtolower($coupon->featured));
			update_post_meta($post_id, 'cashback', '');
			update_post_meta($post_id, 'Youtube_link', '');
			update_post_meta($post_id, 'packageID', 0);
			update_post_meta($post_id, 'pageaccess', '');

			// has their own upload function
			if ($config['import_images'] != 'Off') {
				$thumb_id = linkmydeals_import_image($coupon->image_url, $coupon->lmd_id);
				$data = array(
					'name' 		=> "",
					'type'		=> wp_check_filetype(get_attached_file($thumb_id), null)['type'],
					'postID'	=> $post_id,
					'src' 		=> $coupon->image_url,
					'thumbnail' => "",
					'filepath' 	=> addslashes($coupon->image_url),
					'id'		=> $thumb_id,
					'default' 	=> 0,
					'order'		=> 1,
					'dpi' 		=> '300',
					'size' 		=> filesize(get_attached_file($thumb_id)),
				);
				update_post_meta($post_id, 'image_array', array($data));
			}

			$count_new = $count_new + 1;
		} elseif ($coupon->status == 'updated') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Updating Coupon ({$coupon->lmd_id})')");

			$post_id = $wpdb->get_var("SELECT post_id FROM {$wp_prefix}postmeta WHERE meta_key = 'lmd_id' AND meta_value = '{$coupon->lmd_id}' LIMIT 0,1");

			$post_data = array(
				'ID'             => $post_id,
				'post_title'     => $coupon->title,
				'post_content'   => $coupon->description,
				'post_excerpt'   => $coupon->excerpt,
				'post_status'    => 'publish',
				'post_author'    => get_current_user_id()
			);

			wp_update_post($post_data);

			$cat_names = explode(',', $coupon->categories);
			$append = false;
			foreach ($cat_names as $cat) {
				wp_set_object_terms($post_id, $cat, 'listing', $append);
				$append = true;
			}

			$store_names = explode(',', $coupon->store);
			$append = false;
			foreach ($store_names as $str) {
				wp_set_object_terms($post_id, $str, 'store', $append);
				$append = true;
			}

			update_post_meta($post_id, 'type', ($coupon->type == 'Code' ? '1' : '2'));
			update_post_meta($post_id, 'code', $coupon->code);
			update_post_meta($post_id, 'link', $coupon->url);
			update_post_meta($post_id, 'expiry_date', $coupon->end_date);
			update_post_meta($post_id, 'featured', strtolower($coupon->featured));

			$count_updated = $count_updated + 1;
		} elseif ($coupon->status == 'suspended') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Suspending Coupon ({$coupon->lmd_id})')");

			$post_id = $wpdb->get_var("SELECT post_id FROM {$wp_prefix}postmeta WHERE meta_key = 'lmd_id' AND meta_value = '{$coupon->lmd_id}' LIMIT 0,1");

			wp_delete_post($post_id, true);

			$count_suspended = $count_suspended + 1;
		}

		$wpdb->query("DELETE FROM {$wp_prefix}linkmydeals_upload WHERE lmd_id = {$coupon->lmd_id}");
	}

	$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'info','Processed Offers - $count_new New , $count_updated Updated , $count_suspended Suspended.')");
}


function linkmydeals_rehub_process_batch($coupons, &$config)
{
	global $wpdb;
	$wp_prefix = $wpdb->prefix;

	$categories = array();
	$categoryTerms = get_terms(array(
		'taxonomy' => 'category',
		'hide_empty' => false
	));
	foreach ($categoryTerms as $term) {
		$categories[$term->name] = $term->slug;
	}

	$stores = array();
	$storeTerms = get_terms(array(
		'taxonomy' => 'dealstore',
		'hide_empty' => false
	));
	foreach ($storeTerms as $term) {
		$stores[$term->name] = $term->slug;
	}

	$count_new = $count_suspended = $count_updated = 0;
	$found_count = is_array($coupons) ? count($coupons) : 0;

	$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'info','Found $found_count coupons to process')");

	foreach ($coupons as $coupon) {

		if ($coupon->status == 'new' or $coupon->status == '' or $coupon->status == 'active') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Adding New Coupon ({$coupon->lmd_id})')");

			$post_data = array(
				'ID'             => '',
				'post_title'     => $coupon->title,
				'post_content'   => $coupon->description,
				'post_excerpt'   => $coupon->excerpt,
				'post_status'    => 'publish',
				'post_type'      => 'post',
				'post_author'    => get_current_user_id()
			);

			$post_id = wp_insert_post($post_data);

			$cat_names = explode(',', $coupon->categories);
			foreach ($cat_names as $cat) {
				wp_set_object_terms($post_id, $cat, 'category', true);
			}

			$store_names = explode(',', $coupon->store);
			foreach ($store_names as $str) {
				// Create New Store
				$term = wp_insert_term($str, 'dealstore'); 							// , $args third parameter
				if (!is_wp_error($term)) { 											// Term does not exist. Got inserted now.
					$stores[$str] = get_term($term['term_id'], "dealstore")->slug;
					$meta_args = array("brand_url"	=> $coupon->homepage_url); 		//store taxonomy args in wp_options
					update_option("taxonomy_term_{$term['term_id']}", $meta_args);	// Update Meta Info
				}
				wp_set_object_terms($post_id, $str, 'dealstore', true);
			}

			update_post_meta($post_id, 'lmd_id', $coupon->lmd_id);
			update_post_meta($post_id, 'rehub_offer_name', $coupon->title);
			update_post_meta($post_id, 'rehub_offer_product_desc', $coupon->description);
			if (!empty($coupon->end_date)) {
				update_post_meta($post_id, 'rehub_offer_coupon_date', $coupon->end_date);
			}
			update_post_meta($post_id, 'rehub_offer_product_url', $coupon->url);
			update_post_meta($post_id, 'rehub_offer_coupon_mask', '1');
			update_post_meta($post_id, 'rehub_offer_product_coupon', $coupon->code);

			set_post_thumbnail($post_id, $config['import_images'] != 'Off' ? linkmydeals_import_image($coupon->image_url, $coupon->lmd_id) : 0);

			$count_new = $count_new + 1;
		} elseif ($coupon->status == 'updated') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Updating Coupon ({$coupon->lmd_id})')");

			$post_id = $wpdb->get_var("SELECT post_id FROM {$wp_prefix}postmeta WHERE meta_key = 'lmd_id' AND meta_value = '{$coupon->lmd_id}' LIMIT 0,1");

			$post_data = array(
				'ID'             => $post_id,
				'post_title'     => $coupon->title,
				'post_content'   => $coupon->description,
				'post_excerpt'   => $coupon->excerpt,
				'post_status'    => 'publish',
				'post_author'    => get_current_user_id()
			);

			wp_update_post($post_data);

			$cat_names = explode(',', $coupon->categories);
			$append = false;
			foreach ($cat_names as $cat) {
				wp_set_object_terms($post_id, $cat, 'category', $append);
				$append = true;
			}

			$store_names = explode(',', $coupon->store);
			$append = false;
			foreach ($store_names as $str) {
				wp_set_object_terms($post_id, $str, 'dealstore', $append);
				$append = true;
			}

			update_post_meta($post_id, 'rehub_offer_name', $coupon->title);
			update_post_meta($post_id, 'rehub_offer_product_desc', $coupon->description);
			if (!empty($coupon->end_date)) {
				update_post_meta($post_id, 'rehub_offer_coupon_date', $coupon->end_date);
			}
			update_post_meta($post_id, 'rehub_offer_product_url', $coupon->url);
			update_post_meta($post_id, 'rehub_offer_coupon_mask', '1');
			update_post_meta($post_id, 'rehub_offer_product_coupon', $coupon->code);

			$count_updated = $count_updated + 1;
		} elseif ($coupon->status == 'suspended') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Suspending Coupon ({$coupon->lmd_id})')");

			$post_id = $wpdb->get_var("SELECT post_id FROM {$wp_prefix}postmeta WHERE meta_key = 'lmd_id' AND meta_value = '{$coupon->lmd_id}' LIMIT 0,1");

			wp_delete_post($post_id, true);

			$count_suspended = $count_suspended + 1;
		}

		$wpdb->query("DELETE FROM {$wp_prefix}linkmydeals_upload WHERE lmd_id = {$coupon->lmd_id}");
	}

	$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'info','Processed Offers - $count_new New , $count_updated Updated , $count_suspended Suspended.')");
}


function linkmydeals_wpcoupon_process_batch($coupons, &$config)
{
	global $wpdb;
	$wp_prefix = $wpdb->prefix;

	$categories = array();
	$categoryTerms = get_terms(array(
		'taxonomy' => 'coupon_category',
		'hide_empty' => false
	));
	foreach ($categoryTerms as $term) {
		$categories[$term->name] = $term->slug;
	}

	$stores = array();
	$storeTerms = get_terms(array(
		'taxonomy' => 'coupon_store',
		'hide_empty' => false
	));
	foreach ($storeTerms as $term) {
		$stores[$term->name] = $term->slug;
	}

	$count_new = $count_suspended = $count_updated = 0;
	$found_count = is_array($coupons) ? count($coupons) : 0;

	$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'info','Found $found_count coupons to process')");

	foreach ($coupons as $coupon) {

		if ($coupon->status == 'new' or $coupon->status == '' or $coupon->status == 'active') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Adding New Coupon ({$coupon->lmd_id})')");

			$post_data = array(
				'ID'             => '',
				'post_title'     => $coupon->title,
				'post_content'   => $coupon->description,
				'post_status'    => 'publish',
				'post_type'      => 'coupon',
				'post_author'    => get_current_user_id()
			);

			$post_id = wp_insert_post($post_data);

			$cat_names = explode(',', $coupon->categories);
			foreach ($cat_names as $cat) {
				wp_set_object_terms($post_id, $cat, 'coupon_category', true);
			}

			$store_names = explode(',', $coupon->store);
			foreach ($store_names as $str) {
				wp_set_object_terms($post_id, $str, 'coupon_store', true);
			}

			update_post_meta($post_id, 'lmd_id', $coupon->lmd_id);
			update_post_meta($post_id, '_wpc_percent_success', '100');
			update_post_meta($post_id, '_wpc_used', '0');
			update_post_meta($post_id, '_wpc_today', '');
			update_post_meta($post_id, '_wpc_vote_up', '0');
			update_post_meta($post_id, '_wpc_vote_down', '0');
			if (!empty($coupon->end_date)) {
				update_post_meta($post_id, '_wpc_expires', strtotime($coupon->end_date));
			}
			update_post_meta($post_id, '_wpc_store', '');
			update_post_meta($post_id, '_wpc_coupon_type', ($coupon->type == 'Code' ? 'code' : 'sale'));
			update_post_meta($post_id, '_wpc_coupon_type_code', $coupon->code);
			update_post_meta($post_id, '_wpc_destination_url', $coupon->url);
			update_post_meta($post_id, '_wpc_exclusive', '');
			update_post_meta($post_id, '_wpc_views', '0');

			set_post_thumbnail($post_id, $config['import_images'] != 'Off' ? linkmydeals_import_image($coupon->image_url, $coupon->lmd_id) : 0);

			$count_new = $count_new + 1;
		} elseif ($coupon->status == 'updated') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Updating Coupon ({$coupon->lmd_id})')");

			$post_id = $wpdb->get_var("SELECT post_id FROM {$wp_prefix}postmeta WHERE meta_key = 'lmd_id' AND meta_value = '{$coupon->lmd_id}' LIMIT 0,1");

			$post_data = array(
				'ID'             => $post_id,
				'post_title'     => $coupon->title,
				'post_content'   => $coupon->description,
				'post_status'    => 'publish',
				'post_author'    => get_current_user_id()
			);

			wp_update_post($post_data);

			$cat_names = explode(',', $coupon->categories);
			$append = false;
			foreach ($cat_names as $cat) {
				wp_set_object_terms($post_id, $cat, 'coupon_category', $append);
				$append = true;
			}

			$store_names = explode(',', $coupon->store);
			$append = false;
			foreach ($store_names as $str) {
				wp_set_object_terms($post_id, $str, 'coupon_store', $append);
				$append = true;
			}

			if (!empty($coupon->end_date)) {
				update_post_meta($post_id, '_wpc_expires', strtotime($coupon->end_date));
			}
			update_post_meta($post_id, '_wpc_coupon_type', ($coupon->type == 'Code' ? 'code' : 'sale'));
			update_post_meta($post_id, '_wpc_coupon_type_code', $coupon->code);
			update_post_meta($post_id, '_wpc_destination_url', $coupon->url);

			$count_updated = $count_updated + 1;
		} elseif ($coupon->status == 'suspended') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Suspending Coupon ({$coupon->lmd_id})')");

			$post_id = $wpdb->get_var("SELECT post_id FROM {$wp_prefix}postmeta WHERE meta_key = 'lmd_id' AND meta_value = '{$coupon->lmd_id}' LIMIT 0,1");

			wp_delete_post($post_id, true);

			$count_suspended = $count_suspended + 1;
		}

		$wpdb->query("DELETE FROM {$wp_prefix}linkmydeals_upload WHERE lmd_id = {$coupon->lmd_id}");
	}

	$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'info','Processed Offers - $count_new New , $count_updated Updated , $count_suspended Suspended.')");
}


function linkmydeals_mtscoupon_process_batch($coupons, &$config)
{
	global $wpdb;
	$wp_prefix = $wpdb->prefix;

	$categories = array();
	$categoryTerms = get_terms(array(
		'taxonomy' => 'mts_coupon_categories',
		'hide_empty' => false
	));
	foreach ($categoryTerms as $term) {
		$categories[$term->name] = $term->slug;
	}

	$stores = array();
	$storeTerms = get_terms(array(
		'taxonomy' => 'mts_coupon_tag',
		'hide_empty' => false
	));
	foreach ($storeTerms as $term) {
		$stores[$term->name] = $term->slug;
	}

	$count_new = $count_suspended = $count_updated = 0;
	$found_count = is_array($coupons) ? count($coupons) : 0;

	$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'info','Found $found_count coupons to process')");

	foreach ($coupons as $coupon) {

		if ($coupon->status == 'new' or $coupon->status == '' or $coupon->status == 'active') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Adding New Coupon ({$coupon->lmd_id})')");

			$post_data = array(
				'ID'             => '',
				'post_title'     => $coupon->title,
				'post_content'   => $coupon->description,
				'post_status'    => 'publish',
				'post_type'      => 'coupons',
				'post_author'    => get_current_user_id()
			);

			$post_id = wp_insert_post($post_data);

			$cat_names = explode(',', $coupon->categories);
			foreach ($cat_names as $cat) {
				wp_set_object_terms($post_id, $cat, 'mts_coupon_categories', true);
			}

			$store_names = explode(',', $coupon->store);
			foreach ($store_names as $str) {
				wp_set_object_terms($post_id, $str, 'mts_coupon_tag', true);
			}

			update_post_meta($post_id, 'lmd_id', $coupon->lmd_id);
			update_post_meta($post_id, 'mts_coupon_expire', $coupon->end_date);
			update_post_meta($post_id, 'mts_coupon_featured_text', $coupon->badge);
			update_post_meta($post_id, 'mts_coupon_button_type', $coupon->type == 'Code' ? 'coupon' : 'deal');
			update_post_meta($post_id, 'mts_coupon_deal_URL', $coupon->url);
			update_post_meta($post_id, 'mts_coupon_code', $coupon->code);

			set_post_thumbnail($post_id, $config['import_images'] != 'Off' ? linkmydeals_import_image($coupon->image_url, $coupon->lmd_id) : 0);

			update_post_meta($post_id, '_mts_custom_sidebar', '');
			update_post_meta($post_id, '_mts_sidebar_location', '');
			update_post_meta($post_id, 'mts_coupon_extra_rewards', '');
			update_post_meta($post_id, 'mts_coupon_people_used', '1');
			update_post_meta($post_id, 'mts_coupon_expire_time', '11:59 PM');

			$count_new = $count_new + 1;
		} elseif ($coupon->status == 'updated') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Updating Coupon ({$coupon->lmd_id})')");

			$post_id = $wpdb->get_var("SELECT post_id FROM {$wp_prefix}postmeta WHERE meta_key = 'lmd_id' AND meta_value = '{$coupon->lmd_id}' LIMIT 0,1");

			$post_data = array(
				'ID'             => $post_id,
				'post_title'     => $coupon->title,
				'post_content'   => $coupon->description,
				'post_status'    => 'publish',
				'post_author'    => get_current_user_id()
			);

			wp_update_post($post_data);

			$cat_names = explode(',', $coupon->categories);
			$append = false;
			foreach ($cat_names as $cat) {
				wp_set_object_terms($post_id, $cat, 'mts_coupon_categories', $append);
				$append = true;
			}

			$store_names = explode(',', $coupon->store);
			$append = false;
			foreach ($store_names as $str) {
				wp_set_object_terms($post_id, $str, 'mts_coupon_tag', $append);
				$append = true;
			}

			update_post_meta($post_id, 'mts_coupon_expire', $coupon->end_date);
			update_post_meta($post_id, 'mts_coupon_featured_text', $coupon->badge);
			update_post_meta($post_id, 'mts_coupon_button_type', $coupon->type == 'Code' ? 'coupon' : 'deal');
			update_post_meta($post_id, 'mts_coupon_deal_URL', $coupon->url);
			update_post_meta($post_id, 'mts_coupon_code', $coupon->code);

			$count_updated = $count_updated + 1;
		} elseif ($coupon->status == 'suspended') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Suspending Coupon ({$coupon->lmd_id})')");

			$post_id = $wpdb->get_var("SELECT post_id FROM {$wp_prefix}postmeta WHERE meta_key = 'lmd_id' AND meta_value = '{$coupon->lmd_id}' LIMIT 0,1");

			wp_delete_post($post_id, true);

			$count_suspended = $count_suspended + 1;
		}

		$wpdb->query("DELETE FROM {$wp_prefix}linkmydeals_upload WHERE lmd_id = {$coupon->lmd_id}");
	}

	$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'info','Processed Offers - $count_new New , $count_updated Updated , $count_suspended Suspended.')");
}


function linkmydeals_couponis_process_batch($coupons, &$config)
{
	global $wpdb;
	$wp_prefix = $wpdb->prefix;

	$categories = array();
	$categoryTerms = get_terms(array(
		'taxonomy' => 'coupon-category',
		'hide_empty' => false
	));
	foreach ($categoryTerms as $term) {
		$categories[$term->name] = $term->slug;
	}

	$stores = array();
	$storeTerms = get_terms(array(
		'taxonomy' => 'coupon-store',
		'hide_empty' => false
	));
	foreach ($storeTerms as $term) {
		$stores[$term->name] = $term->slug;
	}

	$count_new = $count_suspended = $count_updated = 0;
	$found_count = is_array($coupons) ? count($coupons) : 0;

	$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'info','Found $found_count coupons to process')");

	foreach ($coupons as $coupon) {

		if ($coupon->status == 'new' or $coupon->status == '' or $coupon->status == 'active') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Adding New Coupon ({$coupon->lmd_id})')");

			$post_data = array(
				'ID'             => '',
				'post_title'     => $coupon->title,
				'post_content'   => $coupon->description,
				'post_status'    => 'publish',
				'post_type'      => 'coupon',
				'post_author'    => get_current_user_id()
			);

			$post_id = wp_insert_post($post_data);

			$wpdb->query($wpdb->prepare("INSERT INTO {$wp_prefix}couponis_coupon_data (post_id,expire,ctype,exclusive,used,positive,negative,success) VALUES ($post_id,'%s','%s',%s,'0','0','0','0')", empty($coupon->end_date) ? '99999999999' : strtotime("{$coupon->end_date} + 1 day"), $coupon->type == 'Code' ? '1' : '3', $coupon->featured == 'Yes' ? '1' : '0'));

			$cat_names = explode(',', $coupon->categories);
			foreach ($cat_names as $cat) {
				wp_set_object_terms($post_id, $cat, 'coupon-category', true);
			}

			$store_names = explode(',', $coupon->store);
			foreach ($store_names as $str) {
				// Create New Store
				$term = wp_insert_term($str, 'coupon-store'); 								// , $args third parameter
				if (!is_wp_error($term)) { 											   		// Term did not exist. Got inserted now.
					$stores[$str] = get_term($term['term_id'], "coupon-store")->slug;
					update_term_meta($term['term_id'], 'store_url', $coupon->homepage_url);	//store taxonomy args in wp_options
				}
				wp_set_object_terms($post_id, $str, 'coupon-store', true);
			}

			update_post_meta($post_id, 'lmd_id', $coupon->lmd_id);
			update_post_meta($post_id, 'coupon_code', $coupon->code);
			update_post_meta($post_id, 'coupon_affiliate', $coupon->url);
			update_post_meta($post_id, 'coupon_url', $coupon->url);

			set_post_thumbnail($post_id, $config['import_images'] != 'Off' ? linkmydeals_import_image($coupon->image_url, $coupon->lmd_id) : 0);

			$count_new = $count_new + 1;
		} elseif ($coupon->status == 'updated') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Updating Coupon ({$coupon->lmd_id})')");

			$post_id = $wpdb->get_var("SELECT post_id FROM {$wp_prefix}postmeta WHERE meta_key = 'lmd_id' AND meta_value = '{$coupon->lmd_id}' LIMIT 0,1");

			$post_data = array(
				'ID'             => $post_id,
				'post_title'     => $coupon->title,
				'post_content'   => $coupon->description,
				'post_status'    => 'publish',
				'post_author'    => get_current_user_id()
			);

			wp_update_post($post_data);

			$cat_names = explode(',', $coupon->categories);
			$append = false;
			foreach ($cat_names as $cat) {
				wp_set_object_terms($post_id, $cat, 'coupon-category', $append);
				$append = true;
			}

			$store_names = explode(',', $coupon->store);
			$append = false;
			foreach ($store_names as $cat) {
				wp_set_object_terms($post_id, $cat, 'coupon-store', $append);
				$append = true;
			}

			$wpdb->query($wpdb->prepare("UPDATE {$wp_prefix}couponis_coupon_data SET expire=%s, ctype=%s, exclusive=%s WHERE post_id = $post_id", empty($coupon->end_date) ? '99999999999' : strtotime("{$coupon->end_date} + 1 day"), $coupon->type == 'Code' ? '1' : '3', $coupon->featured == 'Yes' ? '1' : '0'));

			update_post_meta($post_id, 'coupon_code', $coupon->code);
			update_post_meta($post_id, 'coupon_affiliate', $coupon->url);
			update_post_meta($post_id, 'coupon_url', $coupon->url);

			$count_updated = $count_updated + 1;
		} elseif ($coupon->status == 'suspended') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Suspending Coupon ({$coupon->lmd_id})')");

			$post_id = $wpdb->get_var("SELECT post_id FROM {$wp_prefix}postmeta WHERE meta_key = 'lmd_id' AND meta_value = '{$coupon->lmd_id}' LIMIT 0,1");

			wp_delete_post($post_id, true);

			$count_suspended = $count_suspended + 1;
		}

		$wpdb->query("DELETE FROM {$wp_prefix}linkmydeals_upload WHERE lmd_id = {$coupon->lmd_id}");
	}

	$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'info','Processed Offers - $count_new New , $count_updated Updated , $count_suspended Suspended.')");
}


function linkmydeals_couponhut_process_batch($coupons, &$config)
{
	global $wpdb;
	$wp_prefix = $wpdb->prefix;

	$categories = array();
	$categoryTerms = get_terms(array(
		'taxonomy' => 'deal_category',
		'hide_empty' => false
	));
	foreach ($categoryTerms as $term) {
		$categories[$term->name] = $term->slug;
	}

	$stores = array();
	$storeTerms = get_terms(array(
		'taxonomy' => 'deal_company',
		'hide_empty' => false
	));
	foreach ($storeTerms as $term) {
		$stores[$term->name] = $term->slug;
	}

	$count_new = $count_suspended = $count_updated = 0;
	$found_count = is_array($coupons) ? count($coupons) : 0;

	$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'info','Found $found_count coupons to process')");

	foreach ($coupons as $coupon) {

		if ($coupon->status == 'new' or $coupon->status == '' or $coupon->status == 'active') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Adding New Coupon ({$coupon->lmd_id})')");

			$post_data = array(
				'ID'             => '',
				'post_title'     => $coupon->title,
				'post_content'   => $coupon->description,
				'post_status'    => 'publish',
				'post_type'      => 'deal',
				'post_author'    => get_current_user_id()
			);

			$post_id = wp_insert_post($post_data);

			$cat_names = explode(',', $coupon->categories);
			foreach ($cat_names as $cat) {
				wp_set_object_terms($post_id, $cat, 'deal_category', true);
			}

			$store_names = explode(',', $coupon->store);
			foreach ($store_names as $str) {
				// Create New Store
				$term = wp_insert_term($str, 'deal_company'); // , $args third parameter
				if (!is_wp_error($term)) { // Term did not exist. Got inserted now.
					$stores[$str] = get_term($term['term_id'], "deal_company")->slug;
					update_term_meta($term['term_id'], 'company_website', $coupon->merchant_home_page);
					update_term_meta($term['term_id'], '_company_website', 'field_55225d80cd66e');
				}
				wp_set_object_terms($post_id, $str, 'deal_company', true);
			}

			update_post_meta($post_id, 'lmd_id', $coupon->lmd_id);

			update_post_meta($post_id, 'deal_type', $coupon->type ? 'Code' : 'discount');
			update_post_meta($post_id, '_deal_type', 'field_5519756e0f4e2');
			update_post_meta($post_id, 'coupon_code', $coupon->code);
			update_post_meta($post_id, '_coupon_code', 'field_551976780f4e4');
			update_post_meta($post_id, 'url', $coupon->url);
			update_post_meta($post_id, '_url', 'field_55016e3011ba3');
			update_post_meta($post_id, 'deal_summary', $coupon->badge);
			update_post_meta($post_id, '_deal_summary', 'field_554f8e55b6dd8');
			update_post_meta($post_id, 'discount_value', $coupon->badge);
			update_post_meta($post_id, '_discount_value', 'field_55016e0911ba1');
			update_post_meta($post_id, 'coupon_code_description', $coupon->title);
			update_post_meta($post_id, '_coupon_code_description', 'field_5b3dcdadd300c');
			update_post_meta($post_id, 'expiring_date', empty($coupon->end_date) ? '' : date("Ymd", strtotime($coupon->end_date)));
			update_post_meta($post_id, '_expiring_date', 'field_55016e3d11ba4');

			update_post_meta($post_id, 'deal_layout', 'small');
			update_post_meta($post_id, '_deal_layout', 'field_59db655dfb0cb');
			update_post_meta($post_id, 'virtual_deal', '1');
			update_post_meta($post_id, '_virtual_deal', 'field_56f12e67f1d15');
			update_post_meta($post_id, 'printable_coupon', '0');
			update_post_meta($post_id, '_printable_coupon', 'field_5683c0ebd307f');
			update_post_meta($post_id, 'registered_members_only', '0');
			update_post_meta($post_id, '_registered_members_only', 'field_5673f4f869107');
			update_post_meta($post_id, 'show_location', 'hide');
			update_post_meta($post_id, '_show_location', 'field_55d30607a99a0');
			update_post_meta($post_id, 'redirect_to_offer', '');
			update_post_meta($post_id, '_redirect_to_offer', 'field_551976ac0f4e5');
			update_post_meta($post_id, 'show_pricing_fields', '0');
			update_post_meta($post_id, '_show_pricing_fields', 'field_568a54a25476a');
			update_post_meta($post_id, 'image_type', 'image');
			update_post_meta($post_id, '_image_type', 'field_55532006b5e0c');
			update_post_meta($post_id, 'ssd_post_button_clicks_count', '0');
			update_post_meta($post_id, 'ssd_post_views_count', '0');
			update_post_meta($post_id, 'ssd_couponhut_published_deal_email_pending', 'waiting_to_send');
			update_post_meta($post_id, 'geo_city', '');
			update_post_meta($post_id, 'geo_city_slug', '');
			update_post_meta($post_id, 'geo_country', '');
			update_post_meta($post_id, 'geo_country_slug', '');

			if ($config['import_images'] != 'Off') {
				$coupon_image_id = linkmydeals_import_image($coupon->image_url, $coupon->lmd_id) ?: 0;
				update_post_meta($post_id, 'header_image', $coupon_image_id);
				update_post_meta($post_id, '_header_image', 'field_56b75ea64f4f7');
				update_post_meta($post_id, 'image', $coupon_image_id);
				update_post_meta($post_id, '_image', 'field_55016dd111b9f');
				set_post_thumbnail($post_id, $coupon_image_id);
			}

			$count_new = $count_new + 1;
		} elseif ($coupon->status == 'updated') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Updating Coupon ({$coupon->lmd_id})')");

			$post_id = $wpdb->get_var("SELECT post_id FROM {$wp_prefix}postmeta WHERE meta_key = 'lmd_id' AND meta_value = '{$coupon->lmd_id}' LIMIT 0,1");

			$post_data = array(
				'ID'             => $post_id,
				'post_title'     => $coupon->title,
				'post_content'   => $coupon->description,
				'post_status'    => 'publish',
				'post_author'    => get_current_user_id()
			);

			wp_update_post($post_data);

			$cat_names = explode(',', $coupon->categories);
			$append = false;
			foreach ($cat_names as $cat) {
				wp_set_object_terms($post_id, $cat, 'mts_coupon_categories', $append);
				$append = true;
			}

			$store_names = explode(',', $coupon->store);
			$append = false;
			foreach ($store_names as $str) {
				wp_set_object_terms($post_id, $str, 'mts_coupon_tag', $append);
				$append = true;
			}

			update_post_meta($post_id, 'deal_type', $coupon->type ? 'Code' : 'discount');
			update_post_meta($post_id, '_deal_type', 'field_5519756e0f4e2');
			update_post_meta($post_id, 'coupon_code', $coupon->code);
			update_post_meta($post_id, '_coupon_code', 'field_551976780f4e4');
			update_post_meta($post_id, 'url', $coupon->url);
			update_post_meta($post_id, '_url', 'field_55016e3011ba3');
			update_post_meta($post_id, 'deal_summary', $coupon->badge);
			update_post_meta($post_id, '_deal_summary', 'field_554f8e55b6dd8');
			update_post_meta($post_id, 'discount_value', $coupon->badge);
			update_post_meta($post_id, '_discount_value', 'field_55016e0911ba1');
			update_post_meta($post_id, 'coupon_code_description', $coupon->title);
			update_post_meta($post_id, '_coupon_code_description', 'field_5b3dcdadd300c');
			update_post_meta($post_id, 'expiring_date', empty($coupon->end_date) ? '' : date("Ymd", strtotime($coupon->end_date)));
			update_post_meta($post_id, '_expiring_date', 'field_55016e3d11ba4');

			update_post_meta($post_id, 'deal_layout', 'small');
			update_post_meta($post_id, '_deal_layout', 'field_59db655dfb0cb');
			update_post_meta($post_id, 'virtual_deal', '1');
			update_post_meta($post_id, '_virtual_deal', 'field_56f12e67f1d15');
			update_post_meta($post_id, 'printable_coupon', '0');
			update_post_meta($post_id, '_printable_coupon', 'field_5683c0ebd307f');
			update_post_meta($post_id, 'registered_members_only', '0');
			update_post_meta($post_id, '_registered_members_only', 'field_5673f4f869107');
			update_post_meta($post_id, 'show_location', 'hide');
			update_post_meta($post_id, '_show_location', 'field_55d30607a99a0');
			update_post_meta($post_id, 'redirect_to_offer', '');
			update_post_meta($post_id, '_redirect_to_offer', 'field_551976ac0f4e5');
			update_post_meta($post_id, 'show_pricing_fields', '0');
			update_post_meta($post_id, '_show_pricing_fields', 'field_568a54a25476a');
			update_post_meta($post_id, 'image_type', 'image');
			update_post_meta($post_id, '_image_type', 'field_55532006b5e0c');
			update_post_meta($post_id, 'ssd_post_button_clicks_count', '0');
			update_post_meta($post_id, 'ssd_post_views_count', '0');
			update_post_meta($post_id, 'ssd_couponhut_published_deal_email_pending', 'waiting_to_send');
			update_post_meta($post_id, 'geo_city', '');
			update_post_meta($post_id, 'geo_city_slug', '');
			update_post_meta($post_id, 'geo_country', '');
			update_post_meta($post_id, 'geo_country_slug', '');

			if ($config['import_images'] != 'Off') {
				$coupon_image_id = linkmydeals_import_image($coupon->image_url, $coupon->lmd_id) ?: 0;
				update_post_meta($post_id, 'header_image', $coupon_image_id);
				update_post_meta($post_id, '_header_image', 'field_56b75ea64f4f7');
				update_post_meta($post_id, 'image', $coupon_image_id);
				update_post_meta($post_id, '_image', 'field_55016dd111b9f');
				set_post_thumbnail($post_id, $coupon_image_id);
			}

			$count_updated = $count_updated + 1;
		} elseif ($coupon->status == 'suspended') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Suspending Coupon ({$coupon->lmd_id})')");

			$post_id = $wpdb->get_var("SELECT post_id FROM {$wp_prefix}postmeta WHERE meta_key = 'lmd_id' AND meta_value = '{$coupon->lmd_id}' LIMIT 0,1");

			wp_delete_post($post_id, true);

			$count_suspended = $count_suspended + 1;
		}

		$wpdb->query("DELETE FROM {$wp_prefix}linkmydeals_upload WHERE lmd_id = {$coupon->lmd_id}");
	}

	$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'info','Processed Offers - $count_new New , $count_updated Updated , $count_suspended Suspended.')");
}


function linkmydeals_couponmart_process_batch($coupons,$config){
	global $wpdb;
	$wp_prefix = $wpdb->prefix;

	
	$count_new = $count_suspended = $count_updated = 0;
	$found_count = (count($coupons) > 0) ? count($coupons) : 0;

	$wpdb->query("INSERT INTO " . $wp_prefix . "linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'info','Found $found_count coupons to process')");

	foreach ($coupons as $coupon) {

		if ($coupon->status == 'new' or $coupon->status == '' or $coupon->status == 'active') {

			$wpdb->query("INSERT INTO " . $wp_prefix . "linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Adding New Coupon (" . $coupon->lmd_id . ")')");

			$post_data = array(
				'ID'             => '',
				'post_title'     => $coupon->title,
				'post_content'   => $coupon->description,	
				'post_status'    => 'publish',
				'post_type'      => 'coupon',
				'post_author'    => get_current_user_id()
			);
			$post_id = wp_insert_post($post_data);

			$cat_names = explode(',', $coupon->categories);
			$append = false;
			foreach ($cat_names as $cat) {
				if (!term_exists($cat, 'coupon_category')) {
					$term = wp_insert_term($cat, 'coupon_category'); 					
				}
				wp_set_object_terms($post_id, $cat, 'coupon_category', $append);
				$append = true;
			}

			$str_names = explode(',', $coupon->store);
			$append = false;
			foreach ($str_names as $str) {
				// Create New Store
				if (!term_exists($str, 'coupon_store')) {
					$term = wp_insert_term($str, 'coupon_store'); // , $args third parameter
					if (!is_wp_error($term)) { // Term did not exist. Got inserted now.
						
						// Update Meta Info
						update_term_meta($term['term_id'], '_wpc_store_url', $coupon->homepage_url);//store taxonomy args in wp_options
					}
				} 
				wp_set_object_terms($post_id, $str, 'coupon_store', $append);
				$append = true;
			}
			
			update_post_meta($post_id, 'lmd_id', $coupon->lmd_id);
			update_post_meta($post_id, '_wpc_coupon_type', ($coupon->type == 'Code' ? 'code' : 'sale'));
			update_post_meta($post_id, '_wpc_coupon_type_code', $coupon->code);

			update_post_meta($post_id, '_wpc_destination_url', $coupon->url);

			update_post_meta($post_id, '_wpc_start_on', (empty($coupon->start_date) ? '' : strtotime($coupon->start_date)));
			update_post_meta($post_id, '_wpc_expires', (empty($coupon->end_date) ? '' : strtotime($coupon->end_date)));
			update_post_meta($post_id, '_wpc_coupon_save', $coupon->badge );
			set_post_thumbnail($post_id, $config['import_images'] != 'Off' ? linkmydeals_import_image($coupon->image_url, $coupon->lmd_id) : 0);

			$count_new = $count_new + 1;
		} elseif ($coupon->status == 'updated') {

			$wpdb->query("INSERT INTO " . $wp_prefix . "linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Updating Coupon (" . $coupon->lmd_id . ")')");

			$lmd_id = $coupon->lmd_id;
			$sql_id = "SELECT post_id FROM " . $wp_prefix . "postmeta WHERE meta_key = 'lmd_id' AND meta_value = '$lmd_id' LIMIT 0,1";
			$post_id = $wpdb->get_var($sql_id);
			$data = get_post($post_id);
			$title = (!empty($coupon->title)) ? $coupon->title : $data->post_title;
			$description = (!empty($coupon->description)) ? $coupon->description : $data->post_content;
			$post_data = array(
				'ID'             => $post_id,
				'post_title'     => $title,
				'post_content'   => $description,
				'post_status'    => 'publish',
				'post_author'    => get_current_user_id()
			);
			wp_update_post($post_data);

			if (!empty($coupon->categories)) {
				$cat_names = explode(',', $coupon->categories);
				$append = false;
				foreach ($cat_names as $cat) {
					wp_set_object_terms($post_id, $cat, 'coupon_category', $append);
					$append = true;
				}
			}

			if (!empty($coupon->store)) {
				$str_names = explode(',', $coupon->store);
				$append = false;
				foreach ($str_names as $str) {
					wp_set_object_terms($post_id, $str, 'coupon_store', $append);
					$append = true;
				}
			}

			if (!empty($coupon->type)) {
				update_post_meta($post_id, '_wpc_coupon_type', ($coupon->type == 'Code' ? 'code' : 'sale'));
			}
			if (!empty($coupon->code)) {
				update_post_meta($post_id, '_wpc_coupon_type_code', $coupon->code);
			}

			if (!empty($coupon->url)) {
				update_post_meta($post_id, '_wpc_destination_url', $coupon->url);
			}
	
			$start_date = (!empty($coupon->start_date)) ? strtotime($coupon->start_date) : get_post_meta($post_id, '_wpc_start_on', true);
			$end_date = (!empty($coupon->end_date)) ? strtotime($coupon->end_date) : get_post_meta($post_id, '_wpc_expires', true);

			update_post_meta($post_id, '_wpc_start_on', (empty($start_date) ? '' : $start_date));
			if (empty($end_date)) {
				update_post_meta($post_id, '_wpc_expires', '');
			} else {
				update_post_meta($post_id, '_wpc_expires', $end_date);
			}

			$count_updated = $count_updated + 1;
		} elseif ($coupon->status == 'suspended') {

			$wpdb->query("INSERT INTO " . $wp_prefix . "linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Suspending Coupon (" . $coupon->lmd_id . ")')");

			$lmd_id = $coupon->lmd_id;
			$sql_id = "SELECT post_id FROM " . $wp_prefix . "postmeta WHERE meta_key = 'lmd_id' AND meta_value = '$lmd_id' LIMIT 0,1";
			$post_id = $wpdb->get_var($sql_id);

			wp_delete_post($post_id, true);

			$count_suspended = $count_suspended + 1;
		}

		$wpdb->query("DELETE FROM " . $wp_prefix . "linkmydeals_upload WHERE lmd_id = " . $coupon->lmd_id);
	}

	$wpdb->query("INSERT INTO " . $wp_prefix . "linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'info','Processed Offers - $count_new New , $count_updated Updated , $count_suspended Suspended.')");
}

function linkmydeals_coupon_press_process_batch($coupons,$config)
{
	global $wpdb;
	$wp_prefix = $wpdb->prefix;

	$categories = array();
	$categoryTerms = get_terms(array(
		'taxonomy' => 'coupon_category',
		'hide_empty' => false
	));
	foreach ($categoryTerms as $term) {
		$categories[$term->name] = $term->slug;
	}

	$stores = array();
	$storeTerms = get_terms(array(
		'taxonomy' => 'coupon_store',
		'hide_empty' => false
	));
	foreach ($storeTerms as $term) {
		$stores[$term->name] = $term->slug;
	}

	$count_new = $count_suspended = $count_updated = 0;
	$found_count = is_array($coupons) ? count($coupons) : 0;

	$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'info','Found $found_count coupons to process')");

	foreach ($coupons as $coupon) {

		if ($coupon->status == 'new' or $coupon->status == '' or $coupon->status == 'active') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Adding New Coupon ({$coupon->lmd_id})')");

			$post_data = array(
				'ID'             => '',
				'post_title'     => $coupon->title,
				'post_content'   => $coupon->description,
				'post_status'    => 'publish',
				'post_type'      => 'coupon',
				'post_author'    => get_current_user_id()
			);

			$post_id = wp_insert_post($post_data);

			$cat_names = explode(',', $coupon->categories);
			foreach ($cat_names as $cat) {
				wp_set_object_terms($post_id, $cat, 'coupon_category', true);
			}

			$store_names = explode(',', $coupon->store);
			$append = false;
			foreach ($store_names as $str) {
				if (!term_exists($str, 'coupon_store')) {
					$term = wp_insert_term($str, 'coupon_store'); // , $args third parameter
					if (!is_wp_error($term)) {
						update_term_meta($term['term_id'], '_wpc_store_url', $coupon->homepage_url);
						update_term_meta($term['term_id'], '_wpc_store_name', $str);
					}
				}
				wp_set_object_terms($post_id, $str, 'coupon_store', $append);
				$append = true;
			}

			update_post_meta($post_id, 'lmd_id', $coupon->lmd_id);
			update_post_meta($post_id, '_wpc_percent_success', '100');
			update_post_meta($post_id, '_wpc_used', '0');
			update_post_meta($post_id, '_wpc_today', '');
			update_post_meta($post_id, '_wpc_vote_up', '0');
			update_post_meta($post_id, '_wpc_vote_down', '0');
			update_post_meta($post_id, '_wpc_start_on', $coupon->start_date == '1970-01-01' ? '' : $coupon->start_date);
			if (!empty($coupon->end_date)) {
				update_post_meta($post_id, '_wpc_expires', strtotime($coupon->end_date));
			}
			update_post_meta($post_id, '_wpc_store', '');
			update_post_meta($post_id, '_wpc_coupon_save', $coupon->badge);
			update_post_meta($post_id, '_wpc_coupon_type', ($coupon->type == 'Code' ? 'code' : 'sale'));
			update_post_meta($post_id, '_wpc_coupon_type_code', $coupon->code);
			update_post_meta($post_id, '_wpc_destination_url', $coupon->url);
			update_post_meta($post_id, '_wpc_views', '0');

			set_post_thumbnail($post_id, $config['import_images'] != 'Off' ? linkmydeals_import_image($coupon->image_url, $coupon->lmd_id) : 0);

			$count_new = $count_new + 1;
		} elseif ($coupon->status == 'updated') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Updating Coupon ({$coupon->lmd_id})')");

			$post_id = $wpdb->get_var("SELECT post_id FROM {$wp_prefix}postmeta WHERE meta_key = 'lmd_id' AND meta_value = '{$coupon->lmd_id}' LIMIT 0,1");

			$post_data = array(
				'ID'             => $post_id,
				'post_title'     => $coupon->title,
				'post_content'   => $coupon->description,
				'post_status'    => 'publish',
				'post_author'    => get_current_user_id()
			);

			wp_update_post($post_data);

			$cat_names = explode(',', $coupon->categories);
			$append = false;
			foreach ($cat_names as $cat) {
				wp_set_object_terms($post_id, $cat, 'coupon_category', $append);
				$append = true;
			}

			$store_names = explode(',', $coupon->store);
			$append = false;
			foreach ($store_names as $str) {
				if (!term_exists($str, 'coupon_store')) {
					$term = wp_insert_term($str, 'coupon_store'); // , $args third parameter
					if (!is_wp_error($term)) {
						update_term_meta($term['term_id'], '_wpc_store_url', $coupon->homepage_url);
						update_term_meta($term['term_id'], '_wpc_store_name', $str);
					}
				}
				wp_set_object_terms($post_id, $str, 'coupon_store', $append);
				$append = true;
			}
			update_post_meta($post_id, '_wpc_start_on', $coupon->start_date == '1970-01-01' ? '' : $coupon->start_date);
			if (!empty($coupon->end_date)) {
				update_post_meta($post_id, '_wpc_expires', strtotime($coupon->end_date));
			}
			update_post_meta($post_id, '_wpc_coupon_type', ($coupon->type == 'Code' ? 'code' : 'sale'));
			update_post_meta($post_id, '_wpc_coupon_type_code', $coupon->code);
			update_post_meta($post_id, '_wpc_destination_url', $coupon->url);

			$count_updated = $count_updated + 1;
		} elseif ($coupon->status == 'suspended') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Suspending Coupon ({$coupon->lmd_id})')");

			$post_id = $wpdb->get_var("SELECT post_id FROM {$wp_prefix}postmeta WHERE meta_key = 'lmd_id' AND meta_value = '{$coupon->lmd_id}' LIMIT 0,1");

			wp_delete_post($post_id, true);

			$count_suspended = $count_suspended + 1;
		}

		$wpdb->query("DELETE FROM {$wp_prefix}linkmydeals_upload WHERE lmd_id = {$coupon->lmd_id}");
	}

	$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'info','Processed Offers - $count_new New , $count_updated Updated , $count_suspended Suspended.')");
}

function linkmydeals_generic_theme_process_batch($coupons, &$config)
{
	global $wpdb;
	$wp_prefix = $wpdb->prefix;

	$count_new = $count_suspended = $count_updated = 0;
	$found_count = is_array($coupons) ? count($coupons) : 0;

	$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'info','Found $found_count coupons to process')");

	$default_template = '<p class="has-medium-font-size">{{label}}</p>

	<hr/>
	
	<table style="border: none;border-collapse: collapse;">
	<tr>
	
		<td style="width: 64%;border: none;">
			<strong>Store</strong>: {{store}}<br>
			<strong>Coupon Code</strong>: {{code}}<br>
			<strong>Expiry</strong>: {{expiry}}
		</td>
		<td style="width: 36%;border: none;"> 
			
				<figure>{{image}}</figure><br>
				<div class="wp-block-buttons">
				<div class="wp-block-button has-custom-width wp-block-button__width-100 is-style-fill" style="text-align: center;"><a class="wp-block-button__link wp-element-button" href="{{link}}">Visit Website</a></div>
				</div>
			
		</td>
	
	</tr>
	
	</table>
	{{description}}';
	$uncategorized_id  = get_term_by( 'slug', 'uncategorized', 'category' )->term_id;
	$description_template = get_theme_mod('linkmydeals_custom_coupon_template' ,$default_template);
	foreach ($coupons as $coupon) {

		if ($coupon->status == 'new' or $coupon->status == '' or $coupon->status == 'active') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Adding New Coupon ({$coupon->lmd_id})')");
			
			$post_data = array(
				'ID'             => '',
				'post_title'     => $coupon->excerpt,
				'post_content'   => '',
				'post_status'    => 'publish',
				'post_excerpt' 	 => $coupon->description,
				'post_author'    => get_current_user_id()
			);
			$post_id = wp_insert_post($post_data);
			
			if ($config['store'] != 'none'){

				$store_term = get_term_by('name', $coupon->store, $config['store']);

				if(!$store_term){
					$result = wp_insert_term($coupon->store, $config['store']);
					$store_id = $result['term_id'];
				}else{
					$store_id = $store_term->term_id;
				}
				wp_set_object_terms( $post_id, intval($store_id), $config['store'] ); 
			} 	
			if ($config['category'] != 'none'){

				$category_list = [];
				if(!empty($coupon->categories)){
					foreach(explode(',',$coupon->categories) as $category){
						$categoryTerms = get_term_by('name', $category, $config['category']);
						if($categoryTerms){
							$category_list[] = $categoryTerms->term_id;
						} else {
							$result = wp_insert_term($category, $config['category']);
							$category_list[] = $result['term_id'];
						}
					}
				}

				wp_set_object_terms($post_id,$category_list,$config['category'],true);
			} 
			
			wp_remove_object_terms($post_id, $uncategorized_id, 'category');
			$start_date = '';
			if(!empty($coupon->start_date)){
				$dt = get_date_from_gmt($coupon->start_date, 'Y-m-d');// convert from GMT to local date/time based on WordPress time zone setting.
				$start_date = date_i18n(get_option('date_format') , strtotime($dt));// get format from WordPress settings.
			}
			
			$end_date = '';
			if(!empty($coupon->end_date)){
				$dt = get_date_from_gmt($coupon->end_date, 'Y-m-d');// convert from GMT to local date/time based on WordPress time zone setting.
				$end_date =  date_i18n(get_option('date_format') , strtotime($dt));// get format from WordPress settings.
			}
			$coupon_image_url = '';
			if ($config['import_images'] != 'Off' and $coupon->image_url) {
				$coupon_image_id = linkmydeals_import_image($coupon->image_url, $coupon->lmd_id) ?: 0;
				$coupon_image_url = wp_get_attachment_url($coupon_image_id);
				if($config['featured_image'] != 'Off' and !empty($coupon_image_url)) {
					set_post_thumbnail($post_id, $coupon_image_id);
				}
			}
			
			$replace_variable_list_keys = ['{{description}}','{{link}}','{{label}}','{{store}}','{{code}}','{{start_date}}','{{expiry}}','{{image}}','{{image_url}}'];
			$replace_variable_list_values = 
			[
				"<p>".$coupon->description."</p>" ,
				$coupon->url ,
				$coupon->title,
				$coupon->store ,
				($coupon->code ?: $config['code_text']),
				$start_date ,
				$end_date, 
				($config['import_images']!='Off' && $coupon_image_url) ?"<img class='wp-image-351' src='".$coupon_image_url."' />": "",
				($config['import_images']!='Off' && $coupon_image_url) ?$coupon_image_url: ""
			];

			$description = $description_template;
			
			$description = str_replace($replace_variable_list_keys,$replace_variable_list_values,$description);
			$post_data = array(
				'ID' => $post_id,
				'post_content' => $description,
			);
			
			wp_update_post($post_data);
			
			update_post_meta($post_id, 'lmd_id', $coupon->lmd_id);
			update_post_meta($post_id, 'lmd_link', $coupon->url);
			update_post_meta($post_id, 'lmd_code', ($coupon->code ?: $config['code_text']));
			update_post_meta($post_id, 'lmd_store', $coupon->store);
			update_post_meta($post_id, 'lmd_label', $coupon->title);
			update_post_meta($post_id, 'lmd_image_url', $coupon_image_url);
			update_post_meta($post_id, 'lmd_start_date', ($coupon->start_date ?: ''));
			update_post_meta($post_id, 'lmd_valid_till', ($coupon->end_date ?: ''));
			$count_new = $count_new + 1;
			
		} elseif ($coupon->status == 'updated') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Updating Coupon ({$coupon->lmd_id})')");

			$post_id = $wpdb->get_var("SELECT post_id FROM {$wp_prefix}postmeta WHERE meta_key = 'lmd_id' AND meta_value = '{$coupon->lmd_id}' LIMIT 0,1");		  
			
			$data = get_post($post_id);

			$old_post_meta = get_post_meta($post_id);

			$coupon->start_date = ($coupon->start_date?:$old_post_meta['lmd_start_date'][0]??'');
			$coupon->end_date = ($coupon->end_date?:$old_post_meta['lmd_valid_till'][0]??'');
			$coupon->url = ($coupon->url?:$old_post_meta['lmd_link'][0]??'');
			$coupon->title = ($coupon->title?:$old_post_meta['lmd_label'][0]??'');
			$coupon->store = ($coupon->store?:$old_post_meta['lmd_store'][0]??'');
			$coupon->code = ($coupon->code?:$old_post_meta['lmd_code'][0] ?? '');
			$coupon->description = ($coupon->description?:$data->post_excerpt ?? '');
			
			$post_data = array(
				'ID'             => $post_id?:'',
				'post_title'     => $coupon->excerpt ?: $data->post_title,
				'post_content'   => '',
				'post_status'    => 'publish',
				'post_excerpt' => $coupon->description,
				'post_author'    => get_current_user_id()
			);
			$coupon_image_url = '';
			if(!$post_id) {
				$post_id = wp_insert_post($post_data);
				if (!empty($coupon->image_url) and $config['import_images'] != 'Off') {
					$coupon_image_id = linkmydeals_import_image($coupon->image_url, $coupon->lmd_id) ?: 0;
					$coupon_image_url = wp_get_attachment_url($coupon_image_id);
					if($config['featured_image'] != 'Off' and !empty($coupon_image_url)) {
						set_post_thumbnail($post_id, $coupon_image_id);
					}
				}
			} else {
				wp_update_post($post_data);
				$coupon_image_url = $old_post_meta['lmd_image_url'][0]??'';
			}
			
			if ($config['store'] != 'none'){

				$store_term = get_term_by('name', $coupon->store, $config['store']);
	
				if(!$store_term){
					$result = wp_insert_term($coupon->store, $config['store']);
					$store_id = $result['term_id'];
				}else{
					$store_id = $store_term->term_id;
				}
				wp_set_object_terms( $post_id, intval($store_id), $config['store'] ); 
			} 	
			if ($config['category'] != 'none'){

				$category_list = [];
				if(!empty($coupon->categories)){
					foreach(explode(',',$coupon->categories) as $category){
						$categoryTerms = get_term_by('name', $category, $config['category']);
						if($categoryTerms){
							$category_list[] = $categoryTerms->term_id;
						} else {
							$result = wp_insert_term($category, $config['category']);
							$category_list[] = $result['term_id'];
						}
					}
				}

				wp_set_object_terms($post_id,$category_list,$config['category'],true);
			} 
			
			wp_remove_object_terms($post_id, $uncategorized_id, 'category');
			
			$start_date = '';
			if(!empty($coupon->start_date)){
				$dt = get_date_from_gmt($coupon->start_date, 'Y-m-d');// convert from GMT to local date/time based on WordPress time zone setting.
				$start_date = date_i18n(get_option('date_format') , strtotime($dt));// get format from WordPress settings.
			}
			
			$end_date = '';
			if(!empty($coupon->end_date)){
				$dt = get_date_from_gmt($coupon->end_date, 'Y-m-d');// convert from GMT to local date/time based on WordPress time zone setting.
				$end_date =  date_i18n(get_option('date_format') , strtotime($dt));// get format from WordPress settings.
			}


			$replace_variable_list_keys = ['{{description}}','{{link}}','{{label}}','{{store}}','{{code}}','{{start_date}}','{{expiry}}','{{image}}','{{image_url}}'];
			$replace_variable_list_values = 
			[
				'<p>'.$coupon->description.'</p>' ,
				$coupon->url ,
				$coupon->title,
				$coupon->store ,
				($coupon->code ?: $config['code_text']),
				$start_date ,
				$end_date, 
				($config['import_images']!='Off' and !empty($coupon_image_url))? "<img class='wp-image-351' src='".$coupon_image_url."' />" :"",
				($config['import_images']!='Off')?$coupon_image_url:""
			];

			$description = $description_template;
			
			$description = str_replace($replace_variable_list_keys,$replace_variable_list_values,$description);

			
			
			$post_data = array(
				'ID' => $post_id,
				'post_content' => $description,
			);

			wp_update_post($post_data);	

			update_post_meta($post_id, 'lmd_id', $coupon->lmd_id);
			update_post_meta($post_id, 'lmd_start_date', ($coupon->start_date ?: ''));
			update_post_meta($post_id, 'lmd_valid_till', ($coupon->end_date ?: ''));
			update_post_meta($post_id, 'lmd_link', $coupon->url);
			update_post_meta($post_id, 'lmd_label', $coupon->title);
			update_post_meta($post_id, 'lmd_store', $coupon->store);
			update_post_meta($post_id, 'lmd_image_url', $coupon_image_url);
			update_post_meta($post_id, 'lmd_code', ($coupon->code ?: $config['code_text']));

			$count_updated = $count_updated + 1;


		} elseif ($coupon->status == 'suspended') {

			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'debug','Suspending Coupon ({$coupon->lmd_id})')");

			$post_id = $wpdb->get_var("SELECT post_id FROM {$wp_prefix}postmeta WHERE meta_key = 'lmd_id' AND meta_value = '{$coupon->lmd_id}' LIMIT 0,1");

			wp_delete_post($post_id, true);

			$count_suspended = $count_suspended + 1;
		}

		$wpdb->query("DELETE FROM {$wp_prefix}linkmydeals_upload WHERE lmd_id = {$coupon->lmd_id}");
	}

	$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'info','Processed Offers - $count_new New , $count_updated Updated , $count_suspended Suspended.')");
}


function linkmydeals_import_image($image_url, $lmd_id = 0)
{
	// return 0 if empty url or image is svg (as it is not supported and also takes too much time to fetch)
	if (empty($image_url) or strpos($image_url, '.svg') !== false) return 0;

	// timeout of 5s to fetch image contents and return 0 if it fails
	$image = file_get_contents($image_url, false, stream_context_create(array('http' => array('timeout' => 5))));
	if ($image === false) return 0;

	// check for valid mime type and ratio between 3:2 and 4:1 return 0 if any one is false 
	$image_info = getimagesizefromstring($image);
	if (!in_array($image_info['mime'], array('image/bmp', 'image/jpeg', 'image/png', 'image/jpg', 'image/gif', 'image/webp')) or !$image_info[0] or !$image_info[1] or $image_info[0] / $image_info[1] < 1.5 or $image_info[0] / $image_info[1] > 4) return 0;

	// create dir/filepath for current image to be imported
	$wp_upload_dir = wp_upload_dir();
	$upload_dir    = wp_mkdir_p($wp_upload_dir['path']) ? $wp_upload_dir['path'] : $wp_upload_dir['basedir'];
	$uniquename    = "lmd_" . md5($image);
	$filename  	   = "$uniquename." . preg_split("/[\\/]+/", $image_info['mime'])[1]; // Create image file name
	$filepath  	   = "$upload_dir/$filename";

	// save image if it does not exists and return 0 if it fails 
	global $wpdb;
	$wp_prefix = $wpdb->prefix;
	$attach_id = intval($wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE guid LIKE '%{$uniquename}%' LIMIT 1"));
	if ($attach_id === 0) {
		$start_time = microtime(true);
		if (!is_writable($upload_dir) or file_put_contents($filepath, $image) === false) {
			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message,duration) VALUES (" . microtime(true) . ",'debug','Image - Failed To Upload', " . (microtime(true) - $start_time) . ")");
			return 0;
		}

		$attach_id = wp_insert_attachment(array(
			'post_mime_type' => $image_info['mime'],
			'post_title'     => $filename,
			'post_content'   => '',
			'post_status'    => 'inherit'
		), $filepath);

		$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message,duration) VALUES (" . microtime(true) . ",'debug','Image - Successfully Uploaded', " . (microtime(true) - $start_time) . ")");
	} else {
		$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message,duration) VALUES (" . microtime(true) . ",'debug','Image - Already Exists', 0)");
	}

	require_once(ABSPATH . 'wp-admin/includes/image.php');
	wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $filepath));
	update_post_meta($attach_id, 'image_lmd_id', $lmd_id);

	return $attach_id;
}
