<?php

$server = "";
$username = "";
$password = "";
$port = "";

$imap = new rcube_imap();
$imap->set_debug(false);
$imap->connect($server, $username, $password, $port);

foreach($imap->list_headers() as $header)
{
	// parsed systemical msg
	//$msg = $imap->get_message($header->uid);

	// conains header information, too
	//var_dump($msg->subject);
	//print_r($msg->structure);

	$logical = new rcube_message($imap, $header->uid);

	$strMailMessageID = $header->messageID;
	$strMailReferences = $header->references;
	$strMailFrom = $logical->sender['mailto'];
	$strMailFromName = $logical->sender['name'];
	$strMailTo = $logical->receiver['mailto'];
	$strMailCc = $header->cc;
	$strMailReplyTo = $header->replyto;
	$strMailName = $header->subject;
	$strMailText = $logical->first_text_part();
	$strMailText2 = $logical->first_html_part();
	$strMailCreated = date("Y-m-d H:i:s", $header->timestamp);

	if($strMailMessageID != '')
	{
		echo "Message ID: ".$strMailMessageID."<br/>";
	}

	if($strMailReferences != '')
	{
		echo "References: ".$strMailReferences."<br/>";
	}

	echo "Subject: ".$strMailName."<br/>";
	echo "From: ".$strMailFromName." (".$strMailFrom.")<br/>";
	echo "To: ".$strMailTo."<br/>";

	if($strMailCc != '')
	{
		echo "Cc: ".$strMailCc."<br/>";
	}

	if($strMailReplyTo != '')
	{
		echo "Reply to: ".$strMailReplyTo."<br/>";
	}

	echo "Date: ".$strMailCreated."<br/>";

	if($strMailText != '')
	{
		echo "Text: ".strlen($strMailText)."<br/>";
		//echo "Text: ".$strMailText."<br/>";
	}

	if($strMailText2 != '')
	{
		echo "Html: ".strlen($strMailText2)."<br/>";
		//echo "Html: ".htmlspecialchars($strMailText2)."<br/>";
	}

	if(count($logical->attachments) > 0)
	{
		//echo "Attachments: ".count($logical->attachments)."<br/>";

		foreach($logical->attachments as $attachment)
		{
			echo var_export($attachment, true)."<br/>";
		}

		echo "<br/>";
	}

	echo "<br/>
	=================================================<br/>
	<br/>";
}

$imap->close();