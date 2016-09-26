<?php

$obj_email = new mf_email();

$intMessageID = check_var('intMessageID');
$intMessageDraftID = check_var('intMessageDraftID');
$intMessageAnswer = isset($_GET['answer']) ? 1 : 0;
$intMessageForward = isset($_GET['forward']) ? 1 : 0;

$intEmailID = check_var('intEmailID');
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
			$mail_attachment = get_attachment_to_send($strMessageAttachment);

			add_filter('wp_mail_content_type', 'set_html_content_type');

			if(wp_mail($strMessageTo, $strMessageSubject, $mail_content, $mail_headers, $mail_attachment))
			{
				$intFolderID = get_folder_ids(__('Sent', 'lang_email'), 4, $intEmailID);

				list($intMessageID, $affected_rows) = save_email(array('read' => 1, 'folder_id' => $intFolderID, 'to' => $strMessageTo, 'cc' => $strMessageCc, 'subject' => $strMessageSubject, 'content_html' => $strMessageText));

				if($intMessageID > 0)
				{
					if($strMessageAttachment != '')
					{
						$arr_attachments = explode(",", $strMessageAttachment);

						foreach($arr_attachments as $attachment)
						{
							list($file_name, $file_url) = explode("|", $attachment);

							if($file_url != '')
							{
								$file_url_check = WP_CONTENT_DIR.str_replace(site_url()."/wp-content", "", $file_url);

								if(file_exists($file_url_check))
								{
									$query = $wpdb->prepare("SELECT ID FROM ".$wpdb->posts." WHERE post_type = 'attachment' AND post_title = %s", $file_name);

									$intFileID = $wpdb->get_var($query);

									if($intFileID > 0)
									{
										$wpdb->query($wpdb->prepare("INSERT INTO ".$wpdb->base_prefix."email_message_attachment SET messageID = '%d', fileID = '%d'", $intMessageID, $intFileID));
									}

									else
									{
										$error_text = __("Could not save the attached file to DB, but it was successfully sent", 'lang_email')." (".$query.")";
									}
								}

								else
								{
									$error_text = __("The file does not seem to exist", 'lang_email')." (".$file_url_check.")";
								}
							}
						}
					}

					if(!isset($error_text) || $error_text == '')
					{
						mf_redirect("?page=mf_email/list/index.php&sent");
					}
				}
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
	$intFolderID = get_folder_ids(__('Draft', 'lang_email'), 5, $intEmailID);

	list($intMessageID, $affected_rows) = save_email(array('id' => $intMessageDraftID, 'folder_id' => $intFolderID, 'to' => $strMessageTo, 'cc' => $strMessageCc, 'subject' => $strMessageSubject, 'content_html' => $strMessageText));

	if($affected_rows > 0)
	{
		$done_text = __('The draft has been saved', 'lang_email');
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

						$strMessageAttachment .= ($strMessageAttachment != '' ? "," : "").$file_name."|".$file_url;
					}
				}
			}
		}
	}
}

$current_user = wp_get_current_user();

$user_name = $current_user->display_name;
$user_email = $current_user->user_email;
$admin_name = get_bloginfo('name');
$admin_email = get_bloginfo('admin_email');

$arr_data_from = array();
$arr_data_from[''] = "-- ".__("Choose here", 'lang_email')." --";

$result = $wpdb->get_results("SELECT ".$wpdb->base_prefix."email.emailID, emailName, emailAddress FROM ".$wpdb->base_prefix."email_users RIGHT JOIN ".$wpdb->base_prefix."email USING (emailID) WHERE (emailPublic = '1' OR emailRoles LIKE '%".get_current_user_role()."%' OR ".$wpdb->base_prefix."email.userID = '".get_current_user_id()."' OR ".$wpdb->base_prefix."email_users.userID = '".get_current_user_id()."') AND emailDeleted = '0' ORDER BY emailName ASC, emailAddress ASC");

foreach($result as $r)
{
	$intEmailID2 = $r->emailID;
	$strEmailName = $r->emailName;
	$strEmailAddress = $r->emailAddress;

	$strEmailName = $strEmailName != '' ? $strEmailName." &lt;".$strEmailAddress."&gt;" : $strEmailAddress;

	$arr_data_from[$intEmailID2] = $strEmailName;
}

if(count($arr_data_from) <= 1)
{
	$obj_email = new mf_email();
	$obj_email->fetch_request();

	if($user_email != '')
	{
		//$arr_data_from[$user_name."|".$user_email] = $user_name." (".$user_email.")";

		$obj_email->name = $user_name;
		$obj_email->address = $user_email;
		$obj_email->users = array(get_current_user_id());

		$obj_email->id = $this->check_if_account_exists();

		if(!($obj_email->id > 0))
		{
			$obj_email->create_account();
		}

		if($obj_email->id > 0)
		{
			$arr_data_from[$obj_email->id] = $user_name." &lt;".$user_email."&gt;";
		}
	}

	if($admin_email != '' && $admin_email != $user_email)
	{
		//$arr_data_from[$admin_name."|".$admin_email] = $admin_name." (".$admin_email.")";

		$obj_email->public = 1;
		$obj_email->name = $admin_name;
		$obj_email->address = $admin_email;

		$obj_email->id = $this->check_if_account_exists();

		if(!($obj_email->id > 0))
		{
			$obj_email->create_account();
		}

		if($obj_email->id > 0)
		{
			$arr_data_from[$obj_email->id] = $admin_name." &lt;".$admin_email."&gt;";
		}
	}
}

echo "<div class='wrap'>
	<h2>".__("E-mail", 'lang_email')."</h2>"
	.get_notification()
	."<div id='poststuff'>
		<form method='post' action='' class='mf_form'>
			<div id='post-body' class='columns-2'>
				<div id='post-body-content'>
					<div class='postbox'>
						<h3 class='hndle'>".__("Message", 'lang_email')."</h3>
						<div class='inside'>"
							.show_select(array('data' => $arr_data_from, 'name' => 'intEmailID', 'value' => $intEmailID, 'text' => __('From', 'lang_email'), 'required' => 1))
							."<div class='flex_flow'>
								<div class='search_container'>"
									.show_textarea(array('name' => 'strMessageTo', 'text' => __('To', 'lang_email'), 'value' => $strMessageTo, 'autogrow' => 1, 'xtra' => "autofocus"))
									."<span id='txtMessageTo'></span>
								</div>
								<div class='search_container'>"
									.show_textarea(array('name' => 'strMessageCc', 'text' => __('Cc', 'lang_email'), 'value' => $strMessageCc, 'autogrow' => 1))
									."<span id='txtMessageCc'></span>
								</div>
							</div>"
							.show_textfield(array('name' => 'strMessageSubject', 'text' => __('Subject', 'lang_email'), 'value' => $strMessageSubject, 'required' => 1))
							.mf_editor($strMessageText, "strMessageText")
						."</div>
					</div>
				</div>
				<div id='postbox-container-1'>
					<div class='postbox'>
						<h3 class='hndle'>".__("Send", 'lang_email')."</h3>
						<div class='inside'>"
							.get_media_button(array('name' => "strMessageAttachment", 'value' => $strMessageAttachment))
							."<div>"
								.show_submit(array('name' => 'btnMessageSend', 'text' => __('Send', 'lang_email')))
								."&nbsp;"
								.show_submit(array('name' => 'btnMessageDraft', 'text' => __('Save draft', 'lang_email'), 'class' => "button"))
								.wp_nonce_field('message_send', '_wpnonce', true, false)
								.input_hidden(array('name' => "intMessageDraftID", 'value' => $intMessageDraftID))
							."</div>
						</div>
					</div>
				</div>
			</div>
		</form>
	</div>
</div>";