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
	<h2>".__("Accounts", $obj_email->lang_key)."</h2>"
	.get_notification()
	."<div id='poststuff'>
		<form action='#' method='post' class='mf_form mf_settings'>
			<div id='post-body' class='columns-2'>
				<div id='post-body-content'>
					<div class='postbox'>
						<h3 class='hndle'><span>".__("Information", $obj_email->lang_key)."</span></h3>
						<div class='inside'>
							<div class='flex_flow'>"
								.show_textfield(array('name' => 'strEmailAddress', 'text' => __("Address", $obj_email->lang_key), 'value' => $obj_email->address, 'placeholder' => $placeholder_address))
								.show_textfield(array('name' => 'strEmailName', 'text' => __("Name", $obj_email->lang_key), 'value' => $obj_email->name, 'placeholder' => $placeholder_name, 'xtra_class' => "display_email_name"))
							."</div>"
							.show_textarea(array('name' => 'strEmailSignature', 'text' => __("Signature", $obj_email->lang_key), 'value' => $obj_email->signature, 'class' => "display_email_signature"))
						."</div>
					</div>
					<div class='postbox'>
						<h3 class='hndle'><span>".__("Incoming", $obj_email->lang_key)." (IMAP)</span></h3>
						<div class='inside'>
							<div class='flex_flow'>"
								.show_textfield(array('name' => 'strEmailServer', 'text' => __("Server", $obj_email->lang_key), 'value' => $obj_email->server, 'placeholder' => "mail.".$placeholder_server));

								/*if(!($obj_email->id > 0) || $obj_email->server != '')
								{*/
									echo show_textfield(array('type' => 'number', 'name' => 'intEmailPort', 'text' => __("Port", $obj_email->lang_key), 'value' => $obj_email->port, 'placeholder' => 143, 'xtra_class' => "display_email_settings"));
								//}

							echo "</div>";

							/*if(!($obj_email->id > 0) || $obj_email->server != '')
							{*/
								echo "<div class='flex_flow display_email_credentials'>"
									.show_textfield(array('name' => 'strEmailUsername', 'text' => __("Username", $obj_email->lang_key), 'value' => $obj_email->username, 'xtra' => " autocomplete='off'"))
									.show_password_field(array('name' => 'strEmailPassword', 'text' => __("Password"), 'value' => $obj_email->password, 'xtra' => " autocomplete='new-password'"))
								."</div>";
							//}

						echo "</div>
					</div>
					<div class='postbox'>
						<h3 class='hndle'><span>".__("Outgoing", $obj_email->lang_key)."</span></h3>
						<div class='inside'>
							<div class='flex_flow'>"
								.show_select(array('data' => apply_filters('email_outgoing_alternatives', array('smtp' => "SMTP")), 'name' => 'strEmailOutgoingType', 'text' => __("Type", $obj_email->lang_key), 'value' => $obj_email->outgoing_type))
								.show_textfield(array('type' => 'number', 'name' => 'intEmailLimitPerHour', 'text' => __("Outgoing e-mails per hour", $obj_email->lang_key), 'value' => $obj_email->limit_per_hour))
							."</div>";

							/*switch($obj_email->outgoing_type)
							{
								case 'smtp':*/
									echo "<div class='flex_flow display_outgoing_smtp'>"
										.show_textfield(array('name' => 'strEmailSmtpServer', 'text' => __("Server", $obj_email->lang_key), 'value' => $obj_email->smtp_server, 'placeholder' => "mail.".$placeholder_server));

										/*if(!($obj_email->id > 0) || $obj_email->smtp_server != '')
										{*/
											echo show_textfield(array('type' => 'number', 'name' => 'intEmailSmtpPort', 'text' => __("Port", $obj_email->lang_key), 'value' => $obj_email->smtp_port, 'placeholder' => 587, 'xtra_class' => "display_smtp_settings"))
											.show_select(array('data' => $obj_email->get_ssl_for_select(), 'name' => 'strEmailSmtpSSL', 'text' => "SSL", 'value' => $obj_email->smtp_ssl, 'class' => "display_smtp_settings"));
										//}

									echo "</div>"
									.show_textfield(array('name' => 'strEmailSmtpHostname', 'text' => __("Hostname", $obj_email->lang_key), 'value' => $obj_email->smtp_hostname, 'xtra_class' => "display_smtp_settings"));
								/*break;
							}*/

							/*if(!($obj_email->id > 0) || $obj_email->smtp_server != '' || $obj_email->outgoing_type != 'smtp')
							{*/
								echo "<div class='flex_flow display_smtp_settings'>"
									.show_textfield(array('name' => 'strEmailSmtpUsername', 'text' => __("User", $obj_email->lang_key), 'value' => $obj_email->smtp_username, 'xtra' => " autocomplete='off'"))
									.show_password_field(array('name' => 'strEmailSmtpPassword', 'text' => __("Password"), 'value' => $obj_email->smtp_password, 'xtra' => " autocomplete='new-password'"))
								."</div>";
							//}

						echo "</div>
					</div>
				</div>
				<div id='postbox-container-1'>
					<div class='postbox'>
						<div class='inside'>"
							.show_button(array('name' => 'btnEmailCreate', 'text' => __("Save", $obj_email->lang_key)))
							.input_hidden(array('name' => 'intEmailID', 'value' => $obj_email->id))
							.wp_nonce_field('email_create_'.$obj_email->id, '_wpnonce_email_create', true, false)
						."</div>
					</div>
					<div class='postbox'>
						<h3 class='hndle'><span>".__("Settings", $obj_email->lang_key)."</span></h3>
						<div class='inside'>"
							.show_checkbox(array('name' => 'intEmailPublic', 'text' => __("Public", $obj_email->lang_key), 'value' => 1, 'compare' => $obj_email->public))
							."<h3>".__("or", $obj_email->lang_key)."</h3>"
							.show_select(array('data' => get_roles_for_select(array('use_capability' => false)), 'name' => 'arrEmailRoles[]', 'text' => __("Permission", $obj_email->lang_key), 'value' => $obj_email->roles, 'xtra' => "class='multiselect'"))
							."<h3>".__("or", $obj_email->lang_key)."</h3>"
							.show_select(array('data' => $arr_data_users, 'name' => 'arrEmailUsers[]', 'text' => __("Users", $obj_email->lang_key), 'value' => $obj_email->users, 'xtra' => "class='multiselect'"))
						."</div>
					</div>
				</div>
			</div>
		</form>
	</div>
</div>";