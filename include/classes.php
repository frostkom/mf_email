<?php

class mf_email
{
	var $id = 0;
	var $type = '';
	var $message_id = 0;
	var $server;
	var $port;
	var $username;
	var $password;
	var $address;
	var $name;
	var $signature;
	var $outgoing_type;
	var $limit_per_hour;
	var $smtp_server;
	var $smtp_port;
	var $smtp_ssl;
	var $smtp_hostname;
	var $smtp_username;
	var $smtp_password;
	var $preferred_content_types = array();
	var $public;
	var $roles = array();
	var $users;
	var $smtp_password_encrypted;
	var $password_encrypted;
	var $deleted;
	var $message_draft_id;
	var $message_answer;
	var $message_forward;
	var $message_to;
	var $message_cc;
	var $message_subject;
	var $message_text;
	var $message_attachment;
	var $message_text_source;
	var $group_message_id;
	var $all_left_to_send;
	var $from_address;

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

		$this->type = (isset($data['type']) ? $data['type'] : '');
	}

	function get_ssl_for_select()
	{
		return array(
			'' => __("No", 'lang_email'),
			'ssl' => "SSL",
			'tls' => "TLS",
		);
	}

	function get_preferred_content_types_for_select()
	{
		return array(
			'plain' => __("Plain Text", 'lang_email'),
			'html' => __("HTML", 'lang_email'),
		);
	}

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

	function get_preferred_content_types($arr_out, $email)
	{
		global $wpdb;

		$arr_out = get_option('setting_email_preferred_content_types');

		if($email != '')
		{
			$strEmailPreferredContentTypes = $wpdb->get_var($wpdb->prepare("SELECT emailPreferredContentTypes FROM ".$wpdb->base_prefix."email WHERE emailAddress = %s LIMIT 0, 1", $email));

			if($strEmailPreferredContentTypes != '')
			{
				$arr_out = explode(",", $strEmailPreferredContentTypes);
			}
		}

		return $arr_out;
	}

	function get_update_email($data = array())
	{
		global $wpdb;

		if(!isset($data['cutoff'])){	$data['cutoff'] = date("Y-m-d H:i:s", strtotime("-2 minute"));} //"DATE_SUB(NOW(), INTERVAL 2 MINUTE)"

		if(IS_ADMINISTRATOR)
		{
			$query_permission = " AND emailID IN ('".implode("','", $this->get_email_accounts_permission())."')";

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
		if(!isset($data['debug'])){		$data['debug'] = false;}

		$is_connected = false;

		if(!defined('RCMAIL_VERSION'))
		{
			$plugin_version = get_plugin_version(__FILE__);

			define('RCMAIL_VERSION', $plugin_version);
		}

		$connection = new rcube_imap();
		$connection->set_debug($data['debug']);
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
		$out = "";

		if($data['subject'] != '')
		{
			if(function_exists('imap_mime_header_decode'))
			{
				$elements = imap_mime_header_decode($data['subject']);

				$count_temp = count($elements);

				for($i = 0; $i < $count_temp; $i++)
				{
					$out .= $elements[$i]->text;
				}
			}

			else
			{
				$out_temp = $data['subject'];

				$has_been_spam_tagged = false;
				$spam_tag = "*****SPAM***** ";

				if(substr($out_temp, 0, strlen($spam_tag)) == $spam_tag)
				{
					$out_temp = substr($out_temp, strlen($spam_tag));
					$has_been_spam_tagged = true;
				}

					if($out = @iconv_mime_decode($out_temp)) //, 0, "ISO-8859-1"
					{
						// Do nothing more here
					}

					else
					{
						//do_log("convert_email_subject() Error: Threw error on iconv_mime_decode() for '".htmlspecialchars($data['subject'])."' -> '".htmlspecialchars($out_temp)."'");

						$out = $data['subject'];
					}

				// Has to be returned, otherwise it won't be detected as spam in check_if_spam()
				if($has_been_spam_tagged)
				{
					$out = $spam_tag.$out;
				}
			}
		}

		return $out;
	}

	function check_if_spam($data)
	{
		global $wpdb;

		if(!isset($data['from'])){		$data['from'] = '';}
		if(!isset($data['subject'])){	$data['subject'] = '';}

		$is_spam = false;

		if($is_spam == false && $data['subject'] != '')
		{
			$arr_spam = array('*****SPAM*****');

			foreach($arr_spam as $str_spam)
			{
				if(strpos($data['subject'], $str_spam) !== false)
				{
					$is_spam = true;

					break;
				}
			}
		}

		if($is_spam == false && $data['from'] != '')
		{
			$wpdb->get_results($wpdb->prepare("SELECT spamID FROM ".$wpdb->base_prefix."email_spam WHERE emailID = '%d' AND messageFrom = %s LIMIT 0, 1", $this->id, $data['from']));

			if($wpdb->num_rows > 0)
			{
				$is_spam = true;
			}
		}

		return $is_spam;
	}

	function mark_spam($data)
	{
		global $wpdb;

		$intSpamID = 0;
		$intEmailID = $this->get_email_id($data['message_id']);
		$strMessageFrom = $this->get_from_address($data['message_id']);

		$intSpamID = $this->check_if_spam(array('from' => $strMessageFrom));

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

				$intFolderID = $this->get_folder_ids(__("Trash", 'lang_email'), 2, $intEmailID);
			}

			else
			{
				$query_xtra .= ", messageDeletedDate = '', messageDeletedID = ''";

				$intFolderID = $this->get_folder_ids(__("Inbox", 'lang_email'), 6, $intEmailID);
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

				$intFolderID = $this->get_folder_ids(__("Spam", 'lang_email'), 3, $intEmailID);
			}

			else
			{
				$query_xtra .= ", messageDeletedDate = '', messageDeletedID = ''";

				$intFolderID = $this->get_folder_ids(__("Inbox", 'lang_email'), 6, $intEmailID);
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

	function cron_base()
	{
		global $wpdb;

		$obj_cron = new mf_cron();
		$obj_cron->start(__CLASS__);

		if($obj_cron->is_running == false)
		{
			$arr_bounce_subject = array(
				"Returned mail: see transcript for details",
				"Undelivered Mail Returned to Sender",
				"NDN:",
				"Delivery Status Notification",
				"Undeliverable:",
				"Mail delivery failed",
				"DELIVERY FAILURE:",
			);

			$arr_bounce_from_name = array(
				"Mail Delivery Subsystem",
			);

			// Get new messages
			####################################
			$result = $wpdb->get_results($wpdb->prepare("SELECT emailID, emailServer, emailPort, emailUsername, emailPassword, emailAddress FROM ".$wpdb->base_prefix."email WHERE blogID = '%d' AND emailDeleted = '0' AND emailVerified = '1' GROUP BY emailUsername", $wpdb->blogid)); //, blogID

			foreach($result as $r)
			{
				$intEmailID = $r->emailID;
				//$intBlogID = $r->blogID;
				$strEmailServer = $r->emailServer;
				$intEmailPort = $r->emailPort;
				$strEmailUsername = $r->emailUsername;
				$strEmailPassword = $r->emailPassword;
				$strEmailAddress = $r->emailAddress;

				//switch_to_blog($intBlogID);

				$this->id = $intEmailID;
				$obj_encryption = new mf_email_encryption("email");

				$strEmailPassword = $obj_encryption->decrypt($strEmailPassword, md5($strEmailAddress));

				list($is_connected, $connection) = $this->email_connect(array('server' => $strEmailServer, 'port' => $intEmailPort, 'username' => $strEmailUsername, 'password' => $strEmailPassword));

				if($is_connected == true)
				{
					foreach($connection->list_headers() as $header)
					{
						$logical = new rcube_message($connection, $header->uid);

						$intMessageStatus = (isset($header->flags['SEEN']) && $header->flags['SEEN'] == true);
						$strMessageTextID = $header->messageID;
						$strMessageFrom = $logical->sender['mailto'];
						$strMessageFromName = $logical->sender['name'];

						if(isset($logical->receiver['mailto']))
						{
							$strMessageTo = $logical->receiver['mailto'];
						}

						else if(isset($logical->headers->to) && $logical->headers->to != '')
						{
							$strMessageTo = $logical->headers->to;
						}

						/*else if(isset($logical->imap->icache['message']->to) && $logical->imap->icache['message']->to != '')
						{
							$strMessageTo = $logical->imap->icache['message']->to;
						}*/

						else // imap->user || imap->conn->user
						{
							$strMessageTo = $strEmailAddress;
						}

						$strMessageCc = $header->cc;
						$strMessageReplyTo = $header->replyto;
						$strMessageSubject = $this->convert_email_subject(array('subject' => $header->subject));
						$strMessageTextPlain = $logical->first_text_part();
						$strMessageTextHTML = $logical->first_html_part();
						$strMessageCreated = date("Y-m-d H:i:s", $header->timestamp);

						//do_log("cron_base E-mail Error: ".str_replace("\n", "", var_export($logical->receiver, true)));

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
									global $obj_address;

									if(!isset($obj_address))
									{
										$obj_address = new mf_address();
									}

									foreach($arr_emails as $email)
									{
										$obj_address->get_address_id(array('email' => $email));

										if($obj_address->id > 0)
										{
											$obj_address->update_errors(array('action' => 'reset'));

											$intQueueID = $wpdb->get_var($wpdb->prepare("SELECT queueID FROM ".$wpdb->prefix."group_queue WHERE addressID = '%d' AND queueSent = '1' AND queueSentTime <= '".$strMessageCreated."' ORDER BY queueSentTime DESC LIMIT 0, 1", $obj_address->id));

											if($intQueueID > 0)
											{
												$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->prefix."group_queue SET queueStatus = 'not_received' WHERE queueID = '%d' AND addressID = '%d'", $intQueueID, $obj_address->id));
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
								$intSpamID = $this->check_if_spam(array('from' => $strMessageFrom, 'subject' => $strMessageSubject));

								if($intSpamID > 0)
								{
									$intFolderID = $this->get_folder_ids(__("Spam", 'lang_email'), 3, $intEmailID);
								}

								else
								{
									$intFolderID = $this->get_folder_ids(__("Inbox", 'lang_email'), 6, $intEmailID);
								}

								list($intMessageID, $affected_rows) = $this->save_email(array('folder_id' => $intFolderID, 'text_id' => $strMessageTextID, 'md5' => $strMessageMd5, 'from' => $strMessageFrom, 'from_name' => $strMessageFromName, 'to' => $strMessageTo, 'cc' => $strMessageCc, 'subject' => $strMessageSubject, 'content' => $strMessageTextPlain, 'content_html' => $strMessageTextHTML, 'created' => $strMessageCreated));

								if($affected_rows > 0)
								{
									if($intSpamID > 0)
									{
										$arr_temp = array();

										$this->set_mail_info(array('message_id' => $intMessageID, 'mail_spam' => 1), $arr_temp);
										$this->mark_spam(array('message_id' => $intMessageID, 'spam' => true));
									}

									$done_text = __("Inserted", 'lang_email').": ".$strMessageSubject."<br>";

									$arr_file_id = array();

									if(count($logical->attachments) > 0)
									{
										foreach($logical->attachments as $arr_attachment)
										{
											$data = array(
												'content' => "",
												'mime' => "",
												'name' => "",
											);

											foreach($arr_attachment as $key => $value)
											{
												switch($key)
												{
													case 'mime_id':
														$data['content'] = $logical->get_part_content($value);
													break;

													case 'mimetype':
														$data['mime'] = $value;
													break;

													case 'filename':
														$data['name'] = $value;
													break;
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

				//restore_current_blog();
			}
			####################################

			// Remove fileIDs that do not exist anymore as IDs
			####################################
			$result = $wpdb->get_results($wpdb->prepare("SELECT fileID FROM ".$wpdb->base_prefix."email INNER JOIN ".$wpdb->base_prefix."email_folder USING (emailID) INNER JOIN ".$wpdb->base_prefix."email_message USING (folderID) INNER JOIN ".$wpdb->base_prefix."email_message_attachment USING (messageID) LEFT JOIN ".$wpdb->posts." ON fileID = ID WHERE blogID = '%d' AND ID IS null", $wpdb->blogid));

			foreach($result as $r)
			{
				$this->remove_attachment(array('file_id' => $r->fileID));
				//do_log("Remove fileID (".$r->fileID.") because it does not exist anymore?");
			}
			####################################

			if(is_main_site())
			{
				// Clean up spam folders where messages has not been deleted
				####################################
				$result = $wpdb->get_results("SELECT folderID FROM ".$wpdb->base_prefix."email_folder WHERE folderType = '3'");

				foreach($result as $r)
				{
					$intFolderID = $r->folderID;

					$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email_message SET messageDeleted = '1', messageDeletedDate = NOW() WHERE folderID = '%d' AND messageDeleted = '0'", $intFolderID));
				}
				####################################

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

				// Empty trash
				####################################
				$empty_trash_days = (defined('EMPTY_TRASH_DAYS') ? EMPTY_TRASH_DAYS : 30);

				$result = $wpdb->get_results("SELECT messageID FROM ".$wpdb->base_prefix."email_message WHERE messageDeleted = '1' AND messageDeletedDate < DATE_SUB(NOW(), INTERVAL ".$empty_trash_days." DAY)");

				foreach($result as $r)
				{
					$intMessageID = $r->messageID;

					$this->remove_attachment(array('message_id' => $intMessageID));
					$wpdb->query($wpdb->prepare("DELETE FROM ".$wpdb->base_prefix."email_message WHERE messageID = '%d'", $intMessageID));
				}
				####################################

				// Trash bounce messages
				####################################
				foreach($arr_bounce_subject as $str_bounce_subject)
				{
					$result = $wpdb->get_results($wpdb->prepare("SELECT messageID FROM ".$wpdb->base_prefix."email_message INNER JOIN ".$wpdb->base_prefix."email_folder USING (folderID) WHERE folderType = '%d' AND messageName LIKE %s AND messageReceived < DATE_SUB(NOW(), INTERVAL 1 MONTH) AND messageDeleted = '0' ORDER BY messageReceived ASC LIMIT 0, 5", 6, $str_bounce_subject."%")); //, messageName, messageReceived

					foreach($result as $r)
					{
						$intMessageID = $r->messageID;

						//do_log("Trash ".$r->messageName." (#".$intMessageID.", ".$r->messageReceived.")");
						$json_output = array();
						$this->set_mail_info(array('message_id' => $intMessageID, 'mail_deleted' => 1), $json_output);
					}
				}
				####################################

				// Remove old "Original messages" information from large messages
				####################################
				$original_message_divider = "-------------------- ";

				$result = $wpdb->get_results($wpdb->prepare("SELECT messageID, messageName, messageText, messageText2, messageSize FROM ".$wpdb->base_prefix."email_message WHERE messageText LIKE %s OR messageText2 LIKE %s AND messageCreated < DATE_SUB(NOW(), INTERVAL 1 YEAR) ORDER BY RAND() LIMIT 0, 100", "%".$original_message_divider."%", "%".$original_message_divider."%"));

				foreach($result as $r)
				{
					$intMessageID = $r->messageID;
					$strMessageSubject = $r->messageName;
					$strMessageText = $r->messageText;
					$strMessageText2 = $r->messageText2;
					$intMessageSize = $r->messageSize;

					$mail_strpos = strpos($strMessageText, $original_message_divider);
					$mail_strpos2 = strpos($strMessageText2, $original_message_divider);

					if($mail_strpos > 0)
					{
						$length_before = strlen($strMessageText);

						$strMessageText = substr($strMessageText, 0, $mail_strpos);

						$length_after = strlen($strMessageText);

						//do_log("I shortened the plain message for '".$strMessageSubject."' (#".$intMessageID.") from ".$length_before." -> ".$length_after);

						$intMessageSize -= ($length_before - $length_after);

						$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email_message SET messageText = %s, messageSize = '%d' WHERE messageID = '%d'", $strMessageText, $intMessageSize, $intMessageID));
					}

					if($mail_strpos2 > 0)
					{
						$length_before = strlen($strMessageText2);

						$strMessageText2 = substr($strMessageText2, 0, $mail_strpos2);

						$length_after = strlen($strMessageText2);

						//do_log("I shortened the HTML message for '".$strMessageSubject."' from ".$length_before." -> ".$length_after);

						$intMessageSize -= ($length_before - $length_after);

						$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email_message SET messageText2 = %s, messageSize = '%d' WHERE messageID = '%d'", $strMessageText2, $intMessageSize, $intMessageID));
					}
				}
				####################################

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

				mf_uninstall_plugin(array(
					'options' => array('setting_smtp_test'),
				));
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

		if(IS_SUPER_ADMIN && (!function_exists('is_plugin_active') || function_exists('is_plugin_active') && is_plugin_active("mf_log/index.php") && get_site_option('setting_log_activate', get_option('setting_log_activate')) == 'yes'))
		{
			$arr_settings['setting_email_log'] = __("Log Outgoing Messages", 'lang_email');
		}

		$arr_settings['setting_email_preferred_content_types'] = __("Preferred Content Types", 'lang_email');
		//$arr_settings['setting_email_info'] = __("E-mail", 'lang_email');
		$arr_settings['setting_smtp_server'] = "SMTP ".__("Server", 'lang_email');

		if(get_option('setting_smtp_server') != '')
		{
			$arr_settings['setting_smtp_port'] = "SMTP ".__("Port", 'lang_email');
			$arr_settings['setting_smtp_ssl'] = "SMTP SSL";
			$arr_settings['setting_smtp_username'] = "SMTP ".__("Username", 'lang_email');
			$arr_settings['setting_smtp_password'] = "SMTP ".__("Password", 'lang_email');
		}

		/*$admin_email = get_bloginfo('admin_email');
		$wpdb->get_results($wpdb->prepare("SELECT emailID FROM ".$wpdb->base_prefix."email WHERE emailAddress = %s AND emailSmtpServer != ''", $admin_email));

		if($wpdb->num_rows > 0 || get_option('setting_smtp_server') != '')
		{*/
			$arr_settings['setting_smtp_test'] = __("Test", 'lang_email')." SMTP";
		//}

		show_settings_fields(array('area' => $options_area, 'object' => $this, 'settings' => $arr_settings));
	}

	function settings_email_callback()
	{
		$setting_key = get_setting_key(__FUNCTION__);

		echo settings_header($setting_key, __("E-mail", 'lang_email'));
	}

	function setting_email_log_callback()
	{
		$setting_key = get_setting_key(__FUNCTION__);
		settings_save_site_wide($setting_key);
		$option = get_site_option($setting_key, get_option($setting_key));

		$arr_data = array(
			'core' => __("Core", 'lang_email'),
			'plugin' => __("Plugin", 'lang_email'),
		);

		if(!function_exists('is_plugin_active') || function_exists('is_plugin_active') && is_plugin_active("mf_group/index.php"))
		{
			$arr_data['group'] = __("Group", 'lang_email');
		}

		$description = sprintf(__("The log can be viewed by going to %sTools -> Log -> Notice%s.", 'lang_email'), "<a href='".admin_url("admin.php?page=mf_log/list/index.php&post_status=notification")."'>", "</a>");
		// if get_site_url() is a subfolder, add to descr that some messages (ie. lost password) might be logged on the main site

		list($option, $description_temp) = setting_time_limit(array('key' => $setting_key, 'value' => $option, 'time_limit' => 24, 'return' => 'array'));
		$description .= " ".$description_temp;

		echo show_select(array('data' => $arr_data, 'name' => $setting_key."[]", 'value' => $option, 'description' => $description));
	}

	function setting_email_preferred_content_types_callback()
	{
		$setting_key = get_setting_key(__FUNCTION__);
		$option = get_option($setting_key);

		echo show_select(array('data' => $this->get_preferred_content_types_for_select(), 'name' => $setting_key."[]", 'value' => $option));
	}

	function setting_email_info_callback()
	{
		global $wpdb, $error_text;

		$admin_email = get_bloginfo('admin_email');

		echo "<p>".sprintf(__("The e-mail %s is used as sender address so this must be white listed in the SMTP, otherwise it can be caught in the servers spam filter", 'lang_email'), "<a href='".(is_multisite() ? admin_url("network/site-settings.php?id=".$wpdb->blogid."#admin_email") : admin_url("options-general.php"))."' class='bold'>".$admin_email."</a>")."</p>";

		$intEmailID = $wpdb->get_var($wpdb->prepare("SELECT emailID FROM ".$wpdb->base_prefix."email WHERE emailAddress = %s AND emailSmtpServer != ''", $admin_email));

		if($intEmailID > 0)
		{
			echo "<p>".sprintf(__("The e-mail %s already has an account where you have set an SMTP", 'lang_email'), "<a href='".admin_url("admin.php?page=mf_email/create/index.php&intEmailID=".$intEmailID)."' class='bold'>".$admin_email."</a>")."</p>";
		}

		if(IS_SUPER_ADMIN)
		{
			list($admin_email_prefix, $admin_email_domain) = explode("@", $admin_email);

			$exec_command = "dig +short TXT ".$admin_email_domain;
			exec($exec_command, $return_value);

			echo "<h4>".sprintf(__("TXT records for %s", 'lang_email'), $admin_email_domain)."</h4>";

			if(is_array($return_value) && count($return_value) > 0)
			{
				echo "<ul>";

					foreach($return_value as $return_row)
					{
						echo "<li>".$return_row."</li>";
					}

				echo "</ul>";
			}

			else
			{
				$error_text = sprintf(__("No return value on %s so you should add %s", 'lang_email'), "\"".$exec_command."\"", "\"v=spf1 ip4:".get_option_or_default('setting_server_ip', "[Server IP]")." ~all\"");
				//echo get_notification();
				echo $error_text;
			}
		}
	}

	function setting_smtp_server_callback()
	{
		$setting_key = get_setting_key(__FUNCTION__);
		$option = get_option($setting_key);

		echo show_textfield(array('name' => $setting_key, 'value' => $option));

		$this->setting_email_info_callback();
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

		echo show_password_field(array('name' => $setting_key, 'value' => $option, 'xtra' => " autocomplete='new-password'"));
	}

	function setting_smtp_test_callback()
	{
		echo show_textfield(array('name' => 'smtp_to', 'value' => '', 'placeholder' => __("E-mail to send test message to", 'lang_email'), 'description' => sprintf(__("Try with your e-mail address or create a temporary at %sMail Tester%s to check your spammyness", 'lang_email'), "<a href='//mail-tester.com'>", "</a>")))
		."<div class='form_button'>"
			.show_button(array('type' => 'button', 'name' => 'btnSmtpTest', 'text' => __("Send", 'lang_email'), 'class' => 'button-secondary'))
		."</div>"
		."<div id='smtp_debug'></div>";
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
			mf_enqueue_style('style_base_bb', $plugin_base_include_url."backbone/style.css", $plugin_version);

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

		$query_permission = " AND emailID IN ('".implode("','", $this->get_email_accounts_permission())."')";

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
			add_submenu_page($menu_start, $menu_title, " - ".$menu_title, $menu_capability, $menu_root."send/index.php");

			$menu_title = __("Accounts", 'lang_email');
			add_submenu_page($menu_start, $menu_title, $menu_title, $menu_capability, $menu_root."accounts/index.php");

			$menu_title = __("Add New", 'lang_email');
			add_submenu_page($menu_root, $menu_title, $menu_title, $menu_capability, $menu_root."create/index.php");

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

			$menu_title = __("Add New", 'lang_email');
			add_submenu_page($menu_start, $menu_title, " - ".$menu_title, $menu_capability, $menu_root."create/index.php");
		}

		if(IS_EDITOR)
		{
			$menu_title = __("Settings", 'lang_email');
			add_submenu_page($menu_start, $menu_title, $menu_title, $menu_capability, admin_url("options-general.php?page=settings_mf_base#settings_email"));
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
			$query_permission = " AND emailID IN ('".implode("','", $this->get_email_accounts_permission())."')";

			$intUnread = $wpdb->get_var("SELECT COUNT(messageID) FROM ".$wpdb->base_prefix."email_message INNER JOIN ".$wpdb->base_prefix."email_folder USING (folderID) WHERE messageRead = '0' AND folderType != '3'".$query_permission);

			if($intUnread > 0)
			{
				$array['reminder'][] = array(
					'title' => $intUnread > 1 ? __("There are %d unread emails in your inbox", $intUnread) : "There is one unread email in your inbox",
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

	function remove_attachment($data)
	{
		global $wpdb;

		if(!isset($data['file_id'])){		$data['file_id'] = 0;}
		if(!isset($data['message_id'])){	$data['message_id'] = 0;}

		if($data['file_id'] > 0)
		{
			$wpdb->query($wpdb->prepare("DELETE FROM ".$wpdb->base_prefix."email_message_attachment WHERE fileID = '%d'", $data['file_id']));
		}

		if($data['message_id'] > 0)
		{
			$result = $wpdb->get_results($wpdb->prepare("SELECT fileID FROM ".$wpdb->base_prefix."email_message_attachment WHERE messageID = '%d'", $data['message_id']));

			foreach($result as $r)
			{
				$this->remove_attachment(array('file_id' => $r->fileID));
			}
		}
	}

	function wp_trash_post($post_id)
	{
		global $wpdb;

		if(get_post_type($post_id) == 'attachment')
		{
			$wpdb->get_results($wpdb->prepare("SELECT fileID FROM ".$wpdb->base_prefix."email_message_attachment WHERE fileID = '%d'", $post_id));

			if($wpdb->num_rows > 0)
			{
				$this->remove_attachment(array('file_id' => $post_id));
			}
		}
	}

	function filter_is_file_used($arr_used)
	{
		global $wpdb;

		$result = $wpdb->get_results($wpdb->prepare("SELECT messageID FROM ".$wpdb->base_prefix."email_message WHERE messageDeleted = '0' AND (messageText LIKE %s OR messageText LIKE %s OR messageText2 LIKE %s OR messageText2 LIKE %s)", "%".$arr_used['file_url']."%", "%".$arr_used['file_thumb_url']."%", "%".$arr_used['file_url']."%", "%".$arr_used['file_thumb_url']."%"));
		$rows = $wpdb->num_rows;

		if($rows > 0)
		{
			$arr_used['amount'] += $rows;

			foreach($result as $r)
			{
				if($arr_used['example'] != '')
				{
					break;
				}

				$arr_used['example'] = admin_url("admin.php?page=mf_email/list/index.php#email/show/".$r->messageID);
			}
		}

		$result = $wpdb->get_results($wpdb->prepare("SELECT messageID FROM ".$wpdb->base_prefix."email_message_attachment WHERE fileID = '%d'", $arr_used['id']));
		$rows = $wpdb->num_rows;

		if($rows > 0)
		{
			$arr_used['amount'] += $rows;

			foreach($result as $r)
			{
				if($arr_used['example'] != '')
				{
					break;
				}

				$arr_used['example'] = admin_url("admin.php?page=mf_email/list/index.php#email/show/".$r->messageID);
			}
		}

		return $arr_used;
	}

	function wp_mail_from($old)
	{
		return (substr($old, 0, 10) == "wordpress@" ? get_option('admin_email') : $old);
	}

	function wp_mail_from_name($old)
	{
		return ($old == "WordPress" ? get_option('blogname') : $old);
	}

	function use_smtp_settings($phpmailer)
	{
		global $wpdb;

		$outgoing_type = 'smtp';
		$smtp_ssl = $smtp_host = $smtp_port = $smtp_hostname = $smtp_user = $smtp_pass = "";

		$result = $wpdb->get_results($wpdb->prepare("SELECT emailName, emailOutgoingType, emailSmtpSSL, emailSmtpServer, emailSmtpPort, emailSmtpHostname, emailSmtpUsername, emailSmtpPassword FROM ".$wpdb->base_prefix."email WHERE blogID = '%d' AND emailAddress = %s", $wpdb->blogid, $phpmailer->From));

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
					$phpmailer->SMTPDebug = (defined('SMTPDebug') ? SMTPDebug : false);

					$phpmailer->IsSMTP();
					//$phpmailer->Mailer = 'smtp';

					if($smtp_ssl == '')
					{
						$phpmailer->SMTPAutoTLS = false;
					}

					else
					{
						$phpmailer->SMTPSecure = $smtp_ssl;
					}

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

					/*$mail->SMTPOptions = array(
						'ssl' => [
							'verify_peer' => true,
							'verify_depth' => 3,
							'allow_self_signed' => true,
							'peer_name' => 'smtp.example.com',
							'cafile' => '/etc/ssl/ca_cert.pem',
							'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
						],
					);*/

					//$phpmailer = apply_filters('wp_mail_smtp_custom_options', $phpmailer);
				}
			break;

			default:
				do_action('email_outgoing_process', $outgoing_type, $smtp_user, $smtp_pass);
			break;
		}
	}

	function phpmailer_init($phpmailer)
	{
		global $wpdb, $obj_base;

		/* Log Messages */
		########################################
		$setting_email_log = get_site_option('setting_email_log');

		if(is_array($setting_email_log) && in_array('core', $setting_email_log))
		{
			if(!isset($obj_base))
			{
				$obj_base = new mf_base();
			}

			$obj_base->filter_phpmailer_data();

			$obj_microtime = new mf_microtime();

			do_log(__("Message Sent", 'lang_email')." (core): ".$obj_microtime->now.", ".var_export($obj_base->phpmailer_temp, true)." (".$_SERVER['REQUEST_URI'].")", 'notification');
		}
		########################################

		$phpmailer = $this->use_smtp_settings($phpmailer);
	}

	function sent_email($from)
	{
		global $wpdb;

		$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email SET emailSmtpVerified = '%d', emailSmtpChecked = NOW() WHERE emailAddress = %s", 1, $from));
	}

	function sent_email_error($from)
	{
		global $wpdb;

		$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email SET emailSmtpVerified = '%d', emailSmtpChecked = NOW() WHERE emailAddress = %s", -1, $from));
	}

	function get_emails_left_to_send($amount, $email, $type = '')
	{
		global $wpdb;

		if($type != '' && isset($this->emails_left_to_send[$type][$email]))
		{
			$amount_temp = $this->emails_left_to_send[$type][$email];
		}

		else
		{
			$amount_temp = 0;
			$query_where = "";

			if($email != '')
			{
				$emails_per_hour = $wpdb->get_var($wpdb->prepare("SELECT emailLimitPerHour FROM ".$wpdb->base_prefix."email WHERE emailAddress = %s", $email));

				if($emails_per_hour > 0)
				{
					$amount_temp += $emails_per_hour;
				}

				else if($amount == 0)
				{
					$amount_temp += 10000;
				}
			}

			else
			{
				if($amount == 0)
				{
					$amount_temp += 10000;
				}

				$query_where = " AND emailAddress = '".esc_sql($email)."'";
			}

			$wpdb->get_results("SELECT messageID FROM ".$wpdb->base_prefix."email INNER JOIN ".$wpdb->base_prefix."email_folder USING (emailID) INNER JOIN ".$wpdb->base_prefix."email_message USING (folderID) WHERE messageFrom = '' AND messageCreated > DATE_SUB(NOW(), INTERVAL 1 HOUR)".$query_where);

			$amount_temp -= $wpdb->num_rows;

			if($type != '')
			{
				$this->emails_left_to_send[$type][$email] = $amount_temp;
			}
		}

		if($type != '')
		{
			$this->emails_left_to_send[$type][$email]--;
		}

		return ($amount + $amount_temp);
	}

	function get_hourly_release_time($datetime, $email)
	{
		global $wpdb;

		if($datetime == '')
		{
			$datetime = date("Y-m-d H:i:s");
		}

		$query_where = "";

		if($email != '')
		{
			$query_where = " AND emailAddress = '".esc_sql($email)."'";
		}

		$datetime_temp = $wpdb->get_var("SELECT messageCreated FROM ".$wpdb->base_prefix."email INNER JOIN ".$wpdb->base_prefix."email_folder USING (emailID) INNER JOIN ".$wpdb->base_prefix."email_message USING (folderID) WHERE messageFrom = '' AND messageCreated > DATE_SUB(NOW(), INTERVAL 1 HOUR)".$query_where." ORDER BY messageCreated ASC LIMIT 0, 1");

		if($datetime_temp > DEFAULT_DATE && $datetime_temp < $datetime)
		{
			$datetime = $datetime_temp;
		}

		return $datetime;
	}

	function send_smtp_test()
	{
		global $phpmailer, $done_text, $error_text;

		$mail_to = check_var('smtp_to', 'email');

		if($mail_to != '')
		{
			$user_data = get_userdata(get_current_user_id());

			$mail_subject = sprintf(__("Test mail to %s", 'lang_email'), $mail_to);
			$mail_content = "<p>".__("Hello!", 'lang_email')."</p>"
				."<p>".sprintf(__("This is a test email generated from %s on %s", 'lang_email'), "Wordpress", remove_protocol(array('url' => get_site_url(), 'clean' => true)))."</p>"
				."<p>".__("Regards", 'lang_email')."</p>"
				."<p>".$user_data->first_name."</p>";

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

				if($smtp_debug != '')
				{
					$error_text .= "<p>".sprintf(__("Debug %s", 'lang_email'), "SMTP").":</p>
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
		global $error_text;

		switch($this->type)
		{
			case 'account_create':
				$this->server = check_var('strEmailServer');
				$this->port = check_var('intEmailPort');
				$this->username = check_var('strEmailUsername');
				$this->password = check_var('strEmailPassword');
				$this->address = check_var('strEmailAddress');
				$this->name = check_var('strEmailName');
				$this->signature = check_var('strEmailSignature');

				$this->outgoing_type = check_var('strEmailOutgoingType');
				$this->limit_per_hour = check_var('intEmailLimitPerHour');
				$this->smtp_server = check_var('strEmailSmtpServer');
				$this->smtp_port = check_var('intEmailSmtpPort');
				$this->smtp_ssl = check_var('strEmailSmtpSSL');
				$this->smtp_hostname = check_var('strEmailSmtpHostname');
				$this->smtp_username = check_var('strEmailSmtpUsername');
				$this->smtp_password = check_var('strEmailSmtpPassword');
				$this->preferred_content_types = check_var('arrEmailPreferredContentTypes');

				$this->public = check_var('intEmailPublic');
				$this->roles = check_var('arrEmailRoles');
				$this->users = check_var('arrEmailUsers');

				$this->password_encrypted = $this->smtp_password_encrypted = "";
			break;

			case 'send_email':
				$this->message_id = check_var('intMessageID');
				$this->message_draft_id = check_var('intMessageDraftID');
				$this->message_answer = isset($_GET['answer']);
				$this->message_forward = isset($_GET['forward']);

				$this->id = check_var('intEmailID', 'int', true, $this->get_from_last());
				$this->message_to = check_var('strMessageTo');
				$this->message_cc = check_var('strMessageCc');
				$this->message_subject = check_var('strMessageSubject');
				$this->message_text = check_var('strMessageText', 'raw');
				$this->message_attachment = check_var('strMessageAttachment');
				$this->message_text_source = check_var('intEmailTextSource');

				$this->group_message_id = check_var('intGroupMessageID');

				$this->all_left_to_send = apply_filters('get_emails_left_to_send', 0, '');

				if($this->all_left_to_send == 0)
				{
					$error_text = __("The e-mail limit for the last hour has been reached so you can not send anymore e-mails at them moment. Save as a draft and check back in a moment", 'lang_email');
				}
			break;
		}
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

	function convert_characters($string)
	{
		$arr_exclude = array("å", "ä", "ö", "Å", "Ä", "Ö"); // Å should be replaced with the 'wrong' character when found
		$arr_include = array("å", "ä", "ö", "Å", "Ä", "Ö");

		return str_replace($arr_exclude, $arr_include, $string);
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

								list($is_connected, $connection) = $this->email_connect(array('server' => $strEmailServer, 'port' => $intEmailPort, 'username' => $strEmailUsername, 'password' => $strEmailPassword, 'debug' => true));

								if($is_connected == true)
								{
									$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email SET emailVerified = '1' WHERE emailID = '%d'", $this->id));

									$done_text = __("The e-mail account passed verification", 'lang_email');
								}

								else
								{
									$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email SET emailVerified = '-1' WHERE emailID = '%d'", $this->id));

									$error_text = __("The e-mail account did not pass the verification", 'lang_email');
								}
							}

							else
							{
								$user_data = get_userdata(get_current_user_id());

								$site_title = get_bloginfo('name');
								$site_url = get_site_url();
								$confirm_url = wp_nonce_url(admin_url("admin.php?page=mf_email/accounts/index.php&btnEmailConfirm&intEmailID=".$this->id), 'email_confirm_'.$this->id, '_wpnonce_email_confirm');

								$mail_to = $strEmailAddress;
								$mail_headers = "From: ".$user_data->display_name." <".$user_data->user_email.">\r\n";
								$mail_subject = sprintf(__("Please confirm your e-mail %s for use on %s", 'lang_email'), $strEmailAddress, $site_title);
								$mail_content = sprintf(__("We have gotten a request to confirm the address %s from a user at %s (%s). If this is a valid request please click %shere%s to confirm the use of your e-mail address to send messages", 'lang_email'), $strEmailAddress, $site_title, "<a href='".$site_url."'>".$site_url."</a>", "<a href='".$confirm_url."'>", "</a>");

								$sent = send_email(array(
									'from' => $user_data->user_email,
									'from_name' => $user_data->display_name,
									'to' => $mail_to,
									'subject' => $mail_subject,
									'content' => $mail_content,
									'headers' => $mail_headers,
								));

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

			case 'send_email':
				if(isset($_POST['btnMessageSend']) && wp_verify_nonce($_POST['_wpnonce_message_send'], 'message_send') && $error_text == '')
				{
					if($this->id > 0 && $this->message_to != '' && $this->message_subject != '' && $this->message_text != '')
					{
						$result = $wpdb->get_results($wpdb->prepare("SELECT emailName, emailAddress, emailSignature FROM ".$wpdb->base_prefix."email WHERE emailID = '%d'", $this->id));

						foreach($result as $r)
						{
							$strEmailName = $r->emailName;
							$strEmailAddress = $r->emailAddress;
							$strEmailSignature = $r->emailSignature;

							$this->message_to = $this->validate_email_string($this->message_to);
							$this->message_cc = $this->validate_email_string($this->message_cc);

							$mail_headers = "From: ".$strEmailName." <".$strEmailAddress.">\r\n";

							if($this->message_cc != '')
							{
								$mail_headers .= "Cc: ".$this->message_cc."\r\n";
							}

							$this->message_subject = stripslashes(stripslashes($this->message_subject));
							$this->message_text = str_replace("[signature]", $strEmailSignature, $this->message_text);
							$this->message_text = apply_filters('the_content', stripslashes($this->message_text));

							list($mail_attachment, $rest) = get_attachment_to_send($this->message_attachment);

							$sent = send_email(array(
								'from' => $strEmailAddress,
								'from_name' => $strEmailName,
								'to' => $this->message_to,
								'subject' => $this->message_subject,
								'content' => $this->message_text,
								'headers' => $mail_headers,
								'attachment' => $mail_attachment,
							));

							if($sent)
							{
								$intFolderID = $this->get_folder_ids(__("Sent", 'lang_email'), 4, $this->id);

								list($this->message_id, $affected_rows) = $this->save_email(array('read' => 1, 'folder_id' => $intFolderID, 'to' => $this->message_to, 'cc' => $this->message_cc, 'subject' => $this->message_subject, 'content_html' => $this->message_text));

								if($this->message_id > 0)
								{
									if($this->message_attachment != '')
									{
										$arr_attachments = explode(",", $this->message_attachment);

										foreach($arr_attachments as $attachment)
										{
											@list($file_name, $file_url, $file_id) = explode("|", $attachment);

											if($file_id > 0){}

											else if($file_url != '')
											{
												//$file_url_check = WP_CONTENT_DIR.str_replace(site_url()."/wp-content", "", $file_url);
												$file_url_check = str_replace(WP_CONTENT_URL, WP_CONTENT_DIR, $file_url);

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
												$wpdb->query($wpdb->prepare("INSERT INTO ".$wpdb->base_prefix."email_message_attachment SET messageID = '%d', fileID = '%d'", $this->message_id, $file_id));
											}

											else
											{
												$error_text = __("Could not save the attached file to DB, but it was successfully sent", 'lang_email');
											}
										}
									}

									if(!isset($error_text) || $error_text == '')
									{
										mf_redirect(admin_url("admin.php?page=mf_email/list/index.php&sent"));
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
						$error_text = __("You have to choose who to send from and to, a subject and text", 'lang_email');
					}
				}

				else if(isset($_POST['btnMessageDraft']) && wp_verify_nonce($_POST['_wpnonce_message_send'], 'message_send'))
				{
					$intFolderID = $this->get_folder_ids(__("Draft"), 5, $this->id);

					list($this->message_id, $affected_rows) = $this->save_email(array('id' => $this->message_draft_id, 'folder_id' => $intFolderID, 'to' => $this->message_to, 'cc' => $this->message_cc, 'subject' => $this->message_subject, 'content_html' => $this->message_text));

					if($affected_rows > 0)
					{
						$done_text = __("The draft has been saved", 'lang_email');
					}
				}

				else if($this->group_message_id > 0)
				{
					$result = $wpdb->get_results($wpdb->prepare("SELECT messageFrom, messageName, messageText FROM ".$wpdb->prefix."group_message WHERE messageID = '%d'", $this->group_message_id));

					foreach($result as $r)
					{
						$this->message_subject = $r->messageName;
						$this->message_text = stripslashes($r->messageText);
					}
				}

				else if($this->message_text_source > 0)
				{
					$this->message_text = $wpdb->get_var($wpdb->prepare("SELECT post_content FROM ".$wpdb->posts." WHERE post_type = %s AND post_status = %s AND ID = '%d'", 'page', 'publish', $this->message_text_source));

					$this->message_text = str_replace("[name]", get_user_info(), $this->message_text);

					$this->message_text = $this->convert_characters($this->message_text);
				}

				else if($this->message_cc == '' && $this->message_subject == '' && $this->message_text == '')
				{
					if($this->message_draft_id > 0)
					{
						$result = $wpdb->get_results($wpdb->prepare("SELECT emailID, messageTo, messageCc, messageName, messageText2 FROM ".$wpdb->base_prefix."email_message INNER JOIN ".$wpdb->base_prefix."email_folder USING (folderID) WHERE messageDeleted = '0' AND messageID = '%d'", $this->message_draft_id));

						foreach($result as $r)
						{
							$this->id = $r->emailID;
							$this->message_to = $r->messageTo;
							$this->message_cc = $r->messageCc;
							$this->message_subject = $r->messageName;
							$this->message_text = $r->messageText2;
						}
					}

					else if($this->message_id > 0)
					{
						$result = $wpdb->get_results("SELECT ".$wpdb->base_prefix."email.emailID, emailAddress, messageFrom, messageFromName, messageTo, messageCc, messageReplyTo, messageName, messageText, messageCreated, ".$wpdb->base_prefix."email_message.userID FROM ".$wpdb->base_prefix."email_users RIGHT JOIN ".$wpdb->base_prefix."email USING (emailID) INNER JOIN ".$wpdb->base_prefix."email_folder USING (emailID) INNER JOIN ".$wpdb->base_prefix."email_message USING (folderID) WHERE ".$wpdb->base_prefix."email_message.messageID = '".esc_sql($this->message_id)."' AND (emailPublic = '1' OR emailRoles LIKE '%".get_current_user_role()."%' OR ".$wpdb->base_prefix."email.userID = '".get_current_user_id()."' OR ".$wpdb->base_prefix."email_users.userID = '".get_current_user_id()."') LIMIT 0, 1");

						foreach($result as $r)
						{
							$this->id = $r->emailID;
							$strEmailAddress = $r->emailAddress;
							$strMailFrom = $r->messageFrom;
							$strMailFromName = $r->messageFromName;
							$strMailTo = $r->messageTo;
							$this->message_subject_old = $r->messageName;
							$this->message_text = $r->messageText;
							$dteMessageCreated = $r->messageCreated;
							$intUserID2 = $r->userID;

							if($this->message_forward == 0)
							{
								$arrMessageReplyTo_temp = $this->get_email_address_from_text($r->messageReplyTo);

								if($arrMessageReplyTo_temp != '' && is_array($arrMessageReplyTo_temp))
								{
									foreach($arrMessageReplyTo_temp as $strMessageReplyTo_temp)
									{
										$this->message_to .= " ".$strMessageReplyTo_temp;
									}
								}

								if($this->message_to == '')
								{
									$strMessageFrom_temp = $strMailFrom;

									if($strMessageFrom_temp != '')
									{
										$this->message_to .= " ".$strMessageFrom_temp;
									}
								}

								if($this->message_answer == 1)
								{
									$arrMessageTo_temp = $this->get_email_address_from_text($strMailTo);

									if(is_array($arrMessageTo_temp))
									{
										foreach($arrMessageTo_temp as $this->message_to_temp)
										{
											$this->message_cc .= " ".$this->message_to_temp;
										}
									}

									else
									{
										$this->message_cc .= " ".$arrMessageTo_temp;
									}

									$arrMessageCc_temp = $this->get_email_address_from_text($r->messageCc);

									if(is_array($arrMessageCc_temp))
									{
										foreach($arrMessageCc_temp as $this->message_cc_temp)
										{
											$this->message_cc .= " ".$this->message_cc_temp;
										}
									}

									else
									{
										$this->message_cc .= " ".$arrMessageCc_temp;
									}
								}

								$this->message_to = trim(str_replace($strEmailAddress, "", $this->message_to));
								$this->message_cc = trim(str_replace($strEmailAddress, "", $this->message_cc));
							}

							if($this->message_forward == 1 || $this->message_answer == 1)
							{
								$subject_prefix = $this->message_forward == 1 ? "Fwd: " : "Re: ";

								$email_outgoing = $intUserID2 == '' || $strMailFrom != '' ? false : true;

								$strFrom = $email_outgoing == false ? $strMailFromName." <".$strMailFrom.">" : $strEmailAddress;
								$strTo = $email_outgoing == false ? $strEmailAddress." (".$strMailTo.")" : $strMailTo;

								$this->message_subject = (substr($this->message_subject_old, 0, strlen($subject_prefix)) != $subject_prefix ? $subject_prefix : "").$this->message_subject_old;

								$this->message_text = "<p></p><p>-------------------- ".__("Original message", 'lang_email')." --------------------</p>"
								."<p>".__("From", 'lang_email').": ".$strFrom."</p>"
								."<p>".__("To", 'lang_email').": ".$strTo."</p>"
								."<p>".__("Subject", 'lang_email').": ".$this->message_subject."</p>"
								."<p>".__("Date", 'lang_email').": ".$dteMessageCreated."</p>"
								."<p>"."-------------------</p>"
								."<p>".preg_replace('#^(.*?)$#m', '<br>&gt; \1', strip_tags($this->message_text, '<br>'))."</p>"
								."<p>------------------ ".__("End original message", 'lang_email')." ------------------</p>";

								if($this->message_forward == 1)
								{
									$result = $wpdb->get_results($wpdb->prepare("SELECT fileID FROM ".$wpdb->base_prefix."email_message_attachment WHERE messageID = '%d'", $this->message_id));

									foreach($result as $r)
									{
										list($file_name, $file_url) = get_attachment_data_by_id($r->fileID);

										$this->message_attachment .= ($this->message_attachment != '' ? "," : "").$file_name."|".$file_url."|".$r->fileID;
									}
								}
							}
						}
					}
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
					$result = $wpdb->get_results($wpdb->prepare("SELECT emailPublic, emailRoles, emailServer, emailPort, emailUsername, emailAddress, emailName, emailSignature, emailOutgoingType, emailLimitPerHour, emailSmtpSSL, emailSmtpServer, emailSmtpPort, emailSmtpHostname, emailSmtpUsername, emailPreferredContentTypes, emailDeleted FROM ".$wpdb->base_prefix."email WHERE emailID = '%d'", $this->id));

					foreach($result as $r)
					{
						$this->public = $r->emailPublic;
						$this->roles = $r->emailRoles;
						$this->server = $r->emailServer;
						$this->port = $r->emailPort;
						$this->username = $r->emailUsername;
						$this->address = $r->emailAddress;
						$this->name = $r->emailName;
						$this->signature = $r->emailSignature;
						$this->outgoing_type = $r->emailOutgoingType;
						$this->limit_per_hour = $r->emailLimitPerHour;
						$this->smtp_ssl = $r->emailSmtpSSL;
						$this->smtp_server = $r->emailSmtpServer;
						$this->smtp_port = $r->emailSmtpPort;
						$this->smtp_hostname = $r->emailSmtpHostname;
						$this->smtp_username = $r->emailSmtpUsername;
						$this->preferred_content_types = $r->emailPreferredContentTypes;
						$this->deleted = $r->emailDeleted;

						if($this->roles != '')
						{
							$this->roles = explode(",", $this->roles);
						}

						if($this->preferred_content_types != '')
						{
							$this->preferred_content_types = explode(",", $this->preferred_content_types);
						}

						else
						{
							$this->preferred_content_types = get_option('setting_email_preferred_content_types');
						}

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

			case 'send_email':
				if($this->message_text == '' || !preg_match("/\[signature]/", $this->message_text))
				{
					$wpdb->get_results("SELECT emailID FROM ".$wpdb->base_prefix."email WHERE emailSignature != '' LIMIT 0, 1");

					if($wpdb->num_rows > 0)
					{
						$this->message_text .= "\n\n[signature]";
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

		$intFolderID = $this->get_folder_ids(__("Sent", 'lang_email'), 4, $id);

		$sent = $wpdb->get_var($wpdb->prepare("SELECT COUNT(messageID) FROM ".$wpdb->base_prefix."email_message WHERE folderID = '%d' AND messageDeleted = '0'", $intFolderID));
		$received = $wpdb->get_var($wpdb->prepare("SELECT COUNT(messageID) FROM ".$wpdb->base_prefix."email_message INNER JOIN ".$wpdb->base_prefix."email_folder USING (folderID) WHERE emailID = '%d' AND messageDeleted = '0'", $id));

		return array(
			'sent' => $sent,
			'received' => ($received - $sent),
		);
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

		if($this->from_address == '')
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

		$result = $wpdb->get_results("SELECT ".$wpdb->base_prefix."email.emailID, emailName, emailAddress FROM ".$wpdb->base_prefix."email_users RIGHT JOIN ".$wpdb->base_prefix."email USING (emailID) WHERE (emailPublic = '1' OR emailRoles LIKE '%".get_current_user_role()."%' OR ".$wpdb->base_prefix."email.userID = '".get_current_user_id()."' OR ".$wpdb->base_prefix."email_users.userID = '".get_current_user_id()."') AND (blogID = '".$wpdb->blogid."' OR blogID = '0') AND emailDeleted = '0' AND emailAddress != ''".$query_where." GROUP BY ".$wpdb->base_prefix."email.emailID ORDER BY emailName ASC, emailAddress ASC");

		foreach($result as $r)
		{
			$intEmailID2 = $r->emailID;
			$strEmailName = $strEmailName_orig = $r->emailName;
			$strEmailAddress = $r->emailAddress;

			$strEmailName = ($strEmailName != '' ? $strEmailName." &lt;".$strEmailAddress."&gt;" : $strEmailAddress);

			$key_prefix = "";

			$left_to_send = apply_filters('get_emails_left_to_send', 0, $strEmailAddress);

			if($left_to_send == 0)
			{
				$key_prefix = "disabled_";

				$hourly_release_time = apply_filters('get_hourly_release_time', '', $strEmailAddress);
				$mins = time_between_dates(array('start' => $hourly_release_time, 'end' => date("Y-m-d H:i:s"), 'type' => 'round', 'return' => 'minutes'));

				$strEmailName .= " (".sprintf(__("Hourly Limit Reached. Wait %s min", 'lang_email'), (60 - $mins)).")";
			}

			switch($data['index'])
			{
				case 'id':
					$arr_data[$key_prefix.$intEmailID2] = $strEmailName;
				break;

				case 'address':
					$arr_data[$key_prefix.$strEmailName_orig."|".$strEmailAddress] = $strEmailName;
				break;
			}
		}

		if(count($arr_data) <= 1 && $allow_fallback == true)
		{
			$user_data = get_userdata(get_current_user_id());

			$user_name = $user_data->display_name;
			$user_email = $user_data->user_email;
			$site_title = get_bloginfo('name');
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
				$this->name = $site_title;
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
							$arr_data[$this->id] = $site_title." &lt;".$admin_email."&gt;";
						break;

						case 'address':
							$arr_data[$site_title."|".$admin_email] = $site_title." &lt;".$admin_email."&gt;";
						break;
					}
				}
			}
		}

		return $arr_data;
	}

	function encrypt_password()
	{
		$obj_encryption = new mf_email_encryption("email");

		if(isset($this->password) && $this->password != '' && $this->address != '')
		{
			$this->password_encrypted = $obj_encryption->encrypt($this->password, md5($this->address));
		}

		if(isset($this->smtp_password) && $this->smtp_password != '' && $this->address != '')
		{
			$this->smtp_password_encrypted = $obj_encryption->encrypt($this->smtp_password, md5($this->address));
		}
	}

	function check_if_account_exists()
	{
		global $wpdb;

		$intEmailID = $wpdb->get_var($wpdb->prepare("SELECT emailID FROM ".$wpdb->base_prefix."email WHERE blogID = '%d' AND emailAddress = %s", $wpdb->blogid, $this->address));

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
				do_log("The encrypted password was longer than the max length in DB (".strlen($this->password_encrypted).")");
			}

			$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email SET emailPassword = %s WHERE emailID = '%d'", $this->password_encrypted, $this->id)); // AND userID = '%d', get_current_user_id()

			$rows_affected += $wpdb->rows_affected;
		}

		if($this->smtp_password_encrypted != '')
		{
			if(strlen($this->smtp_password_encrypted) > 150)
			{
				do_log("The encrypted password was longer than the max length in DB (".strlen($this->smtp_password_encrypted).")");
			}

			$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email SET emailSmtpPassword = %s WHERE emailID = '%d'", $this->smtp_password_encrypted, $this->id));

			$rows_affected += $wpdb->rows_affected;
		}

		return ($rows_affected > 0);
	}

	function update_rights()
	{
		global $wpdb;

		$was_updated = false;

		$wpdb->query($wpdb->prepare("DELETE FROM ".$wpdb->base_prefix."email_users WHERE emailID = '%d'", $this->id));

		if($wpdb->rows_affected > 0)
		{
			$was_updated = true;
		}

		if(is_array($this->users))
		{
			foreach($this->users as $intUserID)
			{
				$wpdb->query($wpdb->prepare("INSERT INTO ".$wpdb->base_prefix."email_users SET emailID = '%d', userID = '%d'", $this->id, $intUserID));

				if($wpdb->rows_affected > 0)
				{
					$was_updated = true;
				}
			}
		}

		return $was_updated;
	}

	function create_account()
	{
		global $wpdb;

		$this->encrypt_password();

		if(is_array($this->roles))
		{
			$this->roles = implode(",", $this->roles);
		}

		if(is_array($this->preferred_content_types))
		{
			$this->preferred_content_types = implode(",", $this->preferred_content_types);
		}

		$wpdb->query($wpdb->prepare("INSERT INTO ".$wpdb->base_prefix."email SET blogID = '%d', emailPublic = '%d', emailRoles = %s, emailServer = %s, emailPort = '%d', emailUsername = %s, emailAddress = %s, emailName = %s, emailSignature = %s, emailOutgoingType = %s, emailLimitPerHour = '%d', emailSmtpSSL = %s, emailSmtpServer = %s, emailSmtpPort = '%d', emailSmtpHostname = %s, emailSmtpUsername = %s, emailPreferredContentTypes = %s, emailCreated = NOW(), userID = '%d'", $wpdb->blogid, $this->public, $this->roles, $this->server, $this->port, $this->username, $this->address, $this->name, $this->signature, $this->outgoing_type, $this->limit_per_hour, $this->smtp_ssl, $this->smtp_server, $this->smtp_port, $this->smtp_hostname, $this->smtp_username, $this->preferred_content_types, get_current_user_id()));

		$this->id = $wpdb->insert_id;

		return ($this->id > 0 && ($wpdb->rows_affected > 0 || $this->update_passwords() || $this->update_rights()));
	}

	function update_account()
	{
		global $wpdb;

		$this->encrypt_password();

		if(is_array($this->roles))
		{
			$this->roles = implode(",", $this->roles);
		}

		if(is_array($this->preferred_content_types))
		{
			$this->preferred_content_types = implode(",", $this->preferred_content_types);
		}

		$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email SET blogID = '%d', emailPublic = '%d', emailRoles = %s, emailVerified = '0', emailServer = %s, emailPort = '%d', emailUsername = %s, emailAddress = %s, emailName = %s, emailSignature = %s, emailOutgoingType = %s, emailLimitPerHour = '%d', emailSmtpSSL = %s, emailSmtpServer = %s, emailSmtpPort = '%d', emailSmtpHostname = %s, emailSmtpUsername = %s, emailPreferredContentTypes = %s, emailDeleted = '0' WHERE emailID = '%d'", $wpdb->blogid, $this->public, $this->roles, $this->server, $this->port, $this->username, $this->address, $this->name, $this->signature, $this->outgoing_type, $this->limit_per_hour, $this->smtp_ssl, $this->smtp_server, $this->smtp_port, $this->smtp_hostname, $this->smtp_username, $this->preferred_content_types, $this->id));

		return ($wpdb->rows_affected > 0 || $this->update_passwords() || $this->update_rights());
	}
}

class mf_email_encryption
{
	var $key;
	var $encrypt_method;
	var $iv;

	function __construct($type)
	{
		$this->set_key($type);

		if(function_exists('mcrypt_create_iv') && function_exists('mcrypt_get_iv_size'))
		{
			$this->iv = @mcrypt_create_iv(@mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_ECB), MCRYPT_RAND);
		}

		else
		{
			$this->encrypt_method = 'AES-256-CBC';

			if(!in_array($this->encrypt_method, openssl_get_cipher_methods()) && !in_array(strtolower($this->encrypt_method), openssl_get_cipher_methods()))
			{
				do_log("Encryption: ".$this->encrypt_method." does not exist in ".var_export(openssl_get_cipher_methods(), true));
			}

			$this->iv = substr(hash('sha256', $this->key), 0, 16);
		}
	}

	function set_key($type)
	{
		if(function_exists('mcrypt_encrypt'))
		{
			$this->key = substr("mf_crypt".$type, 0, 32);
		}

		else
		{
			$this->key = hash('sha256', "mf_crypt".$type);
		}
	}

	function encrypt($text, $key = '')
	{
		if($key != '')
		{
			$this->set_key($key);
		}

		if(function_exists('mcrypt_encrypt'))
		{
			$text_encrypted = base64_encode(mcrypt_encrypt(MCRYPT_RIJNDAEL_256, $this->key, $text, MCRYPT_MODE_ECB, $this->iv));
		}

		else
		{
			$text_encrypted = base64_encode(openssl_encrypt($text, $this->encrypt_method, $this->key, 0, $this->iv));
		}

		return $text_encrypted;
	}

	function decrypt($text, $key = '')
	{
		if($key != '')
		{
			$this->set_key($key);
		}

		if(function_exists('mcrypt_encrypt'))
		{
			return trim(@mcrypt_decrypt(MCRYPT_RIJNDAEL_256, $this->key, base64_decode($text), MCRYPT_MODE_ECB, $this->iv));
		}

		else
		{
			return openssl_decrypt(base64_decode($text), $this->encrypt_method, $this->key, 0, $this->iv);
		}
	}
}

if(class_exists('mf_list_table'))
{
	class mf_email_account_table extends mf_list_table
	{
		function set_default()
		{
			global $wpdb;

			$this->arr_settings['query_from'] = $wpdb->base_prefix."email";
			$this->post_type = '';

			$this->arr_settings['query_select_id'] = "emailID";
			$this->arr_settings['query_all_id'] = "0";
			$this->arr_settings['query_trash_id'] = "1";
			$this->orderby_default = "emailDeleted ASC, ".$wpdb->base_prefix."email.userID ASC, emailUsername";

			//$this->arr_settings['has_autocomplete'] = true;
			//$this->arr_settings['plugin_name'] = 'mf_email';
		}

		function init_fetch()
		{
			global $wpdb, $obj_email;

			$this->query_join .= " LEFT JOIN ".$wpdb->base_prefix."email_users USING (emailID)";
			$this->query_where .= ($this->query_where != '' ? " AND " : "")."(emailPublic = '1' OR emailRoles LIKE '%".get_current_user_role()."%' OR ".$wpdb->base_prefix."email.userID = '".get_current_user_id()."' OR ".$wpdb->base_prefix."email_users.userID = '".get_current_user_id()."') AND (blogID = '".$wpdb->blogid."' OR blogID = '0')";

			if($this->search != '')
			{
				$this->query_where .= ($this->query_where != '' ? " AND " : "")."(emailAddress LIKE '".$this->filter_search_before_like($this->search)."' OR emailName LIKE '".$this->filter_search_before_like($this->search)."' OR emailUsername LIKE '".$this->filter_search_before_like($this->search)."' OR emailServer LIKE '".$this->filter_search_before_like($this->search)."')";
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
				//'received' => __("Received", 'lang_email'),
				//'sent' => __("Sent", 'lang_email'),
				'emailServer' => __("Incoming", 'lang_email'),
				'emailSmtpServer' => __("Outgoing", 'lang_email'),
				'userID' => shorten_text(array('string' => __("User", 'lang_email'), 'limit' => 4)),
			);

			$this->set_columns($arr_columns);

			$this->set_sortable_columns(array(
				'emailAddress',
				'emailName',
				'emailServer',
				'emailSmtpServer',
				'userID',
			));
		}

		function column_default($item, $column_name)
		{
			global $wpdb, $obj_email;

			$out = "";

			$intEmailID = $item['emailID'];

			switch($column_name)
			{
				case 'emailAddress':
					$strEmailAddress = $item['emailAddress'];
					$intUserID = $item['userID'];
					$intEmailDeleted = $item['emailDeleted'];

					$email_url = admin_url("admin.php?page=mf_email/create/index.php&intEmailID=".$intEmailID);

					$out .= "<a href='".$email_url."'>"
						.$strEmailAddress
					."</a>";

					$actions = array();

					if($intEmailDeleted == 0)
					{
						$actions['edit'] = "<a href='".$email_url."'>".__("Edit", 'lang_email')."</a>";

						if(IS_ADMINISTRATOR || $intUserID == get_current_user_id())
						{
							$actions['delete'] = "<a href='".wp_nonce_url(admin_url("admin.php?page=mf_email/accounts/index.php&btnEmailDelete&intEmailID=".$intEmailID), 'email_delete_'.$intEmailID, '_wpnonce_email_delete')."' rel='confirm'>".__("Delete", 'lang_email')."</a>";
						}

						$actions['send'] = "<a href='".admin_url("admin.php?page=mf_email/send/index.php&intEmailID=".$intEmailID)."'><i class='fa fa-paper-plane fa-lg' title='".__("Send Message", 'lang_email')."'></i></a>";
					}

					else
					{
						$actions['send'] = "<a href='".$email_url."'>".__("Recover", 'lang_email')."</a>";
					}

					$out .= $this->row_actions($actions);
				break;

				case 'emailName':
					$out .= $item['emailName'];

					$actions = array();

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

					$rights_icon = $rights_title = "";
					$arr_rights = array();

					if($intEmailPublic == 1)
					{
						$rights_icon = "fa fa-check green";
						$rights_title = __("Public", 'lang_email');

						$arr_rights[] = array(
							'icon' => $rights_icon,
							'title' => $rights_title,
						);
					}

					if($strEmailRoles != '')
					{
						$arr_roles = get_roles_for_select(array('use_capability' => false));

						$arrEmailRoles = explode(",", $strEmailRoles);

						$rights_title = "";

						foreach($arrEmailRoles as $role)
						{
							$rights_title .= ($rights_title != '' ? ", " : "").$arr_roles[$role];
						}

						$rights_icon = "fa fa-users";

						if(count($arrEmailRoles) == 1)
						{
							$rights_icon .= " grey";
						}

						$arr_rights[] = array(
							'icon' => $rights_icon,
							'title' => $rights_title,
						);
					}

					$resultUsers = $wpdb->get_results($wpdb->prepare("SELECT userID FROM ".$wpdb->base_prefix."email_users WHERE emailID = '%d'", $intEmailID));

					if($wpdb->num_rows > 0)
					{
						$rights_title = "";

						foreach($resultUsers as $r)
						{
							$rights_title .= ($rights_title != '' ? ", " : "").get_user_info(array('id' => $r->userID));
						}

						$rights_icon = "fa fa-user";

						if(count($resultUsers) == 1)
						{
							$rights_icon .= " grey";
						}

						$arr_rights[] = array(
							'icon' => $rights_icon,
							'title' => $rights_title,
						);
					}

					$i = 0;

					foreach($arr_rights as $row)
					{
						$out .= ($i > 0 ? "&nbsp;" : "")."<i class='".$row['icon']." fa-lg' title='".$row['title']."'></i>";

						$i++;
					}
				break;

				/*case 'received':
					$arr_message_amount = $obj_email->get_message_amount($intEmailID);

					$out .= $arr_message_amount['received'];
				break;

				case 'sent':
					$arr_message_amount = $obj_email->get_message_amount($intEmailID);

					$out .= $arr_message_amount['sent'];
				break;*/

				case 'emailServer':
					$strEmailServer = $item['emailServer'];

					if($strEmailServer != '')
					{
						$intEmailVerified = $item['emailVerified'];
						$dteEmailChecked = $item['emailChecked'];

						$row_info = $row_actions = "";

						if($item['emailPassword'] == '')
						{
							$row_info .= "<i class='fa fa-times red display_warning'></i> ".__("No Password", 'lang_email');
						}

						else
						{
							switch($intEmailVerified)
							{
								default:
								case 0:
									$row_info .= "<i class='fa fa-question fa-lg' title='".__("Needs to be Verified", 'lang_email')."'></i>";

									$row_actions .= ($row_actions != '' ? " | " : "")."<a href='".wp_nonce_url(admin_url("admin.php?page=mf_email/accounts/index.php&btnEmailVerify&intEmailID=".$intEmailID), 'email_verify_'.$intEmailID, '_wpnonce_email_verify')."'>".__("Verify", 'lang_email')."</a>";
								break;

								case 1:
									if($dteEmailChecked > DEFAULT_DATE)
									{
										if($dteEmailChecked < date("Y-m-d H:i:s", strtotime("-1 day")))
										{
											$row_info .= "<i class='fa fa-ban fa-lg red' title='".sprintf(__("Last Checked %s", 'lang_email'), format_date($dteEmailChecked))."'></i>";
										}

										else
										{
											$dteEmailReceived = $wpdb->get_var($wpdb->prepare("SELECT messageReceived FROM ".$wpdb->base_prefix."email_folder INNER JOIN ".$wpdb->base_prefix."email_message USING (folderID) WHERE emailID = '%d' ORDER BY messageReceived DESC LIMIT 0, 1", $intEmailID));

											if($dteEmailReceived > DEFAULT_DATE)
											{
												if($dteEmailReceived < date("Y-m-d H:i:s", strtotime("-3 day")))
												{
													$row_info .= "<i class='fa fa-exclamation-triangle fa-lg yellow' title='".sprintf(__("Last E-mail %s", 'lang_email'), format_date($dteEmailReceived))."'></i>";
												}

												else
												{
													$row_info .= "<i class='fa fa-check fa-lg green' title='".sprintf(__("Checked %s, Received %s", 'lang_email'), format_date($dteEmailChecked), format_date($dteEmailReceived))."'></i>";
												}
											}

											else
											{
												$row_info .= "<i class='fa fa-question-circle fa-lg' title='".__("No e-mails received so far", 'lang_email')."'></i>";
											}
										}
									}

									else
									{
										$row_info .= "<i class='fa fa-spinner fa-spin fa-lg'></i>";

										$row_actions .= ($row_actions != '' ? " | " : "").__("Incoming has not been checked yet", 'lang_email');
									}
								break;

								case -1:
									$row_info .= "<i class='fa fa-times fa-lg red' title='".__("Verification Failed", 'lang_email')."'></i>";
								break;
							}

							$row_info .= "&nbsp;".$strEmailServer.":".$item['emailPort'];

							if($item['emailUsername'] != '')
							{
								$row_actions .= ($row_actions != '' ? " | " : "").$item['emailUsername'];
							}
						}

						$out .= "<span class='nowrap'>"
							.$row_info
						."</span>
						<div class='row-actions'>"
							.$row_actions
						."</div>";
					}
				break;

				case 'emailSmtpServer':
					$strEmailSmtpServer = $item['emailSmtpServer'];
					$strEmailOutgoingType = $item['emailOutgoingType'];

					if($strEmailSmtpServer != '' || $strEmailOutgoingType != 'smtp')
					{
						$intEmailSmtpVerified = $item['emailSmtpVerified'];
						$dteEmailSmtpChecked = $item['emailSmtpChecked'];

						$row_info = $row_actions = "";

						if($item['emailSmtpPassword'] == '')
						{
							$row_info .= "<i class='fa fa-times red display_warning'></i> ".__("No Password", 'lang_email');
						}

						else
						{
							switch($intEmailSmtpVerified)
							{
								default:
								case 0:
								case 1:
									if($dteEmailSmtpChecked > DEFAULT_DATE)
									{
										if($dteEmailSmtpChecked < date("Y-m-d H:i:s", strtotime("-7 day")))
										{
											$row_info .= "<i class='fa fa-exclamation-triangle fa-lg yellow' title='".sprintf(__("Last Checked %s", 'lang_email'), format_date($dteEmailSmtpChecked))."'></i>";
										}

										else
										{
											$row_info .= "<i class='fa fa-check fa-lg green' title='".sprintf(__("Checked %s", 'lang_email'), format_date($dteEmailSmtpChecked))."'></i>";
										}
									}

									else
									{
										$row_info .= "<i class='fa fa-question-circle fa-lg' title='".__("Outgoing has not been checked yet", 'lang_email')."'></i>";
									}
								break;

								case -1:
									$row_info .= "<i class='fa fa-times fa-lg red' title='".__("Connection Failed", 'lang_email')."'></i>";
								break;
							}

							switch($strEmailOutgoingType)
							{
								case 'smtp':
									$row_info .= "&nbsp;".$strEmailSmtpServer.":".$item['emailSmtpPort'];
								break;

								default:
									$row_info_temp = apply_filters('get_email_outgoing_alternative', $strEmailOutgoingType);

									if($row_info_temp != '')
									{
										$row_info .= "&nbsp;".$row_info_temp;
									}
								break;
							}

							if($item['emailSmtpUsername'] != '')
							{
								$row_actions .= ($row_actions != '' ? " | " : "").$item['emailSmtpUsername'];
							}
						}

						$out .= "<span class='nowrap'>"
							.$row_info
						."</span>
						<div class='row-actions'>"
							.$row_actions
						."</div>";
					}
				break;

				case 'userID':
					$out .= get_user_info(array('id' => $item['userID'], 'type' => 'short_name'));
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
}