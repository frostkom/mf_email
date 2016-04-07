<?php

$encryption = new mf_encryption("email");

$intEmailID = check_var('intEmailID');
$intEmailPublic = check_var('intEmailPublic');
$arrEmailRoles = check_var('arrEmailRoles');
$arrEmailUsers = check_var('arrEmailUsers');
$strEmailServer = check_var('strEmailServer');
$intEmailPort = check_var('intEmailPort');
$strEmailUsername = check_var('strEmailUsername');
$strEmailPassword = check_var('strEmailPassword');
$strEmailAddress = check_var('strEmailAddress');
$strEmailName = check_var('strEmailName');

if($strEmailPassword != '')
{
	$strEmailPassword = $encryption->encrypt($strEmailPassword, md5($strEmailAddress));
}

if(isset($_POST['btnEmailCreate']) && wp_verify_nonce($_POST['_wpnonce'], 'email_create'))
{
	if($intEmailID > 0)
	{
		$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email SET emailPublic = '%d', emailRoles = %s, emailVerified = '0', emailServer = %s, emailPort = '%d', emailUsername = %s, emailAddress = %s, emailName = %s WHERE emailID = '%d' AND userID = '%d'", $intEmailPublic, @implode(",", $arrEmailRoles), $strEmailServer, $intEmailPort, $strEmailUsername, $strEmailAddress, $strEmailName, $intEmailID, get_current_user_id()));

		if($wpdb->rows_affected > 0 && $strEmailPassword != '')
		{
			$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email SET emailPassword = %s WHERE emailID = '%d' AND userID = '%d'", $strEmailPassword, $intEmailID, get_current_user_id()));
		}

		$type = "updated";
	}

	else
	{
		$wpdb->query($wpdb->prepare("INSERT INTO ".$wpdb->base_prefix."email SET emailPublic = '%d', emailRoles = %s, emailServer = %s, emailPort = '%d', emailUsername = %s, emailPassword = %s, emailAddress = %s, emailName = %s, emailCreated = NOW(), userID = '%d'", $intEmailPublic, @implode(",", $arrEmailRoles), $strEmailServer, $intEmailPort, $strEmailUsername, $strEmailPassword, $strEmailAddress, $strEmailName, get_current_user_id()));

		$intEmailID = $wpdb->insert_id;

		if($intEmailID > 0)
		{
			$type = "created";
		}

		else
		{
			$error_text = __("The e-mail account could not be created", 'lang_email');
		}
	}

	if($intEmailID > 0)
	{
		$wpdb->query($wpdb->prepare("DELETE FROM ".$wpdb->base_prefix."email_users WHERE emailID = '%d'", $intEmailID));

		if(is_array($arrEmailUsers))
		{
			foreach($arrEmailUsers as $intUserID)
			{
				$wpdb->query($wpdb->prepare("INSERT INTO ".$wpdb->base_prefix."email_users SET emailID = '%d', userID = '%d'", $intEmailID, $intUserID));
			}
		}
	}

	if(!isset($error_text) || $error_text == '')
	{
		mf_redirect("?page=mf_email/accounts/index.php&".$type);
	}
}

echo "<div class='wrap'>
	<h2>".__("Accounts", 'lang_email')."</h2>"
	.get_notification()
	."<div id='poststuff' class='postbox'>
		<h3 class='hndle'>".__("Add", 'lang_email')."</h3>
		<div class='inside'>
			<form action='#' method='post' class='mf_form mf_settings'>";

				if($intEmailID > 0)
				{
					$result = $wpdb->get_results($wpdb->prepare("SELECT emailPublic, emailRoles, emailServer, emailPort, emailUsername, emailAddress, emailName, emailDeleted FROM ".$wpdb->base_prefix."email WHERE emailID = '%d'", $intEmailID));

					foreach($result as $r)
					{
						$intEmailPublic = $r->emailPublic;
						$arrEmailRoles = explode(",", $r->emailRoles);
						$strEmailServer = $r->emailServer;
						$intEmailPort = $r->emailPort;
						$strEmailUsername = $r->emailUsername;
						//$strEmailPassword = $r->emailPassword;
						$strEmailAddress = $r->emailAddress;
						$strEmailName = $r->emailName;
						$intEmailDeleted = $r->emailDeleted;

						$arrEmailUsers = array();

						$resultUsers = $wpdb->get_results($wpdb->prepare("SELECT userID FROM ".$wpdb->base_prefix."email_users WHERE emailID = '%d'", $intEmailID));

						foreach($resultUsers as $r)
						{
							$arrEmailUsers[] = $r->userID;
						}

						if($intEmailDeleted == 1)
						{
							$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email SET emailDeleted = '0', emailDeletedID = '', emailDeletedDate = '' WHERE emailID = '%d' AND userID = '%d'", $intEmailID, get_current_user_id()));
						}
					}
				}

				if($strEmailPassword != '')
				{
					$strEmailPassword = $encryption->decrypt($strEmailPassword, md5($strEmailAddress));
				}

				$user_data = get_userdata(get_current_user_id());

				$placeholder_name = $user_data->display_name;
				$placeholder_address = $user_data->user_email;
				list($rest, $placeholder_server) = explode("@", $placeholder_address);

				echo "<div class='flex_flow'>"
					.show_checkbox(array('name' => "intEmailPublic", 'text' => __("Public", 'lang_email'), 'value' => 1, 'compare' => $intEmailPublic))
					."<h3>".__("or", 'lang_email')."</h3>";

					$roles = get_all_roles();

					$arr_data = array();

					foreach($roles as $key => $value)
					{
						$arr_data[$key] = __($value);
					}

					echo show_select(array('data' => $arr_data, 'name' => 'arrEmailRoles[]', 'text' => __("Permission", 'lang_email'), 'compare' => $arrEmailRoles))
					."<h3>".__("or", 'lang_email')."</h3>";

					$users = get_users(array(
						'orderby'      => 'display_name',
						'order'        => 'ASC',
						'fields'       => array('ID', 'display_name'), //'all'
					));

					$arr_data = array();

					foreach($users as $user)
					{
						$arr_data[$user->ID] = $user->display_name;
					}

					echo show_select(array('data' => $arr_data, 'name' => 'arrEmailUsers[]', 'text' => __("Users", 'lang_email'), 'compare' => $arrEmailUsers))
				."</div>
				<div class='flex_flow'>"
					.show_textfield(array('name' => "strEmailServer", 'text' => __("Server", 'lang_email'), 'value' => $strEmailServer, 'placeholder' => "mail.".$placeholder_server))
					.show_textfield(array('name' => "intEmailPort", 'text' => __("Port", 'lang_email'), 'value' => $intEmailPort, 'placeholder' => 143))
				."</div>
				<div class='flex_flow'>"
					.show_textfield(array('name' => "strEmailUsername", 'text' => __("Username", 'lang_email'), 'value' => $strEmailUsername))
					.show_password_field(array('name' => "strEmailPassword", 'text' => __("Password", 'lang_email'), 'value' => $strEmailPassword))
				."</div>"
				.show_textfield(array('name' => "strEmailAddress", 'text' => __("E-mail Address", 'lang_email'), 'value' => $strEmailAddress, 'placeholder' => $placeholder_address))
				.show_textfield(array('name' => "strEmailName", 'text' => __("Name", 'lang_email'), 'value' => $strEmailName, 'placeholder' => $placeholder_name))
				.show_submit(array('name' => 'btnEmailCreate', 'text' => __("Save", 'lang_email')))
				.input_hidden(array('name' => "intEmailID", 'value' => $intEmailID))
				.wp_nonce_field('email_create', '_wpnonce', true, false)
			."</form>
		</div>
	</div>
</div>";