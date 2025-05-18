jQuery(function($)
{
	if($("#strMessageTo, #strMessageCc").length > 0)
	{
		$("#strMessageTo, #strMessageCc").autocomplete(
		{
			source: function(request, response)
			{
				$.ajax(
				{
					url: script_email_send.plugin_url + 'api/?type=email/search',
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
});