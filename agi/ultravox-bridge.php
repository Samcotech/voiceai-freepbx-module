#!/usr/bin/env php
<?php
// AGI script for Ultravox per-call SIP registration
// Called as: AGI(ultravox-bridge.php,agent_id,api_key)

$env = [];
$stdin = fopen('php://stdin', 'r');

while (($line = fgets($stdin)) !== false) {
	$line = trim($line);
	if ($line === '') break;
	if (preg_match('/^agi_(\w+):\s*(.*)$/', $line, $m)) {
		$env[$m[1]] = $m[2];
	}
}

function agi_send($cmd) {
	fwrite(STDOUT, $cmd . "\n");
	fflush(STDOUT);
}

function agi_read() {
	global $stdin;
	$line = fgets($stdin);
	return $line !== false ? trim($line) : '';
}

function agi_verbose($msg) {
	agi_send("VERBOSE \"{$msg}\" 1");
	agi_read();
}

function agi_set_var($name, $value) {
	agi_send("SET VARIABLE {$name} \"{$value}\"");
	agi_read();
}

$agentId = $env['arg_1'] ?? '';
$apiKey = $env['arg_2'] ?? '';

agi_verbose("VoiceAI Ultravox: agent_id=[{$agentId}] apikey_len=" . strlen($apiKey));

if (empty($agentId) || empty($apiKey)) {
	agi_set_var('VOICEAI_RESULT', 'ERROR');
	agi_set_var('VOICEAI_ERROR', 'Missing agent ID or API key');
	exit(0);
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.ultravox.ai/api/agents/{$agentId}/calls");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
	"X-API-Key: {$apiKey}",
	'Content-Type: application/json',
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
	'medium' => ['sip' => ['incoming' => new \stdClass()]],
	'firstSpeakerSettings' => ['agent' => new \stdClass()],
	'initialOutputMedium' => 'MESSAGE_MEDIUM_VOICE',
]));

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
	agi_set_var('VOICEAI_RESULT', 'ERROR');
	agi_set_var('VOICEAI_ERROR', "CURL: {$error}");
	agi_verbose("VoiceAI Ultravox: CURL error - {$error}");
	exit(0);
}

$data = json_decode($response, true);
if ($httpCode >= 400 || empty($data['joinUrl'])) {
	$msg = $data['message'] ?? $data['error'] ?? substr($response, 0, 200);
	if ($httpCode < 400 && empty($data['joinUrl'])) {
		$msg = 'No joinUrl in response';
	}
	agi_set_var('VOICEAI_RESULT', 'ERROR');
	agi_set_var('VOICEAI_ERROR', "API({$httpCode}): {$msg}");
	agi_verbose("VoiceAI Ultravox: API error {$httpCode} - {$msg}");
	exit(0);
}

$joinUrl = $data['joinUrl'];
$callId = $data['callId'] ?? '';

if (preg_match('/^sip:([^@]+)@(.+)$/', $joinUrl, $matches)) {
	$sipUser = $matches[1];
	$sipDomain = $matches[2];
} else {
	agi_set_var('VOICEAI_RESULT', 'ERROR');
	agi_set_var('VOICEAI_ERROR', "Invalid joinUrl: {$joinUrl}");
	exit(0);
}

agi_set_var('VOICEAI_RESULT', 'OK');
agi_set_var('VOICEAI_SIP_USER', $sipUser);
agi_set_var('VOICEAI_SIP_DOMAIN', $sipDomain);
agi_set_var('VOICEAI_CALL_ID', $callId);
agi_set_var('VOICEAI_JOIN_URL', $joinUrl);
agi_verbose("VoiceAI Ultravox: Created call {$callId} joinUrl={$joinUrl}");
exit(0);
