<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

$voiceai = FreePBX::Voiceai();
$providers = \FreePBX\modules\Voiceai::getProviderList();
$configs = $voiceai->getAllProviderConfigs();

$id = isset($_REQUEST['id']) ? (int)$_REQUEST['id'] : 0;
$agent = $id ? $voiceai->getAgent($id) : [];

$availableProviders = [];
foreach ($providers as $key => $info) {
	if (!empty($configs[$key]['api_key'])) {
		$availableProviders[$key] = $info;
	}
}
?>
<form autocomplete="off" name="voiceai_form" id="voiceai_form" class="fpbx-submit" action="" method="post">
	<input type="hidden" name="display" value="voiceai"/>
	<input type="hidden" name="action" value="<?php echo $id ? 'edit' : 'add'; ?>"/>
	<?php if ($id): ?>
		<input type="hidden" name="id" value="<?php echo $id; ?>"/>
	<?php endif; ?>

	<div class="display no-border">
		<div class="row">
			<div class="col-sm-12">
				<div class="fpbx-container">
					<div class="display full-border">
						<h1><?php echo $id ? _('Edit AI Agent') : _('Add AI Agent'); ?>
							<a href="?display=voiceai" class="btn btn-default pull-right">
								<i class="fa fa-arrow-left"></i> <?php echo _('Back to Agents'); ?>
							</a>
						</h1>

						<?php if (empty($availableProviders)): ?>
							<div class="alert alert-warning">
								<strong><?php echo _('No providers configured!'); ?></strong>
								<?php echo _('Please configure at least one provider API key in'); ?>
								<a href="?display=voiceai&view=settings"><?php echo _('Provider Settings'); ?></a>
								<?php echo _('before adding an agent.'); ?>
							</div>
						<?php endif; ?>

						<!--Provider-->
						<div class="element-container">
							<div class="row">
								<div class="col-md-12">
									<div class="row">
										<div class="form-group">
											<div class="col-md-3">
												<label class="control-label" for="provider"><?php echo _('Provider'); ?></label>
												<i class="fa fa-question-circle fpbx-help-icon" data-for="provider"></i>
											</div>
											<div class="col-md-9">
												<select class="form-control" id="provider" name="provider" <?php echo empty($availableProviders) ? 'disabled' : ''; ?>>
													<option value=""><?php echo _('-- Select Provider --'); ?></option>
													<?php foreach ($availableProviders as $key => $info): ?>
														<option value="<?php echo $key; ?>" <?php echo (isset($agent['provider']) && $agent['provider'] === $key) ? 'selected' : ''; ?>>
															<?php echo $info['name']; ?>
														</option>
													<?php endforeach; ?>
												</select>
											</div>
										</div>
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span id="provider-help" class="help-block fpbx-help-block"><?php echo _('Select the Voice AI provider for this agent.'); ?></span>
								</div>
							</div>
						</div>

						<!--Remote Agent Selection-->
						<div class="element-container" id="agent-selection-row">
							<div class="row">
								<div class="col-md-12">
									<div class="row">
										<div class="form-group">
											<div class="col-md-3">
												<label class="control-label" for="remote_agent_id"><?php echo _('AI Agent'); ?></label>
												<i class="fa fa-question-circle fpbx-help-icon" data-for="remote_agent_id"></i>
											</div>
											<div class="col-md-9">
												<div class="input-group">
													<select class="form-control" id="remote_agent_id" name="remote_agent_id">
														<option value=""><?php echo _('-- Select a provider first --'); ?></option>
														<?php if (!empty($agent['remote_agent_id'])): ?>
															<option value="<?php echo htmlspecialchars($agent['remote_agent_id']); ?>" selected>
																<?php echo htmlspecialchars($agent['name'] . ' (' . substr($agent['remote_agent_id'], 0, 16) . '...)'); ?>
															</option>
														<?php endif; ?>
													</select>
													<span class="input-group-btn">
														<button type="button" class="btn btn-info" id="btn-fetch-agents" disabled>
															<i class="fa fa-cloud-download"></i> <?php echo _('Fetch Agents'); ?>
														</button>
													</span>
												</div>
												<span id="fetch-status" class="help-block"></span>
											</div>
										</div>
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span id="remote_agent_id-help" class="help-block fpbx-help-block"><?php echo _('Select an agent from your provider account. Click "Fetch Agents" to load the list from the API.'); ?></span>
								</div>
							</div>
						</div>

						<!--Agent Name-->
						<div class="element-container">
							<div class="row">
								<div class="col-md-12">
									<div class="row">
										<div class="form-group">
											<div class="col-md-3">
												<label class="control-label" for="name"><?php echo _('Display Name'); ?></label>
												<i class="fa fa-question-circle fpbx-help-icon" data-for="name"></i>
											</div>
											<div class="col-md-9">
												<input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($agent['name'] ?? ''); ?>" placeholder="<?php echo _('e.g. Sales Bot, Support Agent'); ?>"/>
											</div>
										</div>
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span id="name-help" class="help-block fpbx-help-block"><?php echo _('A friendly name for this agent shown in FreePBX.'); ?></span>
								</div>
							</div>
						</div>

						<!--Timeout-->
						<div class="element-container">
							<div class="row">
								<div class="col-md-12">
									<div class="row">
										<div class="form-group">
											<div class="col-md-3">
												<label class="control-label" for="timeout"><?php echo _('Call Timeout (seconds)'); ?></label>
												<i class="fa fa-question-circle fpbx-help-icon" data-for="timeout"></i>
											</div>
											<div class="col-md-9">
												<input type="number" class="form-control" id="timeout" name="timeout" value="<?php echo (int)($agent['timeout'] ?? 300); ?>" min="30" max="3600"/>
											</div>
										</div>
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span id="timeout-help" class="help-block fpbx-help-block"><?php echo _('Maximum call duration in seconds before automatic hangup. Default: 300 (5 minutes).'); ?></span>
								</div>
							</div>
						</div>

						<!--Enabled-->
						<div class="element-container">
							<div class="row">
								<div class="col-md-12">
									<div class="row">
										<div class="form-group">
											<div class="col-md-3">
												<label class="control-label" for="enabled"><?php echo _('Enabled'); ?></label>
												<i class="fa fa-question-circle fpbx-help-icon" data-for="enabled"></i>
											</div>
											<div class="col-md-9">
												<span class="radioset">
													<input type="radio" name="enabled" id="enabled_yes" value="1" <?php echo (!isset($agent['enabled']) || $agent['enabled']) ? 'checked' : ''; ?>/>
													<label for="enabled_yes"><?php echo _('Yes'); ?></label>
													<input type="radio" name="enabled" id="enabled_no" value="0" <?php echo (isset($agent['enabled']) && !$agent['enabled']) ? 'checked' : ''; ?>/>
													<label for="enabled_no"><?php echo _('No'); ?></label>
												</span>
											</div>
										</div>
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span id="enabled-help" class="help-block fpbx-help-block"><?php echo _('Enable or disable this agent. Disabled agents cannot receive calls.'); ?></span>
								</div>
							</div>
						</div>

						<?php if ($id && !empty($agent['sip_uri'])): ?>
						<!--SIP Info (read-only)-->
						<div class="element-container">
							<div class="row">
								<div class="col-md-12">
									<div class="row">
										<div class="form-group">
											<div class="col-md-3">
												<label class="control-label"><?php echo _('SIP Bridge Info'); ?></label>
											</div>
											<div class="col-md-9">
												<?php if ($agent['provider'] === 'retell'): ?>
													<p class="form-control-static text-muted">
														<i class="fa fa-refresh"></i> <?php echo _('Retell uses dynamic per-call SIP registration. No static endpoint needed.'); ?>
													</p>
												<?php else: ?>
													<p class="form-control-static">
														<strong><?php echo _('Endpoint:'); ?></strong>
														<code><?php echo htmlspecialchars($agent['sip_user'] ? $agent['sip_user'] . '@' . $agent['sip_uri'] : $agent['sip_uri']); ?></code>
														<br/>
														<strong><?php echo _('Transport:'); ?></strong> <?php echo strtoupper($agent['transport'] ?: 'UDP'); ?>
													</p>
												<?php endif; ?>
											</div>
										</div>
									</div>
								</div>
							</div>
						</div>
						<?php endif; ?>

					</div>
				</div>
			</div>
		</div>
	</div>
</form>

<script>
$(document).ready(function() {
	var fetchedAgents = {};

	$('#provider').on('change', function() {
		var provider = $(this).val();
		$('#btn-fetch-agents').prop('disabled', !provider);
		$('#remote_agent_id').html('<option value=""><?php echo _("-- Select a provider first --"); ?></option>');
		$('#fetch-status').html('');
		if (provider) {
			$('#btn-fetch-agents').click();
		}
	});

	$('#btn-fetch-agents').on('click', function() {
		var provider = $('#provider').val();
		if (!provider) return;

		var btn = $(this);
		var select = $('#remote_agent_id');
		var status = $('#fetch-status');

		btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> <?php echo _("Fetching..."); ?>');
		status.html('').removeClass('text-success text-danger');

		$.ajax({
			url: 'ajax.php?module=voiceai&command=fetchAgents&provider=' + provider,
			dataType: 'json',
			success: function(data) {
				select.html('<option value=""><?php echo _("-- Select Agent --"); ?></option>');

				if (data.error) {
					status.html('<i class="fa fa-times"></i> ' + data.error).addClass('text-danger');
					return;
				}

				var agents = data.agents || [];
				if (agents.length === 0) {
					status.html('<i class="fa fa-info-circle"></i> <?php echo _("No agents found in your account."); ?>').addClass('text-muted');
					return;
				}

				fetchedAgents = {};
				$.each(agents, function(i, agent) {
					fetchedAgents[agent.id] = agent;
					var label = agent.name + ' (' + agent.id.substring(0, 12) + '...)';
					var opt = $('<option></option>').val(agent.id).text(label);
					select.append(opt);
				});

				<?php if (!empty($agent['remote_agent_id'])): ?>
				select.val('<?php echo addslashes($agent['remote_agent_id']); ?>');
				<?php endif; ?>

				status.html('<i class="fa fa-check"></i> <?php echo _("Found"); ?> ' + agents.length + ' <?php echo _("agent(s)"); ?>').addClass('text-success');
			},
			error: function() {
				status.html('<i class="fa fa-times"></i> <?php echo _("Failed to fetch agents."); ?>').addClass('text-danger');
			},
			complete: function() {
				btn.prop('disabled', false).html('<i class="fa fa-cloud-download"></i> <?php echo _("Fetch Agents"); ?>');
			}
		});
	});

	$('#remote_agent_id').on('change', function() {
		var agentId = $(this).val();
		if (agentId && fetchedAgents[agentId] && !$('#name').val()) {
			$('#name').val(fetchedAgents[agentId].name);
		}
	});

	<?php if ($id && !empty($agent['provider'])): ?>
	$('#provider').trigger('change');
	<?php endif; ?>
});
</script>
