<?php

class mf_email
{
	function __construct($id = 0)
	{
		if($id > 0)
		{
			$this->id = $id;
		}

		else
		{
			$this->id = check_var('intEmailID');
		}

		$this->message_id = 0;
	}

	function fetch_request()
	{
		$this->public = check_var('intEmailPublic');
		$this->roles = check_var('arrEmailRoles');
		$this->users = check_var('arrEmailUsers');
		$this->server = check_var('strEmailServer');
		$this->port = check_var('intEmailPort');
		$this->username = check_var('strEmailUsername');
		$this->password = check_var('strEmailPassword');
		$this->address = check_var('strEmailAddress');
		$this->name = check_var('strEmailName');

		$this->password_encrypted = "";
	}

	function save_data()
	{
		global $wpdb, $error_text, $done_text;

		$out = "";

		if(isset($_POST['btnEmailCreate']) && wp_verify_nonce($_POST['_wpnonce'], 'email_create'))
		{
			if($this->id > 0)
			{
				$this->update_account();

				$type = "updated";
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

		return $out;
	}

	function get_from_db()
	{
		global $wpdb;

		if($this->id > 0)
		{
			$result = $wpdb->get_results($wpdb->prepare("SELECT emailPublic, emailRoles, emailServer, emailPort, emailUsername, emailAddress, emailName, emailDeleted FROM ".$wpdb->base_prefix."email WHERE emailID = '%d'", $this->id));

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

		/*if($this->password_encrypted != '')
		{
			$encryption = new mf_encryption("email");

			$this->password = $encryption->decrypt($this->password_encrypted, md5($this->address));
		}*/
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

		if($wpdb->num_rows > 0)
		{
			return true;
		}

		else
		{
			return false;
		}
	}

	function encrypt_password()
	{
		if($this->password != '' && $this->address != '')
		{
			$encryption = new mf_encryption("email");

			$this->password_encrypted = $encryption->encrypt($this->password, md5($this->address));
		}
	}

	function check_if_account_exists()
	{
		global $wpdb;

		$intEmailID = $wpdb->get_var($wpdb->prepare("SELECT emailID FROM ".$wpdb->base_prefix."email WHERE emailAddress = %s", $this->address));

		return $intEmailID;
	}

	function create_account()
	{
		global $wpdb;

		$this->encrypt_password();

		$wpdb->query($wpdb->prepare("INSERT INTO ".$wpdb->base_prefix."email SET emailPublic = '%d', emailRoles = %s, emailServer = %s, emailPort = '%d', emailUsername = %s, emailPassword = %s, emailAddress = %s, emailName = %s, emailCreated = NOW(), userID = '%d'", $this->public, @implode(",", $this->roles), $this->server, $this->port, $this->username, $this->password_encrypted, $this->address, $this->name, get_current_user_id()));

		$this->id = $wpdb->insert_id;
	}

	function update_account()
	{
		global $wpdb;

		$this->encrypt_password();

		$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email SET emailPublic = '%d', emailRoles = %s, emailVerified = '0', emailServer = %s, emailPort = '%d', emailUsername = %s, emailAddress = %s, emailName = %s WHERE emailID = '%d' AND userID = '%d'", $this->public, @implode(",", $this->roles), $this->server, $this->port, $this->username, $this->password, $this->address, $this->name, $this->id, get_current_user_id()));

		if($wpdb->rows_affected > 0 && $this->password_encrypted != '')
		{
			$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email SET emailPassword = %s WHERE emailID = '%d' AND userID = '%d'", $this->password_encrypted, $this->id, get_current_user_id()));
		}
	}
}