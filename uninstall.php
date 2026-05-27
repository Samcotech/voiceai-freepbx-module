<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

$pjsipCustom = '/etc/asterisk/pjsip_custom.conf';
$includeLine = '#include pjsip.voiceai.conf';

if (file_exists($pjsipCustom)) {
	$contents = file_get_contents($pjsipCustom);
	$contents = str_replace($includeLine, '', $contents);
	$contents = preg_replace("/\n{3,}/", "\n\n", $contents);
	file_put_contents($pjsipCustom, $contents);
}

$voiceaiConf = '/etc/asterisk/pjsip.voiceai.conf';
if (file_exists($voiceaiConf)) {
	unlink($voiceaiConf);
}

$agiScript = '/var/lib/asterisk/agi-bin/retell-bridge.php';
if (file_exists($agiScript)) {
	unlink($agiScript);
}
