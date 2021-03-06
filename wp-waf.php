<?php
/*
Plugin Name: WP WAF
Plugin URI: http://wordpress.org/extend/plugins/wp-waf/
Description: WP WAF - WordPress Application Firewall. Protects against current and future attacks. Email notification is disabled by default, notification can be activated and configured in <strong>Settings -> WP WAF</strong>. Go to your <a href="options-general.php?page=wp_waf">WP WAF configuration</a> page.
Author: Gianni 'guelfoweb' Amato
Version: 2.0
Author URI: https://github.com/guelfoweb/wp-waf/
*/

$blog_wpurl  = get_bloginfo( 'wpurl' );
$blog_name   = get_bloginfo( 'name' );
$admin_email = get_option( 'admin_email' );

/* Set alert */
$alert  = "<br><center>";
$alert .= "<h2>WP_WAF</h2>";
$alert .= "<img src='" . plugin_dir_url( __FILE__ ) . "stuff/wp_waf.png' /><br>";
$alert .= "<b><font color=\"red\">Your request has been blocked!</font></b><br><br>";
$alert .= "<i>See <a href='" . plugin_dir_url( __FILE__ ) . "stuff/README'>README</a> file for more info.</i>";
$alert .= "</center>";

/* Set filter */
$xss  = "javascript|vbscript|expression|applet|meta|xml|blink|";
$xss .= "link|style|script|embed|object|iframe|frame|frameset|";
$xss .= "ilayer|layer|bgsound|title|base|form|img|body|href|div|cdata";

$ua  = "curl|wget|winhttp|HTTrack|clshttp|loader|email|harvest|extract|grab|miner|";
$ua .= "libwww-perl|acunetix|sqlmap|python|nikto|scan";

$sql  = "[\x22\x27](\s)*(or|and)(\s).*(\s)*\x3d|";
$sql .= "cmd=ls|cmd%3Dls|";
$sql .= "(drop|alter|create|truncate).*(index|table|database)|";
$sql .= "insert(\s).*(into|member.|value.)|";
$sql .= "(select|union|order).*(select|union|order)|";
$sql .= "0x[0-9a-f][0-9a-f]|";
$sql .= "benchmark\([0-9]+,[a-z]+|benchmark\%28+[0-9]+%2c[a-z]+|";
$sql .= "eval\(.*\(.*|eval%28.*%28.*|";
$sql .= "update.*set.*=|delete.*from";

$trasversal = "\.\.\/|\.\.\\|%2e%2e%2f|%2e%2e\/|\.\.%2f|%2e%2e%5c";

$rfi  = "%00|";
$rfi .= "(?:((?:ht|f)tp(?:s?)|file|webdav)\:\/\/|~\/|\/).*\.\w{2,3}|";
$rfi .= "(?:((?:ht|f)tp(?:s?)|file|webdav)%3a%2f%2f|%7e%2f%2f).*\.\w{2,3}";

/* Block request and send email */
function wp_waf_email( $attack_type, $log, $matched, $via ) {
	global $admin_email, $blog_wpurl, $blog_name;

	$settings  = (array) get_option( 'waf_settings' );
	$waf_email = isset( $settings['waf_email'] ) ? $settings['waf_email'] : $admin_email;
	$waf_msg   = isset( $settings['waf_msg'] ) ? $settings['waf_msg'] : '';

	/* Compose email */
	$subject = "WP_WAF - $blog_name";
	$body    = "== Attack Details ==\n\n";
	$body   .= "TYPE: $attack_type\n";
	$body   .= "MATCHED: \"$matched\"\n";
	$body   .= "ACTION: Blocked\n";
	$body   .= "$log";

	/* Send email */
	if ( get_option( $via ) != "" ) {
		if ( $waf_email == "" ) {
			@mail( $admin_email, $subject, $body );
		} else {
			@mail( $waf_email, $subject, $body );
		}
	}

	global $alert;
	switch ( $waf_msg ) {
	case "":
		die( $alert );
		break;
	case "waf_logo":
		die ( $alert );
		break;
	case "waf_blank":
		die( "" );
		break;
	}
}

/* Attack filter */
function wp_waf_filter( $content ) {
	$req_method = $_SERVER['REQUEST_METHOD'];
	$req_referr = $_SERVER['HTTP_REFERER'];
	$req_uagent = $_SERVER['HTTP_USER_AGENT'];
	$req_query  = $_SERVER['QUERY_STRING'];
	$req_uri    = $_SERVER['REQUEST_URI'];
	$req_ip     = getenv( 'REMOTE_ADDR' );

	$url  = ( !empty( $_SERVER['HTTPS'] ) ) ? "https://".$_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI'] : "http://".$_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI'];
	$time = date( "M j G:i:s Y" );

	/* Info User Log */
	$msg  = "\nDATE/TIME: ".$time;
	$msg .= "\n\nFROM IP: http://whois.domaintools.com/".$req_ip;
	$msg .= "\nURI: ".$req_uri;
	$msg .= "\nSTRING: ".$req_query;
	$msg .= "\nMETHOD: ".$req_method;
	$msg .= "\nUSERAGENT: ".$req_uagent;
	$msg .= "\nREFERRER: ".$req_referr;
	$msg .= "\n";

	global $xss, $ua, $trasversal, $sql, $rfi;

	$settings = (array) get_option( 'waf_settings' );

	/* Method Blacklist*/
	if ( preg_match( "/^(TRACE|DELETE|TRACK)/i", $req_method, $matched ) ) {
		wp_waf_email( 'Method Blacklist', $msg, $matched[1], 'waf_method' );
	}
	/* Referrer */
	elseif ( preg_match( "/<[^>]*(".$xss.")[^>]*>/i", $req_referr, $matched ) ) {
		wp_waf_email( 'Referrer XSS', $msg, $matched[1], 'waf_referrer' );
	}
	/* User Agent Empty */
	elseif ( preg_match( "/(^$)/i", $req_uagent, $matched ) ) {
		wp_waf_email( 'User Agent Empty', $msg, $matched[1], 'waf_useragent_blank' );
	}
	/* User Agent Blacklist */
	elseif ( preg_match( "/^(".$ua.").*/i", $req_uagent, $matched ) ) {
		wp_waf_email( 'User Agent Blacklist', $msg, $matched[1], 'waf_useragent' );
	}
	/* Query - > 255 */
	elseif ( strlen( $req_query ) > 255 ) {
		if ( $settings['waf_query_too_long'] != "" ) {
			wp_waf_email( 'Query Too Long', $msg, '> 255', 'waf_query' );
		}
	}
	/* Query - Cross Site Scripting */
	elseif ( preg_match( "/(<|<.)[^>]*(".$xss.")[^>]*>/i", $req_query, $matched ) ) {
		wp_waf_email( 'Query XSS', $msg, $matched[1], 'waf_query' );
	}
	elseif ( preg_match( "/((\%3c)|(\%3c).)[^(\%3e)]*(".$xss.")[^(\%3e)]*(%3e)/i", $req_query, $matched ) ) {
		wp_waf_email( 'Query XSS', $msg, $matched[1], 'waf_query' );
	}
	/* Query - Trasversal */
	elseif ( preg_match( "/^.*(".$trasversal.").*/i", $req_query, $matched ) ) {
		wp_waf_email( 'Query Trasversal', $msg, $matched[1], 'waf_query' );
	}
	/* Query - Remote File Inclusion */
	elseif ( preg_match( "/^.*(".$rfi.").*/i", $req_query, $matched ) ) {
		wp_waf_email( 'Query RFI', $msg, $matched[1], 'waf_query' );
	}
	/* Query - Sql injection */
	elseif ( preg_match( "/^.*(".$sql.").*/i", $req_query, $matched ) ) {
		wp_waf_email( 'Query SQL', $msg, $matched[1], 'waf_query' );
	}
}

add_action( 'posts_selection', 'wp_waf_filter' );


function wp_waf_install() {
	/* echo getcwd() = wp-admin */
	$htaccess = '../.htaccess';
	$htaback  = '../original.htaccess';
	$htawpwaf = '../wp-content/plugins/wp-waf/stuff/wp-waf.htaccess';
	if ( file_exists( $htawpwaf ) ) {
		/* from wordpress repository */
		$htawpwaf = '../wp-content/plugins/wp-waf/stuff/wp-waf.htaccess';
	} else {
		/* form github repository */
		$htawpwaf = '../wp-content/plugins/wp-waf-master/stuff/wp-waf.htaccess';
	}

	if ( file_exists( $htaccess ) ) {
		if ( file_exists( $htaback ) ) {
			/* remove original .htaccess */
			unlink( $htaccess );
			/* replace .htaccess with wp-waf.htaccess */
			copy( $htawpwaf, $htaccess );
		} else {
			/* backup */
			copy( $htaccess, $htaback );
			/* remove original .htaccess */
			unlink( $htaccess );
			/* replace .htaccess with wp-waf.htaccess */
			copy( $htawpwaf, $htaccess );
		}
	} else {
		/* replace .htaccess with wp-waf.htaccess */
		copy( $htawpwaf, $htaccess );
	}
}


if ( isset( $_GET['activate'] ) && $_GET['activate'] == 'true' ) {
	wp_waf_install();
}


function wp_waf_init() {
	register_setting( 'waf_settings_group', 'waf_settings', 'wp_waf_validation' );
}

add_action( 'admin_init', 'wp_waf_init' );


function wp_waf_validation( $input ) {
	$input['waf_email'] = wp_filter_nohtml_kses( $input['waf_email'] );
	return $input;
}


function wp_waf_settings_link( $links, $file ) {
	static $this_plugin;

	if ( empty( $this_plugin ) )
		$this_plugin = plugin_basename( __FILE__ );

	if ( $file == $this_plugin )
		$links[] = '<a href="' . admin_url( 'options-general.php?page=wp_waf' ) . '">' . __( 'Settings', 'wp-waf' ) . '</a>';

	return $links;
}

add_filter( 'plugin_action_links', 'wp_waf_settings_link', 10, 2 );


function wp_waf_plugin_menu() {
	add_options_page( 'WAF Options', 'WP WAF', 'manage_options', 'wp_waf', 'wp_waf_settings' );
}

add_action( 'admin_menu', 'wp_waf_plugin_menu' );


function wp_waf_settings() {
	global $admin_email, $blog_wpurl;
?>

	<div class="wrap">
		<h2><?php _e( 'WP WAF Settings', 'wp-waf' ); ?></h2>
		<p><?php _e( 'The WP_WAF is a Web Application Firewall for Wordpress. Protects against current and future attacks.', 'wp-waf' ); ?></p>
		<div class="clear" id="poststuff" style="width: 560px;">
			<form method="post" action="options.php">
<?php
settings_fields( 'waf_settings_group' );
$settings = get_option( 'waf_settings' );

$waf_email           = isset( $settings['waf_email'] )           ? $settings['waf_email']           : $admin_email;
$waf_query           = isset( $settings['waf_query'] )           ? $settings['waf_query']           : false;
$waf_method          = isset( $settings['waf_method'] )          ? $settings['waf_method']          : false;
$waf_referrer        = isset( $settings['waf_referrer'] )        ? $settings['waf_referrer']        : false;
$waf_useragent       = isset( $settings['waf_useragent'] )       ? $settings['waf_useragent']       : false;
$waf_useragent_blank = isset( $settings['waf_useragent_blank'] ) ? $settings['waf_useragent_blank'] : false;
$waf_query_too_long  = isset( $settings['waf_query_too_long'] )  ? $settings['waf_query_too_long']  : false;
$waf_msg             = isset( $settings['waf_msg'] )             ? $settings['waf_msg']             : 'waf_logo';
?>
				<div class="postbox">
					<h3 style="cursor: default;"><?php _e( 'Manage email notification of attacks', 'wp-waf' ); ?></h3>
					<div class="inside">
						<table class="widefat">
							<tr valign="top">
								<th scope="row">
									<?php _e( 'Email Address' , 'wp-waf' ); ?>
								</th>
								<td>
									<input type="text" name="waf_settings[waf_email]" value="<?php echo $waf_email; ?>" />
								</td>
							</tr>
						</table>
					</div>
				</div>
				<!-- /Manage email -->

				<div class="postbox">
					<h3 style="cursor: default;"><?php _e( 'Notifications for attacks type', 'wp-waf' ); ?></h3>
					<div class="inside">
						<table class="widefat">
							<tr valign="top">
								<th scope="row"><?php _e( 'Query', 'wp-waf' ); ?></th>
								<td>
									<input type="checkbox" name="waf_settings[waf_query]" value="1" id="waf_query" <?php checked( '1', $waf_query ); ?> />
								</td>
							</tr>

							<tr valign="top" class="alternate">
								<th scope="row"><?php _e( 'Method', 'wp-waf' ); ?></th>
								<td>
									<input type="checkbox" name="waf_settings[waf_method]" value="1" id="waf_method" <?php checked( '1', $waf_method ); ?> />
								</td>
							</tr>

							<tr valign="top">
								<th scope="row"><?php _e( 'Referrer', 'wp-waf' ); ?></th>
								<td>
									<input type="checkbox" name="waf_settings[waf_referrer]" value="1" id="waf_referrer" <?php checked( '1', $waf_referrer ); ?> />
								</td>
							</tr>

							<tr valign="top" class="alternate">
								<th scope="row"><?php _e( 'User Agent', 'wp-waf' ); ?></th>
								<td>
									<input type="checkbox" name="waf_settings[waf_useragent]" value="1" id="waf_useragent" <?php checked( '1', $waf_useragent ); ?> />
								</td>
							</tr>

							<tr valign="top">
								<th scope="row"><?php _e( 'User Agent Empty', 'wp-waf' ); ?></th>
								<td>
									<input type="checkbox" name="waf_settings[waf_useragent_blank]" value="1" id="waf_useragent_blank" <?php checked( '1', $waf_useragent_blank ); ?> />
									<?php echo '<em>'; _e( 'Not recommended', 'wp-waf' ); echo '</em>'; ?>
								</td>
							</tr>
						</table>
					</div>
				</div>
				<!-- /Notifications for attacks type -->

				<div class="postbox">
					<h3 style="cursor: default;"><?php _e( 'Under the Hood', 'wp-waf' ); ?></h3>
					<div class="inside">
						<table class="widefat">
							<tr valign="top">
								<th scope="row"><?php _e( 'Block Query &gt; 255 char', 'wp-waf' ); ?></th>
								<td>
									<input type="checkbox" name="waf_settings[waf_query_too_long]" value="1" id="waf_query_too_long" <?php checked( '1', $waf_query_too_long ); ?> />
								</td>
							</tr>

							<tr valign="top">
								<th scope="row"><?php _e( 'Attack Message', 'wp-waf' ); ?></th>
								<td>
									<select name="waf_settings[waf_msg]">
										<option <?php selected( 'waf_logo', $waf_msg ); ?> value="waf_logo">
											<?php _e( 'Logo', 'wp-waf' ); ?>
										</option>
										<option <?php selected( 'waf_blank', $waf_msg ); ?> value="waf_blank">
											<?php _e( 'Blank Page', 'wp-waf' ); ?>
										</option>
									</select>
								</td>
							</tr>
						</table>
					</div>
				</div>
				<!-- /Under the Hood -->

				<div class="postbox">
					<h3 style="cursor: default;"><?php _e( 'Test Configuration', 'wp-waf' ); ?></h3>
					<div class="inside">
						<table class="widefat">
							<tr valign="top">
								<th scope="row">
									<?php printf( __( '%1$sTest your configuration now!%2$s', 'wp-waf' ), '<a href="'.$blog_wpurl.'/?s=<script>alert(31337)</script>" target="_blank">', '</a>' ); ?>
								</th>
							</tr>
						</table>
					</div>
				</div>
				<!-- /Test configuration -->

				<p class="submit">
					<input type="submit" class="button-primary" value="<?php esc_attr_e( 'Save Changes', 'wp-waf' ) ?>" />
				</p>
			</form>
		</div>
		<!-- /poststuff -->
	</div>
	<!-- /wrap -->
<?php }
// Close function wp_waf_settings()


function wp_waf_load_languages() {
	load_plugin_textdomain( 'wp-waf', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

add_action( 'init', 'wp_waf_load_languages' );
