<?php
namespace FreePBX\modules\Voiceai;

class ProviderApi {

	public static function getProvider($provider, $apiKey, $extraConfig = []) {
		switch ($provider) {
			case 'vapi':    return new VapiApi($apiKey, $extraConfig);
			case 'retell':  return new RetellApi($apiKey, $extraConfig);
			case '11labs':  return new ElevenlabsApi($apiKey, $extraConfig);
			case 'ultravox': return new UltravoxApi($apiKey, $extraConfig);
		}
		return null;
	}

	protected $apiKey;
	protected $extraConfig;

	public function __construct($apiKey, $extraConfig = []) {
		$this->apiKey = $apiKey;
		$this->extraConfig = is_array($extraConfig) ? $extraConfig : (json_decode($extraConfig, true) ?: []);
	}

	protected function request($method, $url, $data = null, $headers = []) {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

		$defaultHeaders = $this->getAuthHeaders();
		$allHeaders = array_merge($defaultHeaders, ['Content-Type: application/json'], $headers);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $allHeaders);

		if ($method === 'POST') {
			curl_setopt($ch, CURLOPT_POST, true);
			if ($data) {
				curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
			}
		}

		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		curl_close($ch);

		if ($error) {
			return ['error' => "CURL Error: $error"];
		}

		$decoded = json_decode($response, true);
		if ($httpCode >= 400) {
			$msg = $decoded['message'] ?? $decoded['error'] ?? $response;
			return ['error' => "API Error ($httpCode): $msg"];
		}

		return $decoded ?: [];
	}

	protected function getAuthHeaders() {
		return [];
	}

	public function listAgents() { return []; }
	public function getSipUri($agentId) { return ''; }
	public function setupBridge($agentId, $serverIp) { return []; }
	public function getProviderName() { return 'unknown'; }
	public function getSipDomain() { return ''; }
	public function needsPerCallRegistration() { return false; }
}

class VapiApi extends ProviderApi {
	private $baseUrl = 'https://api.vapi.ai';

	protected function getAuthHeaders() {
		return ["Authorization: Bearer {$this->apiKey}"];
	}

	public function getProviderName() { return 'VAPI'; }
	public function getSipDomain() { return 'sip.vapi.ai'; }

	public function listAgents() {
		$result = $this->request('GET', "{$this->baseUrl}/assistant?limit=100");
		if (isset($result['error'])) return $result;

		$agents = [];
		$items = isset($result[0]) ? $result : ($result['results'] ?? $result);
		foreach ($items as $a) {
			if (!isset($a['id'])) continue;
			$agents[] = [
				'id' => $a['id'],
				'name' => $a['name'] ?? $a['id'],
			];
		}
		return $agents;
	}

	public function setupBridge($agentId, $serverIp) {
		$sipName = 'fpbx-' . substr(md5($agentId), 0, 8);
		$result = $this->request('POST', "{$this->baseUrl}/phone-number", [
			'provider' => 'vapi',
			'sipUri' => "sip:{$sipName}@sip.vapi.ai",
			'assistantId' => $agentId,
		]);

		if (isset($result['error'])) return $result;

		return [
			'sip_uri' => "sip.vapi.ai",
			'sip_user' => $sipName,
			'transport' => 'udp',
			'phone_number_id' => $result['id'] ?? '',
		];
	}

	public function getSipUri($agentId) {
		$sipName = 'fpbx-' . substr(md5($agentId), 0, 8);
		return "sip:{$sipName}@sip.vapi.ai";
	}
}

class RetellApi extends ProviderApi {
	private $baseUrl = 'https://api.retellai.com';

	protected function getAuthHeaders() {
		return ["Authorization: Bearer {$this->apiKey}"];
	}

	public function getProviderName() { return 'Retell AI'; }
	public function getSipDomain() { return 'sip.retellai.com'; }
	public function needsPerCallRegistration() { return true; }

	public function listAgents() {
		$result = $this->request('GET', "{$this->baseUrl}/list-agents");
		if (isset($result['error'])) return $result;

		$agents = [];
		$seen = [];
		foreach ($result as $a) {
			if (!isset($a['agent_id'])) continue;
			if (isset($seen[$a['agent_id']])) continue;
			$seen[$a['agent_id']] = true;
			$agents[] = [
				'id' => $a['agent_id'],
				'name' => $a['agent_name'] ?? $a['agent_id'],
			];
		}
		return $agents;
	}

	public function registerCall($agentId, $fromNumber, $toNumber) {
		$result = $this->request('POST', "{$this->baseUrl}/v2/register-phone-call", [
			'agent_id' => $agentId,
			'direction' => 'inbound',
			'from_number' => $fromNumber,
			'to_number' => $toNumber,
		]);
		if (isset($result['error'])) return $result;
		return [
			'call_id' => $result['call_id'] ?? '',
			'sip_uri' => "sip:{$result['call_id']}@sip.retellai.com",
		];
	}

	public function setupBridge($agentId, $serverIp) {
		return [
			'sip_uri' => 'sip.retellai.com',
			'sip_user' => '',
			'transport' => 'udp',
			'needs_agi' => true,
		];
	}
}

class ElevenlabsApi extends ProviderApi {
	private $baseUrl = 'https://api.elevenlabs.io';

	protected function getAuthHeaders() {
		return ["xi-api-key: {$this->apiKey}"];
	}

	public function getProviderName() { return 'ElevenLabs'; }
	public function getSipDomain() { return 'sip.rtc.elevenlabs.io'; }

	public function listAgents() {
		$result = $this->request('GET', "{$this->baseUrl}/v1/convai/agents?page_size=100");
		if (isset($result['error'])) return $result;

		$agents = [];
		$items = $result['agents'] ?? $result;
		foreach ($items as $a) {
			if (!isset($a['agent_id'])) continue;
			$agents[] = [
				'id' => $a['agent_id'],
				'name' => $a['name'] ?? $a['agent_id'],
			];
		}
		return $agents;
	}

	public function setupBridge($agentId, $serverIp, $localId = null) {
		$phoneLabel = $localId ? "voiceai-{$localId}" : "voiceai-11labs";

		$existing = $this->findPhoneNumber($phoneLabel);
		if ($existing) {
			$this->request('DELETE', "{$this->baseUrl}/v1/convai/phone-numbers/{$existing}");
		}

		$result = $this->request('POST', "{$this->baseUrl}/v1/convai/phone-numbers/create", [
			'phone_number' => $phoneLabel,
			'label' => "FreePBX-{$phoneLabel}",
			'provider' => 'sip_trunk',
			'termination_uri' => "sip:{$serverIp}:5060",
			'inbound_trunk' => [
				'allowed_addresses' => ["{$serverIp}/32"],
				'media_encryption' => 'allowed',
			],
		]);

		if (isset($result['error'])) return $result;

		$phoneId = $result['phone_number_id'] ?? '';
		if ($phoneId) {
			$this->request('PATCH', "{$this->baseUrl}/v1/convai/phone-numbers/{$phoneId}", [
				'agent_id' => $agentId,
			]);
		}

		return [
			'sip_uri' => 'sip.rtc.elevenlabs.io',
			'sip_user' => $phoneLabel,
			'transport' => 'tcp',
			'port' => 5060,
			'phone_number_id' => $phoneId,
		];
	}

	private function findPhoneNumber($label) {
		$result = $this->request('GET', "{$this->baseUrl}/v1/convai/phone-numbers");
		if (isset($result['error']) || !is_array($result)) return null;
		foreach ($result as $p) {
			if (($p['phone_number'] ?? '') === $label) {
				return $p['phone_number_id'] ?? null;
			}
		}
		return null;
	}

	public function deletePhoneNumber($localId) {
		$phoneLabel = "voiceai-{$localId}";
		$phoneId = $this->findPhoneNumber($phoneLabel);
		if ($phoneId) {
			$this->request('DELETE', "{$this->baseUrl}/v1/convai/phone-numbers/{$phoneId}");
		}
	}

	public function getSipUri($agentId) {
		return "sip:{$agentId}@sip.rtc.elevenlabs.io:5060;transport=tcp";
	}

	protected function request($method, $url, $data = null, $headers = []) {
		if (in_array($method, ['PATCH', 'DELETE'])) {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, 30);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
			$allHeaders = array_merge($this->getAuthHeaders(), ['Content-Type: application/json'], $headers);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $allHeaders);
			if ($data) {
				curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
			}
			$response = curl_exec($ch);
			$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$error = curl_error($ch);
			curl_close($ch);
			if ($error) return ['error' => "CURL Error: $error"];
			$decoded = json_decode($response, true);
			if ($httpCode >= 400) {
				$msg = $decoded['message'] ?? $decoded['error'] ?? $response;
				return ['error' => "API Error ($httpCode): $msg"];
			}
			return $decoded ?: [];
		}
		return parent::request($method, $url, $data, $headers);
	}
}

class UltravoxApi extends ProviderApi {
	private $baseUrl = 'https://api.ultravox.ai';

	protected function getAuthHeaders() {
		return ["X-API-Key: {$this->apiKey}"];
	}

	public function getProviderName() { return 'Ultravox'; }

	public function getSipDomain() {
		return $this->extraConfig['sip_domain'] ?? 'sip.ultravox.ai';
	}

	public function getSipExtension() {
		return $this->extraConfig['sip_extension'] ?? '';
	}

	public function listAgents() {
		$result = $this->request('GET', "{$this->baseUrl}/api/agents");
		if (isset($result['error'])) return $result;

		$agents = [];
		$items = $result['results'] ?? $result;
		foreach ($items as $a) {
			$id = $a['agentId'] ?? $a['agent_id'] ?? $a['id'] ?? null;
			if (!$id) continue;
			$agents[] = [
				'id' => $id,
				'name' => $a['name'] ?? $id,
			];
		}
		return $agents;
	}

	public function setupBridge($agentId, $serverIp, $localId = null) {
		$sipExt = $this->getSipExtension();
		$pattern = $localId ? 'voiceai-' . $localId : $agentId;

		$this->addSipAgentMapping($agentId, $pattern);

		return [
			'sip_uri' => $this->getSipDomain(),
			'sip_user' => $pattern,
			'sip_extension' => $sipExt,
			'transport' => 'udp',
			'ultravox_pattern' => $pattern,
		];
	}

	private function addSipAgentMapping($agentId, $pattern) {
		$sipConfig = $this->request('GET', "{$this->baseUrl}/api/sip");
		if (isset($sipConfig['error'])) return $sipConfig;

		$agents = $sipConfig['allowedAgents'] ?? [];
		$found = false;

		$filtered = [];
		foreach ($agents as $a) {
			if ($a['agentId'] === $agentId || $a['toUserPattern'] === $pattern) {
				if (!$found) {
					$filtered[] = ['agentId' => $agentId, 'toUserPattern' => $pattern];
					$found = true;
				}
			} else {
				$filtered[] = $a;
			}
		}

		if (!$found) {
			$filtered[] = ['agentId' => $agentId, 'toUserPattern' => $pattern];
		}

		return $this->request('PATCH', "{$this->baseUrl}/api/sip", [
			'allowedAgents' => $filtered,
		]);
	}

	public function removeSipAgentMapping($agentId) {
		$sipConfig = $this->request('GET', "{$this->baseUrl}/api/sip");
		if (isset($sipConfig['error'])) return;

		$agents = $sipConfig['allowedAgents'] ?? [];
		$filtered = array_values(array_filter($agents, fn($a) => $a['agentId'] !== $agentId));

		if (count($filtered) !== count($agents)) {
			$this->request('PATCH', "{$this->baseUrl}/api/sip", [
				'allowedAgents' => $filtered,
			]);
		}
	}

	protected function request($method, $url, $data = null, $headers = []) {
		if ($method === 'PATCH') {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, 30);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
			$allHeaders = array_merge($this->getAuthHeaders(), ['Content-Type: application/json'], $headers);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $allHeaders);
			if ($data) {
				curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
			}
			$response = curl_exec($ch);
			$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$error = curl_error($ch);
			curl_close($ch);
			if ($error) return ['error' => "CURL Error: $error"];
			$decoded = json_decode($response, true);
			if ($httpCode >= 400) {
				$msg = $decoded['message'] ?? $decoded['error'] ?? $response;
				return ['error' => "API Error ($httpCode): $msg"];
			}
			return $decoded ?: [];
		}
		return parent::request($method, $url, $data, $headers);
	}

	public function getSipUri($agentId) {
		$domain = $this->getSipDomain();
		return "sip:{$agentId}@{$domain}";
	}
}
