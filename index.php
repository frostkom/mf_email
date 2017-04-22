<?php
/*
Plugin Name: MF Email
Plugin URI: https://github.com/frostkom/mf_email
Description: 
Version: 5.4.2
Author: Martin Fors
Author URI: http://frostkom.se
Text Domain: lang_email
Domain Path: /lang

GitHub Plugin URI: frostkom/mf_email
*/

include_once("include/classes.php");
include_once("include/functions.php");

add_action('cron_base', 'activate_email', mt_rand(1, 10));
add_action('cron_base', 'cron_email', mt_rand(1, 10));

if(is_admin())
{
	DEFINE('EMAILS2SHOW', 50);

	register_activation_hook(__FILE__, 'activate_email');
	register_uninstall_hook(__FILE__, 'uninstall_email');

	add_action('admin_init', 'settings_email');
	add_action('admin_menu', 'menu_email');

	add_filter('get_user_notifications', 'get_user_notifications_email', 10, 1);
	add_action('deleted_user', 'deleted_user_email');
}

add_action('phpmailer_init','phpmailer_init_email');
add_action('wp_ajax_send_smtp_test', 'send_smtp_test');

load_plugin_textdomain('lang_email', false, dirname(plugin_basename(__FILE__)).'/lang/');

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

	$default_charset = DB_CHARSET != '' ? DB_CHARSET : "utf8";

	$wpdb->query("CREATE TABLE IF NOT EXISTS ".$wpdb->base_prefix."email (
		emailID INT UNSIGNED NOT NULL AUTO_INCREMENT,
		blogID TINYINT UNSIGNED NOT NULL DEFAULT '0',
		emailPublic ENUM('0', '1') NOT NULL DEFAULT '0',
		emailRoles VARCHAR(100),
		emailVerified ENUM('-1', '0', '1') NOT NULL DEFAULT '0',
		emailServer VARCHAR(30),
		emailPort SMALLINT,
		emailUsername VARCHAR(30),
		emailPassword VARCHAR(100),
		emailAddress VARCHAR(50),
		emailName VARCHAR(60),
		emailCreated DATETIME,
		emailChecked DATETIME,
		emailSmtpSSL ENUM('', 'ssl', 'tls') NOT NULL DEFAULT '',
		emailSmtpServer VARCHAR(100) DEFAULT NULL,
		emailSmtpPort SMALLINT DEFAULT NULL,
		emailSmtpUsername VARCHAR(100) DEFAULT NULL,
		emailSmtpPassword VARCHAR(100) DEFAULT NULL,
		userID INT UNSIGNED,
		emailDeleted ENUM('0','1') NOT NULL DEFAULT '0',
		emailDeletedDate DATETIME DEFAULT NULL,
		emailDeletedID INT UNSIGNED DEFAULT NULL,
		PRIMARY KEY (emailID),
		KEY userID (userID)
	) DEFAULT CHARSET=".$default_charset);

	$wpdb->query("CREATE TABLE IF NOT EXISTS ".$wpdb->base_prefix."email_users (
		emailID INT UNSIGNED,
		userID INT UNSIGNED,
		KEY emailID (emailID),
		KEY userID (userID)
	) DEFAULT CHARSET=".$default_charset);

	$wpdb->query("CREATE TABLE IF NOT EXISTS ".$wpdb->base_prefix."email_folder (
		folderID INT unsigned NOT NULL AUTO_INCREMENT,
		folderID2 INT unsigned DEFAULT NULL,
		emailID INT unsigned NOT NULL DEFAULT '0',
		folderType INT unsigned NOT NULL DEFAULT '0',
		folderName VARCHAR(100) DEFAULT NULL,
		folderCreated DATETIME DEFAULT NULL,
		userID INT unsigned DEFAULT NULL,
		folderDeleted ENUM('0','1') NOT NULL DEFAULT '0',
		folderDeletedDate DATETIME DEFAULT NULL,
		folderDeletedID INT unsigned DEFAULT NULL,
		PRIMARY KEY (folderID),
		KEY emailID (emailID),
		KEY userID (userID),
		KEY folderType (folderType)
	) DEFAULT CHARSET=".$default_charset);

	$wpdb->query("CREATE TABLE IF NOT EXISTS ".$wpdb->base_prefix."email_message (
		messageID INT unsigned NOT NULL AUTO_INCREMENT,
		folderID INT unsigned DEFAULT NULL,
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
		messageSize INT unsigned NOT NULL DEFAULT '0',
		messageCreated DATETIME DEFAULT NULL,
		messageReceived DATETIME DEFAULT NULL,
		userID INT unsigned DEFAULT NULL,
		messageDeleted ENUM('0','1') NOT NULL DEFAULT '0',
		messageDeletedDate DATETIME DEFAULT NULL,
		messageDeletedID INT unsigned DEFAULT NULL,
		PRIMARY KEY (messageID),
		KEY folderID (folderID),
		KEY messageDeleted (messageDeleted),
		KEY messageCreated (messageCreated)
	) DEFAULT CHARSET=".$default_charset);

	$wpdb->query("CREATE TABLE IF NOT EXISTS ".$wpdb->base_prefix."email_message_attachment (
		messageID INT unsigned NOT NULL,
		fileID INT unsigned DEFAULT NULL,
		KEY messageID (messageID),
		KEY fileID (fileID)
	) DEFAULT CHARSET=".$default_charset);

	$wpdb->query("CREATE TABLE IF NOT EXISTS ".$wpdb->base_prefix."email_spam (
		spamID INT unsigned NOT NULL AUTO_INCREMENT,
		emailID INT UNSIGNED,
		messageFrom VARCHAR(100) DEFAULT NULL,
		spamCount INT UNSIGNED DEFAULT NULL,
		KEY spamID (spamID),
		KEY emailID (emailID)
	) DEFAULT CHARSET=".$default_charset);

	$arr_update_tables = array();

	$arr_update_tables[$wpdb->base_prefix."email"] = array(
		'emailVerified' => "ALTER TABLE [table] ADD [column] ENUM('-1', '0', '1') NOT NULL DEFAULT '0' AFTER emailID",
		'emailPublic' => "ALTER TABLE [table] ADD [column] ENUM('0', '1') NOT NULL DEFAULT '0' AFTER emailID",
		'emailRoles' => "ALTER TABLE [table] ADD [column] VARCHAR(100) AFTER emailPublic",
		'blogID' => "ALTER TABLE [table] ADD [column] TINYINT UNSIGNED NOT NULL DEFAULT '0' AFTER emailID",
		'emailChecked' => "ALTER TABLE [table] ADD [column] DATETIME AFTER emailCreated",
		'emailSmtpSSL' => "ALTER TABLE [table] ADD [column] ENUM('', 'ssl', 'tls') NOT NULL DEFAULT '' AFTER emailChecked",
		'emailSmtpServer' => "ALTER TABLE [table] ADD [column] VARCHAR(100) DEFAULT NULL AFTER emailSmtpSSL",
		'emailSmtpPort' => "ALTER TABLE [table] ADD [column] SMALLINT DEFAULT NULL AFTER emailSmtpServer",
		'emailSmtpUsername' => "ALTER TABLE [table] ADD [column] VARCHAR(100) DEFAULT NULL AFTER emailSmtpPort",
		'emailSmtpPassword' => "ALTER TABLE [table] ADD [column] VARCHAR(100) DEFAULT NULL AFTER emailSmtpUsername",
	);

	add_columns($arr_update_tables);

	$arr_update_existing_columns = array();

	$arr_update_existing_columns[$wpdb->base_prefix."email_message"] = array(
		'messageHeader' => "ALTER TABLE [table] DROP [column]",
		'messageRecieved' => "ALTER TABLE [table] CHANGE [column] messageReceived DATETIME DEFAULT NULL",
		'emailSmtpSSL' => "ALTER TABLE [table] CHANGE [column] emailSmtpSSL ENUM('', 'ssl', 'tls') NOT NULL DEFAULT ''",
	);

	update_columns($arr_update_existing_columns);

	delete_base(array(
		'table' => "email_folder",
		'field_prefix' => "folder",
		'child_tables' => array(
			'email_message' => array(
				'action' => "trash",
				'field_prefix' => "message",
			),
		),
	));

	delete_base(array(
		'table' => "email_message",
		'field_prefix' => "message",
		'child_tables' => array(
			'email_message_attachment' => array(
				'action' => "delete",
			),
		),
	));

	delete_base(array(
		'table' => "email",
		'field_prefix' => "email",
		'child_tables' => array(
			'email_folder' => array(
				'action' => "trash",
				'field_prefix' => "folder",
			),
			'email_users' => array(
				'action' => "delete",
			),
			'email_spam' => array(
				'action' => "delete",
			),
		),
	));
}

function uninstall_email()
{
	mf_uninstall_plugin(array(
		'tables' => array('email', 'email_users', 'email_folders', 'email_message', 'email_message_attachment', 'email_spam'),
	));
}