<?php

class mf_email
{
	function __construct($data = array())
	{
		if(isset($data['id']) && $data['id'] > 0)
		{
			$this->id = $id;
		}

		else
		{
			$this->id = check_var('intEmailID');
		}

		$this->type = isset($data['type']) ? $data['type'] : '';

		$this->message_id = 0;
	}

	function get_ssl_for_select()
	{
		$arr_data = array();
		$arr_data[''] = __("No", 'lang_email');
		$arr_data['ssl'] = __("SSL", 'lang_email');
		$arr_data['tls'] = __("TLS", 'lang_email');

		return $arr_data;
	}

	function get_update_email($data = array())
	{
		global $wpdb;

		if(!isset($data['cutoff'])){	$data['cutoff'] = date("Y-m-d H:i:s", strtotime("-2 minute"));} //"DATE_SUB(NOW(), INTERVAL 2 MINUTE)"

		if(IS_ADMIN)
		{
			$query_permission = " AND emailID IN ('".implode("','", get_email_accounts_permission())."')";

			$intUnread = $wpdb->get_var($wpdb->prepare("SELECT COUNT(messageID) FROM ".$wpdb->base_prefix."email_message INNER JOIN ".$wpdb->base_prefix."email_folder USING (folderID) WHERE messageRead = '0' AND folderType != '3' AND messageCreated > %s".$query_permission, $data['cutoff']));

			if($intUnread > 0)
			{
				return array(
					'title' => $intUnread > 1 ? sprintf(__("There are %d new emails in your inbox", 'lang_email'), $intUnread) : __("There is one new email in your inbox", 'lang_email'),
					'tag' => 'email',
					//'text' => "",
					//'icon' => "",
					'link' => admin_url("admin.php?page=mf_email/list/index.php"),
				);
			}
		}
	}

	function email_connect($data)
	{
		$is_connected = false;

		$connection = new rcube_imap();
		$connection->set_debug(false);
		$is_connected = $connection->connect($data['server'], $data['username'], $data['password'], $data['port']);

		return array($is_connected, $connection);
	}

	function create_term_if_not_exists($data)
	{
		$term = term_exists($data['term_slug'], $data['taxonomy']);

		if(!$term)
		{
			$term = wp_insert_term($data['term_name'], $data['taxonomy'], array(
				'slug' => $data['term_slug'],
			));
		}

		return $term;
	}

	function convert_email_subject($data)
	{
		if(function_exists('imap_mime_header_decode'))
		{
			$out = "";

			$elements = imap_mime_header_decode($data['subject']);

			$count_temp = count($elements);

			for($i = 0; $i < $count_temp; $i++)
			{
				$out .= $elements[$i]->text;
			}
		}

		else
		{
			$out = iconv_mime_decode($data['subject']); //, 0, "ISO-8859-1"
		}

		/*else
		{
			$out = $data['subject'];
		}*/

		return $out;
	}

	function run_cron()
	{
		global $wpdb;

		$obj_cron = new mf_cron();
		$obj_cron->start(__FUNCTION__);

		if($obj_cron->is_running == false)
		{
			$arr_bounce_subject = array(
				'Returned mail: see transcript for details',
				'Undelivered Mail Returned to Sender'
			);

			$arr_bounce_from_name = array(
				'Mail Delivery Subsystem'
			);

			$result = $wpdb->get_results("SELECT emailID, emailServer, emailPort, emailUsername, emailPassword, emailAddress FROM ".$wpdb->base_prefix."email WHERE emailDeleted = '0' AND emailVerified = '1' GROUP BY emailUsername");

			foreach($result as $r)
			{
				$intEmailID = $r->emailID;
				$strEmailServer = $r->emailServer;
				$intEmailPort = $r->emailPort;
				$strEmailUsername = $r->emailUsername;
				$strEmailPassword = $r->emailPassword;
				$strEmailAddress = $r->emailAddress;

				$obj_email = new mf_email($intEmailID);
				$obj_encryption = new mf_email_encryption("email");

				$strEmailPassword = $obj_encryption->decrypt($strEmailPassword, md5($strEmailAddress));

				list($is_connected, $connection) = $this->email_connect(array('server' => $strEmailServer, 'port' => $intEmailPort, 'username' => $strEmailUsername, 'password' => $strEmailPassword));

				if($is_connected == true)
				{
					foreach($connection->list_headers() as $header)
					{
						$logical = new rcube_message($connection, $header->uid);

						$intMessageStatus = isset($header->flags['SEEN']) && $header->flags['SEEN'] == true ? 1 : 0;
						$strMessageTextID = $header->messageID;
						//$strMessageReferences = $header->references;
						$strMessageFrom = $logical->sender['mailto'];
						$strMessageFromName = $logical->sender['name'];
						$strMessageTo = $logical->receiver['mailto'];
						$strMessageCc = $header->cc;
						$strMessageReplyTo = $header->replyto;
						$strMessageSubject = $this->convert_email_subject(array('subject' => $header->subject));
						$strMessageTextPlain = $logical->first_text_part();
						$strMessageTextHTML = $logical->first_html_part();
						$strMessageCreated = date("Y-m-d H:i:s", $header->timestamp);

						$bounce_from_name = preg_match('/('. implode('|', $arr_bounce_from_name) .')/i', $strMessageFromName);
						$bounce_subject = preg_match('/('. implode('|', $arr_bounce_subject) .')/i', $strMessageSubject);

						if($bounce_from_name == true || $bounce_subject == true)
						{
							$arr_emails = get_match_all('/[a-z0-9][-a-z0-9\._]+@[a-z0-9][-a-z0-9\._]+\.[a-z]{2,6}/is', $strMessageTextPlain, true);
							$arr_emails = array_unique($arr_emails);

							if(count($arr_emails) > 0)
							{
								$error_text = "Found bounce: ".var_export($arr_emails, true)." (".$strMessageSubject.")<br>";

								if(is_plugin_active("mf_address/index.php"))
								{
									foreach($arr_emails as $email)
									{
										$obj_address = new mf_address();
										$obj_address->get_address_id(array('email' => $email));

										if($obj_address->id > 0)
										{
											$obj_address->update_errors(array('action' => 'reset'));

											$intQueueID = $wpdb->get_var($wpdb->prepare("SELECT queueID FROM ".$wpdb->prefix."group_queue WHERE addressID = '%d' AND queueSent = '1' AND queueSentTime <= '".$strMessageCreated."' ORDER BY queueSentTime DESC LIMIT 0, 1", $obj_address->id));

											if($intQueueID > 0)
											{
												$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->prefix."group_queue SET queueReceived = '-1' WHERE queueID = '%d' AND addressID = '%d'", $intQueueID, $obj_address->id));
											}

											else
											{
												$error_text = "No group message sent to that address (".$email.", ".$obj_address->id.")<br>";
											}
										}

										else
										{
											$error_text = "No address with that e-mail (".$email.")<br>";
										}
									}
								}
							}

							else
							{
								$error_text = "No rows (".$strMessageSubject.")<br>";
							}
						}

						else
						{
							$strMessageMd5 = md5($strMessageTextID.$strMessageFrom.$strMessageTextPlain.$strMessageTextHTML);

							$resultExists_md5 = $wpdb->get_results($wpdb->prepare("SELECT messageID FROM ".$wpdb->base_prefix."email_message INNER JOIN ".$wpdb->base_prefix."email_folder USING (folderID) WHERE emailID = '%d' AND messageMd5 != '' AND messageMd5 = %s LIMIT 0, 2", $intEmailID, $strMessageMd5));

							if(count($resultExists_md5) == 0 && ($strMessageCreated >= date("Y-m-d H:i:s", strtotime("-7 day")))) // || $rowsTotal == 0
							{
								$intSpamID = $obj_email->check_if_spam(array('from' => $strMessageFrom));

								if($intSpamID > 0)
								{
									$intFolderID = get_folder_ids(__("Spam", 'lang_email'), 3, $intEmailID);
								}

								else
								{
									$intFolderID = get_folder_ids(__("Inbox", 'lang_email'), 6, $intEmailID);
								}

								list($intMessageID, $affected_rows) = save_email(array('folder_id' => $intFolderID, 'text_id' => $strMessageTextID, 'md5' => $strMessageMd5, 'from' => $strMessageFrom, 'from_name' => $strMessageFromName, 'to' => $strMessageTo, 'cc' => $strMessageCc, 'subject' => $strMessageSubject, 'content' => $strMessageTextPlain, 'content_html' => $strMessageTextHTML, 'created' => $strMessageCreated));

								if($affected_rows > 0)
								{
									if($intSpamID > 0)
									{
										$arr_temp = array();

										set_mail_info(array('message_id' => $intMessageID, 'mail_spam' => 1), $arr_temp);
										mark_spam(array('message_id' => $intMessageID, 'spam' => true));
									}

									$done_text = __("Inserted", 'lang_email').": ".$strMessageSubject."<br>";

									$arr_file_id = array();

									foreach($logical->attachments as $attachment)
									{
										$data = array(
											'content' => "",
											'mime' => "",
											'name' => "",
										);

										foreach($attachment as $key => $value)
										{
											if($key == "mime_id")
											{
												$data['content'] = $logical->get_part_content($value);
											}

											else if($key == "mimetype")
											{
												$data['mime'] = $value;
											}

											else if($key == "filename")
											{
												$data['name'] = $value;
											}
										}

										$intFileID = insert_attachment($data);

										if($intMessageID > 0 && $intFileID > 0)
										{
											$taxonomy = 'category';
											$post_id = $intFileID;

											$wpdb->query($wpdb->prepare("INSERT INTO ".$wpdb->base_prefix."email_message_attachment SET messageID = '%d', fileID = '%d'", $intMessageID, $intFileID));

											$term_attachment = $this->create_term_if_not_exists(array('taxonomy' => $taxonomy, 'term_slug' => 'email_attachment', 'term_name' => __("E-mail attachments", 'lang_email')));

											wp_set_object_terms($post_id, array((int)$term_attachment['term_id']), $taxonomy, false);

											$done_text = __("The attachment was saved", 'lang_email').": ".$intFileID." -> ".$intMessageID;
										}
									}
								}

								else
								{
									$error_text = __("The email was not able to be saved", 'lang_email').": ".$strMessageSubject."<br>";
								}
								########################
							}

							else
							{
								$error_text = __("The e-mail already exists", 'lang_email').": ".$strMessageSubject."<br>";

								/*$r = $resultExists_md5[0];
								$intMessageStatus = $r->mailStatus;

								if($intMessageStatus > 0)
								{
									$read_email_message = true;
								}

								if($strMessageCreated < date("Y-m-d H:i:s", strtotime("-14 day")) && $error_text == '')
								{
									$delete_email_message = true;
								}*/
							}

							/*if(isset($read_email_message) && $read_email_message == true)
							{
								if(isset($delete_email_message) && $delete_email_message == true)
								{
									$imap->delete_message($header->uid);
								}

								else
								{
									//$imap->set_flag($header->uid, 'SEEN', 'INBOX');
								}
							}

							else
							{
								//$imap->unset_flag($header->uid, 'SEEN', 'INBOX');
							}*/
						}
					}

					$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email SET emailChecked = NOW() WHERE emailID = '%d'", $intEmailID));
				}

				else
				{
					$error_text = __("Connection failed", 'lang_email');
				}
			}
		}

		$obj_cron->end();
	}

	function settings_email()
	{
		global $wpdb;

		$options_area = __FUNCTION__;

		add_settings_section($options_area, "", array($this, $options_area."_callback"), BASE_OPTIONS_PAGE);

		$arr_settings = array();
		$arr_settings['setting_email'] = __("E-mail", 'lang_email');

		$admin_email = get_bloginfo('admin_email');
		$wpdb->get_results($wpdb->prepare("SELECT emailID FROM ".$wpdb->base_prefix."email WHERE emailAddress = %s AND emailSmtpServer != ''", $admin_email));

		if($wpdb->num_rows == 0)
		{
			$arr_settings['setting_smtp_server'] = __("SMTP Server", 'lang_email');
			$arr_settings['setting_smtp_port'] = __("SMTP Port", 'lang_email');
			$arr_settings['setting_smtp_ssl'] = __("SMTP SSL", 'lang_email');
			$arr_settings['setting_smtp_username'] = __("SMTP Username", 'lang_email');
			$arr_settings['setting_smtp_password'] = __("SMTP Password", 'lang_email');
		}

		else if($wpdb->num_rows > 0 || get_option('setting_smtp_server') != '')
		{
			$arr_settings['setting_smtp_test'] = __("Test SMTP", 'lang_email');

			delete_option('setting_smtp_server');
			delete_option('setting_smtp_port');
			delete_option('setting_smtp_ssl');
			delete_option('setting_smtp_username');
			delete_option('setting_smtp_password');
		}

		show_settings_fields(array('area' => $options_area, 'object' => $this, 'settings' => $arr_settings));
	}

	function settings_email_callback()
	{
		$setting_key = get_setting_key(__FUNCTION__);

		echo settings_header($setting_key, __("E-mail", 'lang_email'));
	}

	function setting_email_callback()
	{
		global $wpdb;

		$admin_email = get_bloginfo('admin_email');

		echo "<p>".sprintf(__("The e-mail %s is used as sender address so this must be white listed in the SMTP, otherwise it can be caught in the servers spam filter", 'lang_email'), "<a href='".(is_multisite() ? admin_url("network/site-settings.php?id=".$wpdb->blogid."#admin_email") : admin_url("options-general.php"))."' class='bold'>".$admin_email."</a>")."</p>";

		$intEmailID = $wpdb->get_var($wpdb->prepare("SELECT emailID FROM ".$wpdb->base_prefix."email WHERE emailAddress = %s AND emailSmtpServer != ''", $admin_email));

		if($intEmailID > 0)
		{
			echo "<p>".sprintf(__("The e-mail %s already has an account where you have set an SMTP", 'lang_email'), "<a href='".admin_url("admin.php?page=mf_email/create/index.php&intEmailID=".$intEmailID)."' class='bold'>".$admin_email."</a>")."</p>";
		}
	}

	function setting_smtp_server_callback()
	{
		$setting_key = get_setting_key(__FUNCTION__);
		$option = get_option($setting_key);

		echo show_textfield(array('name' => $setting_key, 'value' => $option));
	}

	function setting_smtp_port_callback()
	{
		$setting_key = get_setting_key(__FUNCTION__);
		$option = get_option($setting_key);

		echo show_textfield(array('type' => 'number', 'name' => $setting_key, 'value' => $option));
	}

	function setting_smtp_ssl_callback()
	{
		$setting_key = get_setting_key(__FUNCTION__);
		$option = get_option($setting_key);

		echo show_select(array('data' => $this->get_ssl_for_select(), 'name' => $setting_key, 'value' => $option));
	}

	function setting_smtp_username_callback()
	{
		$setting_key = get_setting_key(__FUNCTION__);
		$option = get_option($setting_key);

		echo show_textfield(array('name' => $setting_key, 'value' => $option, 'xtra' => " autocomplete='off'"));
	}

	function setting_smtp_password_callback()
	{
		$setting_key = get_setting_key(__FUNCTION__);
		$option = get_option($setting_key);

		echo show_password_field(array('name' => $setting_key, 'value' => $option, 'xtra' => " autocomplete='off'"));
	}

	function setting_smtp_test_callback()
	{
		echo show_textfield(array('name' => 'smtp_to', 'value' => '', 'placeholder' => __("E-mail to send test message to", 'lang_email')))
		."<div class='form_buttons'>"
			.show_button(array('type' => 'button', 'name' => 'btnSmtpTest', 'text' => __("Send", 'lang_email'), 'class' => 'button-secondary'))
		."</div>
		<div id='smtp_debug'></div>";
	}

	function admin_init()
	{
		global $pagenow;

		$plugin_base_include_url = plugins_url()."/mf_base/include/";
		$plugin_include_url = plugin_dir_url(__FILE__);
		$plugin_version = get_plugin_version(__FILE__);

		if(IS_EDITOR)
		{
			mf_enqueue_script('jquery-ui-autocomplete');
			mf_enqueue_script('script_email', $plugin_include_url."script_wp.js", array('admin_url' => admin_url("admin.php?page=mf_email/send/index.php"), 'plugin_url' => $plugin_include_url, 'ajax_url' => admin_url('admin-ajax.php')), $plugin_version);
		}

		if($pagenow == 'admin.php' && check_var('page') == 'mf_email/list/index.php')
		{
			mf_enqueue_style('style_email_wp', $plugin_include_url."style_wp.css", $plugin_version);
			mf_enqueue_style('style_bb', $plugin_base_include_url."backbone/style.css", $plugin_version);

			wp_enqueue_script('jquery-ui-draggable');
			wp_enqueue_script('jquery-ui-droppable');
			mf_enqueue_script('script_touch', $plugin_base_include_url."jquery.ui.touch-punch.min.js", $plugin_version);

			mf_enqueue_script('underscore');
			mf_enqueue_script('backbone');
			mf_enqueue_script('script_base_plugins', $plugin_base_include_url."backbone/bb.plugins.js", $plugin_version);
			//mf_enqueue_script('script_email_plugins', $plugin_include_url."backbone/bb.plugins.js", $plugin_version);
			mf_enqueue_script('script_email_router', $plugin_include_url."backbone/bb.router.js", $plugin_version);
			mf_enqueue_script('script_email_models', $plugin_include_url."backbone/bb.models.js", array('plugin_url' => $plugin_include_url), $plugin_version);
			mf_enqueue_script('script_email_views', $plugin_include_url."backbone/bb.views.js", array('emails2show' => EMAILS2SHOW), $plugin_version);
			mf_enqueue_script('script_base_init', $plugin_base_include_url."backbone/bb.init.js", $plugin_version);
		}
	}

	function count_unread_email()
	{
		global $wpdb;

		$count_message = "";

		$query_permission = " AND emailID IN ('".implode("','", get_email_accounts_permission())."')";

		$intUnread = $wpdb->get_var("SELECT COUNT(messageID) FROM ".$wpdb->base_prefix."email_message INNER JOIN ".$wpdb->base_prefix."email_folder USING (folderID) WHERE messageRead = '0' AND folderType != '3'".$query_permission);

		if($intUnread > 0)
		{
			$count_message = "&nbsp;<span class='update-plugins' title='".__("Unread", 'lang_email')."'>
				<span>".$intUnread."</span>
			</span>";
		}

		return $count_message;
	}

	function admin_menu()
	{
		$menu_root = 'mf_email/';
		$menu_start = $menu_root."list/index.php";
		$menu_capability = override_capability(array('page' => $menu_start, 'default' => 'edit_posts'));

		if($this->has_accounts())
		{
			$count_message = $this->count_unread_email();

			$menu_title = __("E-mail", 'lang_email');
			add_menu_page("", $menu_title.$count_message, $menu_capability, $menu_start, '', 'dashicons-email-alt', 99);

			$menu_title = __("Inbox", 'lang_email');
			add_submenu_page($menu_start, $menu_title, $menu_title, $menu_capability, $menu_start);

			$menu_title = __("Send New", 'lang_email');
			add_submenu_page($menu_start, $menu_title, $menu_title, $menu_capability, $menu_root."send/index.php");

			$menu_title = __("Accounts", 'lang_email');
			add_submenu_page($menu_start, $menu_title, $menu_title, $menu_capability, $menu_root."accounts/index.php");

			$menu_title = __("Add New Account", 'lang_email');
			add_submenu_page($menu_start, $menu_title, $menu_title, $menu_capability, $menu_root."create/index.php");

			$menu_title = __("Add New Folder", 'lang_email');
			add_submenu_page($menu_root, $menu_title, $menu_title, $menu_capability, $menu_root."folder/index.php");
		}

		else
		{
			$menu_start = $menu_root."accounts/index.php";

			$menu_title = __("E-mail", 'lang_email');
			add_menu_page("", $menu_title, $menu_capability, $menu_start, '', 'dashicons-email-alt', 99);

			$menu_title = __("Accounts", 'lang_email');
			add_submenu_page($menu_start, $menu_title, $menu_title, $menu_capability, $menu_start);

			$menu_title = __("Add New Account", 'lang_email');
			add_submenu_page($menu_start, $menu_title, $menu_title, $menu_capability, $menu_root."create/index.php");
		}
	}

	function get_user_notifications($array)
	{
		$update_email = $this->get_update_email();

		if($update_email != '')
		{
			$array[] = $update_email;
		}

		return $array;
	}

	/*function get_user_reminders($array)
	{
		global $wpdb;

		$user_id = $array['user_id'];
		$reminder_cutoff = $array['cutoff'];

		do_log("get_user_reminder_email was run for ".$user_id." (".$reminder_cutoff.")");

		$update_email = $this->get_update_email(array('cutoff' => $reminder_cutoff));

		if($update_email != '')
		{
			$array['reminder'][] = $update_email;
		}

		else
		{
			$query_permission = " AND emailID IN ('".implode("','", get_email_accounts_permission())."')";

			$intUnread = $wpdb->get_var("SELECT COUNT(messageID) FROM ".$wpdb->base_prefix."email_message INNER JOIN ".$wpdb->base_prefix."email_folder USING (folderID) WHERE messageRead = '0' AND folderType != '3'".$query_permission);

			if($intUnread > 0)
			{
				$array['reminder'][] = array(
					'title' => $intUnread > 1 ? sprintf(__("There are %d unread emails in your inbox", 'lang_email'), $intUnread) : __("There is one unread email in your inbox", 'lang_email'),
					'link' => admin_url("admin.php?page=mf_email/list/index.php"),
				);
			}
		}

		return $array;
	}*/

	function deleted_user($user_id)
	{
		global $wpdb;

		$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email SET userID = '%d' WHERE userID = '%d'", get_current_user_id(), $user_id));
		$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email_users SET userID = '%d' WHERE userID = '%d'", get_current_user_id(), $user_id));
		$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email_folder SET userID = '%d' WHERE userID = '%d'", get_current_user_id(), $user_id));
		$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email_message SET userID = '%d' WHERE userID = '%d'", get_current_user_id(), $user_id));
	}

	function wp_mail_from($old)
	{
		return (substr($old, 0, 10) == "wordpress@" ? get_option('admin_email') : $old);
	}

	function wp_mail_from_name($old)
	{
		return ($old == "WordPress" ? get_option('blogname') : $old);
	}

	function phpmailer_init($phpmailer)
	{
		global $wpdb;

		$outgoing_type = 'smtp';
		$smtp_ssl = $smtp_host = $smtp_port = $smtp_hostname = $smtp_user = $smtp_pass = "";

		$result = $wpdb->get_results($wpdb->prepare("SELECT emailName, emailOutgoingType, emailSmtpSSL, emailSmtpServer, emailSmtpPort, emailSmtpHostname, emailSmtpUsername, emailSmtpPassword FROM ".$wpdb->base_prefix."email WHERE emailAddress = %s AND emailSmtpServer != ''", $phpmailer->From));

		if($wpdb->num_rows > 0)
		{
			foreach($result as $r)
			{
				$outgoing_type = $r->emailOutgoingType;
				$smtp_ssl = $r->emailSmtpSSL;
				$smtp_host = $r->emailSmtpServer;
				$smtp_port = $r->emailSmtpPort;
				$smtp_hostname = $r->emailSmtpHostname;
				$smtp_user = $r->emailSmtpUsername;
				$smtp_pass_encrypted = $r->emailSmtpPassword;

				$obj_encryption = new mf_email_encryption("email");
				$smtp_pass = $obj_encryption->decrypt($smtp_pass_encrypted, md5($phpmailer->From));

				$phpmailer->FromName = $r->emailName;
			}
		}

		else
		{
			$smtp_ssl = get_option('setting_smtp_ssl');
			$smtp_host = get_option('setting_smtp_server');
			$smtp_port = get_option('setting_smtp_port');
			$smtp_user = get_option('setting_smtp_username');
			$smtp_pass = get_option('setting_smtp_password');
		}

		switch($outgoing_type)
		{
			case 'smtp':
				$phpmailer->Sender = $phpmailer->From;

				if($smtp_host != '')
				{
					$phpmailer->SMTPDebug = defined('SMTPDebug') ? SMTPDebug : false;

					$phpmailer->Mailer = 'smtp';
					$phpmailer->SMTPSecure = $smtp_ssl;
					$phpmailer->Host = $smtp_host;

					if($smtp_port > 0)
					{
						$phpmailer->Port = $smtp_port;
					}

					if($smtp_hostname != '')
					{
						$phpmailer->Hostname = $smtp_hostname;
					}

					if($smtp_user != '' && $smtp_pass != '')
					{
						$phpmailer->SMTPAuth = true;
						$phpmailer->Username = $smtp_user;
						$phpmailer->Password = $smtp_pass;
					}

					// You can add your own options here, see the phpmailer documentation for more info: http://phpmailer.sourceforge.net/docs/
					$phpmailer = apply_filters('wp_mail_smtp_custom_options', $phpmailer);
				}
			break;

			default:
				do_action('email_outgoing_process', $outgoing_type, $smtp_user, $smtp_pass);
			break;
		}
	}

	function send_smtp_test()
	{
		global $phpmailer, $done_text, $error_text;

		$mail_to = check_var('smtp_to', 'email');

		if($mail_to != '')
		{
			$mail_subject = sprintf(__("Test mail to %s", 'lang_email'), $mail_to);
			$mail_content = sprintf(__("This is a test email generated from %s on %s", 'lang_email'), "Wordpress", remove_protocol(array('url' => get_site_url(), 'clean' => true)));

			DEFINE('SMTPDebug', 3);

			ob_start();

				$sent = send_email(array('to' => $mail_to, 'subject' => $mail_subject, 'content' => $mail_content));

			$smtp_debug = ob_get_clean();

			if($sent == true)
			{
				$done_text = "<p><strong>".__("The test message was sent successfully", 'lang_email')."</strong></p>";
			}

			else
			{
				$error_text = "<p><strong>".__("I am sorry, but I could not send the message for you", 'lang_email')."</strong></p>"
				."<p>".__("More information regarding this is saved in the log", 'lang_email')."</p>";

				/*$error_text .= "<p>".__("The result I got back was", 'lang_email').":</p>
				<pre>".var_export($sent, true)."</pre>";*/

				/*$error_text .= "<p>".__("PHPmailer debug", 'lang_email').":</p>
				<pre>".var_export($phpmailer, true)."</pre>";*/

				if($smtp_debug != '')
				{
					$error_text .= "<p>".__("SMTP debug", 'lang_email').":</p>
					<pre>".$smtp_debug."</pre>";
				}
			}

			$out = get_notification();

			if($out != '')
			{
				$result['success'] = true;
				$result['message'] = $out;
			}

			else
			{
				$result['error'] = __("I could not send the test email. Please make sure that the credentials are correct", 'lang_email');
			}
		}

		else
		{
			$result['error'] = __("You did not enter a valid email address. Please do and try again", 'lang_email');
		}

		header('Content-Type: application/json');
		echo json_encode($result);
		die();
	}

	function fetch_request()
	{
		switch($this->type)
		{
			case 'account_create':
				$this->public = check_var('intEmailPublic');
				$this->roles = check_var('arrEmailRoles');
				$this->users = check_var('arrEmailUsers');
				$this->server = check_var('strEmailServer');
				$this->port = check_var('intEmailPort');
				$this->username = check_var('strEmailUsername');
				$this->password = check_var('strEmailPassword');
				$this->address = check_var('strEmailAddress');
				$this->name = check_var('strEmailName');

				$this->outgoing_type = check_var('strEmailOutgoingType');
				$this->smtp_server = check_var('strEmailSmtpServer');
				$this->smtp_port = check_var('intEmailSmtpPort');
				$this->smtp_ssl = check_var('strEmailSmtpSSL');
				$this->smtp_hostname = check_var('strEmailSmtpHostname');
				$this->smtp_username = check_var('strEmailSmtpUsername');
				$this->smtp_password = check_var('strEmailSmtpPassword');

				$this->password_encrypted = $this->smtp_password_encrypted = "";
			break;
		}
	}

	function save_data()
	{
		global $wpdb, $error_text, $done_text;

		$out = "";

		switch($this->type)
		{
			case 'account_create':
				if(isset($_POST['btnEmailCreate']) && wp_verify_nonce($_POST['_wpnonce_email_create'], 'email_create_'.$this->id))
				{
					if($this->id > 0)
					{
						$updated = $this->update_account();

						if($updated == true)
						{
							$type = "updated";
						}

						else
						{
							$error_text = __("The email account was not updated", 'lang_email');
						}
					}

					else
					{
						if($this->check_if_account_exists() > 0)
						{
							$error_text = __("The email account already exists", 'lang_email');
						}

						else
						{
							$this->create_account();

							if($this->id > 0)
							{
								$type = "created";
							}

							else
							{
								$error_text = __("The email account could not be created", 'lang_email');
							}
						}
					}

					if($this->id > 0)
					{
						$wpdb->query($wpdb->prepare("DELETE FROM ".$wpdb->base_prefix."email_users WHERE emailID = '%d'", $this->id));

						if(is_array($this->users))
						{
							foreach($this->users as $intUserID)
							{
								$wpdb->query($wpdb->prepare("INSERT INTO ".$wpdb->base_prefix."email_users SET emailID = '%d', userID = '%d'", $this->id, $intUserID));
							}
						}
					}

					if(!isset($error_text) || $error_text == '')
					{
						mf_redirect(admin_url("admin.php?page=mf_email/accounts/index.php&".$type));
					}
				}
			break;

			case 'account_list':
				if(isset($_REQUEST['btnEmailDelete']) && $this->id > 0 && wp_verify_nonce($_REQUEST['_wpnonce_email_delete'], 'email_delete_'.$this->id))
				{
					$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email SET emailDeleted = '1', emailDeletedID = '%d', emailDeletedDate = NOW() WHERE blogID = '%d' AND emailID = '%d'", get_current_user_id(), $wpdb->blogid, $this->id));

					if($wpdb->rows_affected > 0)
					{
						$done_text = __("The e-mail account was deleted", 'lang_email');
					}

					else
					{
						$error_text = __("The e-mail account could not be deleted", 'lang_email');
					}
				}

				else if(isset($_REQUEST['btnEmailConfirm']) && $this->id > 0 && wp_verify_nonce($_REQUEST['_wpnonce_email_confirm'], 'email_confirm_'.$this->id))
				{
					$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email SET blogID = '%d', emailVerified = '1' WHERE emailID = '%d'", $wpdb->blogid, $this->id));

					$done_text = __("The e-mail account was confirmed", 'lang_email');
				}

				else if(isset($_REQUEST['btnEmailVerify']) && $this->id > 0 && wp_verify_nonce($_REQUEST['_wpnonce_email_verify'], 'email_verify_'.$this->id))
				{
					$result = $wpdb->get_results($wpdb->prepare("SELECT emailVerified, emailServer, emailPort, emailUsername, emailPassword, emailAddress FROM ".$wpdb->base_prefix."email WHERE blogID = '%d' AND emailID = '%d'", $wpdb->blogid, $this->id));

					foreach($result as $r)
					{
						$intEmailVerified = $r->emailVerified;
						$strEmailServer = $r->emailServer;
						$intEmailPort = $r->emailPort;
						$strEmailUsername = $r->emailUsername;
						$strEmailPassword = $r->emailPassword;
						$strEmailAddress = $r->emailAddress;

						if($intEmailVerified != 1)
						{
							if($strEmailServer != '' && $strEmailUsername != '')
							{
								if($strEmailPassword != '')
								{
									$obj_encryption = new mf_email_encryption("email");
									$strEmailPassword = $obj_encryption->decrypt($strEmailPassword, md5($strEmailAddress));
								}

								list($is_connected, $connection) = $this->email_connect(array('server' => $strEmailServer, 'port' => $intEmailPort, 'username' => $strEmailUsername, 'password' => $strEmailPassword));

								if($is_connected == true)
								{
									$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email SET emailVerified = '1' WHERE emailID = '%d'", $this->id));

									$done_text = __("The e-mail account passed verification", 'lang_email');
								}

								else
								{
									$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email SET emailVerified = '-1' WHERE emailID = '%d'", $this->id));

									$error_text = __("The e-mail account didn't pass the verification", 'lang_email');
								}
							}

							else
							{
								$user_data = get_userdata(get_current_user_id());

								$site_name = get_bloginfo('name');
								$site_url = get_site_url();
								$confirm_url = wp_nonce_url(admin_url("admin.php?page=mf_email/accounts/index.php&btnEmailConfirm&intEmailID=".$this->id), 'email_confirm_'.$this->id, '_wpnonce_email_confirm');

								$mail_to = $strEmailAddress;
								$mail_headers = "From: ".$user_data->display_name." <".$user_data->user_email.">\r\n";
								$mail_subject = sprintf(__("Please confirm your e-mail %s for use on %s", 'lang_email'), $strEmailAddress, $site_name);
								$mail_content = sprintf(__("We've gotten a request to confirm the address %s from a user at %s (<a href='%s'>%s</a>). If this is a valid request please click <a href='%s'>here</a> to confirm the use of your e-mail address to send messages", 'lang_email'), $strEmailAddress, $site_name, $site_url, $confirm_url);

								$sent = send_email(array('to' => $mail_to, 'subject' => $mail_subject, 'content' => $mail_content, 'headers' => $mail_headers));

								if($sent)
								{
									$done_text = sprintf(__("An e-mail with a confirmation link has been sent to %s", 'lang_email'), $strEmailAddress);
								}
							}
						}
					}
				}

				else if(isset($_GET['created']))
				{
					$done_text = __("The account was created", 'lang_email');
				}

				else if(isset($_GET['updated']))
				{
					$done_text = __("The account was updated", 'lang_email');
				}
			break;
		}

		return $out;
	}

	function get_from_db()
	{
		global $wpdb;

		switch($this->type)
		{
			case 'account_create':
				if($this->id > 0)
				{
					$result = $wpdb->get_results($wpdb->prepare("SELECT emailPublic, emailRoles, emailServer, emailPort, emailUsername, emailAddress, emailName, emailOutgoingType, emailSmtpSSL, emailSmtpServer, emailSmtpPort, emailSmtpHostname, emailSmtpUsername, emailDeleted FROM ".$wpdb->base_prefix."email WHERE emailID = '%d'", $this->id));

					foreach($result as $r)
					{
						$this->public = $r->emailPublic;
						$this->roles = explode(",", $r->emailRoles);
						$this->server = $r->emailServer;
						$this->port = $r->emailPort;
						$this->username = $r->emailUsername;
						$this->address = $r->emailAddress;
						$this->name = $r->emailName;
						$this->outgoing_type = $r->emailOutgoingType;
						$this->smtp_ssl = $r->emailSmtpSSL;
						$this->smtp_server = $r->emailSmtpServer;
						$this->smtp_port = $r->emailSmtpPort;
						$this->smtp_hostname = $r->emailSmtpHostname;
						$this->smtp_username = $r->emailSmtpUsername;
						$this->deleted = $r->emailDeleted;

						$this->users = array();

						$resultUsers = $wpdb->get_results($wpdb->prepare("SELECT userID FROM ".$wpdb->base_prefix."email_users WHERE emailID = '%d'", $this->id));

						foreach($resultUsers as $r)
						{
							$this->users[] = $r->userID;
						}

						if($this->deleted == 1)
						{
							$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email SET emailDeleted = '0', emailDeletedID = '', emailDeletedDate = '' WHERE emailID = '%d' AND userID = '%d'", $this->id, get_current_user_id()));
						}
					}
				}
			break;
		}
	}

	function validate_email_string($in)
	{
		$out = "";

		$arr_emails = explode(" ", $in);

		foreach($arr_emails as $email)
		{
			$email = trim($email, ",");
			$email = trim($email, ";");

			if($email != '') //is_domain_valid($email)
			{
				$out .= ($out != '' ? ", " : "").$email;
			}
		}

		return $out;
	}

	function has_accounts()
	{
		$result = $this->get_account_amount();

		return (count($result) > 0 ? true : false);
	}

	function get_account_amount($query_xtra = '')
	{
		global $wpdb;

		return $wpdb->get_results("SELECT emailID FROM ".$wpdb->base_prefix."email_users RIGHT JOIN ".$wpdb->base_prefix."email USING (emailID) WHERE (emailPublic = '1' OR emailRoles LIKE '%".get_current_user_role()."%' OR ".$wpdb->base_prefix."email.userID = '".get_current_user_id()."' OR ".$wpdb->base_prefix."email_users.userID = '".get_current_user_id()."') AND (blogID = '".$wpdb->blogid."' OR blogID = '0') AND emailDeleted = '0'".$query_xtra." GROUP BY emailID");
	}

	function get_message_amount($id)
	{
		global $wpdb;

		$array = array();

		$intFolderID = get_folder_ids(__("Sent", 'lang_email'), 4, $id);

		$array['sent'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(messageID) FROM ".$wpdb->base_prefix."email_message WHERE folderID = '%d' AND messageDeleted = '0'", $intFolderID));

		$array['received'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(messageID) FROM ".$wpdb->base_prefix."email_message INNER JOIN ".$wpdb->base_prefix."email_folder USING (folderID) WHERE emailID = '%d' AND messageDeleted = '0'", $id));

		$array['received'] -= $array['sent'];

		return $array;
	}

	function get_message_info()
	{
		global $wpdb;

		$result = $wpdb->get_results($wpdb->prepare("SELECT emailID, messageFrom FROM ".$wpdb->base_prefix."email_message INNER JOIN ".$wpdb->base_prefix."email_folder USING (folderID) WHERE messageID = '%d'", $this->message_id));

		foreach($result as $r)
		{
			$this->id = $r->emailID;
			$this->from_address = $r->messageFrom;
		}
	}

	function get_email_id($message_id = 0)
	{
		$this->message_id = $message_id;

		if(!($this->id > 0))
		{
			$this->get_message_info();
		}

		return $this->id;
	}

	function get_from_address($message_id = 0)
	{
		$this->message_id = $message_id;

		if($this->from_address != '')
		{
			$this->get_message_info();
		}

		return $this->from_address;
	}

	function get_from_last()
	{
		global $wpdb;

		return $wpdb->get_var($wpdb->prepare("SELECT emailID FROM ".$wpdb->base_prefix."email INNER JOIN ".$wpdb->base_prefix."email_message ON ".$wpdb->base_prefix."email.emailAddress = ".$wpdb->base_prefix."email_message.messageFrom WHERE blogID = '%d' ORDER BY messageCreated DESC LIMIT 0, 1", $wpdb->blogid));
	}

	function get_from_for_select($data = array())
	{
		global $wpdb;

		if(!isset($data['index'])){	$data['index'] = 'id';}
		if(!isset($data['type'])){	$data['type'] = 'all';} //incoming, outgoing

		$arr_data = array(
			'' => "-- ".__("Choose Here", 'lang_email')." --"
		);

		switch($data['type'])
		{
			case 'incoming':
				$query_where = " AND emailServer != ''";
				$allow_fallback = true;
			break;

			case 'outgoing':
				$query_where = " AND emailSmtpServer != ''";
				$allow_fallback = false;
			break;

			case 'abuse':
				$query_where = " AND (emailAddress LIKE 'abuse@%' OR emailAddress LIKE 'postmaster@%')";
				$allow_fallback = false;
			break;

			default:
				$query_where = "";
				$allow_fallback = true;
			break;
		}

		$result = $wpdb->get_results("SELECT ".$wpdb->base_prefix."email.emailID, emailName, emailAddress FROM ".$wpdb->base_prefix."email_users RIGHT JOIN ".$wpdb->base_prefix."email USING (emailID) WHERE (emailPublic = '1' OR emailRoles LIKE '%".get_current_user_role()."%' OR ".$wpdb->base_prefix."email.userID = '".get_current_user_id()."' OR ".$wpdb->base_prefix."email_users.userID = '".get_current_user_id()."') AND (blogID = '".$wpdb->blogid."' OR blogID = '0') AND emailDeleted = '0'".$query_where." ORDER BY emailName ASC, emailAddress ASC");

		foreach($result as $r)
		{
			$intEmailID2 = $r->emailID;
			$strEmailName = $strEmailName_orig = $r->emailName;
			$strEmailAddress = $r->emailAddress;

			$strEmailName = $strEmailName != '' ? $strEmailName." &lt;".$strEmailAddress."&gt;" : $strEmailAddress;

			switch($data['index'])
			{
				case 'id':
					$arr_data[$intEmailID2] = $strEmailName;
				break;

				case 'address':
					$arr_data[$strEmailName_orig."|".$strEmailAddress] = $strEmailName;
				break;
			}
		}

		if(count($arr_data) <= 1 && $allow_fallback == true)
		{
			$current_user = wp_get_current_user();

			$user_name = $current_user->display_name;
			$user_email = $current_user->user_email;
			$admin_name = get_bloginfo('name');
			$admin_email = get_bloginfo('admin_email');

			$this->fetch_request();

			if($user_email != '')
			{
				$this->name = $user_name;
				$this->address = $user_email;
				$this->users = array(get_current_user_id());

				$this->id = $this->check_if_account_exists();

				if(!($this->id > 0))
				{
					$this->create_account();
				}

				if($this->id > 0)
				{
					switch($data['index'])
					{
						case 'id':
							$arr_data[$this->id] = $user_name." &lt;".$user_email."&gt;";
						break;

						case 'address':
							$arr_data[$user_name."|".$user_email] = $user_name." &lt;".$user_email."&gt;";
						break;
					}
				}
			}

			if($admin_email != '' && $admin_email != $user_email)
			{
				$this->public = 1;
				$this->name = $admin_name;
				$this->address = $admin_email;

				$this->id = $this->check_if_account_exists();

				if(!($this->id > 0))
				{
					$this->create_account();
				}

				if($this->id > 0)
				{
					switch($data['index'])
					{
						case 'id':
							$arr_data[$this->id] = $admin_name." &lt;".$admin_email."&gt;";
						break;

						case 'address':
							$arr_data[$admin_name."|".$admin_email] = $admin_name." &lt;".$admin_email."&gt;";
						break;
					}
				}
			}
		}

		return $arr_data;
	}

	function check_if_spam($data)
	{
		global $wpdb;

		$wpdb->get_results($wpdb->prepare("SELECT spamID FROM ".$wpdb->base_prefix."email_spam WHERE emailID = '%d' AND messageFrom = %s LIMIT 0, 1", $this->id, $data['from']));

		return $wpdb->num_rows > 0 ? true : false;
	}

	function encrypt_password()
	{
		$obj_encryption = new mf_email_encryption("email");

		if($this->password != '' && $this->address != '')
		{
			$this->password_encrypted = $obj_encryption->encrypt($this->password, md5($this->address));
		}

		if($this->smtp_password != '' && $this->address != '')
		{
			$this->smtp_password_encrypted = $obj_encryption->encrypt($this->smtp_password, md5($this->address));
		}
	}

	function check_if_account_exists()
	{
		global $wpdb;

		$intEmailID = $wpdb->get_var($wpdb->prepare("SELECT emailID FROM ".$wpdb->base_prefix."email WHERE emailAddress = %s", $this->address));

		return $intEmailID;
	}

	function filter_text($string)
	{
		//$string = preg_replace("/\@media.*\}/s", "", $string);
		$string = preg_replace("/<title>.*?<\/title>/is", "", $string);
		$string = preg_replace("/<meta[^>]*>/i", "", $string);
		$string = preg_replace("/<style[^>]*>.*?<\/style>/is", "", $string);
		$string = preg_replace("/<img[^>]*(height|width)=[\"\']1[\"\'][^>]*>/is", "", $string);
		$string = preg_replace("/<!--(.*?)-->/is", "", $string); // /<!--[\s\S]*?-->/g

		return $string;
	}

	function update_passwords()
	{
		global $wpdb;

		$rows_affected = 0;

		if($this->password_encrypted != '')
		{
			if(strlen($this->password_encrypted) > 150)
			{
				do_log(__("The encrypted password was longer than the max length in DB", 'lang_email')." (".strlen($this->password_encrypted).")");
			}

			$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email SET emailPassword = %s WHERE emailID = '%d'", $this->password_encrypted, $this->id)); // AND userID = '%d', get_current_user_id()

			$rows_affected += $wpdb->rows_affected;
		}

		if($this->smtp_password_encrypted != '')
		{
			if(strlen($this->smtp_password_encrypted) > 150)
			{
				do_log(__("The encrypted password was longer than the max length in DB", 'lang_email')." (".strlen($this->smtp_password_encrypted).")");
			}

			$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email SET emailSmtpPassword = %s WHERE emailID = '%d'", $this->smtp_password_encrypted, $this->id)); // AND userID = '%d', get_current_user_id()

			$rows_affected += $wpdb->rows_affected;
		}

		return $rows_affected > 0;
	}

	function create_account()
	{
		global $wpdb;

		$this->encrypt_password();

		$wpdb->query($wpdb->prepare("INSERT INTO ".$wpdb->base_prefix."email SET blogID = '%d', emailPublic = '%d', emailRoles = %s, emailServer = %s, emailPort = '%d', emailUsername = %s, emailAddress = %s, emailName = %s, emailOutgoingType = %s, emailSmtpSSL = %s, emailSmtpServer = %s, emailSmtpPort = '%d', emailSmtpHostname = %s, emailSmtpUsername = %s, emailCreated = NOW(), userID = '%d'", $wpdb->blogid, $this->public, @implode(",", $this->roles), $this->server, $this->port, $this->username, $this->address, $this->name, $this->outgoing_type, $this->smtp_ssl, $this->smtp_server, $this->smtp_port, $this->smtp_hostname, $this->smtp_username, get_current_user_id()));

		$this->id = $wpdb->insert_id;

		if($this->id > 0)
		{
			$updated = $this->update_passwords();
		}
	}

	function update_account()
	{
		global $wpdb;

		$this->encrypt_password();

		$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email SET blogID = '%d', emailPublic = '%d', emailRoles = %s, emailVerified = '0', emailServer = %s, emailPort = '%d', emailUsername = %s, emailAddress = %s, emailName = %s, emailOutgoingType = %s, emailSmtpSSL = %s, emailSmtpServer = %s, emailSmtpPort = '%d', emailSmtpHostname = %s, emailSmtpUsername = %s, emailDeleted = '0' WHERE emailID = '%d'", $wpdb->blogid, $this->public, @implode(",", $this->roles), $this->server, $this->port, $this->username, $this->address, $this->name, $this->outgoing_type, $this->smtp_ssl, $this->smtp_server, $this->smtp_port, $this->smtp_hostname, $this->smtp_username, $this->id));

		$rows_affected = $wpdb->rows_affected;

		$updated = $this->update_passwords();

		if($rows_affected > 0 || $updated == true)
		{
			return true;
		}

		else
		{
			return false;
		}
	}
}

class mf_email_encryption
{
	function __construct($type)
	{
		$this->set_key($type);
		$this->iv = mcrypt_create_iv(mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_ECB), MCRYPT_RAND);
	}

	function set_key($type)
	{
		$this->key = substr("mf_crypt".$type, 0, 32);
	}

	function encrypt($text, $key = "")
	{
		if($key != '')
		{
			$this->set_key($key);
		}

		return base64_encode(mcrypt_encrypt(MCRYPT_RIJNDAEL_256, $this->key, $text, MCRYPT_MODE_ECB, $this->iv));
	}

	function decrypt($text, $key = "")
	{
		if($key != '')
		{
			$this->set_key($key);
		}

		return trim(mcrypt_decrypt(MCRYPT_RIJNDAEL_256, $this->key, base64_decode($text), MCRYPT_MODE_ECB, $this->iv));
	}
}

class mf_email_account_table extends mf_list_table
{
	function set_default()
	{
		global $wpdb;

		$this->arr_settings['query_from'] = $wpdb->base_prefix."email";
		$this->post_type = "";

		$this->arr_settings['query_select_id'] = "emailID";
		$this->arr_settings['query_all_id'] = "0";
		$this->arr_settings['query_trash_id'] = "1";
		$this->orderby_default = "emailDeleted ASC, ".$wpdb->base_prefix."email.userID ASC, emailUsername";

		//$this->arr_settings['has_autocomplete'] = true;
		//$this->arr_settings['plugin_name'] = 'mf_email';

		$this->query_join .= " LEFT JOIN ".$wpdb->base_prefix."email_users USING (emailID)";
		$this->query_where .= ($this->query_where != '' ? " AND " : "")."(emailPublic = '1' OR emailRoles LIKE '%".get_current_user_role()."%' OR ".$wpdb->base_prefix."email.userID = '".get_current_user_id()."' OR ".$wpdb->base_prefix."email_users.userID = '".get_current_user_id()."') AND (blogID = '".$wpdb->blogid."' OR blogID = '0')";

		if($this->search != '')
		{
			$this->query_where .= ($this->query_where != '' ? " AND " : "")."(emailAddress LIKE '%".$this->search."%' OR emailName LIKE '%".$this->search."%' OR emailUsername LIKE '%".$this->search."%' OR emailServer LIKE '%".$this->search."%')";
		}

		$this->set_views(array(
			'db_field' => 'emailDeleted',
			'types' => array(
				'0' => __("All", 'lang_email'),
				'1' => __("Trash", 'lang_email')
			),
		));

		$arr_columns = array(
			//'cb' => '<input type="checkbox">',
			'emailAddress' => __("Address", 'lang_email'),
			'emailName' => __("Name", 'lang_email'),
			'rights' => shorten_text(array('string' => __("Rights", 'lang_email'), 'limit' => 3)),
			//'emailPublic' => __("Public", 'lang_email'),
			//'emailRoles' => __("Roles", 'lang_email'),
			//'emailUsers' => __("Users", 'lang_email'),
			'emailServer' => __("Incoming", 'lang_email'),
			'emailChecked' => __("Status", 'lang_email'),
			'emailSmtpServer' => __("Outgoing", 'lang_email'),
		);

		$this->set_columns($arr_columns);

		$this->set_sortable_columns(array(
			'emailAddress',
			'emailName',
			'emailServer',
			'emailSmtpServer',
		));
	}

	function column_default($item, $column_name)
	{
		global $wpdb, $obj_group;

		$out = "";

		$intEmailID = $item['emailID'];

		switch($column_name)
		{
			case 'emailAddress':
				$strEmailAddress = $item[$column_name];
				$intUserID = $item['userID'];
				$intEmailDeleted = $item['emailDeleted'];

				$out .= "<a href='?page=mf_email/create/index.php&intEmailID=".$intEmailID."'>"
					.$strEmailAddress
				."</a>
				<div class='row-actions'>";

					if($intEmailDeleted == 0)
					{
						$out .= "<a href='?page=mf_email/create/index.php&intEmailID=".$intEmailID."'>".__("Edit", 'lang_email')."</a>";

						if($intUserID == get_current_user_id())
						{
							$out .= " | <a href='".wp_nonce_url(admin_url("admin.php?page=mf_email/accounts/index.php&btnEmailDelete&intEmailID=".$intEmailID), 'email_delete_'.$intEmailID, '_wpnonce_email_delete')."' rel='confirm'>".__("Delete", 'lang_email')."</a>";
						}
					}

					else
					{
						$out .= "<a href='?page=mf_email/create/index.php&intEmailID=".$intEmailID."'>".__("Recover", 'lang_email')."</a>";
					}

				$out .= "</div>";
			break;

			case 'emailName':
				$out .= $item[$column_name];

				$actions = array();

				$obj_email = new mf_email();
				$arr_message_amount = $obj_email->get_message_amount($intEmailID);

				if($arr_message_amount['received'] > 0)
				{
					$actions['received'] = __("Received", 'lang_email').": ".$arr_message_amount['received'];
				}

				if($arr_message_amount['sent'] > 0)
				{
					$actions['sent'] = __("Sent", 'lang_email').": ".$arr_message_amount['sent'];
				}

				$out .= $this->row_actions($actions);
			break;

			case 'rights':
				$intEmailPublic = $item['emailPublic'];
				$strEmailRoles = $item['emailRoles'];

				if($intEmailPublic == 1)
				{
					$out .= "<i class='fa fa-lg fa-check green'></i>";
				}

				else if($strEmailRoles != '')
				{
					$out .= "<i class='fa fa-lg fa-users' title='".$strEmailRoles."'></i>";
				}

				else
				{
					$strEmailUsers = "";

					$resultUsers = $wpdb->get_results($wpdb->prepare("SELECT userID FROM ".$wpdb->base_prefix."email_users WHERE emailID = '%d'", $intEmailID));

					foreach($resultUsers as $r)
					{
						$strEmailUsers .= ($strEmailUsers != '' ? ", " : "").get_user_info(array('id' => $r->userID));
					}

					if($strEmailUsers != '')
					{
						$out .= "<i class='fa fa-lg fa-users' title='".$strEmailUsers."'></i>";
					}
				}
			break;

			case 'emailServer':
				$strEmailServer = $item[$column_name];

				if($strEmailServer != '')
				{
					$row_actions = "";

					$out .= "<span class='nowrap'>";

						switch($item['emailVerified'])
						{
							default:
							case 0:
								$out .= "<i class='fa fa-lg fa-question'></i>&nbsp;";

								$row_actions .= ($row_actions != '' ? " | " : "")."<a href='".wp_nonce_url(admin_url("admin.php?page=mf_email/accounts/index.php&btnEmailVerify&intEmailID=".$intEmailID), 'email_verify_'.$intEmailID, '_wpnonce_email_verify')."'>".__("Verify", 'lang_email')."</a>";
							break;

							case 1:
								$out .= "<i class='fa fa-lg fa-check green'></i>&nbsp;";
							break;

							case -1:
								$out .= "<span class='fa-stack'>
									<i class='fa fa-search fa-stack-1x'></i>
									<i class='fa fa-ban fa-stack-2x red'></i>
								</span>&nbsp;";
							break;
						}

						$row_actions .= ($row_actions != '' ? " | " : "").$item['emailUsername'];

						$out .= $strEmailServer.":".$item['emailPort']
					."</span>"
					."<div class='row-actions'>"
						.$row_actions
					."</div>";
				}
			break;

			case 'emailChecked':
				$dteEmailChecked = $item[$column_name];

				if($dteEmailChecked > DEFAULT_DATE && $item['emailServer'] != '')
				{
					if($dteEmailChecked < date("Y-m-d H:i:s", strtotime("-1 day")))
					{
						$out .= "<i class='fa fa-lg fa-ban red'></i>"
						."<div class='row-actions'>".sprintf(__("Not been checked since %s", 'lang_email'), format_date($dteEmailChecked))."</div>";
					}

					else
					{
						$dteEmailReceived = $wpdb->get_var($wpdb->prepare("SELECT messageReceived FROM ".$wpdb->base_prefix."email_folder INNER JOIN ".$wpdb->base_prefix."email_message USING (folderID) WHERE emailID = '%d' ORDER BY messageReceived DESC LIMIT 0, 1", $intEmailID));

						if($dteEmailReceived > DEFAULT_DATE)
						{
							if($dteEmailReceived < date("Y-m-d H:i:s", strtotime("-3 day")))
							{
								$out .= "<i class='fa fa-lg fa-ban red'></i>"
								."<div class='row-actions'>".sprintf(__("No e-mails since %s", 'lang_email'), format_date($dteEmailReceived))."</div>";
							}

							else
							{
								$out .= "<i class='fa fa-lg fa-check green'></i> ".format_date($dteEmailReceived)
								."<div class='row-actions'>".__("Checked", 'lang_email')." ".format_date($dteEmailChecked)."</div>";
							}
						}

						else
						{
							$out .= "<i class='fa fa-question-circle fa-lg'></i>"
							."<div class='row-actions'>".__("No e-mails so far", 'lang_email')."</div>";
						}
					}
				}

				else if($item['emailVerified'] > 0)
				{
					$out .= "<i class='fa fa-spinner fa-spin fa-lg'></i>
					<div class='row-actions'>".__("The e-mail has not been checked yet", 'lang_email')."</div>";
				}
			break;

			case 'emailSmtpServer':
				$strEmailSmtpServer = $item[$column_name];

				if($strEmailSmtpServer != '')
				{
					switch($item['emailOutgoingType'])
					{
						case 'smtp':
							$out .= $strEmailSmtpServer.":".$item['emailSmtpPort'];
						break;

						default:
							$out .= apply_filters('get_email_outgoing_alternative', $item['emailOutgoingType']);
						break;
					}

					$out .= "<div class='row-actions'>"
						.$item['emailSmtpUsername']
					."</div>";
				}
			break;

			default:
				if(isset($item[$column_name]))
				{
					$out .= $item[$column_name];
				}
			break;
		}

		return $out;
	}
}