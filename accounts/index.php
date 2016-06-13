<?php

$intEmailID = check_var('intEmailID');
$paged = check_var('paged', 'int', true, '0');
$strSearch = check_var('s', 'char');

$intLimitAmount = 20;
$intLimitStart = $paged * $intLimitAmount;

if(isset($_REQUEST['btnEmailDelete']) && $intEmailID > 0 && wp_verify_nonce($_REQUEST['_wpnonce'], 'email_delete'))
{
	$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email SET emailDeleted = '1', emailDeletedID = '%d', emailDeletedDate = NOW() WHERE emailID = '%d' AND userID = '%d'", get_current_user_id(), $intEmailID, get_current_user_id()));

	if($wpdb->rows_affected > 0)
	{
		$done_text = __("The e-mail account was deleted", 'lang_email');
	}

	else
	{
		$error_text = __("The e-mail account could not be deleted", 'lang_email');
	}
}

else if(isset($_REQUEST['btnEmailConfirm']) && $intEmailID > 0 && wp_verify_nonce($_REQUEST['_wpnonce'], 'email_confirm'))
{
	$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email SET emailVerified = '1' WHERE emailID = '%d'", $intEmailID));

	$done_text = __("The e-mail account was confirmed", 'lang_email');
}

else if(isset($_REQUEST['btnEmailVerify']) && $intEmailID > 0 && wp_verify_nonce($_REQUEST['_wpnonce'], 'email_verify'))
{
	$result = $wpdb->get_results($wpdb->prepare("SELECT emailVerified, emailServer, emailPort, emailUsername, emailPassword, emailAddress FROM ".$wpdb->base_prefix."email WHERE emailID = '%d'", $intEmailID));

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

				$connection = email_connect(array('server' => $strEmailServer, 'port' => $intEmailPort, 'username' => $strEmailUsername, 'password' => $strEmailPassword, 'close_after' => true));

				if($connection == true)
				{
					$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email SET emailVerified = '1' WHERE emailID = '%d'", $intEmailID));

					$done_text = __("The e-mail account passed verification", 'lang_email');
				}

				else
				{
					$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email SET emailVerified = '-1' WHERE emailID = '%d'", $intEmailID));

					$error_text = __("The e-mail account didn't pass the verification", 'lang_email')." (".var_export($connection, true).")";
				}
			}

			else
			{
				$user_data = get_userdata(get_current_user_id());

				$site_name = get_bloginfo('name');
				$site_url = get_site_url();
				$confirm_url = wp_nonce_url($site_url.$_SERVER['PHP_SELF']."?page=mf_email/accounts/index.php&btnEmailConfirm&intEmailID=".$intEmailID, 'email_confirm');

				$mail_to = $strEmailAddress;
				$mail_headers = "From: ".$user_data->display_name." <".$user_data->user_email.">\r\n";
				$mail_subject = sprintf(__("Please confirm your e-mail %s for use on %s", 'lang_email'), $strEmailAddress, $site_name);
				$mail_content = sprintf(__("We've gotten a request to confirm the address %s from a user at %s (<a href='%s'>%s</a>). If this is a valid request please click <a href='%s'>here</a> to confirm the use of your e-mail address to send messages", 'lang_email'), $strEmailAddress, $site_name, $site_url, $confirm_url);

				wp_mail($mail_to, $mail_subject, $mail_content, $mail_headers);

				$done_text = sprintf(__("An e-mail with a confirmation link has been sent to %s", 'lang_email'), $strEmailAddress); //." (".$mail_to.", ".$mail_headers.", ".$mail_subject.", ".$mail_content.")"
			}
		}
	}
}

echo "<div class='wrap'>
	<h2>"
		.__("Accounts", 'lang_email')
		."<a href='?page=mf_email/create/index.php' class='add-new-h2'>".__("Add New", 'lang_email')."</a>"
	."</h2>"
	.get_notification();

	$query_xtra = "";

	if($strSearch != '')
	{
		$query_xtra .= ($query_xtra != '' ? " AND " : " WHERE ")."emailUsername LIKE '%".esc_sql($strSearch)."%'";
	}

	$resultPagination = $wpdb->get_results("SELECT emailID FROM ".$wpdb->base_prefix."email".$query_xtra." GROUP BY emailID");

	echo get_list_navigation($resultPagination);

	$result = $wpdb->get_results("SELECT emailID, emailPublic, emailRoles, emailVerified, emailServer, emailPort, emailUsername, emailAddress, emailName, ".$wpdb->base_prefix."email.userID, emailDeleted FROM ".$wpdb->base_prefix."email_users RIGHT JOIN ".$wpdb->base_prefix."email USING (emailID) WHERE (emailPublic = '1' OR emailRoles LIKE '%".get_current_user_role()."%' OR ".$wpdb->base_prefix."email.userID = '".get_current_user_id()."' OR ".$wpdb->base_prefix."email_users.userID = '".get_current_user_id()."')".$query_xtra." GROUP BY emailID ORDER BY emailDeleted ASC, ".$wpdb->base_prefix."email.userID ASC, emailUsername ASC LIMIT ".esc_sql($intLimitStart).", ".esc_sql($intLimitAmount));

	echo "<table class='widefat striped'>";

		$arr_header[] = __("Public", 'lang_email');
		$arr_header[] = __("Roles", 'lang_email');
		$arr_header[] = __("Users", 'lang_email');
		$arr_header[] = __("Verified", 'lang_email');
		$arr_header[] = __("Address", 'lang_email');
		$arr_header[] = __("Name", 'lang_email');
		$arr_header[] = __("Server", 'lang_email');
		$arr_header[] = __("Username", 'lang_email');

		echo show_table_header($arr_header)
		."<tbody>";

			if(count($result) == 0)
			{
				echo "<tr><td colspan='".count($arr_header)."'>".__("There is nothing to show", 'lang_email')."</td></tr>";
			}

			else
			{
				foreach($result as $r)
				{
					$intEmailID = $r->emailID;
					$intEmailPublic = $r->emailPublic;
					$strEmailRoles = $r->emailRoles;
					$intEmailVerified = $r->emailVerified;
					$strEmailServer = $r->emailServer;
					$intEmailPort = $r->emailPort;
					$strEmailUsername = $r->emailUsername;
					$strEmailAddress = $r->emailAddress;
					$strEmailName = $r->emailName;
					$intUserID = $r->userID;
					$intEmailDeleted = $r->emailDeleted;

					$strEmailUsers = "";

					$resultUsers = $wpdb->get_results($wpdb->prepare("SELECT userID FROM ".$wpdb->base_prefix."email_users WHERE emailID = '%d'", $intEmailID));

					foreach($resultUsers as $r)
					{
						$user_data = get_userdata($r->userID);

						$strEmailUsers .= ($strEmailUsers != '' ? ", " : "").$user_data->display_name;
					}

					$class = "";

					if($intEmailDeleted == 1)
					{
						$class .= ($class != '' ? " " : "")."inactive";
					}

					echo "<tr".($class != '' ? " class='".$class."'" : "").">
						<td>";

							switch($intEmailPublic)
							{
								default:
								case 0:
									echo "<i class='fa fa-lg fa-close red'></i>";
								break;

								case 1:
									echo "<i class='fa fa-lg fa-check green'></i>";
								break;
							}

						echo "</td>
						<td>";

							if($strEmailRoles != '')
							{
								echo "<i class='fa fa-lg fa-users' title='".$strEmailRoles."'></i>";
							}

						echo "</td>
						<td>";

							if($strEmailUsers != '')
							{
								echo "<i class='fa fa-lg fa-users' title='".$strEmailUsers."'></i>";
							}

						echo "</td>
						<td>";

							switch($intEmailVerified)
							{
								default:
								case 0:
									echo "<i class='fa fa-lg fa-question'></i>
									<div class='row-actions'>
										<a href='".wp_nonce_url("?page=mf_email/accounts/index.php&btnEmailVerify&intEmailID=".$intEmailID, 'email_verify')."'>".__("Verify Account", 'lang_email')."</a>
									</div>";
								break;

								case 1:
									echo "<i class='fa fa-lg fa-check green'></i>";
								break;

								case -1:
									echo "<span class='fa-stack'>
										<i class='fa fa-search fa-stack-1x'></i>
										<i class='fa fa-ban fa-stack-2x text-danger red'></i>
									</span>";
								break;
							}

						echo "</td>
						<td>
							<a href='?page=mf_email/create/index.php&intEmailID=".$intEmailID."'>"
								.$strEmailAddress
							."</a>
							<div class='row-actions'>";

								if($intEmailDeleted == 0)
								{
									echo "<a href='?page=mf_email/create/index.php&intEmailID=".$intEmailID."'>".__("Edit", 'lang_email')."</a>";

									if($intUserID == get_current_user_id())
									{
										echo " | <a href='".wp_nonce_url("?page=mf_email/accounts/index.php&btnEmailDelete&intEmailID=".$intEmailID, 'email_delete')."' rel='confirm'>".__("Delete", 'lang_email')."</a>";
									}
								}

								else
								{
									echo "<a href='?page=mf_email/create/index.php&intEmailID=".$intEmailID."'>".__("Recover", 'lang_email')."</a>";
								}

							echo "</div>
						</td>
						<td>".$strEmailName."</td>
						<td>";
						
							if($strEmailServer != '')
							{
								echo $strEmailServer.":".$intEmailPort;
							}
							
						echo "</td>
						<td>".$strEmailUsername."</td>
					</tr>";
				}
			}

		echo "</tbody>
	</table>";

echo "</div>";