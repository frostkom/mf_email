<?php

if(!defined('ABSPATH'))
{
	header('Content-Type: application/json');

	$folder = str_replace("/wp-content/plugins/mf_email/include/api", "/", dirname(__FILE__));

	require_once($folder."wp-load.php");
}

$json_output = array();

$strAjaxInput = check_var('type', 'char', true, 'email/folders/'.__("Inbox", 'lang_email'));
$arr_input = explode("/", trim($strAjaxInput, "/"));

if($arr_input[0] == "email")
{
	$query_where = " AND emailID IN ('".implode("','", get_email_accounts_permission())."')";

	if($arr_input[1] == "folders")
	{
		$strFolderName = $arr_input[2];
		$strFolderAction = isset($arr_input[3]) && $arr_input[3] != '' ? $arr_input[3] : "";

		if($strFolderAction == "delete")
		{
			$intTotal = $wpdb->get_var($wpdb->prepare("SELECT COUNT(messageID) FROM ".$wpdb->base_prefix."email_message INNER JOIN ".$wpdb->base_prefix."email_folder USING (folderID) WHERE folderName = %s".$query_where, $strFolderName));

			if($intTotal == 0)
			{
				$query = $wpdb->prepare("UPDATE ".$wpdb->base_prefix."email_folder SET folderDeleted = '1', folderDeletedDate = NOW(), folderDeletedID = '%d' WHERE folderName = %s".$query_where, get_current_user_id(), $strFolderName);

				$wpdb->query($query);

				if($wpdb->rows_affected > 0)
				{
					$intFolderID = $wpdb->get_var($wpdb->prepare("SELECT folderID FROM ".$wpdb->base_prefix."email_folder WHERE folderName = %s".$query_where, $strFolderName));

					$json_output['success'] = true;
					$json_output['remove_id'] = "folder".$intFolderID;
				}

				else
				{
					$json_output['error'] = $query;
				}
			}

			else
			{
				$json_output['error'] = $intTotal;
			}
		}

		else
		{
			$json_output['folders'] = array();

			$result = $wpdb->get_results("SELECT folderID, folderType, folderName FROM ".$wpdb->base_prefix."email_folder WHERE (folderID2 = '0' OR folderID2 IS null) AND folderDeleted = '0'".$query_where." GROUP BY folderName ORDER BY folderType DESC, folderName ASC");

			foreach($result as $r)
			{
				$intFolderID2 = $r->folderID;
				$intFolderType = $r->folderType;
				$strFolderName2 = $r->folderName;

				$intTotal = $wpdb->get_var($wpdb->prepare("SELECT COUNT(messageID) FROM ".$wpdb->base_prefix."email_message INNER JOIN ".$wpdb->base_prefix."email_folder USING (folderID) WHERE folderName = %s".$query_where, $strFolderName2));
				$intUnread = $wpdb->get_var($wpdb->prepare("SELECT COUNT(messageID) FROM ".$wpdb->base_prefix."email_message INNER JOIN ".$wpdb->base_prefix."email_folder USING (folderID) WHERE folderName = %s AND messageRead = '0'".$query_where, $strFolderName2));

				$is_active = $strFolderName == $intFolderID2 || $strFolderName == $strFolderName2 ? 1 : 0;

				$class = "";

				if($intUnread > 0){							$class .= ($class != '' ? " " : "")."strong";}
				if(in_array($intFolderType, array(0, 6))){	$class .= ($class != '' ? " " : "")."droppable";}
				if($is_active){								$class .= ($class != '' ? " " : "")."color_active yellow";}

				switch($intFolderType)
				{
					case 6:		$image = 'fa fa-inbox green';	break;
					case 5:		$image = 'far fa-edit';			break;
					case 2:		$image = 'fa fa-trash-alt';		break;
					case 4:		$image = 'fa fa-upload';		break;
					case 3:		$image = 'fa fa-ban red';		break;
					default:	$image = "fa fa-folder";		break;
				}

				$json_output['folders'][] = array(
					'folderID' => $intFolderID2,
					'folderType' => $intFolderType,
					'folderActive' => $is_active,
					'folderImage' => $image,
					'folderClass' => $class,
					'folderName' => $strFolderName2,
					'folderUnread' => $intUnread,
					'folderTotal' => $intTotal,
				);
			}

			if(count($json_output['folders']) > 0)
			{
				//$json_output['next_request'] = "email/emails/".$strFolderName;
				$arr_input[1] = "emails";
				$arr_input[2] = $strFolderName;
			}

			$json_output['success'] = true;
		}
	}

	if($arr_input[1] == "emails")
	{
		DEFINE('EMAILS2SHOW', 50);

		$json_output['emails'] = array();

		$strFolderName = $arr_input[2];

		$json_output['folderName'] = $strFolderName;
		$json_output['limit_start'] = $intFolderLimitStart = isset($arr_input[3]) && $arr_input[3] > 0 ? $arr_input[3] : 0;
		$json_output['limit_amount'] = $intFolderLimitAmount = EMAILS2SHOW;

		$result = $wpdb->get_results($wpdb->prepare("SELECT messageID, messageRead, messageFrom, messageFromName, messageTo, messageName, messageSize, messageCreated, messageReceived, messageDeleted, folderType, emailAddress FROM ".$wpdb->base_prefix."email_message INNER JOIN ".$wpdb->base_prefix."email_folder USING (folderID) INNER JOIN ".$wpdb->base_prefix."email USING (emailID) WHERE folderName = %s".$query_where." ORDER BY messageCreated DESC LIMIT ".$intFolderLimitStart.", ".$intFolderLimitAmount, $strFolderName));

		$json_output['limit_amount'] = $wpdb->num_rows;

		foreach($result as $r)
		{
			$intMessageID = $r->messageID;
			$intMessageRead = $r->messageRead;
			$strMessageFrom = $r->messageFrom;
			$strMessageFromName = $r->messageFromName != '' ? $r->messageFromName : $strMessageFrom;
			$strMessageTo = $r->messageTo;
			$strMessageName = $r->messageName != '' ? $r->messageName : "(".__("No subject", 'lang_email').")";
			$strMessageCreated = format_date($r->messageCreated);
			$strMessageReceived = format_date($r->messageReceived);
			$intMessageDeleted = $r->messageDeleted;
			$intFolderType = $r->folderType;
			$strEmailAddress = $r->emailAddress;

			$email_outgoing = $strMessageFrom != '' ? false : true;
			$is_draggable = $intFolderType == 0 || $intFolderType == 6;

			$class = "";
			if($intMessageRead == 0){	$class .= ($class != '' ? " " : "")."strong";}
			if($is_draggable){			$class .= ($class != '' ? " " : "")."draggable";}

			$wpdb->get_results($wpdb->prepare("SELECT fileID FROM ".$wpdb->base_prefix."email_message_attachment WHERE messageID = '%d' LIMIT 0, 1", $intMessageID));
			$has_attachment = $wpdb->num_rows > 0;

			$json_output['emails'][] = array(
				'messageID' => $intMessageID,
				'messageName' => $strMessageName,
				'messageRead' => $intMessageRead,
				'folderType' => $intFolderType,
				'messageClass' => $class,
				'messageAttachment' => $has_attachment,
				'messageDraggable' => $is_draggable,
				'messageOutgoing' => $email_outgoing,
				'emailAddress' => $strEmailAddress,
				'messageFrom' => $strMessageFrom,
				'messageTo' => $strMessageTo,
				'messageFromName' => $strMessageFromName,
				'messageToName' => $strMessageTo,
				'messageCreated' => $strMessageCreated,
				'messageReceived' => $strMessageReceived,
				'messageDeleted' => $intMessageDeleted,
				//'messageSpam' => $strMessageSpam,
			);
		}

		$json_output['success'] = true;
	}

	else if($arr_input[1] == "render_row")
	{
		$json_output['render_row'] = array();

		$intMessageID = $arr_input[2];

		$result = $wpdb->get_results($wpdb->prepare("SELECT messageID, messageRead, messageFrom, messageFromName, messageTo, messageName, messageText, messageSize, messageCreated, messageReceived, messageDeleted, folderType, emailAddress FROM ".$wpdb->base_prefix."email_message INNER JOIN ".$wpdb->base_prefix."email_folder USING (folderID) INNER JOIN ".$wpdb->base_prefix."email USING (emailID) WHERE messageID = '%d'".$query_where." ORDER BY messageCreated DESC", $intMessageID));

		foreach($result as $r)
		{
			$intMessageID = $r->messageID;
			$intMessageRead = $r->messageRead;
			$strMessageFrom = $r->messageFrom;
			$strMessageFromName = $r->messageFromName != '' ? $r->messageFromName : $strMessageFrom;
			$strMessageTo = $r->messageTo;
			$strMessageName = $r->messageName != '' ? $r->messageName : "(".__("No subject", 'lang_email').")";
			$strMessageText_orig = $r->messageText;
			$strMessageCreated = format_date($r->messageCreated);
			$strMessageReceived = format_date($r->messageReceived);
			$intMessageDeleted = $r->messageDeleted;
			$intFolderType = $r->folderType;
			$strEmailAddress = $r->emailAddress;

			$email_outgoing = $strMessageFrom != '' ? false : true;
			$is_draggable = $intFolderType == 0 || $intFolderType == 6;

			$class = "";
			if($intMessageRead == 0){	$class .= ($class != '' ? " " : "")."strong";}
			if($is_draggable){			$class .= ($class != '' ? " " : "")."draggable";}

			$wpdb->get_results($wpdb->prepare("SELECT fileID FROM ".$wpdb->base_prefix."email_message_attachment WHERE messageID = '%d' LIMIT 0, 1", $intMessageID));
			$has_attachment = $wpdb->num_rows > 0;

			$json_output['render_row'] = array(
				'messageID' => $intMessageID,
				'messageName' => $strMessageName,
				'messageRead' => $intMessageRead,
				'folderType' => $intFolderType,
				'messageClass' => $class,
				'messageAttachment' => $has_attachment,
				'messageDraggable' => $is_draggable,
				'messageOutgoing' => $email_outgoing,
				'emailAddress' => $strEmailAddress,
				'messageFrom' => $strMessageFrom,
				'messageTo' => $strMessageTo,
				'messageFromName' => $strMessageFromName,
				'messageToName' => $strMessageTo,
				'messageCreated' => $strMessageCreated,
				'messageReceived' => $strMessageReceived,
				'messageDeleted' => $intMessageDeleted,
			);
		}

		$json_output['success'] = true;
	}

	else if($arr_input[1] == "show")
	{
		$obj_email = new mf_email();

		$intMessageID = $arr_input[2];

		$query = $wpdb->prepare("SELECT messageTextID, messageRead, messageFrom, messageFromName, messageTo, messageCc, emailAddress, messageName, messageText, messageText2, messageCreated, messageReceived, ".$wpdb->base_prefix."email_message.userID, folderType FROM ".$wpdb->base_prefix."email INNER JOIN ".$wpdb->base_prefix."email_folder USING (emailID) INNER JOIN ".$wpdb->base_prefix."email_message USING (folderID) WHERE ".$wpdb->base_prefix."email_message.messageID = '%d'".$query_where." LIMIT 0, 1", $intMessageID);
		$result = $wpdb->get_results($query);
		$rows = $wpdb->num_rows;

		if($rows > 0)
		{
			$r = $result[0];
			$strMailMessageID = $r->messageTextID;
			$intMailRead = $r->messageRead;
			$strMailFrom = $r->messageFrom;
			$strMailFromName = $r->messageFromName != '' ? $r->messageFromName : $strMailFrom;
			$strMailTo = $r->messageTo;
			$strMailCc = $r->messageCc;
			$strPop3Address = $r->emailAddress;
			$strMailName = $r->messageName != '' ? $r->messageName : "(".__("No subject", 'lang_email').")";
			$strMessageText = $r->messageText;
			$strMessageText2 = $r->messageText2;
			$strMailCreated = $r->messageCreated;
			$strMailReceived = $r->messageReceived;
			$intUserID2 = $r->userID;
			$intFolderType = $r->folderType;

			$strMessageText2 = $obj_email->filter_text($strMessageText2);

			$email_outgoing = $intUserID2 == '' || $strMailFrom != '' ? false : true;

			$strFrom = $email_outgoing == false ? $strMailFromName." <".$strMailFrom.">" : $strPop3Address;
			$strTo = $email_outgoing == false ? $strPop3Address.($strMailTo != $strPop3Address ? " (".$strMailTo.")" : "") : $strMailTo;

			$arr_attachments = array();

			$result = $wpdb->get_results($wpdb->prepare("SELECT post_title, guid FROM ".$wpdb->base_prefix."email_message_attachment INNER JOIN ".$wpdb->posts." ON ".$wpdb->base_prefix."email_message_attachment.fileID = ".$wpdb->posts.".ID AND post_type = 'attachment' WHERE messageID = '%d'", $intMessageID));

			foreach($result as $r)
			{
				$post_title = $r->post_title;
				$post_guid = $r->guid;

				$arr_attachments[] = array(
					'title' => get_file_icon(array('file' => $post_guid))."&nbsp;".$post_title,
					'url' => $post_guid
				);
			}

			$json_output['email'] = array(
				'messageID' => $intMessageID,
				'messageName' => $strMailName,
				'messageFrom' => $strFrom,
				'messageTo' => $strTo,
				'messageCc' => $strMailCc,
				'messageAttachment' => $arr_attachments,
				'messageText' => nl2br($strMessageText),
				'messageText2' => $strMessageText2,
			);

			if($intMailRead == 0)
			{
				set_mail_info(array('message_id' => $intMessageID, 'mail_read' => 1), $json_output);
			}

			$json_output['success'] = true;
		}

		else
		{
			$json_output['error'] = $query;
		}
	}

	else if($arr_input[1] == "read")
	{
		$intMessageID = $arr_input[2];

		set_mail_info(array('message_id' => $intMessageID, 'mail_read' => 1), $json_output);

		$json_output['success'] = true;
	}

	else if($arr_input[1] == "unread")
	{
		$intMessageID = $arr_input[2];

		set_mail_info(array('message_id' => $intMessageID, 'mail_read' => 0), $json_output);

		$json_output['success'] = true;
	}

	else if($arr_input[1] == "delete")
	{
		$intMessageID = $arr_input[2];

		set_mail_info(array('message_id' => $intMessageID, 'mail_deleted' => 1), $json_output);

		$json_output['success'] = true;
	}

	else if($arr_input[1] == "restore")
	{
		$intMessageID = $arr_input[2];

		set_mail_info(array('message_id' => $intMessageID, 'mail_deleted' => 0), $json_output);
		mark_spam(array('message_id' => $intMessageID, 'spam' => false));

		$json_output['success'] = true;
	}

	else if($arr_input[1] == "spam")
	{
		$intMessageID = $arr_input[2];

		set_mail_info(array('message_id' => $intMessageID, 'mail_spam' => 1), $json_output);
		mark_spam(array('message_id' => $intMessageID, 'spam' => true));

		$json_output['success'] = true;
	}

	else if($arr_input[1] == "move")
	{
		$intMessageID = str_replace("message", "", $arr_input[2]);
		$intFolderID = str_replace("folder", "", $arr_input[3]);

		$result = $wpdb->get_results($wpdb->prepare("SELECT emailID, folderName FROM ".$wpdb->base_prefix."email_folder WHERE folderID = '%d'", $intFolderID));

		foreach($result as $r)
		{
			$intEmailID = $r->emailID;
			$strFolderName = $r->folderName;
		}

		$result = $wpdb->get_results($wpdb->prepare("SELECT emailID, folderID, folderName FROM ".$wpdb->base_prefix."email_folder INNER JOIN ".$wpdb->base_prefix."email_message USING (folderID) WHERE messageID = '%d'", $intMessageID));
		foreach($result as $r)
		{
			$intEmailID2 = $r->emailID;
			$intFolderID2 = $r->folderID;
			$strFolderName2 = $r->folderName;

			if($intEmailID != $intEmailID2)
			{
				if($strFolderName != $strFolderName2)
				{
					$intFolderID = get_folder_ids($strFolderName, 0, $intEmailID2);

					//$json_output['notice'] = "New ID: ".$intFolderID;
				}

				else
				{
					$intFolderID = $intFolderID2;

					//$json_output['notice'] = "Change ID: ".$intFolderID;
				}
			}

			else
			{
				//$json_output['notice'] = "Debug: ".$intEmailID." == ".$intEmailID2.", ".$intFolderID." == ".$intFolderID2;
			}
		}

		set_mail_info(array('message_id' => $intMessageID, 'folder_id' => $intFolderID), $json_output);

		$json_output['success'] = true;
	}

	else if($arr_input[1] == "search")
	{
		$strSearch = check_var('s', 'char');

		$result = $wpdb->get_results("SELECT messageTo FROM ".$wpdb->base_prefix."email_message INNER JOIN ".$wpdb->base_prefix."email_folder USING (folderID) INNER JOIN ".$wpdb->base_prefix."email USING (emailID) WHERE messageTo LIKE '%".esc_sql($strSearch)."%'".$query_where." GROUP BY messageTo ORDER BY messageCreated DESC");

		foreach($result as $r)
		{
			$strMessageTo = $r->messageTo;

			$json_output[] = $strMessageTo;
		}

		$json_output['amount'] = $wpdb->num_rows;
		$json_output['success'] = true;
	}
}

echo json_encode($json_output);