<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

$voiceai = FreePBX::Voiceai();
$configs = $voiceai->getAllProviderConfigs();
$providers = \FreePBX\modules\Voiceai::getProviderList();
$saved = isset($_REQUEST['saved']);
?>
<form autocomplete="off" name="voiceai_settings" id="voiceai_settings" class="fpbx-submit" action="" method="post">
	<input type="hidden" name="display" value="voiceai"/>
	<input type="hidden" name="action" value="save-settings"/>

	<div class="display no-border">
		<div class="row">
			<div class="col-sm-12">
				<div class="fpbx-container">
					<div class="display full-border">
						<h1><?php echo _('Voice AI Provider Settings'); ?>
							<a href="?display=voiceai" class="btn btn-default pull-right">
								<i class="fa fa-arrow-left"></i> <?php echo _('Back to Agents'); ?>
							</a>
						</h1>

						<?php if ($saved): ?>
							<div class="alert alert-success"><?php echo _('Settings saved successfully.'); ?></div>
						<?php endif; ?>

						<p class="help-block"><?php echo _('Enter your API keys for each Voice AI provider you want to use. The module will use these keys to fetch your agents and create SIP bridge configurations automatically.'); ?></p>

						<?php foreach ($providers as $key => $info): ?>
							<?php
								$config = $configs[$key] ?? [];
								$apiKey = $config['api_key'] ?? '';
								$extra = json_decode($config['extra_config'] ?? '{}', true) ?: [];
								$enabled = isset($config['enabled']) ? (int)$config['enabled'] : 0;
							?>
							<div class="panel panel-default">
								<div class="panel-heading">
									<h3 class="panel-title">
										<strong><?php echo $info['name']; ?></strong>
										<small class="text-muted"> — <?php echo $info['description']; ?></small>
										<?php if ($apiKey): ?>
											<span class="label label-success pull-right"><?php echo _('Configured'); ?></span>
										<?php endif; ?>
									</h3>
								</div>
								<div class="panel-body">
									<div class="form-group">
										<label for="api_key_<?php echo $key; ?>"><?php echo _('API Key'); ?></label>
										<div class="input-group">
											<input type="password" class="form-control api-key-input" id="api_key_<?php echo $key; ?>" name="api_key_<?php echo $key; ?>" value="<?php echo htmlspecialchars($apiKey); ?>" placeholder="<?php echo _('Enter your ' . $info['name'] . ' API key'); ?>"/>
											<span class="input-group-btn">
												<button type="button" class="btn btn-default toggle-password" data-target="api_key_<?php echo $key; ?>">
													<i class="fa fa-eye"></i>
												</button>
												<button type="button" class="btn btn-info test-connection" data-provider="<?php echo $key; ?>">
													<i class="fa fa-plug"></i> <?php echo _('Test'); ?>
												</button>
											</span>
										</div>
										<span class="test-result-<?php echo $key; ?> help-block"></span>
									</div>

									<?php if ($key === 'ultravox'): ?>
										<div class="form-group">
											<label for="ultravox_sip_domain"><?php echo _('SIP Domain'); ?></label>
											<input type="text" class="form-control" id="ultravox_sip_domain" name="ultravox_sip_domain" value="<?php echo htmlspecialchars($extra['sip_domain'] ?? ''); ?>" placeholder="<?php echo _('Your Ultravox account SIP domain'); ?>"/>
											<span class="help-block"><?php echo _('Found in Ultravox SIP settings → IP Allowlisting → Domain'); ?></span>
										</div>
										<div class="form-group">
											<label for="ultravox_sip_extension"><?php echo _('PBX SIP Extension'); ?></label>
											<input type="text" class="form-control" id="ultravox_sip_extension" name="ultravox_sip_extension" value="<?php echo htmlspecialchars($extra['sip_extension'] ?? ''); ?>" placeholder="<?php echo _('e.g. 105'); ?>"/>
											<span class="help-block"><?php echo _('The extension Ultravox registers on your PBX (SIP Registration in Ultravox dashboard)'); ?></span>
										</div>
									<?php endif; ?>

									<div class="form-group">
										<label>
											<input type="checkbox" name="enabled_<?php echo $key; ?>" value="1" <?php echo ($enabled || !$apiKey) ? '' : ''; echo $enabled ? 'checked' : ''; ?>/>
											<?php echo _('Enabled'); ?>
										</label>
										<a href="<?php echo $info['docs_url']; ?>" target="_blank" class="pull-right">
											<i class="fa fa-external-link"></i> <?php echo _('Documentation'); ?>
										</a>
									</div>
								</div>
							</div>
						<?php endforeach; ?>

					</div>
				</div>
			</div>
		</div>
	</div>
</form>

<script>
$(document).ready(function() {
	$('.toggle-password').click(function() {
		var target = $(this).data('target');
		var input = $('#' + target);
		var icon = $(this).find('i');
		if (input.attr('type') === 'password') {
			input.attr('type', 'text');
			icon.removeClass('fa-eye').addClass('fa-eye-slash');
		} else {
			input.attr('type', 'password');
			icon.removeClass('fa-eye-slash').addClass('fa-eye');
		}
	});

	$('.test-connection').click(function() {
		var provider = $(this).data('provider');
		var resultSpan = $('.test-result-' + provider);
		var btn = $(this);

		btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Testing...');
		resultSpan.html('').removeClass('text-success text-danger');

		var apiKey = $('#api_key_' + provider).val();
		$.ajax({
			url: 'ajax.php?module=voiceai&command=testConnection&provider=' + provider + '&api_key=' + encodeURIComponent(apiKey),
			success: function(data) {
				if (data.status) {
					resultSpan.html('<i class="fa fa-check"></i> ' + data.message).addClass('text-success');
				} else {
					resultSpan.html('<i class="fa fa-times"></i> ' + data.message).addClass('text-danger');
				}
			},
			error: function() {
				resultSpan.html('<i class="fa fa-times"></i> Connection failed').addClass('text-danger');
			},
			complete: function() {
				btn.prop('disabled', false).html('<i class="fa fa-plug"></i> Test');
			}
		});
	});
});
</script>
