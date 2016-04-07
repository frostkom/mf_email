var EmailApp = Backbone.Router.extend(
{
	routes: {
		"*actions": "the_rest"
	},
	the_rest: function(action_type)
	{
		if(jQuery('#txtFolders tbody tr').length == 0)
		{
			//action_type = '';
			myEmailView.loadPage('', action_type);
		}

		else
		{
			myEmailView.loadPage(action_type);
		}
	}
});

var app = new EmailApp();