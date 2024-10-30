<?php

/**
 * @package LinkMyDeals
 * @version 2.1.0
 */

/**
 * Plugin Name: LinkMyDeals
 * Plugin URI: https://linkmydeals.com/wordpress/
 * Author URI: https://linkmydeals.com/
 * Description: LinkMyDeals.com provides Coupon & Deal Feeds from hundreds of Online Stores. You can use this plugin to automate/upload the feeds into your Coupon Theme.
 * Version: 2.1.0
 * Author: LinkMyDeals Team
 **/

if (!defined('ABSPATH')) exit; // Exit if accessed directly

require plugin_dir_path(__FILE__) . 'activate.php';
require plugin_dir_path(__FILE__) . 'deactivate.php';
require plugin_dir_path(__FILE__) . 'views.php';
require plugin_dir_path(__FILE__) . 'save-api-config.php';
require plugin_dir_path(__FILE__) . 'delete-offers.php';
require plugin_dir_path(__FILE__) . 'pull-feed.php';
if (!function_exists('wp_get_current_user')) require ABSPATH . 'wp-includes/pluggable.php';

function linkmydeals_submit_delete_offers()
{
	if (wp_verify_nonce($_POST['delete_offers_nonce'], 'linkmydeals')) {
		$message = linkmydeals_delete_offers();
	} else {
		$message = '<div class="notice notice-error is-dismissible"><p>Access Denied. Nonce could not be verified.</p></div>';
	}
	setcookie('message', $message);
	wp_redirect('admin.php?page=linkmydeals');
	exit();
}

function linkmydeals_submit_delete_images()
{
	if (wp_verify_nonce($_POST['delete_images_nonce'], 'linkmydeals')) {
		$message = linkmydeals_delete_images();
	} else {
		$message = '<div class="notice notice-error is-dismissible"><p>Access Denied. Nonce could not be verified.</p></div>';
	}
	setcookie('message', $message);
	wp_redirect('admin.php?page=linkmydeals');
	exit();
}

function linkmydeals_submit_sync_offers()
{
	if (wp_verify_nonce($_POST['sync_offers_nonce'], 'linkmydeals')) {
		global $wpdb;
		linkmydeals_delete_offers(); // drop all offers
		$wpdb->query("REPLACE INTO {$wpdb->prefix}linkmydeals_config (name,value) VALUES ('last_extract','100')"); // change last extract
		wp_schedule_single_event(time(), 'linkmydeals_pull_feed_event'); // pull feed
		$message = '<div class="notice notice-success is-dismissible"><p>Sync process has been initiated. Refresh <a href="admin.php?page=linkmydeals-logs">Logs</a> to see current status.</p></div>';
	} else {
		$message = '<div class="notice notice-error is-dismissible"><p>Access Denied. Nonce could not be verified.</p></div>';
	}
	setcookie('message', $message);
	wp_redirect('admin.php?page=linkmydeals');
	exit();
}

function linkmydeals_submit_pull_feed()
{
	if (wp_verify_nonce($_POST['pull_feed_nonce'], 'linkmydeals')) {
		$message = linkmydeals_pull_feed();
	} else {
		$message = '<div class="notice notice-error is-dismissible"><p>Access Denied. Nonce could not be verified.</p></div>';
	}
	setcookie('message', $message);
	wp_redirect('admin.php?page=linkmydeals');
	exit();
}

function linkmydeals_file_upload()
{
	if (wp_verify_nonce($_POST['file_upload_nonce'], 'linkmydeals')) {
		if (!function_exists('wp_handle_upload'))  require_once(ABSPATH . 'wp-admin/includes/file.php');

		$movefile = wp_handle_upload($_FILES['feed'], array('test_form' => false, 'mimes' => array('csv' => 'text/csv')));
		if (!$movefile or isset($movefile['error'])) {
			$message .= '<div class="notice notice-error is-dismissible"><p>Error during File Upload :' . $movefile['error'] . '</p></div>';
		} else {
			global $wpdb;
			$wp_prefix = $wpdb->prefix;
			$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'info','Uploading File')");
			$feedFile = $movefile['file'];
			$wpdb->query('SET autocommit = 0;');
			$result = linkmydeals_save_csv_to_db($feedFile);
			if (!$result['error']) {
				$wpdb->query('COMMIT;');
				$wpdb->query('SET autocommit = 1;');
				$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'info','Offer Feed saved to local database. Starting upload process') ");
				wp_schedule_single_event(time(), 'linkmydeals_process_batch_event'); // process next batch
				$message = '<div class="notice notice-info is-dismissible"><p>Upload process is running in background. Refresh <a href="admin.php?page=linkmydeals-logs">Logs</a> to see current status.</p></div>';
			} else {
				$wpdb->query('ROLLBACK');
				$wpdb->query('SET autocommit = 1;');
				$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES
								(" . microtime(true) . ",'debug','" . esc_sql($result['error_msg']) . "'),
								(" . microtime(true) . ",'error','Error uploading feed to local database')");
				$message = '<div class="notice notice-error is-dismissible"><p>Error uploading feed to local database.</p></div>';
			}
		}
	} else {
		$message = '<div class="notice notice-error is-dismissible"><p>Access Denied. Nonce could not be verified.</p></div>';
	}
	setcookie('message', $message);
	wp_redirect('admin.php?page=linkmydeals');
	exit();
}

function linkmydeals_download_logs()
{
	$message = '';
	if (wp_verify_nonce($_GET['log_nonce'], 'linkmydeals')) {
		global $wpdb;

		$gmt_offset = get_option('gmt_offset');
		$offset_sign = ($gmt_offset < 0) ? '-' : '+';
		$positive_offset = ($gmt_offset < 0) ? $gmt_offset * -1 : $gmt_offset;
		$hours = floor($positive_offset);
		$minutes = round(($positive_offset - $hours) * 60);
		$tz = "$offset_sign$hours:$minutes";

		$logs = $wpdb->get_results("SELECT
										CONCAT(CONVERT_TZ(logtime,@@session.time_zone,'$tz'),' ','$tz') logtime,
										msg_type,
										message
									FROM  {$wpdb->prefix}linkmydeals_logs
									ORDER BY microtime");

		$filename = "linkmydeals_" . date("YmdHis") . ".log";

		header("Content-Type: text/csv");
		header("Content-Disposition: attachment; filename={$filename}");
		header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1
		header("Pragma: no-cache"); // HTTP 1.0
		header("Expires: 0"); // Proxies
		header("Content-Transfer-Encoding: UTF-8");

		$fp = fopen("php://output", "w");
		foreach ($logs as $log)
			fputcsv($fp, array($log->logtime, $log->msg_type, $log->message), "\t");
		fclose($fp);
	} else {
		$message = '<div class="notice notice-error is-dismissible"><p>Access Denied. Nonce could not be verified.</p></div>';
	}
	setcookie('message', $message);
	wp_redirect('admin.php?page=linkmydeals-logs');
	exit();
}

function linkmydeals_save_custom_template(){
	if (wp_verify_nonce($_POST['custom_template_nonce'], 'linkmydeals')){
		set_theme_mod('linkmydeals_custom_coupon_template', $_POST['linkmydeals_custom_coupon_template']);
	}
	wp_redirect('admin.php?page=coupon-custom-template');
	exit();
}

function linkmydeals_get_config()
{

	global $wpdb;

	$theme = get_template();

	$wp_upload_dir = wp_upload_dir();
	$upload_dir = wp_mkdir_p($wp_upload_dir['path']) ? $wp_upload_dir['path'] : $wp_upload_dir['basedir'];

	$required_plugins = array();
	if ($theme == 'rehub-theme') {
		$required_plugins = array('Elementor' => 'elementor/elementor.php', 'Rehub Framework' => 'rehub-framework/rehub-framework.php');
	}

	$result = array(
		'theme'		         => $theme,
		'missing_plugins'    => implode("', '", array_keys(array_diff($required_plugins, array_values(get_option('active_plugins'))))),
		'charset'  	         => strtolower($wpdb->charset),
		'curl'  	         => in_array('curl', get_loaded_extensions()),
		'autopilot'          => 'Off',
		'last_cron'          => '',
		'import_images'      => 'Off',
		'batch_size'         => 500,
		'wp_upload_dir'      => $upload_dir,
		'allow_url_fopen'    => ini_get('allow_url_fopen'),
		'exif'				 => function_exists('exif_imagetype'),
		'file_permissions'   => is_writable($upload_dir),
		'image_upload_speed' => $wpdb->get_var("SELECT AVG(duration) FROM `{$wpdb->prefix}linkmydeals_logs` WHERE message LIKE 'Image%' AND duration > 0"),
		'batch_upload_speed' => $wpdb->get_var("SELECT AVG(duration) FROM `{$wpdb->prefix}linkmydeals_logs` WHERE message LIKE 'Batch%' AND duration > 0"),
	);

	$config = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}linkmydeals_config");
	foreach ($config as $row)
		$result[$row->name] = $row->value;

	return $result;
}

function linkmydeals_get_troubleshootings()
{


	$configs = linkmydeals_get_config();

	$troubleshooting = array();

	// API Key
	if ($configs['autopilot'] == 'On') {
		$usage = json_decode(file_get_contents("http://feed.linkmydeals.com/getUsageDetails/?API_KEY={$configs['API_KEY']}"), true);
		if (!$usage['result']) {
			$troubleshooting['API Key'] = array(
				'status' => 'no',
				'message' => __('Invalid API Key or Account has Expired. Please check your API Key from <a target=\'_blank\' href=\'https://linkmydeals.com/account/dashboard.php\'>LinkMyDeals Dashboard</a>.', 'linkmydeals'),
			);
		} else {
			$troubleshooting['API Key'] = array(
				'status' => 'yes',
				'message' => __('You have an active subscription with LinkMyDeals', 'linkmydeals'),
			);
		}
	}

	// Theme
	if (linkmydeals_is_theme_supported($configs['theme'])) {
		if (!empty($configs['missing_plugins'])) {
			$troubleshooting['Theme'] = array(
				'status' => 'warning',
				'message' => sprintf( /* translators: 1: missing plugins list 2: theme name */__('Missing plugin(s) (\'%1$s\') required by %2$s theme to function properly.', 'linkmydeals'), $configs['missing_plugins'], ucfirst($configs['theme'])),
			);
		} else {
			$troubleshooting['Theme'] = array(
				'status' => 'yes',
				'message' => sprintf( /* translators: 1: theme name */__('LinkMyDeals works perfectly with %1$s theme', 'linkmydeals'), ucfirst($configs['theme'])),
			);
		}
	} else {
		$troubleshooting['Theme'] = array(
			'status' => 'warning',
			'message' => sprintf(__("It seems you are using a generic WordPress blogging theme instead of a niche Coupon Theme. Linkmydeals will still import offers, however they will be available as simple \"WordPress Posts\". If you feel this is a mistake, and your theme natively supports Coupons, then please create a <a href='https://support.linkmydeals.com/open.php' >Ticket</a>. Our technical team will assess the feasibility of integrating with your theme.", "linkmydeals"), ucfirst($configs['theme'])));
		
	}

	// WP-Cron
	if (empty($configs['last_cron'])) {
		$troubleshooting['WP-Cron'] = array(
			'status' => 'no',
			'message' => __('WP-Cron is possibly disabled on your server.', 'linkmydeals'),
		);
	} elseif (time() - $configs['last_cron'] > 600) {
		$troubleshooting['WP-Cron'] = array(
			'status' => 'warning',
			'message' => sprintf( /* translators: 1: Last Cron Run Time  */__('WP-Cron has not run since %1$s', 'linkmydeals'), date('jS F Y, g:i a', $configs['last_cron'] + get_option('gmt_offset') * 60 * 60)),
		);
	} else {
		$troubleshooting['WP-Cron'] = array(
			'status' => 'yes',
			'message' => sprintf( /* translators: 1: Last Cron Run Time  */__('WP-Cron is working fine. Last successful run was on %1$s', 'linkmydeals'), date('jS F Y, g:i a', round($configs['last_cron'] + get_option('gmt_offset') * 60 * 60))),
		);
	}

	// CURL
	if ($configs['curl']) {
		$troubleshooting['cURL'] = array(
			'status' => 'yes',
			'message' => __('PHP CURL module is working', 'linkmydeals'),
		);
	} else {
		$troubleshooting['cURL'] = array(
			'status' => 'no',
			'message' => __('PHP CURL directive is not working. It is required to call external APIs. Please contact your hosting provider and get it enabled.', 'linkmydeals'),
		);
	}

	// Images
	$images_unspported_themes = array('couponer', 'clipper');
	if ($configs['import_images'] == 'On' and in_array($configs['theme'], $images_unspported_themes)) {
		$troubleshooting['Images'] = array(
			'status' => 'warning',
			'message' => sprintf( /* translators: 1: theme name */__('%1$s theme does not have images for coupons/deals. Please add \'Store Logos\' to stores/merchants in your theme to display on offers.', 'linkmydeals'), ucfirst($configs['theme'])),
		);
	} elseif ($configs['import_images'] == 'On') {
		$troubleshooting['Images'] = array(
			'status' => 'yes',
			'message' => __('Note: LinkMyDeals will only pass images to your website when the merchant/source has added an image for the offer.<br/>We highly recommend you add Store Logos in WordPress as a fallback when image is not available for an offer.', 'linkmydeals'),
		);
	}

	// DB Character Set
	if (strpos($configs['charset'], 'utf') !== false) {
		$troubleshooting['Database'] = array(
			'status' => 'yes',
			'message' => sprintf( /* translators: 1: database character-set */__('Your Database Character Set <code>%1$s</code> supports Non-English characters.', 'linkmydeals'), $configs['charset']),
		);
	} else {
		$troubleshooting['Database'] = array(
			'status' => 'warning',
			'message' => sprintf( /* translators: 1: database character-set */__('Your Database Character Set <code>%1$s</code> does not support Non-English characters.', 'linkmydeals'), $configs['charset']),
		);
	}

	// File Permissions
	if ($configs['file_permissions']) {
		$troubleshooting['File Permissions'] = array(
			'status' => 'yes',
			'message' => sprintf( /* translators: 1: image upload directory*/__('Folder <samp>%1$s</samp> has the required <strong>rwx</strong> permssions', 'linkmydeals'), $configs['wp_upload_dir']),
		);
	} else {
		$troubleshooting['File Permissions'] = array(
			'status' => 'no',
			'message' => sprintf( /* translators: 1: image upload directory */__('Folder <samp>%1$s</samp> does not have the required <strong>rwx</strong> permssions', 'linkmydeals'), $configs['wp_upload_dir']),
		);
	}

	// Server Speed
	if ($configs['theme'] != 'clipmydeals') {
		if (!$configs['image_upload_speed']) {
			$troubleshooting['Server Speed'] = array(
				'status' => 'yes',
				'message' => __('Image Import is disabled/ Images are never uploded', 'linkmydeals'),
			);
		} elseif ($configs['image_upload_speed'] < 1 and $configs['batch_upload_speed'] <= 45) {
			$troubleshooting['Server Speed'] = array(
				'status' => 'yes',
				'message' => sprintf( /* translators: 1: upload batch size 2: time taken to upload images per batch */__('Your Server speed is optimal.<br>Batch Size - %1$s<br>Batch Upload Speed - %2$ss<br>Image Upload Speed - %3$ss', 'linkmydeals'), $configs['batch_size'], $configs['batch_upload_speed'], $configs['image_upload_speed'] * $configs['batch_size']),
			);
		} elseif ($configs['image_upload_speed'] < 2 and $configs['batch_upload_speed'] <= 60) {
			$troubleshooting['Server Speed'] = array(
				'status' => 'warning',
				'message' => sprintf( /* translators: 1: upload batch size 2: time taken to upload images per batch */__('Your Server Might Be Slow.<br>Batch Size - %1$s<br>Batch Upload Speed - %2$ss<br>Image Upload Speed - %3$ss', 'linkmydeals'), $configs['batch_size'], $configs['batch_upload_speed'], $configs['image_upload_speed'] * $configs['batch_size']),
			);
		} else {
			$troubleshooting['Server Speed'] = array(
				'status' => 'no',
				'message' => sprintf( /* translators: 1: upload batch size 2: time taken to upload images per batch */__('Your Server Is Slow.<br>Batch Size - %1$s<br>Batch Upload Speed - %2$ss<br>Image Upload Speed - %3$ss', 'linkmydeals'), $configs['batch_size'], $configs['batch_upload_speed'], $configs['image_upload_speed'] * $configs['batch_size']),
			);
		}
	}

	// Allow url fopen
	if ($configs['allow_url_fopen']) {
		$troubleshooting['Allow URL fopen'] = array(
			"status" => 'yes',
			"message" => __('PHP <code>allow_url_fopen</code> is On.', 'linkmydeals'),
		);
	} else {
		$troubleshooting['Allow URL fopen'] = array(
			"status" => 'warning',
			"message" => __('PHP <code>allow_url_fopen</code> is Off.', 'linkmydeals'),
		);
	}

	// Exif
	if($configs['theme'] == 'clipmydeals'){
		if ($configs['exif']) {
			$troubleshooting['Exif'] = array(
				'status' => 'yes',
				'message' => __('PHP exif module is enabled ', 'linkmydeals'),
			);
		} else {
			$troubleshooting['Exif'] = array(
				'status' => 'no',
				'message' => __('PHP exif module is not enabled. Please contact your hosting provider and get it enabled.', 'linkmydeals'),
			);
		}
	}

	return $troubleshooting;
}

function linkmydeals_register_api()
{
	register_rest_route('linkmydeals/v1', 'checkStatus', array(
		'methods'  => 'GET',
		'callback' => 'linkmydeals_server_checks',
		'permission_callback' => '__return_true',
		'args' => array(
			'API_KEY' => array(
				'required' => true
			),
			'debug_log' => array(
				'required' => false
			),
		)
	));
}

function linkmydeals_server_checks($data)
{
	global $wpdb;
	$response = linkmydeals_get_config();

	if ($data['API_KEY'] != $response['API_KEY']) {
		$response = array("API_KEY" => "Incorrect API Key");
	} else if($data['debug_log']=='yes'){
		$response['logs'] = $wpdb->get_results("SELECT `logtime`, `message`, `msg_type` FROM `{$wpdb->prefix}linkmydeals_logs` ORDER BY `microtime`");
	} else {
		$response['logs'] = $wpdb->get_results("SELECT logtime,message,msg_type FROM {$wpdb->prefix}linkmydeals_logs WHERE msg_type != 'debug' ORDER BY microtime DESC LIMIT 20");
	}

	return new WP_REST_Response(
		$response,
		200,
		array('Cache-Control' => 'no-cache, no-store, must-revalidate', 'Pragma' => 'no-cache', 'Expires' => '0', 'Content-Transfer-Encoding' => 'UTF-8')
	);
}


function linkmydeals_is_theme_supported($theme_name)
{
	$supported = array('clipmydeals', 'clipper', 'couponxl', 'couponxxl', 'couponer', 'rehub', 'rehub-theme', 'wpcoupon', 'wp-coupon', 'wp-coupon-pro', 'CP', 'cp', 'CPq', 'mts_coupon', 'couponis', 'couponhut','coupon-mart','coupon-press');
	return (in_array($theme_name, $supported) or substr($theme_name, 0, 2) === "CP");
}


function linkmydeals_admin_menu()
{
	//add_menu_page("LinkMyDeals", "LinkMyDeals", 'manage_options', "linkmydeals", "linkmydeals_display_main", "dashicons-rss",9);
	add_menu_page("LinkMyDeals", "LinkMyDeals", 'manage_options', "linkmydeals", "linkmydeals_display_settings", "dashicons-randomize", 9);
	add_submenu_page("linkmydeals", "LinkMyDeals - Import Coupons & Deals from Affiliate Networks",	"Import Coupon Feed", 'manage_options', "linkmydeals", "linkmydeals_display_settings");
	add_submenu_page("linkmydeals", "LinkMyDeals - Logs", "Logs", 'manage_options', "linkmydeals-logs", "linkmydeals_display_logs");
	if(!linkmydeals_is_theme_supported(get_template())){
		add_submenu_page( 'linkmydeals', 'Linkmydeals - Coupon Custom Template', 'Coupon Custom Template', 'manage_options', 'coupon-custom-template', 'linkmydeals_custom_template' );
	}
}

function linkmydeals_check_wpcron()
{
	global $wpdb;
	$wpdb->query("REPLACE INTO {$wpdb->prefix}linkmydeals_config (name,value) VALUES ('last_cron'," . microtime(true) . ")");
}

function linkmydeals_get_troubleshootings_message()
{
	$troubleshootings = linkmydeals_get_troubleshootings();
	$critical = $warnings = 0;
	foreach ($troubleshootings as $key => $value) {
		if ($value['status'] == 'warning') {
			$warnings++;
		} elseif ($value['status'] == 'no') {
			$critical++;
		}
	}

	$issue_msg = $sep = "";
	if ($critical) {
		$issue_msg .= "$critical critical issue(s)";
		$sep = " and ";
	}

	if ($warnings) {
		$issue_msg .= "$sep$warnings warning(s)";
	}

	return empty($issue_msg) ? false : sprintf(
		/* translators: 1: Warning Message 2: Visit Site Health */
		__('<span class="dashicons dashicons-bell text-warning me-2 mr-2"></span><b>Linkmydeals:</b> You have %1$s in your configuration. <a class="text-info" href="%2$s">See details</a>.', 'linkmydeals'),
		$issue_msg,
		admin_url('site-health.php?tab=linkmydeals-troubleshooting')
	);
}

function linkmydeals_notify_troubleshootings()
{
	$error_message = linkmydeals_get_troubleshootings_message();
	if ($error_message and get_current_screen()->base !== "site-health") echo wp_kses("<div class='notice notice-error'> <p> $error_message </p> </div>", array("div" => array("class" => array()), "p" => array(), "b" => array(), "a" => array("class" => array(), "href" => array()), "span" => array("class" => array())));
}

add_filter('cron_schedules', function ($schedules) {
	return array_merge($schedules, array('every_five_minutes' => array('interval'  => 60 * 5, 'display'   => __('Every 5 Minutes', 'linkmydeals'))));
});

add_filter('site_health_navigation_tabs', function ($tabs) {
	return array_merge($tabs, array('linkmydeals-troubleshooting' => esc_html_x('LinkMyDeals', 'Site Health', 'linkmydeals')));
});

add_action('linkmydeals_check_wpcron_event', 'linkmydeals_check_wpcron');
add_action('admin_menu', 'linkmydeals_admin_menu', 9);
add_action('admin_post_linkmydeals_save_api_config', 'linkmydeals_save_api_config');
add_action('admin_post_linkmydeals_save_import_config', 'linkmydeals_save_import_config');
add_action('admin_post_linkmydeals_sync_offers', 'linkmydeals_submit_sync_offers');
add_action('admin_post_linkmydeals_delete_offers', 'linkmydeals_submit_delete_offers');
add_action('admin_post_linkmydeals_delete_images', 'linkmydeals_submit_delete_images');
add_action('admin_post_linkmydeals_pull_feed', 'linkmydeals_submit_pull_feed');
add_action('admin_post_linkmydeals_file_upload', 'linkmydeals_file_upload');
add_action('admin_post_linkmydeals_download_logs', 'linkmydeals_download_logs');
add_action('admin_post_lmd_custom_template', 'linkmydeals_save_custom_template');
add_action('linkmydeals_pull_feed_event', 'linkmydeals_pull_feed');
add_action('linkmydeals_process_batch_event', 'linkmydeals_process_batch');
add_action('rest_api_init', 'linkmydeals_register_api');
if (current_user_can('manage_options')) add_action('admin_notices', 'linkmydeals_notify_troubleshootings', 9);
add_action('site_health_tab_content', 'linkmydeals_display_troubleshoot');
add_action('plugins_loaded', 'linkmydeals_update_to_1_point_4');

// Schedule an action if it's not already scheduled
if (!wp_next_scheduled('linkmydeals_check_wpcron_event')) {
	wp_schedule_event(time(), 'every_five_minutes', 'linkmydeals_check_wpcron_event');
}

register_activation_hook(__FILE__, 'linkmydeals_activate');
register_deactivation_hook(__FILE__, 'linkmydeals_deactivate');
