<?php

/**
 +-----------------------------------------------------------------------+
 | program/include/rcube_imap_generic.php								|
 |																	   |
 | This file is part of the Roundcube Webmail client					 |
 | Copyright (C) 2005-2010, The Roundcube Dev Team					   |
 | Copyright (C) 2011, Kolab Systems AG								  |
 | Licensed under the GNU GPL											|
 |																	   |
 | PURPOSE:															  |
 |   Provide alternative IMAP library that doesn't rely on the standard  |
 |   C-Client based version. This allows to function regardless		  |
 |   of whether or not the PHP build it's running on has IMAP			|
 |   functionality built-in.											 |
 |																	   |
 |   Based on Iloha IMAP Library. See http://ilohamail.org/ for details  |
 |																	   |
 +-----------------------------------------------------------------------+
 | Author: Aleksander Machniak <alec@alec.pl>							|
 | Author: Ryo Chijiiwa <Ryo@IlohaMail.org>							  |
 +-----------------------------------------------------------------------+

 $Id: rcube_imap_generic.php 5691 2012-01-03 09:57:14Z alec $

*/


/**
 * Struct representing an e-mail message header
 *
 * @package Mail
 * @author  Aleksander Machniak <alec@alec.pl>
 */
class rcube_mail_header
{
	public $id;
	public $uid;
	public $subject;
	public $from;
	public $to;
	public $cc;
	public $replyto;
	public $in_reply_to;
	public $date;
	public $messageID;
	public $size;
	public $encoding;
	public $charset;
	public $ctype;
	public $timestamp;
	public $bodystructure;
	public $internaldate;
	public $references;
	public $priority;
	public $mdn_to;
	public $others = [];
	public $flags = [];

	var $structure;
}

// For backward compatibility with cached messages (#1486602)
class iilBasicHeader extends rcube_mail_header
{
}

/**
 * PHP based wrapper class to connect to an IMAP server
 *
 * @package Mail
 * @author  Aleksander Machniak <alec@alec.pl>
 */
class rcube_imap_generic
{
	public $error;
	public $errornum;
	public $result;
	public $resultcode;
	public $selected;
	public $data = [];
	public $flags = array(
		'SEEN'	 => '\\Seen',
		'DELETED'  => '\\Deleted',
		'ANSWERED' => '\\Answered',
		'DRAFT'	=> '\\Draft',
		'FLAGGED'  => '\\Flagged',
		'FORWARDED' => '$Forwarded',
		'MDNSENT'  => '$MDNSent',
		'*'		=> '\\*',
	);

	private $fp;
	private $host;
	private $logged = false;
	private $capability = [];
	private $capability_readed = false;
	private $prefs;
	private $cmd_tag;
	private $cmd_num = 0;
	private $resourceid;
	private $_debug = false;
	private $_debug_handler = false;

	const ERROR_OK = 0;
	const ERROR_NO = -1;
	const ERROR_BAD = -2;
	const ERROR_BYE = -3;
	const ERROR_UNKNOWN = -4;
	const ERROR_COMMAND = -5;
	const ERROR_READONLY = -6;

	const COMMAND_NORESPONSE = 1;
	const COMMAND_CAPABILITY = 2;
	const COMMAND_LASTLINE   = 4;

	var $user;

	/**
	 * Object constructor
	 */
	function __construct()
	{
	}

	/**
	 * Send simple (one line) command to the connection stream
	 *
	 * @param string $string Command string
	 * @param bool   $endln  True if CRLF need to be added at the end of command
	 *
	 * @param int Number of bytes sent, False on error
	 */
	function putLine($string, $endln=true)
	{
		if(!$this->fp)
			return false;

		if($this->_debug) {
			$this->debug('C: '. rtrim($string));
		}

		$res = fwrite($this->fp, $string . ($endln ? "\r\n" : ''));

		if($res === false) {
			@fclose($this->fp);
			$this->fp = null;
		}

		return $res;
	}

	/**
	 * Send command to the connection stream with Command Continuation
	 * Requests (RFC3501 7.5) and LITERAL+ (RFC2088) support
	 *
	 * @param string $string Command string
	 * @param bool   $endln  True if CRLF need to be added at the end of command
	 *
	 * @param int Number of bytes sent, False on error
	 */
	function putLineC($string, $endln=true)
	{
		if(!$this->fp)
			return false;

		if($endln)
			$string .= "\r\n";


		$res = 0;
		if($parts = preg_split('/(\{[0-9]+\}\r\n)/m', $string, -1, PREG_SPLIT_DELIM_CAPTURE)) {
			for($i=0, $cnt=count($parts); $i<$cnt; $i++) {
				if(isset($parts[$i+1]) && preg_match('/^\{([0-9]+)\}\r\n$/', $parts[$i+1], $matches)) {
					// LITERAL+ support
					if($this->prefs['literal+']) {
						$parts[$i+1] = sprintf("{%d+}\r\n", $matches[1]);
					}

					$bytes = $this->putLine($parts[$i].$parts[$i+1], false);
					if($bytes === false)
						return false;
					$res += $bytes;

					// don't wait if server supports LITERAL+ capability
					if(!$this->prefs['literal+']) {
						$line = $this->readLine(1000);
						// handle error in command
						if($line[0] != '+')
							return false;
					}
					$i++;
				}
				else {
					$bytes = $this->putLine($parts[$i], false);
					if($bytes === false)
						return false;
					$res += $bytes;
				}
			}
		}
		return $res;
	}

	function readLine($size=1024)
	{
		$line = '';

		if(!$size) {
			$size = 1024;
		}

		do {
			if($this->eof()) {
				return $line ? $line : NULL;
			}

			$buffer = fgets($this->fp, $size);

			if($buffer === false) {
				$this->closeSocket();
				break;
			}
			if($this->_debug) {
				$this->debug('S: '. rtrim($buffer));
			}
			$line .= $buffer;
		} while(substr($buffer, -1) != "\n");

		return $line;
	}

	function multLine($line, $escape = false)
	{
		$line = rtrim($line);
		if(preg_match('/\{([0-9]+)\}$/', $line, $m)) {
			$out   = '';
			$str   = substr($line, 0, -strlen($m[0]));
			$bytes = $m[1];

			while(strlen($out) < $bytes) {
				$line = $this->readBytes($bytes);
				if($line === NULL)
					break;
				$out .= $line;
			}

			$line = $str . ($escape ? $this->escape($out) : $out);
		}

		return $line;
	}

	function readBytes($bytes)
	{
		$data = '';
		$len  = 0;
		while($len < $bytes && !$this->eof())
		{
			$d = fread($this->fp, $bytes-$len);
			if($this->_debug) {
				$this->debug('S: '. $d);
			}
			$data .= $d;
			$data_len = strlen($data);
			if($len == $data_len) {
				break; // nothing was read -> exit to avoid apache lockups
			}
			$len = $data_len;
		}

		return $data;
	}

	function readReply(&$untagged=null)
	{
		do
		{
			$line = trim($this->readLine(1024));

			// store untagged response lines
			if(isset($line[0]) && $line[0] == '*')
			{
				$untagged[] = $line;
			}
		}

		while(isset($line[0]) && $line[0] == '*');

		if($untagged)
		{
			$untagged = join("\n", $untagged);
		}

		return $line;
	}

	function parseResult($string, $err_prefix='')
	{
		if(preg_match('/^[a-z0-9*]+ (OK|NO|BAD|BYE)(.*)$/i', trim($string), $matches)) {
			$res = strtoupper($matches[1]);
			$str = trim($matches[2]);

			if($res == 'OK') {
				$this->errornum = self::ERROR_OK;
			} else if($res == 'NO') {
				$this->errornum = self::ERROR_NO;
			} else if($res == 'BAD') {
				$this->errornum = self::ERROR_BAD;
			} else if($res == 'BYE') {
				$this->closeSocket();
				$this->errornum = self::ERROR_BYE;
			}

			if($str) {
				$str = trim($str);
				// get response string and code (RFC5530)
				if(preg_match("/^\[([a-z-]+)\]/i", $str, $m)) {
					$this->resultcode = strtoupper($m[1]);
					$str = trim(substr($str, strlen($m[1]) + 2));
				}
				else {
					$this->resultcode = null;
					// parse response for [APPENDUID 1204196876 3456]
					if(preg_match("/^\[APPENDUID [0-9]+ ([0-9,:*]+)\]/i", $str, $m)) {
						$this->data['APPENDUID'] = $m[1];
					}
				}
				$this->result = $str;

				if($this->errornum != self::ERROR_OK) {
					$this->error = $err_prefix ? $err_prefix.$str : $str;
				}
			}

			return $this->errornum;
		}
		return self::ERROR_UNKNOWN;
	}

	private function eof()
	{
		if(!is_resource($this->fp)) {
			return true;
		}

		// If a connection opened by fsockopen() wasn't closed by the server, feof() will hang.
		$start = microtime(true);

		if(feof($this->fp) || ($this->prefs['timeout'] && (microtime(true) - $start > $this->prefs['timeout'])))
		{
			$this->closeSocket();
			return true;
		}

		return false;
	}

	private function closeSocket()
	{
		@fclose($this->fp);
		$this->fp = null;
	}

	function setError($code, $msg='')
	{
		$this->errornum = $code;
		$this->error	= $msg;
	}

	// check if $string starts with $match (or * BYE/BAD)
	function startsWith($string, $match, $error=false, $nonempty=false)
	{
		$len = strlen($match);
		if($len == 0) {
			return false;
		}
		if(!$this->fp) {
			return true;
		}
		if(strncmp($string, $match, $len) == 0) {
			return true;
		}
		if($error && preg_match('/^\* (BYE|BAD) /i', $string, $m)) {
			if(strtoupper($m[1]) == 'BYE') {
				$this->closeSocket();
			}
			return true;
		}
		if($nonempty && !strlen($string)) {
			return true;
		}
		return false;
	}

	private function hasCapability($name)
	{
		if(empty($this->capability) || $name == '') {
			return false;
		}

		if(in_array($name, $this->capability)) {
			return true;
		}
		else if(strpos($name, '=')) {
			return false;
		}

		$result = [];
		foreach($this->capability as $cap) {
			$entry = explode('=', $cap);
			if($entry[0] == $name) {
				$result[] = $entry[1];
			}
		}

		return !empty($result) ? $result : false;
	}

	/**
	 * Capabilities checker
	 *
	 * @param string $name Capability name
	 *
	 * @return mixed Capability values array for key=value pairs, true/false for others
	 */
	function getCapability($name)
	{
		$result = $this->hasCapability($name);

		if(!empty($result)) {
			return $result;
		}
		else if($this->capability_readed) {
			return false;
		}

		// get capabilities (only once) because initial
		// optional CAPABILITY response may differ
		$result = $this->execute('CAPABILITY');

		if($result[0] == self::ERROR_OK) {
			$this->parseCapability($result[1]);
		}

		$this->capability_readed = true;

		return $this->hasCapability($name);
	}

	function clearCapability()
	{
		$this->capability = [];
		$this->capability_readed = false;
	}

	/**
	 * DIGEST-MD5/CRAM-MD5/PLAIN Authentication
	 *
	 * @param string $user
	 * @param string $pass
	 * @param string $type Authentication type (PLAIN/CRAM-MD5/DIGEST-MD5)
	 *
	 * @return resource Connection resourse on success, error code on error
	 */
	function authenticate($user, $pass, $type='PLAIN')
	{
		if($type == 'CRAM-MD5' || $type == 'DIGEST-MD5') {
			if($type == 'DIGEST-MD5' && !class_exists('Auth_SASL')) {
				$this->setError(self::ERROR_BYE,
					"The Auth_SASL package is required for DIGEST-MD5 authentication");
				return self::ERROR_BAD;
			}

			$this->putLine($this->nextTag() . " AUTHENTICATE $type");
			$line = trim($this->readReply());

			if($line[0] == '+') {
				$challenge = substr($line, 2);
			}
			else {
				return $this->parseResult($line);
			}

			if($type == 'CRAM-MD5') {
				// RFC2195: CRAM-MD5
				$ipad = '';
				$opad = '';

				// initialize ipad, opad
				for($i=0; $i<64; $i++) {
					$ipad .= chr(0x36);
					$opad .= chr(0x5C);
				}

				// pad $pass so it's 64 bytes
				$padLen = 64 - strlen($pass);
				for($i=0; $i<$padLen; $i++) {
					$pass .= chr(0);
				}

				// generate hash
				$hash  = md5($this->_xor($pass, $opad) . pack("H*",
					md5($this->_xor($pass, $ipad) . base64_decode($challenge))));
				$reply = base64_encode($user . ' ' . $hash);

				// send result
				$this->putLine($reply);
			}
			else {
				// RFC2831: DIGEST-MD5
				// proxy authorization
				if(!empty($this->prefs['auth_cid'])) {
					$authc = $this->prefs['auth_cid'];
					$pass  = $this->prefs['auth_pw'];
				}
				else {
					$authc = $user;
				}
				$auth_sasl = Auth_SASL::factory('digestmd5');
				$reply = base64_encode($auth_sasl->getResponse($authc, $pass,
					base64_decode($challenge), $this->host, 'imap', $user));

				// send result
				$this->putLine($reply);
				$line = trim($this->readReply());

				if($line[0] == '+') {
					$challenge = substr($line, 2);
				}
				else {
					return $this->parseResult($line);
				}

				// check response
				$challenge = base64_decode($challenge);
				if(strpos($challenge, 'rspauth=') === false) {
					$this->setError(self::ERROR_BAD,
						"Unexpected response from server to DIGEST-MD5 response");
					return self::ERROR_BAD;
				}

				$this->putLine('');
			}

			$line = $this->readReply();
			$result = $this->parseResult($line);
		}
		else { // PLAIN
			// proxy authorization
			if(!empty($this->prefs['auth_cid'])) {
				$authc = $this->prefs['auth_cid'];
				$pass  = $this->prefs['auth_pw'];
			}
			else {
				$authc = $user;
			}

			$reply = base64_encode($user . chr(0) . $authc . chr(0) . $pass);

			// RFC 4959 (SASL-IR): save one round trip
			if($this->getCapability('SASL-IR')) {
				list($result, $line) = $this->execute("AUTHENTICATE PLAIN", array($reply),
					self::COMMAND_LASTLINE | self::COMMAND_CAPABILITY);
			}
			else {
				$this->putLine($this->nextTag() . " AUTHENTICATE PLAIN");
				$line = trim($this->readReply());

				if(isset($line[0]) && $line[0] != '+') {
					return $this->parseResult($line);
				}

				// send result, get reply and process it
				$this->putLine($reply);
				$line = $this->readReply();
				$result = $this->parseResult($line);
			}
		}

		if($result == self::ERROR_OK) {
			// optional CAPABILITY response
			if($line && preg_match('/\[CAPABILITY ([^]]+)\]/i', $line, $matches)) {
				$this->parseCapability($matches[1], true);
			}
			return $this->fp;
		}
		else {
			$this->setError($result, "AUTHENTICATE $type: $line");
		}

		return $result;
	}

	/**
	 * LOGIN Authentication
	 *
	 * @param string $user
	 * @param string $pass
	 *
	 * @return resource Connection resourse on success, error code on error
	 */
	function login($user, $password)
	{
		list($code, $response) = $this->execute('LOGIN', array(
			$this->escape($user), $this->escape($password)), self::COMMAND_CAPABILITY);

		// re-set capabilities list if untagged CAPABILITY response provided
		if(preg_match('/\* CAPABILITY (.+)/i', $response, $matches)) {
			$this->parseCapability($matches[1], true);
		}

		if($code == self::ERROR_OK) {
			return $this->fp;
		}

		return $code;
	}

	/**
	 * Gets the delimiter
	 *
	 * @return string The delimiter
	 */
	function getHierarchyDelimiter()
	{
		if(isset($this->prefs['delimiter']) && $this->prefs['delimiter']) {
			return $this->prefs['delimiter'];
		}

		// try (LIST "" ""), should return delimiter (RFC2060 Sec 6.3.8)
		list($code, $response) = $this->execute('LIST',
			array($this->escape(''), $this->escape('')));

		if($code == self::ERROR_OK) {
			$args = $this->tokenizeResponse($response, 4);
			$delimiter = $args[3];

			if(strlen($delimiter) > 0) {
				return ($this->prefs['delimiter'] = $delimiter);
			}
		}

		return NULL;
	}

	/**
	 * NAMESPACE handler (RFC 2342)
	 *
	 * @return array Namespace data hash (personal, other, shared)
	 */
	function getNamespace()
	{
		if(array_key_exists('namespace', $this->prefs)) {
			return $this->prefs['namespace'];
		}

		if(!$this->getCapability('NAMESPACE')) {
			return self::ERROR_BAD;
		}

		list($code, $response) = $this->execute('NAMESPACE');

		if($code == self::ERROR_OK && preg_match('/^\* NAMESPACE /', $response)) {
			@$data = $this->tokenizeResponse(substr($response, 11));
		}

		if(!isset($data) || !is_array($data)) {
			return $code;
		}

		$this->prefs['namespace'] = array(
			'personal' => $data[0],
			'other'	=> $data[1],
			'shared'   => $data[2],
		);

		return $this->prefs['namespace'];
	}

	function connect($host, $user, $password, $options=null)
	{
		// set options
		if(is_array($options)) {
			$this->prefs = $options;
		}
		// set auth method
		if(!empty($this->prefs['auth_type'])) {
			$auth_method = strtoupper($this->prefs['auth_type']);
		} else {
			$auth_method = 'CHECK';
		}

		$result = false;

		// initialize connection
		$this->error	= '';
		$this->errornum = self::ERROR_OK;
		$this->selected = null;
		$this->user	 = $user;
		$this->host	 = $host;
		$this->logged   = false;

		// check input
		if(empty($host)) {
			$this->setError(self::ERROR_BAD, "Empty host");
			return false;
		}
		if(empty($user)) {
			$this->setError(self::ERROR_NO, "Empty user");
			return false;
		}
		if(empty($password)) {
			$this->setError(self::ERROR_NO, "Empty password");
			return false;
		}

		if(!$this->prefs['port']) {
			$this->prefs['port'] = 143;
		}
		// check for SSL
		if($this->prefs['ssl_mode'] && $this->prefs['ssl_mode'] != 'tls') {
			$host = $this->prefs['ssl_mode'] . '://' . $host;
		}

		if($this->prefs['timeout'] <= 0) {
			$this->prefs['timeout'] = ini_get('default_socket_timeout');
		}

		// Connect
		$this->fp = @fsockopen($host, $this->prefs['port'], $errno, $errstr, $this->prefs['timeout']);

		if(!$this->fp) {
			$this->setError(self::ERROR_BAD, sprintf("Could not connect to %s:%d: %s", $host, $this->prefs['port'], $errstr));
			return false;
		}

		if($this->prefs['timeout'] > 0)
			stream_set_timeout($this->fp, $this->prefs['timeout']);

		$line = trim(fgets($this->fp, 8192));

		if($this->_debug) {
			// set connection identifier for debug output
			preg_match('/#([0-9]+)/', (string)$this->fp, $m);
			$this->resourceid = strtoupper(substr(md5($m[1].$this->user.microtime()), 0, 4));

			if($line)
				$this->debug('S: '. $line);
		}

		// Connected to wrong port or connection error?
		if(!preg_match('/^\* (OK|PREAUTH)/i', $line)) {
			if($line)
				$error = sprintf("Wrong startup greeting (%s:%d): %s", $host, $this->prefs['port'], $line);
			else
				$error = sprintf("Empty startup greeting (%s:%d)", $host, $this->prefs['port']);

			$this->setError(self::ERROR_BAD, $error);
			$this->closeConnection();
			return false;
		}

		// RFC3501 [7.1] optional CAPABILITY response
		if(preg_match('/\[CAPABILITY ([^]]+)\]/i', $line, $matches)) {
			$this->parseCapability($matches[1], true);
		}

		// TLS connection
		if($this->prefs['ssl_mode'] == 'tls' && $this->getCapability('STARTTLS')) {
			if(version_compare(PHP_VERSION, '5.1.0', '>=')) {
				$res = $this->execute('STARTTLS');

				if($res[0] != self::ERROR_OK) {
					$this->closeConnection();
					return false;
				}

				if(!stream_socket_enable_crypto($this->fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
					$this->setError(self::ERROR_BAD, "Unable to negotiate TLS");
					$this->closeConnection();
					return false;
				}

				// Now we're secure, capabilities need to be reread
				$this->clearCapability();
			}
		}

		// Send ID info
		if(!empty($this->prefs['ident']) && $this->getCapability('ID')) {
			$this->id($this->prefs['ident']);
		}

		$auth_methods = [];
		$result	   = null;

		// check for supported auth methods
		if($auth_method == 'CHECK') {
			if($auth_caps = $this->getCapability('AUTH')) {
				$auth_methods = $auth_caps;
			}
			// RFC 2595 (LOGINDISABLED) LOGIN disabled when connection is not secure
			$login_disabled = $this->getCapability('LOGINDISABLED');
			if(($key = array_search('LOGIN', $auth_methods)) !== false) {
				if($login_disabled) {
					unset($auth_methods[$key]);
				}
			}
			else if(!$login_disabled) {
				$auth_methods[] = 'LOGIN';
			}

			// Use best (for security) supported authentication method
			foreach(array('DIGEST-MD5', 'CRAM-MD5', 'CRAM_MD5', 'PLAIN', 'LOGIN') as $auth_method) {
				if(in_array($auth_method, $auth_methods)) {
					break;
				}
			}
		}
		else {
			// Prevent from sending credentials in plain text when connection is not secure
			if($auth_method == 'LOGIN' && $this->getCapability('LOGINDISABLED'))
			{
				$this->setError(self::ERROR_BAD, "Login disabled by IMAP server");
				$this->closeConnection();
				return false;
			}
			// replace AUTH with CRAM-MD5 for backward compat.
			if($auth_method == 'AUTH') {
				$auth_method = 'CRAM-MD5';
			}
		}

		// pre-login capabilities can be not complete
		$this->capability_readed = false;

		// Authenticate
		switch($auth_method)
		{
			case 'CRAM_MD5':
				$auth_method = 'CRAM-MD5';
			case 'CRAM-MD5':
			case 'DIGEST-MD5':
			case 'PLAIN':
				$result = $this->authenticate($user, $password, $auth_method);
			break;

			case 'LOGIN':
				$result = $this->login($user, $password);
			break;

			default:
				$this->setError(self::ERROR_BAD, "Configuration error. Unknown auth method: $auth_method");
			break;
		}

		// Connected and authenticated
		if(is_resource($result))
		{
			if($this->prefs['force_caps'])
			{
				$this->clearCapability();
			}

			$this->logged = true;

			return true;
		}

		$this->closeConnection();

		return false;
	}

	function connected()
	{
		return ($this->fp && $this->logged); // ? true : false
	}

	function closeConnection()
	{
		if($this->putLine($this->nextTag() . ' LOGOUT'))
		{
			$this->readReply();
		}

		$this->closeSocket();
	}

	/**
	 * Executes SELECT command (if mailbox is already not in selected state)
	 *
	 * @param string $mailbox	  Mailbox name
	 * @param array  $qresync_data QRESYNC data (RFC5162)
	 *
	 * @return boolean True on success, false on error
	 */
	function select($mailbox, $qresync_data = null)
	{
		if(!strlen($mailbox))
		{
			return false;
		}

		if($this->selected === $mailbox)
		{
			return true;
		}
/*
	Temporary commented out because Courier returns \Noselect for INBOX
	Requires more investigation

		if(is_array($this->data['LIST']) && is_array($opts = $this->data['LIST'][$mailbox]))
		{
			if(in_array('\\Noselect', $opts))
			{
				return false;
			}
		}
*/
		$params = array($this->escape($mailbox));

		// QRESYNC data items
		//	0. the last known UIDVALIDITY,
		//	1. the last known modification sequence,
		//	2. the optional set of known UIDs, and
		//	3. an optional parenthesized list of known sequence ranges and their
		//	   corresponding UIDs.
		if(!empty($qresync_data))
		{
			if(!empty($qresync_data[2]))
			{
				$qresync_data[2] = self::compressMessageSet($qresync_data[2]);
			}

			$params[] = array('QRESYNC', $qresync_data);
		}

		list($code, $response) = $this->execute('SELECT', $params);

		if($code == self::ERROR_OK)
		{
			$response = explode("\r\n", $response);
			foreach($response as $line) {
				if(preg_match('/^\* ([0-9]+) (EXISTS|RECENT)$/i', $line, $m)) {
					$this->data[strtoupper($m[2])] = (int) $m[1];
				}
				else if(preg_match('/^\* OK \[/i', $line, $match)) {
					$line = substr($line, 6);
					if(preg_match('/^(UIDNEXT|UIDVALIDITY|UNSEEN) ([0-9]+)/i', $line, $match)) {
						$this->data[strtoupper($match[1])] = (int) $match[2];
					}
					else if(preg_match('/^(HIGHESTMODSEQ) ([0-9]+)/i', $line, $match)) {
						$this->data[strtoupper($match[1])] = (string) $match[2];
					}
					else if(preg_match('/^(NOMODSEQ)/i', $line, $match)) {
						$this->data[strtoupper($match[1])] = true;
					}
					else if(preg_match('/^PERMANENTFLAGS \(([^\)]+)\)/iU', $line, $match)) {
						$this->data['PERMANENTFLAGS'] = explode(' ', $match[1]);
					}
				}
				// QRESYNC FETCH response (RFC5162)
				else if(preg_match('/^\* ([0-9+]) FETCH/i', $line, $match)) {
					$line	   = substr($line, strlen($match[0]));
					$fetch_data = $this->tokenizeResponse($line, 1);
					$data	   = array('id' => $match[1]);

					for($i=0, $size=count($fetch_data); $i<$size; $i+=2) {
						$data[strtolower($fetch_data[$i])] = $fetch_data[$i+1];
					}

					$this->data['QRESYNC'][$data['uid']] = $data;
				}
				// QRESYNC VANISHED response (RFC5162)
				else if(preg_match('/^\* VANISHED [()EARLIER]*/i', $line, $match)) {
					$line   = substr($line, strlen($match[0]));
					$v_data = $this->tokenizeResponse($line, 1);

					$this->data['VANISHED'] = $v_data;
				}
			}

			$this->data['READ-WRITE'] = $this->resultcode != 'READ-ONLY';

			$this->selected = $mailbox;
			return true;
		}

		return false;
	}

	/**
	 * Executes STATUS command
	 *
	 * @param string $mailbox Mailbox name
	 * @param array  $items   Additional requested item names. By default
	 *						MESSAGES and UNSEEN are requested. Other defined
	 *						in RFC3501: UIDNEXT, UIDVALIDITY, RECENT
	 *
	 * @return array Status item-value hash
	 * @since 0.5-beta
	 */
	function status($mailbox, $items=[])
	{
		if(!strlen($mailbox)) {
			return false;
		}

		if(!in_array('MESSAGES', $items)) {
			$items[] = 'MESSAGES';
		}
		if(!in_array('UNSEEN', $items)) {
			$items[] = 'UNSEEN';
		}

		list($code, $response) = $this->execute('STATUS', array($this->escape($mailbox),
			'(' . implode(' ', (array) $items) . ')'));

		if($code == self::ERROR_OK && preg_match('/\* STATUS /i', $response)) {
			$result   = [];
			$response = substr($response, 9); // remove prefix "* STATUS "

			list($mbox, $items) = $this->tokenizeResponse($response, 2);

			// Fix for #1487859. Some buggy server returns not quoted
			// folder name with spaces. Let's try to handle this situation
			if(!is_array($items) && ($pos = strpos($response, '(')) !== false) {
				$response = substr($response, $pos);
				$items = $this->tokenizeResponse($response, 1);
				if(!is_array($items)) {
					return $result;
				}
			}

			for($i=0, $len=count($items); $i<$len; $i += 2) {
				$result[$items[$i]] = $items[$i+1];
			}

			$this->data['STATUS:'.$mailbox] = $result;

			return $result;
		}

		return false;
	}

	/**
	 * Executes EXPUNGE command
	 *
	 * @param string $mailbox  Mailbox name
	 * @param string $messages Message UIDs to expunge
	 *
	 * @return boolean True on success, False on error
	 */
	function expunge($mailbox, $messages=NULL)
	{
		if(!$this->select($mailbox)) {
			return false;
		}

		if(!$this->data['READ-WRITE']) {
			$this->setError(self::ERROR_READONLY, "Mailbox is read-only", 'EXPUNGE');
			return false;
		}

		// Clear internal status cache
		unset($this->data['STATUS:'.$mailbox]);

		if($messages)
			$result = $this->execute('UID EXPUNGE', array($messages), self::COMMAND_NORESPONSE);
		else
			$result = $this->execute('EXPUNGE', null, self::COMMAND_NORESPONSE);

		if($result == self::ERROR_OK) {
			$this->selected = null; // state has changed, need to reselect
			return true;
		}

		return false;
	}

	/**
	 * Executes CLOSE command
	 *
	 * @return boolean True on success, False on error
	 * @since 0.5
	 */
	function close()
	{
		$result = $this->execute('CLOSE', NULL, self::COMMAND_NORESPONSE);

		if($result == self::ERROR_OK) {
			$this->selected = null;
			return true;
		}

		return false;
	}

	/**
	 * Executes SUBSCRIBE command
	 *
	 * @param string $mailbox Mailbox name
	 *
	 * @return boolean True on success, False on error
	 */
	function subscribe($mailbox)
	{
		$result = $this->execute('SUBSCRIBE', array($this->escape($mailbox)),
			self::COMMAND_NORESPONSE);

		return ($result == self::ERROR_OK);
	}

	/**
	 * Executes UNSUBSCRIBE command
	 *
	 * @param string $mailbox Mailbox name
	 *
	 * @return boolean True on success, False on error
	 */
	function unsubscribe($mailbox)
	{
		$result = $this->execute('UNSUBSCRIBE', array($this->escape($mailbox)),
			self::COMMAND_NORESPONSE);

		return ($result == self::ERROR_OK);
	}

	/**
	 * Executes DELETE command
	 *
	 * @param string $mailbox Mailbox name
	 *
	 * @return boolean True on success, False on error
	 */
	function deleteFolder($mailbox)
	{
		$result = $this->execute('DELETE', array($this->escape($mailbox)),
			self::COMMAND_NORESPONSE);

		return ($result == self::ERROR_OK);
	}

	/**
	 * Removes all messages in a folder
	 *
	 * @param string $mailbox Mailbox name
	 *
	 * @return boolean True on success, False on error
	 */
	function clearFolder($mailbox)
	{
		$num_in_trash = $this->countMessages($mailbox);
		if($num_in_trash > 0) {
			$res = $this->delete($mailbox, '1:*');
		}

		if($res) {
			if($this->selected === $mailbox)
				$res = $this->close();
			else
				$res = $this->expunge($mailbox);
		}

		return $res;
	}

	/**
	 * Returns count of all messages in a folder
	 *
	 * @param string $mailbox Mailbox name
	 *
	 * @return int Number of messages, False on error
	 */
	function countMessages($mailbox, $refresh = false)
	{
		if($refresh) {
			$this->selected = null;
		}

		if($this->selected === $mailbox) {
			return $this->data['EXISTS'];
		}

		// Check internal cache
		$cache = isset($this->data['STATUS:'.$mailbox]) ? $this->data['STATUS:'.$mailbox] : "";

		if(!empty($cache) && isset($cache['MESSAGES'])) {
			return (int) $cache['MESSAGES'];
		}

		// Try STATUS (should be faster than SELECT)
		$counts = $this->status($mailbox);
		if(is_array($counts)) {
			return (int) $counts['MESSAGES'];
		}

		return false;
	}

	/**
	 * Returns count of messages with \Recent flag in a folder
	 *
	 * @param string $mailbox Mailbox name
	 *
	 * @return int Number of messages, False on error
	 */
	function countRecent($mailbox)
	{
		if(!strlen($mailbox)) {
			$mailbox = 'INBOX';
		}

		$this->select($mailbox);

		if($this->selected === $mailbox) {
			return $this->data['RECENT'];
		}

		return false;
	}

	/**
	 * Returns count of messages without \Seen flag in a specified folder
	 *
	 * @param string $mailbox Mailbox name
	 *
	 * @return int Number of messages, False on error
	 */
	function countUnseen($mailbox)
	{
		// Check internal cache
		$cache = $this->data['STATUS:'.$mailbox];
		if(!empty($cache) && isset($cache['UNSEEN'])) {
			return (int) $cache['UNSEEN'];
		}

		// Try STATUS (should be faster than SELECT+SEARCH)
		$counts = $this->status($mailbox);
		if(is_array($counts)) {
			return (int) $counts['UNSEEN'];
		}

		// Invoke SEARCH as a fallback
		$index = $this->search($mailbox, 'ALL UNSEEN', false, array('COUNT'));
		if(is_array($index)) {
			return (int) $index['COUNT'];
		}

		return false;
	}

	/**
	 * Executes ID command (RFC2971)
	 *
	 * @param array $items Client identification information key/value hash
	 *
	 * @return array Server identification information key/value hash
	 * @since 0.6
	 */
	function id($items=[])
	{
		if(is_array($items) && !empty($items)) {
			foreach($items as $key => $value) {
				$args[] = $this->escape($key, true);
				$args[] = $this->escape($value, true);
			}
		}

		list($code, $response) = $this->execute('ID', array(
			!empty($args) ? '(' . implode(' ', (array) $args) . ')' : $this->escape(null)
		));


		if($code == self::ERROR_OK && preg_match('/\* ID /i', $response)) {
			$response = substr($response, 5); // remove prefix "* ID "
			$items	= $this->tokenizeResponse($response, 1);
			$result   = null;

			for($i=0, $len=count($items); $i<$len; $i += 2) {
				$result[$items[$i]] = $items[$i+1];
			}

			return $result;
		}

		return false;
	}

	/**
	 * Executes ENABLE command (RFC5161)
	 *
	 * @param mixed $extension Extension name to enable (or array of names)
	 *
	 * @return array|bool List of enabled extensions, False on error
	 * @since 0.6
	 */
	function enable($extension)
	{
		if(empty($extension))
			return false;

		if(!$this->hasCapability('ENABLE'))
			return false;

		if(!is_array($extension))
			$extension = array($extension);

		list($code, $response) = $this->execute('ENABLE', $extension);

		if($code == self::ERROR_OK && preg_match('/\* ENABLED /i', $response)) {
			$response = substr($response, 10); // remove prefix "* ENABLED "
			$result   = (array) $this->tokenizeResponse($response);

			return $result;
		}

		return false;
	}

	function sort($mailbox, $field, $add='', $is_uid=FALSE, $encoding = 'US-ASCII')
	{
		$field = strtoupper($field);
		if($field == 'INTERNALDATE') {
			$field = 'ARRIVAL';
		}

		$fields = array('ARRIVAL' => 1,'CC' => 1,'DATE' => 1,
			'FROM' => 1, 'SIZE' => 1, 'SUBJECT' => 1, 'TO' => 1);

		if(!$fields[$field]) {
			return false;
		}

		if(!$this->select($mailbox)) {
			return false;
		}

		// message IDs
		if(!empty($add))
			$add = $this->compressMessageSet($add);

		list($code, $response) = $this->execute($is_uid ? 'UID SORT' : 'SORT',
			array("($field)", $encoding, 'ALL' . (!empty($add) ? ' '.$add : '')));

		if($code == self::ERROR_OK) {
			// remove prefix and unilateral untagged server responses
			$response = substr($response, stripos($response, '* SORT') + 7);
			if($pos = strpos($response, '*')) {
				$response = substr($response, 0, $pos);
			}
			return preg_split('/[\s\r\n]+/', $response, -1, PREG_SPLIT_NO_EMPTY);
		}

		return false;
	}

	function fetchHeaderIndex($mailbox, $message_set, $index_field='', $skip_deleted=true, $uidfetch=false)
	{
		if(is_array($message_set)) {
			if(!($message_set = $this->compressMessageSet($message_set)))
				return false;
		} else {
			list($from_idx, $to_idx) = explode(':', $message_set);
			if(empty($message_set) ||
				(isset($to_idx) && $to_idx != '*' && (int)$from_idx > (int)$to_idx)) {
				return false;
			}
		}

		$index_field = empty($index_field) ? 'DATE' : strtoupper($index_field);

		$fields_a['DATE']		 = 1;
		$fields_a['INTERNALDATE'] = 4;
		$fields_a['ARRIVAL']	  = 4;
		$fields_a['FROM']		 = 1;
		$fields_a['REPLY-TO']	 = 1;
		$fields_a['SENDER']	   = 1;
		$fields_a['TO']		   = 1;
		$fields_a['CC']		   = 1;
		$fields_a['SUBJECT']	  = 1;
		$fields_a['UID']		  = 2;
		$fields_a['SIZE']		 = 2;
		$fields_a['SEEN']		 = 3;
		$fields_a['RECENT']	   = 3;
		$fields_a['DELETED']	  = 3;

		if(!($mode = $fields_a[$index_field])) {
			return false;
		}

		/*  Do "SELECT" command */
		if(!$this->select($mailbox)) {
			return false;
		}

		// build FETCH command string
		$key	 = $this->nextTag();
		$cmd	 = $uidfetch ? 'UID FETCH' : 'FETCH';
		$deleted = $skip_deleted ? ' FLAGS' : '';

		if($mode == 1 && $index_field == 'DATE')
			$request = " $cmd $message_set (INTERNALDATE BODY.PEEK[HEADER.FIELDS (DATE)]$deleted)";
		else if($mode == 1)
			$request = " $cmd $message_set (BODY.PEEK[HEADER.FIELDS ($index_field)]$deleted)";
		else if($mode == 2) {
			if($index_field == 'SIZE')
				$request = " $cmd $message_set (RFC822.SIZE$deleted)";
			else
				$request = " $cmd $message_set ($index_field$deleted)";
		} else if($mode == 3)
			$request = " $cmd $message_set (FLAGS)";
		else // 4
			$request = " $cmd $message_set (INTERNALDATE$deleted)";

		$request = $key . $request;

		if(!$this->putLine($request)) {
			$this->setError(self::ERROR_COMMAND, "Unable to send command: $request");
			return false;
		}

		$result = [];

		do {
			$line = rtrim($this->readLine(200));
			$line = $this->multLine($line);

			if(preg_match('/^\* ([0-9]+) FETCH/', $line, $m)) {
				$id	 = $m[1];
				$flags  = NULL;

				if($skip_deleted && preg_match('/FLAGS \(([^)]+)\)/', $line, $matches)) {
					$flags = explode(' ', strtoupper($matches[1]));
					if(in_array('\\DELETED', $flags)) {
						$deleted[$id] = $id;
						continue;
					}
				}

				if($mode == 1 && $index_field == 'DATE') {
					if(preg_match('/BODY\[HEADER\.FIELDS \("*DATE"*\)\] (.*)/', $line, $matches)) {
						$value = preg_replace(array('/^"*[a-z]+:/i'), '', $matches[1]);
						$value = trim($value);
						$result[$id] = $this->strToTime($value);
					}
					// non-existent/empty Date: header, use INTERNALDATE
					if(empty($result[$id])) {
						if(preg_match('/INTERNALDATE "([^"]+)"/', $line, $matches))
							$result[$id] = $this->strToTime($matches[1]);
						else
							$result[$id] = 0;
					}
				} else if($mode == 1) {
					if(preg_match('/BODY\[HEADER\.FIELDS \("?(FROM|REPLY-TO|SENDER|TO|SUBJECT)"?\)\] (.*)/', $line, $matches)) {
						$value = preg_replace(array('/^"*[a-z]+:/i', '/\s+$/sm'), array('', ''), $matches[2]);
						$result[$id] = trim($value);
					} else {
						$result[$id] = '';
					}
				} else if($mode == 2) {
					if(preg_match('/(UID|RFC822\.SIZE) ([0-9]+)/', $line, $matches)) {
						$result[$id] = trim($matches[2]);
					} else {
						$result[$id] = 0;
					}
				} else if($mode == 3) {
					if(!$flags && preg_match('/FLAGS \(([^)]+)\)/', $line, $matches)) {
						$flags = explode(' ', $matches[1]);
					}
					$result[$id] = in_array('\\'.$index_field, $flags) ? 1 : 0;
				} else if($mode == 4) {
					if(preg_match('/INTERNALDATE "([^"]+)"/', $line, $matches)) {
						$result[$id] = $this->strToTime($matches[1]);
					} else {
						$result[$id] = 0;
					}
				}
			}
		} while(!$this->startsWith($line, $key, true, true));

		return $result;
	}

	static function compressMessageSet($messages, $force=false)
	{
		// given a comma delimited list of independent mid's,
		// compresses by grouping sequences together

		if(!is_array($messages)) {
			// if less than 255 bytes long, let's not bother
			if(!$force && strlen($messages)<255) {
				return $messages;
		   }

			// see if it's already been compressed
			if(strpos($messages, ':') !== false) {
				return $messages;
			}

			// separate, then sort
			$messages = explode(',', $messages);
		}

		sort($messages);

		$result = [];
		$start  = $prev = $messages[0];

		foreach($messages as $id) {
			$incr = $id - $prev;
			if($incr > 1) { // found a gap
				if($start == $prev) {
					$result[] = $prev; // push single id
				} else {
					$result[] = $start . ':' . $prev; // push sequence as start_id:end_id
				}
				$start = $id; // start of new sequence
			}
			$prev = $id;
		}

		// handle the last sequence/id
		if($start == $prev) {
			$result[] = $prev;
		} else {
			$result[] = $start.':'.$prev;
		}

		// return as comma separated string
		return implode(',', $result);
	}

	static function uncompressMessageSet($messages)
	{
		$result   = [];
		$messages = explode(',', $messages);

		foreach($messages as $part) {
			$items = explode(':', $part);
			$max   = max($items[0], $items[1]);

			for($x=$items[0]; $x<=$max; $x++) {
				$result[] = $x;
			}
		}

		return $result;
	}

	/**
	 * Returns message sequence identifier
	 *
	 * @param string $mailbox Mailbox name
	 * @param int	$uid	 Message unique identifier (UID)
	 *
	 * @return int Message sequence identifier
	 */
	function UID2ID($mailbox, $uid)
	{
		if($uid > 0) {
			$id_a = $this->search($mailbox, "UID $uid");
			if(is_array($id_a) && count($id_a) == 1) {
				return (int) $id_a[0];
			}
		}
		return null;
	}

	/**
	 * Returns message unique identifier (UID)
	 *
	 * @param string $mailbox Mailbox name
	 * @param int	$uid	 Message sequence identifier
	 *
	 * @return int Message unique identifier
	 */
	function ID2UID($mailbox, $id)
	{
		if(empty($id) || $id < 0) {
			return null;
		}

		if(!$this->select($mailbox)) {
			return null;
		}

		list($code, $response) = $this->execute('FETCH', array($id, '(UID)'));

		if($code == self::ERROR_OK && preg_match("/^\* $id FETCH \(UID (.*)\)/i", $response, $m)) {
			return (int) $m[1];
		}

		return null;
	}

	function fetchUIDs($mailbox, $message_set=null)
	{
		if(empty($message_set))
			$message_set = '1:*';

		return $this->fetchHeaderIndex($mailbox, $message_set, 'UID', false);
	}

	/**
	 * FETCH command (RFC3501)
	 *
	 * @param string $mailbox	 Mailbox name
	 * @param mixed  $message_set Message(s) sequence identifier(s) or UID(s)
	 * @param bool   $is_uid	  True if $message_set contains UIDs
	 * @param array  $query_items FETCH command data items
	 * @param string $mod_seq	 Modification sequence for CHANGEDSINCE (RFC4551) query
	 * @param bool   $vanished	Enables VANISHED parameter (RFC5162) for CHANGEDSINCE query
	 *
	 * @return array List of rcube_mail_header elements, False on error
	 * @since 0.6
	 */
	function fetch($mailbox, $message_set, $is_uid = false, $query_items = [],
		$mod_seq = null, $vanished = false)
	{
		if(!$this->select($mailbox)) {
			return false;
		}

		$message_set = $this->compressMessageSet($message_set);
		$result	  = [];

		$key	  = $this->nextTag();
		$request  = $key . ($is_uid ? ' UID' : '') . " FETCH $message_set ";
		$request .= "(" . implode(' ', $query_items) . ")";

		if($mod_seq !== null && $this->hasCapability('CONDSTORE')) {
			$request .= " (CHANGEDSINCE $mod_seq" . ($vanished ? " VANISHED" : '') .")";
		}

		if(!$this->putLine($request)) {
			$this->setError(self::ERROR_COMMAND, "Unable to send command: $request");
			return false;
		}

		do {
			$line = $this->readLine(4096);

			if(!$line)
				break;

			/*if(preg_match('/Message\-ID\: (.*)/', $line, $msfd))
			{
				$message_id_raw = $line;
			}*/

			// Sample reply line:
			// * 321 FETCH (UID 2417 RFC822.SIZE 2730 FLAGS (\Seen)
			// INTERNALDATE "16-Nov-2008 21:08:46 +0100" BODYSTRUCTURE (...)
			// BODY[HEADER.FIELDS ...

			if(preg_match('/^\* ([0-9]+) FETCH/', $line, $m)) {
				$id = intval($m[1]);

				$result[$id]			= new rcube_mail_header;
				$result[$id]->id		= $id;
				$result[$id]->subject   = '';
				$result[$id]->messageID = 'mid:' . $id; //." (".$message_id_raw.")"

				$lines = [];
				$line  = substr($line, strlen($m[0]) + 2);
				$ln	= 0;

				// get complete entry
				while(preg_match('/\{([0-9]+)\}\r\n$/', $line, $m)) {
					$bytes = $m[1];
					$out   = '';

					while(strlen($out) < $bytes) {
						$out = $this->readBytes($bytes);
						if($out === NULL)
							break;
						$line .= $out;
					}

					$str = $this->readLine(4096);
					if($str === false)
						break;

					$line .= $str;
				}

				// Tokenize response and assign to object properties
				while(@list($name, $value) = $this->tokenizeResponse($line, 2)) {
					if($name == 'UID') {
						$result[$id]->uid = intval($value);
					}
					else if($name == 'RFC822.SIZE') {
						$result[$id]->size = intval($value);
					}
					else if($name == 'RFC822.TEXT') {
						$result[$id]->body = $value;
					}
					else if($name == 'INTERNALDATE') {
						$result[$id]->internaldate = $value;
						$result[$id]->date		 = $value;
						$result[$id]->timestamp	= $this->StrToTime($value);
					}
					else if($name == 'FLAGS') {
						if(!empty($value)) {
							foreach((array)$value as $flag) {
								$flag = str_replace(array('$', '\\'), '', $flag);
								$flag = strtoupper($flag);

								$result[$id]->flags[$flag] = true;
							}
						}
					}
					else if($name == 'MODSEQ') {
						$result[$id]->modseq = $value[0];
					}
					else if($name == 'ENVELOPE') {
						$result[$id]->envelope = $value;
					}
					else if($name == 'BODYSTRUCTURE' || ($name == 'BODY' && count($value) > 2)) {
						if(!is_array($value[0]) && (strtolower($value[0]) == 'message' && strtolower($value[1]) == 'rfc822')) {
							$value = array($value);
						}
						$result[$id]->bodystructure = $value;
					}
					else if($name == 'RFC822') {
						$result[$id]->body = $value;
					}
					else if($name == 'BODY') {
						$body = $this->tokenizeResponse($line, 1);
						if($value[0] == 'HEADER.FIELDS')
							$headers = $body;
						else if(!empty($value))
							$result[$id]->bodypart[$value[0]] = $body;
						else
							$result[$id]->body = $body;
					}
				}

				// create array with header field:data
				if(!empty($headers))
				{
					$headers = explode("\n", trim($headers));

					foreach($headers as $hid => $resln)
					{
						if(ord($resln[0]) <= 32)
						{
							$lines[$ln] .= (empty($lines[$ln]) ? '' : "\n") . trim($resln);
						}

						else
						{
							$lines[++$ln] = trim($resln);
						}
					}

					//while(list($lines_key, $str) = each($lines))
					foreach($lines as $lines_key => $str)
					{
						list($field, $string) = explode(':', $str, 2);

						$field  = strtolower($field);
						$string = preg_replace('/\n[\t\s]*/', ' ', trim($string));

						switch($field)
						{
							case 'date';
								$result[$id]->date = $string;
								$result[$id]->timestamp = $this->strToTime($string);
							break;

							case 'from':
								$result[$id]->from = $string;
							break;

							case 'to':
								$result[$id]->to = preg_replace('/undisclosed-recipients:[;,]*/', '', $string);
							break;

							case 'subject':
								$result[$id]->subject = $string;
							break;

							case 'reply-to':
								$result[$id]->replyto = $string;
							break;

							case 'cc':
								$result[$id]->cc = $string;
							break;

							case 'bcc':
								$result[$id]->bcc = $string;
							break;

							case 'content-transfer-encoding':
								$result[$id]->encoding = $string;
							break;

							case 'content-type':
								$ctype_parts = preg_split('/[; ]/', $string);
								$result[$id]->ctype = strtolower(array_shift($ctype_parts));
								if(preg_match('/charset\s*=\s*"?([a-z0-9\-\.\_]+)"?/i', $string, $regs)) {
									$result[$id]->charset = $regs[1];
								}
							break;

							case 'in-reply-to':
								$result[$id]->in_reply_to = str_replace(array("\n", '<', '>'), '', $string);
							break;

							case 'references':
								$result[$id]->references = $string;
							break;

							case 'return-receipt-to':
							case 'disposition-notification-to':
							case 'x-confirm-reading-to':
								$result[$id]->mdn_to = $string;
							break;

							case 'message-id':
								$result[$id]->messageID = $string;
							break;

							case 'x-priority':
								if(preg_match('/^(\d+)/', $string, $matches)) {
									$result[$id]->priority = intval($matches[1]);
								}
							break;

							default:
								if(strlen($field) > 2) {
									$result[$id]->others[$field] = $string;
								}
							break;
						}
					}
				}
			}

			// VANISHED response (QRESYNC RFC5162)
			// Sample: * VANISHED (EARLIER) 300:310,405,411

			else if(preg_match('/^\* VANISHED [()EARLIER]*/i', $line, $match)) {
				$line   = substr($line, strlen($match[0]));
				$v_data = $this->tokenizeResponse($line, 1);

				$this->data['VANISHED'] = $v_data;
			}

		} while(!$this->startsWith($line, $key, true));

		return $result;
	}

	function fetchHeaders($mailbox, $message_set, $is_uid = false, $bodystr = false, $add = '')
	{
		$query_items = array('UID', 'RFC822.SIZE', 'FLAGS', 'INTERNALDATE');
		if($bodystr)
			$query_items[] = 'BODYSTRUCTURE';
		$query_items[] = 'BODY.PEEK[HEADER.FIELDS ('
			. 'DATE FROM TO SUBJECT CONTENT-TYPE CC REPLY-TO LIST-POST DISPOSITION-NOTIFICATION-TO X-PRIORITY'
			. ($add ? ' ' . trim($add) : '')
			. ')]';

		$result = $this->fetch($mailbox, $message_set, $is_uid, $query_items);

		return $result;
	}

	function fetchHeader($mailbox, $id, $uidfetch=false, $bodystr=false, $add='')
	{
		$a = $this->fetchHeaders($mailbox, $id, $uidfetch, $bodystr, $add);
		if(is_array($a)) {
			return array_shift($a);
		}
		return false;
	}

	function sortHeaders($a, $field, $flag)
	{
		if(empty($field)) {
			$field = 'uid';
		}
		else {
			$field = strtolower($field);
		}

		if($field == 'date' || $field == 'internaldate') {
			$field = 'timestamp';
		}

		if(empty($flag)) {
			$flag = 'ASC';
		} else {
			$flag = strtoupper($flag);
		}

		$c = count($a);
		if($c > 0) {
			// Strategy:
			// First, we'll create an "index" array.
			// Then, we'll use sort() on that array,
			// and use that to sort the main array.

			// create "index" array
			$index = [];
			reset($a);
			while(list($key, $val) = each($a)) {
				if($field == 'timestamp') {
					$data = $this->strToTime($val->date);
					if(!$data) {
						$data = $val->timestamp;
					}
				} else {
					$data = $val->$field;
					if(is_string($data)) {
						$data = str_replace('"', '', $data);
						if($field == 'subject') {
							$data = preg_replace('/^(Re: \s*|Fwd:\s*|Fw:\s*)+/i', '', $data);
						}
						$data = strtoupper($data);
					}
				}
				$index[$key] = $data;
			}

			// sort index
			if($flag == 'ASC') {
				asort($index);
			} else {
				arsort($index);
			}

			// form new array based on index
			$result = [];
			reset($index);
			while(list($key, $val) = each($index)) {
				$result[$key] = $a[$key];
			}
		}

		return $result;
	}


	function modFlag($mailbox, $messages, $flag, $mod)
	{
		if($mod != '+' && $mod != '-') {
			$mod = '+';
		}

		if(!$this->select($mailbox)) {
			return false;
		}

		if(!$this->data['READ-WRITE']) {
			$this->setError(self::ERROR_READONLY, "Mailbox is read-only", 'STORE');
			return false;
		}

		// Clear internal status cache
		if($flag == 'SEEN') {
			unset($this->data['STATUS:'.$mailbox]['UNSEEN']);
		}

		$flag   = $this->flags[strtoupper($flag)];
		$result = $this->execute('UID STORE', array(
			$this->compressMessageSet($messages), $mod . 'FLAGS.SILENT', "($flag)"),
			self::COMMAND_NORESPONSE);

		return ($result == self::ERROR_OK);
	}

	function flag($mailbox, $messages, $flag) {
		return $this->modFlag($mailbox, $messages, $flag, '+');
	}

	function unflag($mailbox, $messages, $flag) {
		return $this->modFlag($mailbox, $messages, $flag, '-');
	}

	function delete($mailbox, $messages) {
		return $this->modFlag($mailbox, $messages, 'DELETED', '+');
	}

	function copy($messages, $from, $to)
	{
		if(!$this->select($from)) {
			return false;
		}

		// Clear internal status cache
		unset($this->data['STATUS:'.$to]);

		$result = $this->execute('UID COPY', array(
			$this->compressMessageSet($messages), $this->escape($to)),
			self::COMMAND_NORESPONSE);

		return ($result == self::ERROR_OK);
	}

	function move($messages, $from, $to)
	{
		if(!$this->select($from)) {
			return false;
		}

		if(!$this->data['READ-WRITE']) {
			$this->setError(self::ERROR_READONLY, "Mailbox is read-only", 'STORE');
			return false;
		}

		$r = $this->copy($messages, $from, $to);

		if($r) {
			// Clear internal status cache
			unset($this->data['STATUS:'.$from]);

			return $this->delete($from, $messages);
		}
		return $r;
	}

	// Don't be tempted to change $str to pass by reference to speed this up - it will slow it down by about
	// 7 times instead :-) See comments on http://uk2.php.net/references and this article:
	// http://derickrethans.nl/files/phparch-php-variables-article.pdf
	private function parseThread($str, $begin, $end, $root, $parent, $depth, &$depthmap, &$haschildren)
	{
		$node = [];
		if($str[$begin] != '(') {
			$stop = $begin + strspn($str, '1234567890', $begin, $end - $begin);
			$msg = substr($str, $begin, $stop - $begin);
			if($msg == 0)
				return $node;
			if(is_null($root))
				$root = $msg;
			$depthmap[$msg] = $depth;
			$haschildren[$msg] = false;
			if(!is_null($parent))
				$haschildren[$parent] = true;
			if($stop + 1 < $end)
				$node[$msg] = $this->parseThread($str, $stop + 1, $end, $root, $msg, $depth + 1, $depthmap, $haschildren);
			else
				$node[$msg] = [];
		} else {
			$off = $begin;
			while($off < $end) {
				$start = $off;
				$off++;
				$n = 1;
				while($n > 0) {
					$p = strpos($str, ')', $off);
					if($p === false) {
						error_log("Mismatched brackets parsing IMAP THREAD response:");
						error_log(substr($str, ($begin < 10) ? 0 : ($begin - 10), $end - $begin + 20));
						error_log(str_repeat(' ', $off - (($begin < 10) ? 0 : ($begin - 10))));
						return $node;
					}
					$p1 = strpos($str, '(', $off);
					if($p1 !== false && $p1 < $p) {
						$off = $p1 + 1;
						$n++;
					} else {
						$off = $p + 1;
						$n--;
					}
				}
				$node += $this->parseThread($str, $start + 1, $off - 1, $root, $parent, $depth, $depthmap, $haschildren);
			}
		}

		return $node;
	}

	function thread($mailbox, $algorithm='REFERENCES', $criteria='', $encoding='US-ASCII')
	{
		$old_sel = $this->selected;

		if(!$this->select($mailbox)) {
			return false;
		}

		// return empty result when folder is empty and we're just after SELECT
		if($old_sel != $mailbox && !$this->data['EXISTS']) {
			return array([], [], []);
		}

		$encoding  = $encoding ? trim($encoding) : 'US-ASCII';
		$algorithm = $algorithm ? trim($algorithm) : 'REFERENCES';
		$criteria  = $criteria ? 'ALL '.trim($criteria) : 'ALL';
		$data	  = '';

		list($code, $response) = $this->execute('THREAD', array(
			$algorithm, $encoding, $criteria));

		if($code == self::ERROR_OK) {
			// remove prefix...
			$response = substr($response, stripos($response, '* THREAD') + 9);
			// ...unilateral untagged server responses
			if($pos = strpos($response, '*')) {
				$response = substr($response, 0, $pos);
			}

			$response	= str_replace("\r\n", '', $response);
			$depthmap	= [];
			$haschildren = [];

			$tree = $this->parseThread($response, 0, strlen($response),
				null, null, 0, $depthmap, $haschildren);

			return array($tree, $depthmap, $haschildren);
		}

		return false;
	}

	/**
	 * Executes SEARCH command
	 *
	 * @param string $mailbox	Mailbox name
	 * @param string $criteria   Searching criteria
	 * @param bool   $return_uid Enable UID in result instead of sequence ID
	 * @param array  $items	  Return items (MIN, MAX, COUNT, ALL)
	 *
	 * @return array Message identifiers or item-value hash
	 */
	function search($mailbox, $criteria, $return_uid=false, $items=[])
	{
		$old_sel = $this->selected;

		if(!$this->select($mailbox)) {
			return false;
		}

		// return empty result when folder is empty and we're just after SELECT
		if($old_sel != $mailbox && !$this->data['EXISTS']) {
			if(!empty($items))
				return array_combine($items, array_fill(0, count($items), 0));
			else
				return [];
		}

		$esearch  = empty($items) ? false : $this->getCapability('ESEARCH');
		$criteria = trim($criteria);
		$params   = '';

		// RFC4731: ESEARCH
		if(!empty($items) && $esearch) {
			$params .= 'RETURN (' . implode(' ', $items) . ')';
		}
		if(!empty($criteria)) {
			$modseq = stripos($criteria, 'MODSEQ') !== false;
			$params .= ($params ? ' ' : '') . $criteria;
		}
		else {
			$params .= 'ALL';
		}

		list($code, $response) = $this->execute($return_uid ? 'UID SEARCH' : 'SEARCH',
			array($params));

		if($code == self::ERROR_OK) {
			// remove prefix...
			$response = substr($response, stripos($response,
				$esearch ? '* ESEARCH' : '* SEARCH') + ($esearch ? 10 : 9));
			// ...and unilateral untagged server responses
			if($pos = strpos($response, '*')) {
				$response = rtrim(substr($response, 0, $pos));
			}

			// remove MODSEQ response
			if($modseq) {
				if(preg_match('/\(MODSEQ ([0-9]+)\)$/', $response, $m)) {
					$response = substr($response, 0, -strlen($m[0]));
				}
			}

			if($esearch) {
				// Skip prefix: ... (TAG "A285") UID ...
				$this->tokenizeResponse($response, $return_uid ? 2 : 1);

				$result = [];
				for($i=0; $i<count($items); $i++) {
					// If the SEARCH returns no matches, the server MUST NOT
					// include the item result option in the ESEARCH response
					if($ret = $this->tokenizeResponse($response, 2)) {
						list ($name, $value) = $ret;
						$result[$name] = $value;
					}
				}

				return $result;
			}
			else {
				$response = preg_split('/[\s\r\n]+/', $response, -1, PREG_SPLIT_NO_EMPTY);

				if(!empty($items)) {
					$result = [];
					if(in_array('COUNT', $items)) {
						$result['COUNT'] = count($response);
					}
					if(in_array('MIN', $items)) {
						$result['MIN'] = !empty($response) ? min($response) : 0;
					}
					if(in_array('MAX', $items)) {
						$result['MAX'] = !empty($response) ? max($response) : 0;
					}
					if(in_array('ALL', $items)) {
						$result['ALL'] = $this->compressMessageSet($response, true);
					}

					return $result;
				}
				else {
					return $response;
				}
			}
		}

		return false;
	}

	/**
	 * Returns list of mailboxes
	 *
	 * @param string $ref		 Reference name
	 * @param string $mailbox	 Mailbox name
	 * @param array  $status_opts (see self::_listMailboxes)
	 * @param array  $select_opts (see self::_listMailboxes)
	 *
	 * @return array List of mailboxes or hash of options if $status_opts argument
	 *			   is non-empty.
	 */
	function listMailboxes($ref, $mailbox, $status_opts=[], $select_opts=[])
	{
		return $this->_listMailboxes($ref, $mailbox, false, $status_opts, $select_opts);
	}

	/**
	 * Returns list of subscribed mailboxes
	 *
	 * @param string $ref		 Reference name
	 * @param string $mailbox	 Mailbox name
	 * @param array  $status_opts (see self::_listMailboxes)
	 *
	 * @return array List of mailboxes or hash of options if $status_opts argument
	 *			   is non-empty.
	 */
	function listSubscribed($ref, $mailbox, $status_opts=[])
	{
		return $this->_listMailboxes($ref, $mailbox, true, $status_opts, NULL);
	}

	/**
	 * IMAP LIST/LSUB command
	 *
	 * @param string $ref		 Reference name
	 * @param string $mailbox	 Mailbox name
	 * @param bool   $subscribed  Enables returning subscribed mailboxes only
	 * @param array  $status_opts List of STATUS options (RFC5819: LIST-STATUS)
	 *							Possible: MESSAGES, RECENT, UIDNEXT, UIDVALIDITY, UNSEEN
	 * @param array  $select_opts List of selection options (RFC5258: LIST-EXTENDED)
	 *							Possible: SUBSCRIBED, RECURSIVEMATCH, REMOTE
	 *
	 * @return array List of mailboxes or hash of options if $status_ops argument
	 *			   is non-empty.
	 */
	private function _listMailboxes($ref, $mailbox, $subscribed=false,
		$status_opts=[], $select_opts=[])
	{
		if(!strlen($mailbox)) {
			$mailbox = '*';
		}

		$args = [];

		if(!empty($select_opts) && $this->getCapability('LIST-EXTENDED')) {
			$select_opts = (array) $select_opts;

			$args[] = '(' . implode(' ', $select_opts) . ')';
		}

		$args[] = $this->escape($ref);
		$args[] = $this->escape($mailbox);

		if(!empty($status_opts) && $this->getCapability('LIST-STATUS')) {
			$status_opts = (array) $status_opts;
			$lstatus = true;

			$args[] = 'RETURN (STATUS (' . implode(' ', $status_opts) . '))';
		}

		list($code, $response) = $this->execute($subscribed ? 'LSUB' : 'LIST', $args);

		if($code == self::ERROR_OK) {
			$folders  = [];
			$last	 = 0;
			$pos	  = 0;
			$response .= "\r\n";

			while($pos = strpos($response, "\r\n", $pos+1)) {
				// literal string, not real end-of-command-line
				if($response[$pos-1] == '}') {
					continue;
				}

				$line = substr($response, $last, $pos - $last);
				$last = $pos + 2;

				if(!preg_match('/^\* (LIST|LSUB|STATUS) /i', $line, $m)) {
					continue;
				}
				$cmd  = strtoupper($m[1]);
				$line = substr($line, strlen($m[0]));

				// * LIST (<options>) <delimiter> <mailbox>
				if($cmd == 'LIST' || $cmd == 'LSUB') {
					list($opts, $delim, $mailbox) = $this->tokenizeResponse($line, 3);

					// Add to result array
					if(!$lstatus) {
						$folders[] = $mailbox;
					}
					else {
						$folders[$mailbox] = [];
					}

					// Add to options array
					if(empty($this->data['LIST'][$mailbox]))
						$this->data['LIST'][$mailbox] = $opts;
					else if(!empty($opts))
						$this->data['LIST'][$mailbox] = array_unique(array_merge(
							$this->data['LIST'][$mailbox], $opts));
				}
				// * STATUS <mailbox> (<result>)
				else if($cmd == 'STATUS') {
					list($mailbox, $status) = $this->tokenizeResponse($line, 2);

					for($i=0, $len=count($status); $i<$len; $i += 2) {
						list($name, $value) = $this->tokenizeResponse($status, 2);
						$folders[$mailbox][$name] = $value;
					}
				}
			}

			return $folders;
		}

		return false;
	}

	function fetchMIMEHeaders($mailbox, $uid, $parts, $mime=true)
	{
		if(!$this->select($mailbox)) {
			return false;
		}

		$result = false;
		$parts  = (array) $parts;
		$key	= $this->nextTag();
		$peeks  = [];
		$type   = $mime ? 'MIME' : 'HEADER';

		// format request
		foreach($parts as $part) {
			$peeks[] = "BODY.PEEK[$part.$type]";
		}

		$request = "$key UID FETCH $uid (" . implode(' ', $peeks) . ')';

		// send request
		if(!$this->putLine($request)) {
			$this->setError(self::ERROR_COMMAND, "Unable to send command: $request");
			return false;
		}

		do {
			$line = $this->readLine(1024);

			if(preg_match('/^\* [0-9]+ FETCH [0-9UID( ]+BODY\[([0-9\.]+)\.'.$type.'\]/', $line, $matches)) {
				$idx	 = $matches[1];
				$headers = '';

				// get complete entry
				if(preg_match('/\{([0-9]+)\}\r\n$/', $line, $m)) {
					$bytes = $m[1];
					$out   = '';

					while(strlen($out) < $bytes) {
						$out = $this->readBytes($bytes);
						if($out === null)
							break;
						$headers .= $out;
					}
				}

				if($result == false)
				{
					$result = [];
				}

				$result[$idx] = trim($headers);
			}
		} while(!$this->startsWith($line, $key, true));

		return $result;
	}

	function fetchPartHeader($mailbox, $id, $is_uid=false, $part=NULL)
	{
		$part = empty($part) ? 'HEADER' : $part.'.MIME';

		return $this->handlePartBody($mailbox, $id, $is_uid, $part);
	}

	function handlePartBody($mailbox, $id, $is_uid=false, $part='', $encoding=NULL, $print=NULL, $file=NULL)
	{
		if(!$this->select($mailbox)) {
			return false;
		}

		switch($encoding) {
		case 'base64':
			$mode = 1;
			break;
		case 'quoted-printable':
			$mode = 2;
			break;
		case 'x-uuencode':
		case 'x-uue':
		case 'uue':
		case 'uuencode':
			$mode = 3;
			break;
		default:
			$mode = 0;
		}

		// format request
		$reply_key = '* ' . $id;
		$key	   = $this->nextTag();
		$request   = $key . ($is_uid ? ' UID' : '') . " FETCH $id (BODY.PEEK[$part])";

		// send request
		if(!$this->putLine($request)) {
			$this->setError(self::ERROR_COMMAND, "Unable to send command: $request");
			return false;
		}

		// receive reply line
		do {
			$line = rtrim($this->readLine(1024));
			$a	= explode(' ', $line);
		} while(!($end = $this->startsWith($line, $key, true)) && $a[2] != 'FETCH');

		$len	= strlen($line);
		$result = false;

		if(isset($a[2]) && $a[2] != 'FETCH'){}
		// handle empty "* X FETCH ()" response
		else if(isset($line[$len-1]) && $line[$len-1] == ')' && isset($line[$len-2]) && $line[$len-2] != '(')
		{
			// one line response, get everything between first and last quotes
			if(substr($line, -4, 3) == 'NIL') {
				// NIL response
				$result = '';
			} else {
				$from = strpos($line, '"') + 1;
				$to   = strrpos($line, '"');
				$len  = $to - $from;
				$result = substr($line, $from, $len);
			}

			if($mode == 1) {
				$result = base64_decode($result);
			}
			else if($mode == 2) {
				$result = quoted_printable_decode($result);
			}
			else if($mode == 3) {
				$result = convert_uudecode($result);
			}
		}

		else if(isset($line[$len-1]) && $line[$len-1] == '}')
		{
			// multi-line request, find sizes of content and receive that many bytes
			$from	 = strpos($line, '{') + 1;
			$to	   = strrpos($line, '}');
			$len	  = $to - $from;
			$sizeStr  = substr($line, $from, $len);
			$bytes	= (int)$sizeStr;
			$prev	 = '';

			while($bytes > 0) {
				$line = $this->readLine(4096);

				if($line === NULL) {
					break;
				}

				$len  = strlen($line);

				if($len > $bytes) {
					$line = substr($line, 0, $bytes);
					$len = strlen($line);
				}
				$bytes -= $len;

				// BASE64
				if($mode == 1) {
					$line = rtrim($line, "\t\r\n\0\x0B");
					// create chunks with proper length for base64 decoding
					$line = $prev.$line;
					$length = strlen($line);
					if($length % 4) {
						$length = floor($length / 4) * 4;
						$prev = substr($line, $length);
						$line = substr($line, 0, $length);
					}
					else
						$prev = '';
					$line = base64_decode($line);
				// QUOTED-PRINTABLE
				} else if($mode == 2) {
					$line = rtrim($line, "\t\r\0\x0B");
					$line = quoted_printable_decode($line);
				// UUENCODE
				} else if($mode == 3) {
					$line = rtrim($line, "\t\r\n\0\x0B");
					if($line == 'end' || preg_match('/^begin\s+[0-7]+\s+.+$/', $line))
						continue;
					$line = convert_uudecode($line);
				// default
				} else {
					$line = rtrim($line, "\t\r\n\0\x0B") . "\n";
				}

				if($file)
					fwrite($file, $line);
				else if($print)
					echo $line;
				else
					$result .= $line;
			}
		}

		// read in anything up until last line
		if(!$end)
			do {
				$line = $this->readLine(1024);
			} while(!$this->startsWith($line, $key, true));

		if($result !== false) {
			if($file) {
				fwrite($file, $result);
			} else if($print) {
				echo $result;
			} else
				return $result;
			return true;
		}

		return false;
	}

	function createFolder($mailbox)
	{
		$result = $this->execute('CREATE', array($this->escape($mailbox)),
			self::COMMAND_NORESPONSE);

		return ($result == self::ERROR_OK);
	}

	function renameFolder($from, $to)
	{
		$result = $this->execute('RENAME', array($this->escape($from), $this->escape($to)),
			self::COMMAND_NORESPONSE);

		return ($result == self::ERROR_OK);
	}

	/**
	 * Handler for IMAP APPEND command
	 *
	 * @param string $mailbox Mailbox name
	 * @param string $message Message content
	 *
	 * @return string|bool On success APPENDUID response (if available) or True, False on failure
	 */
	function append($mailbox, &$message)
	{
		unset($this->data['APPENDUID']);

		if(!$mailbox) {
			return false;
		}

		$message = str_replace("\r", '', $message);
		$message = str_replace("\n", "\r\n", $message);

		$len = strlen($message);
		if(!$len) {
			return false;
		}

		$key = $this->nextTag();
		$request = sprintf("$key APPEND %s (\\Seen) {%d%s}", $this->escape($mailbox),
			$len, ($this->prefs['literal+'] ? '+' : ''));

		if($this->putLine($request)) {
			// Don't wait when LITERAL+ is supported
			if(!$this->prefs['literal+']) {
				$line = $this->readReply();

				if($line[0] != '+') {
					$this->parseResult($line, 'APPEND: ');
					return false;
				}
			}

			if(!$this->putLine($message)) {
				return false;
			}

			do {
				$line = $this->readLine();
			} while(!$this->startsWith($line, $key, true, true));

			// Clear internal status cache
			unset($this->data['STATUS:'.$mailbox]);

			if($this->parseResult($line, 'APPEND: ') != self::ERROR_OK)
				return false;
			else if(!empty($this->data['APPENDUID']))
				return $this->data['APPENDUID'];
			else
				return true;
		}
		else {
			$this->setError(self::ERROR_COMMAND, "Unable to send command: $request");
		}

		return false;
	}

	/**
	 * Handler for IMAP APPEND command.
	 *
	 * @param string $mailbox Mailbox name
	 * @param string $path	Path to the file with message body
	 * @param string $headers Message headers
	 *
	 * @return string|bool On success APPENDUID response (if available) or True, False on failure
	 */
	function appendFromFile($mailbox, $path, $headers=null)
	{
		unset($this->data['APPENDUID']);

		if(!$mailbox) {
			return false;
		}

		// open message file
		$in_fp = false;
		if(file_exists(realpath($path))) {
			$in_fp = fopen($path, 'r');
		}
		if(!$in_fp) {
			$this->setError(self::ERROR_UNKNOWN, "Couldn't open $path for reading");
			return false;
		}

		$body_separator = "\r\n\r\n";
		$len = filesize($path);

		if(!$len) {
			return false;
		}

		if($headers) {
			$headers = preg_replace('/[\r\n]+$/', '', $headers);
			$len += strlen($headers) + strlen($body_separator);
		}

		// send APPEND command
		$key = $this->nextTag();
		$request = sprintf("$key APPEND %s (\\Seen) {%d%s}", $this->escape($mailbox),
			$len, ($this->prefs['literal+'] ? '+' : ''));

		if($this->putLine($request)) {
			// Don't wait when LITERAL+ is supported
			if(!$this->prefs['literal+']) {
				$line = $this->readReply();

				if($line[0] != '+') {
					$this->parseResult($line, 'APPEND: ');
					return false;
				}
			}

			// send headers with body separator
			if($headers) {
				$this->putLine($headers . $body_separator, false);
			}

			// send file
			while(!feof($in_fp) && $this->fp) {
				$buffer = fgets($in_fp, 4096);
				$this->putLine($buffer, false);
			}
			fclose($in_fp);

			if(!$this->putLine('')) { // \r\n
				return false;
			}

			// read response
			do {
				$line = $this->readLine();
			} while(!$this->startsWith($line, $key, true, true));

			// Clear internal status cache
			unset($this->data['STATUS:'.$mailbox]);

			if($this->parseResult($line, 'APPEND: ') != self::ERROR_OK)
				return false;
			else if(!empty($this->data['APPENDUID']))
				return $this->data['APPENDUID'];
			else
				return true;
		}
		else {
			$this->setError(self::ERROR_COMMAND, "Unable to send command: $request");
		}

		return false;
	}

	function getQuota()
	{
		/*
		 * GETQUOTAROOT "INBOX"
		 * QUOTAROOT INBOX user/rchijiiwa1
		 * QUOTA user/rchijiiwa1 (STORAGE 654 9765)
		 * OK Completed
		 */
		$result	  = false;
		$quota_lines = [];
		$key		 = $this->nextTag();
		$command	 = $key . ' GETQUOTAROOT INBOX';

		// get line(s) containing quota info
		if($this->putLine($command)) {
			do {
				$line = rtrim($this->readLine(5000));
				if(preg_match('/^\* QUOTA /', $line)) {
					$quota_lines[] = $line;
				}
			} while(!$this->startsWith($line, $key, true, true));
		}
		else {
			$this->setError(self::ERROR_COMMAND, "Unable to send command: $command");
		}

		// return false if not found, parse if found
		$min_free = PHP_INT_MAX;
		foreach($quota_lines as $key => $quota_line) {
			$quota_line   = str_replace(array('(', ')'), '', $quota_line);
			$parts		= explode(' ', $quota_line);
			$storage_part = array_search('STORAGE', $parts);

			if(!$storage_part) {
				continue;
			}

			$used  = intval($parts[$storage_part+1]);
			$total = intval($parts[$storage_part+2]);
			$free  = $total - $used;

			// return lowest available space from all quotas
			if($free < $min_free) {
				$min_free		  = $free;
				$result['used']	= $used;
				$result['total']   = $total;
				$result['percent'] = min(100, round(($used/max(1,$total))*100));
				$result['free']	= 100 - $result['percent'];
			}
		}

		return $result;
	}

	/**
	 * Send the SETACL command (RFC4314)
	 *
	 * @param string $mailbox Mailbox name
	 * @param string $user	User name
	 * @param mixed  $acl	 ACL string or array
	 *
	 * @return boolean True on success, False on failure
	 *
	 * @since 0.5-beta
	 */
	function setACL($mailbox, $user, $acl)
	{
		if(is_array($acl)) {
			$acl = implode('', $acl);
		}

		$result = $this->execute('SETACL', array(
			$this->escape($mailbox), $this->escape($user), strtolower($acl)),
			self::COMMAND_NORESPONSE);

		return ($result == self::ERROR_OK);
	}

	/**
	 * Send the DELETEACL command (RFC4314)
	 *
	 * @param string $mailbox Mailbox name
	 * @param string $user	User name
	 *
	 * @return boolean True on success, False on failure
	 *
	 * @since 0.5-beta
	 */
	function deleteACL($mailbox, $user)
	{
		$result = $this->execute('DELETEACL', array(
			$this->escape($mailbox), $this->escape($user)),
			self::COMMAND_NORESPONSE);

		return ($result == self::ERROR_OK);
	}

	/**
	 * Send the GETACL command (RFC4314)
	 *
	 * @param string $mailbox Mailbox name
	 *
	 * @return array User-rights array on success, NULL on error
	 * @since 0.5-beta
	 */
	function getACL($mailbox)
	{
		list($code, $response) = $this->execute('GETACL', array($this->escape($mailbox)));

		if($code == self::ERROR_OK && preg_match('/^\* ACL /i', $response)) {
			// Parse server response (remove "* ACL ")
			$response = substr($response, 6);
			$ret  = $this->tokenizeResponse($response);
			$mbox = array_shift($ret);
			$size = count($ret);

			// Create user-rights hash array
			// @TODO: consider implementing fixACL() method according to RFC4314.2.1.1
			// so we could return only standard rights defined in RFC4314,
			// excluding 'c' and 'd' defined in RFC2086.
			if($size % 2 == 0) {
				for($i=0; $i<$size; $i++) {
					$ret[$ret[$i]] = str_split($ret[++$i]);
					unset($ret[$i-1]);
					unset($ret[$i]);
				}
				return $ret;
			}

			$this->setError(self::ERROR_COMMAND, "Incomplete ACL response");
			return NULL;
		}

		return NULL;
	}

	/**
	 * Send the LISTRIGHTS command (RFC4314)
	 *
	 * @param string $mailbox Mailbox name
	 * @param string $user	User name
	 *
	 * @return array List of user rights
	 * @since 0.5-beta
	 */
	function listRights($mailbox, $user)
	{
		list($code, $response) = $this->execute('LISTRIGHTS', array(
			$this->escape($mailbox), $this->escape($user)));

		if($code == self::ERROR_OK && preg_match('/^\* LISTRIGHTS /i', $response)) {
			// Parse server response (remove "* LISTRIGHTS ")
			$response = substr($response, 13);

			$ret_mbox = $this->tokenizeResponse($response, 1);
			$ret_user = $this->tokenizeResponse($response, 1);
			$granted  = $this->tokenizeResponse($response, 1);
			$optional = trim($response);

			return array(
				'granted'  => str_split($granted),
				'optional' => explode(' ', $optional),
			);
		}

		return NULL;
	}

	/**
	 * Send the MYRIGHTS command (RFC4314)
	 *
	 * @param string $mailbox Mailbox name
	 *
	 * @return array MYRIGHTS response on success, NULL on error
	 * @since 0.5-beta
	 */
	function myRights($mailbox)
	{
		list($code, $response) = $this->execute('MYRIGHTS', array($this->escape($mailbox)));

		if($code == self::ERROR_OK && preg_match('/^\* MYRIGHTS /i', $response)) {
			// Parse server response (remove "* MYRIGHTS ")
			$response = substr($response, 11);

			$ret_mbox = $this->tokenizeResponse($response, 1);
			$rights   = $this->tokenizeResponse($response, 1);

			return str_split($rights);
		}

		return NULL;
	}

	/**
	 * Send the SETMETADATA command (RFC5464)
	 *
	 * @param string $mailbox Mailbox name
	 * @param array  $entries Entry-value array (use NULL value as NIL)
	 *
	 * @return boolean True on success, False on failure
	 * @since 0.5-beta
	 */
	function setMetadata($mailbox, $entries)
	{
		if(!is_array($entries) || empty($entries)) {
			$this->setError(self::ERROR_COMMAND, "Wrong argument for SETMETADATA command");
			return false;
		}

		foreach($entries as $name => $value) {
			$entries[$name] = $this->escape($name) . ' ' . $this->escape($value);
		}

		$entries = implode(' ', $entries);
		$result = $this->execute('SETMETADATA', array(
			$this->escape($mailbox), '(' . $entries . ')'),
			self::COMMAND_NORESPONSE);

		return ($result == self::ERROR_OK);
	}

	/**
	 * Send the SETMETADATA command with NIL values (RFC5464)
	 *
	 * @param string $mailbox Mailbox name
	 * @param array  $entries Entry names array
	 *
	 * @return boolean True on success, False on failure
	 *
	 * @since 0.5-beta
	 */
	function deleteMetadata($mailbox, $entries)
	{
		if(!is_array($entries) && !empty($entries)) {
			$entries = explode(' ', $entries);
		}

		if(empty($entries)) {
			$this->setError(self::ERROR_COMMAND, "Wrong argument for SETMETADATA command");
			return false;
		}

		foreach($entries as $entry) {
			$data[$entry] = NULL;
		}

		return $this->setMetadata($mailbox, $data);
	}

	/**
	 * Send the GETMETADATA command (RFC5464)
	 *
	 * @param string $mailbox Mailbox name
	 * @param array  $entries Entries
	 * @param array  $options Command options (with MAXSIZE and DEPTH keys)
	 *
	 * @return array GETMETADATA result on success, NULL on error
	 *
	 * @since 0.5-beta
	 */
	function getMetadata($mailbox, $entries, $options=[])
	{
		if(!is_array($entries)) {
			$entries = array($entries);
		}

		// create entries string
		foreach($entries as $idx => $name) {
			$entries[$idx] = $this->escape($name);
		}

		$optlist = '';
		$entlist = '(' . implode(' ', $entries) . ')';

		// create options string
		if(is_array($options)) {
			$options = array_change_key_case($options, CASE_UPPER);
			$opts = [];

			if(!empty($options['MAXSIZE'])) {
				$opts[] = 'MAXSIZE '.intval($options['MAXSIZE']);
			}
			if(!empty($options['DEPTH'])) {
				$opts[] = 'DEPTH '.intval($options['DEPTH']);
			}

			if($opts) {
				$optlist = '(' . implode(' ', $opts) . ')';
			}
		}

		$optlist .= ($optlist ? ' ' : '') . $entlist;

		list($code, $response) = $this->execute('GETMETADATA', array(
			$this->escape($mailbox), $optlist));

		if($code == self::ERROR_OK) {
			$result = [];
			$data   = $this->tokenizeResponse($response);

			// The METADATA response can contain multiple entries in a single
			// response or multiple responses for each entry or group of entries
			if(!empty($data) && ($size = count($data))) {
				for($i=0; $i<$size; $i++) {
					if(isset($mbox) && is_array($data[$i])) {
						$size_sub = count($data[$i]);
						for($x=0; $x<$size_sub; $x++) {
							$result[$mbox][$data[$i][$x]] = $data[$i][++$x];
						}
						unset($data[$i]);
					}
					else if($data[$i] == '*') {
						if($data[$i+1] == 'METADATA') {
							$mbox = $data[$i+2];
							unset($data[$i]);   // "*"
							unset($data[++$i]); // "METADATA"
							unset($data[++$i]); // Mailbox
						}
						// get rid of other untagged responses
						else {
							unset($mbox);
							unset($data[$i]);
						}
					}
					else if(isset($mbox)) {
						$result[$mbox][$data[$i]] = $data[++$i];
						unset($data[$i]);
						unset($data[$i-1]);
					}
					else {
						unset($data[$i]);
					}
				}
			}

			return $result;
		}

		return NULL;
	}

	/**
	 * Send the SETANNOTATION command (draft-daboo-imap-annotatemore)
	 *
	 * @param string $mailbox Mailbox name
	 * @param array  $data	Data array where each item is an array with
	 *						three elements: entry name, attribute name, value
	 *
	 * @return boolean True on success, False on failure
	 * @since 0.5-beta
	 */
	function setAnnotation($mailbox, $data)
	{
		if(!is_array($data) || empty($data)) {
			$this->setError(self::ERROR_COMMAND, "Wrong argument for SETANNOTATION command");
			return false;
		}

		foreach($data as $entry) {
			// ANNOTATEMORE drafts before version 08 require quoted parameters
			$entries[] = sprintf('%s (%s %s)', $this->escape($entry[0], true),
				$this->escape($entry[1], true), $this->escape($entry[2], true));
		}

		$entries = implode(' ', $entries);
		$result  = $this->execute('SETANNOTATION', array(
			$this->escape($mailbox), $entries), self::COMMAND_NORESPONSE);

		return ($result == self::ERROR_OK);
	}

	/**
	 * Send the SETANNOTATION command with NIL values (draft-daboo-imap-annotatemore)
	 *
	 * @param string $mailbox Mailbox name
	 * @param array  $data	Data array where each item is an array with
	 *						two elements: entry name and attribute name
	 *
	 * @return boolean True on success, False on failure
	 *
	 * @since 0.5-beta
	 */
	function deleteAnnotation($mailbox, $data)
	{
		if(!is_array($data) || empty($data)) {
			$this->setError(self::ERROR_COMMAND, "Wrong argument for SETANNOTATION command");
			return false;
		}

		return $this->setAnnotation($mailbox, $data);
	}

	/**
	 * Send the GETANNOTATION command (draft-daboo-imap-annotatemore)
	 *
	 * @param string $mailbox Mailbox name
	 * @param array  $entries Entries names
	 * @param array  $attribs Attribs names
	 *
	 * @return array Annotations result on success, NULL on error
	 *
	 * @since 0.5-beta
	 */
	function getAnnotation($mailbox, $entries, $attribs)
	{
		if(!is_array($entries)) {
			$entries = array($entries);
		}
		// create entries string
		// ANNOTATEMORE drafts before version 08 require quoted parameters
		foreach($entries as $idx => $name) {
			$entries[$idx] = $this->escape($name, true);
		}
		$entries = '(' . implode(' ', $entries) . ')';

		if(!is_array($attribs)) {
			$attribs = array($attribs);
		}
		// create entries string
		foreach($attribs as $idx => $name) {
			$attribs[$idx] = $this->escape($name, true);
		}
		$attribs = '(' . implode(' ', $attribs) . ')';

		list($code, $response) = $this->execute('GETANNOTATION', array(
			$this->escape($mailbox), $entries, $attribs));

		if($code == self::ERROR_OK) {
			$result = [];
			$data   = $this->tokenizeResponse($response);

			// Here we returns only data compatible with METADATA result format
			if(!empty($data) && ($size = count($data))) {
				for($i=0; $i<$size; $i++) {
					$entry = $data[$i];
					if(isset($mbox) && is_array($entry)) {
						$attribs = $entry;
						$entry   = $last_entry;
					}
					else if($entry == '*') {
						if($data[$i+1] == 'ANNOTATION') {
							$mbox = $data[$i+2];
							unset($data[$i]);   // "*"
							unset($data[++$i]); // "ANNOTATION"
							unset($data[++$i]); // Mailbox
						}
						// get rid of other untagged responses
						else {
							unset($mbox);
							unset($data[$i]);
						}
						continue;
					}
					else if(isset($mbox)) {
						$attribs = $data[++$i];
					}
					else {
						unset($data[$i]);
						continue;
					}

					if(!empty($attribs)) {
						for($x=0, $len=count($attribs); $x<$len;) {
							$attr  = $attribs[$x++];
							$value = $attribs[$x++];
							if($attr == 'value.priv') {
								$result[$mbox]['/private' . $entry] = $value;
							}
							else if($attr == 'value.shared') {
								$result[$mbox]['/shared' . $entry] = $value;
							}
						}
					}
					$last_entry = $entry;
					unset($data[$i]);
				}
			}

			return $result;
		}

		return NULL;
	}

	/**
	 * Returns BODYSTRUCTURE for the specified message.
	 *
	 * @param string $mailbox Folder name
	 * @param int	$id	  Message sequence number or UID
	 * @param bool   $is_uid  True if $id is an UID
	 *
	 * @return array/bool Body structure array or False on error.
	 * @since 0.6
	 */
	function getStructure($mailbox, $id, $is_uid = false)
	{
		$result = $this->fetch($mailbox, $id, $is_uid, array('BODYSTRUCTURE'));
		if(is_array($result)) {
			$result = array_shift($result);
			return $result->bodystructure;
		}
		return false;
	}

	/**
	 * Returns data of a message part according to specified structure.
	 *
	 * @param array  $structure Message structure (getStructure() result)
	 * @param string $part	  Message part identifier
	 *
	 * @return array Part data as hash array (type, encoding, charset, size)
	 */
	static function getStructurePartData($structure, $part)
	{
		$part_a = self::getStructurePartArray($structure, $part);
		$data   = [];

		if(empty($part_a)) {
			return $data;
		}

		// content-type
		if(is_array($part_a[0])) {
			$data['type'] = 'multipart';
		}
		else {
			$data['type'] = strtolower($part_a[0]);

			// encoding
			$data['encoding'] = strtolower($part_a[5]);

			// charset
			if(is_array($part_a[2])) {
			   while(list($key, $val) = each($part_a[2])) {
					if(strcasecmp($val, 'charset') == 0) {
						$data['charset'] = $part_a[2][$key+1];
						break;
					}
				}
			}
		}

		// size
		$data['size'] = intval($part_a[6]);

		return $data;
	}

	static function getStructurePartArray($a, $part)
	{
		if(!is_array($a)) {
			return false;
		}

		if(empty($part)) {
			return $a;
		}

		$ctype = is_string($a[0]) && is_string($a[1]) ? $a[0] . '/' . $a[1] : '';

		if(strcasecmp($ctype, 'message/rfc822') == 0) {
			$a = $a[8];
		}

		if(strpos($part, '.') > 0) {
			$orig_part = $part;
			$pos	   = strpos($part, '.');
			$rest	  = substr($orig_part, $pos+1);
			$part	  = substr($orig_part, 0, $pos);

			return self::getStructurePartArray($a[$part-1], $rest);
		}
		else if($part > 0) {
			if(is_array($a[$part-1]))
				return $a[$part-1];
			else
				return $a;
		}
	}

	/**
	 * Creates next command identifier (tag)
	 *
	 * @return string Command identifier
	 * @since 0.5-beta
	 */
	function nextTag()
	{
		$this->cmd_num++;
		$this->cmd_tag = sprintf('A%04d', $this->cmd_num);

		return $this->cmd_tag;
	}

	/**
	 * Sends IMAP command and parses result
	 *
	 * @param string $command   IMAP command
	 * @param array  $arguments Command arguments
	 * @param int	$options   Execution options
	 *
	 * @return mixed Response code or list of response code and data
	 * @since 0.5-beta
	 */
	function execute($command, $arguments=[], $options=0)
	{
		$tag	  = $this->nextTag();
		$query	= $tag . ' ' . $command;
		$noresp   = ($options & self::COMMAND_NORESPONSE);
		$response = $noresp ? null : '';

		if(!empty($arguments)) {
			foreach($arguments as $arg) {
				$query .= ' ' . self::r_implode($arg);
			}
		}

		// Send command
		if(!$this->putLineC($query)) {
			$this->setError(self::ERROR_COMMAND, "Unable to send command: $query");
			return $noresp ? self::ERROR_COMMAND : array(self::ERROR_COMMAND, '');
		}

		// Parse response
		do {
			$line = $this->readLine(4096);
			if($response !== null) {
				$response .= $line;
			}
		} while(!$this->startsWith($line, $tag . ' ', true, true));

		$code = $this->parseResult($line, $command . ': ');

		// Remove last line from response
		if($response) {
			$line_len = min(strlen($response), strlen($line) + 2);
			$response = substr($response, 0, -$line_len);
		}

		// optional CAPABILITY response
		if(($options & self::COMMAND_CAPABILITY) && $code == self::ERROR_OK
			&& preg_match('/\[CAPABILITY ([^]]+)\]/i', $line, $matches)
		) {
			$this->parseCapability($matches[1], true);
		}

		// return last line only (without command tag, result and response code)
		if($line && ($options & self::COMMAND_LASTLINE)) {
			$response = preg_replace("/^$tag (OK|NO|BAD|BYE|PREAUTH)?\s*(\[[a-z-]+\])?\s*/i", '', trim($line));
		}

		return $noresp ? $code : array($code, $response);
	}

	/**
	 * Splits IMAP response into string tokens
	 *
	 * @param string &$str The IMAP's server response
	 * @param int	$num  Number of tokens to return
	 *
	 * @return mixed Tokens array or string if $num=1
	 * @since 0.5-beta
	 */
	static function tokenizeResponse(&$str, $num=0)
	{
		$result = [];

		while(!$num || count($result) < $num) {
			// remove spaces from the beginning of the string
			$str = ltrim($str);

			//if(!isset($str[0])){	$str = "";}

			switch(@$str[0])
			{
				// String literal
				case '{':
					if(($epos = strpos($str, "}\r\n", 1)) == false) {
						// error
					}
					if(!is_numeric(($bytes = substr($str, 1, $epos - 1)))) {
						// error
					}
					$result[] = $bytes ? substr($str, $epos + 3, $bytes) : '';
					// Advance the string
					$str = substr($str, $epos + 3 + $bytes);
					break;

				// Quoted string
				case '"':
					$len = strlen($str);

					for($pos=1; $pos<$len; $pos++) {
						if($str[$pos] == '"') {
							break;
						}
						if($str[$pos] == "\\") {
							if($str[$pos + 1] == '"' || $str[$pos + 1] == "\\") {
								$pos++;
							}
						}
					}
					if($str[$pos] != '"') {
						// error
					}
					// we need to strip slashes for a quoted string
					$result[] = stripslashes(substr($str, 1, $pos - 1));
					$str	  = substr($str, $pos + 1);
					break;

				// Parenthesized list
				case '(':
				case '[':
					$str = substr($str, 1);
					$result[] = self::tokenizeResponse($str);
					break;
				case ')':
				case ']':
					$str = substr($str, 1);
					return $result;
					break;

				// String atom, number, NIL, *, %
				default:
					// empty string
					if($str === '' || $str === null) {
						break 2;
					}

					// excluded chars: SP, CTL, ), [, ]
					if(preg_match('/^([^\x00-\x20\x29\x5B\x5D\x7F]+)/', $str, $m)) {
						$result[] = $m[1] == 'NIL' ? NULL : $m[1];
						$str = substr($str, strlen($m[1]));
					}
					break;
			}
		}

		return $num == 1 ? $result[0] : $result;
	}

	static function r_implode($element)
	{
		$string = '';

		if(is_array($element)) {
			reset($element);
			while(list($key, $value) = each($element)) {
				$string .= ' ' . self::r_implode($value);
			}
		}
		else {
			return $element;
		}

		return '(' . trim($string) . ')';
	}

	private function _xor($string, $string2)
	{
		$result = '';
		$size   = strlen($string);

		for($i=0; $i<$size; $i++) {
			$result .= chr(ord($string[$i]) ^ ord($string2[$i]));
		}

		return $result;
	}

	/**
	 * Converts datetime string into unix timestamp
	 *
	 * @param string $date Date string
	 *
	 * @return int Unix timestamp
	 */
	static function strToTime($date)
	{
		// support non-standard "GMTXXXX" literal
		$date = preg_replace('/GMT\s*([+-][0-9]+)/', '\\1', $date);

		// if date parsing fails, we have a date in non-rfc format
		// remove token from the end and try again
		while(($ts = intval(@strtotime($date))) <= 0) {
			$d = explode(' ', $date);
			array_pop($d);
			if(empty($d)) {
				break;
			}
			$date = implode(' ', $d);
		}

		return $ts < 0 ? 0 : $ts;
	}

	private function parseCapability($str, $trusted=false)
	{
		$str = preg_replace('/^\* CAPABILITY /i', '', $str);

		$this->capability = explode(' ', strtoupper($str));

		if(!isset($this->prefs['literal+']) && in_array('LITERAL+', $this->capability)) {
			$this->prefs['literal+'] = true;
		}

		if($trusted) {
			$this->capability_readed = true;
		}
	}

	/**
	 * Escapes a string when it contains special characters (RFC3501)
	 *
	 * @param string  $string	   IMAP string
	 * @param boolean $force_quotes Forces string quoting (for atoms)
	 *
	 * @return string String atom, quoted-string or string literal
	 * @todo lists
	 */
	static function escape($string, $force_quotes=false)
	{
		if($string === null) {
			return 'NIL';
		}
		if($string === '') {
			return '""';
		}
		// atom-string (only safe characters)
		if(!$force_quotes && !preg_match('/[\x00-\x20\x22\x28-\x2A\x5B-\x5D\x7B\x7D\x80-\xFF]/', $string)) {
			return $string;
		}
		// quoted-string
		if(!preg_match('/[\r\n\x00\x80-\xFF]/', $string)) {
			return '"' . addcslashes($string, '\\"') . '"';
		}

		// literal-string
		return sprintf("{%d}\r\n%s", strlen($string), $string);
	}

	static function unEscape($string)
	{
		return stripslashes($string);
	}

	/**
	 * Set the value of the debugging flag.
	 *
	 * @param   boolean $debug	  New value for the debugging flag.
	 *
	 * @since   0.5-stable
	 */
	function setDebug($debug, $handler = null)
	{
		$this->_debug = $debug;
		$this->_debug_handler = $handler;
	}

	/**
	 * Write the given debug text to the current debug output handler.
	 *
	 * @param   string  $message	Debug mesage text.
	 *
	 * @since   0.5-stable
	 */
	private function debug($message)
	{
		if($this->resourceid) {
			$message = sprintf('[%s] %s', $this->resourceid, $message);
		}

		if($this->_debug_handler) {
			call_user_func_array($this->_debug_handler, array(&$this, $message));
		} else {
			echo "DEBUG: $message\n";
		}
	}

}
