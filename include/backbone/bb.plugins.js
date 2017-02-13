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
			jQuery("#overlay_loading").show();
		},
		onSuccess: function(data){},
		onAfterSend: function()
		{
			jQuery("#overlay_loading").hide();
		},
		onError: function(data)
		{
			setTimeout(function()
			{
				jQuery("#overlay_loading").hide();
				jQuery("#overlay_lost_connection").show();
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
				jQuery("#overlay_lost_connection").show();
			}

			else
			{
				jQuery("#overlay_lost_connection").hide();
			}
		},
		error: function(data)
		{
			op.onError(data);
		}
	});
};