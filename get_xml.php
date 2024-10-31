<?php
require('../../../wp-config.php');

if (!current_user_can('export'))
	wp_die(__('You do not have sufficient permissions to export the content of this site.'));

header('Content-Type: ; charset=' . get_option('blog_charset'), true);

function recmnd_clean_xml($str, $charset){
	$str = htmlspecialchars($str, ENT_COMPAT, $charset);
	$str = preg_replace('/[\x00-\x08\x0B\x0C-\x1F]+/', '', $str);
	return $str;
}

header("Content-type: application/xml; charset=" . get_option('blog_charset'));
header("Content-Disposition: attachment; filename=\"recmnd.xml\";");
header("Content-Transfer-Encoding: binary");
header('Pragma: no-cache');
header('Expires: 0');
set_time_limit(0);

echo '<?xml version="1.0" encoding="'.get_option('blog_charset').'"?>'."\n";

global $post;

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
	
$posts = get_posts('numberposts=-1&orderby=ID');
$charset = get_option('blog_charset');

print("<records>\n");
foreach($posts as $post){
	$userdata = get_userdata($post->post_author);
	setup_postdata($post);

	$categories = array();
	foreach ((get_the_category($post->ID)) as $category){
		$categories[] = $category->cat_name;
	}
	$category_list = count($categories)?implode($categories,', '):'';
	
	printf($record_template,
		recmnd_clean_xml($category_list, $charset),
		is_object($userdata)? recmnd_clean_xml($userdata->user_login, $charset): '',
		date('d/m/Y H:i:s', strtotime($post->post_date)),
		date('d/m/Y H:i:s', strtotime($post->post_date)),	
		recmnd_clean_xml($post->post_title, $charset),
		recmnd_clean_xml($post->post_excerpt, $charset),
		recmnd_clean_xml($post->post_content, $charset),
		recmnd_clean_xml(get_permalink($post->ID)),
		'', //tags
		'', //source
		'blogpost'
	);
}
print("</records>\n");

exit;
?>
