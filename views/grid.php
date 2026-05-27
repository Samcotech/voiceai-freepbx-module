<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
$voiceai = FreePBX::Voiceai();
$agents = $voiceai->getAllAgents();
$providers = \FreePBX\modules\Voiceai::getProviderList();
?>
<div class="display no-border">
	<div class="row">
		<div class="col-sm-12">
			<div class="fpbx-container">
				<div class="display full-border">
					<div class="row">
						<div class="col-sm-12">
							<h1><?php echo _('Voice AI Agents'); ?>
								<div class="pull-right">
									<a href="?display=voiceai&view=settings" class="btn btn-default">
										<i class="fa fa-cog"></i> <?php echo _('Provider Settings'); ?>
									</a>
									<a href="?display=voiceai&view=form" class="btn btn-primary">
										<i class="fa fa-plus"></i> <?php echo _('Add AI Agent'); ?>
									</a>
								</div>
							</h1>
						</div>
					</div>
					<table class="table table-striped table-hover">
						<thead>
							<tr>
								<th><?php echo _('Name'); ?></th>
								<th><?php echo _('Provider'); ?></th>
								<th><?php echo _('Agent ID'); ?></th>
								<th><?php echo _('SIP Endpoint'); ?></th>
								<th><?php echo _('Timeout'); ?></th>
								<th><?php echo _('Status'); ?></th>
									<th style="width:120px"><?php echo _('Actions'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if (empty($agents)): ?>
								<tr>
									<td colspan="7" class="text-center">
										<br/>
										<p><?php echo _('No AI agents configured yet.'); ?></p>
										<p><?php echo _('First, configure your API keys in'); ?>
											<a href="?display=voiceai&view=settings"><strong><?php echo _('Provider Settings'); ?></strong></a>,
											<?php echo _('then click "Add AI Agent" to connect an agent.'); ?>
										</p>
										<br/>
									</td>
								</tr>
							<?php else: ?>
								<?php foreach ($agents as $agent): ?>
									<?php $pName = $providers[$agent['provider']]['name'] ?? ucfirst($agent['provider']); ?>
									<tr>
										<td>
											<a href="?display=voiceai&view=form&id=<?php echo $agent['id']; ?>">
												<strong><?php echo htmlspecialchars($agent['name']); ?></strong>
											</a>
										</td>
										<td><span class="label label-info"><?php echo $pName; ?></span></td>
										<td><code><?php echo htmlspecialchars(substr($agent['remote_agent_id'], 0, 20)); ?><?php echo strlen($agent['remote_agent_id']) > 20 ? '...' : ''; ?></code></td>
										<td>
											<?php if ($agent['provider'] === 'retell'): ?>
												<span class="text-muted"><i class="fa fa-refresh"></i> <?php echo _('Dynamic (per-call)'); ?></span>
											<?php else: ?>
												<code><?php echo htmlspecialchars($agent['sip_user'] ? $agent['sip_user'] . '@' . $agent['sip_uri'] : $agent['sip_uri']); ?></code>
											<?php endif; ?>
										</td>
										<td><?php echo (int)$agent['timeout']; ?>s</td>
										<td>
											<?php if ($agent['enabled']): ?>
												<span class="label label-success"><?php echo _('Enabled'); ?></span>
											<?php else: ?>
												<span class="label label-default"><?php echo _('Disabled'); ?></span>
											<?php endif; ?>
										</td>
										<td>
											<a href="?display=voiceai&view=form&id=<?php echo $agent['id']; ?>" class="btn btn-default btn-xs" title="<?php echo _('Edit'); ?>">
												<i class="fa fa-pencil"></i>
											</a>
											<a href="#" class="btn btn-danger btn-xs btn-delete-agent" data-id="<?php echo $agent['id']; ?>" data-name="<?php echo htmlspecialchars($agent['name']); ?>" title="<?php echo _('Delete'); ?>">
												<i class="fa fa-trash"></i>
											</a>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
</div>
<script>
$(document).ready(function() {
	$('.btn-delete-agent').on('click', function(e) {
		e.preventDefault();
		var id = $(this).data('id');
		var name = $(this).data('name');
		if (confirm('<?php echo _('Are you sure you want to delete agent'); ?> "' + name + '"?')) {
			window.location.href = '?display=voiceai&action=delete&id=' + id;
		}
	});
});
</script>
