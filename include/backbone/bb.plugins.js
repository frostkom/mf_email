jQuery.fn.callAPI = function(o)
{
	var op = jQuery.extend(
	{
		base_url: '/wp-content/plugins/mf_email/include/ajax.php',
		url: '',
		data: '',
		send_type: 'post',
		onBeforeSend: function()
		{
			jQuery("#loading").show();
		},
		onSuccess: function(data){},
		onAfterSend: function()
		{
			jQuery("#loading").hide();
		},
		onError: function(data)
		{
			setTimeout(function()
			{
				jQuery("#loading").hide();
				jQuery("#lost_connection").show();
			}, 2000);
		}
	}, o);

	jQuery.ajax(
	{
		url: op.base_url + op.url,
		type: op.send_type,
		processData: false,
		data: op.data,
		dataType: 'json',
		beforeSend: function()
		{
			op.onBeforeSend();
		},
		success: function(data)
		{
			op.onSuccess(data);
			op.onAfterSend();

			if(data.mysqli_error && data.mysqli_error == true)
			{
				jQuery("#lost_connection").show();
			}

			else
			{
				jQuery("#lost_connection").hide();
			}
		},
		error: function(data)
		{
			op.onError(data);
		}
	});
};