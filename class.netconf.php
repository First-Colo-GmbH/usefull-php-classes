<?php
/***************************************************************************
Copyright (c) 2016, Martin Verges <m.verges@first-colo.net>
All rights reserved.

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:

* Redistributions of source code must retain the above copyright notice, this
  list of conditions and the following disclaimer.

* Redistributions in binary form must reproduce the above copyright notice,
  this list of conditions and the following disclaimer in the documentation
  and/or other materials provided with the distribution.

* Neither the name of the copyright holder nor the names of its
  contributors may be used to endorse or promote products derived from
  this software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
***************************************************************************/

// http://www.juniper.net/techpubs/en_US/junos15.1/information-products/topic-collections/junos-xml-ref-oper/get-bridge-instance-information.html

require_once('class.extendxml.php');

class NetConf {
	private $host = '';			// Host to connect to
	private $port = 830;		// Port to connect for Netconf
	private $user = 'root';		// Username to login
	private $pass = '';			// Password for pw based login
	private $proc;				// SSH Connection proc

	private $pipes;				// Stream Pipes
	private $eoc = ']]>]]>';	// End of communication
	private $lastresponse;		// Last communication response

	private $remote_capabilitys = array();	// remote capabilitys

	public $debug = false;		// Enable/Disable Debugging

	private $message_id = 100;	// RPC Message ID to identify

	public function NetConf($host,$port=false,$user=false,$pass=false) {
		$this->host = $host;
		$this->setPort($port);
		$this->setUser($user);
		$this->setPass($pass);
		libxml_use_internal_errors(true);
	}

	public function setUser($user) {
		if($user) $this->user = $user;
	}

	public function setPass($pass) {
		if($pass) $this->pass = $pass;
	}

	public function setPort($port) {
		if($port) $this->port = $port;
	}

	public function connect() {
		// Build command
		$cmd = array('/usr/bin/ssh');
		for($i=0; $i<func_num_args(); $i++) $cmd[] = func_get_arg($i);
		$cmd[] = '-o BatchMode=yes';							// Never open a Password Prompt
		$cmd[] = '-o PasswordAuthentication=no';				// Never open a Password Prompt
		$cmd[] = '-o StrictHostKeyChecking=no';					// don't ask for HostKey correctness
		$cmd[] = '-p '.(int)$this->port;						// connect to port
		$cmd[] = escapeshellarg($this->user.'@'.$this->host);	// connect to user@host
		$cmd[] = '-s netconf';									// open a Netconf session (must me last arg)

		if($this->debug) echo "SSH Connect: ".implode(' ', $cmd).PHP_EOL;

		$descriptorspec = array(
				0 => array("pipe", "r"),	// stdin is a pipe that the child will read from
				1 => array("pipe", "w"),	// stdout is a pipe that the child will write to
				2 => array("pipe", "r")		// stderr is a file to write to
		);
		$this->proc = proc_open(implode(' ', $cmd), $descriptorspec, $this->pipes);
		#$this->isConnected();

		stream_set_timeout($this->pipes[0], 2);
		stream_set_timeout($this->pipes[1], 2);
		stream_set_timeout($this->pipes[2], 2);

		// Server capabilitys like urn:ietf:params:xml:ns:netconf:capability:candidate:1.0
		list($ok, $serverhello) = $this->readStream(true);
		if( !$ok ) return false;
		foreach($serverhello as $a) {
			if( is_object($a) and $a->capabilities and $a->capabilities->capability ) {
				foreach( $a->capabilities->children() as $c ) {
					$this->remote_capabilitys[] = (string)$c;
				}
			}
		}
		// send out HELLO to the Server with default values
		$this->sendRPCHello();
		return $this->isConnected();
	}

	public function isConnected() {
		$status = proc_get_status($this->proc);
		return (bool)$status["running"];
	}

	public function writeStream($send) {
		if(!$this->isConnected()) 	echo 'No valid connection available'.PHP_EOL;
		if($this->debug)			echo 'Sending COMMAND: '.formatXmlString($send).PHP_EOL;
		return fwrite($this->pipes[0], $send);
	}

	public function readStream($firstattempt=false, $removeNS=true) {
		if(!$this->isConnected()) 	echo 'No valid connection available'.PHP_EOL;
		if($this->debug) echo 'Received RESPONSE: ';
		$buffer = '';
		while( $line = fgets($this->pipes[1]) ) {
			$line = rtrim($line);
			if($this->debug) echo $line.PHP_EOL;
			$buffer .= $line.PHP_EOL;
			if( $line == $this->eoc ) break;
			// only on the first attempt, we want to send out that SSH based stuff
			if( $firstattempt && strpos($line, 'yes/no') )		$this->writeStream('yes');			// Host Key Check
			if( $firstattempt && strpos($line, 'password:') )	$this->writeStream($this->pass);	// Send SSH Password
			if( strpos($line, 'session end at') ) {
				// session is terminated
				break;
			}
		}
		if($this->debug) echo PHP_EOL;
		$this->lastresponse = $buffer;

		$buffer = trim($buffer);
		$buffer = substr($buffer, 0, strpos($buffer, $this->eoc));

		if( $removeNS ) {
			// Gets rid of all namespace definitions
			while( preg_match('/(<[^<]+)\sxmlns(:|)[^=]*="[^"]*"([^>]*>)/i', $buffer) ) {
				$buffer = preg_replace('/(<[^<]+)\sxmlns(:|)[^=]*="[^"]*"([^>]*>)/i', '\1\3', $buffer);
			}
		}

		if( empty($buffer) ) {
			return array(false, false);
		}

		$xml = new ExSimpleXMLElement($buffer);
		if( !$xml ) {
			echo 'Buffer: '.$buffer.PHP_EOL;
			echo 'Parsen des XML fehlgeschlagen:'.PHP_EOL;
			foreach(libxml_get_errors() as $error) echo $error->message;
			return array(false, $buffer);
		} else {
			$correct = false;
			if( $xml->getName() == 'hello'			) $correct = true;
			elseif( $xml->getName() == 'rpc-reply'	) $correct = true;
			elseif( $xml->getName() == 'rpc-error'	) $correct = false;
			return array($correct, $xml);
		}
		// return is
		//   1 = return code is rpc-error or not valid XML Code
		//   2 = XML Object or Buffer String
	}

	/**
	 * Send Hello after Connect with Capabilites
	 *
	 * @param array $capabilites
	 */
	public function sendRPCHello($capabilites=false) {
		$xml = new ExSimpleXMLElement('<hello/>');
		$xml->addAttribute('xmlns', 'urn:ietf:params:xml:ns:netconf:base:1.0');	// NameSpace
		$cap = $xml->addChild('capabilities');
		if( is_array($capabilites) ) {
			foreach($capabilites as $c) $cap->addChild('capability', $c);
		} else {
			// Default
			$cap->addChild('capability', 'urn:ietf:params:xml:ns:netconf:base:1.0');
			$cap->addChild('capability', 'urn:ietf:params:xml:ns:netconf:base:1.0#candidate');
			$cap->addChild('capability', 'urn:ietf:params:xml:ns:netconf:base:1.0#confirmed-commit');
			$cap->addChild('capability', 'urn:ietf:params:xml:ns:netconf:base:1.0#validate');
			$cap->addChild('capability', 'urn:ietf:params:xml:ns:netconf:base:1.0#url?protocol=http,ftp,file');
		}
		return $this->writeStream($xml->asXML());
	}

	/**
	 * Disconnect the current Netconf Session
	 */
	public function disconnect() {
		$xml = $this->getrpc();
		$xml->addChild('request-end-session');

		$this->writeStream($xml->asXML());
		sleep(0.5);
		return proc_close($this->proc);
	}

	/**
	 * Request the configuration from the Netconf Session
	 *
	 * @param string $target
	 * @param string $configTree
	 * @return mixed
	 */
	public function getConfig($target='running', $configTree=false) {
		$xml = $this->getrpc();
		$get = $xml->addChild('get-config');
		$src = $get->addChild('source');
		$src->addChild($target);
		if( $configTree != false ) {	// LIBXML_NOEMPTYTAG
			$filter = $get->addChild('filter');
			$filter->addAttribute('type', 'subtree');
			$filter->appendXML( new ExSimpleXMLElement($configTree) );
			$this->writeStream( $xml->asXML() );
		} else {
			$this->writeStream($xml->asXML());
		}
		return $this->readStream();
	}

	/**
	 * Request the configuration from the Netconf Session in text Format
	 *
	 * @return mixed
	 */
	public function getTextConfig() {
		$xml = $this->getrpc();
		$get = $xml->addChild('get-configuration');
		$get->addAttribute('format', 'text');
		$this->writeStream($xml->asXML());
		return $this->readStream();
	}

	/**
	 * Validate the candidate configuration.
	 *
	 * @return mixed
	 */
	public function validateConfig() {
		$xml = $this->getrpc();
		$validate = $xml->addChild('validate');
		$source = $validate->addChild('source');
		$source->addChild('candidate');

		$this->writeStream($xml->asXML());
		return $this->readStream();
	}

	/**
	 * Request that the Junos XML protocol server open and lock the candidate configuration,
	 * enabling the client application both to read and change it, but preventing any other
	 * users or applications from changing it. The application must emit the
	 * <unlock-configuration/> tag to unlock the configuration.
	 *
	 * @return mixed
	 */
	public function lockConfig() {
		$xml = $this->getrpc();
		$lock = $xml->addChild('lock-configuration');

		$this->writeStream($xml->asXML());
		return $this->readStream();
	}

	/**
	 * Unlocks the candidate configuration within the Netconf Session
	 *
	 * @return mixed
	 */
	public function unlockConfig() {
		$xml = $this->getrpc();
		$lock = $xml->addChild('unlock-configuration');

		$this->writeStream($xml->asXML());
		return $this->readStream();
	}

	/**
	 * Create a private copy of the candidate configuration
	 * can not be commit-confirmed and some other draw backs
	 *
	 * @return mixed
	 */
	public function openConfig() {
		$xml = $this->getrpc();
		$opencfg = $xml->addChild('open-configuration');
		$opencfg->addChild('private');

		$this->writeStream($xml->asXML());
		return $this->readStream();
	}

	/**
	 * Discard a candidate configuration and any changes to it
	 *
	 * @return mixed
	 */
	public function closeConfig() {
		$xml = $this->getrpc();
		$xml->addChild('close-configuration');

		$this->writeStream($xml->asXML());
		return $this->readStream();
	}

	/**
	 * Change the configuration with set commands
	 *
	 * @url http://www.juniper.net/techpubs/en_US/junos12.3/information-products/topic-collections/junos-xml-management-protocol-guide/topic-49518.html
	 * @return mixed
	 */
	public function changeConfigSet($commands) {
		$xml = $this->getrpc();
		$cfg = $xml->addChild('load-configuration');
		$cfg->addAttribute('action', 'set');
		$cfg->addAttribute('format', 'text');
		$set = $cfg->addChild('configuration-set', $commands);

		$this->writeStream($xml->asXML());
		return $this->readStream();
	}

	/**
	 * Change the configuration with set commands
	 *
	 * @url http://www.juniper.net/techpubs/en_US/junos12.3/information-products/topic-collections/junos-xml-management-protocol-guide/topic-49518.html
	 * @return mixed
	 */
	public function changeConfig($xmlcfg, $action='merge', $format='xml') {
		$xml = $this->getrpc();
		$cfg = $xml->addChild('load-configuration');
		$cfg->addAttribute('action', $action);
		$cfg->addAttribute('format', $format);
		if( is_object($xmlcfg) ) {
			$cfg->appendXML( $xmlcfg );
		} else {
			$cfg->appendXML( new ExSimpleXMLElement($xmlcfg) );
		}

		$this->writeStream($xml->asXML());
		return $this->readStream();
	}


	/**
	 * Commit the candidate configuration
	 *
	 * @param int $confirm   confirm time in Minutes
	 * @return mixed
	 */
	public function commit($confirm=0, $logmsg='') {
		$xml = $this->getrpc();
		$commit = $xml->addChild('commit');
		if( $confirm > 0 ) {
			$commit->addChild('confirmed');
			$commit->addChild('confirm-timeout', $confirm*60);
		}
		if( !empty($logmsg) ) {
			$commit->addChild('log', $logmsg);
		} else {
			$commit->addChild('log', 'Commit via Netconf Script: '.basename(__FILE__));
		}

		$this->writeStream($xml->asXML());
		return $this->readStream();
	}

	/**
	 * returns a new ID for the RPC
	 *
	 * @return number
	 */
	private function _getmsgid() {
		return ++$this->message_id;
	}

	public function getrpc() {
		$rpc = new ExSimpleXMLElement('<rpc/>');
		$rpc->addAttribute('message-id', $this->_getmsgid());
		return $rpc;
	}

}

