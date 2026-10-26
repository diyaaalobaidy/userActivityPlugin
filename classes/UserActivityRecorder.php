<?php

/**
 * @file plugins/generic/userActivityPlugin/classes/UserActivityRecorder.php
 *
 * Copyright (c) 2026
 * Distributed under the GNU GPL v3.
 *
 * @class UserActivityRecorder
 * @ingroup plugins_generic_userActivityPlugin
 *
 * @brief Universal system-wide recorder that captures all user activity across OJS 3.5:
 *        - User Authentication (Login, Logout, Failed Login, Registration, Impersonation)
 *        - Every Database Mutation (INSERT, UPDATE, DELETE, REPLACE) across all OJS tables
 *        - High-level Domain Repository Hooks (Users, Submissions, Publications, Authors,
 *          Files, Reviews, Issues, Stage Assignments, Discussions) with old vs. new diffs.
 */

namespace APP\plugins\generic\userActivityPlugin\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\issue\Issue;
use APP\publication\Publication;
use APP\submission\Submission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PKP\author\Author;
use PKP\note\Note;
use PKP\plugins\Hook;
use PKP\query\Query;
use PKP\query\QueryParticipant;
use PKP\stageAssignment\StageAssignment;
use PKP\submission\reviewAssignment\ReviewAssignment;
use PKP\submissionFile\SubmissionFile;
use PKP\user\User;

class UserActivityRecorder
{
    /** @var bool Reentrancy guard to prevent recursive logging loops */
    private static bool $isLogging = false;

    /** @var bool Initialization guard */
    private static bool $isInitialized = false;

    /** @var array In-memory queue of domain & auth events to flush at shutdown */
    private static array $pendingEvents = [];

    /** @var array In-memory queue of raw SQL mutations to flush at shutdown */
    private static array $pendingQueries = [];

    /** @var array Registry to avoid duplicate domain/raw logs when desired */
    private static array $handledMutations = [];

    /** @var bool Flag to prevent duplicate login logs within the same request */
    private static bool $loginRecordedInRequest = false;

    /** @var bool Flag to prevent duplicate logout logs within the same request */
    private static bool $logoutRecordedInRequest = false;

    /** @var int|null Initial user ID at request start to detect session transitions */
    private static ?int $initialUserId = null;

    /**
     * Tables excluded from raw SQL logging to prevent infinite recursion or high-frequency noise
     */
    private static array $ignoredTables = [
        'user_activity_logs' => true,
        'cache' => true,
        'cache_locks' => true,
        'metrics' => true,
        'usage_stats_total_temporary_records' => true,
        'usage_stats_unique_item_investigations_temporary_records' => true,
        'usage_stats_unique_item_requests_temporary_records' => true,
        'usage_stats_Unique_title_investigations_temporary_records' => true,
        'usage_stats_Unique_title_requests_temporary_records' => true,
        'usage_stats_institution_temporary_records' => true,
    ];

    /**
     * Initialize all listeners for database mutations, authentication, and domain hooks
     */
    public static function init(): void
    {
        if (self::$isInitialized) {
            return;
        }
        self::$isInitialized = true;

        try {
            // Capture initial user ID if available
            try {
                self::$initialUserId = Application::get()->getRequest()?->getUser()?->getId()
                    ? (int) Application::get()->getRequest()->getUser()->getId()
                    : null;
            } catch (\Throwable) {
                self::$initialUserId = null;
            }

            // Register shutdown function to safely flush all queued logs at the end of the request
            register_shutdown_function(self::flushPendingLogs(...));

            // 1. Universal Laravel Database Query Listener (captures all INSERT, UPDATE, DELETE, REPLACE)
            DB::listen(self::onDatabaseQuery(...));

            // 2. Authentication & Session Lifecycle Hooks
            Hook::add('LoadHandler', self::onLoadHandler(...));
            Hook::add('Validation::login', self::onValidationLogin(...));
            Hook::add('Validation::logout', self::onValidationLogout(...));
            Hook::add('SessionManager::updateSession', self::onSessionUpdate(...));

            // Listen to Laravel Auth events if fired in OJS 3.5
            if (class_exists(Event::class)) {
                try {
                    Event::listen(\Illuminate\Auth\Events\Login::class, self::onLaravelLogin(...));
                    Event::listen(\Illuminate\Auth\Events\Logout::class, self::onLaravelLogout(...));
                    Event::listen(\Illuminate\Auth\Events\Failed::class, self::onLaravelLoginFailed(...));
                } catch (\Throwable) {
                }
            }

            // 3. High-Level PKP Repository Hooks (with Old vs. New value diffs)
            Hook::add('User::add', self::onUserAdd(...));
            Hook::add('User::edit', self::onUserEdit(...));
            Hook::add('User::delete::before', self::onUserDelete(...));

            Hook::add('Submission::add', self::onSubmissionAdd(...));
            Hook::add('Submission::edit', self::onSubmissionEdit(...));
            Hook::add('Submission::delete::before', self::onSubmissionDelete(...));

            Hook::add('Publication::add', self::onPublicationAdd(...));
            Hook::add('Publication::edit', self::onPublicationEdit(...));
            Hook::add('Publication::publish::before', self::onPublicationPublish(...));
            Hook::add('Publication::unpublish', self::onPublicationUnpublish(...));
            Hook::add('Publication::version', self::onPublicationVersion(...));
            Hook::add('Publication::delete::before', self::onPublicationDelete(...));

            Hook::add('Author::add', self::onAuthorAdd(...));
            Hook::add('Author::edit', self::onAuthorEdit(...));
            Hook::add('Author::delete::before', self::onAuthorDelete(...));

            Hook::add('SubmissionFile::add', self::onSubmissionFileAdd(...));
            Hook::add('SubmissionFile::edit', self::onSubmissionFileEdit(...));
            Hook::add('SubmissionFile::delete::before', self::onSubmissionFileDelete(...));

            Hook::add('ReviewAssignment::add', self::onReviewAssignmentAdd(...));
            Hook::add('ReviewAssignment::edit', self::onReviewAssignmentEdit(...));
            Hook::add('ReviewAssignment::delete::before', self::onReviewAssignmentDelete(...));

            Hook::add('Issue::add', self::onIssueAdd(...));
            Hook::add('Issue::edit', self::onIssueEdit(...));
            Hook::add('Issue::delete::before', self::onIssueDelete(...));

            Hook::add('Decision::add', self::onDecisionAdd(...));

            // 4. Eloquent Model Observers for models without Repository hooks
            if (class_exists(StageAssignment::class)) {
                StageAssignment::saved(self::onStageAssignmentSaved(...));
                StageAssignment::deleted(self::onStageAssignmentDeleted(...));
            }
            if (class_exists(Query::class)) {
                Query::saved(self::onQuerySaved(...));
                Query::deleted(self::onQueryDeleted(...));
            }
            if (class_exists(QueryParticipant::class)) {
                QueryParticipant::saved(self::onQueryParticipantSaved(...));
                QueryParticipant::deleted(self::onQueryParticipantDeleted(...));
            }
            if (class_exists(Note::class)) {
                Note::saved(self::onNoteSaved(...));
                Note::deleted(self::onNoteDeleted(...));
            }
        } catch (\Throwable $e) {
            UserActivityHelper::logError('UserActivityRecorder::init failed', $e);
        }
    }

    /**
     * Mark a specific table/record/operation as already enriched by a high-level domain hook
     */
    public static function markHandled(string $table, mixed $recordId, string $operation): void
    {
        if ($recordId === null || $recordId === '') {
            return;
        }
        $idStr = is_scalar($recordId) ? (string) $recordId : json_encode($recordId);
        $key = strtolower("{$table}:{$idStr}:{$operation}");
        self::$handledMutations[$key] = true;
    }

    /**
     * Check if a specific table/record/operation was already enriched by a high-level domain hook
     */
    public static function isHandled(string $table, mixed $recordId, string $operation): bool
    {
        if ($recordId === null || $recordId === '') {
            return false;
        }
        $idStr = is_scalar($recordId) ? (string) $recordId : json_encode($recordId);
        $key = strtolower("{$table}:{$idStr}:{$operation}");
        return isset(self::$handledMutations[$key]);
    }

    /**
     * Queue a rich activity event in memory for safe deferred persistence
     */
    public static function recordActivity(array $entry): void
    {
        if (self::$isLogging) {
            return;
        }

        try {
            $contextSnap = UserActivityHelper::getContextSnapshot();
            $currentUserId = $entry['user_id'] ?? null;
            if ($currentUserId === null) {
                try {
                    $u = Application::get()->getRequest()?->getUser();
                    $currentUserId = $u?->getId() ? (int) $u->getId() : null;
                } catch (\Throwable) {
                }
            }

            self::$pendingEvents[] = [
                'user_id' => $currentUserId,
                'username' => $entry['username'] ?? null,
                'user_full_name' => $entry['user_full_name'] ?? null,
                'user_email' => $entry['user_email'] ?? null,
                'user_roles' => $entry['user_roles'] ?? null,
                'context_id' => $entry['context_id'] ?? $contextSnap['context_id'],
                'context_path' => $entry['context_path'] ?? $contextSnap['context_path'],
                'session_id' => $entry['session_id'] ?? UserActivityHelper::getSessionIdentifier(),
                'ip_address' => $entry['ip_address'] ?? UserActivityHelper::getRealClientIp(),
                'user_agent' => $entry['user_agent'] ?? UserActivityHelper::getUserAgent(),
                'event_category' => $entry['event_category'] ?? 'system',
                'event_type' => $entry['event_type'] ?? 'ACTION',
                'action_summary' => substr((string) ($entry['action_summary'] ?? 'User Activity'), 0, 500),
                'table_name' => $entry['table_name'] ?? null,
                'operation' => $entry['operation'] ?? 'ACTION',
                'record_id' => isset($entry['record_id']) ? (string) $entry['record_id'] : null,
                'http_method' => $entry['http_method'] ?? ($_SERVER['REQUEST_METHOD'] ?? (PHP_SAPI === 'cli' ? 'CLI' : 'GET')),
                'request_url' => $entry['request_url'] ?? ($_SERVER['REQUEST_URI'] ?? (PHP_SAPI === 'cli' ? implode(' ', $_SERVER['argv'] ?? []) : null)),
                'old_values' => $entry['old_values'] ?? null,
                'new_values' => $entry['new_values'] ?? null,
                'where_conditions' => $entry['where_conditions'] ?? null,
                'sql_query' => $entry['sql_query'] ?? null,
                'request_payload' => $entry['request_payload'] ?? UserActivityHelper::getRequestPayload(),
                'metadata' => $entry['metadata'] ?? null,
                'created_at' => $entry['created_at'] ?? date('Y-m-d H:i:s'),
            ];
        } catch (\Throwable $e) {
            UserActivityHelper::logError('Failed queueing activity event', $e);
        }
    }

    /**
     * Universal Database Query Listener (`DB::listen`).
     * Captures every INSERT, UPDATE, DELETE, and REPLACE query executed on the database
     * completely in-memory so `PDO::lastInsertId()` is never disturbed during active transactions.
     */
    public static function onDatabaseQuery(mixed $query): void
    {
        if (self::$isLogging || !is_object($query) || !isset($query->sql)) {
            return;
        }

        try {
            $sql = trim((string) $query->sql);
            $firstWord = strtolower(strtok($sql, " \t\n\r"));
            if (!in_array($firstWord, ['insert', 'update', 'delete', 'replace'], true)) {
                return;
            }

            if (!preg_match('/^\s*(insert(?:\s+ignore)?\s+into|update|delete\s+from|replace\s+into)\s+[`"]?([a-zA-Z0-9_]+)[`"]?/i', $sql, $matches)) {
                return;
            }

            $opParts = preg_split('/\s+/', trim($matches[1]));
            $operation = strtoupper($opParts[0]);
            $table = strtolower($matches[2]);

            // Skip self-logging and temporary stats noise tables
            if (isset(self::$ignoredTables[$table])) {
                return;
            }

            $bindings = is_array($query->bindings ?? null) ? $query->bindings : [];

            // Parse affected columns and WHERE clauses in-memory
            $data = [];
            $whereData = [];

            if ($operation === 'INSERT' || $operation === 'REPLACE') {
                if (preg_match('/\(([`"\w\s,]+)\)\s+values/i', $sql, $colMatches)) {
                    $cols = array_map(fn ($c) => trim(trim($c), '`" '), explode(',', $colMatches[1]));
                    $colCount = count($cols);
                    for ($i = 0; $i < $colCount; $i++) {
                        $data[$cols[$i]] = $bindings[$i] ?? null;
                    }
                }
            } elseif ($operation === 'UPDATE') {
                if (preg_match('/set\s+(.+?)(?:\s+where\s+(.+))?$/is', $sql, $updateMatches)) {
                    $setClause = $updateMatches[1] ?? '';
                    $whereClause = $updateMatches[2] ?? '';

                    preg_match_all('/[`"]?([a-zA-Z0-9_]+)[`"]?\s*=\s*\?/i', $setClause, $setCols);
                    preg_match_all('/[`"]?([a-zA-Z0-9_]+)[`"]?\s*(?:=|<|>|<=|>=|<>|!=|like)\s*\?/i', $whereClause, $whereCols);

                    $bIdx = 0;
                    if (!empty($setCols[1])) {
                        foreach ($setCols[1] as $col) {
                            $data[$col] = $bindings[$bIdx++] ?? null;
                        }
                    }
                    if (!empty($whereCols[1])) {
                        foreach ($whereCols[1] as $col) {
                            $whereData[$col] = $bindings[$bIdx++] ?? null;
                        }
                    }
                }
            } elseif ($operation === 'DELETE') {
                if (preg_match('/where\s+(.+)$/is', $sql, $deleteMatches)) {
                    $whereClause = $deleteMatches[1];
                    preg_match_all('/[`"]?([a-zA-Z0-9_]+)[`"]?\s*(?:=|<|>|<=|>=|<>|!=|like|in)\s*\?/i', $whereClause, $whereCols);
                    $bIdx = 0;
                    if (!empty($whereCols[1])) {
                        foreach ($whereCols[1] as $col) {
                            $whereData[$col] = $bindings[$bIdx++] ?? null;
                        }
                    }
                }
            }

            // Special handling for `sessions` table:
            // Avoid logging routine session touch/timestamp updates on every page view,
            // but DO detect login (user_id set on session) or logout (user_id cleared on session).
            if ($table === 'sessions') {
                self::inspectSessionTableMutation($operation, $data, $whereData);
                return;
            }

            // Also detect direct `users` date_last_login update as a login signal if not yet recorded
            if ($table === 'users' && $operation === 'UPDATE' && array_key_exists('date_last_login', $data) && count($data) <= 2) {
                $loginUserId = (int) ($whereData['user_id'] ?? $data['user_id'] ?? 0);
                if ($loginUserId && !self::$loginRecordedInRequest) {
                    self::$loginRecordedInRequest = true;
                    self::recordActivity([
                        'user_id' => $loginUserId,
                        'event_category' => 'auth',
                        'event_type' => 'USER_LOGIN',
                        'action_summary' => "User Logged In (User #{$loginUserId})",
                        'table_name' => 'users',
                        'operation' => 'LOGIN',
                        'record_id' => (string) $loginUserId,
                        'new_values' => ['date_last_login' => $data['date_last_login']],
                    ]);
                }
                return;
            }

            $pk = UserActivityHelper::getPrimaryKeyForTable($table);
            $recordId = $whereData[$pk] ?? $data[$pk] ?? null;
            if ($recordId === null) {
                // Fallback to first ID-like column in where or data
                foreach ($whereData as $k => $v) {
                    if (str_ends_with($k, '_id') && is_scalar($v)) {
                        $recordId = $v;
                        break;
                    }
                }
                if ($recordId === null) {
                    foreach ($data as $k => $v) {
                        if (str_ends_with($k, '_id') && is_scalar($v)) {
                            $recordId = $v;
                            break;
                        }
                    }
                }
            }

            // Capture current user ID safely in memory
            $currentUserId = null;
            try {
                $currentUserId = Application::get()->getRequest()?->getUser()?->getId()
                    ? (int) Application::get()->getRequest()->getUser()->getId()
                    : null;
            } catch (\Throwable) {
            }

            $contextSnap = UserActivityHelper::getContextSnapshot();

            // Build sanitized interpolated SQL for audit inspection
            $sanitizedBindings = UserActivityHelper::sanitizeData($bindings);
            $interpolatedSql = self::interpolateSql($sql, $sanitizedBindings);

            self::$pendingQueries[] = [
                'user_id' => $currentUserId,
                'context_id' => $contextSnap['context_id'],
                'context_path' => $contextSnap['context_path'],
                'session_id' => UserActivityHelper::getSessionIdentifier(),
                'ip_address' => UserActivityHelper::getRealClientIp(),
                'user_agent' => UserActivityHelper::getUserAgent(),
                'table' => $table,
                'operation' => $operation,
                'record_id' => $recordId !== null ? (string) $recordId : null,
                'data' => UserActivityHelper::sanitizeData($data),
                'where' => UserActivityHelper::sanitizeData($whereData),
                'sql' => $interpolatedSql,
                'time_ms' => $query->time ?? null,
                'http_method' => $_SERVER['REQUEST_METHOD'] ?? (PHP_SAPI === 'cli' ? 'CLI' : 'GET'),
                'request_url' => $_SERVER['REQUEST_URI'] ?? (PHP_SAPI === 'cli' ? implode(' ', $_SERVER['argv'] ?? []) : null),
                'request_payload' => UserActivityHelper::getRequestPayload(),
                'created_at' => date('Y-m-d H:i:s'),
            ];
        } catch (\Throwable $e) {
            UserActivityHelper::logError('onDatabaseQuery error', $e);
        }
    }

    /**
     * Inspect `sessions` table mutations to detect user login / logout transitions
     */
    private static function inspectSessionTableMutation(string $operation, array $data, array $where): void
    {
        try {
            if ($operation === 'UPDATE' && array_key_exists('user_id', $data)) {
                $newUserId = !empty($data['user_id']) ? (int) $data['user_id'] : null;
                if ($newUserId && !self::$initialUserId && !self::$loginRecordedInRequest) {
                    self::$loginRecordedInRequest = true;
                    self::$initialUserId = $newUserId;
                    self::recordActivity([
                        'user_id' => $newUserId,
                        'event_category' => 'auth',
                        'event_type' => 'USER_LOGIN',
                        'action_summary' => "User Logged In (User #{$newUserId})",
                        'table_name' => 'sessions',
                        'operation' => 'LOGIN',
                        'record_id' => (string) $newUserId,
                    ]);
                } elseif (!$newUserId && self::$initialUserId && !self::$logoutRecordedInRequest) {
                    self::$logoutRecordedInRequest = true;
                    self::recordActivity([
                        'user_id' => self::$initialUserId,
                        'event_category' => 'auth',
                        'event_type' => 'USER_LOGOUT',
                        'action_summary' => 'User Logged Out',
                        'table_name' => 'sessions',
                        'operation' => 'LOGOUT',
                        'record_id' => (string) self::$initialUserId,
                    ]);
                }
            } elseif ($operation === 'DELETE' && self::$initialUserId && !self::$logoutRecordedInRequest) {
                $reqPage = Application::get()->getRequest()?->getRequestedPage();
                $reqOp = Application::get()->getRequest()?->getRequestedOp();
                if ($reqPage === 'login' && str_contains((string) $reqOp, 'signOut')) {
                    self::$logoutRecordedInRequest = true;
                    self::recordActivity([
                        'user_id' => self::$initialUserId,
                        'event_category' => 'auth',
                        'event_type' => 'USER_LOGOUT',
                        'action_summary' => 'User Logged Out',
                        'table_name' => 'sessions',
                        'operation' => 'LOGOUT',
                        'record_id' => (string) self::$initialUserId,
                    ]);
                }
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Flush all queued events and SQL mutations to `user_activity_logs`
     */
    public static function flushPendingLogs(): void
    {
        if (self::$isLogging) {
            return;
        }

        // Check if this request was a failed login attempt before flushing
        self::detectFailedLoginOnShutdown();

        if (empty(self::$pendingEvents) && empty(self::$pendingQueries)) {
            return;
        }

        self::$isLogging = true;

        try {
            if (!UserActivityHelper::ensureTableExists()) {
                return;
            }

            $rowsToInsert = [];

            // 1. Process high-level domain & authentication events
            $events = self::$pendingEvents;
            self::$pendingEvents = [];

            foreach ($events as $ev) {
                $userSnap = UserActivityHelper::getUserSnapshot($ev['user_id']);
                $username = $ev['username'] ?: $userSnap['username'];
                $fullName = $ev['user_full_name'] ?: $userSnap['user_full_name'];
                $email = $ev['user_email'] ?: $userSnap['user_email'];
                $roles = $ev['user_roles'] ?: $userSnap['user_roles'];

                // Enrich action summary with user name on login if generic
                $summary = $ev['action_summary'];
                if ($ev['event_type'] === 'USER_LOGIN' && $fullName && str_starts_with($summary, 'User Logged In (User #')) {
                    $summary = "User Logged In: {$fullName} (@{$username})";
                } elseif ($ev['event_type'] === 'USER_LOGOUT' && $fullName && $summary === 'User Logged Out') {
                    $summary = "User Logged Out: {$fullName} (@{$username})";
                }

                $rowsToInsert[] = [
                    'user_id' => $ev['user_id'],
                    'username' => $username,
                    'user_full_name' => $fullName,
                    'user_email' => $email,
                    'user_roles' => $roles,
                    'context_id' => $ev['context_id'],
                    'context_path' => $ev['context_path'],
                    'session_id' => $ev['session_id'],
                    'ip_address' => $ev['ip_address'],
                    'user_agent' => $ev['user_agent'],
                    'event_category' => $ev['event_category'],
                    'event_type' => $ev['event_type'],
                    'action_summary' => substr($summary, 0, 500),
                    'table_name' => $ev['table_name'],
                    'operation' => $ev['operation'],
                    'record_id' => $ev['record_id'],
                    'http_method' => $ev['http_method'],
                    'request_url' => $ev['request_url'],
                    'old_values' => self::encodeJsonColumn($ev['old_values']),
                    'new_values' => self::encodeJsonColumn($ev['new_values']),
                    'where_conditions' => self::encodeJsonColumn($ev['where_conditions']),
                    'sql_query' => $ev['sql_query'],
                    'request_payload' => $ev['request_payload'],
                    'metadata' => self::encodeJsonColumn($ev['metadata']),
                    'created_at' => $ev['created_at'],
                ];
            }

            // 2. Process raw database queries (INSERT, UPDATE, DELETE, REPLACE)
            $queries = self::$pendingQueries;
            self::$pendingQueries = [];

            foreach ($queries as $q) {
                $table = $q['table'];
                $op = $q['operation'];
                $recId = $q['record_id'];

                // Skip if already logged with rich domain context
                if ($recId !== null && self::isHandled($table, $recId, $op)) {
                    continue;
                }

                $userId = $q['user_id'] ?: self::$initialUserId;
                $userSnap = UserActivityHelper::getUserSnapshot($userId);

                $humanTable = UserActivityHelper::formatTableName($table);
                $category = UserActivityHelper::getCategoryForTable($table);
                $recSuffix = $recId !== null ? " (ID: {$recId})" : '';

                // Build concise field summary
                $fieldSummary = '';
                if (!empty($q['data']) && is_array($q['data'])) {
                    $keys = array_keys($q['data']);
                    // Special case for *_settings tables where setting_name is in data or where
                    $settingName = $q['data']['setting_name'] ?? $q['where']['setting_name'] ?? null;
                    if ($settingName) {
                        $fieldSummary = " [{$settingName}]";
                    } else {
                        $previewKeys = array_slice($keys, 0, 4);
                        $fieldSummary = ' [' . implode(', ', $previewKeys) . (count($keys) > 4 ? '...' : '') . ']';
                    }
                } elseif (!empty($q['where']['setting_name'])) {
                    $fieldSummary = " [{$q['where']['setting_name']}]";
                }

                $actionSummary = "Database {$op} on {$humanTable}{$recSuffix}{$fieldSummary}";

                $rowsToInsert[] = [
                    'user_id' => $userId,
                    'username' => $userSnap['username'],
                    'user_full_name' => $userSnap['user_full_name'],
                    'user_email' => $userSnap['user_email'],
                    'user_roles' => $userSnap['user_roles'],
                    'context_id' => $q['context_id'],
                    'context_path' => $q['context_path'],
                    'session_id' => $q['session_id'],
                    'ip_address' => $q['ip_address'],
                    'user_agent' => $q['user_agent'],
                    'event_category' => $category,
                    'event_type' => "DB_{$op}",
                    'action_summary' => substr($actionSummary, 0, 500),
                    'table_name' => $table,
                    'operation' => $op,
                    'record_id' => $recId,
                    'http_method' => $q['http_method'],
                    'request_url' => $q['request_url'],
                    'old_values' => null,
                    'new_values' => !empty($q['data']) ? self::encodeJsonColumn($q['data']) : null,
                    'where_conditions' => !empty($q['where']) ? self::encodeJsonColumn($q['where']) : null,
                    'sql_query' => $q['sql'],
                    'request_payload' => $q['request_payload'],
                    'metadata' => self::encodeJsonColumn([
                        'execution_time_ms' => $q['time_ms'],
                        'human_table' => $humanTable,
                    ]),
                    'created_at' => $q['created_at'],
                ];
            }

            if (!empty($rowsToInsert)) {
                foreach (array_chunk($rowsToInsert, 50) as $chunk) {
                    DB::table('user_activity_logs')->insert($chunk);
                }
            }
        } catch (\Throwable $e) {
            UserActivityHelper::logError('flushPendingLogs error', $e);
        } finally {
            self::$isLogging = false;
        }
    }

    // =========================================================================
    // Authentication & Session Hook Callbacks
    // =========================================================================

    /**
     * Hook: LoadHandler — intercepts login/logout/signAsUser routes
     */
    public static function onLoadHandler(string $hookName, array $args): bool
    {
        try {
            $page = (string) ($args[0] ?? '');
            $op = (string) ($args[1] ?? '');

            if ($page === 'login') {
                if ($op === 'signOut' && self::$initialUserId && !self::$logoutRecordedInRequest) {
                    self::$logoutRecordedInRequest = true;
                    $snap = UserActivityHelper::getUserSnapshot(self::$initialUserId);
                    self::recordActivity([
                        'user_id' => self::$initialUserId,
                        'username' => $snap['username'],
                        'user_full_name' => $snap['user_full_name'],
                        'user_email' => $snap['user_email'],
                        'user_roles' => $snap['user_roles'],
                        'event_category' => 'auth',
                        'event_type' => 'USER_LOGOUT',
                        'action_summary' => "User Logged Out: {$snap['user_full_name']} (@{$snap['username']})",
                        'table_name' => 'sessions',
                        'operation' => 'LOGOUT',
                        'record_id' => (string) self::$initialUserId,
                    ]);
                } elseif ($op === 'signInAsUser') {
                    $targetUserId = (int) (Application::get()->getRequest()?->getUserVar('userId') ?? ($_GET['userId'] ?? 0));
                    self::recordActivity([
                        'user_id' => self::$initialUserId,
                        'event_category' => 'auth',
                        'event_type' => 'IMPERSONATE_START',
                        'action_summary' => "Admin Impersonated User #{$targetUserId} (Log in As)",
                        'table_name' => 'sessions',
                        'operation' => 'LOGIN',
                        'record_id' => $targetUserId ? (string) $targetUserId : null,
                        'metadata' => ['impersonated_user_id' => $targetUserId, 'admin_user_id' => self::$initialUserId],
                    ]);
                } elseif ($op === 'signOutAsUser') {
                    self::recordActivity([
                        'user_id' => self::$initialUserId,
                        'event_category' => 'auth',
                        'event_type' => 'IMPERSONATE_END',
                        'action_summary' => 'Ended User Impersonation Session',
                        'table_name' => 'sessions',
                        'operation' => 'LOGOUT',
                    ]);
                }
            }
        } catch (\Throwable $e) {
            UserActivityHelper::logError('onLoadHandler error', $e);
        }

        return Hook::CONTINUE;
    }

    /**
     * Hook: Validation::login
     */
    public static function onValidationLogin(string $hookName, mixed ...$args): bool
    {
        try {
            $params = (isset($args[0]) && is_array($args[0])) ? $args[0] : $args;
            $username = (string) ($params[0] ?? ($_POST['username'] ?? ''));

            if (!empty($username)) {
                $userRow = DB::table('users')
                    ->where('username', $username)
                    ->orWhere('email', $username)
                    ->first();

                if ($userRow && !self::$loginRecordedInRequest) {
                    self::$loginRecordedInRequest = true;
                    $userId = (int) $userRow->user_id;
                    $snap = UserActivityHelper::getUserSnapshot($userId);
                    self::recordActivity([
                        'user_id' => $userId,
                        'username' => $snap['username'],
                        'user_full_name' => $snap['user_full_name'],
                        'user_email' => $snap['user_email'],
                        'user_roles' => $snap['user_roles'],
                        'event_category' => 'auth',
                        'event_type' => 'USER_LOGIN',
                        'action_summary' => "User Logged In: {$snap['user_full_name']} (@{$snap['username']})",
                        'table_name' => 'users',
                        'operation' => 'LOGIN',
                        'record_id' => (string) $userId,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            UserActivityHelper::logError('onValidationLogin error', $e);
        }

        return Hook::CONTINUE;
    }

    /**
     * Hook: Validation::logout
     */
    public static function onValidationLogout(string $hookName, mixed ...$args): bool
    {
        try {
            if (!self::$logoutRecordedInRequest && self::$initialUserId) {
                self::$logoutRecordedInRequest = true;
                $snap = UserActivityHelper::getUserSnapshot(self::$initialUserId);
                self::recordActivity([
                    'user_id' => self::$initialUserId,
                    'username' => $snap['username'],
                    'user_full_name' => $snap['user_full_name'],
                    'user_email' => $snap['user_email'],
                    'user_roles' => $snap['user_roles'],
                    'event_category' => 'auth',
                    'event_type' => 'USER_LOGOUT',
                    'action_summary' => "User Logged Out: {$snap['user_full_name']} (@{$snap['username']})",
                    'table_name' => 'sessions',
                    'operation' => 'LOGOUT',
                    'record_id' => (string) self::$initialUserId,
                ]);
            }
        } catch (\Throwable $e) {
            UserActivityHelper::logError('onValidationLogout error', $e);
        }

        return Hook::CONTINUE;
    }

    /**
     * Hook: SessionManager::updateSession
     */
    public static function onSessionUpdate(string $hookName, mixed ...$args): bool
    {
        try {
            $params = (isset($args[0]) && is_array($args[0])) ? $args[0] : $args;
            $session = $params[0] ?? null;
            if ($session && method_exists($session, 'getUserId')) {
                $newUserId = $session->getUserId() ? (int) $session->getUserId() : null;
                if ($newUserId && !self::$initialUserId && !self::$loginRecordedInRequest) {
                    self::$loginRecordedInRequest = true;
                    self::$initialUserId = $newUserId;
                    $snap = UserActivityHelper::getUserSnapshot($newUserId);
                    self::recordActivity([
                        'user_id' => $newUserId,
                        'username' => $snap['username'],
                        'user_full_name' => $snap['user_full_name'],
                        'user_email' => $snap['user_email'],
                        'user_roles' => $snap['user_roles'],
                        'event_category' => 'auth',
                        'event_type' => 'USER_LOGIN',
                        'action_summary' => "User Logged In: {$snap['user_full_name']} (@{$snap['username']})",
                        'table_name' => 'sessions',
                        'operation' => 'LOGIN',
                        'record_id' => (string) $newUserId,
                    ]);
                }
            }
        } catch (\Throwable) {
        }

        return Hook::CONTINUE;
    }

    /**
     * Laravel Auth Login Event listener
     */
    public static function onLaravelLogin(object $event): void
    {
        try {
            if (self::$loginRecordedInRequest) {
                return;
            }
            $user = $event->user ?? null;
            $userId = null;
            if (is_object($user)) {
                $userId = method_exists($user, 'getId') ? (int) $user->getId() : ($user->user_id ?? $user->id ?? null);
            }
            if ($userId) {
                self::$loginRecordedInRequest = true;
                $snap = UserActivityHelper::getUserSnapshot((int) $userId);
                self::recordActivity([
                    'user_id' => (int) $userId,
                    'username' => $snap['username'],
                    'user_full_name' => $snap['user_full_name'],
                    'user_email' => $snap['user_email'],
                    'user_roles' => $snap['user_roles'],
                    'event_category' => 'auth',
                    'event_type' => 'USER_LOGIN',
                    'action_summary' => "User Logged In: {$snap['user_full_name']} (@{$snap['username']})",
                    'table_name' => 'users',
                    'operation' => 'LOGIN',
                    'record_id' => (string) $userId,
                ]);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Laravel Auth Logout Event listener
     */
    public static function onLaravelLogout(object $event): void
    {
        try {
            if (self::$logoutRecordedInRequest) {
                return;
            }
            $user = $event->user ?? null;
            $userId = self::$initialUserId;
            if (!$userId && is_object($user)) {
                $userId = method_exists($user, 'getId') ? (int) $user->getId() : ($user->user_id ?? $user->id ?? null);
            }
            if ($userId) {
                self::$logoutRecordedInRequest = true;
                $snap = UserActivityHelper::getUserSnapshot((int) $userId);
                self::recordActivity([
                    'user_id' => (int) $userId,
                    'username' => $snap['username'],
                    'user_full_name' => $snap['user_full_name'],
                    'user_email' => $snap['user_email'],
                    'user_roles' => $snap['user_roles'],
                    'event_category' => 'auth',
                    'event_type' => 'USER_LOGOUT',
                    'action_summary' => "User Logged Out: {$snap['user_full_name']} (@{$snap['username']})",
                    'table_name' => 'sessions',
                    'operation' => 'LOGOUT',
                    'record_id' => (string) $userId,
                ]);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Laravel Auth Failed Event listener
     */
    public static function onLaravelLoginFailed(object $event): void
    {
        try {
            $credentials = is_array($event->credentials ?? null) ? $event->credentials : [];
            $attemptedUsername = (string) ($credentials['username'] ?? $credentials['email'] ?? ($_POST['username'] ?? 'Unknown'));
            self::$loginRecordedInRequest = true;
            self::recordActivity([
                'user_id' => null,
                'username' => $attemptedUsername,
                'event_category' => 'auth',
                'event_type' => 'LOGIN_FAILED',
                'action_summary' => "Failed Login Attempt for Username: {$attemptedUsername}",
                'table_name' => 'users',
                'operation' => 'LOGIN_FAILED',
                'metadata' => ['attempted_username' => $attemptedUsername],
            ]);
        } catch (\Throwable) {
        }
    }

    /**
     * Detect failed POST /login/signIn attempts at request shutdown
     */
    private static function detectFailedLoginOnShutdown(): void
    {
        try {
            if (self::$loginRecordedInRequest || self::$initialUserId) {
                return;
            }

            $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
            if ($method !== 'POST') {
                return;
            }

            $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
            $reqPage = '';
            $reqOp = '';
            try {
                $reqPage = (string) Application::get()->getRequest()?->getRequestedPage();
                $reqOp = (string) Application::get()->getRequest()?->getRequestedOp();
            } catch (\Throwable) {
            }

            $isSignInPost = ($reqPage === 'login' && $reqOp === 'signIn')
                || str_contains($uri, '/login/signIn');

            if ($isSignInPost && !empty($_POST['username'])) {
                $currentUser = null;
                try {
                    $currentUser = Application::get()->getRequest()?->getUser();
                } catch (\Throwable) {
                }

                if ($currentUser && $currentUser->getId()) {
                    // Login actually succeeded
                    $uid = (int) $currentUser->getId();
                    $snap = UserActivityHelper::getUserSnapshot($uid, $currentUser);
                    self::$loginRecordedInRequest = true;
                    self::recordActivity([
                        'user_id' => $uid,
                        'username' => $snap['username'],
                        'user_full_name' => $snap['user_full_name'],
                        'user_email' => $snap['user_email'],
                        'user_roles' => $snap['user_roles'],
                        'event_category' => 'auth',
                        'event_type' => 'USER_LOGIN',
                        'action_summary' => "User Logged In: {$snap['user_full_name']} (@{$snap['username']})",
                        'table_name' => 'users',
                        'operation' => 'LOGIN',
                        'record_id' => (string) $uid,
                    ]);
                } else {
                    // Login failed!
                    $attempted = substr(trim((string) $_POST['username']), 0, 255);
                    self::$loginRecordedInRequest = true;
                    self::recordActivity([
                        'user_id' => null,
                        'username' => $attempted,
                        'event_category' => 'auth',
                        'event_type' => 'LOGIN_FAILED',
                        'action_summary' => "Failed Login Attempt for Username: {$attempted}",
                        'table_name' => 'users',
                        'operation' => 'LOGIN_FAILED',
                        'metadata' => ['attempted_username' => $attempted],
                    ]);
                }
            }
        } catch (\Throwable) {
        }
    }

    // =========================================================================
    // High-Level PKP Repository Domain Hooks (with Old vs. New Diffs)
    // =========================================================================

    public static function onUserAdd(string $hookName, mixed ...$args): bool
    {
        try {
            $params = (isset($args[0]) && is_array($args[0])) ? $args[0] : $args;
            $user = $params[0] ?? null;
            if (!$user instanceof User) {
                return Hook::CONTINUE;
            }

            self::markHandled('users', $user->getId(), 'INSERT');

            self::recordActivity([
                'event_category' => 'user',
                'event_type' => 'USER_CREATED',
                'action_summary' => "User Account Created: {$user->getFullName()} (@{$user->getUsername()})",
                'table_name' => 'users',
                'operation' => 'INSERT',
                'record_id' => (string) $user->getId(),
                'new_values' => [
                    'user_id' => $user->getId(),
                    'username' => $user->getUsername(),
                    'email' => $user->getEmail(),
                    'full_name' => $user->getFullName(),
                ],
            ]);
        } catch (\Throwable $e) {
            UserActivityHelper::logError('onUserAdd error', $e);
        }
        return Hook::CONTINUE;
    }

    public static function onUserEdit(string $hookName, mixed ...$args): bool
    {
        try {
            $params = (isset($args[0]) && is_array($args[0])) ? $args[0] : $args;
            $newUser = $params[0] ?? null;
            $oldUser = $params[1] ?? null;
            $changes = is_array($params[2] ?? null) ? $params[2] : [];

            if (!$newUser instanceof User) {
                return Hook::CONTINUE;
            }

            // Ignore pure dateLastLogin updates as those are logged as USER_LOGIN
            $changeKeys = array_keys($changes);
            if ($changeKeys === ['dateLastLogin'] || $changeKeys === ['date_last_login']) {
                return Hook::CONTINUE;
            }

            self::markHandled('users', $newUser->getId(), 'UPDATE');

            [$oldVals, $newVals] = self::extractEntityDiff($oldUser, $newUser, $changes);

            self::recordActivity([
                'event_category' => 'user',
                'event_type' => 'USER_UPDATED',
                'action_summary' => "User Profile Updated: {$newUser->getFullName()} (@{$newUser->getUsername()})",
                'table_name' => 'users',
                'operation' => 'UPDATE',
                'record_id' => (string) $newUser->getId(),
                'old_values' => $oldVals,
                'new_values' => $newVals,
            ]);
        } catch (\Throwable $e) {
            UserActivityHelper::logError('onUserEdit error', $e);
        }
        return Hook::CONTINUE;
    }

    public static function onUserDelete(string $hookName, mixed ...$args): bool
    {
        try {
            $params = (isset($args[0]) && is_array($args[0])) ? $args[0] : $args;
            $user = $params[0] ?? null;
            if (!$user instanceof User) {
                return Hook::CONTINUE;
            }

            self::markHandled('users', $user->getId(), 'DELETE');

            self::recordActivity([
                'event_category' => 'user',
                'event_type' => 'USER_DELETED',
                'action_summary' => "User Account Deleted: {$user->getFullName()} (@{$user->getUsername()})",
                'table_name' => 'users',
                'operation' => 'DELETE',
                'record_id' => (string) $user->getId(),
                'old_values' => [
                    'user_id' => $user->getId(),
                    'username' => $user->getUsername(),
                    'email' => $user->getEmail(),
                    'full_name' => $user->getFullName(),
                ],
            ]);
        } catch (\Throwable $e) {
            UserActivityHelper::logError('onUserDelete error', $e);
        }
        return Hook::CONTINUE;
    }

    public static function onSubmissionAdd(string $hookName, mixed ...$args): bool
    {
        try {
            $params = (isset($args[0]) && is_array($args[0])) ? $args[0] : $args;
            $submission = $params[0] ?? null;
            if (!$submission instanceof Submission) {
                return Hook::CONTINUE;
            }

            self::markHandled('submissions', $submission->getId(), 'INSERT');

            self::recordActivity([
                'event_category' => 'submission',
                'event_type' => 'SUBMISSION_CREATED',
                'action_summary' => "New Submission Created (#{$submission->getId()})",
                'table_name' => 'submissions',
                'operation' => 'INSERT',
                'record_id' => (string) $submission->getId(),
                'new_values' => UserActivityHelper::sanitizeData($submission->getAllData()),
            ]);
        } catch (\Throwable $e) {
            UserActivityHelper::logError('onSubmissionAdd error', $e);
        }
        return Hook::CONTINUE;
    }

    public static function onSubmissionEdit(string $hookName, mixed ...$args): bool
    {
        try {
            $params = (isset($args[0]) && is_array($args[0])) ? $args[0] : $args;
            $newSub = $params[0] ?? null;
            $oldSub = $params[1] ?? null;
            $changes = is_array($params[2] ?? null) ? $params[2] : [];

            if (!$newSub instanceof Submission) {
                return Hook::CONTINUE;
            }

            self::markHandled('submissions', $newSub->getId(), 'UPDATE');

            [$oldVals, $newVals] = self::extractEntityDiff($oldSub, $newSub, $changes);
            $changedFields = !empty($newVals) ? implode(', ', array_slice(array_keys($newVals), 0, 5)) : 'status/stage';

            self::recordActivity([
                'event_category' => 'submission',
                'event_type' => 'SUBMISSION_UPDATED',
                'action_summary' => "Submission #{$newSub->getId()} Updated ({$changedFields})",
                'table_name' => 'submissions',
                'operation' => 'UPDATE',
                'record_id' => (string) $newSub->getId(),
                'old_values' => $oldVals,
                'new_values' => $newVals,
            ]);
        } catch (\Throwable $e) {
            UserActivityHelper::logError('onSubmissionEdit error', $e);
        }
        return Hook::CONTINUE;
    }

    public static function onSubmissionDelete(string $hookName, mixed ...$args): bool
    {
        try {
            $params = (isset($args[0]) && is_array($args[0])) ? $args[0] : $args;
            $submission = $params[0] ?? null;
            if (!$submission instanceof Submission) {
                return Hook::CONTINUE;
            }

            self::markHandled('submissions', $submission->getId(), 'DELETE');

            self::recordActivity([
                'event_category' => 'submission',
                'event_type' => 'SUBMISSION_DELETED',
                'action_summary' => "Submission #{$submission->getId()} Deleted",
                'table_name' => 'submissions',
                'operation' => 'DELETE',
                'record_id' => (string) $submission->getId(),
                'old_values' => UserActivityHelper::sanitizeData($submission->getAllData()),
            ]);
        } catch (\Throwable $e) {
            UserActivityHelper::logError('onSubmissionDelete error', $e);
        }
        return Hook::CONTINUE;
    }

    public static function onPublicationAdd(string $hookName, mixed ...$args): bool
    {
        try {
            $params = (isset($args[0]) && is_array($args[0])) ? $args[0] : $args;
            $pub = $params[0] ?? null;
            if (!$pub instanceof Publication) {
                return Hook::CONTINUE;
            }

            self::markHandled('publications', $pub->getId(), 'INSERT');
            $subId = $pub->getData('submissionId');

            self::recordActivity([
                'event_category' => 'publication',
                'event_type' => 'PUBLICATION_CREATED',
                'action_summary' => "Publication Created (#{$pub->getId()}) for Submission #{$subId}",
                'table_name' => 'publications',
                'operation' => 'INSERT',
                'record_id' => (string) $pub->getId(),
                'new_values' => UserActivityHelper::sanitizeData($pub->getAllData()),
            ]);
        } catch (\Throwable $e) {
            UserActivityHelper::logError('onPublicationAdd error', $e);
        }
        return Hook::CONTINUE;
    }

    public static function onPublicationEdit(string $hookName, mixed ...$args): bool
    {
        try {
            $params = (isset($args[0]) && is_array($args[0])) ? $args[0] : $args;
            $newPub = $params[0] ?? null;
            $oldPub = $params[1] ?? null;
            $changes = is_array($params[2] ?? null) ? $params[2] : [];

            if (!$newPub instanceof Publication) {
                return Hook::CONTINUE;
            }

            self::markHandled('publications', $newPub->getId(), 'UPDATE');
            [$oldVals, $newVals] = self::extractEntityDiff($oldPub, $newPub, $changes);
            $subId = $newPub->getData('submissionId');
            $changedFields = !empty($newVals) ? implode(', ', array_slice(array_keys($newVals), 0, 5)) : 'metadata';

            self::recordActivity([
                'event_category' => 'publication',
                'event_type' => 'PUBLICATION_UPDATED',
                'action_summary' => "Publication #{$newPub->getId()} (Submission #{$subId}) Updated: {$changedFields}",
                'table_name' => 'publications',
                'operation' => 'UPDATE',
                'record_id' => (string) $newPub->getId(),
                'old_values' => $oldVals,
                'new_values' => $newVals,
            ]);
        } catch (\Throwable $e) {
            UserActivityHelper::logError('onPublicationEdit error', $e);
        }
        return Hook::CONTINUE;
    }

    public static function onPublicationPublish(string $hookName, mixed ...$args): bool
    {
        try {
            $params = (isset($args[0]) && is_array($args[0])) ? $args[0] : $args;
            $pub = $params[0] ?? null;
            if (!$pub instanceof Publication) {
                return Hook::CONTINUE;
            }

            self::markHandled('publications', $pub->getId(), 'UPDATE');
            $subId = $pub->getData('submissionId');

            self::recordActivity([
                'event_category' => 'publication',
                'event_type' => 'PUBLICATION_PUBLISHED',
                'action_summary' => "Publication #{$pub->getId()} Published (Submission #{$subId}, Version {$pub->getData('version')})",
                'table_name' => 'publications',
                'operation' => 'UPDATE',
                'record_id' => (string) $pub->getId(),
                'new_values' => [
                    'submissionId' => $subId,
                    'publicationId' => $pub->getId(),
                    'version' => $pub->getData('version'),
                    'datePublished' => $pub->getData('datePublished') ?: date('Y-m-d H:i:s'),
                ],
            ]);
        } catch (\Throwable $e) {
            UserActivityHelper::logError('onPublicationPublish error', $e);
        }
        return Hook::CONTINUE;
    }

    public static function onPublicationUnpublish(string $hookName, mixed ...$args): bool
    {
        try {
            $params = (isset($args[0]) && is_array($args[0])) ? $args[0] : $args;
            $pub = $params[0] ?? null;
            if (!$pub instanceof Publication) {
                return Hook::CONTINUE;
            }

            self::markHandled('publications', $pub->getId(), 'UPDATE');
            $subId = $pub->getData('submissionId');

            self::recordActivity([
                'event_category' => 'publication',
                'event_type' => 'PUBLICATION_UNPUBLISHED',
                'action_summary' => "Publication #{$pub->getId()} Unpublished (Submission #{$subId})",
                'table_name' => 'publications',
                'operation' => 'UPDATE',
                'record_id' => (string) $pub->getId(),
            ]);
        } catch (\Throwable $e) {
            UserActivityHelper::logError('onPublicationUnpublish error', $e);
        }
        return Hook::CONTINUE;
    }

    public static function onPublicationVersion(string $hookName, mixed ...$args): bool
    {
        try {
            $params = (isset($args[0]) && is_array($args[0])) ? $args[0] : $args;
            $newPub = $params[0] ?? null;
            if (!$newPub instanceof Publication) {
                return Hook::CONTINUE;
            }

            self::markHandled('publications', $newPub->getId(), 'INSERT');
            $subId = $newPub->getData('submissionId');

            self::recordActivity([
                'event_category' => 'publication',
                'event_type' => 'PUBLICATION_VERSIONED',
                'action_summary' => "New Publication Version Created (Version {$newPub->getData('version')}) for Submission #{$subId}",
                'table_name' => 'publications',
                'operation' => 'INSERT',
                'record_id' => (string) $newPub->getId(),
            ]);
        } catch (\Throwable $e) {
            UserActivityHelper::logError('onPublicationVersion error', $e);
        }
        return Hook::CONTINUE;
    }

    public static function onPublicationDelete(string $hookName, mixed ...$args): bool
    {
        try {
            $params = (isset($args[0]) && is_array($args[0])) ? $args[0] : $args;
            $pub = $params[0] ?? null;
            if (!$pub instanceof Publication) {
                return Hook::CONTINUE;
            }

            self::markHandled('publications', $pub->getId(), 'DELETE');

            self::recordActivity([
                'event_category' => 'publication',
                'event_type' => 'PUBLICATION_DELETED',
                'action_summary' => "Publication #{$pub->getId()} Deleted",
                'table_name' => 'publications',
                'operation' => 'DELETE',
                'record_id' => (string) $pub->getId(),
                'old_values' => UserActivityHelper::sanitizeData($pub->getAllData()),
            ]);
        } catch (\Throwable $e) {
            UserActivityHelper::logError('onPublicationDelete error', $e);
        }
        return Hook::CONTINUE;
    }

    public static function onAuthorAdd(string $hookName, mixed ...$args): bool
    {
        try {
            $params = (isset($args[0]) && is_array($args[0])) ? $args[0] : $args;
            $author = $params[0] ?? null;
            if (!$author instanceof Author) {
                return Hook::CONTINUE;
            }

            self::markHandled('authors', $author->getId(), 'INSERT');

            self::recordActivity([
                'event_category' => 'publication',
                'event_type' => 'AUTHOR_ADDED',
                'action_summary' => "Contributor Added: {$author->getFullName()} ({$author->getData('email')})",
                'table_name' => 'authors',
                'operation' => 'INSERT',
                'record_id' => (string) $author->getId(),
                'new_values' => UserActivityHelper::sanitizeData($author->getAllData()),
            ]);
        } catch (\Throwable $e) {
            UserActivityHelper::logError('onAuthorAdd error', $e);
        }
        return Hook::CONTINUE;
    }

    public static function onAuthorEdit(string $hookName, mixed ...$args): bool
    {
        try {
            $params = (isset($args[0]) && is_array($args[0])) ? $args[0] : $args;
            $newAuthor = $params[0] ?? null;
            $oldAuthor = $params[1] ?? null;
            $changes = is_array($params[2] ?? null) ? $params[2] : [];

            if (!$newAuthor instanceof Author) {
                return Hook::CONTINUE;
            }

            self::markHandled('authors', $newAuthor->getId(), 'UPDATE');
            [$oldVals, $newVals] = self::extractEntityDiff($oldAuthor, $newAuthor, $changes);

            self::recordActivity([
                'event_category' => 'publication',
                'event_type' => 'AUTHOR_UPDATED',
                'action_summary' => "Contributor Updated: {$newAuthor->getFullName()}",
                'table_name' => 'authors',
                'operation' => 'UPDATE',
                'record_id' => (string) $newAuthor->getId(),
                'old_values' => $oldVals,
                'new_values' => $newVals,
            ]);
        } catch (\Throwable $e) {
            UserActivityHelper::logError('onAuthorEdit error', $e);
        }
        return Hook::CONTINUE;
    }

    public static function onAuthorDelete(string $hookName, mixed ...$args): bool
    {
        try {
            $params = (isset($args[0]) && is_array($args[0])) ? $args[0] : $args;
            $author = $params[0] ?? null;
            if (!$author instanceof Author) {
                return Hook::CONTINUE;
            }

            self::markHandled('authors', $author->getId(), 'DELETE');

            self::recordActivity([
                'event_category' => 'publication',
                'event_type' => 'AUTHOR_DELETED',
                'action_summary' => "Contributor Removed: {$author->getFullName()}",
                'table_name' => 'authors',
                'operation' => 'DELETE',
                'record_id' => (string) $author->getId(),
                'old_values' => UserActivityHelper::sanitizeData($author->getAllData()),
            ]);
        } catch (\Throwable $e) {
            UserActivityHelper::logError('onAuthorDelete error', $e);
        }
        return Hook::CONTINUE;
    }

    public static function onSubmissionFileAdd(string $hookName, mixed ...$args): bool
    {
        try {
            $params = (isset($args[0]) && is_array($args[0])) ? $args[0] : $args;
            $file = $params[0] ?? null;
            if (!$file instanceof SubmissionFile) {
                return Hook::CONTINUE;
            }

            self::markHandled('submission_files', $file->getId(), 'INSERT');
            $filename = $file->getLocalizedData('name') ?: "File #{$file->getId()}";
            $subId = $file->getData('submissionId');

            self::recordActivity([
                'event_category' => 'file',
                'event_type' => 'FILE_UPLOADED',
                'action_summary' => "File Uploaded: {$filename} (Submission #{$subId})",
                'table_name' => 'submission_files',
                'operation' => 'INSERT',
                'record_id' => (string) $file->getId(),
                'new_values' => UserActivityHelper::sanitizeData($file->getAllData()),
            ]);
        } catch (\Throwable $e) {
            UserActivityHelper::logError('onSubmissionFileAdd error', $e);
        }
        return Hook::CONTINUE;
    }

    public static function onSubmissionFileEdit(string $hookName, mixed ...$args): bool
    {
        try {
            $params = (isset($args[0]) && is_array($args[0])) ? $args[0] : $args;
            $newFile = $params[0] ?? null;
            $oldFile = $params[1] ?? null;
            $changes = is_array($params[2] ?? null) ? $params[2] : [];

            if (!$newFile instanceof SubmissionFile) {
                return Hook::CONTINUE;
            }

            self::markHandled('submission_files', $newFile->getId(), 'UPDATE');
            [$oldVals, $newVals] = self::extractEntityDiff($oldFile, $newFile, $changes);
            $filename = $newFile->getLocalizedData('name') ?: "File #{$newFile->getId()}";

            self::recordActivity([
                'event_category' => 'file',
                'event_type' => 'FILE_UPDATED',
                'action_summary' => "Submission File Updated: {$filename}",
                'table_name' => 'submission_files',
                'operation' => 'UPDATE',
                'record_id' => (string) $newFile->getId(),
                'old_values' => $oldVals,
                'new_values' => $newVals,
            ]);
        } catch (\Throwable $e) {
            UserActivityHelper::logError('onSubmissionFileEdit error', $e);
        }
        return Hook::CONTINUE;
    }

    public static function onSubmissionFileDelete(string $hookName, mixed ...$args): bool
    {
        try {
            $params = (isset($args[0]) && is_array($args[0])) ? $args[0] : $args;
            $file = $params[0] ?? null;
            if (!$file instanceof SubmissionFile) {
                return Hook::CONTINUE;
            }

            self::markHandled('submission_files', $file->getId(), 'DELETE');
            $filename = $file->getLocalizedData('name') ?: "File #{$file->getId()}";

            self::recordActivity([
                'event_category' => 'file',
                'event_type' => 'FILE_DELETED',
                'action_summary' => "Submission File Deleted: {$filename}",
                'table_name' => 'submission_files',
                'operation' => 'DELETE',
                'record_id' => (string) $file->getId(),
                'old_values' => UserActivityHelper::sanitizeData($file->getAllData()),
            ]);
        } catch (\Throwable $e) {
            UserActivityHelper::logError('onSubmissionFileDelete error', $e);
        }
        return Hook::CONTINUE;
    }

    public static function onReviewAssignmentAdd(string $hookName, mixed ...$args): bool
    {
        try {
            $params = (isset($args[0]) && is_array($args[0])) ? $args[0] : $args;
            $ra = $params[0] ?? null;
            if (!$ra instanceof ReviewAssignment) {
                return Hook::CONTINUE;
            }

            self::markHandled('review_assignments', $ra->getId(), 'INSERT');
            $subId = $ra->getSubmissionId();
            $reviewerId = $ra->getReviewerId();

            self::recordActivity([
                'event_category' => 'review',
                'event_type' => 'REVIEWER_ASSIGNED',
                'action_summary' => "Reviewer (User #{$reviewerId}) Assigned to Submission #{$subId} (Round {$ra->getRound()})",
                'table_name' => 'review_assignments',
                'operation' => 'INSERT',
                'record_id' => (string) $ra->getId(),
                'new_values' => UserActivityHelper::sanitizeData($ra->getAllData()),
            ]);
        } catch (\Throwable $e) {
            UserActivityHelper::logError('onReviewAssignmentAdd error', $e);
        }
        return Hook::CONTINUE;
    }

    public static function onReviewAssignmentEdit(string $hookName, mixed ...$args): bool
    {
        try {
            $params = (isset($args[0]) && is_array($args[0])) ? $args[0] : $args;
            $newRa = $params[0] ?? null;
            $oldRa = $params[1] ?? null;
            $changes = is_array($params[2] ?? null) ? $params[2] : [];

            if (!$newRa instanceof ReviewAssignment) {
                return Hook::CONTINUE;
            }

            self::markHandled('review_assignments', $newRa->getId(), 'UPDATE');
            [$oldVals, $newVals] = self::extractEntityDiff($oldRa, $newRa, $changes);

            self::recordActivity([
                'event_category' => 'review',
                'event_type' => 'REVIEW_UPDATED',
                'action_summary' => "Review Assignment #{$newRa->getId()} Updated (Submission #{$newRa->getSubmissionId()})",
                'table_name' => 'review_assignments',
                'operation' => 'UPDATE',
                'record_id' => (string) $newRa->getId(),
                'old_values' => $oldVals,
                'new_values' => $newVals,
            ]);
        } catch (\Throwable $e) {
            UserActivityHelper::logError('onReviewAssignmentEdit error', $e);
        }
        return Hook::CONTINUE;
    }

    public static function onReviewAssignmentDelete(string $hookName, mixed ...$args): bool
    {
        try {
            $params = (isset($args[0]) && is_array($args[0])) ? $args[0] : $args;
            $ra = $params[0] ?? null;
            if (!$ra instanceof ReviewAssignment) {
                return Hook::CONTINUE;
            }

            self::markHandled('review_assignments', $ra->getId(), 'DELETE');

            self::recordActivity([
                'event_category' => 'review',
                'event_type' => 'REVIEW_DELETED',
                'action_summary' => "Review Assignment #{$ra->getId()} Removed (Submission #{$ra->getSubmissionId()})",
                'table_name' => 'review_assignments',
                'operation' => 'DELETE',
                'record_id' => (string) $ra->getId(),
                'old_values' => UserActivityHelper::sanitizeData($ra->getAllData()),
            ]);
        } catch (\Throwable $e) {
            UserActivityHelper::logError('onReviewAssignmentDelete error', $e);
        }
        return Hook::CONTINUE;
    }

    public static function onIssueAdd(string $hookName, mixed ...$args): bool
    {
        try {
            $params = (isset($args[0]) && is_array($args[0])) ? $args[0] : $args;
            $issue = $params[0] ?? null;
            if (!$issue instanceof Issue) {
                return Hook::CONTINUE;
            }

            self::markHandled('issues', $issue->getId(), 'INSERT');

            self::recordActivity([
                'event_category' => 'publication',
                'event_type' => 'ISSUE_CREATED',
                'action_summary' => "Journal Issue Created: {$issue->getIssueIdentification()} (#{$issue->getId()})",
                'table_name' => 'issues',
                'operation' => 'INSERT',
                'record_id' => (string) $issue->getId(),
                'new_values' => UserActivityHelper::sanitizeData($issue->getAllData()),
            ]);
        } catch (\Throwable $e) {
            UserActivityHelper::logError('onIssueAdd error', $e);
        }
        return Hook::CONTINUE;
    }

    public static function onIssueEdit(string $hookName, mixed ...$args): bool
    {
        try {
            $params = (isset($args[0]) && is_array($args[0])) ? $args[0] : $args;
            $newIssue = $params[0] ?? null;
            $oldIssue = $params[1] ?? null;
            $changes = is_array($params[2] ?? null) ? $params[2] : [];

            if (!$newIssue instanceof Issue) {
                return Hook::CONTINUE;
            }

            self::markHandled('issues', $newIssue->getId(), 'UPDATE');
            [$oldVals, $newVals] = self::extractEntityDiff($oldIssue, $newIssue, $changes);

            self::recordActivity([
                'event_category' => 'publication',
                'event_type' => 'ISSUE_UPDATED',
                'action_summary' => "Journal Issue Updated: {$newIssue->getIssueIdentification()} (#{$newIssue->getId()})",
                'table_name' => 'issues',
                'operation' => 'UPDATE',
                'record_id' => (string) $newIssue->getId(),
                'old_values' => $oldVals,
                'new_values' => $newVals,
            ]);
        } catch (\Throwable $e) {
            UserActivityHelper::logError('onIssueEdit error', $e);
        }
        return Hook::CONTINUE;
    }

    public static function onIssueDelete(string $hookName, mixed ...$args): bool
    {
        try {
            $params = (isset($args[0]) && is_array($args[0])) ? $args[0] : $args;
            $issue = $params[0] ?? null;
            if (!$issue instanceof Issue) {
                return Hook::CONTINUE;
            }

            self::markHandled('issues', $issue->getId(), 'DELETE');

            self::recordActivity([
                'event_category' => 'publication',
                'event_type' => 'ISSUE_DELETED',
                'action_summary' => "Journal Issue Deleted (#{$issue->getId()})",
                'table_name' => 'issues',
                'operation' => 'DELETE',
                'record_id' => (string) $issue->getId(),
                'old_values' => UserActivityHelper::sanitizeData($issue->getAllData()),
            ]);
        } catch (\Throwable $e) {
            UserActivityHelper::logError('onIssueDelete error', $e);
        }
        return Hook::CONTINUE;
    }

    public static function onDecisionAdd(string $hookName, mixed ...$args): bool
    {
        try {
            $params = (isset($args[0]) && is_array($args[0])) ? $args[0] : $args;
            $decision = $params[0] ?? null;
            if (!is_object($decision) || !method_exists($decision, 'getId')) {
                return Hook::CONTINUE;
            }

            self::markHandled('edit_decisions', $decision->getId(), 'INSERT');
            $subId = $decision->getData('submissionId');
            $decVal = $decision->getData('decision');

            self::recordActivity([
                'event_category' => 'review',
                'event_type' => 'EDITORIAL_DECISION',
                'action_summary' => "Editorial Decision Recorded (Code {$decVal}) on Submission #{$subId}",
                'table_name' => 'edit_decisions',
                'operation' => 'INSERT',
                'record_id' => (string) $decision->getId(),
                'new_values' => method_exists($decision, 'getAllData') ? UserActivityHelper::sanitizeData($decision->getAllData()) : null,
            ]);
        } catch (\Throwable $e) {
            UserActivityHelper::logError('onDecisionAdd error', $e);
        }
        return Hook::CONTINUE;
    }

    // =========================================================================
    // Eloquent Model Observers
    // =========================================================================

    public static function onStageAssignmentSaved(mixed $assignment): void
    {
        try {
            if (!$assignment instanceof StageAssignment) {
                return;
            }
            $id = $assignment->id ?? $assignment->stageAssignmentId ?? null;
            self::markHandled('stage_assignments', $id, 'INSERT');
            self::markHandled('stage_assignments', $id, 'UPDATE');

            self::recordActivity([
                'event_category' => 'submission',
                'event_type' => 'PARTICIPANT_ASSIGNED',
                'action_summary' => "Stage Participant Assigned: User #{$assignment->userId} to Submission #{$assignment->submissionId}",
                'table_name' => 'stage_assignments',
                'operation' => 'INSERT',
                'record_id' => $id ? (string) $id : null,
                'new_values' => [
                    'submission_id' => $assignment->submissionId,
                    'user_id' => $assignment->userId,
                    'user_group_id' => $assignment->userGroupId,
                    'stage_id' => $assignment->stageId,
                ],
            ]);
        } catch (\Throwable) {
        }
    }

    public static function onStageAssignmentDeleted(mixed $assignment): void
    {
        try {
            if (!$assignment instanceof StageAssignment) {
                return;
            }
            $id = $assignment->id ?? $assignment->stageAssignmentId ?? null;
            self::markHandled('stage_assignments', $id, 'DELETE');

            self::recordActivity([
                'event_category' => 'submission',
                'event_type' => 'PARTICIPANT_REMOVED',
                'action_summary' => "Stage Participant Removed: User #{$assignment->userId} from Submission #{$assignment->submissionId}",
                'table_name' => 'stage_assignments',
                'operation' => 'DELETE',
                'record_id' => $id ? (string) $id : null,
                'old_values' => [
                    'submission_id' => $assignment->submissionId,
                    'user_id' => $assignment->userId,
                    'user_group_id' => $assignment->userGroupId,
                ],
            ]);
        } catch (\Throwable) {
        }
    }

    public static function onQuerySaved(mixed $query): void
    {
        try {
            if (!$query instanceof Query) {
                return;
            }
            $id = $query->id ?? $query->queryId ?? null;
            self::markHandled('queries', $id, 'INSERT');
            self::markHandled('queries', $id, 'UPDATE');

            self::recordActivity([
                'event_category' => 'submission',
                'event_type' => 'DISCUSSION_SAVED',
                'action_summary' => "Discussion Query #{$id} " . ($query->closed ? 'Closed' : 'Saved') . " (Assoc #{$query->assocId})",
                'table_name' => 'queries',
                'operation' => 'UPDATE',
                'record_id' => $id ? (string) $id : null,
            ]);
        } catch (\Throwable) {
        }
    }

    public static function onQueryDeleted(mixed $query): void
    {
        try {
            if (!$query instanceof Query) {
                return;
            }
            $id = $query->id ?? $query->queryId ?? null;
            self::markHandled('queries', $id, 'DELETE');

            self::recordActivity([
                'event_category' => 'submission',
                'event_type' => 'DISCUSSION_DELETED',
                'action_summary' => "Discussion Query #{$id} Deleted",
                'table_name' => 'queries',
                'operation' => 'DELETE',
                'record_id' => $id ? (string) $id : null,
            ]);
        } catch (\Throwable) {
        }
    }

    public static function onQueryParticipantSaved(mixed $qp): void
    {
        try {
            if (!$qp instanceof QueryParticipant) {
                return;
            }
            self::recordActivity([
                'event_category' => 'submission',
                'event_type' => 'DISCUSSION_PARTICIPANT_ADDED',
                'action_summary' => "User #{$qp->userId} Added to Discussion Query #{$qp->queryId}",
                'table_name' => 'query_participants',
                'operation' => 'INSERT',
                'record_id' => "{$qp->queryId}_{$qp->userId}",
            ]);
        } catch (\Throwable) {
        }
    }

    public static function onQueryParticipantDeleted(mixed $qp): void
    {
        try {
            if (!$qp instanceof QueryParticipant) {
                return;
            }
            self::recordActivity([
                'event_category' => 'submission',
                'event_type' => 'DISCUSSION_PARTICIPANT_REMOVED',
                'action_summary' => "User #{$qp->userId} Removed from Discussion Query #{$qp->queryId}",
                'table_name' => 'query_participants',
                'operation' => 'DELETE',
                'record_id' => "{$qp->queryId}_{$qp->userId}",
            ]);
        } catch (\Throwable) {
        }
    }

    public static function onNoteSaved(mixed $note): void
    {
        try {
            if (!$note instanceof Note) {
                return;
            }
            $id = $note->id ?? $note->noteId ?? null;
            self::markHandled('notes', $id, 'INSERT');
            self::markHandled('notes', $id, 'UPDATE');

            self::recordActivity([
                'event_category' => 'submission',
                'event_type' => 'NOTE_SAVED',
                'action_summary' => "Discussion Note Saved (#{$id})" . ($note->title ? ": {$note->title}" : ''),
                'table_name' => 'notes',
                'operation' => 'INSERT',
                'record_id' => $id ? (string) $id : null,
                'new_values' => [
                    'title' => $note->title,
                    'contents' => $note->contents ? mb_substr(strip_tags((string) $note->contents), 0, 500) : null,
                ],
            ]);
        } catch (\Throwable) {
        }
    }

    public static function onNoteDeleted(mixed $note): void
    {
        try {
            if (!$note instanceof Note) {
                return;
            }
            $id = $note->id ?? $note->noteId ?? null;
            self::markHandled('notes', $id, 'DELETE');

            self::recordActivity([
                'event_category' => 'submission',
                'event_type' => 'NOTE_DELETED',
                'action_summary' => "Discussion Note #{$id} Deleted",
                'table_name' => 'notes',
                'operation' => 'DELETE',
                'record_id' => $id ? (string) $id : null,
            ]);
        } catch (\Throwable) {
        }
    }

    // =========================================================================
    // Internal Formatting Helpers
    // =========================================================================

    /**
     * Extract old vs. new field values from PKP DataObject edit hooks
     */
    private static function extractEntityDiff($oldObj, $newObj, array $changes): array
    {
        $oldVals = [];
        $newVals = [];

        $keys = !empty($changes) ? array_keys($changes) : [];
        if (empty($keys) && is_object($newObj) && method_exists($newObj, 'getAllData')) {
            $keys = array_keys($newObj->getAllData());
        }

        foreach ($keys as $key) {
            if (in_array($key, ['lastModified', 'updated_at'], true)) {
                continue;
            }
            $nVal = is_object($newObj) && method_exists($newObj, 'getData') ? $newObj->getData($key) : ($changes[$key] ?? null);
            $oVal = is_object($oldObj) && method_exists($oldObj, 'getData') ? $oldObj->getData($key) : null;

            if ($oVal !== $nVal) {
                if ($oVal !== null) {
                    $oldVals[$key] = $oVal;
                }
                $newVals[$key] = $nVal;
            }
        }

        return [
            UserActivityHelper::sanitizeData($oldVals),
            UserActivityHelper::sanitizeData($newVals),
        ];
    }

    /**
     * Safely interpolate bindings into SQL string for human audit readability
     */
    private static function interpolateSql(string $sql, array $bindings): string
    {
        if (empty($bindings)) {
            return $sql;
        }

        $result = $sql;
        foreach ($bindings as $binding) {
            if ($binding === null) {
                $val = 'NULL';
            } elseif (is_bool($binding)) {
                $val = $binding ? '1' : '0';
            } elseif (is_int($binding) || is_float($binding)) {
                $val = (string) $binding;
            } elseif ($binding instanceof \DateTimeInterface) {
                $val = "'" . $binding->format('Y-m-d H:i:s') . "'";
            } else {
                $str = (string) $binding;
                if (mb_strlen($str) > 300) {
                    $str = mb_substr($str, 0, 300) . '...[truncated]';
                }
                $val = "'" . addslashes($str) . "'";
            }

            $pos = strpos($result, '?');
            if ($pos !== false) {
                $result = substr_replace($result, $val, $pos, 1);
            }
        }

        if (mb_strlen($result) > 16384) {
            $result = mb_substr($result, 0, 16300) . ' ... [truncated]';
        }

        return $result;
    }

    /**
     * Encode array/object to JSON string or return string as-is
     */
    private static function encodeJsonColumn(mixed $val): ?string
    {
        if ($val === null || $val === '' || $val === []) {
            return null;
        }
        if (is_string($val)) {
            return $val;
        }
        return json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
