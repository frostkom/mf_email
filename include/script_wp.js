jQuery(function($)
{
	$(document).on('click', "a[href^='mailto:']", function(e)
	{
		if(e.which != 3)
		{
			var this_href = $(this).attr('href').replace('mailto:', ''),
				url = '/wp-admin/admin.php?page=mf_email/send/index.php&strMessageTo=' + this_href;

			location.href = url;

			return false;
		}
	});

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
						$('button[name=btnSmtpTest]').remove();
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