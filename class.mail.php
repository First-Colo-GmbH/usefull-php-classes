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
* Net_Send_Email
*
* Diese Klasse dient zum Versenden von E-Mails mit Dateianhang mittels der PHP Funktion.
* für umfangreichere Mailgeschichten rate ich ab.
*
* @author         Martin Verges <martin@verges.cc>
* @version        1.1
* @package        Net_Send_Email
**/
class Net_Send_Email {

	protected $mail_headers;
	protected $mail_text;
	protected $mail_to;
	protected $mail_reply_to;
	protected $mail_return_path;
	protected $mail_betreff;
	protected $mail_filecontent;
	protected $mail_boundary;
	protected $mail_bcc;
	protected $mail_from;
	public    $mail_generated;
	public    $mail_content = "text/plain"; // text/html
	public    $mail_charset = "utf-8";

	public function __construct($charset = "utf-8", $from = NULL, $to = NULL, $bcc = NULL, $betreff = NULL, $text = NULL) {
		if( defined("PAGE_DEBUG_MODE") and PAGE_DEBUG_MODE ) {
			if( function_exists("debug_text") ) debug_text("Class -> ".get_class($this).": Loaded");
		}
		$this->mail_boundary = strtoupper( md5( uniqid( time() ) ) );
		$this->mail_headers = "X-Mailer: PHP v".phpversion()."\nX-Mailer-Author: Martin Verges <php@verges.cc>\n";

		$this->mail_charset = $charset;
		$this->mail_from    = $from;
		$this->mail_to      = $to;
		$this->mail_bcc     = $bcc;
		$this->mail_text    = $text;
		$this->mail_betreff = $betreff;
	}

	/**
	 * This function generates the Mail Content and can be used for SQL Buffering or other Stuff
	 * Used in $this->send_mail();
	 * 
	 * @return string		MAIL_HEADERS
	 */
	public function generate_mail() {
		$parts = $this->generate_mail_parts();
		return $parts['mail_header'].$parts['mail_text'];
	}
	
	/**
	 * This function generates the Mail Content and can be used for SQL Buffering or other Stuff
	 * Used in $this->send_mail();
	 *
	 * @return string		MAIL_HEADERS
	 */
	public function generate_mail_parts() {
		$mail_header = '';
		// if specified, set "Return-Path" header
		if( !empty($this->mail_return_path) ) $mail_header .= "Return-Path: ".$this->mail_return_path."\n";
		
		
		$mail_header .= "From: ".$this->mail_from."\n";
		if( !empty($this->mail_bcc) )		$mail_header .= "BCC: ".$this->mail_bcc."\n";
		if( !empty($this->mail_reply_to) )	$mail_header .= "Reply-To: ".$this->mail_reply_to."\n";
		$mail_header .= $this->mail_headers;						// additional Headers
		// Beginne den Mail-Header mit MIME-Mail-Header
		$mail_header .= "MIME-Version: 1.0\n";
		$mail_header .= "Content-Type: multipart/mixed; boundary=\"".$this->mail_boundary."\"\n";
		
		// Beginne dem Mail-Text
		$mail_text = "\n";
		$mail_text .= "This is a multi-part message in MIME format  --  Dies ist eine mehrteilige Nachricht im MIME-Format\n";
		/* Hier faengt der normale Mail-Text an */
		$mail_text .= "--".$this->mail_boundary."\n";
		$mail_text .= "Content-type: ".$this->mail_content."; charset=".$this->mail_charset."\n";
		$mail_text .= "Content-Transfer-Encoding: 8bit\n";
		$mail_text .= "\n";
		$mail_text .= $this->mail_text;						// Mailtext eingeben
		$mail_text .= "\n";
		$mail_text .= $this->mail_filecontent;				// Dateien anhängen
		$mail_text .= "\n";
		$mail_text .= "--".$this->mail_boundary."--";			// Ende der Mail
		
		$this->mail_generated = array('mail_header'=>$mail_header, 'mail_text'=>$mail_text);
		return $this->mail_generated;
	}

	/**
	 * Verschickt die E-Mail mit zuvor definierten Parametern
	 */
	public function send_mail() {
		$this->generate_mail_parts();
		return mail( $this->mail_to, $this->mail_betreff, $this->mail_generated['mail_text'], $this->mail_generated['mail_header']);
	}

	/**
	* Fügt eine Datei zu
	*
    * @param  string $header	Header nach RFC oder mail(,,,header)
	*/
	public function add_file($dateiname, $inhalt, $type="text/plain") {
		/* Hier faengt der Datei-Anhang an */
		$mail_anhang  = "\n--".$this->mail_boundary;
		$mail_anhang .= "\nContent-Type: ".$type."; name=\"".$dateiname."\"";
		$mail_anhang .= "\nContent-Transfer-Encoding: base64";
		$mail_anhang .= "\nContent-Disposition: attachment; filename=\"".$dateiname."\"";
		$mail_anhang .= "\n\n".chunk_split(base64_encode($inhalt));
		$this->mail_filecontent .= $mail_anhang;
	}

	/**
	* Setzt den MailHeader
	*
    * @param  string $header	Header nach RFC oder mail(,,,header)
	*/
	public function set_header($header) {
		$header = trim($header);
		$this->mail_headers .= $header."\n";
	}

	/**
	* Setzt den Mail Absender
	*
    * @param  string $header	Header nach RFC oder mail(,,,header)
	*/
	public function set_from($from) {
		$this->mail_from = $from;
	}

	/**
	* Setzt den MailText
	*
    * @param  string $text	Header nach RFC oder mail(,,text,)
	*/
	public function set_text($text) {
		$this->mail_text = $text;
	}

	/**
	* Setzt den MailBetreff
	*
    * @param  string $text	Header nach RFC oder mail(,betreff,,)
	*/
	public function set_betreff($betreff) {
		$this->mail_betreff = $betreff;
	}

	/**
	* Setzt den MailText
	*
    * @param  string $text	Header nach RFC oder mail(to,,,)
	*/
	public function set_to($to) {
		$this->mail_to = $to;
	}

	/**
	* Setzt den BCC Empfänger
	*
    * @param  string $bcc	Email BCC Empfänger
	*/
	public function set_bcc($bcc) {
		$this->mail_bcc = $bcc;
	}

	/**
	 * Setzt den reply-to Header
	 *
	 * @param  string $mail		reply-to Header
	 */
	public function set_reply_to($mail) {
		$this->mail_reply_to = $mail;
	}
	
	/**
	 * Setzt den return-path Header
	 *
	 * @param  string $mail		return-path Header
	 */
	public function set_return_path($mail) {
		$this->mail_return_path = $mail;
	}	

}

