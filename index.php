<?php
/*
Plugin Name: MF Email
Plugin URI: https://github.com/frostkom/mf_email
Description: 
Version: 5.11.11
Licence: GPLv2 or later
Author: Martin Fors
Author URI: https://frostkom.se
Text Domain: lang_email
Domain Path: /lang

Depends: MF Base
GitHub Plugin URI: frostkom/mf_email
*/

include_once("include/classes.php");
include_once("include/functions.php");

$obj_email = new mf_email();

add_action('cron_base', 'activate_email', mt_rand(1, 10));
add_action('cron_base', array($obj_email, 'run_cron'), mt_rand(1, 10));

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
}

add_filter('wp_mail_from', array($obj_email, 'wp_mail_from'));
add_filter('wp_mail_from_name', array($obj_email, 'wp_mail_from_name'));
add_action('phpmailer_init', array($obj_email, 'phpmailer_init'));

add_action('wp_ajax_send_smtp_test', array($obj_email, 'send_smtp_test'));
add_action('wp_ajax_nopriv_send_smtp_test', array($obj_email, 'send_smtp_test'));

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

	$arr_add_column = $arr_update_column = $arr_add_index = array();

	$wpdb->query("CREATE TABLE IF NOT EXISTS ".$wpdb->base_prefix."email (
		emailID INT UNSIGNED NOT NULL AUTO_INCREMENT,
		blogID TINYINT UNSIGNED NOT NULL DEFAULT '0',
		emailPublic ENUM('0', '1') NOT NULL DEFAULT '0',
		emailRoles VARCHAR(100),
		emailVerified ENUM('-1', '0', '1') NOT NULL DEFAULT '0',
		emailServer VARCHAR(30),
		emailPort SMALLINT,
		emailUsername VARCHAR(30),
		emailPassword VARCHAR(150),
		emailAddress VARCHAR(50),
		emailName VARCHAR(60),
		emailCreated DATETIME,
		emailChecked DATETIME,
		emailOutgoingType VARCHAR(20) NOT NULL DEFAULT 'smtp',
		emailSmtpSSL ENUM('', 'ssl', 'tls') NOT NULL DEFAULT '',
		emailSmtpServer VARCHAR(100) DEFAULT NULL,
		emailSmtpPort SMALLINT DEFAULT NULL,
		emailSmtpHostname VARCHAR(100) DEFAULT NULL,
		emailSmtpUsername VARCHAR(100) DEFAULT NULL,
		emailSmtpPassword VARCHAR(150) DEFAULT NULL,
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
		'emailVerified' => "ALTER TABLE [table] ADD [column] ENUM('-1', '0', '1') NOT NULL DEFAULT '0' AFTER emailID",
		'emailPublic' => "ALTER TABLE [table] ADD [column] ENUM('0', '1') NOT NULL DEFAULT '0' AFTER emailID",
		'emailRoles' => "ALTER TABLE [table] ADD [column] VARCHAR(100) AFTER emailPublic",
		'blogID' => "ALTER TABLE [table] ADD [column] TINYINT UNSIGNED NOT NULL DEFAULT '0' AFTER emailID",
		'emailChecked' => "ALTER TABLE [table] ADD [column] DATETIME AFTER emailCreated",
		'emailSmtpServer' => "ALTER TABLE [table] ADD [column] VARCHAR(100) DEFAULT NULL AFTER emailSmtpSSL",
		'emailSmtpPort' => "ALTER TABLE [table] ADD [column] SMALLINT DEFAULT NULL AFTER emailSmtpServer",
		'emailSmtpUsername' => "ALTER TABLE [table] ADD [column] VARCHAR(100) DEFAULT NULL AFTER emailSmtpPort",
		'emailSmtpPassword' => "ALTER TABLE [table] ADD [column] VARCHAR(150) DEFAULT NULL AFTER emailSmtpUsername",
		'emailSmtpHostname' => "ALTER TABLE [table] ADD [column] VARCHAR(100) DEFAULT NULL AFTER emailSmtpPort",
		'emailOutgoingType' => "ALTER TABLE [table] ADD [column] VARCHAR(20) NOT NULL DEFAULT 'smtp' AFTER emailChecked",
	);

	$arr_update_column[$wpdb->base_prefix."email"] = array(
		'emailSmtpSSL' => "ALTER TABLE [table] CHANGE [column] [column] ENUM('', 'ssl', 'tls') NOT NULL DEFAULT ''",
		'emailPassword' => "ALTER TABLE [table] CHANGE [column] [column] VARCHAR(150)",
		'emailSmtpPassword' => "ALTER TABLE [table] CHANGE [column] [column] VARCHAR(150)",
		'emailOutgoingType' => "ALTER TABLE [table] CHANGE [column] [column] VARCHAR(60)",
	);

	$arr_add_index[$wpdb->base_prefix."email"] = array(
		'emailDeleted' => "ALTER TABLE [table] ADD INDEX [column] ([column])",
		'emailAddress' => "ALTER TABLE [table] ADD INDEX [column] ([column])",
	);

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

	$arr_add_index[$wpdb->base_prefix."email_folder"] = array(
		'folderName' => "ALTER TABLE [table] ADD INDEX [column] ([column])",
	);

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

	$arr_update_column[$wpdb->base_prefix."email_message"] = array(
		'messageHeader' => "ALTER TABLE [table] DROP [column]",
		'messageRecieved' => "ALTER TABLE [table] CHANGE [column] messageReceived DATETIME DEFAULT NULL",
	);

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

	$arr_add_index[$wpdb->base_prefix."email_spam"] = array(
		'messageFrom' => "ALTER TABLE [table] ADD INDEX [column] ([column])",
	);

	update_columns($arr_update_column);
	add_columns($arr_add_column);
	add_index($arr_add_index);

	delete_base(array(
		'table_prefix' => $wpdb->base_prefix,
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
		'table_prefix' => $wpdb->base_prefix,
		'table' => "email_message",
		'field_prefix' => "message",
		'child_tables' => array(
			'email_message_attachment' => array(
				'action' => "delete",
			),
		),
	));

	delete_base(array(
		'table_prefix' => $wpdb->base_prefix,
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

	//Clean up spam folders where messages has not been deleted
	####################################
	$result = $wpdb->get_results("SELECT folderID FROM ".$wpdb->base_prefix."email_folder WHERE folderType = '3'");

	foreach($result as $r)
	{
		$intFolderID = $r->folderID;

		$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email_message SET messageDeleted = '1', messageDeletedDate = NOW() WHERE folderID = '%d' AND messageDeleted = '0'", $intFolderID));
	}
	####################################

	mf_uninstall_plugin(array(
		'options' => array('setting_smtp_test'),
	));
}

function uninstall_email()
{
	mf_uninstall_plugin(array(
		'options' => array('setting_email', 'setting_smtp_test', 'setting_smtp_server', 'setting_smtp_port', 'setting_smtp_ssl', 'setting_smtp_username', 'setting_smtp_password'),
		'tables' => array('email', 'email_users', 'email_folders', 'email_message', 'email_message_attachment', 'email_spam'),
	));
}