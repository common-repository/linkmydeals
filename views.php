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

function linkmydeals_display_settings()
{

	//Bootstrap CSS
	wp_register_style('bootstrap.min', plugins_url('css/bootstrap.min.css', __FILE__));
	wp_enqueue_style('bootstrap.min');
	//Custom CSS
	wp_register_style('linkmydeals_css', plugins_url('css/linkmydeals_style.css', __FILE__));
	wp_enqueue_style('linkmydeals_css');
	//Bootstrap JS
	wp_register_script('bootstrap.min', plugins_url('js/bootstrap.min.js', __FILE__), array('jquery'));
	wp_enqueue_script('bootstrap.min');

	set_time_limit(0);

	// Get Messages
	if (!empty($_COOKIE['message'])) {
		$message = wp_kses(stripslashes($_COOKIE['message']), array("div" => array("class" => array()), "p" => array())); ?>
		<script>
			document.cookie = "message=; expires=Thu, 01 Jan 1970 00:00:00 UTC;"
		</script>
	<?php // php works only before html
	}


	global $wpdb;

	// GET CONFIG DETAILS
	$result = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}linkmydeals_config");
	$config = array('autopilot' => 'Off', 'API_KEY' => '', 'last_extract' => strtotime('2001-01-01 00:00:00') - get_option('gmt_offset') * 60 * 60, 'batch_size' => 500, 'last_cron' => '', 'import_images' => 'Off', 'store'=>'post_tag','category'=>'category', 'featured_image' => 'Off');
	foreach ($result as $row) {
		$config[$row->name] = $row->value;
	}
	$theme = get_template();
	$usage = empty($config['API_KEY']) ? array() : json_decode(file_get_contents("http://feed.linkmydeals.com/getUsageDetails/?API_KEY={$config['API_KEY']}"), true);
	$allowed_formats = explode(',', $usage['allowed_formats'] ?? '');
	?>
	<div class="wrap" style="background:#F1F1F1;">

		<h2>LinkMyDeals <small>Coupon Feeds Import Plugin</small> </h2>
		<?php echo $message ?? '' ?>

		<script>
			function confirmDelete(formName) {
				var cnf = confirm(`Are you sure you want to delete all ${ formName == 'deleteOffersForm' ? 'offers' : 'images' } imported from LinkMyDeals?`);
				if (cnf == true) {
					document.getElementById(formName).submit();
				}
			}

			function confirmSync() {
				var cnf = confirm("This will drop all LinkMyDeals Offers and start pulling them again. Are you sure about this?");
				if (cnf == true) {
					document.getElementById("syncOffersForm").submit();
				}
			}

			function setRecommendedBatchSize() {
				var batch_size = document.querySelector("#batch_size")
				var batchsize_warning = document.querySelector("#batchsize_warning")
				if (event.target.checked && <?php var_export(get_template() != 'clipmydeals'); ?>) {
					batch_size.value = 20;
					batchsize_warning.classList.remove('d-none');
				} else {
					batch_size.value = 500;
					batchsize_warning.classList.add('d-none');
				}
			}

			function toggleAPIKeyRequiredAttribute() {
				var APIKey = document.querySelector("#API_KEY");
				var reqAPIKEY = document.querySelector("#API_KEY_required_asterisk");
				if (event.target.checked) {
					APIKey.setAttribute("required", "required");
					reqAPIKEY.classList.remove('d-none');
				} else {
					APIKey.removeAttribute("required");
					reqAPIKEY.classList.add('d-none');
				}
			}

			function setDefaultFormat() {
				document.querySelector('#feed_format').value = 'json';
			}

			function setCashbackVariable() {
				var showCashbackId = document.querySelector("#show_cashback_id");
				if (event.target.checked) {
					showCashbackId.classList.remove('d-none');
				} else {
					showCashbackId.classList.add('d-none');
				}
			}
		</script>

		<hr />

		<style>
			.card {
				max-width: none;
			}
		</style>
		<div class="row mb-5">

			<div class="col-12 my-0">
				<div class="card my-0 p-0 shadow" style="max-width:none; background-color:#474372; color:#dfe7f6;">
					<div class="card-body">
						<div class="d-flex justify-content-around align-items-center">
							<div class="">
								<b>Feeds Pulled Today: </b> <?php echo esc_html($usage['limit_used'] ?? '0') . ' / ' . ($usage['daily_limit'] ?? '0') ?>
							</div>

							<?php if ($config['autopilot'] == 'On') { ?>
								<div class="">
									<b>Next Pull: </b> <?php echo esc_html(date('g:i a', wp_next_scheduled('linkmydeals_pull_feed_event') + get_option('gmt_offset') * 60 * 60)) ?>
								</div>
							<?php } ?>

							<?php if (!empty($config['API_KEY']) && $usage['limit_used'] < $usage['daily_limit']) { ?>
								<form name="pullFeedForm" role="form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
									<input type="hidden" name="action" value="linkmydeals_pull_feed" />
									<?php wp_nonce_field('linkmydeals', 'pull_feed_nonce'); ?>

									<button class="btn btn-warning" type="submit" name="submit_pull_feed">Pull Feed Now <span class="dashicons dashicons-database-import"></span></button>

								</form>
							<?php } ?>

						</div>

					</div>
				</div>
			</div>

			<div class="col-md-4 my-0">
				<div class="card p-0 shadow">
					<div class="card-header" style="background-color:#a6a2d1; color:#fcfcfc;">
						<h5 class="card-title mb-0">Settings</h5>
					</div>
					<div class="card-body">
						<form class="form" name="autoPilot" role="form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')) ?>">

							<div class="mb-3">
								<label for="API_KEY" class="form-label"><b>API Key</b> <span id="API_KEY_required_asterisk" class="text-danger fw-bold <?php echo esc_attr($config['autopilot'] == 'On' ? '' : 'd-none') ?>">*</span></label>
								<input type="text" class="form-control" name="API_KEY" id="API_KEY" onchange="setDefaultFormat()" aria-describedby="API_KEY" value="<?php echo esc_attr($config['API_KEY']) ?>" <?php echo esc_attr($config['autopilot'] == 'On' ? 'required' : '') ?>>
								<?php if (empty($config['API_KEY'])) { ?>
									<div id="API_KEY" class="form-text"><small style="color:#a7a7a7;">Don't have an account? <a target="_blank" href="https://linkmydeals.com">Register Now</a>.</small></div>
								<?php } ?>
							</div>

							<div class="mb-3">
								<label for="batch_size" class="form-label"><b>Batch Size</b> <span class="dashicons dashicons-info-outline" title="Number of offers to process in a minute. Reduce this if you are facing timeout issues."></span></label>
								<input type="number" class="form-control" name="batch_size" id="batch_size" value="<?php echo esc_attr($config['batch_size']) ?>" min="1">
							</div>

							<div class="mb-3">
								<label for="feed_format" class="form-label"><b>Allowed Format</b> <span class="dashicons dashicons-info-outline" title="This are your allowed formats. Please select a format to get feed in that format."></span></label>
								<select class="form-control" style="min-width:100% !important;" name="feed_format" id="feed_format">
									<?php
									if (!in_array('json', $allowed_formats)) { ?>
										<option value="json" <?php if (empty($allowed_formats) or !in_array($usage['default_format'], $allowed_formats)) {
																	echo "selected";
																} ?>>json</option>
									<?php }
									foreach ($allowed_formats as $format) {
										if (strpos($format, 'csv') !== false or empty($format)) continue;
									?>
										<option value="<?php echo $format; ?>" <?php if ($format == $usage['default_format']) {
																					echo 'selected';
																				} ?>><?php echo $format; ?></option>
									<?php } ?>
								</select>
							</div>
							<?php
							if(!linkmydeals_is_theme_supported($theme)){
								$taxonomies = get_taxonomies(array('public' => true));

							?>
								<div class="mb-3">
									<label for="store"><b>Store</b></label>
									<select name="store" id="store" class="form-control">
										<?php
											$config['store'] = ($config['store']??'post_tag');
											foreach ($taxonomies as $taxonomy) {
												?>
												<option value="<?= $taxonomy ?>" <?= $config['store']==$taxonomy?'selected':'' ?> ><?= $taxonomy ?></option>
												<?php
											}
										?>
										<option value="none" <?= $config['store']=='none'?'selected':'' ?> >None</option>
									</select>
								</div>
								<div class="mb-3">
									<label for="category"><b>Category</b></label>
									<select name="category" id="category" class="form-control">
										<?php
										$config['category'] = ($config['category'] ?? 'category');
										foreach ($taxonomies as $taxonomy) {?>
											<option value="<?= $taxonomy ?>" <?= $config['category']==$taxonomy?'selected':'' ?> ><?= $taxonomy ?></option>
										<?php
											}
										?>
										<option value="none" <?= $config['category']=='none'?'selected':'' ?> >None</option>
									</select>
								</div>
								<div class="mb-3">
									<div class="form-group">
										<label for="code_text"><b>Default Code Text</b></label>
										<input type="text" class="form-control col-10" id='code_text' name="code_text" value="<?= $config['code_text']??'(not required)' ?>">
									</div>
								</div>

							<?php
								}
							?>
							<div class="mb-3">
								<div class="form-check">
									<input class="form-check-input" type="checkbox" id="auto-pilot" onchange="toggleAPIKeyRequiredAttribute()" <?php echo esc_attr($config['autopilot'] == 'On' ? 'checked' : '') ?> name="autopilot">
									<label class="form-check-label" for=""><b>Auto-Pilot Mode</b></label>
								</div>
							</div>

							<div class="mb-3">
								<div class="form-check">
									<input class="form-check-input" type="checkbox" id="import_images" onchange="setRecommendedBatchSize()" <?php echo esc_attr($config['import_images'] == 'On' ? 'checked' : '') ?> name="import_images">
									<label class="form-check-label" for=""><b>Import Offer Images</b> <span class="dashicons dashicons-info-outline" title="An image will be associated with a coupon only if it is available at the source or uploaded by the merchant. Also, only images that fit the supported file format and have a ratio between 3:2 and 4:1 will be uploaded to your WordPress website."></span></label>
								</div>
							</div>

							<?php  if(!linkmydeals_is_theme_supported($theme)) {?>
								<div class="mb-3 <?php echo $config['import_images'] == 'On' ? 'd-block' : 'd-none' ?>" id='featured_image_box' >
									<div class="form-check">
										<input class="form-check-input" type="checkbox" id="featured_image" <?php echo esc_attr($config['featured_image'] == 'On' ? 'checked' : '') ?> name="featured_image">
										<label class="form-check-label" for=""><b>Set Image As Featured Image</b> <span class="dashicons dashicons-info-outline" title="An image will be associated with a coupon only if it is available at the source or uploaded by the merchant. Also, only images that fit the supported file format and have a ratio between 3:2 and 4:1 will be uploaded to your WordPress website."></span></label>
									</div>
								</div>
								<script>
							        const importImagesCheckbox = document.getElementById('import_images');
        							const featuredImageBox = document.getElementById('featured_image_box');
	
        							importImagesCheckbox.addEventListener('change', function () {
        							    if (importImagesCheckbox.checked) {
        							        featuredImageBox.classList.remove('d-none');
        							        featuredImageBox.classList.add('d-block');
        							    } else {
        							        featuredImageBox.classList.remove('d-block');
        							        featuredImageBox.classList.add('d-none');
        							    }
        							});
								</script>
							<?php } ?>

							<div class="mb-3">
								<div class="form-check">
									<input class="form-check-input" type="checkbox" id="cashback_mode" onchange="setCashbackVariable()" <?php echo esc_attr($config['cashback_mode'] == 'On' ? 'checked' : '') ?> name="cashback_mode">
									<label class="form-check-label" for="cashback_mode"><b>Cashback Mode</b> </label>
								</div>
							</div>

							<div class="mb-3 <?php echo esc_attr($config['cashback_mode'] == 'On' ? '' : 'd-none') ?>" id="show_cashback_id">
								<label for="cashback_id" class="form-label"><b>Cashback Click ID</b> </label>
								<input type="text" class="form-control" name="cashback_id" id="cashback_id" value="<?php echo esc_attr(!empty($config['cashback_id']) ? $config['cashback_id'] : '[click_id]') ?>">
							</div>

							<div class="mb-3 d-grid gap-2">
								<button class="btn btn-primary" type="submit" name="submit_config" style="border-color: #474372; background-color: #474372;">Save <span class="dashicons dashicons-arrow-right" style="margin-top:2px;"></span></button>
							</div>

							<?php wp_nonce_field('linkmydeals', 'config_nonce'); ?>
							<input type="hidden" name="action" value="linkmydeals_save_api_config" />

						</form>
					</div>

					<div id="batchsize_warning" class="card-footer bg-warning <?php echo esc_attr(($config['import_images'] == 'On' && get_template() != 'clipmydeals') && $config['batch_size'] > 25 ? '' : 'd-none') ?>">
						<p class="m-0"><b><u>Warning:</u></b> Your theme does not support third-party hosted images. So LinkMyDeals will have to upload offer images to your server, which takes time and bandwidth. We recommend you set a lower batch size (15~20 offers per minute) if you are enabline image import. If you wish to have a higher upload speed (Batch Size 100 or above) we strictly recommend turning off image import, as it may cause timeout issues, especially on shared hostings.</p>
					</div>

				</div>

			</div>


			<div class="col-md-4 my-0">

				<div class="card p-0 shadow">

					<div class="card-header" style="background-color:#a6a2d1; color:#fcfcfc;">
						<h5 class="card-title mb-0">CSV Import</h5>
					</div>
					<div class="card-body">
						<form name="bulkUpload" class="form-inline" role="form" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
							<input class="col-8" type="hidden" name="action" value="linkmydeals_file_upload" />
							<?php wp_nonce_field('linkmydeals', 'file_upload_nonce'); ?>
							<div class="d-grid gap-2">
								<label for="feed" class="form-label"><b> CSV </b></label>
								<input type="file" class="form-control" id="feed" name="feed" />
								<button class="btn btn-primary" type="submit" name="submit_upload_feed" style="border-color: #474372; background-color: #474372;">Upload <span class="dashicons dashicons-upload"></span></button>
							</div>
						</form>
					</div>
					<div class="card-footer">
						<small><i>NOTE: If you are using a shared-server, your server may time-out in case of large files. We recommend you split such files into multiple files of ~500 coupons each. Advance plan users can make use of our <a href="https://myaccount.linkmydeals.com/affiliate-tools/csv-splitter" target="_blank">CSV Splitter tool</a>.</i></small>
					</div>

				</div>
			</div>

			<div class="col-md-4 my-0">

				<div class="card p-0 shadow">

					<div class="card-header" style="background-color:#a6a2d1; color:#fcfcfc;">
						<h5 class="card-title mb-0">Toolbox</h5>
					</div>
					<div class="card-body">
						<div class="row">
							<form name="syncOffersForm" class="col-md-12" id="syncOffersForm" role="form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
								<input type="hidden" name="action" value="linkmydeals_sync_offers" />
								<?php wp_nonce_field('linkmydeals', 'sync_offers_nonce'); ?>
								<div class="mb-4 d-grid gap-2">
									<button class="btn btn-warning btn-block" type="button" name="button_sync_offers" onclick="confirmSync();">Drop & Resync Offers <span class="dashicons dashicons-update"></span></button>
								</div>
							</form>
							<?php if ($theme != 'clipper' && $theme != 'coupner') { ?>
								<form name="deleteImagesForm" class="col-md-12" id="deleteImagesForm" role="form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
									<input type="hidden" name="action" value="linkmydeals_delete_images" />
									<?php wp_nonce_field('linkmydeals', 'delete_images_nonce'); ?>
									<div class="mb-4 d-grid gap-2">
										<button class="btn btn-outline-danger btn-block" type="button" name="button_delete_images" onclick="confirmDelete('deleteImagesForm');">Delete Offer Images <span class="dashicons dashicons-table-col-delete"></span></button>
									</div>
								</form>
							<?php } ?>
							<form name="deleteOffersForm" class="col-md-12" id="deleteOffersForm" role="form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
								<input type="hidden" name="action" value="linkmydeals_delete_offers" />
								<?php wp_nonce_field('linkmydeals', 'delete_offers_nonce'); ?>
								<div class="d-grid gap-2">
									<button class="btn btn-danger btn-block" type="button" name="button_delete_offers" onclick="confirmDelete('deleteOffersForm');">Drop LinkMyDeals Offers <span class="dashicons dashicons-remove"></span></button>
								</div>
							</form>
						</div>

					</div>

				</div>

			</div>

			<div class="col-md-12 mt-3">
				<div class="alert alert-warning alert-dismissible fade show" role="alert">
					<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
					<p>
						<b><u>Warning</u> : If ever you wish to discontinue your LinkMyDeals' subscription, we request you to please update your Website/App and replace all SmartLinks with your own Affiliate Links.</b>
						</br>
						SmartLinks is a paid feature. If you continue using SmartLinks even after your account has expired, LinkMyDeals reserves the right to redirect your SmartLinks via our own Affiliate IDs as a compensation for engaging our servers and other resources.
					</p>
				</div>
			</div>

		</div>

	</div>
	<?php
}

function linkmydeals_display_logs()
{
	//Bootstrap CSS
	wp_register_style('bootstrap.min', plugins_url('css/bootstrap.min.css', __FILE__));
	wp_enqueue_style('bootstrap.min');
	//Custom CSS
	wp_register_style('linkmydeals_css', plugins_url('css/linkmydeals_style.css', __FILE__));
	wp_enqueue_style('linkmydeals_css');
	//Bootstrap JS
	wp_register_script('bootstrap.min', plugins_url('js/bootstrap.min.js', __FILE__), array('jquery'));
	wp_enqueue_script('bootstrap.min');

	set_time_limit(0);

	// Get Messages
	if (!empty($_COOKIE['message'])) {
		$message = wp_kses(stripslashes($_COOKIE['message']), array("div" => array("class" => array()), "p" => array())); ?>
		<script>
			document.cookie = "message=; expires=Thu, 01 Jan 1970 00:00:00 UTC;"
		</script>
	<?php // php works only before html
	}

	global $wpdb;

	// Get Logs
	$log_duration = isset($_POST['log_duration']) ? sanitize_text_field($_POST['log_duration']) : '';
	$log_duration = in_array($log_duration, array('1 HOUR', '1 DAY', '1 WEEK',)) ? $log_duration : '1 HOUR';

	$log_debug = !isset($_POST['log_debug']) ? "msg_type != 'debug'" : "TRUE";

	$gmt_offset = get_option('gmt_offset');
	$offset_sign = ($gmt_offset < 0) ? '-' : '+';
	$positive_offset = ($gmt_offset < 0) ? $gmt_offset * -1 : $gmt_offset;
	$hours = floor($positive_offset);
	$minutes = round(($positive_offset - $hours) * 60);
	$tz = "$offset_sign$hours:$minutes";

	$logs = $wpdb->get_results("SELECT
								CONVERT_TZ(logtime,@@session.time_zone,'$tz') logtime,
								msg_type,
								message,
								CASE
									WHEN msg_type = 'success' THEN 'green'
									WHEN msg_type = 'error' THEN 'red'
									WHEN msg_type = 'debug' THEN '#4a92bf'
								END as color
							FROM  {$wpdb->prefix}linkmydeals_logs
							WHERE logtime > NOW() - INTERVAL $log_duration
								AND $log_debug
							ORDER BY microtime");

	?>
	<div class="wrap" style="background:#F1F1F1;">

		<h2>LinkMyDeals <small>- Logs</small> </h2>

		<?php echo $message ?? '' ?>

		<hr />
		<style>
			.card {
				max-width: none;
			}
		</style>
		<div class="card p-0">
			<div class="card-header" style="background-color:#474372; color:#dfe7f6;">
				<form name="refreshLogs" role="form" class="w-100" method="post" action="<?php echo esc_url(str_replace('&tab=', '&oldtab=', str_replace('%7E', '~', $_SERVER['REQUEST_URI']))) ?>&tab=logs">
					<div class="d-flex justify-content-between flex-wrap">

						<div class="p-0 d-flex justify-content-between col-12 col-lg-8 col-xl-6">
							<div class="my-md-0">
								<button class="btn btn-warning btn-sm" type="submit" name="submit_fetch_logs">Refresh <span class="dashicons dashicons-update"></span></button>
							</div>
							<div class="my-md-0 mx-4">
								<label>
									<small>Duration</small>
									<select name="log_duration">
										<option value="1 HOUR" <?php echo esc_attr($log_duration == '1 HOUR' ? 'selected' : ''); ?>>1 Hour</option>
										<option value="1 DAY" <?php echo esc_attr($log_duration == '1 DAY' ? 'selected' : ''); ?>>24 Hours</option>
										<option value="1 WEEK" <?php echo esc_attr($log_duration == '1 WEEK' ? 'selected' : ''); ?>>This Week</option>
									</select>
								</label>
							</div>
							<div class="my-md-0 form-check">
								<label class="form-check-label">
									<input class="form-check-input" name="log_debug" type="checkbox" <?php echo esc_attr(isset($_POST['log_debug'])) ? 'checked' : ''; ?>>
									<small>Show Debug Logs</small>
								</label>
							</div>
						</div>

						<div class="my-md-0 col-12 col-lg-auto p-0">
							<a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=linkmydeals_download_logs'), 'linkmydeals', 'log_nonce')) ?>" class="btn btn-sm btn-outline-light float-end">Download Logs <span class="dashicons dashicons-download"></span></a>
						</div>
					</div>

				</form>
			</div>

			<div class="card-body">
				<?php if (sizeof($logs) >= 1) { ?>
					<table>
						<tr>
							<th style="white-space:nowrap;">Time</th>
							<th style="padding-left:20px;">Message</th>
						</tr>
						<?php foreach ($logs as $log) { ?>
							<tr style="font-size:0.85em;">
								<td><?php echo esc_html($log->logtime) ?></td>
								<td style="padding-left:20px;color:<?php echo esc_attr($log->color) ?>;"><?php echo esc_html($log->message) ?></td>
							</tr>
						<?php } ?>
					</table>
				<?php } else { ?>
					<i>No Data to display</i>
				<?php } ?>
			</div>
		</div>

	</div>
<?php
}


function linkmydeals_custom_template(){

		//Bootstrap CSS
		wp_register_style('bootstrap.min', plugins_url('css/bootstrap.min.css', __FILE__));
		wp_enqueue_style('bootstrap.min');
		//Custom CSS
		wp_register_style('linkmydeals_css', plugins_url('css/linkmydeals_style.css', __FILE__));
		wp_enqueue_style('linkmydeals_css');
		//Bootstrap JS
		wp_register_script('bootstrap.min', plugins_url('js/bootstrap.min.js', __FILE__), array('jquery'));
		wp_enqueue_script('bootstrap.min');
		
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
		{{description}}
		';
	?>
	<div class="wrap" style="background:#F1F1F1;">
		<h2>Custom HTML Template</h2>
		
		<hr>
		<div class="row">
			<div class="card p-0 mt-0 col-10">
			<div class="card-header card-header d-flex bg-dark text-white">Custom HTML Template For Coupons </div>
				<form role="form" method="post" action="<?= admin_url('admin-post.php'); ?>">
					<div class="card-body row">
						<div class="col-10">
						<?php
							$settings = array( 'textarea_name' => 'linkmydeals_custom_coupon_template' ); 
							wp_editor( stripslashes(get_theme_mod('linkmydeals_custom_coupon_template', $default_template)), 'linkmydeals_custom_coupon_template' , $settings );
						?>
						</div>
						<div class="col-2 position-relative">
						<div class="linkmydeals_variables btn btn-sm text-light mt-1" style="border-color: #474372; background-color: #474372;">{{description}}</div><br>
						<div class="linkmydeals_variables btn btn-sm text-light mt-1" style="border-color: #474372; background-color: #474372;">{{link}}</div><br>
						<div class="linkmydeals_variables btn btn-sm text-light mt-1" style="border-color: #474372; background-color: #474372;">{{label}}</div><br>
						<div class="linkmydeals_variables btn btn-sm text-light mt-1" style="border-color: #474372; background-color: #474372;">{{store}}</div><br>
						<div class="linkmydeals_variables btn btn-sm text-light mt-1" style="border-color: #474372; background-color: #474372;">{{code}}</div><br>
						<div class="linkmydeals_variables btn btn-sm text-light mt-1" style="border-color: #474372; background-color: #474372;">{{start_date}}</div><br>
						<div class="linkmydeals_variables btn btn-sm text-light mt-1" style="border-color: #474372; background-color: #474372;">{{expiry}}</div><br>
						<div class="linkmydeals_variables btn btn-sm text-light mt-1" style="border-color: #474372; background-color: #474372;">{{image}}</div><br>
						<div class="linkmydeals_variables btn btn-sm text-light mt-1 align-text-bottom" style="border-color: #474372; background-color: #474372;">{{image_url}}</div><br>
	
						<div class="btn btn-sm text-light mt-1 align-text-bottom btn-secondary position-absolute fixed-bottom w-75" onclick='reset_template()'>Reset Template</div>
	
						</div>
	
						<div class="col-10 d-flex justify-content-center  pt-2">
								<?php wp_nonce_field('linkmydeals', 'custom_template_nonce'); ?>
								<input type="hidden" name="action" value="lmd_custom_template" />
								<button class="btn btn-primary btn-block" style="border-color: #474372; background-color: #474372; width:fit-content;" type="submit" name="submit_feed_config">Save<span class="dashicons dashicons-arrow-right" style="margin-top:2px;"></span></button>
						</div>
					</div>
				</form>
				
				<div class="card-footer bg-dark text-light">
					<h4>Instructions</h4>
					<ol>
						<li>This HTML Template will be used by plugin to replace the coupon's design while importing</li>
						<li>You can make your coupons more informative by using the following placeholders: {{description}}, {{link}}, {{label}}, {{store}}, {{code}}, {{start_date}}, {{expiry}}, {{image}}, and {{image_url}}. These placeholders allow you to include details such as the coupon's description, affiliate link, label, store name, coupon code (if applicable), start date, end date, image, and image link, respectively.
						By arranging these variables, you can easily manage the coupon information as per your requirements.</li>
						<li>You can add html and style around the variables</li>
					</ol>
				</div>
			</div>
		</div>
	
	</div>
	
	<script>
	
		function reset_template(){
			default_template = `<?= $default_template ?>`
			document.getElementById('linkmydeals_custom_coupon_template').value = default_template 
			tinyMCE.get('linkmydeals_custom_coupon_template').setContent(default_template);
	
		}
		
		document.querySelectorAll(".linkmydeals_variables").forEach(ele => 
		  ele.addEventListener("click", () => {
			var editor = tinymce.get('linkmydeals_custom_coupon_template');
			variable = ele.innerHTML
			if (editor) {
			  editor.execCommand('mceInsertContent', false, variable);
			} 
				inputField = document.getElementById('linkmydeals_custom_coupon_template')
				const start = inputField.selectionStart;
				const end = inputField.selectionEnd;
				const currentValue = inputField.value;
				const newValue = currentValue.slice(0, start) + variable + currentValue.slice(end);
				inputField.value = newValue;
				const newCursorPosition = start + variable.length;
				inputField.setSelectionRange(newCursorPosition, newCursorPosition);
				inputField.focus()
			}
	
		)
		)
		
	</script>
<?php
}


function linkmydeals_display_troubleshoot($tab)
{
	// Do nothing if this is not our tab.
	if ('linkmydeals-troubleshooting' !== $tab)	return;

	//Bootstrap CSS
	wp_register_style('bootstrap.min', plugins_url('css/bootstrap.min.css', __FILE__));
	wp_enqueue_style('bootstrap.min');
	//Custom CSS
	wp_register_style('linkmydeals_css', plugins_url('css/linkmydeals_style.css', __FILE__));
	wp_enqueue_style('linkmydeals_css');

	set_time_limit(0);

	$troubleshooting = linkmydeals_get_troubleshootings();

?>
	<div class="health-check-body health-check-debug-tab hide-if-no-js">

		<h2><?php esc_html_e('LinkMyDeals Troubleshoot'); ?></h2>

		<p><?php esc_html_e('This page shows you every details about the configuration for Linkmydeals plugin.'); ?></p>

		<p><?php esc_html_e('If you want to export a handy list of all the information on this page, you can use the button below to copy it to the clipboard. You can then paste it in a text file and save it to your device, or paste it in an email exchange with a support engineer or theme/plugin developer for example.'); ?></p>

		<div class="site-health-copy-buttons">
			<div class="copy-button-wrapper">
				<button type="button" class="button copy-button" data-clipboard-text='<?php echo esc_attr(strip_tags(json_encode($troubleshooting, JSON_PRETTY_PRINT))) ?>'>
					<?php esc_html_e('Copy site info to clipboard'); ?>
				</button>
				<span class="success hidden" aria-hidden="true"><?php esc_html_e('Copied!'); ?></span>
			</div>
		</div>

		<div id="health-check-debug" class="health-check-accordion">
			<?php foreach ($troubleshooting as $section => $details) {
				$section_filtered =  strtolower(str_replace(' ', '-', esc_attr($section))); ?>

				<h3 class="health-check-accordion-heading">
					<button aria-expanded="false" class="health-check-accordion-trigger" aria-controls="linkmydeals-<?php echo esc_attr($section_filtered) ?>" type="button">
						<span class="linkmydeals_troubleshoot me-5 mr-5 dashicons dashicons-<?php echo esc_attr($details['status']) ?>"></span>
						<span class="title"> <?php echo esc_html($section) ?> </span>
						<span class="icon"></span>
					</button>
				</h3>

				<div id="linkmydeals-<?php echo esc_attr($section_filtered) ?>" class="health-check-accordion-panel" hidden="hidden">
					<p><?php echo wp_kses($details['message'], array("br" => array(), "strong" => array(), "samp" => array(), "code" => array(), "a" => array("target" => "_blank", "href" => array()))) ?></p>
				</div>

			<?php } ?>
		</div>

	</div>
<?php
}

?>