var EmailApp = Backbone.Router.extend(
{
	routes: {
		"*actions": "the_rest"
	},
	the_rest: function(action_type)
	{
		if(jQuery('#txtFolders tbody tr').length == 0)
		{
			myEmailView.loadPage('', action_type);
		}

		else
		{
			myEmailView.loadPage(action_type);
		}
	}
});

new EmailApp();