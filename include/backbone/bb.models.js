var EmailModel = Backbone.Model.extend(
{
	getPage: function(dom_href, tab_next)
	{
		var self = this,
			form_data = '';

		jQuery().callAPI(
		{
			base_url: script_email_models.plugin_url + 'api/',
			url: dom_href ? "?type=" + dom_href : '',
			data: form_data,
			onSuccess: function(data)
			{
				self.set(data);

				if(tab_next && tab_next != '')
				{
					myEmailView.loadPage(tab_next);
				}
			}
		});
	}
});