<?php
/*
Plugin Name: Recmnd
Plugin URI: http://www.recmnd.com/
Description: This plugin allows you to automate the entire communication with the Recmnd API. It performs the following tasks automatically for you: submits your newly published items to your Recmnd account; synchronizes all updates you perform at your blog with the data at your Recmnd account; removes the deleted articles from your blog, from your Recmnd account as well. (Recmnd: <a href="https://www.recmnd.com/account/login/">Login</a> | <a href="https://www.recmnd.com/signup/">Sign-up</a> | <a href="https://www.recmnd.com/signup/free-trial/">Request for a demo</a>)
Author: Neural Brothers <hello@neuralbrothers.com>
Version: 1.2
Author URI: http://neuralbrothers.com/
*/

// localization (recmnd.LOCALE.mo)
$plugin_dir = basename(dirname(__FILE__).'/locale');
load_plugin_textdomain('recmnd', null, $plugin_dir);

require_once 'functions.php';

/**
 * Register the configuration page in wordpress admin menu 
 */
function recmnd_admin_menu() {
	global $recmnd_api_host, $recmnd_api_key, $recmnd_api_secret, $recmnd_created;

	add_submenu_page('plugins.php', __('Recmnd Configuration'), __('Recmnd'), 'manage_options', 'recmnd-options', 'recmnd_options');
}

function recmnd_msg_almost_done(){
	echo recmnd_message("<strong>".__('Recmnd configuration is almost complete.')."</strong> ".sprintf(__('You only need to <a href="%1$s">enter your API key</a> now.'), "plugins.php?page=recmnd-options"));
}

/**
 * Create post in recmnd
 * @param array $post post record
 */
function recmnd_api_insert($post) {
	recmnd_log("api insert({$post->ID})");
	global $recmnd_api_host, $recmnd_api_key, $recmnd_api_secret, $recmnd_created;
	if (!($recmnd_api_key && $recmnd_api_secret && $recmnd_api_host)) {
		// not configured
		return;
	}

	$userdata = get_userdata($post->post_author);
	$categories = array();
	foreach ((get_the_category($post->ID)) as $category){
		$categories[] = $category->cat_name;
	}
	$category_list = count($categories)?implode($categories,', '):'';

	$content = $post->post_content;
	$content = recmnd_apply_filters('the_content', $content);
	$content = str_replace(']]>', ']]&gt;', $content);

	$excerpt = $post->post_excerpt;
	$excerpt = recmnd_apply_filters('the_excerpt', $excerpt);
	$excerpt = str_replace(']]>', ']]&gt;', $excerpt);

	$request = array (
		'api_key' => $recmnd_api_key,
		'category' => $category_list,
		'postedBy' => $userdata->user_login,
		'dateposted' => date('d/m/Y H:i:s', strtotime($post->post_date)),
		'dateparsed' => date('d/m/Y H:i:s', strtotime($post->post_date)),
		'title' => $post->post_title,
		'description' => $excerpt,
		'body' => $content,
		'url' =>  get_permalink($post->ID),
		'item_type' => 'blogpost'
	);
	$result = recmnd_http_post($recmnd_api_host,'/api/record/create/', $request);
	if (in_array($result['status'], array(200, 201))) {
		update_post_meta($post->ID, 'recmnd_status', 1);
		recmnd_log("set recmnd status: 1");
	} else {
		recmnd_log("failed, recmnd status not changed: got {$result['status']}");
	}
	return $result;
}

/**
 * Update post in recmnd
 * @param array $post post record
 */
function recmnd_api_update($post) {
	recmnd_log("api update({$post->ID})");
	global $recmnd_api_host, $recmnd_api_key, $recmnd_api_secret, $recmnd_old_post;
	if (!($recmnd_api_key && $recmnd_api_secret && $recmnd_api_host)) {
		// not configured
		return;
	}
	
	$userdata = get_userdata($post->post_author);
	$categories = array();
	foreach ((get_the_category($post->ID)) as $category){
		$categories[] = $category->cat_name;
	}
	$category_list = count($categories)?implode($categories,', '):'';
	
	$content = $post->post_content;
	$content = recmnd_apply_filters('the_content', $content);
	$content = str_replace(']]>', ']]&gt;', $content);

	$excerpt = $post->post_excerpt;
	$excerpt = recmnd_apply_filters('the_excerpt', $excerpt);
	$excerpt = str_replace(']]>', ']]&gt;', $excerpt);

	$request = array (
		'api_key' => $recmnd_api_key,
		'new_category' => $category_list,
		'new_postedBy' => $userdata->user_login,
		'new_dateposted' => date('d/m/Y H:i:s', strtotime($post->post_date)),
		'new_dateparsed' => date('d/m/Y H:i:s', strtotime($post->post_date)),
		'new_title' => $post->post_title,
		'new_description' => $excerpt,
		'new_body' => $content,
		'new_url' => get_permalink($post->ID),
		'new_item_type' => 'blogpost',
		'old_url' => $recmnd_old_post->permalink
	);
	return recmnd_http_post($recmnd_api_host,'/api/record/update/', $request);
}

/**
 * Insert or update post in recmnd
 * @param array $post post record
 */
function recmnd_api_upsert($post) {
	recmnd_log("api upsert({$post->ID})");
	$result = recmnd_api_update($post);
	if ($result && ($result['status'] == 406)) {
		// post not found, insert
		$result = recmnd_api_insert($post);
	} else {
		update_post_meta($post->ID, 'recmnd_status', 1);
		recmnd_log("set recmnd status: 1");
	}
	return $result;
}

/**
 * Delete post from recmnd
 * @param array $post post record
 */
function recmnd_api_delete($post){
	recmnd_log("api delete({$post->ID})");
	global $recmnd_api_host, $recmnd_api_key, $recmnd_api_secret;
	if (!($recmnd_api_key && $recmnd_api_secret && $recmnd_api_host)) {
		// not configured
		return;
	}
	$recmnd_status = get_post_meta($post->ID, 'recmnd_status', true);
	recmnd_log("recmnd status: ".json_encode($recmnd_status));
	if ($recmnd_status == '0') {
		// article not in recmnd, skip
		return;
	}

	$url = get_permalink($post->ID);
	if (!$url)
		return;
	$request = array (
		'api_key' => $recmnd_api_key,
		'url' =>  $url
	);
	$result = recmnd_http_post($recmnd_api_host, '/api/record/delete/', $request);
	recmnd_log("set recmnd status: 0");
	update_post_meta($post->ID, 'recmnd_status', 0);
}

/**
 * Delete post from the recommendations database
 * @param int $post_ID id of the post
 */
function recmnd_delete_single($post_ID){
	recmnd_log("delete single($post_ID)");
	global $recmnd_api_host, $recmnd_api_key, $recmnd_api_secret;
	if (!($recmnd_api_key && $recmnd_api_secret && $recmnd_api_host)) {
		// not configured
		return;
	}
	$post = get_post($post_ID, 'OBJECT');
	if (($post->post_type != 'post') || ($post->post_status != 'publish')) {
		// not a post or is not published
		return;
	}
	return recmnd_api_delete($post);
}

/**
 * Get data for the post being updated, BEFORE update
 * @param int $post_ID id of the post
 */
function recmnd_pre_update_single($post_ID){
	recmnd_log("pre update single($post_ID)");
	global $recmnd_api_key, $recmnd_api_secret, $recmnd_api_host, $recmnd_old_post;
	if (!($recmnd_api_key && $recmnd_api_secret && $recmnd_api_host)) {
		// not configured
		return;
	}
	$recmnd_old_post = get_post($post_ID, 'OBJECT');
	$recmnd_old_post->permalink = get_permalink($post_ID);
	// $recmnd_old_url = get_permalink($post_ID);
	// $recmnd_old_status = $post->post_status;
	// $recmnd_old_body = $post->post_content;
}

/**
 * Handle post update
 * @param int $post_ID id of the post
 */
function recmnd_update_single($post_ID){
	global $recmnd_api_key, $recmnd_api_secret, $recmnd_api_host, $recmnd_old_post;
	if (!($recmnd_api_key && $recmnd_api_secret && $recmnd_api_host)) {
		// not configured
		return;
	}
	$post = get_post($post_ID, 'OBJECT');
	if ($post->post_type != 'post') {
		// not a post
		return;
	}
	$changed = empty($recmnd_old_post)
		|| ($post->post_title != $recmnd_old_post->post_title)
		|| ($post->post_excerpt != $recmnd_old_post->post_excerpt)
		|| ($post->post_content != $recmnd_old_post->post_content)
		|| (get_permalink($post_ID) != $recmnd_old_post->permalink);
	recmnd_log("post update single($post_ID): ".(empty($recmnd_old_post)? '(none)': $recmnd_old_post->post_status)." -> ".$post->post_status.($changed? "; changed": ""));

	if ((@$recmnd_old_post->post_status == 'auto-draft') && ($post->post_status != 'auto-draft')) {
		// first save of a draft (happens automatically even when publishing a draft directly)
		recmnd_log("initial draft -> set recmnd status: 0");
		update_post_meta($post_ID, 'recmnd_status', 0);
	}
	if (($post->post_status != 'publish') && (@$recmnd_old_post->post_status == 'publish')) {
		// newly unpublished post
		recmnd_api_delete($post);
		return;
	}
	if (($post->post_status == 'publish') && ((@$recmnd_old_post->post_status != 'publish') || $changed)) {
		// newly published post, or content has changed
		$recmnd_status = get_post_meta($post_ID, 'recmnd_status', true);
		recmnd_log("recmnd status: ".json_encode($recmnd_status));
		switch ($recmnd_status) {
			case '0':
				recmnd_api_insert($post);
				break;
			case '1':
				recmnd_api_update($post);
				break;
			default: // legacy case
				recmnd_api_upsert($post);
				break;
		}
	}
}

/**
 * Generate the widget body
 */
function recmnd_widget_content($returnType = null, $resultType = null) {
	if (!is_single())
		// not in a single post page
		return '';
	if (empty($returnType))
		$returnType = 'blogpost:news:analysis:interview';
	if (empty($resultType))
		$resultType = 'combined';
	global $recmnd_api_key, $recmnd_api_secret, $recmnd_api_host;
	if (!($recmnd_api_key && $recmnd_api_secret && $recmnd_api_host))
		return '';
	$proto = preg_match('/^http:\/\//', $recmnd_api_host)?'':'http://';
	ob_start();
?>
<!-- Start recmnd -->
<script>
	Recmnd.init(<?php echo recmnd_js_string($proto.$recmnd_api_host.'/api/') ?>, <?php echo recmnd_js_string($recmnd_api_key) ?>, <?php echo recmnd_js_string(get_permalink()) ?>);
	Recmnd.showRecommendations( { returnType: <?php echo recmnd_js_string($returnType) ?>, resultType: <?php echo recmnd_js_string($resultType) ?> } );
</script>
<!-- End recmnd -->
<?php
	$widget = ob_get_clean();
	return $widget;
}

/**
 * Display the widget body
 */

function recmnd_widget($returnType = null, $resultType = null) {
	global $recmnd_auto_append;
	if (!$recmnd_auto_append)
		print recmnd_widget_content($returnType, $resultType);
}
function recmnd_body() {
	recmnd_widget();
}

/**
 * Auto-append widget to end of post content
 */
function recmnd_auto_append($content) {
	global $recmnd_auto_append;
	if ($recmnd_auto_append)
		// auto-append is enabled, add content
		$content .= recmnd_widget_content();
	return $content;
}

/**
 * Update options
 */
function recmnd_update_options(){
	global $recmnd_api_key, $recmnd_api_secret, $recmnd_api_host, $recmnd_auto_append;

	if (!current_user_can('manage_options')){
		wp_die( __('You do not have sufficient permissions to access this page.', 'recmnd') );
	}
	
	$initialized = strlen($recmnd_api_key) && strlen($recmnd_api_secret) && strlen($recmnd_api_host);
	
	// update API key
	if(isset($_POST['recmnd_api_key'])) {
		$recmnd_api_key = $_POST['recmnd_api_key'];
		if ($_POST['recmnd_api_key']==''){
			delete_option('recmnd_api_key');
		} else {
			update_option('recmnd_api_key', $recmnd_api_key );		
		}
	}
	
	// update API secret
	if(isset($_POST['recmnd_api_secret'])) {
		$recmnd_api_secret = $_POST['recmnd_api_secret'];
		if ($_POST['recmnd_api_secret']==''){
			delete_option('recmnd_api_secret');
		} else {
			update_option('recmnd_api_secret', $recmnd_api_secret );
		}
	}
	
	// update API Host
	if(isset($_POST['recmnd_api_host'])) {
		$recmnd_api_host = $_POST['recmnd_api_host'];
		if ($_POST['recmnd_api_host']==''){
			delete_option('recmnd_api_host');
		} else {
			update_option('recmnd_api_host', $recmnd_api_host );		
		}
	}
	
	// update auto-append widget flag
	if (isset($_POST['recmnd_auto_append'])) {
		$recmnd_auto_append = $_POST['recmnd_auto_append'];
		update_option('recmnd_auto_append', $recmnd_auto_append);
	}
	

	if (!$initialized && strlen($recmnd_api_key) && strlen($recmnd_api_secret) && strlen($recmnd_api_host) && current_user_can('export')) {
		// transition from not initialized to initialized state
		echo recmnd_message(__('Settings saved. Initializing Recmnd content...'));
		recmnd_bulk_upload();
		?><hr /><a href="">Return to Recmnd Configuration</a><?php
		return true;
	} else {
		echo recmnd_message(__('Settings saved.'));
	}

	return false;
}

/**
 * Display the admin page
 */
function recmnd_options() {
	global $recmnd_api_key, $recmnd_api_secret, $recmnd_api_host, $recmnd_auto_append;

	//check if the user has enough permissions
	if (!current_user_can('manage_options')){
		wp_die( __('You do not have sufficient permissions to access this page.', 'recmnd') );
	}

	// Now display the settings editing screen
	echo '<div class="wrap">';
	// header
	echo "<h2>" . __( 'Recmnd Configuration', 'recmnd-options' ) . "</h2>";

	if (count($_POST)) {
		if (recmnd_update_options()) {
			// update options indicates to skip rest of options
			return;
		}
	}
	
	$initialized = strlen($recmnd_api_key) && strlen($recmnd_api_secret) && strlen($recmnd_api_host);
	if ($initialized) {
		$submit_button = 'Save Settings';
	} else {
		$submit_button = 'Initialize Recmnd';
	}

	// settings form
	?>
	<p>In order to use the Recmnd plugin you must have an active Recmnd account. You can signup for an account at our site - <a href="http://www.recmnd.com/" title="Recmnd">www.recmnd.com</a>.</p>
	<form name="recmnd_conf_form" method="post" action="">
		<h3><?php _e("API Hostname:", 'recmnd' ); ?> </h3>
		<p>
			<input tabindex="1" type="text" name="recmnd_api_host" value="<?php echo $recmnd_api_host; ?>" size="80" /> 
			&nbsp; See your Recmnd API hostname <a href="https://www.recmnd.com/account/profile/" title="Recmnd API hostname">here</a>.
		</p>
		
		<h3><?php _e("API Key:", 'recmnd' ); ?> </h3>
		<p>
			<input tabindex="1" type="text" name="recmnd_api_key" value="<?php echo $recmnd_api_key; ?>" size="80" />
			&nbsp; See your Recmnd API key <a href="https://www.recmnd.com/account/profile/" title="Recmnd API key">here</a>.
		</p>
		
		<h3><?php _e("API Secret:", 'recmnd' ); ?> </h3>
		<p>
			<input tabindex="1" type="text" name="recmnd_api_secret" value="<?php echo $recmnd_api_secret; ?>" size="80" />
			&nbsp; See your Recmnd API secret <a href="https://www.recmnd.com/account/profile/" title="Recmnd API secret">here</a>.
		</p>
		
		<h3><?php _e("Recmnd Widget", 'recmnd'); ?></h3>
		<p>
			<input type="hidden" name="recmnd_auto_append" value="0" />
			<input tabindex="1" type="checkbox" name="recmnd_auto_append" value="1"<?php echo $recmnd_auto_append? ' checked="checked"': '' ?> />
			&nbsp; Automatically insert the Recmnd widget below each post.
		</p>
		<p><strong>Note:</strong> Uncheck this option only if you are planning to insert the Recmnd widget <a href="https://www.recmnd.com/account/widget/">manually</a> at your pages. Inserting the widget manually allows you to change the widget position at your pages.</p>
		
		<p class="submit">
			<input tabindex="1" type="submit" name="submit" value="<?php esc_attr_e($submit_button, 'recmnd') ?> &raquo;" />
		</p>
	</form>
	<?php
		if ($recmnd_api_key && $recmnd_api_secret && $recmnd_api_host){
			$proto = preg_match('/^http:\/\//', $recmnd_api_host)?'':'http://';
		}
	?>
	<p>You can control the additional service settings such as the number of displayed recommendations, the applied time filter, and the widget style directly from the "Settings" and "Widget" sections of your Recmnd account. You can monitor your recommendations history, follow-through statistics, and the remaining resources at the "Dashboard" section of your Recmnd account.</p>

	<?php if (current_user_can('export')): ?>
	<hr />
	
	<?php if ($initialized): ?>
	<p><a id="a-bulk-upload" href="<?php bloginfo('wpurl');?>/wp-content/plugins/recmnd/bulk_upload.php" target="_blank"><?php _e('Automatic Recmnd Initialization');?></a> (new in version 1.1)
	<script type="text/javascript">
	var a = document.getElementById('a-bulk-upload');
	a.onclick = function() {
		var div = document.getElementById('bulk-upload-progress');
		var iframe = document.createElement('iframe');
		iframe.src = '<?php bloginfo('wpurl');?>/wp-content/plugins/recmnd/bulk_upload.php';
		iframe.style.width = '90%';
		iframe.style.height = '20em';
		while (div.firstChild)
			div.removeChild(div.firstChild);
		div.appendChild(iframe);
		return false;
	}
	</script>
	<br />You can generate, upload, and have your bulk XML analyzed automatically by the Recmnd engine. Simply click on the link above and all your current posts will be processed automatically.
	<div id="bulk-upload-progress"></div>
	</p>
	<?php endif ?>
	<p>
	<a href="<?php bloginfo('wpurl');?>/wp-content/plugins/recmnd/get_xml.php" target="_blank"><?php _e('Manual Recmnd Initialization');?></a> (use this option if the automatic initialization fails)
	<br />Generate an xml dump of your current posts and upload it to your Recmnd account. Completing these two steps will prepare your own recommendation engine and you will be ready to serve related items to your readers.
	</p>
	<?php endif ?>

</div>
<?php
}

/**
 * Plugin init
 */
function recmnd_init(){
	global $recmnd_api_key, $recmnd_api_secret, $recmnd_api_host, $recmnd_auto_append;
	$recmnd_api_key = get_option('recmnd_api_key');
	$recmnd_api_secret = get_option('recmnd_api_secret');
	$recmnd_api_host = get_option('recmnd_api_host');
	$recmnd_auto_append = get_option('recmnd_auto_append', true);

	wp_enqueue_script('recmnd', 'http://api.recmnd.com/recmnd.js');
	
	$home_url = get_bloginfo('url');
	if (substr($home_url, -1) !== '/') 
		$home_url = $home_url . '/';
		
	// if (recmnd_current_url() != $home_url)
	// 	wp_register_sidebar_widget('recmnd', __('Recmnd'), 'recmnd_widget');
		
	if (!$recmnd_api_key && !isset($_POST['submit']))
		add_action('admin_notices', 'recmnd_msg_almost_done');
}

// publish post hook
//add_action('publish_post', 'recmnd_insert_single', 0); // normal post
//add_action('publish_phone', 'recmnd_insert_single', 0); // post via email

// delete post hook
add_action('delete_post', 'recmnd_delete_single', 0);

// update post hook
add_action('pre_post_update', 'recmnd_pre_update_single', 0);
add_action('save_post', 'recmnd_update_single', 0);

// admin page hook
add_action('admin_menu', 'recmnd_admin_menu');

// sidebar widget hook
add_action('init', 'recmnd_init');

// define recmnd action for use in themes
add_action('recmnd_widget', 'recmnd_widget');

// define filter to add the recmnd widget after the post
add_filter('the_content', 'recmnd_auto_append');

?>
