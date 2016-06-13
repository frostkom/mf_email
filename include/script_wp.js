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
});