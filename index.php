<?php
/*
Plugin Name: MF Email
Plugin URI: https://github.com/frostkom/mf_email
Description:
Version: 6.6.18
Licence: GPLv2 or later
Author: Martin Fors
Author URI: https://martinfors.se
Text Domain: lang_email
Domain Path: /lang

Depends: MF Base
GitHub Plugin URI: frostkom/mf_email
*/

if(!function_exists('is_plugin_active') || function_exists('is_plugin_active') && is_plugin_active("mf_base/index.php"))
{
	include_once("include/classes.php");

	load_plugin_textdomain('lang_email', false, dirname(plugin_basename(__FILE__))."/lang/");

	$obj_email = new mf_email();

	add_action('cron_base', 'activate_email', mt_rand(1, 10));
	add_action('cron_base', array($obj_email, 'cron_base'), mt_rand(1, 10));

	if(is_admin())
	{
		DEFINE('EMAILS2SHOW', 50);

		register_activation_hook(__FILE__, 'activate_email');
		register_uninstall_hook(__FILE__, 'uninstall_email');

		add_action('admin_init', array($obj_email, 'settings_email'));
		add_action('admin_init', array($obj_email, 'admin_init'), 0);
		add_action('admin_menu', array($obj_email, 'admin_menu'));

		add_filter('get_user_notifications', array($obj_email, 'get_user_notifications'), 10, 1);
		//add_filter('get_user_reminders', array($obj_email, 'get_user_reminders'), 10, 1);
		add_action('deleted_user', array($obj_email, 'deleted_user'));
		add_action('wp_trash_post', array($obj_email, 'wp_trash_post'));
	}

	add_filter('wp_mail_from', array($obj_email, 'wp_mail_from'));
	add_filter('wp_mail_from_name', array($obj_email, 'wp_mail_from_name'));
	add_action('phpmailer_init', array($obj_email, 'phpmailer_init'));
	add_action('sent_email', array($obj_email, 'sent_email'));
	add_action('sent_email_error', array($obj_email, 'sent_email_error'));

	add_filter('get_emails_left_to_send', array($obj_email, 'get_emails_left_to_send'), 10, 4);
	add_filter('get_hourly_release_time', array($obj_email, 'get_hourly_release_time'), 10, 3);

	add_filter('get_preferred_content_types', array($obj_email, 'get_preferred_content_types'), 10, 3);

	add_action('wp_ajax_send_smtp_test', array($obj_email, 'send_smtp_test'));
	add_action('wp_ajax_nopriv_send_smtp_test', array($obj_email, 'send_smtp_test'));

	add_filter('filter_is_file_used', array($obj_email, 'filter_is_file_used'));

	require_once("include/roundcube/lib/html2text.php");
	require_once("include/roundcube/lib/tnef_decoder.php");
	require_once("include/roundcube/rcube_charset.php");
	require_once("include/roundcube/rcube_imap_generic.php");
	require_once("include/roundcube/rcube_imap.php");
	require_once("include/roundcube/rcube_message.php");

	define('RCMAIL_CHARSET', get_bloginfo('charset'));
	define('DEFAULT_MAIL_CHARSET', 'ISO-8859-1');
	define('RCMAIL_PREFER_HTML', false);

	function activate_email()
	{
		global $wpdb;

		$default_charset = (DB_CHARSET != '' ? DB_CHARSET : 'utf8');

		$arr_add_column = $arr_update_column = $arr_add_index = array();

		$wpdb->query("CREATE TABLE IF NOT EXISTS ".$wpdb->base_prefix."email (
			emailID INT UNSIGNED NOT NULL AUTO_INCREMENT,
			blogID TINYINT UNSIGNED NOT NULL DEFAULT '0',
			emailPublic ENUM('0', '1') NOT NULL DEFAULT '0',
			emailRoles VARCHAR(100) DEFAULT NULL,
			emailVerified ENUM('-1', '0', '1') NOT NULL DEFAULT '0',
			emailServer VARCHAR(30),
			emailPort SMALLINT,
			emailUsername VARCHAR(100),
			emailPassword VARCHAR(150),
			emailAddress VARCHAR(50),
			emailName VARCHAR(60),
			emailSignature TEXT,
			emailCreated DATETIME,
			emailChecked DATETIME,
			emailOutgoingType VARCHAR(20) NOT NULL DEFAULT 'smtp',
			emailLimitPerHour SMALLINT UNSIGNED DEFAULT '0',
			emailSmtpVerified ENUM('-1', '0', '1') NOT NULL DEFAULT '0',
			emailSmtpSSL ENUM('', 'ssl', 'tls') NOT NULL DEFAULT '',
			emailSmtpServer VARCHAR(100) DEFAULT NULL,
			emailSmtpPort SMALLINT DEFAULT NULL,
			emailSmtpHostname VARCHAR(100) DEFAULT NULL,
			emailSmtpUsername VARCHAR(100) DEFAULT NULL,
			emailSmtpPassword VARCHAR(150) DEFAULT NULL,
			emailPreferredContentTypes VARCHAR(100) DEFAULT NULL,
			emailSmtpChecked DATETIME,
			userID INT UNSIGNED DEFAULT NULL,
			emailDeleted ENUM('0','1') NOT NULL DEFAULT '0',
			emailDeletedDate DATETIME DEFAULT NULL,
			emailDeletedID INT UNSIGNED DEFAULT NULL,
			PRIMARY KEY (emailID),
			KEY userID (userID),
			KEY emailDeleted (emailDeleted),
			KEY emailAddress (emailAddress)
		) DEFAULT CHARSET=".$default_charset);

		$arr_add_column[$wpdb->base_prefix."email"] = array(
			'emailSmtpVerified' => "ALTER TABLE [table] ADD [column] ENUM('-1', '0', '1') NOT NULL DEFAULT '0' AFTER emailLimitPerHour",
			'emailPreferredContentTypes' => "ALTER TABLE [table] ADD [column] VARCHAR(100) DEFAULT NULL AFTER emailSmtpPassword",
		);

		$arr_update_column[$wpdb->base_prefix."email"] = array(
			//'emailUsername' => "ALTER TABLE [table] CHANGE [column] [column] VARCHAR(100)",
		);

		/*$arr_add_index[$wpdb->base_prefix."email"] = array(
			//'' => "ALTER TABLE [table] ADD INDEX [column] ([column])",
		);*/

		$wpdb->query("CREATE TABLE IF NOT EXISTS ".$wpdb->base_prefix."email_users (
			emailID INT UNSIGNED,
			userID INT UNSIGNED DEFAULT NULL,
			KEY emailID (emailID),
			KEY userID (userID)
		) DEFAULT CHARSET=".$default_charset);

		$wpdb->query("CREATE TABLE IF NOT EXISTS ".$wpdb->base_prefix."email_folder (
			folderID INT UNSIGNED NOT NULL AUTO_INCREMENT,
			folderID2 INT UNSIGNED DEFAULT NULL,
			emailID INT UNSIGNED NOT NULL DEFAULT '0',
			folderType INT UNSIGNED NOT NULL DEFAULT '0',
			folderName VARCHAR(100) DEFAULT NULL,
			folderCreated DATETIME DEFAULT NULL,
			userID INT UNSIGNED DEFAULT NULL,
			folderDeleted ENUM('0','1') NOT NULL DEFAULT '0',
			folderDeletedDate DATETIME DEFAULT NULL,
			folderDeletedID INT UNSIGNED DEFAULT NULL,
			PRIMARY KEY (folderID),
			KEY emailID (emailID),
			KEY userID (userID),
			KEY folderType (folderType),
			KEY folderName (folderName)
		) DEFAULT CHARSET=".$default_charset);

		$wpdb->query("CREATE TABLE IF NOT EXISTS ".$wpdb->base_prefix."email_message (
			messageID INT UNSIGNED NOT NULL AUTO_INCREMENT,
			folderID INT UNSIGNED DEFAULT NULL,
			messageTextID VARCHAR(100) DEFAULT NULL,
			messageMd5 VARCHAR(32) DEFAULT NULL,
			messageRead ENUM('0','1') NOT NULL DEFAULT '0',
			messageFrom VARCHAR(100) DEFAULT NULL,
			messageFromName VARCHAR(100) DEFAULT NULL,
			messageTo TEXT,
			messageCc TEXT,
			messageReplyTo VARCHAR(100) DEFAULT NULL,
			messageName VARCHAR(200) DEFAULT NULL,
			messageText TEXT,
			messageText2 TEXT,
			messageSize INT UNSIGNED NOT NULL DEFAULT '0',
			messageCreated DATETIME DEFAULT NULL,
			messageReceived DATETIME DEFAULT NULL,
			userID INT UNSIGNED DEFAULT NULL,
			messageDeleted ENUM('0','1') NOT NULL DEFAULT '0',
			messageDeletedDate DATETIME DEFAULT NULL,
			messageDeletedID INT UNSIGNED DEFAULT NULL,
			PRIMARY KEY (messageID),
			KEY folderID (folderID),
			KEY messageDeleted (messageDeleted),
			KEY messageCreated (messageCreated)
		) DEFAULT CHARSET=".$default_charset);

		$wpdb->query("CREATE TABLE IF NOT EXISTS ".$wpdb->base_prefix."email_message_attachment (
			messageID INT UNSIGNED NOT NULL,
			fileID INT UNSIGNED DEFAULT NULL,
			KEY messageID (messageID),
			KEY fileID (fileID)
		) DEFAULT CHARSET=".$default_charset);

		$wpdb->query("CREATE TABLE IF NOT EXISTS ".$wpdb->base_prefix."email_spam (
			spamID INT UNSIGNED NOT NULL AUTO_INCREMENT,
			emailID INT UNSIGNED,
			messageFrom VARCHAR(100) DEFAULT NULL,
			spamCount INT UNSIGNED DEFAULT NULL,
			KEY spamID (spamID),
			KEY emailID (emailID),
			KEY messageFrom (messageFrom)
		) DEFAULT CHARSET=".$default_charset);

		update_columns($arr_update_column);
		add_columns($arr_add_column);
		add_index($arr_add_index);

		mf_uninstall_plugin(array(
			'options' => array('setting_email_info', 'setting_email_custom_log_file'),
		));
	}

	function uninstall_email()
	{
		mf_uninstall_plugin(array(
			'options' => array('setting_email_log', 'setting_email_preferred_content_types', 'setting_email_info', 'setting_smtp_test', 'setting_smtp_server', 'setting_smtp_port', 'setting_smtp_ssl', 'setting_smtp_username', 'setting_smtp_password'),
			'tables' => array('email', 'email_users', 'email_folders', 'email_message', 'email_message_attachment', 'email_spam'),
		));
	}
}