<?php

require_once '../../../wp-config.php';
require_once 'functions.php';

global $recmnd_api_host, $recmnd_api_key, $recmnd_api_secret;
global $recmnd_bulk_result;

wp_admin_css( 'css/global' );
wp_admin_css();
wp_admin_css( 'css/colors' );
wp_admin_css( 'css/ie' );

?>
<!DOCTYPE html>
<html>
	<head>
		<?php print_admin_styles() ?>
	</head>
	<body>
		<div class="wrap">
			<h2><?php echo __( 'Bulk Upload', 'recmnd-options' ) ?></h2>
<?php recmnd_bulk_upload() ?>
		</div>
	</body>
</html>