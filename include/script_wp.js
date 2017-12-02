jQuery(function($)
{
	/* WP admin */
	$(document).on('click', "a[href^='mailto:']", function(e)
	{
		if(e.which != 3)
		{
			var this_href = $(this).attr('href').replace('mailto:', ''),
				url = script_email.admin_url + '&strMessageTo=' + this_href;

			location.href = url;

			return false;
		}
	});

	$(document).on('click', "a[rel='external']", function(e)
	{
		if(e.which != 3)
		{
			window.open($(this).attr('href'));
			return false;
		}
	});

	/* create account */
	function clone_value_if_empty(self_obj, to_obj)
	{
		var dom_from_val = self_obj.val(),
			dom_to_val = to_obj.val();

		if(dom_from_val != '' && dom_to_val == '')
		{
			to_obj.val(dom_from_val);
		}
	}

	if($('.mf_form.mf_settings input[type=hidden][name=intEmailID]').val() == '')
	{
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
	}

	/* send email */
	$("#strMessageTo, #strMessageCc").autocomplete(
	{
		source: function(request, response)
		{
			$.ajax(
			{
				url: script_email.plugin_url + 'ajax.php?type=email/search',
				dataType: "json",
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

	/* settings */
	$(document).on('click', "button[name=btnSmtpTest]", function()
	{
		var smtp_to = $('#smtp_to').val();

		if(typeof smtp_to != undefined && smtp_to != '')
		{
			$('#smtp_debug').html("<i class='fa fa-spinner fa-spin fa-2x'></i>");

			$.ajax(
			{
				type: "post",
				dataType: "json",
				url: script_email.ajax_url,
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