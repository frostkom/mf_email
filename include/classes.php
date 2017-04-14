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

				$this->smtp_server = check_var('strEmailSmtpServer');
				$this->smtp_port = check_var('intEmailSmtpPort');
				$this->smtp_ssl = check_var('intEmailSmtpSSL');
				$this->smtp_username = check_var('strEmailSmtpUsername');
				$this->smtp_password = check_var('strEmailSmtpPassword');

				$this->password_encrypted = "";
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
				if(isset($_POST['btnEmailCreate']) && wp_verify_nonce($_POST['_wpnonce'], 'email_create_'.$this->id))
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
							//$error_text .= " (".$wpdb->last_query.")";
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
						mf_redirect("?page=mf_email/accounts/index.php&".$type);
					}
				}
			break;

			case 'account_list':
				if(isset($_REQUEST['btnEmailDelete']) && $this->id > 0 && wp_verify_nonce($_REQUEST['_wpnonce'], 'email_delete_'.$this->id))
				{
					$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email SET emailDeleted = '1', emailDeletedID = '%d', emailDeletedDate = NOW() WHERE blogID = '%d' AND emailID = '%d' AND userID = '%d'", $wpdb->blogid, get_current_user_id(), $this->id, get_current_user_id()));

					if($wpdb->rows_affected > 0)
					{
						$done_text = __("The e-mail account was deleted", 'lang_email');
					}

					else
					{
						$error_text = __("The e-mail account could not be deleted", 'lang_email');
					}
				}

				else if(isset($_REQUEST['btnEmailConfirm']) && $this->id > 0 && wp_verify_nonce($_REQUEST['_wpnonce'], 'email_confirm_'.$this->id))
				{
					$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email SET blogID = '%d', emailVerified = '1' WHERE emailID = '%d'", $wpdb->blogid, $this->id));

					$done_text = __("The e-mail account was confirmed", 'lang_email');
				}

				else if(isset($_REQUEST['btnEmailVerify']) && $this->id > 0 && wp_verify_nonce($_REQUEST['_wpnonce'], 'email_verify_'.$this->id))
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
								$encryption = new mf_encryption("email");

								if($strEmailPassword != '')
								{
									$strEmailPassword = $encryption->decrypt($strEmailPassword, md5($strEmailAddress));
								}

								list($is_connected, $connection) = email_connect(array('server' => $strEmailServer, 'port' => $intEmailPort, 'username' => $strEmailUsername, 'password' => $strEmailPassword, 'close_after' => true));

								if($is_connected == true)
								{
									$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email SET emailVerified = '1' WHERE emailID = '%d'", $this->id));

									$done_text = __("The e-mail account passed verification", 'lang_email');
								}

								else
								{
									$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email SET emailVerified = '-1' WHERE emailID = '%d'", $this->id));

									$error_text = __("The e-mail account didn't pass the verification", 'lang_email')." (".var_export($connection, true).")";
								}
							}

							else
							{
								$user_data = get_userdata(get_current_user_id());

								$site_name = get_bloginfo('name');
								$site_url = get_site_url();
								$confirm_url = wp_nonce_url($site_url.$_SERVER['PHP_SELF']."?page=mf_email/accounts/index.php&btnEmailConfirm&intEmailID=".$this->id, 'email_confirm_'.$this->id);

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
					$result = $wpdb->get_results($wpdb->prepare("SELECT emailPublic, emailRoles, emailServer, emailPort, emailUsername, emailAddress, emailName, emailSmtpSSL, emailSmtpServer, emailSmtpPort, emailSmtpUsername, emailSmtpPassword, emailDeleted FROM ".$wpdb->base_prefix."email WHERE emailID = '%d'", $this->id));

					foreach($result as $r)
					{
						$this->public = $r->emailPublic;
						$this->roles = explode(",", $r->emailRoles);
						$this->server = $r->emailServer;
						$this->port = $r->emailPort;
						$this->username = $r->emailUsername;
						//$this->password = $r->emailPassword;
						$this->address = $r->emailAddress;
						$this->name = $r->emailName;
						$this->smtp_ssl = $r->emailSmtpSSL;
						$this->smtp_server = $r->emailSmtpServer;
						$this->smtp_port = $r->emailSmtpPort;
						$this->smtp_username = $r->emailSmtpUsername;
						$this->smtp_password = $r->emailSmtpPassword;
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

	function check_if_spam($data)
	{
		global $wpdb;

		$result = $wpdb->get_results($wpdb->prepare("SELECT spamID FROM ".$wpdb->base_prefix."email_spam WHERE emailID = '%d' AND messageFrom = %s", $this->id, $data['from']));

		return $wpdb->num_rows > 0 ? true : false;
	}

	function encrypt_password()
	{
		if($this->password != '' && $this->address != '')
		{
			$encryption = new mf_encryption("email");

			$this->password_encrypted = $encryption->encrypt($this->password, md5($this->address));
		}

		if($this->smtp_password != '' && $this->address != '')
		{
			$encryption = new mf_encryption("email");

			$this->smtp_password_encrypted = $encryption->encrypt($this->smtp_password, md5($this->address));
		}
	}

	function check_if_account_exists()
	{
		global $wpdb;

		$intEmailID = $wpdb->get_var($wpdb->prepare("SELECT emailID FROM ".$wpdb->base_prefix."email WHERE emailAddress = %s", $this->address));

		return $intEmailID;
	}

	function update_passwords()
	{
		global $wpdb;

		if($this->password_encrypted != '')
		{
			$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email SET emailPassword = %s WHERE emailID = '%d' AND userID = '%d'", $this->password_encrypted, $this->id, get_current_user_id()));
		}

		if($this->smtp_password_encrypted != '')
		{
			$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email SET emailSmtpPassword = %s WHERE emailID = '%d' AND userID = '%d'", $this->smtp_password_encrypted, $this->id, get_current_user_id()));
		}
	}

	function create_account()
	{
		global $wpdb;

		$this->encrypt_password();

		$wpdb->query($wpdb->prepare("INSERT INTO ".$wpdb->base_prefix."email SET blogID = '%d', emailPublic = '%d', emailRoles = %s, emailServer = %s, emailPort = '%d', emailUsername = %s, emailAddress = %s, emailName = %s, emailSmtpSSL = '%d', emailSmtpServer = %s, emailSmtpPort = '%d', emailSmtpUsername = %s, emailCreated = NOW(), userID = '%d'", $wpdb->blogid, $this->public, @implode(",", $this->roles), $this->server, $this->port, $this->username, $this->address, $this->name, $this->smtp_ssl, $this->smtp_server, $this->smtp_port, $this->smtp_username, get_current_user_id()));

		$this->id = $wpdb->insert_id;

		if($this->id > 0)
		{
			$this->update_passwords();
		}
	}

	function update_account()
	{
		global $wpdb;

		$this->encrypt_password();

		$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email SET blogID = '%d', emailPublic = '%d', emailRoles = %s, emailVerified = '0', emailServer = %s, emailPort = '%d', emailUsername = %s, emailAddress = %s, emailName = %s, emailSmtpSSL = '%d', emailSmtpServer = %s, emailSmtpPort = '%d', emailSmtpUsername = %s, emailDeleted = '0' WHERE emailID = '%d'", $wpdb->blogid, $this->public, @implode(",", $this->roles), $this->server, $this->port, $this->username, $this->address, $this->name, $this->smtp_ssl, $this->smtp_server, $this->smtp_port, $this->smtp_username, $this->id));

		$rows_affected = $wpdb->rows_affected;

		if($rows_affected > 0)
		{
			$this->update_passwords();

			return true;
		}

		else
		{
			return false;
		}
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
			'rights' => __("Rights", 'lang_email'),
			//'emailPublic' => __("Public", 'lang_email'),
			//'emailRoles' => __("Roles", 'lang_email'),
			//'emailUsers' => __("Users", 'lang_email'),
			'emailVerified' => __("Verified", 'lang_email'),
			'emailAddress' => __("Address", 'lang_email'),
			'emailName' => __("Name", 'lang_email'),
			'emailServer' => __("Server", 'lang_email'),
			'emailUsername' => __("Username", 'lang_email'),
			'emailChecked' => __("Status", 'lang_email'),
		);

		$this->set_columns($arr_columns);

		$this->set_sortable_columns(array(
			'emailAddress',
			'emailName',
			'emailServer',
			'emailUsername',
		));
	}

	function column_default($item, $column_name)
	{
		global $wpdb, $obj_group;

		$out = "";

		$intEmailID = $item['emailID'];

		switch($column_name)
		{
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

			case 'emailVerified':
				switch($item[$column_name])
				{
					default:
					case 0:
						$out .= "<i class='fa fa-lg fa-question'></i>
						<div class='row-actions'>
							<a href='".wp_nonce_url("?page=mf_email/accounts/index.php&btnEmailVerify&intEmailID=".$intEmailID, 'email_verify_'.$intEmailID)."'>".__("Verify", 'lang_email')."</a>
						</div>";
					break;

					case 1:
						$out .= "<i class='fa fa-lg fa-check green'></i>";
					break;

					case -1:
						$out .= "<span class='fa-stack'>
							<i class='fa fa-search fa-stack-1x'></i>
							<i class='fa fa-ban fa-stack-2x text-danger red'></i>
						</span>";
					break;
				}
			break;

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
							$out .= " | <a href='".wp_nonce_url("?page=mf_email/accounts/index.php&btnEmailDelete&intEmailID=".$intEmailID, 'email_delete_'.$intEmailID)."' rel='confirm'>".__("Delete", 'lang_email')."</a>";
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
			break;

			case 'emailServer':
				$strEmailServer = $item[$column_name];
				$intEmailPort = $item['emailPort'];

				if($strEmailServer != '')
				{
					$out .= $strEmailServer.":".$intEmailPort;
				}
			break;

			case 'emailUsername':
				$out .= $item[$column_name];
			break;

			case 'emailChecked':
				$dteEmailChecked = $item[$column_name];

				if($dteEmailChecked > DEFAULT_DATE)
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
							$out .= "<i class='fa fa-question-circle-o fa-lg'></i>"
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