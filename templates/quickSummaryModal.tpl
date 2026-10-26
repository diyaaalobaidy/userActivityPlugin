{**
 * plugins/generic/userActivityPlugin/templates/quickSummaryModal.tpl
 *
 * Copyright (c) 2026
 * Distributed under the GNU GPL v3.
 *
 * Quick summary modal shown to Site Administrators from the OJS Plugin Gallery.
 *}

<div class="ua-quick-modal" style="padding: 12px 16px; font-family: inherit; color: #1e293b;">
	<div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid #e2e8f0;">
		<div>
			<span style="display: inline-block; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; font-size: 0.75rem; font-weight: 700; padding: 2px 8px; border-radius: 12px; margin-bottom: 4px;">
				🛡️ {translate key="plugins.generic.userActivityPlugin.adminOnlyBadge"}
			</span>
			<h3 style="margin: 0; font-size: 1.15rem; color: #0f172a;">{translate key="plugins.generic.userActivityPlugin.displayName"}</h3>
		</div>
		<div style="display: flex; gap: 8px;">
			<a href="{$dashboardUrl|escape}" class="pkp_button pkp_button_primary" style="background: #1d4ed8; color: #fff; text-decoration: none; padding: 6px 14px; border-radius: 6px; font-weight: 600;">
				🚀 {translate key="plugins.generic.userActivityPlugin.openFullDashboard"}
			</a>
			<a href="{$exportCsvUrl|escape}" class="pkp_button" target="_blank" style="text-decoration: none; padding: 6px 12px; border-radius: 6px;">
				📥 CSV
			</a>
			<a href="{$exportJsonUrl|escape}" class="pkp_button" target="_blank" style="text-decoration: none; padding: 6px 12px; border-radius: 6px;">
				📋 JSON
			</a>
		</div>
	</div>

	{* KPI Summary Grid *}
	<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin-bottom: 18px;">
		<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 12px; text-align: center;">
			<div style="font-size: 1.45rem; font-weight: 700; color: #1d4ed8;">{$stats.total|number_format}</div>
			<div style="font-size: 0.78rem; color: #64748b;">{translate key="plugins.generic.userActivityPlugin.stat.totalEvents"}</div>
		</div>
		<div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 10px 12px; text-align: center;">
			<div style="font-size: 1.45rem; font-weight: 700; color: #15803d;">{$stats.today|number_format}</div>
			<div style="font-size: 0.78rem; color: #166534;">{translate key="plugins.generic.userActivityPlugin.stat.todayEvents"}</div>
		</div>
		<div style="background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 8px; padding: 10px 12px; text-align: center;">
			<div style="font-size: 1.45rem; font-weight: 700; color: #4338ca;">{$stats.logins|number_format}</div>
			<div style="font-size: 0.78rem; color: #3730a3;">{translate key="plugins.generic.userActivityPlugin.stat.authEvents"}</div>
		</div>
		<div style="background: #fff1f2; border: 1px solid #fecdd3; border-radius: 8px; padding: 10px 12px; text-align: center;">
			<div style="font-size: 1.45rem; font-weight: 700; color: #be123c;">{$stats.failedLogins|number_format}</div>
			<div style="font-size: 0.78rem; color: #9f1239;">{translate key="plugins.generic.userActivityPlugin.stat.failedLogins"}</div>
		</div>
		<div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 10px 12px; text-align: center;">
			<div style="font-size: 1.45rem; font-weight: 700; color: #b45309;">{$stats.dbMutations|number_format}</div>
			<div style="font-size: 0.78rem; color: #92400e;">{translate key="plugins.generic.userActivityPlugin.stat.dbMutations"}</div>
		</div>
	</div>

	{* Recent 10 Activities Preview *}
	<h4 style="margin: 0 0 8px 0; font-size: 0.95rem; color: #334155;">⚡ {translate key="plugins.generic.userActivityPlugin.recentActivities"}</h4>
	<div style="overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 6px; margin-bottom: 12px;">
		<table style="width: 100%; border-collapse: collapse; font-size: 0.82rem;">
			<thead>
				<tr style="background: #f1f5f9; color: #475569; text-align: left;">
					<th style="padding: 8px 10px; border-bottom: 1px solid #cbd5e1;">{translate key="common.date"}</th>
					<th style="padding: 8px 10px; border-bottom: 1px solid #cbd5e1;">{translate key="common.user"}</th>
					<th style="padding: 8px 10px; border-bottom: 1px solid #cbd5e1;">{translate key="plugins.generic.userActivityPlugin.table.operation"}</th>
					<th style="padding: 8px 10px; border-bottom: 1px solid #cbd5e1;">{translate key="plugins.generic.userActivityPlugin.table.dbTableRecord"}</th>
					<th style="padding: 8px 10px; border-bottom: 1px solid #cbd5e1;">{translate key="plugins.generic.userActivityPlugin.table.actionSummary"}</th>
					<th style="padding: 8px 10px; border-bottom: 1px solid #cbd5e1;">IP</th>
				</tr>
			</thead>
			<tbody>
				{if $recentLogs|@count > 0}
					{foreach from=$recentLogs item=r}
						<tr style="border-bottom: 1px solid #f1f5f9;">
							<td style="padding: 7px 10px; white-space: nowrap; color: #475569;">{$r->created_at|escape}</td>
							<td style="padding: 7px 10px; font-weight: 600;">
								{$r->user_full_name|default:$r->username|default:"System / Guest"|escape}
							</td>
							<td style="padding: 7px 10px; font-family: monospace; font-weight: 700;">{$r->operation|escape}</td>
							<td style="padding: 7px 10px; font-family: monospace; color: #334155;">
								{$r->table_name|default:"-"|escape}
								{if $r->record_id}#{$r->record_id|escape}{/if}
							</td>
							<td style="padding: 7px 10px;">{$r->action_summary|escape}</td>
							<td style="padding: 7px 10px; font-family: monospace; color: #64748b;">{$r->ip_address|escape}</td>
						</tr>
					{/foreach}
				{else}
					<tr>
						<td colspan="6" style="padding: 16px; text-align: center; color: #64748b;">{translate key="plugins.generic.userActivityPlugin.noLogsFound"}</td>
					</tr>
				{/if}
			</tbody>
		</table>
	</div>

	<div style="text-align: right;">
		<a href="{$dashboardUrl|escape}" style="font-size: 0.88rem; font-weight: 600; color: #1d4ed8; text-decoration: underline;">
			{translate key="plugins.generic.userActivityPlugin.openFullDashboard"} &rarr;
		</a>
	</div>
</div>
