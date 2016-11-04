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

/**
 * Diese Klasse dient zum Berechnen von IPv4 und IPv6 IP Adressen
 *
 * @author        Martin Verges <martin@verges.cc>
 * @copyright     2005-2016
 * @version       4.0
 **/
class IP_Calc {
	/**
	 * Liefert den IP Typ (4 oder 6) der angegebenen IP
	 * sollte es keine IP sein wird false zurückgegeben
	 *
	 * @param string $ip IPv4 oder IPv6 IP OHNE CIDR
	 * @return mixed                4 = IPv4, 6 = IPv6, false = keine IP
	 */
	public function ip_is_ip($ip) {
		if( filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ) return 4;
		elseif( filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ) return 6;
		else return false;
	}

	/**
	 * Liefert den IP Typ (4 oder 6) der angegebenen IP in CIDR Notation
	 * sollte es keine IP sein wird false zurückgegeben
	 *
	 * @param string $ip IPv4 oder IPv6 IP MIT CIDR
	 * @return mixed                4 = IPv4, 6 = IPv6, false = keine IP
	 */
	public function ip_is_ipcidr($ip) {
		$ip = strstr($ip, '/', true);
		if( filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ) return 4;
		elseif( filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ) return 6;
		else return false;
	}

	/**
	 * Liefert eine gesäuberte IP (v4 oder v6) zurück
	 *
	 * @param string $ip IPv4 oder IPv6 [mit CIDR]
	 * @return string                IP [mit CIDR]
	 */
	public function ip_clean($ip) {
		if( $this->ip_is_ipcidr($ip) == 6 or $this->ip_is_ip($ip) == 6 ) {
			return $this->ipv6_clean($ip);
		} else {
			return $this->ipv4_clean($ip);
		}
	}

	/**
	 * Ermittelt die Distanz zwischen zwei IP Adressen ..1 zu ..10 ist 9 (1+9=10).
	 *
	 * @param $ip1
	 * @param $ip2
	 * @return bool|int
	 */
	public function ip_distance($ip1, $ip2) {
		if( $this->ip_is_ip($ip1) == 6 and $this->ip_is_ip($ip2) == 6 ) {
			return $this->ipv6_distance($ip1, $ip2);
		} elseif( $this->ip_is_ip($ip1) == 4 and $this->ip_is_ip($ip2) == 4 ) {
			return $this->ipv4_distance($ip1, $ip2);
		} else return false;
	}

	/**
	 * Prüft ob eine IPv4 oder IPv6 innerhalb einer CIDR liegt.
	 *
	 * @param string $ip   IPv4 oder IPv6 Adresse
	 * @param string $cidr CIDR in v4 oder v6
	 * @return boolean
	 */
	public function ip_in_cidr($ip, $cidr) {
		if( $this->ip_is_ipcidr($cidr) != $this->ip_is_ip($ip) ) return false;
		if( $this->ip_is_ip($ip) == 6 ) {
			return $this->ipv6_in_cidr($ip, $cidr);
		} else {
			return $this->ipv4_in_cidr($ip, $cidr);
		}
	}

	/**
	 * Prüft ob eine IPv4 CIDR oder IPv6 CIDR innerhalb einer CIDR liegt.
	 *
	 * @param string $net    CIDR in v4 oder v6 (das größere)
	 * @param string $search CIDR in v4 oder v6 (das kleinere)
	 * @return boolean
	 */
	public function cidr_in_cidr($net, $search) {
		if( $this->ip_is_ipcidr($search) === false ) return false;    // no valid
		if( $this->ip_is_ipcidr($net) != $this->ip_is_ipcidr($search) ) return false;    // ipv4 vs ipv6 - no way
		if( $this->ip_is_ipcidr($net) == 6 ) {
			echo "not yet implemented: ".__FILE__.' ('.__LINE__.')'.PHP_EOL;
		} else {
			$search_ip = $this->ip_from_cidr($search);
			$search_cidr = (int)substr($search, strpos($search, "/") + 1);
			$search_min = $this->ipv4_to_network($search_ip, $search_cidr);
			$search_max = $this->ipv4_to_broadcast($search_ip, $search_cidr);
			if( $this->ipv4_in_cidr($search_min, $net) && $this->ipv4_in_cidr($search_max, $net) ) {
				return true;
			} else {
				return false;
			}
		}
	}

	/**
	 * Liefert die Network IP einer CIDR z.b. 212.224.70.0/24 von 212.224.70.222/24
	 *
	 * @param string $cidr
	 * @return string|boolean
	 */
	public function get_network_from_cidr($cidr) {
		$typ = $this->ip_is_ipcidr($cidr);
		if( $typ == 4 ) {
			list($baseip, $len) = explode("/", $cidr);
			return $this->ipv4_to_network($baseip, $len).'/'.$len;
		} elseif( $typ == 6 ) {
			list($baseip, $len) = explode("/", $cidr);
			return $this->ipv6_to_network($baseip, $len).'/'.$len;
		} else return false;
	}

	/**
	 * Liefert die Broadcast IP einer CIDR z.b. 212.224.70.255/24 von 212.224.70.222/24
	 *
	 * @param string $cidr
	 * @return string|boolean
	 */
	public function get_broadcast_from_cidr($cidr) {
		$typ = $this->ip_is_ipcidr($cidr);
		if( $typ == 4 ) {
			list($baseip, $len) = explode("/", $cidr);
			return $this->ipv4_to_broadcast($baseip, $len).'/'.$len;
		} elseif( $typ == 6 ) {
			echo "not yet implemented: ".__FILE__.' ('.__LINE__.')'.PHP_EOL;
		} else return false;
	}


	/*************************************************************************************/
	/******                                 IPv4                                       ***/
	/*************************************************************************************/

	/**
	 * Errechnet alle relevanten informationen einer IPv4 (x.x.x.x/y)
	 *
	 * @param string $cidrip IPv4 CIDR (x.x.x.x/y)
	 * @return array                    address, gateway, netmask, ...
	 */
	public function ipv4_calculate($cidrip) {
		$cidr = (int)substr($cidrip, strpos($cidrip, "/") + 1);
		$ip = $this->ip_from_cidr($cidrip);

		if( !$this->ipv4_is_correct($ip) ) return false;

		return array(
			"address"   => $this->ipv4_clean($ip),
			"gateway"   => $this->ipv4_clean($this->ipv4_to_network($ip, $cidr), 1),
			"netmask"   => $this->ipv4_cidr_to_mask($cidr),
			"cidrmask"  => $cidr,
			"network"   => $this->ipv4_to_network($ip, $cidr),
			"hostmin"   => $this->ipv4_clean($this->ipv4_to_network($ip, $cidr), 2),
			"hostmax"   => $this->ipv4_clean($this->ipv4_to_broadcast($ip, $cidr), -1),
			"broadcast" => $this->ipv4_to_broadcast($ip, $cidr),
			"hostcount" => $this->ipv4_ipcount($cidrip) - 2,    // network/broadcast
		);
	}

	/**
	 * Rechnet einen Wert auf eine IP, z.b. um die 10. Adresse des Netzwerks zu bestimmen
	 *
	 * @param string  $ip  IPv4 Adresse
	 * @param integer $add Zusätzliche modifikation (ip+x)
	 * @return string                IPv4 Adresse
	 */
	public function ipv4_add_value($ip, $add = 0) {
		if( strstr($ip, '/') ) {
			return long2ip(ip2long(strstr($ip, '/', true)) + $add).strstr($ip, '/');
		} else
			return long2ip(ip2long($ip) + $add);
	}

	/**
	 * Gibt den IPv4 hostcount an (z.b. 256 bei /24)
	 *
	 * @param string $cidrip
	 * @return boolean|number
	 */
	public function ipv4_ipcount($cidrip) {
		$cidr = (int)substr($cidrip, strpos($cidrip, "/") + 1);
		return 1 << (32 - $cidr);
	}

	/**
	 * Ermittelt die Distanz zwischen einer IP und einer anderen. Zwischen ..1 und ..10 liegen 9 andere IPs (1+9=10)
	 *
	 * @param $ip1
	 * @param $ip2
	 * @return integer
	 */
	public function ipv4_distance($ip1, $ip2) {
		if( !$this->ipv4_is_correct($ip1) ) return false;
		if( !$this->ipv4_is_correct($ip2) ) return false;
		$ip1 = ip2long($ip1);
		$ip2 = ip2long($ip2);
		return $ip2 - $ip1;
	}


	/**
	 * Gibt eine IPv6 mapped IP von einer IPv4 zurück
	 *
	 * @param string $ip IPv4 192.168.0.1
	 * @return string                (::ffff:192.168.0.1)
	 */
	public function ipv4_to_ipv6($ip) {
		if( !$this->ipv4_is_correct($ip) ) return false;
		return "::ffff:".$this->ipv4_clean($ip);
	}

	/**
	 * Funktion zum ermitteln der Arpa Zone
	 *
	 * @param string $ip IPv4 192.168.0.1
	 * @return string                x.y.z.in-addr.arpa
	 */
	public function ipv4_to_arpa($ip) {
		$len = 0;
		if( $this->ip_is_ipcidr($ip) == 4 ) {            // Network based
			$net = $this->ipv4_calculate($ip);
			if( $net['cidrmask'] < 24 ) return false;    // we cannot report other then /24-/32
			elseif( $net['cidrmask'] == 24 ) {
				$p = explode(".", $net['network']);
				return $p[2].".".$p[1].".".$p[0].".in-addr.arpa";
			} else {
				$p = explode(".", $net['network']);
				$b = explode('.', $net['broadcast']);
				$min = $p[3];
				$max = $b[3];
				return $min."-".$max.".".$p[2].".".$p[1].".".$p[0].".in-addr.arpa";
			}
		} elseif( $this->ip_is_ip($ip) == 4 ) {            // IP based
			$p = explode(".", $this->ipv4_clean($ip));
			if( count($p) != 4 ) return false;
			return $p[2].".".$p[1].".".$p[0].".in-addr.arpa";
		}
	}

	/**
	 * Reduziert die IPv4 auf kürzeste schreibweise
	 * von 192.186.000.001 auf 192.168.0.1
	 * behandelt auch /24 CIDR Angaben korrekt
	 *
	 * @param string  $ip  IPv4 Adresse
	 * @param integer $add Zusätzliche modifikation (long+x)
	 * @return string                IPv4 Adresse
	 */
	public function ipv4_clean($ip, $add = 0) {
		if( strstr($ip, '/') ) {
			return long2ip(ip2long(strstr($ip, '/', true)) + $add).strstr($ip, '/');
		} else
			return long2ip(ip2long($ip) + $add);
	}

	/**
	 * errechnet das CIDR (/24) einer Mask (255.255.255.0)
	 *
	 * @param string $mask Netmask 255.255.255.0
	 * @return integer                24
	 */
	public function ipv4_mask_to_cidr($mask) {
		return strlen(trim(decbin(ip2long($mask)), "0"));
	}

	/**
	 * errechnet aus einer CIDR (/24) eine Mask (255.255.255.0)
	 *
	 * @param integer $cidr 24
	 * @return string                Netmask 255.255.255.0
	 */
	public function ipv4_cidr_to_mask($cidr) {
		if( $cidr < 1 or $cidr > 32 ) return false;
		return long2ip(0xffffffff << (32 - (int)$cidr));
	}

	/**
	 * Diese Funktion gibt eine gültige IPv4 IP (x.x.x.x) von einer CIDR IP (x.x.x.x/y) zurück
	 *
	 * @param string                IPv4 CIDR (x.x.x.x/y)
	 * @return string            IPv4 im Format (x.x.x.x)
	 **/
	public function ip_from_cidr($ip_in_cidr) {
		$tmp = explode("/", $ip_in_cidr);
		return $this->ipv4_clean(array_shift($tmp));
	}

	/**
	 * Diese Funktion prüft den Syntax einer IPv4 Adresse (x.x.x.x)
	 * gibt auch true bei CIDR Notation zurück (x.x.x.x/y)
	 *
	 * @param string $ip IP Adresse die geprüft werden soll
	 * @return bool                true wenn der IPv4 Syntax (x.x.x.x) stimmt
	 **/
	public function ipv4_is_correct($ip) {
		if( strstr($ip, '/') ) {
			return (boolean)filter_var(strstr($ip, '/', true), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
		} else
			return (boolean)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
	}

	/**
	 * Diese Funktion prüft ob eine IP (x.x.x.x) innerhalb eines CIDR (x.x.x.x/y) liegt
	 *
	 * @param string $ip   IP Adresse die geprüft werden soll
	 * @param mixed  $cidr ein oder mehrere (array) CIDR Netze
	 * @return bool                true or false (false also on error)
	 **/
	public function ipv4_in_cidr($ip, $cidrip) {
		if( !$this->ipv4_is_correct($ip) ) return false;
		if( is_array($cidrip) ) {
			foreach($cidrip as $part) {
				list($net, $len) = explode('/', $part);
				$r = ip2long($this->ipv4_to_network($ip, $len)) == ip2long($this->ipv4_to_network($net, $len));
				if( $r == true ) return true;
			}
			return false;
		} else {
			list($net, $len) = explode('/', $cidrip);
			return ip2long($this->ipv4_to_network($ip, $len)) == ip2long($this->ipv4_to_network($net, $len));
		}
	}

	/**
	 * Liefert ein Array mit allen IPs eines Subnetz
	 *
	 * @param string $cidrip Netzwerk das in einzelne IPs zerlegt werden soll
	 * @return mixed                false or array (1.1.1.1, 1.1.1.2, 1.1.1.3, ...)
	 */
	public function ipv4_list_addresses($cidrip) {
		if( !$this->ipv4_is_correct($cidrip) ) return false;

		$cidr = (int)substr($cidrip, strpos($cidrip, "/") + 1);
		if( $cidr < 20 ) return false;        // to much memory usage for sure!
		$ip = $this->ip_from_cidr($cidrip);

		$low = ip2long($this->ipv4_to_network($ip, $cidr));
		$high = ip2long($this->ipv4_to_broadcast($ip, $cidr));

		$out = array();
		while($low <= $high) {
			$out[] = long2ip($low);
			$low++;
		}
		return $out;
	}

	/**
	 * Errechnet den Wert für die Netzwerkadresse einer IP innerhalb einer bitmask
	 *
	 * @param string  $baseip 192.168.0.20
	 * @param integer $len    z.b. 24 für /24
	 * @return long                    Network IP -> 192.168.0.0
	 */
	public function ipv4_to_network($baseip, $len = false) {
		if( !$len ) {
			if( $this->ip_is_ipcidr($baseip) == 4 ) {
				list($baseip, $len) = explode("/", $baseip);
			}
		}
		return long2ip(ip2long($baseip) & (0xffffffff << (32 - (int)$len)));
	}

	/**
	 * Errechnet den Wert für die Broadcast Adresse einer IP innerhalb einer bitmask
	 *
	 * @param string  $baseip 192.168.0.20
	 * @param integer $len    z.b. 24 für /24
	 * @return long                    Network IP -> 192.168.0.0
	 */
	public function ipv4_to_broadcast($baseip, $len) {
		return long2ip(ip2long($baseip) | ~(0xffffffff << (32 - (int)$len)) & 0xffffffff);
	}

	/**
	 * Ermittelt die größte Netmask die das Network nicht verschiebt
	 * (die nächste möglichst große Subnetz wird ermittelt)
	 *
	 * @param string $ip lower (starting address)
	 * @param string $up upper (limiting address)
	 * @return 0-32|false    false if no slot or error
	 **/
	function find_next_subnet($ip, $up = false) {
		$ip = ip2long($ip);
		$up = ($up == false ? 0xffffffff : ip2long($up));
		for($i = 0; $i <= 32; $i++) {
			$network = $ip & (0xffffffff << $i);                        // calculate network address from /$i mask
			$bcast = $network | ~(0xffffffff << $i) & 0xffffffff;     // calculate broadcast address from /$i mask
			if( $network != $ip or $bcast > $up ) {
				if( $i == 0 ) return false;                             // $i = 0 means there is no free slot, $ip and $up are without free space
				if( $network != $ip ) {
					return array((32 - $i + 1), $bcast);
				} else {
					$i--;                                               // Recalculate broadcast to lower value
					$bcast = $network | ~(0xffffffff << $i) & 0xffffffff;
					return array((32 - $i), $bcast);
				}
			}
		}
		return false;
	}



	/*************************************************************************************/
	/******                                 IPv6                                       ***/
	/*************************************************************************************/

	/**
	 * Diese Funktion prüft den Syntax einer IPv6 Adresse (2a01:7e0::1)
	 * gibt auch true bei CIDR Notation zurück (2a01:7e0::1/64)
	 *
	 * @return bool                true wenn der IPv6 Syntax (2a01:7e0::1) stimmt
	 **/
	public function ipv6_is_correct($ip) {
		if( strstr($ip, '/') ) {
			return (boolean)filter_var(strstr($ip, '/', true), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
		} else
			return (boolean)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
	}

	/**
	 * Gibt eine IPv4 IP von einer IPv6 mapped IP zurück
	 *
	 * @param string $ip IPv6 Mapped (::ffff:192.168.0.1)
	 * @return string                192.168.0.1
	 */
	public function ipv6_to_ipv4($ip) {
		if( !$this->ipv6_is_correct($ip) ) return false;
		if( preg_match('/^[a-f0-9:]+((\d+)\.(\d+)\.(\d+)\.(\d+))$/', $ip, $match) ) {
			return long2ip(ip2long($match[1]));
		}
	}

	/**
	 * Errechnet alle relevanten informationen einer IPv6 (2a01:7e0::1/64)
	 *
	 * @param string $cidripv6 IPv6 CIDR (2a01:7e0::1/64)
	 * @return array                    address, fullip, gateway, network, cidrmask
	 */
	public function ipv6_calculate($cidripv6) {
		$parts = explode("/", $cidripv6);
		if( count($parts) != 2 ) return false;
		$cidr = (int)$parts[1];
		$ip = $this->ipv6_clean($parts[0]);

		if( !$this->ipv6_is_correct($ip) ) return false;

		return array(
			"address"  => $ip,
			"fullip"   => $this->ipv6_full($ip),
			"gateway"  => $this->ipv6_to_gateway($ip, $cidr),
			"network"  => $this->ipv6_to_network($ip, $cidr),
			"arpa"     => $this->ipv6_arpa($ip, $cidr),
			"cidrmask" => $cidr
		);
	}

	/**
	 * Ermittelt die Distanz zwischen einer IP und einer anderen. Zwischen ..1 und ..10 liegen 9 andere IPs (1+9=10)
	 *
	 * @param $ip1
	 * @param $ip2
	 * @return integer
	 */
	public function ipv6_distance($ip1, $ip2) {
		if( !$this->ipv6_is_correct($ip1) ) return false;
		if( !$this->ipv6_is_correct($ip2) ) return false;

		$ip1_gmp = gmp_init($this->ipv6_full($ip1, true), 16);
		$ip2_gmp = gmp_init($this->ipv6_full($ip2, true), 16);
		$diff = gmp_sub($ip2_gmp, $ip1_gmp);
		return gmp_intval($diff);
	}


	/**
	 * Liefert zu einer IPv6 den passenden rDNS Zonennamen
	 *
	 * @param string  $ip
	 * @param integer $cidr
	 * @return string
	 */
	public function ipv6_arpa($ip, $cidr) {
		$full = $this->ipv6_full($ip, true);        // 2a0107e0000000000000000000000000
		$net = substr($full, 0, (128 - $cidr) / 4);    // 2a0107e000000000
		return implode(".", str_split(strrev($net))).'.ip6.arpa';
	}

	/**
	 * Gibt die kürzeste schreibweise für eine IPv6 IP aus
	 * behandelt auch CIDR Notation korrekt
	 *
	 * @param string $ip IPv6 (2a01:07e0:0000:0000:0000:0000:0000:0001)
	 * @return string                    IPv6 (2a01:7e0::1)
	 */
	public function ipv6_clean($ip) {
		$ip_addr = $this->ipv6_full($ip);

		if( strstr($ip_addr, '/') ) $ip_addr = inet_ntop(inet_pton(strstr($ip_addr, '/', true))).strstr($ip_addr, '/');
		else						$ip_addr = inet_ntop(inet_pton($ip_addr));

		// Find largest zero chunk and replace it with "::" once
		// Längsten Null-Teil einmal mit "::" ersetzen
		$ip_addr = preg_replace('_^0:0:0:0:0:0:0:0$_', '::', $ip_addr, 1);
		if( strpos($ip_addr, '::') === false ) $ip_addr = preg_replace('_(^|:)0:0:0:0:0:0:0(:|$)_', '::', $ip_addr, 1);
		if( strpos($ip_addr, '::') === false ) $ip_addr = preg_replace('_(^|:)0:0:0:0:0:0(:|$)_', '::', $ip_addr, 1);
		if( strpos($ip_addr, '::') === false ) $ip_addr = preg_replace('_(^|:)0:0:0:0:0(:|$)_', '::', $ip_addr, 1);
		if( strpos($ip_addr, '::') === false ) $ip_addr = preg_replace('_(^|:)0:0:0:0(:|$)_', '::', $ip_addr, 1);
		if( strpos($ip_addr, '::') === false ) $ip_addr = preg_replace('_(^|:)0:0:0(:|$)_', '::', $ip_addr, 1);
		if( strpos($ip_addr, '::') === false ) $ip_addr = preg_replace('_(^|:)0:0(:|$)_', '::', $ip_addr, 1);
		if( strpos($ip_addr, '::') === false ) $ip_addr = preg_replace('_(^|:)0(:|$)_', '::', $ip_addr, 1);

		return $ip_addr;
	}

	/**
	 * Gibt eine IPv6 in voller schreibweise zurück
	 *
	 * @param string  $ip    IPv6 z.B. 2a01:7e0::1
	 * @param boolean $clean ob mit oder ohne : ausgegeben werden soll
	 * @return string                    2a01:07e0:0000:0000:0000:0000:0000:0001
	 */
	public function ipv6_full($ip, $clean = false) {
		if( !$this->ipv6_is_correct($ip) ) return false;

		// CIDR given? fixt it
		if( strstr($ip, '/') ) $addr = str_split(inet_pton(strstr($ip, '/', true)));
		else                        $addr = str_split(inet_pton($ip));

		$output = "";
		$i = 0;
		foreach($addr as $char) {
			$i++;
			$output .= str_pad(dechex(ord($char)), 2, '0', STR_PAD_LEFT);
			if( !$clean and $i <= 15 and ($i % 2) == 0 ) $output .= ":";
		}
		return $output;
	}

	/**
	 * Rechnet einen Wert auf eine IP, z.b. um die 8. Adresse des Netzwerks zu bestimmen
	 *
	 * @param  string $baseip 2a01:7e0::ab00
	 * @param  int    $change 8
	 * @return ipv6_full            Network IP -> 2a01:07e0:0000:0000:0000:0000:0000:ab08
	 */
	public function ipv6_add_value($baseip, $change = 0) {
		if( $change == 0 ) return $this->ipv6_full($baseip);

		$val = gmp_init($this->ipv6_full($baseip, true), 16);
		if( $change > 0 ) $val = gmp_add($val, $change);
		elseif( $change <= 0 ) $val = gmp_sub($val, $change);

		return $this->__ipv6_format(gmp_strval($val, 16));
	}

	/**
	 * Errechnet den Wert für die Netzwerkadresse einer IP innerhalb einer CIDR
	 *
	 * @param string  $baseip 2a01:7e0::abcd
	 * @param integer $len    z.b. 64 für /64
	 * @return ipv6_full            Network IP -> 2a01:07e0:0000:0000:0000:0000:0000:0000
	 */
	public function ipv6_to_network($baseip, $len) {
		return $this->__ipv6_format(
			gmp_strval(
				gmp_and(
					gmp_init($this->ipv6_full($baseip, true), 16),
					gmp_init(str_pad(str_repeat("1", $len), 128, "0", STR_PAD_RIGHT), 2)
				),
				16
			)
		);
	}

	/**
	 * Errechnet das Gateway für die Netzwerkadresse einer IP innerhalb einer CIDR
	 *
	 * @param string  $baseip 2a01:7e0::abcd
	 * @param integer $len    z.b. 64 für /64
	 * @return ipv6_full            Network IP -> 2a01:07e0:0000:0000:0000:0000:0000:0001
	 */
	public function ipv6_to_gateway($baseip, $len) {
		$net = $this->ipv6_to_network($baseip, $len);
		return $this->ipv6_add_value($net, 1);
	}

	/**
	 * Diese Funktion prüft ob eine IPv6 innerhalb eines CIDRv6 liegt
	 *
	 * @param string $ip   IP Adresse die geprüft werden soll
	 * @param mixed  $cidr CIDR Netz
	 * @return bool                true or false (false also on error)
	 **/
	public function ipv6_in_cidr($ip, $cidrip) {
		if( !$this->ipv6_is_correct($ip) ) return false;
		list($net, $len) = explode('/', $cidrip);
		return $this->ipv6_to_network($ip, $len) === $this->ipv6_to_network($net, $len);
	}

	/**
	 * fügt in eine clean ipv6 (2a0107e0000000000000000000000000) die doppelpunkte ein
	 *
	 * @param string $ipv6clean
	 * @return mixed
	 */
	protected function __ipv6_format($ipv6clean) {
		if( strlen($ipv6clean) != 32 ) $ipv6clean = $this->ipv6_full($ipv6clean, true);
		if( $ipv6clean === false ) return false;
		return wordwrap($ipv6clean, 4, ":", true);
	}
}

?>
