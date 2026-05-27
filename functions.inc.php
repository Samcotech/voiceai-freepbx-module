<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

function voiceai_destinations() {
	$agents = voiceai_list();
	$extens = [];
	$pNames = ['vapi' => 'VAPI', 'retell' => 'Retell AI', '11labs' => 'ElevenLabs', 'ultravox' => 'Ultravox'];
	foreach ($agents as $agent) {
		if ($agent['enabled']) {
			$pName = $pNames[$agent['provider']] ?? ucfirst($agent['provider']);
			$extens[] = [
				'destination' => 'app-voiceai-' . $agent['id'] . ',s,1',
				'description' => $agent['name'] . ' (' . $pName . ')',
				'category' => 'Voice AI Agent',
			];
		}
	}
	return $extens;
}

function voiceai_getdest($exten) {
	return ['app-voiceai-' . $exten . ',s,1'];
}

function voiceai_getdestinfo($dest) {
	global $active_modules;
	if (str_starts_with(trim((string)$dest), 'app-voiceai-')) {
		$exten = explode(',', (string)$dest);
		$id = (int)substr($exten[0], 12);
		$agent = voiceai_get($id);
		if (empty($agent)) return [];
		$type = $active_modules['voiceai']['type'] ?? 'setup';
		$pNames = ['vapi' => 'VAPI', 'retell' => 'Retell AI', '11labs' => 'ElevenLabs', 'ultravox' => 'Ultravox'];
		$pName = $pNames[$agent['provider']] ?? ucfirst($agent['provider']);
		return [
			'description' => sprintf(_('Voice AI: %s (%s)'), $agent['name'], $pName),
			'edit_url' => 'config.php?display=voiceai&view=form&type=' . $type . '&id=' . urlencode($id),
		];
	}
	return false;
}

function voiceai_list() {
	global $db;
	$sql = "SELECT * FROM voiceai_agents ORDER BY name";
	$sth = $db->prepare($sql);
	$sth->execute();
	return $sth->fetchAll(PDO::FETCH_ASSOC);
}

function voiceai_get($id) {
	global $db;
	$sql = "SELECT * FROM voiceai_agents WHERE id = ?";
	$sth = $db->prepare($sql);
	$sth->execute([$id]);
	return $sth->fetch(PDO::FETCH_ASSOC);
}

function voiceai_get_config($engine) {
	global $ext, $db;
	switch ($engine) {
		case 'asterisk':
			$agents = voiceai_list();

			$providerKeys = [];
			$providerExtra = [];
			$sql = "SELECT provider, api_key, extra_config FROM voiceai_providers";
			$sth = $db->prepare($sql);
			$sth->execute();
			foreach ($sth->fetchAll(PDO::FETCH_ASSOC) as $p) {
				$providerKeys[$p['provider']] = $p['api_key'];
				$providerExtra[$p['provider']] = json_decode($p['extra_config'] ?: '{}', true) ?: [];
			}

			foreach ($agents as $agent) {
				if (!$agent['enabled']) continue;

				$context = 'app-voiceai-' . $agent['id'];
				$slug = 'voiceai-' . $agent['id'];
				$timeout = (int)$agent['timeout'] ?: 300;

				$ext->add($context, 's', '', new ext_noop('Voice AI Agent: ' . $agent['name'] . ' [' . $agent['provider'] . ']'));
				$ext->add($context, 's', '', new ext_answer(''));
				$ext->add($context, 's', '', new ext_wait('1'));

				if ($agent['provider'] === 'retell') {
					$apiKey = $providerKeys['retell'] ?? '';
					$ext->add($context, 's', '', new ext_agi('retell-bridge.php,' . $agent['remote_agent_id'] . ',' . $apiKey));
					$ext->add($context, 's', '', new ext_gotoif('$["${VOICEAI_RESULT}" != "OK"]', 'voiceai-error,s,1'));
					$ext->add($context, 's', '', new ext_noop('Retell SIP URI: ${VOICEAI_SIP_URI}'));
					$ext->add($context, 's', '', new ext_dial('PJSIP/${VOICEAI_CALL_ID}@voiceai-retell', $timeout, 'g'));
				} elseif ($agent['provider'] === 'ultravox') {
					$sipExt = $providerExtra['ultravox']['sip_extension'] ?? '105';
					$pattern = $agent['sip_user'] ?: 'voiceai-' . $agent['id'];
					$ext->add($context, 's', '', new ext_noop('Ultravox: dialing pattern ' . $pattern . ' via ext ' . $sipExt));
					$ext->add($context, 's', '', new ext_dial('PJSIP/' . $pattern . '@' . $sipExt, $timeout, 'g'));
				} else {
					// VAPI, ElevenLabs - static SIP endpoint
					$sipUser = $agent['sip_user'] ?: 's';
					$ext->add($context, 's', '', new ext_dial('PJSIP/' . $sipUser . '@' . $slug, $timeout, 'g'));
				}

				$ext->add($context, 's', '', new ext_noop('Voice AI call ended. DIALSTATUS=${DIALSTATUS}'));
				$ext->add($context, 's', '', new ext_hangup(''));
			}

			// Error context
			if (!empty($agents)) {
				$ext->add('voiceai-error', 's', '', new ext_noop('Voice AI Error: ${VOICEAI_ERROR}'));
				$ext->add('voiceai-error', 's', '', new ext_playback('an-error-has-occurred'));
				$ext->add('voiceai-error', 's', '', new ext_hangup(''));
			}
			break;
	}
}
