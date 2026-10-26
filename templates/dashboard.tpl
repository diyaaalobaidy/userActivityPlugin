{**
 * plugins/generic/userActivityPlugin/templates/dashboard.tpl
 *
 * Copyright (c) 2026
 * Distributed under the GNU GPL v3.
 *
 * Full-page Admin Audit Dashboard for viewing, filtering, inspecting, and exporting
 * all user activity from login/logout to any database action.
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
<div id="userActivityDashboard" class="ua-dashboard-container" data-api-urls='{$apiUrlsJson|escape:"html"}'>
	{* Top Header Banner *}
	<div class="ua-header-banner">
		<div class="ua-header-title-area">
			<div class="ua-shield-badge">🛡️ {translate key="plugins.generic.userActivityPlugin.adminOnlyBadge"}</div>
			<h1 class="ua-page-heading">{translate key="plugins.generic.userActivityPlugin.pageTitle"}</h1>
			<p class="ua-page-subtitle">{translate key="plugins.generic.userActivityPlugin.pageSubtitle"}</p>
		</div>
		<div class="ua-header-actions">
			<button type="button" id="uaRefreshBtn" class="ua-btn ua-btn-secondary" title="{translate key="plugins.generic.userActivityPlugin.refresh"}">
				🔄 {translate key="plugins.generic.userActivityPlugin.refresh"}
			</button>
			<a href="{$apiUrls.exportCsv|escape}" id="uaExportCsvBtn" class="ua-btn ua-btn-primary" target="_blank">
				📥 {translate key="plugins.generic.userActivityPlugin.exportCsv"}
			</a>
			<a href="{$apiUrls.exportJson|escape}" id="uaExportJsonBtn" class="ua-btn ua-btn-dark" target="_blank">
				📋 {translate key="plugins.generic.userActivityPlugin.exportJson"}
			</a>
			<button type="button" id="uaPurgeOpenBtn" class="ua-btn ua-btn-danger">
				🗑️ {translate key="plugins.generic.userActivityPlugin.retentionPurge"}
			</button>
		</div>
	</div>

	{* Live Summary KPI Cards *}
	<div class="ua-kpi-grid">
		<div class="ua-kpi-card ua-kpi-blue">
			<div class="ua-kpi-value" id="uaStatTotal">{$stats.total|number_format}</div>
			<div class="ua-kpi-label">{translate key="plugins.generic.userActivityPlugin.stat.totalEvents"}</div>
		</div>
		<div class="ua-kpi-card ua-kpi-emerald">
			<div class="ua-kpi-value" id="uaStatToday">{$stats.today|number_format}</div>
			<div class="ua-kpi-label">{translate key="plugins.generic.userActivityPlugin.stat.todayEvents"}</div>
		</div>
		<div class="ua-kpi-card ua-kpi-indigo">
			<div class="ua-kpi-value" id="uaStatLogins">{$stats.logins|number_format}</div>
			<div class="ua-kpi-label">{translate key="plugins.generic.userActivityPlugin.stat.authEvents"}</div>
		</div>
		<div class="ua-kpi-card ua-kpi-rose">
			<div class="ua-kpi-value" id="uaStatFailedLogins">{$stats.failedLogins|number_format}</div>
			<div class="ua-kpi-label">{translate key="plugins.generic.userActivityPlugin.stat.failedLogins"}</div>
		</div>
		<div class="ua-kpi-card ua-kpi-amber">
			<div class="ua-kpi-value" id="uaStatDbMutations">{$stats.dbMutations|number_format}</div>
			<div class="ua-kpi-label">{translate key="plugins.generic.userActivityPlugin.stat.dbMutations"}</div>
		</div>
		<div class="ua-kpi-card ua-kpi-purple">
			<div class="ua-kpi-value" id="uaStatActiveUsers">{$stats.activeUsers|number_format}</div>
			<div class="ua-kpi-label">{translate key="plugins.generic.userActivityPlugin.stat.activeUsers"}</div>
		</div>
	</div>

	{* Multi-Dimension Filter Bar *}
	<form id="uaFilterForm" class="ua-filter-panel" onsubmit="return false;">
		<div class="ua-filter-row">
			<div class="ua-filter-group ua-filter-search">
				<label for="uaSearchInput">{translate key="common.search"}</label>
				<input type="text" id="uaSearchInput" name="search" class="ua-input" placeholder="{translate key="plugins.generic.userActivityPlugin.filter.searchPlaceholder"}">
			</div>

			<div class="ua-filter-group">
				<label for="uaCategorySelect">{translate key="plugins.generic.userActivityPlugin.filter.category"}</label>
				<select id="uaCategorySelect" name="category" class="ua-select">
					<option value="all">{translate key="plugins.generic.userActivityPlugin.filter.allCategories"}</option>
					<option value="auth">🔐 {translate key="plugins.generic.userActivityPlugin.category.auth"}</option>
					<option value="user">👤 {translate key="plugins.generic.userActivityPlugin.category.user"}</option>
					<option value="submission">📄 {translate key="plugins.generic.userActivityPlugin.category.submission"}</option>
					<option value="publication">📢 {translate key="plugins.generic.userActivityPlugin.category.publication"}</option>
					<option value="review">⚖️ {translate key="plugins.generic.userActivityPlugin.category.review"}</option>
					<option value="file">📁 {translate key="plugins.generic.userActivityPlugin.category.file"}</option>
					<option value="email">✉️ {translate key="plugins.generic.userActivityPlugin.category.email"}</option>
					<option value="settings">⚙️ {translate key="plugins.generic.userActivityPlugin.category.settings"}</option>
					<option value="database">🗄️ {translate key="plugins.generic.userActivityPlugin.category.database"}</option>
				</select>
			</div>

			<div class="ua-filter-group">
				<label for="uaOperationSelect">{translate key="plugins.generic.userActivityPlugin.filter.operation"}</label>
				<select id="uaOperationSelect" name="operation" class="ua-select">
					<option value="all">{translate key="plugins.generic.userActivityPlugin.filter.allOperations"}</option>
					<option value="LOGIN">LOGIN</option>
					<option value="LOGOUT">LOGOUT</option>
					<option value="LOGIN_FAILED">LOGIN_FAILED</option>
					<option value="INSERT">INSERT (DB Create)</option>
					<option value="UPDATE">UPDATE (DB Modify)</option>
					<option value="DELETE">DELETE (DB Remove)</option>
					<option value="REPLACE">REPLACE</option>
				</select>
			</div>

			<div class="ua-filter-group">
				<label for="uaTableSelect">{translate key="plugins.generic.userActivityPlugin.filter.table"}</label>
				<select id="uaTableSelect" name="tableName" class="ua-select">
					<option value="all">{translate key="plugins.generic.userActivityPlugin.filter.allTables"}</option>
					{foreach from=$filterOptions.tables item=tbl}
						<option value="{$tbl.value|escape}">{$tbl.label|escape}</option>
					{/foreach}
				</select>
			</div>
		</div>

		<div class="ua-filter-row ua-filter-row-secondary">
			<div class="ua-filter-group">
				<label for="uaUserSelect">{translate key="common.user"}</label>
				<select id="uaUserSelect" name="userId" class="ua-select">
					<option value="all">{translate key="plugins.generic.userActivityPlugin.filter.allUsers"}</option>
					<option value="guest">{translate key="plugins.generic.userActivityPlugin.filter.systemOrGuest"}</option>
					{foreach from=$filterOptions.users item=usr}
						<option value="{$usr.value|escape}">{$usr.label|escape}</option>
					{/foreach}
				</select>
			</div>

			<div class="ua-filter-group">
				<label for="uaContextSelect">{translate key="plugins.generic.userActivityPlugin.filter.journalContext"}</label>
				<select id="uaContextSelect" name="contextId" class="ua-select">
					<option value="all">{translate key="plugins.generic.userActivityPlugin.filter.allContexts"}</option>
					<option value="site">Site-Wide (Admin)</option>
					{foreach from=$filterOptions.journals item=jnl}
						<option value="{$jnl.value|escape}">{$jnl.label|escape}</option>
					{/foreach}
				</select>
			</div>

			<div class="ua-filter-group">
				<label for="uaIpInput">{translate key="plugins.generic.userActivityPlugin.filter.ipAddress"}</label>
				<input type="text" id="uaIpInput" name="ipAddress" class="ua-input" placeholder="e.g. 192.168.1.1">
			</div>

			<div class="ua-filter-group">
				<label for="uaDateFrom">{translate key="plugins.generic.userActivityPlugin.filter.dateFrom"}</label>
				<input type="date" id="uaDateFrom" name="dateFrom" class="ua-input">
			</div>

			<div class="ua-filter-group">
				<label for="uaDateTo">{translate key="plugins.generic.userActivityPlugin.filter.dateTo"}</label>
				<input type="date" id="uaDateTo" name="dateTo" class="ua-input">
			</div>

			<div class="ua-filter-group">
				<label for="uaPerPageSelect">{translate key="plugins.generic.userActivityPlugin.filter.perPage"}</label>
				<select id="uaPerPageSelect" name="perPage" class="ua-select">
					<option value="25">25</option>
					<option value="50">50</option>
					<option value="100">100</option>
					<option value="200">200</option>
				</select>
			</div>

			<div class="ua-filter-actions">
				<button type="submit" id="uaApplyFilterBtn" class="ua-btn ua-btn-primary">
					🔍 {translate key="common.search"}
				</button>
				<button type="button" id="uaResetFilterBtn" class="ua-btn ua-btn-light">
					✖ {translate key="plugins.generic.userActivityPlugin.filter.reset"}
				</button>
			</div>
		</div>
	</form>

	{* Main Audit Log Table *}
	<div class="ua-table-card">
		<div class="ua-table-toolbar">
			<div class="ua-showing-info" id="uaShowingInfo">
				Showing <strong>{$pagination.from}</strong> &ndash; <strong>{$pagination.to}</strong> of <strong>{$pagination.total}</strong> recorded activities
			</div>
			<div class="ua-pagination-controls" id="uaPaginationTop"></div>
		</div>

		<div class="ua-table-responsive">
			<table class="ua-audit-table">
				<thead>
					<tr>
						<th style="width: 70px;">ID</th>
						<th style="width: 155px;">{translate key="common.date"}</th>
						<th style="width: 190px;">{translate key="common.user"}</th>
						<th style="width: 130px;">{translate key="plugins.generic.userActivityPlugin.table.category"}</th>
						<th style="width: 110px;">{translate key="plugins.generic.userActivityPlugin.table.operation"}</th>
						<th style="width: 165px;">{translate key="plugins.generic.userActivityPlugin.table.dbTableRecord"}</th>
						<th>{translate key="plugins.generic.userActivityPlugin.table.actionSummary"}</th>
						<th style="width: 130px;">{translate key="plugins.generic.userActivityPlugin.table.ipContext"}</th>
						<th style="width: 110px; text-align: center;">{translate key="common.moreActions"}</th>
					</tr>
				</thead>
				<tbody id="uaAuditTableBody">
					{if $initialLogs|@count > 0}
						{foreach from=$initialLogs item=log}
							<tr data-activity-id="{$log.activityId|escape}">
								<td class="ua-mono ua-text-muted">#{$log.activityId|escape}</td>
								<td class="ua-date-cell">{$log.createdAt|escape}</td>
								<td class="ua-user-cell">
									<div class="ua-user-name">{$log.userFullName|escape}</div>
									<div class="ua-user-meta">
										{if $log.userId}
											<span class="ua-mono">@{$log.username|escape}</span> (#{$log.userId|escape})
										{else}
											<span class="ua-text-muted">{$log.username|escape}</span>
										{/if}
									</div>
									{if $log.userRoles}
										<span class="ua-role-pill">{$log.userRoles|escape}</span>
									{/if}
								</td>
								<td>
									<span class="ua-cat-badge {$log.categoryBadge.class|escape}">
										{$log.categoryBadge.icon} {$log.categoryBadge.label|escape}
									</span>
								</td>
								<td>
									<span class="ua-op-badge {$log.operationBadge.class|escape}">
										{$log.operationBadge.label|escape}
									</span>
								</td>
								<td>
									{if $log.tableName}
										<div class="ua-table-name">{$log.humanTable|escape}</div>
										<div class="ua-mono ua-text-xs ua-text-muted">{$log.tableName|escape} {if $log.recordId && $log.recordId !== '-'}#{$log.recordId|escape}{/if}</div>
									{else}
										<span class="ua-text-muted">&mdash;</span>
									{/if}
								</td>
								<td class="ua-summary-cell">
									<div class="ua-summary-text">{$log.actionSummary|escape}</div>
									{if $log.requestUrl}
										<div class="ua-request-line ua-mono">
											<span class="ua-http-method">{$log.httpMethod|escape}</span> {$log.requestUrl|escape|truncate:85:"..."}
										</div>
									{/if}
								</td>
								<td>
									<div class="ua-mono ua-ip-tag">{$log.ipAddress|escape}</div>
									<div class="ua-context-tag">{$log.contextPath|escape}</div>
								</td>
								<td style="text-align: center;">
									<button type="button" class="ua-btn ua-btn-xs ua-btn-inspect ua-inspect-btn" data-id="{$log.activityId|escape}">
										🔍 {translate key="plugins.generic.userActivityPlugin.inspect"}
									</button>
								</td>
							</tr>
						{/foreach}
					{else}
						<tr>
							<td colspan="9" class="ua-empty-row">
								{translate key="plugins.generic.userActivityPlugin.noLogsFound"}
							</td>
						</tr>
					{/if}
				</tbody>
			</table>
		</div>

		<div class="ua-table-footer">
			<div class="ua-pagination-info" id="uaPageInfoBottom">
				Page {$pagination.page} of {$pagination.totalPages}
			</div>
			<div class="ua-pagination-controls" id="uaPaginationBottom" data-page="{$pagination.page}" data-total-pages="{$pagination.totalPages}"></div>
		</div>
	</div>

	{* Detail Inspection Modal *}
	<div id="uaDetailModalBackdrop" class="ua-modal-backdrop" style="display: none;">
		<div class="ua-modal-dialog">
			<div class="ua-modal-header">
				<h3 id="uaModalTitle">🔍 {translate key="plugins.generic.userActivityPlugin.modalTitle"}</h3>
				<button type="button" id="uaModalCloseBtn" class="ua-modal-close">&times;</button>
			</div>
			<div class="ua-modal-body" id="uaModalBodyContent">
				<div class="ua-loading-spinner">Loading activity details...</div>
			</div>
			<div class="ua-modal-footer">
				<button type="button" id="uaCopyJsonBtn" class="ua-btn ua-btn-secondary">📋 {translate key="plugins.generic.userActivityPlugin.copyJson"}</button>
				<button type="button" id="uaModalFooterCloseBtn" class="ua-btn ua-btn-primary">{translate key="common.close"}</button>
			</div>
		</div>
	</div>

	{* Purge / Retention Modal *}
	<div id="uaPurgeModalBackdrop" class="ua-modal-backdrop" style="display: none;">
		<div class="ua-modal-dialog ua-modal-sm">
			<div class="ua-modal-header">
				<h3>🗑️ {translate key="plugins.generic.userActivityPlugin.retentionPurge"}</h3>
				<button type="button" id="uaPurgeModalCloseBtn" class="ua-modal-close">&times;</button>
			</div>
			<div class="ua-modal-body">
				<p>{translate key="plugins.generic.userActivityPlugin.purgeDescription"}</p>
				<div class="ua-filter-group" style="margin-top: 12px;">
					<label for="uaPurgeDaysSelect">{translate key="plugins.generic.userActivityPlugin.purgeOlderThan"}</label>
					<select id="uaPurgeDaysSelect" class="ua-select">
						<option value="180">Older than 180 days (6 months)</option>
						<option value="90" selected>Older than 90 days (3 months)</option>
						<option value="30">Older than 30 days (1 month)</option>
						<option value="7">Older than 7 days (1 week)</option>
						<option value="0">Clear ALL logs immediately</option>
					</select>
				</div>
			</div>
			<div class="ua-modal-footer">
				<button type="button" id="uaPurgeCancelBtn" class="ua-btn ua-btn-light">{translate key="common.cancel"}</button>
				<button type="button" id="uaPurgeConfirmBtn" class="ua-btn ua-btn-danger">🗑️ {translate key="plugins.generic.userActivityPlugin.confirmPurge"}</button>
			</div>
		</div>
	</div>
</div>
{/block}
