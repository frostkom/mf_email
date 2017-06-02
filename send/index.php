<?php

$obj_email = new mf_email();

$intMessageID = check_var('intMessageID');
$intMessageDraftID = check_var('intMessageDraftID');
$intMessageAnswer = isset($_GET['answer']) ? 1 : 0;
$intMessageForward = isset($_GET['forward']) ? 1 : 0;

$intEmailID = check_var('intEmailID', 'int', true, $obj_email->get_from_last());
$strMessageTo = check_var('strMessageTo');
$strMessageCc = check_var('strMessageCc');
$strMessageSubject = check_var('strMessageSubject');
$strMessageText = check_var('strMessageText', 'raw');
$strMessageAttachment = check_var('strMessageAttachment');

$intGroupMessageID = check_var('intGroupMessageID');

if(isset($_POST['btnMessageSend']) && wp_verify_nonce($_POST['_wpnonce'], 'message_send'))
{
	if($intEmailID > 0 && $strMessageTo != '')
	{
		$result = $wpdb->get_results($wpdb->prepare("SELECT emailName, emailAddress FROM ".$wpdb->base_prefix."email WHERE emailID = '%d'", $intEmailID));

		foreach($result as $r)
		{
			$strEmailName = $r->emailName;
			$strEmailAddress = $r->emailAddress;

			$strMessageTo = $obj_email->validate_email_string($strMessageTo);
			$strMessageCc = $obj_email->validate_email_string($strMessageCc);

			$mail_headers = "From: ".$strEmailName." <".$strEmailAddress.">\r\n";
			$mail_headers .= "Cc: ".$strMessageCc."\r\n";

			$mail_content = apply_filters('the_content', stripslashes($strMessageText));
			list($mail_attachment, $rest) = get_attachment_to_send($strMessageAttachment);

			$sent = send_email(array('to' => $strMessageTo, 'subject' => $strMessageSubject, 'content' => $mail_content, 'headers' => $mail_headers, 'attachment' => $mail_attachment));

			if($sent)
			{
				$intFolderID = get_folder_ids(__("Sent", 'lang_email'), 4, $intEmailID);

				list($intMessageID, $affected_rows) = save_email(array('read' => 1, 'folder_id' => $intFolderID, 'to' => $strMessageTo, 'cc' => $strMessageCc, 'subject' => $strMessageSubject, 'content_html' => $strMessageText));

				if($intMessageID > 0)
				{
					if($strMessageAttachment != '')
					{
						$arr_attachments = explode(",", $strMessageAttachment);

						foreach($arr_attachments as $attachment)
						{
							@list($file_name, $file_url, $file_id) = explode("|", $attachment);

							if($file_id > 0){}

							else if($file_url != '')
							{
								$file_url_check = WP_CONTENT_DIR.str_replace(site_url()."/wp-content", "", $file_url);

								if(file_exists($file_url_check))
								{
									$query = $wpdb->prepare("SELECT ID FROM ".$wpdb->posts." WHERE post_type = 'attachment' AND post_title = %s", $file_name);

									$file_id = $wpdb->get_var($query);
								}

								else
								{
									$error_text = __("The file does not seem to exist", 'lang_email')." (".$file_url_check.")";
								}
							}

							if($file_id > 0)
							{
								$wpdb->query($wpdb->prepare("INSERT INTO ".$wpdb->base_prefix."email_message_attachment SET messageID = '%d', fileID = '%d'", $intMessageID, $file_id));
							}

							else
							{
								$error_text = __("Could not save the attached file to DB, but it was successfully sent", 'lang_email');
							}
						}
					}

					if(!isset($error_text) || $error_text == '')
					{
						mf_redirect("?page=mf_email/list/index.php&sent");
					}
				}
			}

			else
			{
				$error_text = __("Unfortunately, I could not send the email for you. Please try again. If the problem persists, please contact my admin", 'lang_email');
			}
		}
	}

	else
	{
		$error_text = __("You have to enter all required fields", 'lang_email');
	}
}

else if(isset($_POST['btnMessageDraft']) && wp_verify_nonce($_POST['_wpnonce'], 'message_send'))
{
	$intFolderID = get_folder_ids(__("Draft", 'lang_email'), 5, $intEmailID);

	list($intMessageID, $affected_rows) = save_email(array('id' => $intMessageDraftID, 'folder_id' => $intFolderID, 'to' => $strMessageTo, 'cc' => $strMessageCc, 'subject' => $strMessageSubject, 'content_html' => $strMessageText));

	if($affected_rows > 0)
	{
		$done_text = __("The draft has been saved", 'lang_email');
	}
}

else if($intGroupMessageID > 0)
{
	$result = $wpdb->get_results($wpdb->prepare("SELECT messageFrom, messageName, messageText FROM ".$wpdb->base_prefix."group_message WHERE messageID = '%d'", $intGroupMessageID));

	foreach($result as $r)
	{
		$strMessageSubject = $r->messageName;
		$strMessageText = stripslashes($r->messageText);
	}
}

else if($strMessageCc == '' && $strMessageSubject == '' && $strMessageText == '')
{
	if($intMessageDraftID > 0)
	{
		$result = $wpdb->get_results($wpdb->prepare("SELECT emailID, messageTo, messageCc, messageName, messageText2 FROM ".$wpdb->base_prefix."email_message INNER JOIN ".$wpdb->base_prefix."email_folder USING (folderID) WHERE messageDeleted = '0' AND messageID = '%d'", $intMessageDraftID));

		foreach($result as $r)
		{
			$intEmailID = $r->emailID;
			$strMessageTo = $r->messageTo;
			$strMessageCc = $r->messageCc;
			$strMessageSubject = $r->messageName;
			$strMessageText = $r->messageText2;
		}
	}

	else if($intMessageID > 0)
	{
		$result = $wpdb->get_results("SELECT ".$wpdb->base_prefix."email.emailID, emailAddress, messageFrom, messageFromName, messageTo, messageCc, messageReplyTo, messageName, messageText, messageCreated, ".$wpdb->base_prefix."email_message.userID FROM ".$wpdb->base_prefix."email_users RIGHT JOIN ".$wpdb->base_prefix."email USING (emailID) INNER JOIN ".$wpdb->base_prefix."email_folder USING (emailID) INNER JOIN ".$wpdb->base_prefix."email_message USING (folderID) WHERE ".$wpdb->base_prefix."email_message.messageID = '".esc_sql($intMessageID)."' AND (emailPublic = '1' OR emailRoles LIKE '%".get_current_user_role()."%' OR ".$wpdb->base_prefix."email.userID = '".get_current_user_id()."' OR ".$wpdb->base_prefix."email_users.userID = '".get_current_user_id()."') LIMIT 0, 1");

		foreach($result as $r)
		{
			$intEmailID = $r->emailID;
			$strEmailAddress = $r->emailAddress;
			$strMailFrom = $r->messageFrom;
			$strMailFromName = $r->messageFromName;
			$strMailTo = $r->messageTo;
			$strMessageSubject_old = $r->messageName;
			$strMessageText = $r->messageText;
			$dteMessageCreated = $r->messageCreated;
			$intUserID2 = $r->userID;

			if($intMessageForward == 0)
			{
				$arrMessageReplyTo_temp = get_email_address_from_text($r->messageReplyTo);

				if($arrMessageReplyTo_temp != '' && is_array($arrMessageReplyTo_temp))
				{
					foreach($arrMessageReplyTo_temp as $strMessageReplyTo_temp)
					{
						$strMessageTo .= " ".$strMessageReplyTo_temp;
					}
				}

				if($strMessageTo == '')
				{
					$strMessageFrom_temp = $strMailFrom;

					if($strMessageFrom_temp != '')
					{
						$strMessageTo .= " ".$strMessageFrom_temp;
					}
				}

				if($intMessageAnswer == 1)
				{
					$arrMessageTo_temp = get_email_address_from_text($strMailTo);

					if(is_array($arrMessageTo_temp))
					{
						foreach($arrMessageTo_temp as $strMessageTo_temp)
						{
							$strMessageCc .= " ".$strMessageTo_temp;
						}
					}

					else
					{
						$strMessageCc .= " ".$arrMessageTo_temp;
					}

					$arrMessageCc_temp = get_email_address_from_text($r->messageCc);

					if(is_array($arrMessageCc_temp))
					{
						foreach($arrMessageCc_temp as $strMessageCc_temp)
						{
							$strMessageCc .= " ".$strMessageCc_temp;
						}
					}

					else
					{
						$strMessageCc .= " ".$arrMessageCc_temp;
					}
				}

				$strMessageTo = trim(str_replace($strEmailAddress, "", $strMessageTo));
				$strMessageCc = trim(str_replace($strEmailAddress, "", $strMessageCc));
			}

			if($intMessageForward == 1 || $intMessageAnswer == 1)
			{
				$subject_prefix = $intMessageForward == 1 ? "Fwd: " : "Re: ";

				$email_outgoing = $intUserID2 == '' || $strMailFrom != '' ? false : true;

				$strFrom = $email_outgoing == false ? $strMailFromName." <".$strMailFrom.">" : $strEmailAddress;
				$strTo = $email_outgoing == false ? $strEmailAddress." (".$strMailTo.")" : $strMailTo;

				$strMessageSubject = (substr($strMessageSubject_old, 0, strlen($subject_prefix)) != $subject_prefix ? $subject_prefix : "").$strMessageSubject_old;

				$strMessageText = "<p></p><p>-------------------- ".__("Original message", 'lang_email')." --------------------</p>"
				."<p>".__("From", 'lang_email').": ".$strFrom."</p>"
				."<p>".__("To", 'lang_email').": ".$strTo."</p>"
				."<p>".__("Subject", 'lang_email').": ".$strMessageSubject."</p>"
				."<p>".__("Date", 'lang_email').": ".$dteMessageCreated."</p>"
				."<p>"."-------------------</p>"
				."<p>".preg_replace('#^(.*?)$#m', '<br>&gt; \1', strip_tags($strMessageText, '<br>'))."</p>"
				."<p>------------------ ".__("End original message", 'lang_email')." ------------------</p>";

				if($intMessageForward == 1)
				{
					$result = $wpdb->get_results($wpdb->prepare("SELECT fileID FROM ".$wpdb->base_prefix."email_message_attachment WHERE messageID = '%d'", $intMessageID));

					foreach($result as $r)
					{
						list($file_name, $file_url) = get_attachment_data_by_id($r->fileID);

						$strMessageAttachment .= ($strMessageAttachment != '' ? "," : "").$file_name."|".$file_url."|".$r->fileID;
					}
				}
			}
		}
	}
}

echo "<div class='wrap'>
	<h2>".__("E-mail", 'lang_email')."</h2>"
	.get_notification()
	."<div id='poststuff'>
		<form method='post' action='' class='mf_form mf_settings'>
			<div id='post-body' class='columns-2'>
				<div id='post-body-content'>
					<div class='postbox'>
						<h3 class='hndle'>".__("Message", 'lang_email')."</h3>
						<div class='inside'>"
							.show_select(array('data' => $obj_email->get_from_for_select(), 'name' => 'intEmailID', 'value' => $intEmailID, 'text' => __("From", 'lang_email'), 'required' => 1))
							."<div class='flex_flow'>
								<div class='search_container'>"
									.show_textarea(array('name' => 'strMessageTo', 'text' => __("To", 'lang_email'), 'value' => $strMessageTo, 'autogrow' => 1, 'xtra' => "autofocus"))
									."<span id='txtMessageTo'></span>
								</div>
								<div class='search_container'>"
									.show_textarea(array('name' => 'strMessageCc', 'text' => __("Cc", 'lang_email'), 'value' => $strMessageCc, 'autogrow' => 1))
									."<span id='txtMessageCc'></span>
								</div>
							</div>"
							.show_textfield(array('name' => 'strMessageSubject', 'text' => __("Subject", 'lang_email'), 'value' => $strMessageSubject, 'required' => 1))
							.show_wp_editor(array('name' => 'strMessageText', 'value' => $strMessageText))
						."</div>
					</div>
				</div>
				<div id='postbox-container-1'>
					<div class='postbox'>
						<div class='inside'>"
							.show_button(array('name' => 'btnMessageSend', 'text' => __("Send", 'lang_email')))
							."&nbsp;"
							.show_button(array('name' => 'btnMessageDraft', 'text' => __("Save draft", 'lang_email'), 'class' => "button"))
							.input_hidden(array('name' => "intMessageDraftID", 'value' => $intMessageDraftID))
							.wp_nonce_field('message_send', '_wpnonce', true, false)
						."</div>
					</div>
					<div class='postbox'>
						<h3 class='hndle'>".__("Settings", 'lang_email')."</h3>
						<div class='inside'>"
							.get_media_button(array('name' => "strMessageAttachment", 'value' => $strMessageAttachment))
						."</div>
					</div>
				</div>
			</div>
		</form>
	</div>
</div>";