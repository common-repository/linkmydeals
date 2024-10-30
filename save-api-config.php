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

if (!defined('ABSPATH')) exit; // Exit if accessed directly

function linkmydeals_save_api_config()
{

	if (wp_verify_nonce($_POST['config_nonce'], 'linkmydeals')) {
		global $wpdb;
		$API_KEY       = sanitize_key($_POST['API_KEY']);

		$import_images = isset($_POST['import_images']) ? 'On' : 'Off';
		$autopilot     = isset($_POST['autopilot']) ? 'On' : 'Off';
		$cashback_mode = isset($_POST['cashback_mode']) ? 'On' : 'Off';
		$featured_image = isset($_POST['featured_image']) ? 'On' : 'Off';
		$cashback_id   = !empty($_POST['cashback_id']) ? sanitize_text_field($_POST['cashback_id']) : '';
		$batch_size    = sanitize_text_field($_POST['batch_size']);
		$batch_size    = is_numeric($batch_size) ? $batch_size : ($import_images == 'On' && get_template() == 'clipmydeals' ? '20' : '500');
		$feed_format   = sanitize_text_field($_POST['feed_format']);
		$store = sanitize_text_field($_POST['store']??'post_tag');
		$category = sanitize_text_field($_POST['category']??'category');
		$code_text = sanitize_text_field($_POST['code_text']??"(not required)");
		

		$last_extract  = $wpdb->get_var("SELECT value FROM {$wpdb->prefix}linkmydeals_config WHERE name='last_extract'");
		$last_extract  = $last_extract ? $last_extract : ($API_KEY ? sanitize_text_field(json_decode(file_get_contents("http://feed.linkmydeals.com/getUsageDetails/?API_KEY={$API_KEY}"), true)['last_extract_ts'])  : strtotime('2001-01-01 00:00:00'));

		// Validations
		if ($cashback_mode == 'On' and empty($cashback_id)) {
			$message = '<div class="notice notice-error is-dismissible"><p>Cashback Click ID required for cashback link.</p></div>';
		} else {
			if ($autopilot == 'On' and empty($API_KEY)) {
				$message = '<div class="notice notice-error is-dismissible"><p>API Key is required for Auto-Pilot.</p></div>';
			} else {

				global $wpdb;
				if (empty($feed_format)) {
					$message = '<div class="notice notice-error is-dismissible"><p>Please Select Feed Format.</p></div>';
				} else {
					$response = json_decode(file_get_contents("http://feed.linkmydeals.com/updateDefaultFormat/?API_KEY={$API_KEY}&format={$feed_format}"), true);
					if (!$response['result']) {
						$message = '<div class="notice notice-error is-dismissible"><p>' . $response['error_message'] . '</p></div>';
					} else {
						$sql = "REPLACE INTO {$wpdb->prefix}linkmydeals_config (name,value) VALUES ('autopilot','$autopilot'), ('API_KEY','$API_KEY'), ('last_extract','$last_extract'), ('batch_size','$batch_size'), ('import_images','$import_images'), ('cashback_mode','$cashback_mode'), ('cashback_id','$cashback_id') , ('store','$store'), ('category','$category') , ('code_text','$code_text') , ('featured_image','$featured_image')";
						if ($wpdb->query($sql) === false) {
							$message = '<div class="notice notice-error is-dismissible"><p>' . $wpdb->last_error . '</p></div>';
						} else {
							$message = '<div class="notice notice-success is-dismissible"><p>Settings saved successfully.</p></div>';
						}

						if ($autopilot == 'On' and !wp_next_scheduled('linkmydeals_pull_feed_event')) {
							wp_schedule_event(time(), 'hourly', 'linkmydeals_pull_feed_event');
							$message .= '<div class="notice notice-warning is-dismissible"><p><b>NOTE:</b> This plugin makes use of WordPress scheduling. WordPress does NOT have a real cron scheduler. Instead, it triggers events only when someone visits your website, after the scheduled time has passed. If you currently do not have traffic on your WordPress site, you will need to load a few pages yourself to keep the offers updated.</p></div>';
						} else if ($autopilot == 'Off' and wp_next_scheduled('linkmydeals_pull_feed_event')) {
							wp_clear_scheduled_hook('linkmydeals_pull_feed_event');
						}
					}
				}
			}
		}
	} else {
		$message = '<div class="notice notice-error is-dismissible"><p>Access Denied. Nonce could not be verified.</p></div>';
	}

	setcookie('message', $message);
	wp_redirect('admin.php?page=linkmydeals');
	exit;
}
