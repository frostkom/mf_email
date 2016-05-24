<?php

function get_user_notifications_email($arr_notifications)
{
	global $wpdb;

	$arr_email_accounts_permission = get_email_accounts_permission();
	$query_permission = " AND emailID IN ('".implode("','", $arr_email_accounts_permission)."')";

	$intUnread = $wpdb->get_var("SELECT COUNT(messageID) FROM ".$wpdb->base_prefix."email_message INNER JOIN ".$wpdb->base_prefix."email_folder USING (folderID) WHERE messageRead = '0' AND folderType != '3' AND messageCreated > DATE_SUB(NOW(), INTERVAL 2 MINUTE)".$query_permission);

	if($intUnread > 0)
	{
		$arr_notifications[] = array(
			'title' => $intUnread > 1 ? sprintf(__("There are %d new emails in your inbox", 'lang_email'), $intUnread) : __("There is one new email in your inbox", 'lang_email'),
			'tag' => 'email',
			//'text' => "",
			//'icon' => "",
			'link' => admin_url("admin.php?page=mf_email/list/index.php"),
		);
	}

	return $arr_notifications;
}

function deleted_user_email($user_id)
{
	global $wpdb;

	$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email SET userID = '%d' WHERE userID = '%d'", get_current_user_id(), $user_id));
	$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email_users SET userID = '%d' WHERE userID = '%d'", get_current_user_id(), $user_id));
	$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email_folder SET userID = '%d' WHERE userID = '%d'", get_current_user_id(), $user_id));
	$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email_message SET userID = '%d' WHERE userID = '%d'", get_current_user_id(), $user_id));
}

function is_class_method($class, $method, $type)
{
	$refl = new ReflectionMethod($class, $method);

	switch($type)
	{
		case "static":
			return $refl->isStatic();
		break;

		case "public":
			return $refl->isPublic();
		break;

		case "private":
			return $refl->isPrivate();
		break;
	}
}

function get_info_for_spam_check($message_id)
{
	global $wpdb;

	$arr_message = array();

	$result = $wpdb->get_results($wpdb->prepare("SELECT messageFrom, messageFromName, messageName, messageText, messageText2 FROM ".$wpdb->base_prefix."email_message WHERE messageID = '%d'", $message_id));

	foreach($result as $r)
	{
		$strMessageFrom = $r->messageFrom;
		$strMessageFromName = $r->messageFromName;
		$strMessageName = $r->messageName;
		$strMessageText = $r->messageText;
		$strMessageText2 = $r->messageText2;

		$arr_message = array(
			//'comment_author_IP' => $_SERVER['REMOTE_ADDR'],
			//'comment_author_url' => $,
			'comment_content' => $strMessageName.$strMessageText.$strMessageText2,
			'comment_author_email' => $strMessageFrom,
			'comment_author' => $strMessageFromName,
			//'comment_type' => 'pingback',
			//'akismet_pre_check' => '1',
			//'comment_pingback_target' => $args[1],
			//'comment_post_ID' => '',
		);
	}

	return $arr_message;
}

function check_spam_reason($message_id)
{
	global $wpdb;

	$out = "";

	$arr_message = get_info_for_spam_check($message_id);

	if(class_exists("Akismet"))
	{
		$akismet = new Akismet();

		$comment = $akismet->auto_check_comment($arr_message);

		$out = $comment['akismet_result'];
	}

	else if(class_exists("Antispam_Bee"))
	{
		$antispam_bee = new Antispam_Bee();

		if(is_class_method("Antispam_Bee", "_verify_comment_request", "private"))
		{
			$strMessageSpam = __("You have to edit the function to be public instead of private", 'lang_email')." (class Antispam_Bee, function _verify_comment_request())";
		}

		else
		{
			$reason = $antispam_bee->_verify_comment_request($arr_message);

			if($reason['reason'])
			{
				$reason = $reason['reason'];

				if(!preg_match("/[empty|null]/i", $reason))
				{
					$out = $reason['reason'];
				}
			}
		}
	}

	return $out;
}

function get_email_accounts_permission()
{
	global $wpdb;

	$out = array();

	$result = $wpdb->get_results("SELECT emailID FROM ".$wpdb->base_prefix."email_users RIGHT JOIN ".$wpdb->base_prefix."email USING (emailID) WHERE (emailPublic = '1' OR emailRoles LIKE '%".get_current_user_role()."%' OR ".$wpdb->base_prefix."email_users.userID = '".get_current_user_id()."' OR (".$wpdb->base_prefix."email.userID = '".get_current_user_id()."' AND ".$wpdb->base_prefix."email_users.userID IS null))");

	foreach($result as $r)
	{
		$out[] = $r->emailID;
	}

	return $out;
}

function convert_email_subject($data)
{
	$elements = imap_mime_header_decode($data['subject']);

	$out = "";

	$count_temp = count($elements);

	for($i = 0; $i < $count_temp; $i++)
	{
		$out .= $elements[$i]->text;
	}

	return $out;
}

function save_email($data)
{
	global $wpdb;

	if(!isset($data['id'])){			$data['id'] = 0;}
	if(!isset($data['text_id'])){		$data['text_id'] = "<".time().".".md5(mt_rand(1000, 9999))."@".get_site_url().">";}
	if(!isset($data['read'])){			$data['read'] = 0;}
	if(!isset($data['from'])){			$data['from'] = "";}
	if(!isset($data['from_name'])){		$data['from_name'] = "";}
	if(!isset($data['reply_to'])){		$data['reply_to'] = "";}
	if(!isset($data['content'])){		$data['content'] = "";}
	if(!isset($data['content_html'])){	$data['content_html'] = "";}
	if(!isset($data['created'])){		$data['created'] = date("Y-m-d H:i:s");}

	if($data['content_html'] == $data['content'])
	{
		$data['content_html'] = "";
	}

	if(!isset($data['md5'])){			$data['md5'] = md5($data['text_id'].$data['from'].$data['content'].$data['content_html']);}

	$data['size'] = strlen(implode("", $data));

	if($data['id'] > 0)
	{
		$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email_message SET messageTo = %s, messageCc = %s, messageReplyTo = %s, messageName = %s, messageText = %s, messageText2 = %s, messageCreated = NOW(), userID = '%d' WHERE messageID = '%d'", $data['to'], $data['cc'], $data['reply_to'], $data['subject'], $data['content'], $data['content_html'], get_current_user_id(), $data['id']));
	}

	else
	{
		$wpdb->query($wpdb->prepare("INSERT INTO ".$wpdb->base_prefix."email_message SET messageRead = '%d', folderID = '%d', messageTextID = %s, messageMd5 = %s, messageFrom = %s, messageFromName = %s, messageTo = %s, messageCc = %s, messageReplyTo = %s, messageName = %s, messageText = %s, messageText2 = %s, messageSize = '%d', messageCreated = %s, messageReceived = NOW(), userID = '%d'", $data['read'], $data['folder_id'], $data['text_id'], $data['md5'], $data['from'], $data['from_name'], $data['to'], $data['cc'], $data['reply_to'], $data['subject'], $data['content'], $data['content_html'], $data['size'], $data['created'], get_current_user_id()));

		$data['id'] = $wpdb->insert_id;
	}

	return array($data['id'], $wpdb->rows_affected);
}

function get_email_address_from_text($in)
{
	preg_match_all('/[a-z\d][-a-z\d._]+@[a-z\d][-a-z\d._]+\.[a-z]{2,6}/is', strtolower($in), $out);

	$out = array_unique($out);

	$output = "";

	$count_temp1 = count($out);

	for($i = 0; $i < $count_temp1; $i++)
	{
		$count_temp2 = count($out[$i]);

		for($j = 0; $j < $count_temp2; $j++)
		{
			if($out[$i][$j] != '')
			{
				$output .= ($i > 0 || $j > 0 ? " " : "").$out[$i][$j];
			}
		}
	}

	return $output;
}

function set_mail_info($data, &$json_output)
{
	global $wpdb;

	$query_xtra = "";

	if(isset($data['folder_id']))
	{
		$query_xtra .= ($query_xtra == '' ? "" : ", ")."folderID = '".esc_sql($data['folder_id'])."'";
	}

	if(isset($data['mail_read']))
	{
		$query_xtra .= ($query_xtra == '' ? "" : ", ")."messageRead = '".esc_sql($data['mail_read'])."'";
	}

	if(isset($data['mail_deleted']))
	{
		$intEmailID = $wpdb->get_var($wpdb->prepare("SELECT emailID FROM ".$wpdb->base_prefix."email_folder INNER JOIN ".$wpdb->base_prefix."email_message USING (folderID) WHERE messageID = '%d'", $data['message_id']));

		$query_xtra .= ($query_xtra == '' ? "" : ", ")."messageDeleted = '".esc_sql($data['mail_deleted'])."'";

		if($data['mail_deleted'] == 1)
		{
			$query_xtra .= ", messageDeletedDate = NOW(), messageDeletedID = '".get_current_user_id()."', messageRead = '1'";

			$intFolderID = get_folder_ids(__('Trash', 'lang_email'), 2, $intEmailID);
		}

		else
		{
			$query_xtra .= ", messageDeletedDate = '', messageDeletedID = ''";

			$intFolderID = get_folder_ids(__('Inbox', 'lang_email'), 6, $intEmailID);
		}

		$query_xtra .= ($query_xtra == '' ? "" : ", ")."folderID = '".esc_sql($intFolderID)."'";
	}

	if(isset($data['mail_spam']))
	{
		$intEmailID = $wpdb->get_var($wpdb->prepare("SELECT emailID FROM ".$wpdb->base_prefix."email_folder INNER JOIN ".$wpdb->base_prefix."email_message USING (folderID) WHERE messageID = '%d'", $data['message_id']));

		$query_xtra .= ($query_xtra == '' ? "" : ", ")."messageDeleted = '".esc_sql($data['mail_spam'])."'";

		if($data['mail_spam'] == 1)
		{
			$query_xtra .= ", messageDeletedDate = NOW(), messageDeletedID = '".get_current_user_id()."', messageRead = '1'";

			$intFolderID = get_folder_ids(__('Spam', 'lang_email'), 3, $intEmailID);
		}

		else
		{
			$query_xtra .= ", messageDeletedDate = '', messageDeletedID = ''";

			$intFolderID = get_folder_ids(__('Inbox', 'lang_email'), 6, $intEmailID);
		}

		$query_xtra .= ($query_xtra == '' ? "" : ", ")."folderID = '".esc_sql($intFolderID)."'";
	}

	if($query_xtra != '')
	{
		$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email_message SET ".$query_xtra." WHERE messageID = '%d'", $data['message_id']));

		if($wpdb->rows_affected > 0)
		{
			if(isset($data['mail_read']))
			{
				$json_output['next_request'] = "email/render_row/".$data['message_id'];
			}

			if(isset($data['mail_deleted']) || isset($data['mail_spam']) || isset($data['folder_id']))
			{
				$json_output['remove_id'] = "message".$data['message_id'];
			}
		}
	}
}

function mark_spam($data)
{
	global $wpdb;

	$obj_email = new mf_email();

	$intSpamID = 0;
	$intEmailID = $obj_email->get_email_id($data['message_id']);
	$strMessageFrom = $obj_email->get_from_address($data['message_id']);

	$intSpamID = $obj_email->check_if_spam(array('from' => $strMessageFrom));

	if($data['spam'] == true)
	{
		if($intSpamID > 0)
		{
			$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email_spam SET spamCount = (spamCount + 1) WHERE spamID = '%d'", $intSpamID));
		}

		else
		{
			$wpdb->query($wpdb->prepare("INSERT INTO ".$wpdb->base_prefix."email_spam SET emailID = '%d', messageFrom = %s, spamCount = 1", $intEmailID, $strMessageFrom));
		}
	}

	else
	{
		if($intSpamID > 0)
		{
			$wpdb->query($wpdb->prepare("DELETE FROM ".$wpdb->base_prefix."email_spam WHERE spamID = '%d'", $intSpamID));
		}

		else
		{
			//It doesn't exist so do nothing...
		}
	}
}

function get_folder_ids($name, $type, $intEmailID)
{
	global $wpdb;

	$strFolder = $type > 0 ? "folderType = '".esc_sql($type)."'" : "folderName = '".esc_sql($name)."'";

	$result = $wpdb->get_results($wpdb->prepare("SELECT folderID, emailID FROM ".$wpdb->base_prefix."email_folder WHERE folderDeleted = '0' AND emailID = '%d' AND ".$strFolder." LIMIT 0, 1", $intEmailID));

	if($wpdb->num_rows > 0)
	{
		$r = $result[0];
		$id = $r->folderID;

		if($r->emailID == 0)
		{
			$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email_folder SET emailID = '%d', userID = '0' WHERE folderID = '%d'", $intEmailID, $id));
		}
	}

	else
	{
		$intFolderID2 = $wpdb->get_var($wpdb->prepare("SELECT folderID2 FROM ".$wpdb->base_prefix."email_folder WHERE folderDeleted = '0' AND folderName = '".$name."' AND userID = '%d'", get_current_user_id()));

		$wpdb->query($wpdb->prepare("INSERT INTO ".$wpdb->base_prefix."email_folder SET folderID2 = '%d', folderType = %s, folderName = %s, emailID = '%d', folderCreated = NOW(), userID = '0'", $intFolderID2, $type, $name, $intEmailID));

		$id = $wpdb->insert_id;
	}

	return $id;
}

function count_unread_email()
{
	global $wpdb;

	$count_message = "";

	$arr_email_accounts_permission = get_email_accounts_permission();
	$query_permission = " AND emailID IN ('".implode("','", $arr_email_accounts_permission)."')";

	$intUnread = $wpdb->get_var("SELECT COUNT(messageID) FROM ".$wpdb->base_prefix."email_message INNER JOIN ".$wpdb->base_prefix."email_folder USING (folderID) WHERE messageRead = '0' AND folderType != '3'".$query_permission);

	if($intUnread > 0)
	{
		$count_message = "&nbsp;<span class='update-plugins' title='".__("Unread", 'lang_email')."'>
			<span>".$intUnread."</span>
		</span>";
	}

	return $count_message;
}

function menu_email()
{
	global $wpdb;

	$menu_root = 'mf_email/';
	$menu_start = $menu_root."list/index.php";
	$menu_capability = "edit_posts";

	/*$obj_email = new mf_email();

	if($obj_email->has_accounts() > 0)
	{*/
		if(current_user_can($menu_capability))
		{
			wp_enqueue_script('jquery-ui-autocomplete');
			mf_enqueue_script('script_email', plugin_dir_url(__FILE__)."script_wp.js", array('plugin_url' => plugin_dir_url(__FILE__)));
		}

		$count_message = count_unread_email();

		add_menu_page("", __("E-mail", 'lang_email').$count_message, $menu_capability, $menu_start, '', 'dashicons-email-alt');

		add_submenu_page($menu_start, __("E-mail", 'lang_email'), __("E-mail", 'lang_email'), $menu_capability, $menu_start);
		add_submenu_page($menu_start, __("Send new", 'lang_email'), __("Send new", 'lang_email'), $menu_capability, $menu_root."send/index.php");
		add_submenu_page($menu_start, __("Accounts", 'lang_email'), __("Accounts", 'lang_email'), $menu_capability, $menu_root."accounts/index.php");
		add_submenu_page($menu_start, __("Add new account", 'lang_email'), __("Add new account", 'lang_email'), $menu_capability, $menu_root."create/index.php");
		add_submenu_page($menu_root, __("Add new folder", 'lang_email'), __("Add new folder", 'lang_email'), $menu_capability, $menu_root."folder/index.php");
	/*}

	else
	{
		$menu_start = $menu_root."accounts/index.php";

		add_menu_page("", __("E-mail", 'lang_email'), $menu_capability, $menu_start, '', 'dashicons-email-alt');

		add_submenu_page($menu_start, __("Accounts", 'lang_email'), __("Accounts", 'lang_email'), $menu_capability, $menu_start);

		add_submenu_page($menu_start, __("Add new account", 'lang_email'), __("Add new account", 'lang_email'), $menu_capability, $menu_root."create/index.php");
	}*/
}

//Extension for RCube
function raise_error($error)
{
	echo var_export($error, true)."<br>";
}

function email_connect($data)
{
	if(!isset($data['close_after'])){	$data['close_after'] = false;}

	$is_connected = false;

	$connection = new rcube_imap();
	$connection->set_debug(false);
	$connection->connect($data['server'], $data['username'], $data['password'], $data['port']);

	return $connection;
}

function cron_email()
{
	global $wpdb;

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

		$encryption = new mf_encryption("email");

		$strEmailPassword = $encryption->decrypt($strEmailPassword, md5($strEmailAddress));

		$connection = email_connect(array('server' => $strEmailServer, 'port' => $intEmailPort, 'username' => $strEmailUsername, 'password' => $strEmailPassword));

		if($connection == true)
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
				$strMessageSubject = convert_email_subject(array('subject' => $header->subject));
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

						foreach($arr_emails as $email)
						{
							$intAddressID = $wpdb->get_var($wpdb->prepare("SELECT addressID FROM ".$wpdb->base_prefix."address WHERE addressEmail = %s", $email));

							if($intAddressID > 0)
							{
								$obj_address = new mf_address($intAddressID);
								$obj_address->update_errors(array('action' => 'reset'));

								$intQueueID = $wpdb->get_var($wpdb->prepare("SELECT queueID FROM ".$wpdb->base_prefix."group_queue WHERE addressID = '%d' AND queueSent = '1' AND queueSentTime <= '".$strMessageCreated."' ORDER BY queueSentTime DESC LIMIT 0, 1", $intAddressID));

								if($intQueueID > 0)
								{
									$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."group_queue SET queueReceived = '-1' WHERE queueID = '%d' AND addressID = '%d'", $intQueueID, $intAddressID));
								}

								else
								{
									$error_text = "No group message sent to that address (".$email.", ".$intAddressID.")<br>";
								}
							}

							else
							{
								$error_text = "No address with that e-mail (".$email.")<br>";
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
							$intFolderID = get_folder_ids(__('Spam', 'lang_email'), 3, $intEmailID);
						}

						else
						{
							$intFolderID = get_folder_ids(__('Inbox', 'lang_email'), 6, $intEmailID);
						}

						list($intMessageID, $affected_rows) = save_email(array('folder_id' => $intFolderID, 'text_id' => $strMessageTextID, 'md5' => $strMessageMd5, 'from' => $strMessageFrom, 'from_name' => $strMessageFromName, 'to' => $strMessageTo, 'cc' => $strMessageCc, 'subject' => $strMessageSubject, 'content' => $strMessageTextPlain, 'content_html' => $strMessageTextHTML, 'created' => $strMessageCreated));

						if($affected_rows > 0)
						{
							if($intSpamID > 0)
							{
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

									$term_attachment = create_term_if_not_exists(array('taxonomy' => $taxonomy, 'term_slug' => 'email_attachment', 'term_name' => __("E-mail attachments", 'lang_email')));

									wp_set_object_terms($post_id, array((int)$term_attachment['term_id']), $taxonomy, false);

									$done_text = __("The attachment was saved", 'lang_base').": ".$intFileID." -> ".$intMessageID;
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
		}

		else
		{
			$error_text = __("Connection failed", 'lang_email');
		}

		//echo get_notification();
	}
}