<?php

$obj_email = new mf_email(array('type' => 'account_create'));
$obj_email->fetch_request();
echo $obj_email->save_data();
$obj_email->get_from_db();

$user_data = get_userdata(get_current_user_id());

$placeholder_name = $user_data->display_name;
$placeholder_address = $user_data->user_email;
list($rest, $placeholder_server) = explode("@", $placeholder_address);

$users = get_users(array(
	'orderby' => 'display_name',
	'order' => 'ASC',
	'fields' => array('ID', 'display_name'),
));

$arr_data_users = array();

foreach($users as $user)
{
	$arr_data_users[$user->ID] = $user->display_name;
}

echo "<div class='wrap'>
	<h2>".__("Accounts", 'lang_email')."</h2>"
	.get_notification()
	."<div id='poststuff'>
		<form action='#' method='post' class='mf_form mf_settings'>
			<div id='post-body' class='columns-2'>
				<div id='post-body-content'>
					<div class='postbox'>
						<h3 class='hndle'><span>".__("Information", 'lang_email')."</span></h3>
						<div class='inside'>
							<div class='flex_flow'>"
								.show_textfield(array('name' => 'strEmailAddress', 'text' => __("Address", 'lang_email'), 'value' => $obj_email->address, 'placeholder' => $placeholder_address))
								.show_textfield(array('name' => 'strEmailName', 'text' => __("Name", 'lang_email'), 'value' => $obj_email->name, 'placeholder' => $placeholder_name, 'xtra_class' => "display_email_name"))
							."</div>"
							.show_textarea(array('name' => 'strEmailSignature', 'text' => __("Signature", 'lang_email'), 'value' => $obj_email->signature, 'class' => "display_email_signature"))
						."</div>
					</div>
					<div class='postbox'>
						<h3 class='hndle'><span>".__("Incoming", 'lang_email')." (IMAP)</span></h3>
						<div class='inside'>
							<div class='flex_flow'>"
								.show_textfield(array('name' => 'strEmailServer', 'text' => __("Server", 'lang_email'), 'value' => $obj_email->server, 'placeholder' => "mail.".$placeholder_server))
								.show_textfield(array('type' => 'number', 'name' => 'intEmailPort', 'text' => __("Port", 'lang_email'), 'value' => $obj_email->port, 'placeholder' => 143, 'xtra_class' => "display_email_settings"))
							."</div>
							<div class='flex_flow display_email_credentials'>"
								.show_textfield(array('name' => 'strEmailUsername', 'text' => __("Username", 'lang_email'), 'value' => $obj_email->username, 'xtra' => " autocomplete='off' maxlength='100'"))
								.show_password_field(array('name' => 'strEmailPassword', 'text' => __("Password"), 'value' => $obj_email->password, 'xtra' => " autocomplete='new-password'"))
							."</div>
						</div>
					</div>
					<div class='postbox'>
						<h3 class='hndle'><span>".__("Outgoing", 'lang_email')."</span></h3>
						<div class='inside'>
							<div class='flex_flow'>"
								.show_select(array('data' => apply_filters('email_outgoing_alternatives', array('smtp' => "SMTP")), 'name' => 'strEmailOutgoingType', 'text' => __("Type", 'lang_email'), 'value' => $obj_email->outgoing_type, 'allow_hidden_field' => false))
								.show_textfield(array('type' => 'number', 'name' => 'intEmailLimitPerHour', 'text' => __("Outgoing e-mails per hour", 'lang_email'), 'value' => $obj_email->limit_per_hour))
							."</div>
							<div class='flex_flow display_outgoing_smtp'>"
								.show_textfield(array('name' => 'strEmailSmtpServer', 'text' => __("Server", 'lang_email'), 'value' => $obj_email->smtp_server, 'placeholder' => "mail.".$placeholder_server))
								.show_textfield(array('type' => 'number', 'name' => 'intEmailSmtpPort', 'text' => __("Port", 'lang_email'), 'value' => $obj_email->smtp_port, 'placeholder' => 587, 'xtra_class' => "display_smtp_settings"))
								.show_select(array('data' => $obj_email->get_ssl_for_select(), 'name' => 'strEmailSmtpSSL', 'text' => "SSL", 'value' => $obj_email->smtp_ssl, 'class' => "display_smtp_settings"))
							."</div>"
							.show_textfield(array('name' => 'strEmailSmtpHostname', 'text' => __("Hostname", 'lang_email'), 'value' => $obj_email->smtp_hostname, 'xtra_class' => "display_smtp_settings"))
							."<div class='flex_flow display_smtp_credentials'>"
								.show_textfield(array('name' => 'strEmailSmtpUsername', 'text' => __("User", 'lang_email'), 'value' => $obj_email->smtp_username, 'xtra' => " autocomplete='off' maxlength='100'"))
								.show_password_field(array('name' => 'strEmailSmtpPassword', 'text' => __("Password"), 'value' => $obj_email->smtp_password, 'xtra' => " autocomplete='new-password'"))
							."</div>"
							.show_select(array('data' => $obj_email->get_preferred_content_types_for_select(), 'name' => 'arrEmailPreferredContentTypes[]', 'text' => __("Preferred Content Types", 'lang_email'), 'value' => $obj_email->preferred_content_types))
						."</div>
					</div>
				</div>
				<div id='postbox-container-1'>
					<div class='postbox'>
						<div class='inside'>"
							.show_button(array('name' => 'btnEmailCreate', 'text' => __("Save", 'lang_email')))
							.input_hidden(array('name' => 'intEmailID', 'value' => $obj_email->id))
							.wp_nonce_field('email_create_'.$obj_email->id, '_wpnonce_email_create', true, false);

							if($obj_email->id > 0)
							{
								$result = $wpdb->get_results($wpdb->prepare("SELECT emailCreated, userID FROM ".$wpdb->base_prefix."email WHERE emailID = '%d'", $obj_email->id));

								foreach($result as $r)
								{
									$dteEmailCreated = $r->emailCreated;
									$intUserID = $r->userID;

									if($intUserID > 0)
									{
										echo "<br><em>".sprintf(__("Created %s by %s", 'lang_email'), format_date($dteEmailCreated), get_user_info(array('id' => $intUserID)))."</em>";
									}

									else
									{
										echo "<br><em>".sprintf(__("Created %s", 'lang_email'), format_date($dteEmailCreated))."</em>";
									}
								}
							}

						echo "</div>
					</div>
					<div class='postbox'>
						<h3 class='hndle'><span>".__("Rights", 'lang_email')."</span></h3>
						<div class='inside'>"
							.show_checkbox(array('name' => 'intEmailPublic', 'text' => __("Public", 'lang_email'), 'value' => 1, 'compare' => $obj_email->public))
							."<h3>".__("or", 'lang_email')."</h3>"
							.show_select(array('data' => get_roles_for_select(array('use_capability' => false)), 'name' => 'arrEmailRoles[]', 'text' => __("Permission", 'lang_email'), 'value' => $obj_email->roles, 'xtra' => "class='multiselect'"))
							."<h3>".__("or", 'lang_email')."</h3>"
							.show_select(array('data' => $arr_data_users, 'name' => 'arrEmailUsers[]', 'text' => __("Users", 'lang_email'), 'value' => $obj_email->users, 'xtra' => "class='multiselect'"))
						."</div>
					</div>
				</div>
			</div>
		</form>
	</div>
</div>";