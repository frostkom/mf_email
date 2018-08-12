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

$arr_data_outgoing = array(
	'smtp' => __("SMTP", 'lang_email'),
	'ungapped' => __("Ungapped", 'lang_email'),
);

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
								.show_textfield(array('name' => "strEmailName", 'text' => __("Name", 'lang_email'), 'value' => $obj_email->name, 'placeholder' => $placeholder_name))
								.show_textfield(array('name' => "strEmailAddress", 'text' => __("E-mail Address", 'lang_email'), 'value' => $obj_email->address, 'placeholder' => $placeholder_address))
							."</div>
						</div>
					</div>
					<div class='postbox'>
						<h3 class='hndle'><span>".__("Incoming", 'lang_email')." (IMAP)</span></h3>
						<div class='inside'>
							<div class='flex_flow'>"
								.show_textfield(array('name' => "strEmailServer", 'text' => __("Server", 'lang_email'), 'value' => $obj_email->server, 'placeholder' => "mail.".$placeholder_server))
								.show_textfield(array('type' => 'number', 'name' => "intEmailPort", 'text' => __("Port", 'lang_email'), 'value' => $obj_email->port, 'placeholder' => 143))
							."</div>
							<div class='flex_flow'>"
								.show_textfield(array('name' => "strEmailUsername", 'text' => __("Username", 'lang_email'), 'value' => $obj_email->username, 'xtra' => " autocomplete='off'"))
								.show_password_field(array('name' => "strEmailPassword", 'text' => __("Password", 'lang_email'), 'value' => $obj_email->password, 'xtra' => " autocomplete='off'"))
							."</div>
						</div>
					</div>
					<div class='postbox'>
						<h3 class='hndle'><span>".__("Outgoing", 'lang_email')."</span></h3>
						<div class='inside'>
							<div class='flex_flow'>"
								.show_select(array('data' => $arr_data_outgoing, 'name' => "strEmailOutgoingType", 'text' => __("Type", 'lang_email'), 'value' => $obj_email->outgoing_type));

								if($obj_email->outgoing_type == 'smtp')
								{
									echo show_textfield(array('name' => "strEmailSmtpServer", 'text' => __("Server", 'lang_email'), 'value' => $obj_email->smtp_server, 'placeholder' => "mail.".$placeholder_server))
									.show_textfield(array('type' => 'number', 'name' => "intEmailSmtpPort", 'text' => __("Port", 'lang_email'), 'value' => $obj_email->smtp_port, 'placeholder' => 587))
									.show_select(array('data' => $obj_email->get_ssl_for_select(), 'name' => "strEmailSmtpSSL", 'text' => __("SSL", 'lang_email'), 'value' => $obj_email->smtp_ssl));
								}

							echo "</div>";

							if($obj_email->outgoing_type == 'smtp')
							{
								echo show_textfield(array('name' => "strEmailSmtpHostname", 'text' => __("Hostname", 'lang_email'), 'value' => $obj_email->smtp_hostname));
							}

							echo "<div class='flex_flow'>"
								.show_textfield(array('name' => "strEmailSmtpUsername", 'text' => __("User", 'lang_email'), 'value' => $obj_email->smtp_username, 'xtra' => " autocomplete='off'"))
								.show_password_field(array('name' => "strEmailSmtpPassword", 'text' => __("Password", 'lang_email'), 'value' => $obj_email->smtp_password, 'xtra' => " autocomplete='off'"))
							."</div>
						</div>
					</div>
				</div>
				<div id='postbox-container-1'>
					<div class='postbox'>
						<div class='inside'>"
							.show_button(array('name' => 'btnEmailCreate', 'text' => __("Save", 'lang_email')))
							.input_hidden(array('name' => "intEmailID", 'value' => $obj_email->id))
							.wp_nonce_field('email_create_'.$obj_email->id, '_wpnonce_email_create', true, false)
						."</div>
					</div>
					<div class='postbox'>
						<h3 class='hndle'><span>".__("Settings", 'lang_email')."</span></h3>
						<div class='inside'>"
							.show_checkbox(array('name' => "intEmailPublic", 'text' => __("Public", 'lang_email'), 'value' => 1, 'compare' => $obj_email->public))
							."<h3>".__("or", 'lang_email')."</h3>"
							.show_select(array('data' => get_roles_for_select(array('use_capability' => false)), 'name' => 'arrEmailRoles[]', 'text' => __("Permission", 'lang_email'), 'value' => $obj_email->roles))
							."<h3>".__("or", 'lang_email')."</h3>"
							.show_select(array('data' => $arr_data_users, 'name' => 'arrEmailUsers[]', 'text' => __("Users", 'lang_email'), 'value' => $obj_email->users))
						."</div>
					</div>
				</div>
			</div>
		</form>
	</div>
</div>";