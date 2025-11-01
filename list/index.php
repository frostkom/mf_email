<?php

global $obj_base;

if(!isset($obj_base))
{
	$obj_base = new mf_base();
}

$obj_email = new mf_email();

$intFolderID = check_var('intFolderID');
$strFolderName = check_var('strFolderName', '', true, __("Inbox", 'lang_email'));

if(isset($_GET['sent']))
{
	$done_text = __("The e-mail was successfully sent", 'lang_email');
}

else if(isset($_GET['created']))
{
	$done_text = __("The folder was created", 'lang_email');
}

else if(isset($_GET['updated']))
{
	$done_text = __("The folder was updated", 'lang_email');
}

echo "<div class='wrap'>
	<h2>"
		.__("E-mail", 'lang_email')
		."<a href='".admin_url("admin.php?page=mf_email/folder/index.php")."' class='add-new-h2'>".__("Add New Folder", 'lang_email')."</a>"
		."<a href='".admin_url("admin.php?page=mf_email/send/index.php")."' class='add-new-h2'>".__("Send new", 'lang_email')."</a>"
	."</h2>"
	.get_notification()
	."<div".apply_filters('get_flex_flow', "").">
		<div>
			<table id='txtFolders'".apply_filters('get_table_attr', "")."><tbody></tbody></table>
		</div>
		<div id='email_column'>
			<div id='txtEmails'>
				<table".apply_filters('get_table_attr', "")."><tbody></tbody></table>
			</div>
			<div id='txtEmail' class='stuffbox'></div>
		</div>
	</div>
</div>"
.$obj_base->get_templates(array('lost_connection', 'loading'))
."<script type='text/template' id='template_folder_item'>
	<tr id='folder<%= folderID %>' class='<%= folderClass %>'>
		<td>
			<i class='<%= folderImage %> fa-lg'></i>
		</td>
		<td>
			<a href='#email/folders/<%= folderName %>'>
				<%= folderName %>
			</a>
			<div class='row-actions'>
				<% if(folderUnread > 0)
				{ %>
					<%= folderUnread %> /
				<% } %>
				<%= folderTotal %>
				<% if(!(folderType > 0))
				{ %>
					| <a href='".admin_url("admin.php?page=mf_email/folder/index.php&intFolderID=<%= folderID %>")."'>".__("Edit", 'lang_email')."</a>
				<% }

				if(folderTotal == 0)
				{ %>
					 | <a href='#email/folders/<%= folderID %>/delete'".make_link_confirm().">".__("Delete", 'lang_email')."</a>
				<% } %>
			</div>
		</td>
	</tr>
</script>

<script type='text/template' id='template_folder_message'>
	<tr><td colspan='2'>".__("There is nothing to show", 'lang_email')."</td></tr>
</script>

<script type='text/template' id='template_email_more'>
	<tr class='show_more'><td colspan='5'><a href='#email/emails/<%= folderName %>/<%= limit_start %>'>".__("Show more", 'lang_email')."&hellip;</a></td></tr>
</script>

<script type='text/template' id='template_email_item'>
	<tr id='message<%= messageID %>' class='<%= messageClass %>'>
		<td>
			<% if(messageDraggable)
			{ %>
				<i class='fa fa-arrows-alt fa-lg'></i>
			<% } %>
			<% if(messageAttachment)
			{ %>
				<div class='row-actions'>
					<i class='fa fa-paperclip fa-lg'></i>
				</div>
			<% } %>
		</td>
		<td>"
			."<% if(messageDeleted == 0)
			{
				if(folderType == 5)
				{ %>
					<a href='".admin_url("admin.php?page=mf_email/send/index.php&intMessageDraftID=<%= messageID %>")."'>
				<% }

				else
				{ %>
					<a href='#email/show/<%= messageID %>'>
				<% }
			} %>
				<%= messageName %>
			<% if(messageDeleted == 0)
			{ %>
				</a>
			<% } %>"
			."<div class='row-actions'>
				<% if(messageDeleted == 0)
				{ %>
					<a href='#email/delete/<%= messageID %>'".make_link_confirm().">".__("Delete", 'lang_email')."</a>
					 | <a href='#email/spam/<%= messageID %>'".make_link_confirm().">".__("Mark as Spam", 'lang_email')."</a>
				<% }

				else
				{ %>
					<a href='#email/restore/<%= messageID %>' title='".sprintf(__("Removed %s", 'lang_email'), "<%= messageDeletedDate %>")."'".make_link_confirm().">".__("Restore", 'lang_email')."</a>
				<% } %>
			</div>
		</td>
		<td>
			<% if(messageRead == 0)
			{ %>
				<a href='#email/read/<%= messageID %>'><i class='fa fa-circle fa-lg green'></i></a>
			<% }

			else
			{ %>
				<a href='#email/unread/<%= messageID %>'><i class='far fa-circle fa-lg'></i></a>
			<% } %>
		</td>
		<td>
			<% if(messageOutgoing)
			{ %>
				<a href='mailto:<%= messageTo %>'><%= messageToName %></a>
				<div class='row-actions'>
					<%= messageFromName %>
					<% if(messageFrom != messageFromName)
					{ %>
						&nbsp;&lt;<%= messageFrom %>&gt;
					<% } %>
				</div>
			<% }

			else
			{ %>
				<a href='mailto:<%= messageFrom %>'><%= messageFromName %></a>
				<div class='row-actions'>
					<%= emailAddress %>
				</div>
			<% } %>
		</td>
		<td>
			<%= messageCreated %>
			<% if(messageReceived != messageCreated)
			{ %>
				<div class='row-actions'>
					<%= messageReceived %>
				</div>
			<% } %>
		</td>
	</tr>
</script>

<script type='text/template' id='template_email_message'>
	<tr><td colspan=''>".__("There is nothing to show", 'lang_email')."</td></tr>
</script>

<script type='text/template' id='template_email_show'>
	<ul class='alternate'>
		<li><strong>".__("Subject", 'lang_email').":</strong> <%= messageName %></li>
		<li><strong>".__("From", 'lang_email').":</strong> <%= messageFrom %> -> <%= messageTo %></li>
		<% if(messageCc != '')
		{ %>
			<li><strong>".__("Cc", 'lang_email').":</strong> <%= messageCc %></li>
		<% } %>
		<li><a href='".admin_url("admin.php?page=mf_email/send/index.php&intMessageID=<%= messageID %>&answer")."' class='button'><i class='fa fa-chevron-left'></i> ".__("Answer", 'lang_email')."</a> <a href='?page=mf_email/send/index.php&intMessageID=<%= messageID %>&forward' class='button'>".__("Forward", 'lang_email')." <i class='fa fa-chevron-right'></i></a></li>
		<% if(messageAttachment.length > 0)
		{ %>
			<li>&nbsp;</li>
			<% _.each(messageAttachment, function(attachment)
			{ %>
				<li>
					<a href='<%= attachment.url %>'>
						<%= attachment.title %>
					</a>
				</li>
			<% });
		} %>
	</ul>
	<div id='message_container'>
		<% if(messageText2)
		{ %>
			<% if(messageText != '')
			{ %>
				<h3 class='nav-tab-wrapper'>
					<a class='nav-tab'>".__("Plain", 'lang_email')."</a>
					<a class='nav-tab nav-tab-active'>HTML</a>
				</h3>
				<div class='hide'>
					<%= messageText %>
				</div>
			<% } %>
			<div>
				<%= messageText2 %>
			</div>
		<% }

		else
		{ %>
			<div>
				<%= messageText %>
			</div>
		<% } %>
	</div>
</script>";