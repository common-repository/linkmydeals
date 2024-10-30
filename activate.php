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

function linkmydeals_activate()
{
	global $wpdb;
	$wp_prefix = $wpdb->prefix;

	// DROP TABLES IF ALREADY PRESENT
	$wpdb->query("DROP TABLE IF EXISTS {$wp_prefix}linkmydeals_logs, {$wp_prefix}linkmydeals_config, {$wp_prefix}linkmydeals_upload");

	// CREATE LOG TABLE
	$result = $wpdb->get_row("SHOW VARIABLES LIKE 'sql_require_primary_key'");
	$wpdb->query("SET sql_require_primary_key=0");
	$wpdb->query(
		"CREATE TABLE IF NOT EXISTS {$wp_prefix}linkmydeals_logs (
			logtime timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			microtime DECIMAL(20,6) NOT NULL DEFAULT '0',
			msg_type VARCHAR( 10 ) NOT NULL,
			message text NOT NULL,
			duration DECIMAL(20,6) NOT NULL DEFAULT '0'
		)"
	);

	// CREATE CONFIG TABLE
	$wpdb->query(
		"CREATE TABLE IF NOT EXISTS {$wp_prefix}linkmydeals_config (
			name varchar(50) NOT NULL,
			value text NOT NULL,
			UNIQUE  (name)
		)"
	);

	// CREATE UPLOAD TABLE
	$wpdb->query(
		"CREATE TABLE IF NOT EXISTS {$wp_prefix}linkmydeals_upload (
			lmd_id int(11) NOT NULL,
			status varchar(15) NOT NULL,
			title text NOT NULL,
			description text NOT NULL,
			excerpt text NOT NULL,
			badge text NOT NULL,
			type VARCHAR(5) NOT NULL,
			code varchar(50) NOT NULL,
			categories text NOT NULL,
			store varchar(50) NOT NULL,
			homepage_url text NOT NULL,
			url text NOT NULL,
			image_url text NOT NULL,
			terms_and_conditions text NOT NULL,
			start_date DATE NOT NULL DEFAULT '0000-00-00',
			end_date DATE NOT NULL DEFAULT '0000-00-00',
			featured varchar(10) NOT NULL,
			upload_date timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
		)"
	);
	$wpdb->query("SET sql_require_primary_key={$result->Value}");

	// TODO: Remove in later versions
	$res = $wpdb->get_results("SHOW TABLES FROM `{$wpdb->dbname}` LIKE '{$wp_prefix}lmd_config'");
	if(count($res) != 0) { // lmd_config table exist
		$wpdb->query("INSERT INTO {$wp_prefix}linkmydeals_config SELECT * FROM {$wp_prefix}lmd_config");
	}
	$wpdb->query("DROP TABLE IF EXISTS {$wp_prefix}lmd_logs, {$wp_prefix}lmd_config, {$wp_prefix}lmd_upload");
}

function linkmydeals_update_to_1_point_4() {
	global $wpdb;
	$wp_prefix = $wpdb->prefix;
	$res = $wpdb->get_results("SHOW COLUMNS FROM ".$wp_prefix."linkmydeals_upload WHERE Field='locations'");
	if(count($res)==0) { // locations column does not exist
		$sql = "ALTER TABLE ".$wp_prefix."linkmydeals_upload ADD COLUMN locations text NOT NULL AFTER categories";
		$wpdb->query($sql);
	}
}