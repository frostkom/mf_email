<?php

function get_email_accounts_permission()
{
	global $wpdb;

	$out = array();

	$result = $wpdb->get_results("SELECT emailID FROM ".$wpdb->base_prefix."email_users RIGHT JOIN ".$wpdb->base_prefix."email USING (emailID) WHERE (emailPublic = '1' OR emailRoles LIKE '%".get_current_user_role()."%' OR ".$wpdb->base_prefix."email_users.userID = '".get_current_user_id()."' OR (".$wpdb->base_prefix."email.userID = '".get_current_user_id()."' AND ".$wpdb->base_prefix."email_users.userID IS null)) AND (blogID = '".$wpdb->blogid."' OR blogID = '0')");

	foreach($result as $r)
	{
		$out[] = $r->emailID;
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

function get_email_address_from_id($id)
{
	global $wpdb;

	return $wpdb->get_var($wpdb->prepare("SELECT emailAddress FROM ".$wpdb->base_prefix."email WHERE emailID = '%d'", $id));
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

			$intFolderID = get_folder_ids(__("Trash", 'lang_email'), 2, $intEmailID);
		}

		else
		{
			$query_xtra .= ", messageDeletedDate = '', messageDeletedID = ''";

			$intFolderID = get_folder_ids(__("Inbox", 'lang_email'), 6, $intEmailID);
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

			$intFolderID = get_folder_ids(__("Spam", 'lang_email'), 3, $intEmailID);
		}

		else
		{
			$query_xtra .= ", messageDeletedDate = '', messageDeletedID = ''";

			$intFolderID = get_folder_ids(__("Inbox", 'lang_email'), 6, $intEmailID);
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

/*function is_class_method($class, $method, $type)
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
}*/