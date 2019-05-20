jQuery(function($)
{
	/* WP Admin */
	$(document).on('click', "a[href^='mailto:']", function(e)
	{
		/* If left-click and the admin menu item has not been removed by for example MF Admin Menu */
		if(e.which != 3 && $("#toplevel_page_mf_email-list-index").length > 0)
		{
			var this_href = $(this).attr('href').replace("mailto:", "").replace("?", "&").replace("&subject", "&strMessageSubject").replace("&body", "&strMessageText"),
				url = script_email.admin_url + '&strMessageTo=' + this_href;

			location.href = url;

			return false;
		}
	});

	/* Create Account */
	function clone_value_if_empty(self_obj, to_obj)
	{
		var dom_from_val = self_obj.val(),
			dom_to_val = to_obj.val();

		if(dom_from_val != '' && dom_to_val == '')
		{
			to_obj.val(dom_from_val);
		}
	}

	$(document).on('blur', 'input[name=strEmailAddress]', function()
	{
		clone_value_if_empty($(this), $('input[name=strEmailUsername]'));
	});

	$(document).on('blur', 'input[name=strEmailServer]', function()
	{
		clone_value_if_empty($(this), $('input[name=strEmailSmtpServer]'));
	});

	$(document).on('blur', 'input[name=strEmailUsername]', function()
	{
		clone_value_if_empty($(this), $('input[name=strEmailSmtpUsername]'));
	});

	$(document).on('blur', 'input[name=strEmailAddress]', function()
	{
		var self_obj = $(this),
			to_obj = $('input[name=strEmailSmtpHostname]');

		var dom_from_val = self_obj.val(),
			dom_to_val = to_obj.val();

		if(dom_from_val != '' && dom_to_val == '')
		{
			var arr_from_val = dom_from_val.split("@");

			to_obj.val(arr_from_val[1]);
		}
	});

	/* Send Email */
	if($("#strMessageTo, #strMessageCc").length > 0)
	{
		$("#strMessageTo, #strMessageCc").autocomplete(
		{
			source: function(request, response)
			{
				$.ajax(
				{
					url: script_email.plugin_url + 'api/?type=email/search',
					dataType: 'json',
					data: {
						s: request.term
					},
					success: function(data)
					{
						if(data.amount > 0)
						{
							response(data);
						}
					}
				});
			},
			minLength: 3
		});
	}

	/* Settings */
	$(document).on('click', "button[name=btnSmtpTest]", function()
	{
		var smtp_to = $('#smtp_to').val();

		if(typeof smtp_to != undefined && smtp_to != '')
		{
			$('#smtp_debug').html("<i class='fa fa-spinner fa-spin fa-2x'></i>");

			$.ajax(
			{
				url: script_email.ajax_url,
				type: 'post',
				dataType: 'json',
				data: {
					action: "send_smtp_test",
					smtp_to: smtp_to
				},
				success: function(data)
				{
					$('#smtp_debug').empty();

					if(data.success)
					{
						$('button[name=btnSmtpTest]').attr('disabled', true);
						$('#smtp_debug').html(data.message);
					}

					else
					{
						$('#smtp_debug').html(data.error);
					}
				}
			});
		}

		return false;
	});
});