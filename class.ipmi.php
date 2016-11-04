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

class ipmi {
	const DEBUG = false;
	public $ipaddress = '';
	public $username = '';
	public $password = '';
	public $ipmitool = 'ipmitool';    // maybe you want to add path to bin, default use search path
	public $proxyaddress = IPMI_PROXY_HOST;        // IP of the IPMI Proxy
	public $proxyportmin = 65000;    // Lowest Port
	public $proxyportmax = 65500;    // Hightest Port
	public $proto = 'http';        // current Session ID
	protected $ipmi_vendor = '';    // AMI, ATEN,...
	private $sessionID = '';            // http://.. or https://..

	public function __construct($ip, $user, $pass) {
		$this->ipaddress = $ip;
		$this->username = $user;
		$this->password = $pass;
	}

	/**
	 * reuse a existing SessionID from previous execution
	 *
	 * @param string $sessionID
	 */
	public function reuseSession($sessionID) {
		$this->sessionID = $sessionID;
	}

	/**
	 * restarts the IPMI Interface
	 */
	public function resetIPMI() {
		return shell_exec($this->_cmd('mc reset cold'));
	}

	/**
	 * Generates a commandline to connect to IPMI via lanplus
	 * INFO: $append needs to be shell escaped!
	 *
	 * @return string        Shell Command
	 */
	private function _cmd($append = false) {
		return sprintf($this->ipmitool.' -I lanplus -H %s -U %s -P %s ',
			escapeshellarg($this->ipaddress),
			escapeshellarg($this->username),
			escapeshellarg($this->password)
		).($append ? ' '.$append : '');
	}

	/**
	 * turns on the computer
	 */
	public function powerOn() {
		return shell_exec($this->_cmd('power on'));
	}

	/**
	 * turns off the computer (warning)
	 */
	public function powerOff() {
		return shell_exec($this->_cmd('power off'));
	}

	/**
	 * restart the computer (warning)
	 */
	public function powerReset() {
		return shell_exec($this->_cmd('power reset'));
	}

	/**
	 * Sets a temporary boot device
	 *
	 * @param string $device Boot device which will be used at next reboot
	 * @return string
	 */
	public function setTempBootDevice($device) {
		return shell_exec($this->_cmd('chassis bootdev '.$device));
	}

	/**
	 * Terminates the current IPMI Session
	 *
	 * @return boolean
	 */
	public function logout() {
		if( empty($this->sessionID) ) return true;

		$curl = $this->_curl_init($this->ipaddress.'/cgi/logout.cgi', sprintf('http://%s/cgi/url_redirect.cgi?url_name=sys_info', $this->ipaddress));
		$out = curl_exec($curl);
		if( $out === false && self::DEBUG ) error_log('cURL Error: '.curl_error($curl));
		curl_close($curl);

		$this->sessionID = '';
		return true;
	}

	public function _curl_init($url, $referer = false) {
		if( self::DEBUG ) error_log('IPMI URL: '.sprintf('%s://%s', $this->proto, $url));
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, sprintf('%s://%s', $this->proto, $url));
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
		curl_setopt($curl, CURLOPT_TIMEOUT, 10);
		curl_setopt($curl, CURLOPT_ENCODING, '');
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		if( !empty($this->sessionID) ) {
			if( $this->ipmi_vendor == 'ATEN' ) curl_setopt($curl, CURLOPT_COOKIE, sprintf('SID=%s;', urlencode($this->sessionID)));
			if( $this->ipmi_vendor == 'AMI' ) curl_setopt($curl, CURLOPT_COOKIE, sprintf('SessionCookie=%s; Username=%s', urlencode($this->sessionID), urlencode($this->username)));
		}
		if( $referer ) {
			curl_setopt($curl, CURLOPT_REFERER, $referer);
		}
		return $curl;
	}

	/**
	 * Liefert Informationen über das IPMI Interface wie z.B. Firmware Version
	 *
	 * @return boolean|array
	 */
	public function getGenericInfo() {
		if( empty($this->sessionID) && !$this->login() ) return false;

		if( !$this->ipmi_vendor ) $this->DetectVendor();
		if( self::DEBUG ) error_log('IPMI: Vendor is '.$this->ipmi_vendor);

		if( $this->ipmi_vendor == 'AMI' ) {
			$curl = $this->_curl_init($this->ipaddress.'/rpc/getalllancfg.asp', sprintf('http://%s/page/dashboard.html', $this->ipaddress));
			$out = curl_exec($curl);
			if( $out === false && self::DEBUG ) error_log('cURL Error: '.curl_error($curl));
			curl_close($curl);

			if( preg_match_all("/'(\w+)'\s+:\s+(?:'|)([^' ,]+)(?:'|)/", $out, $match) ) {
				$data = array();
				foreach($match[1] as $k => $v) $data[$v] = $match[2][$k];
				return $data;
			} else {
				return false;
			}
		} elseif( $this->ipmi_vendor == 'ATEN' ) {
			// FIRMWARE Typ A
			$curl = $this->_curl_init($this->ipaddress.'/cgi/ipmi.cgi', sprintf('http://%s/cgi/url_redirect.cgi?url_name=sys_info', $this->ipaddress));
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query(array('GENERIC_INFO.XML' => '(0,0)', 'time_stamp' => time())));
			$out = curl_exec($curl);
			if( $out === false && self::DEBUG ) error_log('cURL Error: '.curl_error($curl));
			curl_close($curl);

			if( substr($out, 0, 21) != '<?xml version="1.0"?>' ) {        // Content-Type: application/xml
				// FIRMWARE Typ B
				$curl = $this->_curl_init($this->ipaddress.'/cgi/ipmi.cgi', sprintf('http://%s/cgi/url_redirect.cgi?url_name=sys_info', $this->ipaddress));
				curl_setopt($curl, CURLOPT_POST, true);
				curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query(array('op' => 'GENERIC_INFO.XML', 'r' => '(0,0)', 'time_stamp' => time())));
				$out = curl_exec($curl);
				if( $out === false && self::DEBUG ) error_log('cURL Error: '.curl_error($curl));
				curl_close($curl);
			}

			if( substr($out, 0, 21) != '<?xml version="1.0"?>' ) return false;    // firmware won't answer correct...
			$xml = simplexml_load_string($out);

			if( !isset($xml->GENERIC_INFO->GENERIC) ) return false;

			$data = array();
			foreach($xml->GENERIC_INFO->GENERIC->attributes() as $k => $v) {
				$data[strtolower($k)] = (string)$v;
			}
			return $data;
		}
	}

	/**
	 * Loggt sich in das Webinterface ein und generiert eine Session
	 *
	 * @return string || false
	 */
	public function login() {
		if( !empty($this->sessionID) ) return $this->sessionID;

		if( !$this->ipmi_vendor ) $this->DetectVendor();

		if( $this->ipmi_vendor == 'AMI' ) {
			$curl = $this->_curl_init($this->ipaddress.'/rpc/WEBSES/create.asp');
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, sprintf('WEBVAR_USERNAME=%s&WEBVAR_PASSWORD=%s', urlencode($this->username), urlencode($this->password)));
			$out = curl_exec($curl);
			if( $out === false && self::DEBUG ) error_log('cURL Error: '.curl_error($curl));
			curl_close($curl);

			if( preg_match("/'SESSION_COOKIE'\s+:\s+'(\w+)'/", $out, $match) ) {
				$this->sessionID = $match[1];
				return $this->sessionID;
			} else {
				return false;
			}
		} elseif( $this->ipmi_vendor == 'ATEN' ) {
			$curl = $this->_curl_init($this->ipaddress.'/cgi/login.cgi', $this->proto.'://10.224.1.155/');
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, sprintf('name=%s&pwd=%s', urlencode($this->username), urlencode($this->password)));
			if( self::DEBUG ) error_log('IPMI Login: '.sprintf('name=%s&pwd=%s', urlencode($this->username), urlencode($this->password)));
			curl_setopt($curl, CURLOPT_HEADER, true);
			$out = curl_exec($curl);
			if( $out === false && self::DEBUG ) error_log('cURL Error: '.curl_error($curl));
			curl_close($curl);

			if( preg_match('/Set-Cookie: SID=([^;]+); path=/', $out, $match) ) {
				if( self::DEBUG ) error_log('IPMI: login cookie found');
				$this->sessionID = $match[1];
				return $this->sessionID;
			} else {
				if( self::DEBUG ) error_log('IPMI: no login cookie found');
				if( self::DEBUG ) error_log($out);
				return false;
			}
		}
	}

	/**
	 * Ermittelt den IPMI Interface Hersteller
	 *
	 * @return string || false
	 */
	public function DetectVendor() {
		$curl = $this->_curl_init($this->ipaddress);
		curl_setopt($curl, CURLOPT_HEADER, true);
		$out = curl_exec($curl);
		if( $out === false && self::DEBUG ) error_log('cURL Error: '.curl_error($curl));
		if( preg_match('#Location: (http|https)://(.*)#', $out, $match) && $match[1] == 'https' ) $this->setProtocol('https');
		curl_close($curl);

		if( preg_match('/American Megatrends Inc/', $out) ) {
			$this->ipmi_vendor = 'AMI';
		} elseif( preg_match('/ATEN International Co Ltd./', $out) ) {
			$this->ipmi_vendor = 'ATEN';
		} else {
			if( self::DEBUG ) error_log('Unable to detect IPMI Vendor, output is:');
			if( self::DEBUG ) error_log($out);
			$this->ipmi_vendor = false;
		}
		return $this->ipmi_vendor;
	}

	/**
	 * legt das genutzte Protokoll für die Verbindung fest (HTTP/HTTPS)
	 *
	 * @param string $newproto http|https
	 * @return boolean
	**/
	public function setProtocol($newproto) {
		if( $newproto == 'http' or $newproto == 'https' ) {
			if( self::DEBUG ) error_log('IPMI: new protocol is '.$newproto);
			$this->proto = $newproto;
			return true;
		} else return false;
	}

	/**
	 * retrieve a bmp image from kvm
	 */
	public function getPreviewImage() {
		if( empty($this->sessionID) && !$this->login() ) return false;

		if( !$this->ipmi_vendor ) $this->DetectVendor();

		// Feature only available at ATEN IPMI
		if( $this->ipmi_vendor != 'ATEN' ) return false;

		$timestamp = time();

		// FIRMWARE Typ A
		$curl = $this->_curl_init($this->ipaddress.'/cgi/CapturePreview.cgi', sprintf('http://%s/cgi/url_redirect.cgi?url_name=sys_info', $this->ipaddress));
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query(array('IKVM_PREVIEW.XML' => '(0,0)', 'time_stamp' => $timestamp)));
		$out = curl_exec($curl);
		if( $out === false && self::DEBUG ) error_log('cURL Error: '.curl_error($curl));
		curl_close($curl);

		// FIRMWARE Typ B
		$curl = $this->_curl_init($this->ipaddress.'/cgi/op.cgi', sprintf('http://%s/cgi/url_redirect.cgi?url_name=sys_info', $this->ipaddress));
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query(array('op' => 'sys_preview')));
		curl_setopt($curl, CURLOPT_HTTPHEADER, array('X-Requested-With: XMLHttpRequest', 'X-Prototype-Version: 1.5.0'));
		$out = curl_exec($curl);
		if( $out === false && self::DEBUG ) error_log('cURL Error: '.curl_error($curl));
		curl_close($curl);

		// no sleep, no pic. IPMI needs some time... lol ;)
		sleep(1.5);

		$curl = $this->_curl_init(sprintf('%s/cgi/url_redirect.cgi?url_name=Snapshot&url_type=img&time_stamp=%s', $this->ipaddress, $timestamp), sprintf('http://%s/cgi/url_redirect.cgi?url_name=sys_info', $this->ipaddress));
		$out = curl_exec($curl);
		if( $out === false && self::DEBUG ) error_log('cURL Error: '.curl_error($curl));
		curl_close($curl);

		// "File Not Found"

		if( strlen($out) <= 10000 ) {    // error
			if( strpos($out, "<body onload= 'logout_alert()'>") ) {
				// session lost, start a new one
				$this->sessionID = '';
			}
			#error_log('Line '.__LINE__.': '.$out);
			return "data:image/png;base64,".base64_encode(file_get_contents('design/img/icons/icon_error.png'));
		}
		return "data:image/bmp;base64,".base64_encode($out);
	}

	/**
	 * Mounts a ISO file as CD-Rom Image
	 *
	 * @param string $host
	 * @param string $path
	 * @param string $user
	 * @param string $pass
	 * @return boolean
	 */
	public function setVirtualMedia($data) {
		if( empty($this->sessionID) && !$this->login() ) return false;

		if( !$this->ipmi_vendor ) $this->DetectVendor();

		if( $this->ipmi_vendor == 'AMI' ) {
			$data['RMEDIAENABLE'] = 1;
			$curl = $this->_curl_init($this->ipaddress.'/rpc/setmediacfg.asp', sprintf('http://%s/page/images_redirection.html', $this->ipaddress));
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
			$out1 = curl_exec($curl);
			if( $out1 === false && self::DEBUG ) error_log('cURL Error: '.curl_error($curl));
			curl_close($curl);

			$imagedata = array('MEDIA_TYPE' => 2, 'IMAGE_OPER' => 2, 'IMAGE_TYPE' => 'CD/DVD', 'IMAGE_NAME' => $data['image']);
			$curl = $this->_curl_init($this->ipaddress.'/rpc/setmediaimage.asp', sprintf('http://%s/page/images_redirection.html', $this->ipaddress));
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($imagedata));
			$out2 = curl_exec($curl);
			if( $out2 === false && self::DEBUG ) error_log('cURL Error: '.curl_error($curl));
			curl_close($curl);

			#error_log($out1.BR.$out2);
			// FIXME: Status Auswertung
			return true;
		} elseif( $this->ipmi_vendor == 'ATEN' ) {
			// FIRMWARE Typ A
			$curl = $this->_curl_init($this->ipaddress.'/cgi/virtual_media_share_img.cgi', sprintf('http://%s/cgi/url_redirect.cgi?url_name=vm_cdrom', $this->ipaddress));
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
			$out = curl_exec($curl);
			if( $out === false && self::DEBUG ) error_log('cURL Error: '.curl_error($curl));
			curl_close($curl);

			if( $out == "ok" ) return true;

			// FIRMWARE Typ B
			$data['op'] = 'config_iso';
			$curl = $this->_curl_init($this->ipaddress.'/cgi/op.cgi', sprintf('http://%s/cgi/url_redirect.cgi?url_name=vm_cdrom', $this->ipaddress));
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
			$out = curl_exec($curl);
			if( $out === false && self::DEBUG ) error_log('cURL Error: '.curl_error($curl));
			curl_close($curl);

			if( $out == "ok" ) return true;
			else return false;
		}
	}

	/**
	 * receive current VirtualMedia settings
	 *
	 * @return array
	 */
	public function getVirtualMedia() {
		if( empty($this->sessionID) && !$this->login() ) return false;

		if( !$this->ipmi_vendor ) $this->DetectVendor();

		if( $this->ipmi_vendor == 'AMI' ) {
			$curl = $this->_curl_init($this->ipaddress.'/rpc/getrmediacfg.asp');
			$out = curl_exec($curl);
			if( $out === false && self::DEBUG ) error_log('cURL Error: '.curl_error($curl));
			curl_close($curl);

			if( preg_match('/({.*}) ],/', $out, $match) ) {
				$obj = json_decode('['.str_replace('\'', '"', $match[1]).']');        // omfg, was zur hölle liefern die da für schrott
				foreach($obj as $part) {
					if( $part->IMG_TYPE == 'CD/DVD' ) {
						return (array)$part;
					}
				}
				return false;
			} else return false;
		} elseif( $this->ipmi_vendor == 'ATEN' ) {
			// FIRMWARE Typ A
			$curl = $this->_curl_init($this->ipaddress.'/cgi/ipmi.cgi', sprintf('http://%s/cgi/url_redirect.cgi?url_name=vm_cdrom', $this->ipaddress));
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query(array('VIRTUAL_MEDIA_SHARE_IMAGE.XML' => '(0,0)', 'time_stamp' => time())));
			$out = curl_exec($curl);
			if( $out === false && self::DEBUG ) error_log('cURL Error: '.curl_error($curl));
			curl_close($curl);

			$data = array('host' => '', 'path' => '', 'user' => '', 'pass' => '');
			if( preg_match('/HOST="([^"]+)"/', $out, $match) ) $data['host'] = $match[1];
			if( preg_match('/PATH="([^"]+)"/', $out, $match) ) $data['path'] = $match[1];
			if( preg_match('/USER="([^"]+)"/', $out, $match) ) $data['user'] = $match[1];
			if( preg_match('/PWD="([^"]+)"/', $out, $match) ) $data['pass'] = $match[1];
			if( !empty($data['host']) ) return $data;

			// FIRMWARE Typ B
			$curl = $this->_curl_init($this->ipaddress.'/cgi/ipmi.cgi', sprintf('http://%s/cgi/url_redirect.cgi?url_name=vm_cdrom', $this->ipaddress));
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query(array('op' => 'VIRTUAL_MEDIA_SHARE_IMAGE.XML', 'r' => '(0,0)')));
			$out = curl_exec($curl);
			if( $out === false && self::DEBUG ) error_log('cURL Error: '.curl_error($curl));
			curl_close($curl);

			if( substr($out, 0, 21) != '<?xml version="1.0"?>' ) return $data;    // firmware won't answer correct...
			$xml = simplexml_load_string($out);

			foreach($xml->VM->attributes() as $k => $v) {
				switch($k) {
					case 'HOST':
						$data['host'] = (string)$v;
					break;
					case 'PATH':
						$data['path'] = (string)$v;
					break;
					case 'USER':
						$data['user'] = (string)$v;
					break;
					case 'PWD':
						$data['pass'] = (string)$v;
					break;
				}
			}
			return $data;
		}
	}

	/**
	 * mount currently configured ISO image from Virtual Media
	 *
	 * @return string
	 */
	public function mountVirtualMedia() {
		if( empty($this->sessionID) && !$this->login() ) return false;
		if( !$this->ipmi_vendor ) $this->DetectVendor();

		if( $this->ipmi_vendor == 'AMI' ) return false;
		elseif( $this->ipmi_vendor == 'ATEN' ) {
			// FIRMWARE Typ A
			$curl = $this->_curl_init($this->ipaddress.'/cgi/isopin.cgi', sprintf('http://%s/cgi/url_redirect.cgi?url_name=vm_cdrom', $this->ipaddress));
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query(array('time_stamp' => time())));
			$out = curl_exec($curl);
			if( $out === false && self::DEBUG ) error_log('cURL Error: '.curl_error($curl));
			curl_close($curl);
			if( $out == 'VMCOMCODE=001' ) return $out;

			// FIRMWARE Typ B
			$curl = $this->_curl_init($this->ipaddress.'/cgi/op.cgi', sprintf('http://%s/cgi/url_redirect.cgi?url_name=vm_cdrom', $this->ipaddress));
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query(array('op' => 'mount_iso')));
			curl_setopt($curl, CURLOPT_HTTPHEADER, array('X-Requested-With: XMLHttpRequest', 'X-Prototype-Version: 1.5.0'));
			curl_setopt($curl, CURLOPT_HEADER, true);
			$out = curl_exec($curl);
			if( $out === false && self::DEBUG ) error_log('cURL Error: '.curl_error($curl));
			curl_close($curl);
			return $out;    // VMCOMCODE=001
		}
	}

	/**
	 * unmount currently mounted ISO image from Virtual Media
	 *
	 * @return string
	 */
	public function unmountVirtualMedia() {
		if( empty($this->sessionID) && !$this->login() ) return false;
		if( !$this->ipmi_vendor ) $this->DetectVendor();

		if( $this->ipmi_vendor == 'AMI' ) return false;
		elseif( $this->ipmi_vendor == 'ATEN' ) {
			// FIRMWARE Typ A
			$curl = $this->_curl_init($this->ipaddress.'/cgi/uisopout.cgi', sprintf('http://%s/cgi/url_redirect.cgi?url_name=vm_cdrom', $this->ipaddress));
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query(array('time_stamp' => time())));
			$out = curl_exec($curl);
			if( $out === false && self::DEBUG ) error_log('cURL Error: '.curl_error($curl));
			curl_close($curl);
			if( $out == 'VMCOMCODE=001' ) return $out;

			// FIRMWARE Typ B
			$curl = $this->_curl_init($this->ipaddress.'/cgi/op.cgi', sprintf('http://%s/cgi/url_redirect.cgi?url_name=vm_cdrom', $this->ipaddress));
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query(array('op' => 'umount_iso')));
			$out = curl_exec($curl);
			if( $out === false && self::DEBUG ) error_log('cURL Error: '.curl_error($curl));
			curl_close($curl);
			return $out;    // VMCOMCODE=001
		}
	}

	/**
	 * get current status of VirtualMedia, 255 means not mounted, 4 is ISO mounted
	 *
	 * @return boolean|array[id]=state
	 */
	public function getVirtualMediaStatus() {
		if( empty($this->sessionID) && !$this->login() ) return false;
		if( !$this->ipmi_vendor ) $this->DetectVendor();

		if( $this->ipmi_vendor == 'AMI' ) return false;
		elseif( $this->ipmi_vendor == 'ATEN' ) {
			$curl = $this->_curl_init($this->ipaddress.'/cgi/vmstatus.cgi', sprintf('http://%s/cgi/url_redirect.cgi?url_name=vm_cdrom', $this->ipaddress));
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query(array('time_stamp' => time())));
			$out = curl_exec($curl);
			if( $out === false && self::DEBUG ) error_log('cURL Error: '.curl_error($curl));
			curl_close($curl);

			$state = array();
			foreach(explode("\n", $out) as $line) {
				if( preg_match('/DEVICE ID="(\d+)" STATUS="(\d+)"/', $line, $match) ) {
					$state[(int)$match[1]] = (int)$match[2];
				}
			}
			if( count($state) > 0 ) return $state;
			else return false;
		}
	}

	public function getPrivateJavaPackage($clientIP = false, $timeout = 5) {
		if( !$this->ipmi_vendor ) $this->DetectVendor();

		if( $this->ipmi_vendor == 'AMI' ) $jnlp = $this->getJavaPackage_AMI();
		if( $this->ipmi_vendor == 'ATEN' ) $jnlp = $this->getJavaPackage_ATEN();

		// Rewrite Private IPs/Ports to Public IP/Ports
		$jnlp = str_replace($this->ipaddress, $this->proxyaddress, $jnlp);

		// get a free portrange (start+3) to spawn IPMI Proxy
		$privateport = $this->getRandomPort();
		if( !$privateport ) return false;

		// IPMI Webserver for JavaApplet Download
		$jnlp = str_replace(':80', ':'.$privateport, $jnlp);

		if( $this->ipmi_vendor == 'AMI' ) {
			// FIXME: https://www.thomas-krenn.com/de/wiki/Ukvm (maybe?)
			// VirtualMedia Port for IPMI
			$jnlp = str_replace('5120', ($privateport + 1), $jnlp);
			// RealVNC IPMI
			$jnlp = str_replace('5901', ($privateport + 2), $jnlp);
		} elseif( $this->ipmi_vendor == 'ATEN' ) {
			// VirtualMedia Port for IPMI
			$jnlp = str_replace('623', ($privateport + 1), $jnlp);
			// RealVNC IPMI
			$jnlp = str_replace('5900', ($privateport + 2), $jnlp);
			// Unbekannten weiteren Port - Firmware Revision : 02.18
			$jnlp = str_replace('3520', ($privateport + 3), $jnlp);
		}

		// socat version >= 1.7.2.0 required for max-children=10
		$socatopt = ',fork'.($clientIP != false ? ',range='.escapeshellarg($clientIP).'/32' : '');

		// HTTP Port Forwarding (Download Codebase Client .jar files)
		exec(sprintf('nohup timeout %dm socat -T 10 TCP4-LISTEN:%d%s TCP4:%s:80 > /dev/null 2>&1 & echo $!', $timeout, $privateport, $socatopt, escapeshellarg($this->ipaddress)));

		// Media Port Forwarding (CD-ROM Emulation in Client)
		exec(sprintf('nohup timeout %dm socat -T 10 TCP4-LISTEN:%d%s TCP4:%s:623 > /dev/null 2>&1 & echo $!', $timeout, ($privateport + 1), $socatopt, escapeshellarg($this->ipaddress)));

		// VNC Port Forwarding (remote KVM) MSS + MTU Discover to prevent VPN Problems (connection failed)
		exec(sprintf('nohup timeout %dm socat -T 10 TCP4-LISTEN:%d,mss=1300,mtudiscover=2%s TCP4:%s:5900 > /dev/null 2>&1 & echo $!', $timeout, ($privateport + 2), $socatopt, escapeshellarg($this->ipaddress)));

		// Unbekannten weiteren Port - ATEN Firmware Revision : 02.18
		exec(sprintf('nohup timeout %dm socat -T 10 TCP4-LISTEN:%d%s TCP4:%s:3520 > /dev/null 2>&1 & echo $!', $timeout, ($privateport + 3), $socatopt, escapeshellarg($this->ipaddress)));

		// Download JNLP Image
		ob_end_clean();
		header('Content-Description: File Transfer');
		header('Content-Type: application/x-java-applet');
		header('Content-Disposition: attachment; filename=ipmi.jnlp');
		header('Content-Transfer-Encoding: binary');
		header('Expires: 0');
		header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
		header('Pragma: public');
		header('Content-Length: '.strlen($jnlp));
		die($jnlp);
	}

	public function getJavaPackage_AMI() {
		if( empty($this->sessionID) && !$this->login() ) return false;

		$curl = $this->_curl_init($this->ipaddress.'/Java/jviewer.jnlp?EXTRNIP=%s&JNLPSTR=JViewer', sprintf('http://%s/page/dashboard.html', $this->ipaddress));
		$out = curl_exec($curl);
		if( $out === false && self::DEBUG ) error_log('cURL Error: '.curl_error($curl));
		curl_close($curl);
		return $out;
	}

	/**
	 * downloads a fresh KVM Console Java Package
	 */
	public function getJavaPackage_ATEN() {
		if( empty($this->sessionID) && !$this->login() ) return false;

		$curl = $this->_curl_init($this->ipaddress.'/cgi/url_redirect.cgi?url_name=ikvm&url_type=jwsk', sprintf('http://%s/cgi/url_redirect.cgi?url_name=sys_info', $this->ipaddress));
		$out = curl_exec($curl);
		if( $out === false && self::DEBUG ) error_log('cURL Error: '.curl_error($curl));
		curl_close($curl);
		return $out;
	}

	/**
	 * Search for free Ports to start IPMI Proxy on
	 *
	 * @return integer
	 */
	public function getRandomPort() {
		for($j = 0; $j < 5; $j++) {
			$randomPort = rand($this->proxyportmin, $this->proxyportmax - 4);
			if( $this->_testPort($randomPort) )
				return $randomPort;
		}
		return false;
	}

	/**
	 * Verify if a Port is currently not in use and can be used to bind for TCP Proxy
	 *
	 * @param inet    $ipAddress
	 * @param integer $port
	 * @return boolean
	 */
	private function _testPort($port) {
		for($i = 0; $i < 3; $i++) {
			$testSock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
			if( !socket_bind($testSock, $this->proxyaddress, $port + $i) ) return false;
			else socket_close($testSock);
		}
		return true;
	}
}

