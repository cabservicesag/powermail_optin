<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2008 Alexander Kellner <alexander.kellner@einpraegsam.net>
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/

require_once(t3lib_extMgm::extPath('powermail_optin') . 'lib/class.tx_powermail_optin_div.php'); // load div class
require_once(t3lib_extMgm::extPath('powermail') . 'lib/class.tx_powermail_functions_div.php'); // load div class of powermail

class tx_powermail_optin_submit extends tslib_pibase {
	
	var $prefixId      = 'tx_powermail_optin_pi1';		// Same as class name
	var $scriptRelPath = 'lib/class.tx_powermail_optin_submit.php';	// Path to this script relative to the extension dir.
	var $extKey        = 'powermail_optin';	// The extension key.
	var $pi_checkCHash = true;
	var $dbInsert = 1; // disable for testing only (db entry)
	var $sendMail = 1; // disable for testing only (emails)
	var $tsSetupPostfix = 'tx_powermailoptin.'; // Typoscript name for variables


	// Function PM_SubmitBeforeMarkerHook() to manipulate db entry
	function PM_SubmitBeforeMarkerHook(&$obj, $markerArray, $sessiondata) {
		
		// config
		global $TSFE;
    	$this->cObj = $TSFE->cObj; // cObject
		$this->conf = $obj->conf;
		$this->conf[$this->tsSetupPostfix] = $GLOBALS['TSFE']->tmpl->setup['plugin.'][$this->tsSetupPostfix];
		$this->confArr = $obj->confArr;
		$this->obj = $obj;
		$this->pi_loadLL();
		$this->sessiondata = $sessiondata;
		$this->div = t3lib_div::makeInstance('tx_powermail_optin_div'); // Create new instance for div class
		$this->receiver = $this->sessiondata[$this->obj->cObj->data['tx_powermail_sender']]; // sender email address
		$this->hash = $this->div->simpleRandString(); // Get random hash code
		$this->piVars = t3lib_div::GPvar('tx_powermail_pi1'); // get piVars
		
		// lets start
		if ( $obj->cObj->data['tx_powermailoptin_optin'] == 1 && t3lib_div::validEmail($this->receiver) ) { // only if optin is enabled in tt_content AND senderemail is set and valid email
			
			if (empty($this->piVars['optinuid'])) { // if optinuid is not set
				// disable emails and db entry from powermail
				$obj->conf['allow.']['email2receiver'] = 0; // disable email to receiver
				$obj->conf['allow.']['email2sender'] = 0; // disable email to sender
				$obj->conf['allow.']['dblog'] = 0; // disable database storing
				
				// write values to db with hidden = 1
				$this->saveMail();
				
				// send email to sender with confirmation link
				$this->sendMail();
			}	
			
			else { // optinuid is set - so go on with normal powermail => redirect
			
				$obj->conf['allow.']['dblog'] = 0; // disable database storing, because it was already stored
				
			}
				
		}
		
		return false; // no error return
	}
	
	
	// Function PM_SubmitLastOneHook() to change thx message to "confirmation needed" message
	function PM_SubmitLastOneHook(&$content, $conf, $sessiondata, $ok, $obj) {
		// config
		global $TSFE;
    	$this->cObj = $TSFE->cObj; // cObject
    	$this->obj = $obj;
		$this->conf = $conf;
		$this->sessiondata = $sessiondata;
		$this->pi_loadLL();
		$this->conf[$this->tsSetupPostfix] = $GLOBALS['TSFE']->tmpl->setup['plugin.'][$this->tsSetupPostfix];
		$this->div_pm = t3lib_div::makeInstance('tx_powermail_functions_div'); // Create new instance for div class of powermail
		$this->receiver = $this->sessiondata[$this->obj->cObj->data['tx_powermail_sender']]; // sender email address
		$this->piVars = t3lib_div::GPvar('tx_powermail_pi1'); // get piVars
		
		// let's start
		if ( $obj->cObj->data['tx_powermailoptin_optin'] == 1 && t3lib_div::validEmail($this->receiver) ) { // only if optin is enabled in tt_content AND senderemail is set and valid email
			if (empty($this->piVars['optinuid'])) { // if optinuid is not set
				$markerArray = array(); $tmpl = array(); // init
				$tmpl['confirmationmessage']['all'] = $this->cObj->getSubpart(tslib_cObj::fileResource($this->conf['tx_powermailoptin.']['template.']['confirmationmessage']), '###POWERMAILOPTIN_CONFIRMATIONMESSAGE###'); // Content for HTML Template
				$markerArray['###POWERMAILOPTIN_MESSAGE###'] = $this->pi_getLL('confirmation_message', 'Look into your mails - confirmation needed'); // mail subject;
				$content = $this->cObj->substituteMarkerArrayCached($tmpl['confirmationmessage']['all'], $markerArray); // substitute markerArray for HTML content
				$content = $this->div_pm->marker2value($content, $this->sessiondata); // ###UID34### to its value
				$content = preg_replace("|###.*?###|i", "", $content); // Finally clear not filled markers
			}
		}
		
	}
	
	
	// Function sendMail() to send confirmation link to sender
	function sendMail() {
	
		// Prepare mail content
		$this->markerArray = $this->tmpl = array(); // init
		$this->div_pm = t3lib_div::makeInstance('tx_powermail_functions_div'); // Create new instance for div class of powermail
		$this->tmpl['confirmationemail']['all'] = $this->cObj->getSubpart(tslib_cObj::fileResource($this->conf['tx_powermailoptin.']['template.']['confirmationemail']), '###POWERMAILOPTIN_CONFIRMATIONEMAIL###'); // Content for HTML Template
		$this->markerArray['###POWERMAILOPTIN_LINK###'] = ($GLOBALS['TSFE']->tmpl->setup['config.']['baseURL'] ? $GLOBALS['TSFE']->tmpl->setup['config.']['baseURL'] : 'http://'.$_SERVER['HTTP_HOST'].'/') . $this->cObj->typolink('x',array("returnLast"=>"url","parameter"=>$GLOBALS['TSFE']->id,"additionalParams"=>'&tx_powermail_pi1[optinhash]='.$this->hash.'&tx_powermail_pi1[optinuid]='.$this->saveUid,"useCacheHash"=>1)); // Link marker
		$this->markerArray['###POWERMAILOPTIN_HASH###'] = $this->hash; // Hash marker
		$this->markerArray['###POWERMAILOPTIN_MAILUID###'] = $this->saveUid; // uid of last saved mail
		$this->markerArray['###POWERMAILOPTIN_PID###'] = $GLOBALS['TSFE']->id; // pid of current page
		$this->markerArray['###POWERMAILOPTIN_LINKLABEL###'] = $this->pi_getLL('email_linklabel', 'Confirmationlink'); // label from locallang
		$this->markerArray['###POWERMAILOPTIN_TEXT1###'] = $this->pi_getLL('email_text1', 'Confirmationlink'); // label from locallang
		$this->markerArray['###POWERMAILOPTIN_TEXT2###'] = $this->pi_getLL('email_text2', 'Confirmationlink'); // label from locallang
		$this->mailcontent = $this->cObj->substituteMarkerArrayCached($this->tmpl['confirmationemail']['all'], $this->markerArray); // substitute markerArray for HTML content
		$this->mailcontent = $this->div_pm->marker2value($this->mailcontent, $this->sessiondata); // ###UID34### to its value
		$this->mailcontent = preg_replace("|###.*?###|i", "", $this->mailcontent); // Finally clear not filled markers
		
		// start main mail function
		$this->htmlMail = t3lib_div::makeInstance('t3lib_htmlmail'); // New object: TYPO3 mail class
		$this->htmlMail->start(); // start htmlmail
		$this->htmlMail->recipient = (t3lib_div::validEmail($this->conf['tx_powermailoptin.']['email.']['receiverOverwrite']) ? $this->conf['tx_powermailoptin.']['email.']['receiverOverwrite'] : $this->receiver); // main receiver email address
		$this->htmlMail->recipient_copy = (t3lib_div::validEmail($this->conf['tx_powermailoptin.']['email.']['cc']) ? $this->conf['tx_powermailoptin.']['email.']['cc'] : ''); // cc field (other email addresses from ts)
		$this->htmlMail->subject = ($this->conf['tx_powermailoptin.']['email.']['subjectoverwrite'] ? $this->conf['tx_powermailoptin.']['email.']['subjectoverwrite'] : $this->pi_getLL('email_subject', 'Confirmation needed') ); // mail subject
		$this->htmlMail->from_email = $this->obj->sender; // sender email address
		$this->htmlMail->from_name = $this->obj->sendername; // sender email name
		$this->htmlMail->returnPath = $this->obj->sender; // return path
		$this->htmlMail->replyto_email = ''; // clear replyto email
		$this->htmlMail->replyto_name = ''; // clear replyto name
		$this->htmlMail->charset = $GLOBALS['TSFE']->metaCharset; // set current charset
		$this->htmlMail->defaultCharset = $GLOBALS['TSFE']->metaCharset; // set current charset
		$this->htmlMail->addPlain($this->mailcontent);
		$this->htmlMail->setHTML($this->htmlMail->encodeMsg($this->mailcontent));
		if ($this->sendMail) {
			$this->htmlMail->send(t3lib_div::validEmail($this->conf['tx_powermailoptin.']['email.']['receiverOverwrite']) ? $this->conf['tx_powermailoptin.']['email.']['receiverOverwrite'] : $this->receiver);
		}
					
		if ($this->conf['tx_powermailoptin.']['debug'] == 1) { // if debug output enabled
			$d_array = array(
				'receiver' => (t3lib_div::validEmail($this->conf['tx_powermailoptin.']['email.']['receiverOverwrite']) ? $this->conf['tx_powermailoptin.']['email.']['receiverOverwrite'] : $this->receiver),
				'cc receiver' => (t3lib_div::validEmail($this->conf['tx_powermailoptin.']['email.']['cc']) ? $this->conf['tx_powermailoptin.']['email.']['cc'] : ''),
				'sender' => $this->obj->sender,
				'sender name' => $this->obj->sendername,
				'subject' => ($this->conf['tx_powermailoptin.']['email.']['subjectoverwrite'] ? $this->conf['tx_powermailoptin.']['email.']['subjectoverwrite'] : $this->pi_getLL('email_subject', 'Confirmation needed') ),
				'body' => $this->mailcontent
			);
			t3lib_div::debug($d_array, 'powermail_optin: Values in confirmation email');
		}
	}
	
	
	// Function saveMail() to save piVars and some more infos to DB (tx_powermail_mails) with hidden = 1
	function saveMail() {
		
		$pid = $GLOBALS['TSFE']->id; // current page
		if ($this->conf['PID.']['dblog'] > 0) $pid = $this->conf['PID.']['dblog']; // take pid from ts
		if ($this->obj->cObj->data['tx_powermail_pages'] > 0) $pid = $this->obj->cObj->data['tx_powermail_pages'];
		
		// DB entry for table Tabelle: tx_powermail_mails
		$db_values = array (
			'pid' => intval($pid), // PID
			'tstamp' => time(), // save current time
			'crdate' => time(), // save current time
			'hidden' => 1, // save as hidden
			'formid' => $this->obj->cObj->data['uid'],
			'recipient' => $this->obj->MainReceiver,
			'subject_r' => $this->obj->subject_r,
			'sender' => $this->obj->sender,
			'content' => $this->pi_getLL('database_content', 'No mailcontent: Double opt-in mail was send'), // message for "email-content" field
			'piVars' => t3lib_div::array2xml($this->sessiondata, '', 0, 'piVars'),
			'senderIP' => ($this->confArr['disableIPlog'] == 1 ? $this->pi_getLL('database_noip') : t3lib_div::getIndpEnv('REMOTE_ADDR')), // IP address if enabled
			'UserAgent' => t3lib_div::getIndpEnv('HTTP_USER_AGENT'),
			'Referer' => t3lib_div::getIndpEnv('HTTP_REFERER'),
			'SP_TZ' => $_SERVER['SP_TZ'],
			'tx_powermailoptin_hash' => $this->hash
		);
		
		if ($this->dbInsert) {
			if ($this->conf['tx_powermailoptin.']['debug'] == 1) { // if debug output enabled
				t3lib_div::debug($db_values, 'powermail_optin: Save this values to db');
			}
			$GLOBALS['TYPO3_DB']->exec_INSERTquery('tx_powermail_mails', $db_values); // DB entry
			$this->saveUid = $GLOBALS['TYPO3_DB']->sql_insert_id(); // Give me the uid if the last saved mail

		}
	}

}
if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/powermail_optin/lib/class.tx_powermail_optin_submit.php']) {
	include_once ($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/powermail_optin/lib/class.tx_powermail_optin_submit.php']);
}
?>