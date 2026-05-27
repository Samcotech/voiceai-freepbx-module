<?php
namespace FreePBX\modules;

require_once(__DIR__ . '/lib/ProviderApi.php');

use FreePBX\modules\Voiceai\ProviderApi;

class Voiceai extends \FreePBX_Helpers implements \BMO {

	private $freepbx;
	private $db;

	public function __construct($freepbx = null) {
		parent::__construct($freepbx);
		$this->freepbx = $freepbx;
		$this->db = $this->freepbx->Database;
	}

	public function install() {}
	public function uninstall() {}
	public function backup() {}
	public function restore($backup) {}
	public function doConfigPageInit($page) {}

	public function getActionBar($request) {
		$buttons = [];
		$display = $request['display'] ?? '';
		$view = $request['view'] ?? '';

		if ($display === 'voiceai' && $view === 'form') {
			$buttons = [
				'delete' => ['name' => 'delete', 'id' => 'delete', 'value' => _('Delete')],
				'reset' => ['name' => 'reset', 'id' => 'reset', 'value' => _('Reset')],
				'submit' => ['name' => 'submit', 'id' => 'submit', 'value' => _('Submit')],
			];
			if (empty($request['id'])) {
				unset($buttons['delete']);
			}
		}
		if ($display === 'voiceai' && $view === 'settings') {
			$buttons = [
				'submit' => ['name' => 'submit', 'id' => 'submit', 'value' => _('Save Settings')],
			];
		}
		return $buttons;
	}

	public function ajaxRequest($req, &$setting) {
		switch ($req) {
			case 'getJSON':
			case 'delete':
			case 'fetchAgents':
			case 'testConnection':
				return true;
		}
		return false;
	}

	public function ajaxHandler() {
		$request = $_REQUEST;
		switch ($request['command'] ?? '') {
			case 'getJSON':
				return $this->getAllAgents();

			case 'delete':
				$id = (int)($request['id'] ?? 0);
				if ($id) { $this->deleteAgent($id); return ['status' => true]; }
				return ['status' => false];

			case 'fetchAgents':
				$provider = $request['provider'] ?? '';
				return $this->fetchRemoteAgents($provider);

			case 'testConnection':
				$provider = $request['provider'] ?? '';
				$apiKey = $request['api_key'] ?? '';
				return $this->testProviderConnection($provider, $apiKey);
		}
		return false;
	}

	// ---- Provider Settings ----

	public function getProviderConfig($provider) {
		$sql = "SELECT * FROM voiceai_providers WHERE provider = ?";
		$sth = $this->db->prepare($sql);
		$sth->execute([$provider]);
		$row = $sth->fetch(\PDO::FETCH_ASSOC);
		if ($row && $row['extra_config']) {
			$row['extra'] = json_decode($row['extra_config'], true) ?: [];
		}
		return $row ?: [];
	}

	public function saveProviderConfig($provider, $apiKey, $extraConfig = [], $enabled = 1) {
		$existing = $this->getProviderConfig($provider);
		$extra = json_encode($extraConfig);

		if ($existing) {
			$sql = "UPDATE voiceai_providers SET api_key = ?, extra_config = ?, enabled = ? WHERE provider = ?";
			$sth = $this->db->prepare($sql);
			$sth->execute([$apiKey, $extra, $enabled, $provider]);
		} else {
			$sql = "INSERT INTO voiceai_providers (provider, api_key, extra_config, enabled) VALUES (?, ?, ?, ?)";
			$sth = $this->db->prepare($sql);
			$sth->execute([$provider, $apiKey, $extra, $enabled]);
		}
	}

	public function getAllProviderConfigs() {
		$sql = "SELECT * FROM voiceai_providers ORDER BY provider";
		$sth = $this->db->prepare($sql);
		$sth->execute();
		$rows = $sth->fetchAll(\PDO::FETCH_ASSOC);
		$configs = [];
		foreach ($rows as $r) {
			$configs[$r['provider']] = $r;
		}
		return $configs;
	}

	public function testProviderConnection($provider, $apiKeyOverride = '') {
		$config = $this->getProviderConfig($provider);
		$apiKey = !empty($apiKeyOverride) ? $apiKeyOverride : ($config['api_key'] ?? '');
		if (empty($apiKey)) {
			return ['status' => false, 'message' => 'No API key configured for ' . $provider];
		}

		$api = ProviderApi::getProvider($provider, $apiKey, $config['extra_config'] ?? '');
		if (!$api) {
			return ['status' => false, 'message' => 'Unknown provider: ' . $provider];
		}

		$agents = $api->listAgents();
		if (isset($agents['error'])) {
			return ['status' => false, 'message' => $agents['error']];
		}

		return ['status' => true, 'message' => 'Connected! Found ' . count($agents) . ' agent(s).', 'count' => count($agents)];
	}

	public function fetchRemoteAgents($provider) {
		$config = $this->getProviderConfig($provider);
		if (empty($config['api_key'])) {
			return ['error' => 'No API key configured'];
		}

		$api = ProviderApi::getProvider($provider, $config['api_key'], $config['extra_config'] ?? '');
		if (!$api) return ['error' => 'Unknown provider'];

		$agents = $api->listAgents();
		if (isset($agents['error'])) return $agents;

		return ['agents' => $agents];
	}

	// ---- Agent CRUD ----

	public function getAllAgents() {
		$sql = "SELECT * FROM voiceai_agents ORDER BY name";
		$sth = $this->db->prepare($sql);
		$sth->execute();
		return $sth->fetchAll(\PDO::FETCH_ASSOC);
	}

	public function getAgent($id) {
		$sql = "SELECT * FROM voiceai_agents WHERE id = ?";
		$sth = $this->db->prepare($sql);
		$sth->execute([$id]);
		return $sth->fetch(\PDO::FETCH_ASSOC);
	}

	public function saveAgent($data) {
		$id = !empty($data['id']) ? (int)$data['id'] : null;
		$provider = trim($data['provider'] ?? '');
		$remoteAgentId = trim($data['remote_agent_id'] ?? '');
		$name = trim($data['name'] ?? '');
		$timeout = (int)($data['timeout'] ?? 300);
		$enabled = isset($data['enabled']) ? (int)$data['enabled'] : 1;

		$config = $this->getProviderConfig($provider);
		$api = ProviderApi::getProvider($provider, $config['api_key'] ?? '', $config['extra_config'] ?? '');

		$sipUri = '';
		$sipUser = '';
		$transport = 'udp';
		$configJson = '{}';

		if (!$id) {
			$sql = "INSERT INTO voiceai_agents (name, provider, remote_agent_id, sip_uri, sip_user, transport, timeout, enabled, config_json) VALUES (?, ?, ?, '', '', 'udp', ?, ?, '{}')";
			$sth = $this->db->prepare($sql);
			$sth->execute([$name, $provider, $remoteAgentId, $timeout, $enabled]);
			$id = (int)$this->db->lastInsertId();
		}

		if ($api) {
			$bridge = in_array($provider, ['ultravox', '11labs'])
				? $api->setupBridge($remoteAgentId, $this->getServerIp(), $id)
				: $api->setupBridge($remoteAgentId, $this->getServerIp());
			if (!isset($bridge['error'])) {
				$sipUri = $bridge['sip_uri'] ?? '';
				$sipUser = $bridge['sip_user'] ?? '';
				$transport = $bridge['transport'] ?? 'udp';
				$configJson = json_encode($bridge);
			}
		}

		$fields = [
			'name' => $name,
			'provider' => $provider,
			'remote_agent_id' => $remoteAgentId,
			'sip_uri' => $sipUri,
			'sip_user' => $sipUser,
			'transport' => $transport,
			'timeout' => $timeout,
			'enabled' => $enabled,
			'config_json' => $configJson,
		];

		$sets = [];
		$vals = [];
		foreach ($fields as $k => $v) {
			$sets[] = "$k = ?";
			$vals[] = $v;
		}
		$vals[] = $id;
		$sql = "UPDATE voiceai_agents SET " . implode(', ', $sets) . " WHERE id = ?";
		$sth = $this->db->prepare($sql);
		$sth->execute($vals);

		$this->generatePjsipConfig();
		needreload();
		return ['status' => true, 'id' => $id];
	}

	public function deleteAgent($id) {
		$agent = $this->getAgent($id);
		if ($agent && $agent['provider'] === 'ultravox') {
			$config = $this->getProviderConfig('ultravox');
			$api = ProviderApi::getProvider('ultravox', $config['api_key'] ?? '', $config['extra_config'] ?? '');
			if ($api) {
				$api->removeSipAgentMapping($agent['remote_agent_id']);
			}
		}
		if ($agent && $agent['provider'] === '11labs') {
			$config = $this->getProviderConfig('11labs');
			$api = ProviderApi::getProvider('11labs', $config['api_key'] ?? '');
			if ($api) {
				$api->deletePhoneNumber($id);
			}
		}
		$sql = "DELETE FROM voiceai_agents WHERE id = ?";
		$sth = $this->db->prepare($sql);
		$sth->execute([$id]);
		$this->generatePjsipConfig();
		needreload();
	}

	// ---- PJSIP Config Generation ----

	public function generatePjsipConfig() {
		$agents = $this->getAllAgents();
		$providerConfigs = $this->getAllProviderConfigs();
		$conf = "; Voice AI Agent PJSIP Configuration\n";
		$conf .= "; Auto-generated by voiceai module v2 - DO NOT EDIT\n";
		$conf .= "; Generated: " . date('Y-m-d H:i:s') . "\n\n";

		$endpoints = [];
		foreach ($agents as $agent) {
			if (!$agent['enabled'] || empty($agent['sip_uri'])) continue;
			if (in_array($agent['provider'], ['retell', 'ultravox'])) continue;

			$slug = 'voiceai-' . $agent['id'];
			$host = $agent['sip_uri'];
			$user = $agent['sip_user'] ?: '';
			$transport = $agent['transport'] ?: 'udp';
			$port = 5060;
			$apiKey = $providerConfigs[$agent['provider']]['api_key'] ?? '';

			if ($agent['provider'] === '11labs') {
				$port = 5060;
				$transport = 'tcp';
			}

			if (in_array($slug, $endpoints)) continue;
			$endpoints[] = $slug;

			$transportId = '';
			if ($transport === 'tcp') {
				$transportId = "transport=0.0.0.0-tcp\n";
			}

			$needsAuth = $agent['provider'] === 'vapi' && !empty($apiKey);

			$conf .= "; {$agent['name']} ({$agent['provider']})\n";
			$conf .= "[{$slug}]\n";
			$conf .= "type=endpoint\n";
			$conf .= "context=from-trunk\n";
			$conf .= "disallow=all\n";
			if ($agent['provider'] === '11labs') {
				$conf .= "allow=g722\n";
			}
			$conf .= "allow=ulaw\n";
			$conf .= "allow=alaw\n";
			$conf .= "direct_media=no\n";
			$conf .= "rtp_symmetric=yes\n";
			$conf .= "force_rport=yes\n";
			$conf .= "rewrite_contact=yes\n";
			$conf .= "ice_support=no\n";
			$conf .= $transportId;
			$conf .= "from_domain={$host}\n";
			if ($user) {
				$conf .= "from_user={$user}\n";
			}
			$conf .= "aors={$slug}\n";
			if ($needsAuth) {
				$conf .= "outbound_auth={$slug}-auth\n";
			}
			$conf .= "\n";

			if ($needsAuth) {
				$conf .= "[{$slug}-auth]\n";
				$conf .= "type=auth\n";
				$conf .= "auth_type=userpass\n";
				$conf .= "username={$user}\n";
				$conf .= "password={$apiKey}\n";
				$conf .= "\n";
			}

			$contact = "sip:" . ($user ? "{$user}@" : '') . "{$host}:{$port}";
			if ($transport === 'tcp') {
				$contact .= ";transport=tcp";
			}
			$conf .= "[{$slug}]\n";
			$conf .= "type=aor\n";
			$conf .= "contact={$contact}\n";
			$qualFreq = ($agent['provider'] === '11labs') ? 0 : 60;
			$conf .= "qualify_frequency={$qualFreq}\n";
			$conf .= "\n";

			$ips = $this->resolveHostIps($host);
			$conf .= "[{$slug}]\n";
			$conf .= "type=identify\n";
			$conf .= "endpoint={$slug}\n";
			foreach ($ips as $ip) {
				$conf .= "match={$ip}\n";
			}
			if (empty($ips)) {
				$conf .= "match={$host}\n";
			}
			$conf .= "\n";
		}

		$hasRetell = false;
		foreach ($agents as $agent) {
			if ($agent['enabled'] && $agent['provider'] === 'retell') {
				$hasRetell = true;
				break;
			}
		}
		if ($hasRetell) {
			$retellApiKey = $providerConfigs['retell']['api_key'] ?? '';
			$retellIps = $this->resolveHostIps('sip.retellai.com');

			$conf .= "; Retell AI (dynamic per-call endpoint)\n";
			$conf .= "[voiceai-retell]\n";
			$conf .= "type=endpoint\n";
			$conf .= "context=from-trunk\n";
			$conf .= "disallow=all\n";
			$conf .= "allow=ulaw\n";
			$conf .= "allow=alaw\n";
			$conf .= "direct_media=no\n";
			$conf .= "rtp_symmetric=yes\n";
			$conf .= "force_rport=yes\n";
			$conf .= "rewrite_contact=yes\n";
			$conf .= "ice_support=no\n";
			$conf .= "from_domain=sip.retellai.com\n";
			$conf .= "aors=voiceai-retell\n";
			if (!empty($retellApiKey)) {
				$conf .= "outbound_auth=voiceai-retell-auth\n";
			}
			$conf .= "\n";

			if (!empty($retellApiKey)) {
				$conf .= "[voiceai-retell-auth]\n";
				$conf .= "type=auth\n";
				$conf .= "auth_type=userpass\n";
				$conf .= "username=retell\n";
				$conf .= "password={$retellApiKey}\n";
				$conf .= "\n";
			}

			$conf .= "[voiceai-retell]\n";
			$conf .= "type=aor\n";
			$conf .= "contact=sip:sip.retellai.com:5060\n";
			$conf .= "qualify_frequency=0\n";
			$conf .= "\n";

			$conf .= "[voiceai-retell]\n";
			$conf .= "type=identify\n";
			$conf .= "endpoint=voiceai-retell\n";
			foreach ($retellIps as $ip) {
				$conf .= "match={$ip}\n";
			}
			if (empty($retellIps)) {
				$conf .= "match=sip.retellai.com\n";
			}
			$conf .= "\n";
		}

		// Ultravox uses SIP registration (ext dials through registered contact) - no static endpoint needed

		file_put_contents('/etc/asterisk/pjsip.voiceai.conf', $conf);
	}

	private function resolveHostIps($host) {
		$records = dns_get_record($host, DNS_A);
		$ips = [];
		if ($records) {
			foreach ($records as $r) {
				if (!empty($r['ip'])) {
					$ips[] = $r['ip'];
				}
			}
		}
		return $ips;
	}

	private function getServerIp() {
		$ip = shell_exec("hostname -I | awk '{print $1}'");
		return trim($ip);
	}

	// ---- Provider metadata ----

	public static function getProviderList() {
		return [
			'vapi' => [
				'name' => 'VAPI',
				'description' => 'VAPI Voice AI Platform',
				'sip_domain' => 'sip.vapi.ai',
				'docs_url' => 'https://docs.vapi.ai/advanced/sip',
			],
			'retell' => [
				'name' => 'Retell AI',
				'description' => 'Retell AI Conversational Platform',
				'sip_domain' => 'sip.retellai.com',
				'docs_url' => 'https://docs.retellai.com/deploy/custom-telephony',
			],
			'11labs' => [
				'name' => 'ElevenLabs',
				'description' => 'ElevenLabs Conversational AI',
				'sip_domain' => 'sip.rtc.elevenlabs.io',
				'docs_url' => 'https://elevenlabs.io/docs/agents-platform/phone-numbers/sip-trunking',
			],
			'ultravox' => [
				'name' => 'Ultravox',
				'description' => 'Ultravox Voice AI',
				'sip_domain' => 'sip.ultravox.ai',
				'docs_url' => 'https://docs.ultravox.ai/telephony/sip',
			],
		];
	}
}
