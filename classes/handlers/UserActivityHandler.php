<?php

/**
 * @file plugins/generic/userActivityPlugin/classes/handlers/UserActivityHandler.php
 *
 * Copyright (c) 2026
 * Distributed under the GNU GPL v3.
 *
 * @class UserActivityHandler
 * @ingroup plugins_generic_userActivityPlugin
 *
 * @brief Admin-only page and AJAX handler for viewing, filtering, inspecting,
 *        and exporting all system-wide user activities and database audit logs.
 */

namespace APP\plugins\generic\userActivityPlugin\classes\handlers;

use APP\core\Application;
use APP\handler\Handler;
use APP\plugins\generic\userActivityPlugin\classes\UserActivityHelper;
use APP\plugins\generic\userActivityPlugin\classes\UserActivityRecorder;
use APP\plugins\generic\userActivityPlugin\UserActivityPlugin;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\DB;
use PKP\core\JSONMessage;
use PKP\plugins\PluginRegistry;
use PKP\security\authorization\PolicySet;
use PKP\security\authorization\RoleBasedHandlerOperationPolicy;
use PKP\security\authorization\UserRolesRequiredPolicy;
use PKP\security\Role;

class UserActivityHandler extends Handler
{
    /** @var bool Handler is a backend management page */
    public $_isBackendPage = true;

    /** @var UserActivityPlugin|null Plugin reference */
    protected ?UserActivityPlugin $plugin = null;

    /**
     * Constructor
     */
    public function __construct(?UserActivityPlugin $plugin = null)
    {
        parent::__construct();
        $this->plugin = $plugin;

        // Restrict EVERY operation strictly to Site Administrator (ROLE_ID_SITE_ADMIN)
        $this->addRoleAssignment(
            [Role::ROLE_ID_SITE_ADMIN],
            ['index', 'fetchLogs', 'viewDetails', 'exportCsv', 'exportJson', 'purgeLogs']
        );
    }

    /**
     * @copydoc PKPHandler::authorize()
     */
    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new UserRolesRequiredPolicy($request), true);

        $rolePolicy = new PolicySet(PolicySet::COMBINING_PERMIT_OVERRIDES);
        foreach ($roleAssignments as $role => $operations) {
            $rolePolicy->addPolicy(new RoleBasedHandlerOperationPolicy($request, $role, $operations));
        }
        $this->addPolicy($rolePolicy);

        $authorized = parent::authorize($request, $args, $roleAssignments);
        if (!$authorized || !UserActivityHelper::isCurrentUserAdmin($request)) {
            return false;
        }

        return true;
    }

    /**
     * Get the plugin instance
     */
    protected function getPlugin(): UserActivityPlugin
    {
        if (!$this->plugin) {
            $this->plugin = PluginRegistry::getPlugin('generic', 'useractivityplugin')
                ?: PluginRegistry::loadPlugin('generic', 'userActivityPlugin')
                ?: new UserActivityPlugin();
        }
        return $this->plugin;
    }

    /**
     * Enforce admin check and terminate with 403 if not Site Admin
     */
    protected function assertAdminOrAbort($request): void
    {
        if (!UserActivityHelper::isCurrentUserAdmin($request)) {
            header('HTTP/1.1 403 Forbidden');
            echo json_encode(['error' => 'Forbidden: Site Administrator access required.']);
            exit;
        }
    }

    /**
     * Main dashboard page (`index` operation)
     */
    public function index($args, $request)
    {
        $this->assertAdminOrAbort($request);
        $this->setupTemplate($request);

        UserActivityHelper::ensureTableExists();
        UserActivityRecorder::flushPendingLogs();

        $plugin = $this->getPlugin();
        $templateMgr = TemplateManager::getManager($request);

        $baseUrl = $request->getBaseUrl();
        $pluginUrl = $baseUrl . '/' . $plugin->getPluginPath();

        $templateMgr->addStyleSheet(
            'userActivityPluginCss',
            $pluginUrl . '/css/userActivity.css',
            ['contexts' => ['backend']]
        );
        $templateMgr->addJavaScript(
            'userActivityPluginJs',
            $pluginUrl . '/js/userActivity.js',
            ['contexts' => ['backend']]
        );

        // Gather summary statistics and filter dropdown options
        $stats = $this->getSummaryStats();
        $filterOptions = $this->getFilterOptions();

        // Build initial page of logs
        $queryResult = $this->queryLogs($request, 1, 25);

        $router = $request->getRouter();
        $apiUrls = [
            'fetchLogs' => $router->url($request, null, 'userActivity', 'fetchLogs'),
            'viewDetails' => $router->url($request, null, 'userActivity', 'viewDetails'),
            'exportCsv' => $router->url($request, null, 'userActivity', 'exportCsv'),
            'exportJson' => $router->url($request, null, 'userActivity', 'exportJson'),
            'purgeLogs' => $router->url($request, null, 'userActivity', 'purgeLogs'),
        ];

        $templateMgr->assign([
            'pageTitle' => UserActivityHelper::translate('plugins.generic.userActivityPlugin.pageTitle', [], 'System User Activity & Database Audit Log'),
            'stats' => $stats,
            'filterOptions' => $filterOptions,
            'initialLogs' => $queryResult['items'],
            'pagination' => $queryResult['pagination'],
            'apiUrls' => $apiUrls,
            'apiUrlsJson' => json_encode($apiUrls, JSON_UNESCAPED_SLASHES),
            'isModal' => (bool) $request->getUserVar('modal'),
        ]);

        if ($request->getUserVar('modal')) {
            return new JSONMessage(true, $templateMgr->fetch($plugin->getTemplateResource('dashboardModal.tpl')));
        }

        return $templateMgr->display($plugin->getTemplateResource('dashboard.tpl'));
    }

    /**
     * AJAX endpoint: Fetch filtered & paginated logs + live summary stats
     */
    public function fetchLogs($args, $request)
    {
        $this->assertAdminOrAbort($request);
        UserActivityHelper::ensureTableExists();
        UserActivityRecorder::flushPendingLogs();

        $page = max(1, (int) ($request->getUserVar('page') ?: 1));
        $perPage = min(200, max(10, (int) ($request->getUserVar('perPage') ?: 25)));

        $result = $this->queryLogs($request, $page, $perPage);
        $stats = $this->getSummaryStats();

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => true,
            'stats' => $stats,
            'items' => $result['items'],
            'pagination' => $result['pagination'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * AJAX endpoint: View full details of a single activity record
     */
    public function viewDetails($args, $request)
    {
        $this->assertAdminOrAbort($request);
        UserActivityHelper::ensureTableExists();

        $activityId = (int) ($request->getUserVar('activityId') ?: ($args[0] ?? 0));
        $row = DB::table('user_activity_logs')->where('activity_id', $activityId)->first();

        if (!$row) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => false, 'error' => 'Activity record not found.']);
            exit;
        }

        $formatted = $this->formatActivityRow($row, true);

        if ($request->getUserVar('format') === 'html') {
            $templateMgr = TemplateManager::getManager($request);
            $templateMgr->assign(['item' => $formatted]);
            return new JSONMessage(true, $templateMgr->fetch($this->getPlugin()->getTemplateResource('activityDetailModal.tpl')));
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => true,
            'item' => $formatted,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Export filtered activity logs to CSV
     */
    public function exportCsv($args, $request)
    {
        $this->assertAdminOrAbort($request);
        UserActivityHelper::ensureTableExists();
        UserActivityRecorder::flushPendingLogs();

        $builder = $this->buildFilteredQuery($request);
        $rows = $builder->orderByDesc('activity_id')->limit(10000)->get();

        $filename = 'ojs-user-activity-audit-' . date('Y-m-d_His') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        // UTF-8 BOM for Excel
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($out, [
            'Activity ID',
            'Timestamp',
            'User ID',
            'Username',
            'Full Name',
            'Email',
            'User Roles',
            'Context / Journal',
            'IP Address',
            'Category',
            'Event Type',
            'Operation',
            'Database Table',
            'Record ID',
            'Action Summary',
            'HTTP Method',
            'Request URL',
            'Old Values (JSON)',
            'New Values (JSON)',
            'Where Conditions (JSON)',
            'SQL Query',
            'User Agent',
        ]);

        foreach ($rows as $r) {
            fputcsv($out, [
                $r->activity_id,
                $r->created_at,
                $r->user_id ?? '',
                $r->username ?? 'System / Guest',
                $r->user_full_name ?? '',
                $r->user_email ?? '',
                $r->user_roles ?? '',
                $r->context_path ?? 'site',
                $r->ip_address ?? '',
                $r->event_category,
                $r->event_type,
                $r->operation ?? '',
                $r->table_name ?? '',
                $r->record_id ?? '',
                $r->action_summary,
                $r->http_method ?? '',
                $r->request_url ?? '',
                $r->old_values ?? '',
                $r->new_values ?? '',
                $r->where_conditions ?? '',
                $r->sql_query ?? '',
                $r->user_agent ?? '',
            ]);
        }

        fclose($out);
        exit;
    }

    /**
     * Export filtered activity logs to JSON
     */
    public function exportJson($args, $request)
    {
        $this->assertAdminOrAbort($request);
        UserActivityHelper::ensureTableExists();
        UserActivityRecorder::flushPendingLogs();

        $builder = $this->buildFilteredQuery($request);
        $rows = $builder->orderByDesc('activity_id')->limit(10000)->get();

        $items = [];
        foreach ($rows as $r) {
            $items[] = $this->formatActivityRow($r, true);
        }

        $filename = 'ojs-user-activity-audit-' . date('Y-m-d_His') . '.json';

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo json_encode([
            'exportedAt' => date('c'),
            'totalExported' => count($items),
            'records' => $items,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Admin action to purge logs older than N days (or all logs if days=0)
     */
    public function purgeLogs($args, $request)
    {
        $this->assertAdminOrAbort($request);
        UserActivityHelper::ensureTableExists();

        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('HTTP/1.1 405 Method Not Allowed');
            echo json_encode(['status' => false, 'error' => 'Method not allowed']);
            exit;
        }

        $days = (int) ($request->getUserVar('days') ?? 90);
        $deletedCount = 0;

        if ($days <= 0) {
            $deletedCount = DB::table('user_activity_logs')->delete();
        } else {
            $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
            $deletedCount = DB::table('user_activity_logs')
                ->where('created_at', '<', $cutoff)
                ->delete();
        }

        // Record that the admin purged logs
        UserActivityRecorder::recordActivity([
            'event_category' => 'settings',
            'event_type' => 'AUDIT_LOGS_PURGED',
            'action_summary' => $days <= 0
                ? "Admin Purged All Activity Logs ({$deletedCount} records deleted)"
                : "Admin Purged Activity Logs Older Than {$days} Days ({$deletedCount} records deleted)",
            'table_name' => 'user_activity_logs',
            'operation' => 'DELETE',
        ]);
        UserActivityRecorder::flushPendingLogs();

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => true,
            'deletedCount' => $deletedCount,
            'stats' => $this->getSummaryStats(),
        ]);
        exit;
    }

    // =========================================================================
    // Query & Formatting Helpers
    // =========================================================================

    /**
     * Build the filtered Eloquent/QueryBuilder instance from request parameters
     */
    protected function buildFilteredQuery($request)
    {
        $q = DB::table('user_activity_logs');

        $search = trim((string) $request->getUserVar('search'));
        $category = trim((string) $request->getUserVar('category'));
        $operation = trim((string) $request->getUserVar('operation'));
        $tableName = trim((string) $request->getUserVar('tableName'));
        $userId = trim((string) $request->getUserVar('userId'));
        $contextId = trim((string) $request->getUserVar('contextId'));
        $ipAddress = trim((string) $request->getUserVar('ipAddress'));
        $dateFrom = trim((string) $request->getUserVar('dateFrom'));
        $dateTo = trim((string) $request->getUserVar('dateTo'));

        if ($category !== '' && $category !== 'all') {
            $q->where('event_category', $category);
        }

        if ($operation !== '' && $operation !== 'all') {
            $q->where('operation', strtoupper($operation));
        }

        if ($tableName !== '' && $tableName !== 'all') {
            $q->where('table_name', $tableName);
        }

        if ($userId !== '' && $userId !== 'all') {
            if ($userId === 'guest') {
                $q->whereNull('user_id');
            } elseif (is_numeric($userId)) {
                $q->where('user_id', (int) $userId);
            } else {
                $q->where('username', $userId);
            }
        }

        if ($contextId !== '' && $contextId !== 'all') {
            if ($contextId === 'site') {
                $q->whereNull('context_id');
            } elseif (is_numeric($contextId)) {
                $q->where('context_id', (int) $contextId);
            }
        }

        if ($ipAddress !== '') {
            $q->where('ip_address', 'like', '%' . $ipAddress . '%');
        }

        if ($dateFrom !== '') {
            $q->where('created_at', '>=', $dateFrom . ' 00:00:00');
        }

        if ($dateTo !== '') {
            $q->where('created_at', '<=', $dateTo . ' 23:59:59');
        }

        if ($search !== '') {
            $q->where(function ($sub) use ($search) {
                $like = '%' . $search . '%';
                $sub->where('action_summary', 'like', $like)
                    ->orWhere('username', 'like', $like)
                    ->orWhere('user_full_name', 'like', $like)
                    ->orWhere('user_email', 'like', $like)
                    ->orWhere('table_name', 'like', $like)
                    ->orWhere('record_id', 'like', $like)
                    ->orWhere('ip_address', 'like', $like)
                    ->orWhere('event_type', 'like', $like)
                    ->orWhere('request_url', 'like', $like)
                    ->orWhere('new_values', 'like', $like)
                    ->orWhere('old_values', 'like', $like)
                    ->orWhere('sql_query', 'like', $like);
            });
        }

        return $q;
    }

    /**
     * Execute paginated query and format results
     */
    protected function queryLogs($request, int $page = 1, int $perPage = 25): array
    {
        try {
            $builder = $this->buildFilteredQuery($request);
            $total = (clone $builder)->count();
            $totalPages = max(1, (int) ceil($total / $perPage));
            $page = min($page, $totalPages);
            $offset = ($page - 1) * $perPage;

            $rows = $builder
                ->orderByDesc('activity_id')
                ->offset($offset)
                ->limit($perPage)
                ->get();

            $items = [];
            foreach ($rows as $row) {
                $items[] = $this->formatActivityRow($row, false);
            }

            return [
                'items' => $items,
                'pagination' => [
                    'total' => $total,
                    'page' => $page,
                    'perPage' => $perPage,
                    'totalPages' => $totalPages,
                    'from' => $total > 0 ? $offset + 1 : 0,
                    'to' => min($offset + $perPage, $total),
                ],
            ];
        } catch (\Throwable $e) {
            UserActivityHelper::logError('queryLogs error', $e);
            return [
                'items' => [],
                'pagination' => [
                    'total' => 0,
                    'page' => 1,
                    'perPage' => $perPage,
                    'totalPages' => 1,
                    'from' => 0,
                    'to' => 0,
                ],
            ];
        }
    }

    /**
     * Format a database row from `user_activity_logs` for UI rendering
     */
    protected function formatActivityRow(object $row, bool $includeFullPayloads = false): array
    {
        $catBadge = UserActivityHelper::getCategoryBadge((string) $row->event_category);
        $opBadge = UserActivityHelper::getOperationBadge($row->operation);
        $humanTable = $row->table_name ? UserActivityHelper::formatTableName((string) $row->table_name) : '-';

        $oldVals = $row->old_values ? json_decode((string) $row->old_values, true) : null;
        $newVals = $row->new_values ? json_decode((string) $row->new_values, true) : null;
        $whereVals = $row->where_conditions ? json_decode((string) $row->where_conditions, true) : null;
        $metadata = $row->metadata ? json_decode((string) $row->metadata, true) : null;

        $item = [
            'activityId' => (int) $row->activity_id,
            'userId' => $row->user_id ? (int) $row->user_id : null,
            'username' => $row->username ?: 'System / Guest',
            'userFullName' => $row->user_full_name ?: ($row->username ?: 'System / Unauthenticated'),
            'userEmail' => $row->user_email ?: '',
            'userRoles' => $row->user_roles ?: 'Guest',
            'contextId' => $row->context_id ? (int) $row->context_id : null,
            'contextPath' => $row->context_path ?: 'site',
            'sessionId' => $row->session_id ?: '',
            'ipAddress' => $row->ip_address ?: '-',
            'userAgent' => $row->user_agent ?: '',
            'eventCategory' => $row->event_category,
            'categoryBadge' => $catBadge,
            'eventType' => $row->event_type,
            'actionSummary' => $row->action_summary,
            'tableName' => $row->table_name ?: '',
            'humanTable' => $humanTable,
            'operation' => $row->operation ?: 'ACTION',
            'operationBadge' => $opBadge,
            'recordId' => $row->record_id ?: '-',
            'httpMethod' => $row->http_method ?: '',
            'requestUrl' => $row->request_url ?: '',
            'createdAt' => $row->created_at,
            'hasDiff' => !empty($oldVals) || !empty($newVals),
            'hasSql' => !empty($row->sql_query),
        ];

        if ($includeFullPayloads) {
            $item['oldValues'] = $oldVals;
            $item['newValues'] = $newVals;
            $item['whereConditions'] = $whereVals;
            $item['oldValuesRaw'] = $row->old_values ? json_encode($oldVals, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
            $item['newValuesRaw'] = $row->new_values ? json_encode($newVals, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
            $item['whereConditionsRaw'] = $row->where_conditions ? json_encode($whereVals, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
            $item['sqlQuery'] = $row->sql_query;
            $item['requestPayload'] = $row->request_payload;
            $item['metadata'] = $metadata;
            $item['metadataRaw'] = $metadata ? json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        }

        return $item;
    }

    /**
     * Get live summary counters for the top dashboard cards
     */
    public function getSummaryStats(): array
    {
        try {
            $total = DB::table('user_activity_logs')->count();
            $todayStart = date('Y-m-d 00:00:00');
            $todayCount = DB::table('user_activity_logs')->where('created_at', '>=', $todayStart)->count();

            $loginsCount = DB::table('user_activity_logs')
                ->whereIn('operation', ['LOGIN', 'LOGOUT'])
                ->count();

            $failedLoginsCount = DB::table('user_activity_logs')
                ->where('operation', 'LOGIN_FAILED')
                ->count();

            $dbMutationsCount = DB::table('user_activity_logs')
                ->whereIn('operation', ['INSERT', 'UPDATE', 'DELETE', 'REPLACE'])
                ->count();

            $activeUsersCount = DB::table('user_activity_logs')
                ->whereNotNull('user_id')
                ->distinct('user_id')
                ->count('user_id');

            $distinctTablesCount = DB::table('user_activity_logs')
                ->whereNotNull('table_name')
                ->distinct('table_name')
                ->count('table_name');

            return [
                'total' => $total,
                'today' => $todayCount,
                'logins' => $loginsCount,
                'failedLogins' => $failedLoginsCount,
                'dbMutations' => $dbMutationsCount,
                'activeUsers' => $activeUsersCount,
                'distinctTables' => $distinctTablesCount,
            ];
        } catch (\Throwable $e) {
            UserActivityHelper::logError('getSummaryStats error', $e);
            return [
                'total' => 0,
                'today' => 0,
                'logins' => 0,
                'failedLogins' => 0,
                'dbMutations' => 0,
                'activeUsers' => 0,
                'distinctTables' => 0,
            ];
        }
    }

    /**
     * Get distinct tables, users, and journals for filter dropdowns
     */
    protected function getFilterOptions(): array
    {
        try {
            $tables = DB::table('user_activity_logs')
                ->whereNotNull('table_name')
                ->where('table_name', '!=', '')
                ->select('table_name')
                ->distinct()
                ->orderBy('table_name')
                ->pluck('table_name')
                ->toArray();

            $tableOptions = [];
            foreach ($tables as $t) {
                $tableOptions[] = [
                    'value' => $t,
                    'label' => UserActivityHelper::formatTableName($t) . " ({$t})",
                ];
            }

            $users = DB::table('user_activity_logs')
                ->whereNotNull('user_id')
                ->select('user_id', 'username', 'user_full_name')
                ->distinct()
                ->orderBy('username')
                ->limit(300)
                ->get();

            $userOptions = [];
            $seenUserIds = [];
            foreach ($users as $u) {
                if (isset($seenUserIds[$u->user_id])) {
                    continue;
                }
                $seenUserIds[$u->user_id] = true;
                $label = ($u->user_full_name ?: $u->username) . ($u->username ? " (@{$u->username})" : '') . " [#{$u->user_id}]";
                $userOptions[] = [
                    'value' => $u->user_id,
                    'label' => $label,
                ];
            }

            $journals = [];
            try {
                $journalRows = DB::table('journals')->select('journal_id', 'path')->orderBy('seq')->get();
                foreach ($journalRows as $j) {
                    $journals[] = [
                        'value' => $j->journal_id,
                        'label' => $j->path . " (ID: {$j->journal_id})",
                    ];
                }
            } catch (\Throwable) {
            }

            return [
                'tables' => $tableOptions,
                'users' => $userOptions,
                'journals' => $journals,
            ];
        } catch (\Throwable $e) {
            UserActivityHelper::logError('getFilterOptions error', $e);
            return [
                'tables' => [],
                'users' => [],
                'journals' => [],
            ];
        }
    }
}
