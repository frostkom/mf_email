<?php

$obj_email = new mf_email(array('type' => 'send_email'));
$obj_email->fetch_request();
echo $obj_email->save_data();
$obj_email->get_from_db();

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
							.show_select(array('data' => $obj_email->get_from_for_select(), 'name' => 'intEmailID', 'value' => $obj_email->id, 'text' => __("From", 'lang_email'), 'required' => 1))
							."<div class='flex_flow'>
								<div class='search_container'>"
									.show_textarea(array('name' => 'strMessageTo', 'text' => __("To", 'lang_email'), 'value' => $obj_email->message_to, 'autogrow' => 1, 'xtra' => "autofocus"))
									."<span id='txtMessageTo'></span>
								</div>
								<div class='search_container'>"
									.show_textarea(array('name' => 'strMessageCc', 'text' => __("Cc", 'lang_email'), 'value' => $obj_email->message_cc, 'autogrow' => 1))
									."<span id='txtMessageCc'></span>
								</div>
							</div>"
							.show_textfield(array('name' => 'strMessageSubject', 'text' => __("Subject", 'lang_email'), 'value' => $obj_email->message_subject, 'required' => 1, 'max_length' => 200))
							.show_wp_editor(array('name' => 'strMessageText', 'value' => $obj_email->message_text))
						."</div>
					</div>
				</div>
				<div id='postbox-container-1'>
					<div class='postbox'>
						<h3 class='hndle'>".__("Send", 'lang_email')."</h3>
						<div class='inside'>";

							if($obj_email->all_left_to_send > 0)
							{
								echo show_button(array('name' => 'btnMessageSend', 'text' => __("Send", 'lang_email')))."&nbsp;";
							}

							else
							{
								$hourly_release_time = apply_filters('get_hourly_release_time', '', '');
								$mins = time_between_dates(array('start' => $hourly_release_time, 'end' => date("Y-m-d H:i:s"), 'type' => 'round', 'return' => 'minutes'));

								echo "<p>".sprintf(__("Hourly Limit Reached. Wait %s min", 'lang_email'), (60 - $mins))."</p>";
							}

							echo show_button(array('name' => 'btnMessageDraft', 'text' => __("Save draft", 'lang_email'), 'class' => "button"))
							.input_hidden(array('name' => 'intMessageDraftID', 'value' => $obj_email->message_draft_id))
							.wp_nonce_field('message_send', '_wpnonce_message_send', true, false)
						."</div>
					</div>
					<div class='postbox'>
						<h3 class='hndle'>".__("Advanced", 'lang_email')."</h3>
						<div class='inside'>";

							$arr_data_source = array();
							get_post_children(array('add_choose_here' => true), $arr_data_source);

							echo show_select(array('data' => $arr_data_source, 'name' => 'intEmailTextSource', 'text' => __("Text Source", 'lang_email'), 'xtra' => "rel='submit_change' class='is_disabled' disabled"))
							.get_media_button(array('name' => 'strMessageAttachment', 'value' => $obj_email->message_attachment))
						."</div>
					</div>
				</div>
			</div>
		</form>
	</div>
</div>";