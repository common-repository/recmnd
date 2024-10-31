<?php

if (!function_exists("htmlspecialchars_decode")) {
	function htmlspecialchars_decode($string, $quote_style = ENT_COMPAT) {
		return strtr($string, array_flip(get_html_translation_table(HTML_SPECIALCHARS, $quote_style)));
	}
}

if (!function_exists('fprintf')) {
	function fprintf() {
		$args = func_get_args();
		$fp = array_shift($args);
		$string = call_user_func_array('sprintf', $args);
		fwrite($fp, $string);
	}
}

function recmnd_js_string($str) { return '"' . addcslashes($str, "\\\"\n\r\t" . chr(8) . chr(12)) . '"'; }

function recmnd_array_merge_assoc($a1, $a2) {
	foreach ($a2 as $k => $v)
		$a1[$k] = $v;
	return $a1;
}

// defeat broken plugins that produce output in their filters
function recmnd_apply_filters($filter, $data) {
	ob_start();
	$data = apply_filters($filter, $data);
	ob_end_clean();
	return $data;
}

// DEBUG
function recmnd_log($text){
	$logfile = dirname(__FILE__).'/recmnd.log';
	$handle = @fopen($logfile, 'a');
	if ($handle !== false){
		fwrite($handle, $text."\n");
		fclose($handle);
	}
}

// calculate request signature
function recmnd_signature($endpoint, $request, $secret) {
	unset($request['signature']);
	foreach ($request as $k => $v)
		$request[$k] = rawurlencode($k).'='.rawurlencode($v);
	ksort($request);
	$base = rawurlencode($secret).'&'.rawurlencode($endpoint).'&'.join('&', $request);
	return sha1($base);
}

// produce a nonce
function recmnd_nonce() {
	$last = get_option('recmnd_nonce');
	$nonce = substr(md5($last.time().getmypid()), 0, 16);
	update_option('recmnd_nonce', $nonce);
	return $nonce;
}

/**
 * Make a http post requst using cURL
 * @param string $host to request
 * @param string $path to request
 * @param array $request values
 * @return bool|string
 */
function recmnd_http_get($host, $path, $request){
	global $recmnd_api_secret;
	$request['nonce'] = recmnd_nonce();
	$request['ts'] = time();
	wp_verify_nonce($request['nonce']); // invalidate the nonce, forcing a new one next time
	$request['signature'] = recmnd_signature($path, $request, $recmnd_api_secret);

	$response = false;
	$http_code = 0;
	$url = $host. $path. (strpos($path, '?') === FALSE ? '?' : ''). http_build_query($request);

	$curl = curl_init();
	curl_setopt($curl, CURLOPT_URL, $url);
	curl_setopt($curl, CURLOPT_HEADER, 0);
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($curl, CURLOPT_TIMEOUT, 4);
	$response = curl_exec($curl);
	$header = curl_getinfo($curl);
	$http_code = $header['http_code'];
	curl_close($curl);
	recmnd_log("GET http://$host$path -> $http_code");
	return array('status' => $http_code, 'content' => $response);
}

/**
 * Make a http post requst using cURL
 * @param string $host to request
 * @param string $path to request
 * @param array $request values
 * @param array $options cURL options
 * @return bool|string
 */
function recmnd_http_post($host, $path, $request, $options = array()){
	global $recmnd_api_secret;
	$request['nonce'] = recmnd_nonce();
	$request['ts'] = time();
	$request['signature'] = recmnd_signature($path, $request, $recmnd_api_secret);

	$response = false;
	$http_code = 0;
	$curl = curl_init();

	$defaults = array(
		CURLOPT_POST => 1,
		CURLOPT_HEADER => 0,
		CURLOPT_URL => $host.$path,
		CURLOPT_FRESH_CONNECT => 1,
		CURLOPT_RETURNTRANSFER => 1,
		CURLOPT_FORBID_REUSE => 1,
		CURLOPT_TIMEOUT => 4,
		CURLOPT_POSTFIELDS => http_build_query($request),
	);
	$options = recmnd_array_merge_assoc($defaults, $options);
	foreach ($options as $option => $value)
		curl_setopt($curl, $option, $value);

	$response = curl_exec($curl);
	$header = curl_getinfo($curl);
	$http_code = $header['http_code'];
	curl_close($curl);
	recmnd_log("POST http://$host$path -> $http_code");
	return array('status' => $http_code, 'content' => $response);
}

/**
 * Get the current page url
 */
function recmnd_current_url() {
	$url = 'http';
	if (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] == "on") {
		$url .= "s";
	}
	$url .= "://";
	if ($_SERVER["SERVER_PORT"] != "80") {
		$url .= $_SERVER["SERVER_NAME"].":".$_SERVER["SERVER_PORT"].$_SERVER["REQUEST_URI"];
	} else {
		$url .= $_SERVER["SERVER_NAME"].$_SERVER["REQUEST_URI"];
	}
	return $url;
}

/**
 * Format notification message
 * @param string $text message
 */
function recmnd_message($text){
	return '
		<div class="updated">
			<p>
				<strong>'.$text.'</strong>
			</p>
		</div>
	';
}

function recmnd_clean_xml($str, $charset){
	$str = htmlspecialchars($str, ENT_COMPAT, $charset);
	$str = preg_replace('/[\x00-\x08\x0B\x0C-\x1F]+/', '', $str);
	return $str;
}

function recmnd_bulk_progress($curl, $data) {
	global $recmnd_bulk_result;
	if (strlen($recmnd_bulk_result)) {
		// continuation of payload
		$recmnd_bulk_result .= $data;
		return strlen($data);
	}

	static $buffer = '';
	$buffer .= $data;
	$lines = explode("\n", $buffer);
	// last line is either incomplete, or empty: keep it in the buffer
	$buffer = array_pop($lines);
	
	// process the completed lines:
	while (count($lines)) {
		if (substr($lines[0], 0, 9) == 'progress:') {
			// progress event
			$line = array_shift($lines);
			list($size, $total) = explode('/', substr($line, 9), 2);
			$percent = $size * 100 / $total;
			print("<script>recmnd_bulk_progress($percent)</script>");
			flush();
		} else {
			// start of payload, consume all remaining whole lines
			$recmnd_bulk_result = join("\n", $lines)."\n";
			break;
		}
	}
	if (strlen($recmnd_bulk_result) || ((strlen($buffer) >= 9) && (substr($buffer, 0, 9) != 'progress:'))) {
		// remaining buffer is payload, consume it
		$recmnd_bulk_result .= $buffer;
		$buffer = '';
	}
	return strlen($data);
}

function recmnd_bulk_upload() {
	if (!current_user_can('export')) {
		?><p><?php echo __('You do not have sufficient permissions to export the content of this site.'); ?></p><?
		return;
	}
	
	global $recmnd_bulk_result, $recmnd_api_key, $recmnd_api_secret, $recmnd_api_host;

	if (!($recmnd_api_key && $recmnd_api_secret && $recmnd_api_host)) {
		?><p><?php echo __('Recmnd plugin is not configured.'); ?></p><?
		return;
	}

	// create the export directory
	$exportdir = dirname(__FILE__).'/xml';
	if (!is_dir($exportdir)) {
		if (!mkdir($exportdir, 0755)) {
			?><p><?php echo __('Cannot create the export directory. Please create it manually:<br />'.htmlspecialchars($exportdir)); ?></p><?
			return;
		}
	}
	if (!is_writable($exportdir)) {
		?><p><?php echo __('Please make the export directory writable for your webserver:<br />'.htmlspecialchars($exportdir)); ?></p><?
		return;
	}

	$record_template = 
		"	<record>\n".
		"		<category>%s</category>\n".
		"		<postedBy>%s</postedBy>\n".
		"		<postedAt>%s</postedAt>\n".
		"		<parsedAt>%s</parsedAt>\n".
		"		<title>%s</title>\n".
		"		<description>%s</description>\n".
		"		<body>%s</body>\n".
		"		<url>%s</url>\n".
		"		<tags>%s</tags>\n".
		"		<source>%s</source>\n".
		"		<type>%s</type>\n".
		"	</record>\n";

	global $post;
	$posts = get_posts('numberposts=-1&orderby=ID');
	$charset = get_option('blog_charset');

	$fname = sprintf('%s-%d.xml', strftime('%Y%m%d-%H%M%S'), getmypid());
	$f = fopen("$exportdir/$fname", 'w');
	if (!$f) {
		?><p><?php echo __('Could not create XML export file.'); ?></p></div><?
		return;
	}
	$xmlurl = get_bloginfo('wpurl').'/wp-content/plugins/recmnd/xml/'.$fname;

	?>
			<div style="height:1em;width:20em;border:1px solid black;position:relative;background:#eee"><div id="recmnd_bulk_progress" style="height:100%;width:0%;position:absolute;background:#777"></div></div>
			<script> function recmnd_bulk_progress(perc) { document.getElementById('recmnd_bulk_progress').style.width = perc + '%' } </script>
			<div>Generating XML dump...</div>
	<?php
	flush();

	set_time_limit(0);
	fwrite($f, "<?xml version=\"1.0\" encoding=\"$charset\"?>\n");
	fwrite($f, "<records>\n");
	$total = 0;
	foreach($posts as $post){
		$userdata = get_userdata($post->post_author);
		setup_postdata($post);

		$categories = array();
		foreach ((get_the_category($post->ID)) as $category)
			$categories[] = $category->cat_name;
		$category_list = count($categories)?implode($categories,', '):'';

		$content = $post->post_content;
		$content = recmnd_apply_filters('the_content', $content);
		$content = str_replace(']]>', ']]&gt;', $content);

		$excerpt = $post->post_excerpt;
		$excerpt = recmnd_apply_filters('the_excerpt', $excerpt);
		$excerpt = str_replace(']]>', ']]&gt;', $excerpt);

		fprintf($f, $record_template,
			recmnd_clean_xml($category_list, $charset),
			is_object($userdata)? recmnd_clean_xml($userdata->user_login, $charset): '',
			date('d/m/Y H:i:s', strtotime($post->post_date)),
			date('d/m/Y H:i:s', strtotime($post->post_date)),			
			recmnd_clean_xml($post->post_title, $charset),
			recmnd_clean_xml($excerpt, $charset),
			recmnd_clean_xml($content, $charset),
			recmnd_clean_xml(get_permalink($post->ID), $charset),
			'', //tags
			'', //source
			'blogpost'
		);
		$total++;
		if (!($total % 50)) {
			$percent = $total * 100 / count($posts);
			print("<script>recmnd_bulk_progress($percent)</script>");
		}
	}
	print("<script>recmnd_bulk_progress(100)</script>");
	fwrite($f,"</records>\n");
	print("<div>Sending request to Recmnd...</div>");
	flush();
	$request = array (
		'api_key' => $recmnd_api_key,
		'xml' => $xmlurl,
	);
	$result = recmnd_http_post($recmnd_api_host, '/api/bulk_import/', $request, array(CURLOPT_TIMEOUT => 600, CURLOPT_WRITEFUNCTION => 'recmnd_bulk_progress'));
	unlink("$exportdir/$fname");


	if ($result['status'] == 0) {
		print("<div style=\"font-size: 120%; color: #FF0000; font-weight: bold;\">Error: Request timed out</div>");
		print("<div>Bulk upload failed.</div>");
		return;
	}

	$response = @unserialize($recmnd_bulk_result);
	if ($response === false) {
		print("<div style=\"font-size: 120%; color: #FF0000; font-weight: bold;\">Error: Received malformed response</div>");
		print("<div>".htmlspecialchars($recmnd_bulk_result)."</div>");
		print("<div>Bulk upload failed.</div>");
		return;
	}

	if (isset($response['stats']) && is_array($response['stats']))
		foreach ($response['stats'] as $line)
			print("<div>&bull; ".htmlspecialchars($line)."</div>");

	if (isset($response['error'])) {
		print("<div style=\"font-size: 120%; color: #FF0000; font-weight: bold;\">Error: ".$response['error']."</div>");
		print("<div>Bulk upload failed.</div>");
		return;
	}
	if (isset($response['records']) && ($total != $response['records'])) {
		print("<div style=\"font-size: 120%; color: #FF0000; font-weight: bold;\">Error: Sent $total records, Recmnd recognized {$response['records']} records</div>");
		print("<div>Bulk upload incomplete.</div>");
	}
		
	print("<div>Bulk upload finished.</div>");
}

?>