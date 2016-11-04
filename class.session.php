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
 * Session handling made easy
 *
 * @author Martin Verges <php@verges.cc>
 * @copyright Martin Verges - 2007-2016
 * @version 2.1
 */
class Small_Session {
	/*
	 * remember last used session name to allow $session->restart() to work propperly
	 */
	public $lastsessionname = '';

	/**
	 * Constructor zum Session Start
	 *
	 * @param bool|string $name Name der Session
	 * @return Small_Session false on error
	 */
	public function Small_Session($name=false) {
		if( $this->header_check() ) {
			if( !$name and !empty($this->lastsessionname) ) {
				$this->start($this->lastsessionname);
			} elseif( $name ) {
				$this->lastsessionname = $name;
				$this->start($name);
			} else {
				$this->start('mysession');
			}

			$x = $this->get_var("session_ip");
			if( $x === false ) {
				$this->set_var("session_ip", $_SERVER['REMOTE_ADDR']);
			}

			if( $this->get_var("session_ip") != $_SERVER['REMOTE_ADDR'] ) {
				$this->destroy();
				$this->terminate(); // Leads to 'die();' call.
			}
			else if( $this->get_var("session_language") === false ) {
				$this->set_var("session_language", "de");
				return true;
			}
			else {
				return true;
			}
		} else {
			return false;					// Sorry, geht nicht, bitte Abbruch erzeugen
		}
	}

	/**
	 * Startet (LOCK) die Session.
	 *
	 * @param string  $name   Name der Session
	 */
	public function start($name) {
		if( $name ) session_name($name);
		session_start();
	}

	/**
	 * Stopt (UNLOCK) die Session.
	 *
	 * @param string  $name   Name der Session
	 */
	public function stop() {
		session_write_close();
	}


	/**
	 * Startet die Session erneut (alle Daten werden gelöscht)
	 *
	 * @return void
	 */
	public function restart() {
		$this->destroy();
		$this->Small_Session();
	}

	/**
	 * Prüft ob eine Session aktiv ist.
	 *
	 * @return boolean
	 */
	function is_session_started() {
		if ( php_sapi_name() !== 'cli' ) {
			if ( version_compare(phpversion(), '5.4.0', '>=') )
				return session_status() === PHP_SESSION_ACTIVE ? true : false;
			else
				return session_id() === '' ? false : true;
		}
		return false;
	}

	/**
	 * Beendet die aktuelle Session und löscht alle darin enthaltenen Informationen
	 *
	 * @return void
	 */
	public function destroy() {							// Session zerstören
		if( !$this->is_session_started() ) $this->start();
		session_unset();								// Session Variablen removen
		session_destroy();								// Session killen

		// if we unset whole $_SESSION, a new session cannot be established in runtime environment
		foreach($_SESSION as $k=>$v) unset($_SESSION[$k]);
	}

	/**
	 * Prüft ob eine Variable bereits gesetzt wurde
	 *
	 * @param string $var
	 * @return boolean
	 */
	public function is_set($var) {							// Wurde die Variable bereits gesetzt ?
		return isset($_SESSION[$var]);						// Rückmeldung ob das schon drin ist
	}

	/**
	 * Setzt eine Variable innerhalb der Session
	 *
	 * @param string $var
	 * @return boolean
	 */
	public function set_var($var, $value) {										// Variablen Setzen
		if( !$this->is_session_started() ) $this->start();
		$_SESSION[$var] = $value;												// Variable setzen
		return true;															// Erfolg zurückmelden
	}

	/**
	 * Hohlt daten einer Session Variable
	 *
	 * @param string $var
	 * @return mixed
	 */
	public function get_var( $var ) {					// Variable auslesen
		if( isset( $_SESSION[$var] ) )
			return $_SESSION[$var];						// Wert der Variable zurückmelden
		else return false;
	}

	/**
	 * Löscht eine Session Variable
	 *
	 * @param string $var
	 * @return boolean
	 */
	public function del_var( $var ) {					// Variable löschen
		if( isset($_SESSION[$var]) ) 					// Existiert noch was in der Superglobalen?
			unset($_SESSION[$var]);						// dann weg damit
		return true;									// Rückmeldung
	}

	/**
	 * Prüft auf bereits gesendete Header (also ob oder ob nicht der Header modifizierbar ist)
	 *
	 * @return boolean
	 */
	protected function header_check() {
		return !headers_sent();
	}

	/**
	 * Beendet das Programm im Fehlefall mit der entsprechenden Nachricht.
	 *
	 * @return null
	 */
	protected function terminate() {
		die(_('Entschuldigung, die Session ist abgebrochen. Bitte versuchen Sie es erneut oder prüfen Sie die aufgerufende URL bzw. den Weg der Sie hierher brachte.'));
	}
}

