jQuery(function($)
{
	var dom_show_and_hide_fields = $("#strEmailAddress, #strEmailName, #strEmailServer, #strEmailOutgoingType, #strEmailSmtpServer");

	function show_and_hide_fields()
	{
		var display_email_name = false,
			display_email_signature = false,
			display_email_settings = false,
			display_email_credentials = false,
			display_outgoing_smtp = false;

		dom_show_and_hide_fields.each(function()
		{
			var dom_obj = $(this),
				dom_obj_id = dom_obj.attr('id'),
				dom_obj_val = dom_obj.val();

			switch(dom_obj_id)
			{
				case 'strEmailAddress':
					if(dom_obj_val != '')
					{
						display_email_name = true;
					}
				break;

				case 'strEmailName':
					if(dom_obj_val != '')
					{
						display_email_signature = true;
					}
				break;

				case 'strEmailServer':
					if(dom_obj_val != '')
					{
						display_email_settings = true;
						display_email_credentials = true;
					}
				break;

				case 'strEmailOutgoingType':
					if(dom_obj_val == 'smtp')
					{
						display_outgoing_smtp = true;
					}

					else
					{
						display_smtp_credentials = true;
					}
				break;

				case 'strEmailSmtpServer':
					if(dom_obj_val != '')
					{
						display_email_settings = true;
						display_email_credentials = true;
					}
				break;
			}
		});

		if(display_email_name == true)
		{
			$(".display_email_name").removeClass('hide');
		}

		else
		{
			$(".display_email_name").addClass('hide');
		}

		if(display_email_signature == true)
		{
			$(".display_email_signature").removeClass('hide');
		}

		else
		{
			$(".display_email_signature").addClass('hide');
		}

		if(display_email_settings == true)
		{
			$(".display_email_settings").removeClass('hide');
		}

		else
		{
			$(".display_email_settings").addClass('hide');
		}

		if(display_email_credentials == true)
		{
			$(".display_email_credentials").removeClass('hide');
		}

		else
		{
			$(".display_email_credentials").addClass('hide');
		}

		if(display_outgoing_smtp == true)
		{
			$(".display_outgoing_smtp").removeClass('hide');
		}

		else
		{
			$(".display_outgoing_smtp").addClass('hide');
		}
	}

	show_and_hide_fields();

	dom_show_and_hide_fields.on('keyup change', function()
	{
		show_and_hide_fields();
	});

	function clone_value_if_empty(self_obj, to_obj)
	{
		var dom_from_val = self_obj.val(),
			dom_to_val = to_obj.val();

		if(dom_from_val != '' && dom_to_val == '')
		{
			to_obj.val(dom_from_val);
		}
	}

	$(document).on('blur', "input[name=strEmailAddress]", function()
	{
		clone_value_if_empty($(this), $("input[name=strEmailUsername]"));
	});

	$(document).on('blur', "input[name=strEmailServer]", function()
	{
		clone_value_if_empty($(this), $("input[name=strEmailSmtpServer]"));
	});

	$(document).on('blur', "input[name=strEmailUsername]", function()
	{
		clone_value_if_empty($(this), $("input[name=strEmailSmtpUsername]"));
	});

	$(document).on('blur', "input[name=strEmailAddress]", function()
	{
		var self_obj = $(this),
			to_obj = $("input[name=strEmailSmtpHostname]");

		var dom_from_val = self_obj.val(),
			dom_to_val = to_obj.val();

		if(dom_from_val != '' && dom_to_val == '')
		{
			var arr_from_val = dom_from_val.split("@");

			to_obj.val(arr_from_val[1]);
		}
	});
});