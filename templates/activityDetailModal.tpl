{**
 * plugins/generic/userActivityPlugin/templates/activityDetailModal.tpl
 *
 * Copyright (c) 2026
 * Distributed under the GNU GPL v3.
 *
 * Detailed Activity Record Inspection Modal Template.
 *}
<div class="ua-detail-modal-inner">
	<div class="ua-detail-summary-box">
		<h4>{$item.actionSummary|escape}</h4>
		<div class="ua-detail-meta-grid">
			<div><strong>Activity ID:</strong> #{$item.activityId|escape}</div>
			<div><strong>Timestamp:</strong> {$item.createdAt|escape}</div>
			<div><strong>User:</strong> {$item.userFullName|escape} (@{$item.username|escape})</div>
			<div><strong>Roles:</strong> {$item.userRoles|escape}</div>
			<div><strong>IP Address:</strong> <code>{$item.ipAddress|escape}</code></div>
			<div><strong>Context:</strong> {$item.contextPath|escape}</div>
			<div><strong>Table:</strong> <code>{$item.tableName|escape}</code> (Record: {$item.recordId|escape})</div>
			<div><strong>Operation:</strong> <code>{$item.operation|escape}</code></div>
		</div>
	</div>
	{if $item.oldValuesRaw}
		<h5>Previous Values (Before)</h5>
		<pre class="ua-code-block">{$item.oldValuesRaw|escape}</pre>
	{/if}
	{if $item.newValuesRaw}
		<h5>New / Modified Values (After)</h5>
		<pre class="ua-code-block">{$item.newValuesRaw|escape}</pre>
	{/if}
	{if $item.sqlQuery}
		<h5>Executed Database SQL</h5>
		<pre class="ua-code-block">{$item.sqlQuery|escape}</pre>
	{/if}
</div>
