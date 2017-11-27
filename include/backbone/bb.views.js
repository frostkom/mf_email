var EmailView = Backbone.View.extend(
{
	el: jQuery('body'),

	initialize: function()
	{
		this.model.on("change:next_request", this.next_request, this);
		this.model.on("change:folders", this.show_folders, this);
		this.model.on("change:emails", this.show_emails, this);
		this.model.on("change:render_row", this.show_render_row, this);
		this.model.on("change:remove_id", this.remove_id, this);
		this.model.on("change:email", this.show_email, this);
	},

	events:
	{
		"click #message_container .nav-tab-wrapper a:not(.nav-tab-active)": "change_text_format"
	},

	loadPage: function(tab_active, tab_next)
	{
		this.model.getPage(tab_active, tab_next);
	},

	change_text_format: function(e)
	{
		var dom_obj = jQuery(e.currentTarget);

		dom_obj.parent('h3').siblings('div').toggleClass('hide');
		dom_obj.addClass('nav-tab-active').siblings('a').removeClass('nav-tab-active');
	},

	init_dropzone: function()
	{
		var self = this;

		jQuery('.draggable .fa-arrows').draggable(
		{
			revert: true
		});

		jQuery('.droppable').droppable(
		{
			hoverClass: 'color_active',
			drop: function(e, ui)
			{
				var this_drag_id = jQuery(ui.draggable).parents('tr').attr('id'),
					this_drop_id = jQuery(this).attr('id');

				self.loadPage("email/move/" + this_drag_id + "/" + this_drop_id)
			}
		});
	},

	next_request: function()
	{
		var response = this.model.get("next_request");

		if(response != '')
		{
			this.model.getPage(response);

			this.model.set({"next_request" : ""});
		}
	},

	remove_id: function()
	{
		var response = this.model.get("remove_id");

		if(response != '')
		{
			jQuery('#' + response).remove();

			this.model.set({"remove_id" : ""});
		}
	},

	show_folders: function()
	{
		var response = this.model.get("folders"),
			count_temp = response.length,
			html = "";

		if(count_temp > 0)
		{
			for(var i = 0; i < count_temp; i++)
			{
				html += _.template(jQuery('#template_folder_item').html())(response[i]);
			}

			jQuery('#txtFolders tbody').html(html);

			this.mark_current_message(0);
		}

		else
		{
			html = _.template(jQuery('#template_folder_message').html())("");

			jQuery('#txtFolders tbody').html(html);
		}
	},

	show_emails: function()
	{
		var response = this.model.get("emails"),
			limit_start = this.model.get("limit_start"),
			limit_amount = this.model.get("limit_amount"),
			count_temp = response.length,
			html = "";

		if(count_temp > 0)
		{
			for(var i = 0; i < count_temp; i++)
			{
				html += _.template(jQuery('#template_email_item').html())(response[i]);
			}

			if(limit_start == 0)
			{
				jQuery('#txtEmails tbody').html(html);
			}

			else
			{
				jQuery('#txtEmails tbody tr.show_more').remove();

				jQuery('#txtEmails tbody').append(html);
			}

			if(limit_amount >= script_email_bb_views.emails2show)
			{
				html = _.template(jQuery('#template_email_more').html())({'folderName': this.model.get("folderName"), 'limit_start': parseInt(limit_start) + parseInt(script_email_bb_views.emails2show)});

				jQuery('#txtEmails tbody').append(html);
			}

			this.mark_current_message();
			this.init_dropzone();
		}

		else
		{
			html = _.template(jQuery('#template_email_message').html())("");

			jQuery('#txtEmails tbody').html(html);
		}
	},

	show_render_row: function()
	{
		var response = this.model.get("render_row"),
			html = _.template(jQuery('#template_email_item').html())(response);

		jQuery('#message' + response.messageID).replaceWith(html);

		this.mark_current_message();
		this.init_dropzone();
	},

	show_email: function()
	{
		var response = this.model.get("email"),
			html = _.template(jQuery('#template_email_show').html())(response);

		this.mark_current_message(response.messageID);

		jQuery('#txtEmail').html(html);
	},

	mark_current_message: function(currentMessageID)
	{
		if(currentMessageID >= 0)
		{
			if(currentMessageID > 0)
			{
				jQuery('#email_column').addClass('flex_vertical');
			}

			else
			{
				jQuery('#txtEmail').empty();
				jQuery('#email_column').removeClass('flex_vertical');
			}

			this.currentMessageID = currentMessageID;
		}

		if(this.currentMessageID > 0)
		{
			jQuery('#message' + this.currentMessageID).addClass('color_active yellow').siblings('tr').removeClass('color_active yellow');
		}
	}
});

var myEmailView = new EmailView({model: new EmailModel()});