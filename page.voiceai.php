<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

$request = $_REQUEST;
$action = $request['action'] ?? '';
$view = $request['view'] ?? '';
$id = isset($request['id']) ? (int)$request['id'] : 0;

$voiceai = FreePBX::Voiceai();

// Handle form submissions
if ($action === 'save-settings') {
	$providers = ['vapi', 'retell', '11labs', 'ultravox'];
	foreach ($providers as $p) {
		$key = trim($request["api_key_{$p}"] ?? '');
		$extra = [];
		if ($p === 'ultravox') {
			$extra['sip_domain'] = trim($request['ultravox_sip_domain'] ?? '');
			$extra['sip_extension'] = trim($request['ultravox_sip_extension'] ?? '');
		}
		$enabled = isset($request["enabled_{$p}"]) ? 1 : 0;
		if (!empty($key)) {
			$voiceai->saveProviderConfig($p, $key, $extra, $enabled);
		}
	}
	redirect('config.php?display=voiceai&view=settings&saved=1');
}

if (($action === 'add' || $action === 'edit') && !empty($request['name'])) {
	$voiceai->saveAgent($request);
	redirect('config.php?display=voiceai');
}

if ($action === 'delete' && $id) {
	$voiceai->deleteAgent($id);
	redirect('config.php?display=voiceai');
}

// Route to views
switch ($view) {
	case 'settings':
		include __DIR__ . '/views/settings.php';
		break;
	case 'form':
		include __DIR__ . '/views/form.php';
		break;
	default:
		include __DIR__ . '/views/grid.php';
		break;
}
