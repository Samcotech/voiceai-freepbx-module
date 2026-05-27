<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

global $db;

$pjsipCustom = '/etc/asterisk/pjsip_custom.conf';
$includeLine = '#include pjsip.voiceai.conf';

if (file_exists($pjsipCustom)) {
	$contents = file_get_contents($pjsipCustom);
	if (strpos($contents, $includeLine) === false) {
		file_put_contents($pjsipCustom, rtrim($contents) . "\n\n" . $includeLine . "\n");
	}
} else {
	file_put_contents($pjsipCustom, $includeLine . "\n");
}

touch('/etc/asterisk/pjsip.voiceai.conf');
chown('/etc/asterisk/pjsip.voiceai.conf', 'asterisk');
chgrp('/etc/asterisk/pjsip.voiceai.conf', 'asterisk');

$agiBin = '/var/lib/asterisk/agi-bin';
$agiScripts = ['retell-bridge.php', 'ultravox-bridge.php'];
foreach ($agiScripts as $script) {
	$src = __DIR__ . '/agi/' . $script;
	$dst = $agiBin . '/' . $script;
	if (file_exists($src)) {
		copy($src, $dst);
		chmod($dst, 0755);
		chown($dst, 'asterisk');
		chgrp($dst, 'asterisk');
	}
}
