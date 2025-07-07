jQuery(function($)
{
	$(document).on('click', "button[name=btnSmtpTest]:not(.is_disabled)", function()
	{
		var smtp_to = $("#smtp_to").val();

		if(typeof smtp_to != undefined && smtp_to != '')
		{
			$("button[name=btnSmtpTest]").addClass('is_disabled');
			$("#smtp_debug").html(script_email_settings.loading_animation);

			$.ajax(
			{
				url: script_email_settings.ajax_url,
				type: 'post',
				dataType: 'json',
				data: {
					action: 'api_email_smtp_test',
					smtp_to: smtp_to
				},
				success: function(data)
				{
					$("#smtp_debug").html(data.html);
				}
			});
		}

		return false;
	});
});