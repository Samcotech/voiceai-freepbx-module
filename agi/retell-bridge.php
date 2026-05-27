#!/usr/bin/env php
<?php
// AGI script for Retell AI per-call registration
// Called as: AGI(retell-bridge.php,agent_id,api_key)

$env = [];
$stdin = fopen('php://stdin', 'r');

// Read AGI environment
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

// Get agent_id and api_key from AGI arguments
$agentId = $env['arg_1'] ?? '';
$apiKey = $env['arg_2'] ?? '';
$callerNum = $env['callerid'] ?? '';
$calledNum = $env['dnid'] ?? $env['extension'] ?? 's';

agi_verbose("VoiceAI Retell: agent_id=[{$agentId}] apikey_len=" . strlen($apiKey));

if (empty($agentId) || empty($apiKey)) {
	agi_set_var('VOICEAI_RESULT', 'ERROR');
	agi_set_var('VOICEAI_ERROR', 'Missing agent ID or API key');
	agi_verbose("VoiceAI: Missing agent_id or api_key");
	exit(0);
}

$fromNumber = $callerNum ?: '+10000000000';
$toNumber = $calledNum ?: '+10000000001';

if ($fromNumber[0] !== '+') $fromNumber = '+' . $fromNumber;
if ($toNumber[0] !== '+') $toNumber = '+' . $toNumber;

agi_verbose("VoiceAI Retell: Registering call from={$fromNumber} to={$toNumber}");

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://api.retellai.com/v2/register-phone-call');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
	"Authorization: Bearer {$apiKey}",
	'Content-Type: application/json',
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
	'agent_id' => $agentId,
	'direction' => 'inbound',
	'from_number' => $fromNumber,
	'to_number' => $toNumber,
]));

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
	agi_set_var('VOICEAI_RESULT', 'ERROR');
	agi_set_var('VOICEAI_ERROR', "CURL: {$error}");
	agi_verbose("VoiceAI Retell: CURL error - {$error}");
	exit(0);
}

$data = json_decode($response, true);
if ($httpCode >= 400 || !isset($data['call_id'])) {
	$msg = $data['message'] ?? $data['error'] ?? substr($response, 0, 200);
	agi_set_var('VOICEAI_RESULT', 'ERROR');
	agi_set_var('VOICEAI_ERROR', "API({$httpCode}): {$msg}");
	agi_verbose("VoiceAI Retell: API error {$httpCode} - {$msg}");
	exit(0);
}

$callId = $data['call_id'];
$sipUri = "sip:{$callId}@sip.retellai.com";

agi_set_var('VOICEAI_RESULT', 'OK');
agi_set_var('VOICEAI_SIP_URI', $sipUri);
agi_set_var('VOICEAI_CALL_ID', $callId);
agi_verbose("VoiceAI Retell: Registered call {$callId}");
exit(0);
