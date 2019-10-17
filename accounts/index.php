<?php

$obj_email = new mf_email(array('type' => 'account_list'));
$obj_email->fetch_request();
echo $obj_email->save_data();
$obj_email->get_from_db();

echo "<div class='wrap'>
	<h2>"
		.__("Accounts", 'lang_email')
		."<a href='".admin_url("admin.php?page=mf_email/create/index.php")."' class='add-new-h2'>".__("Add New", 'lang_email')."</a>"
	."</h2>"
	.get_notification();

	$tbl_group = new mf_email_account_table();

	$tbl_group->select_data(array(
		//'select' => "*",
		'debug' => ($_SERVER['REMOTE_ADDR'] == ""),
	));

	$tbl_group->do_display();

echo "</div>";