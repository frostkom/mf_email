<?php

$intFolderID = check_var('intFolderID');
$intEmailID = check_var('intEmailID');
$strFolderName = check_var('strFolderName');

if(isset($_POST['btnFolderCreate']) && wp_verify_nonce($_POST['_wpnonce'], 'folder_create'))
{
	if($intFolderID > 0)
	{
		$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email_folder SET emailID = '%d', folderName = %s WHERE folderID = '%d'", $intEmailID, $strFolderName, $intFolderID));

		if($wpdb->rows_affected > 0)
		{
			mf_redirect("?page=mf_email/list/index.php&updated");
		}

		else
		{
			$error_text = __("The folder could not be updated", 'lang_email');
		}
	}

	else
	{
		$wpdb->query($wpdb->prepare("INSERT INTO ".$wpdb->base_prefix."email_folder SET emailID = '%d', folderName = %s, folderCreated = NOW(), userID = '%d'", $intEmailID, $strFolderName, get_current_user_id()));

		$intFolderID = $wpdb->insert_id;

		if($wpdb->rows_affected > 0)
		{
			mf_redirect("?page=mf_email/list/index.php&created");
		}

		else
		{
			$error_text = __("The folder could not be created", 'lang_email');
		}
	}
}

echo "<div class='wrap'>
	<h2>".__("Folder", 'lang_email')."</h2>"
	.get_notification()
	."<div id='poststuff' class='postbox'>
		<h3 class='hndle'>".__("Add", 'lang_email')."</h3>
		<div class='inside'>
			<form action='#' method='post' class='mf_form mf_settings'>";

				if($intFolderID > 0)
				{
					$result = $wpdb->get_results($wpdb->prepare("SELECT emailID, folderName, folderDeleted FROM ".$wpdb->base_prefix."email_folder WHERE folderID = '%d'", $intFolderID));

					foreach($result as $r)
					{
						$intEmailID = $r->emailID;
						$strFolderName = $r->folderName;
						$intFolderDeleted = $r->folderDeleted;

						if($intFolderDeleted == 1)
						{
							$wpdb->query($wpdb->prepare("UPDATE ".$wpdb->base_prefix."email_folder SET folderDeleted = '0', folderDeletedID = '', folderDeletedDate = '' WHERE folderID = '%d'", $intFolderID));
						}
					}
				}

				$arr_data = array();

				$result = $wpdb->get_results("SELECT ".$wpdb->base_prefix."email.emailID, emailName, emailAddress FROM ".$wpdb->base_prefix."email_users RIGHT JOIN ".$wpdb->base_prefix."email USING (emailID) WHERE (emailPublic = '1' OR emailRoles LIKE '%".get_current_user_role()."%' OR ".$wpdb->base_prefix."email.userID = '".get_current_user_id()."' OR ".$wpdb->base_prefix."email_users.userID = '".get_current_user_id()."') AND emailDeleted = '0' ORDER BY emailName ASC, emailAddress ASC");

				foreach($result as $r)
				{
					$intEmailID2 = $r->emailID;
					$strEmailName = $r->emailName;
					$strEmailAddress = $r->emailAddress;

					$strEmailName = $strEmailName != '' ? $strEmailName." &lt;".$strEmailAddress."&gt;" : $strEmailAddress;

					$arr_data[$intEmailID2] = $strEmailName;
				}

				echo show_select(array('data' => $arr_data, 'name' => 'intEmailID', 'compare' => $intEmailID, 'text' => __('Account', 'lang_email')))
				.show_textfield(array('name' => "strFolderName", 'text' => __("Name", 'lang_email'), 'value' => $strFolderName))
				.show_submit(array('name' => 'btnFolderCreate', 'text' => __("Save", 'lang_email')))
				.input_hidden(array('name' => "intFolderID", 'value' => $intFolderID))
				.wp_nonce_field('folder_create', '_wpnonce', true, false)
			."</form>
		</div>
	</div>
</div>";