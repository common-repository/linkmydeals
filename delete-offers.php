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

function linkmydeals_delete_offers()
{
	global $wpdb;
	$wp_prefix = $wpdb->prefix;

	wp_defer_term_counting(true);
	$wpdb->query('SET autocommit = 0;');

	$coupons = $wpdb->get_results("SELECT post_id FROM  {$wp_prefix}postmeta WHERE meta_key = 'lmd_id' AND meta_value != '' AND meta_value IS NOT NULL");
	$count_suspended = $wpdb->num_rows;

	foreach ($coupons as $coupon) wp_delete_post($coupon->post_id, true);

	$wpdb->query("DELETE FROM {$wp_prefix}linkmydeals_upload");

	wp_defer_term_counting(false);
	$wpdb->query('COMMIT;');
	$wpdb->query('SET autocommit = 1;');

	$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'success','All Offers imported from LinkMyDeals have been dropped.')");

	return '<div class="notice notice-success is-dismissible"><p>Dropped ' . $count_suspended . ' offers.</p></div>';
}

function linkmydeals_delete_images()
{
	global $wpdb;
	$wp_prefix = $wpdb->prefix;
	$theme = get_template();
	wp_defer_term_counting(true);
	$wpdb->query('SET autocommit = 0;');
	if ($theme == 'clipmydeals') {
		$attachments = $wpdb->get_results("SELECT post_id  FROM  {$wp_prefix}postmeta WHERE meta_key = 'cmd_image_url' AND meta_value != '' AND meta_value IS NOT NULL AND post_id IN (SELECT post_id FROM  {$wp_prefix}postmeta WHERE meta_key = 'lmd_id' AND meta_value != '' AND meta_value IS NOT NULL)");
	} else {
		$attachments = $wpdb->get_results("SELECT post_id FROM  {$wp_prefix}postmeta WHERE meta_key = 'image_lmd_id'");
	}
	$count_suspended = $wpdb->num_rows;

	foreach ($attachments as $attachment) {
		if ($theme == 'clipmydeals') {
			delete_post_meta($attachment->post_id, 'cmd_image_url');
		} elseif ($theme == 'CP' or $theme == 'cp' or $theme == 'CPq' or substr($theme, 0, 2) === "CP" or str_contains(wp_get_theme()->get( 'AuthorURI' ),'premiumpress')) {
			wp_delete_attachment($attachment->post_id, true);
			delete_post_meta($attachment->post_id, 'image_array');
		} elseif ($theme == 'couponhut') {
			wp_delete_attachment($attachment->post_id, true);
			delete_post_meta($attachment->post_id, 'header_image');
			delete_post_meta($attachment->post_id, '_header_image');
			delete_post_meta($attachment->post_id, 'image');
			delete_post_meta($attachment->post_id, '_image');
		} else {
			wp_delete_attachment($attachment->post_id, true);
		}
	}

	wp_defer_term_counting(false);
	$wpdb->query('COMMIT;');
	$wpdb->query('SET autocommit = 1;');

	$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_logs (microtime,msg_type,message) VALUES (" . microtime(true) . ",'success','All Images imported from LinkMyDeals have been dropped.')");

	return '<div class="notice notice-success is-dismissible"><p>Deleted ' . $count_suspended . ' images.</p></div>';
}
