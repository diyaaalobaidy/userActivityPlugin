<?php

/**
 * @file plugins/generic/userActivityPlugin/classes/UserActivityHelper.php
 *
 * Copyright (c) 2026
 * Distributed under the GNU GPL v3.
 *
 * @class UserActivityHelper
 * @ingroup plugins_generic_userActivityPlugin
 *
 * @brief Helper utility for the User Activity & Database Audit Plugin (OJS 3.5).
 *        Provides translation fallbacks, IP resolution, request payload sanitization,
 *        role checks, table formatting, and safe error logging.
 */

namespace APP\plugins\generic\userActivityPlugin\classes;

use APP\core\Application;
use APP\facades\Repo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PKP\security\Role;
use PKP\security\Validation;

class UserActivityHelper
{
    /** @var array Cache for user metadata */
    protected static array $userCache = [];

    /** @var array Cache for journal contexts */
    protected static array $contextCache = [];

    /** @var bool|null Cached flag whether user_activity_logs table exists */
    protected static ?bool $tableExistsCache = null;

    /**
     * Check whether the current logged-in user is a Site Administrator
     */
    public static function isCurrentUserAdmin($request = null): bool
    {
        try {
            if (Validation::isSiteAdmin()) {
                return true;
            }

            $request = $request ?: Application::get()->getRequest();
            $user = $request?->getUser();
            if (!$user) {
                return false;
            }

            $userId = (int) $user->getId();
            if (!$userId) {
                return false;
            }

            // Check if user belongs to any Site Admin user group (role_id = ROLE_ID_SITE_ADMIN = 1)
            $isAdmin = DB::table('user_user_groups as uug')
                ->join('user_groups as ug', 'uug.user_group_id', '=', 'ug.user_group_id')
                ->where('uug.user_id', $userId)
                ->where('ug.role_id', Role::ROLE_ID_SITE_ADMIN)
                ->where(function ($q) {
                    $q->whereNull('uug.date_start')->orWhere('uug.date_start', '<=', date('Y-m-d H:i:s'));
                })
                ->where(function ($q) {
                    $q->whereNull('uug.date_end')->orWhere('uug.date_end', '>', date('Y-m-d H:i:s'));
                })
                ->exists();

            return (bool) $isAdmin;
        } catch (\Throwable $e) {
            self::logError('isCurrentUserAdmin check error', $e);
            return false;
        }
    }

    /**
     * Ensure the user_activity_logs table exists, running the migration automatically if needed
     */
    public static function ensureTableExists(): bool
    {
        if (self::$tableExistsCache === true) {
            return true;
        }

        try {
            if (class_exists(\Illuminate\Support\Facades\Cache::class)) {
                try {
                    if (\Illuminate\Support\Facades\Cache::get('ua_table_exists')) {
                        self::$tableExistsCache = true;
                        return true;
                    }
                } catch (\Throwable) {
                }
            }

            if (Schema::hasTable('user_activity_logs')) {
                self::$tableExistsCache = true;
                if (class_exists(\Illuminate\Support\Facades\Cache::class)) {
                    try {
                        \Illuminate\Support\Facades\Cache::put('ua_table_exists', true, 86400);
                        // Ensure performance indexes exist once per cache cycle
                        if (!\Illuminate\Support\Facades\Cache::get('ua_indexes_checked')) {
                            migration\UserActivitySchemaMigration::ensurePerformanceIndexes();
                            \Illuminate\Support\Facades\Cache::put('ua_indexes_checked', true, 86400);
                        }
                    } catch (\Throwable) {
                    }
                }
                return true;
            }

            $migration = new migration\UserActivitySchemaMigration();
            $migration->up();

            self::$tableExistsCache = Schema::hasTable('user_activity_logs');
            if (self::$tableExistsCache && class_exists(\Illuminate\Support\Facades\Cache::class)) {
                try {
                    \Illuminate\Support\Facades\Cache::put('ua_table_exists', true, 86400);
                } catch (\Throwable) {
                }
            }
            return self::$tableExistsCache;
        } catch (\Throwable $e) {
            self::logError('Failed ensuring user_activity_logs table exists', $e);
            return false;
        }
    }

    /**
     * Safe translation helper with fallback string
     */
    public static function translate(string $key, array $params = [], string $fallback = ''): string
    {
        try {
            $trans = __($key, $params);
            if (!empty($trans) && !str_starts_with($trans, '##') && !str_ends_with($trans, '##') && $trans !== $key) {
                return $trans;
            }
        } catch (\Throwable) {
        }

        if ($fallback !== '') {
            foreach ($params as $k => $v) {
                $fallback = str_replace(['{$' . $k . '}', ':' . $k], (string) $v, $fallback);
            }
            return $fallback;
        }

        return $key;
    }

    /**
     * Silently log internal errors without disrupting the OJS request
     */
    public static function logError(string $context, \Throwable $e): void
    {
        try {
            error_log(sprintf(
                '[UserActivityPlugin] %s: %s in %s:%d',
                $context,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
        } catch (\Throwable) {
        }
    }

    /**
     * Resolve user details (username, full name, email, roles) with in-memory caching
     */
    public static function getUserSnapshot(?int $userId = null, $userObj = null): array
    {
        $default = [
            'user_id' => $userId,
            'username' => null,
            'user_full_name' => null,
            'user_email' => null,
            'user_roles' => null,
        ];

        try {
            if (!$userObj && !$userId) {
                $userObj = Application::get()->getRequest()?->getUser();
                $userId = $userObj?->getId() ? (int) $userObj->getId() : null;
            }

            if (!$userId) {
                return $default;
            }

            if (isset(self::$userCache[$userId])) {
                return self::$userCache[$userId];
            }

            if (!$userObj) {
                try {
                    $userObj = Repo::user()->get($userId, true);
                } catch (\Throwable) {
                    $userObj = null;
                }
            }

            $username = null;
            $fullName = null;
            $email = null;

            if ($userObj) {
                $username = method_exists($userObj, 'getUsername') ? $userObj->getUsername() : $userObj->getData('username');
                $fullName = method_exists($userObj, 'getFullName') ? $userObj->getFullName() : null;
                $email = method_exists($userObj, 'getEmail') ? $userObj->getEmail() : $userObj->getData('email');
            } else {
                $row = DB::table('users')->where('user_id', $userId)->first();
                if ($row) {
                    $username = $row->username ?? null;
                    $email = $row->email ?? null;
                    $given = DB::table('user_settings')
                        ->where('user_id', $userId)
                        ->where('setting_name', 'givenName')
                        ->value('setting_value');
                    $family = DB::table('user_settings')
                        ->where('user_id', $userId)
                        ->where('setting_name', 'familyName')
                        ->value('setting_value');
                    $fullName = trim(($given ?? '') . ' ' . ($family ?? '')) ?: $username;
                }
            }

            // Resolve user roles
            $roleIds = DB::table('user_user_groups as uug')
                ->join('user_groups as ug', 'uug.user_group_id', '=', 'ug.user_group_id')
                ->where('uug.user_id', $userId)
                ->distinct()
                ->pluck('ug.role_id')
                ->toArray();

            $roleNames = array_map([self::class, 'formatRoleId'], $roleIds);

            $snapshot = [
                'user_id' => $userId,
                'username' => $username,
                'user_full_name' => $fullName ?: $username,
                'user_email' => $email,
                'user_roles' => !empty($roleNames) ? implode(', ', array_unique($roleNames)) : 'User',
            ];

            self::$userCache[$userId] = $snapshot;
            return $snapshot;
        } catch (\Throwable $e) {
            self::logError('getUserSnapshot error', $e);
            return $default;
        }
    }

    /**
     * Format OJS Role ID into readable string
     */
    public static function formatRoleId(int $roleId): string
    {
        return match ($roleId) {
            Role::ROLE_ID_SITE_ADMIN => 'Site Admin',
            Role::ROLE_ID_MANAGER => 'Journal Manager',
            Role::ROLE_ID_SUB_EDITOR => 'Section Editor',
            Role::ROLE_ID_ASSISTANT => 'Assistant',
            Role::ROLE_ID_AUTHOR => 'Author',
            Role::ROLE_ID_REVIEWER => 'Reviewer',
            Role::ROLE_ID_READER => 'Reader',
            Role::ROLE_ID_SUBSCRIPTION_MANAGER => 'Subscription Manager',
            default => "Role #{$roleId}",
        };
    }

    /**
     * Get current context (journal) ID and path
     */
    public static function getContextSnapshot(): array
    {
        try {
            $request = Application::get()->getRequest();
            $context = $request?->getContext();
            if ($context) {
                return [
                    'context_id' => (int) $context->getId(),
                    'context_path' => (string) $context->getPath(),
                ];
            }
        } catch (\Throwable) {
        }

        return [
            'context_id' => null,
            'context_path' => 'site',
        ];
    }

    /**
     * Resolve real client IP address bypassing reverse proxies, Cloudflare, and load balancers
     */
    public static function getRealClientIp(): string
    {
        try {
            $headers = [
                'HTTP_CF_CONNECTING_IP',
                'HTTP_X_REAL_IP',
                'HTTP_X_FORWARDED_FOR',
                'HTTP_CLIENT_IP',
                'HTTP_X_CLUSTER_CLIENT_IP',
            ];

            foreach ($headers as $header) {
                if (!empty($_SERVER[$header])) {
                    $value = (string) $_SERVER[$header];
                    if (str_contains($value, ',')) {
                        $ips = explode(',', $value);
                        foreach ($ips as $ip) {
                            $ip = trim($ip);
                            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                                return $ip;
                            }
                        }
                        foreach ($ips as $ip) {
                            $ip = trim($ip);
                            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                                return $ip;
                            }
                        }
                    } else {
                        $ip = trim($value);
                        if (filter_var($ip, FILTER_VALIDATE_IP)) {
                            return $ip;
                        }
                    }
                }
            }

            try {
                $ojsIp = Application::get()->getRequest()?->getRemoteAddr();
                if ($ojsIp && filter_var($ojsIp, FILTER_VALIDATE_IP)) {
                    return $ojsIp;
                }
            } catch (\Throwable) {
            }

            $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
            if (filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
                return $remoteAddr;
            }

            return $remoteAddr ?: (PHP_SAPI === 'cli' ? 'CLI / Local' : '127.0.0.1');
        } catch (\Throwable) {
            return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        }
    }

    /**
     * Get User-Agent string
     */
    public static function getUserAgent(): string
    {
        if (PHP_SAPI === 'cli' && empty($_SERVER['HTTP_USER_AGENT'])) {
            return 'PHP CLI (' . PHP_VERSION . ')';
        }
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'), 0, 1000);
    }

    /**
     * Get current session ID or identifier safely
     */
    public static function getSessionIdentifier(): ?string
    {
        try {
            if (session_status() === PHP_SESSION_ACTIVE && session_id()) {
                return substr(session_id(), 0, 128);
            }
            $session = Application::get()->getRequest()?->getSession();
            if ($session && method_exists($session, 'getId')) {
                return substr((string) $session->getId(), 0, 128);
            }
        } catch (\Throwable) {
        }
        return null;
    }

    /**
     * Safely capture and sanitize the HTTP request body payload
     */
    public static function getRequestPayload(): ?string
    {
        try {
            static $cachedPayload = null;
            if ($cachedPayload !== null) {
                return $cachedPayload ?: null;
            }

            if (PHP_SAPI === 'cli' && empty($_POST) && !isset($_SERVER['REQUEST_METHOD'])) {
                $cachedPayload = '';
                return null;
            }

            $raw = '';
            try {
                $raw = (string) file_get_contents('php://input');
            } catch (\Throwable) {
                $raw = '';
            }

            $data = null;
            if (!empty($raw)) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                } else {
                    parse_str($raw, $parsed);
                    if (!empty($parsed)) {
                        $data = $parsed;
                    }
                }
            }

            if (empty($data) && !empty($_POST)) {
                $data = $_POST;
            }

            if (!empty($_FILES)) {
                $fileSummaries = [];
                foreach ($_FILES as $field => $file) {
                    if (is_array($file['name'] ?? null)) {
                        $names = implode(', ', $file['name']);
                        $fileSummaries[$field] = "[{$names}]";
                    } else {
                        $name = $file['name'] ?? 'unknown';
                        $size = $file['size'] ?? 0;
                        $fileSummaries[$field] = "{$name} ({$size} bytes)";
                    }
                }
                if (is_array($data)) {
                    $data['_uploaded_files'] = $fileSummaries;
                } else {
                    $data = ['_uploaded_files' => $fileSummaries];
                }
            }

            if (!empty($data)) {
                $sanitized = self::sanitizeData($data);
                $encoded = json_encode($sanitized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (mb_strlen($encoded) > 32768) {
                    $encoded = mb_substr($encoded, 0, 32700) . "\n... [truncated]";
                }
                $cachedPayload = $encoded;
                return $cachedPayload;
            }

            $cachedPayload = '';
            return null;
        } catch (\Throwable $e) {
            self::logError('getRequestPayload error', $e);
            return null;
        }
    }

    /**
     * Redact sensitive keys (passwords, secrets, tokens, API keys) from any array or value
     */
    public static function sanitizeData(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        $sensitivePatterns = [
            'password', 'oldpassword', 'newpassword', 'confirmpassword', 'pass',
            'secret', 'token', 'csrf', 'csrftoken', 'apikey', 'api_key', 'private_key', 'authorization',
        ];

        $result = [];
        foreach ($data as $key => $val) {
            $lowerKey = strtolower((string) $key);
            $isSensitive = false;
            foreach ($sensitivePatterns as $pattern) {
                if (str_contains($lowerKey, $pattern)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $result[$key] = '***REDACTED***';
            } elseif (is_array($val)) {
                $result[$key] = self::sanitizeData($val);
            } elseif ($val instanceof \DateTimeInterface) {
                $result[$key] = $val->format('Y-m-d H:i:s');
            } else {
                $result[$key] = $val;
            }
        }

        return $result;
    }

    /**
     * Determine primary key column name for standard OJS 3.5 database tables
     */
    public static function getPrimaryKeyForTable(string $table): string
    {
        return match ($table) {
            'users', 'user_settings' => 'user_id',
            'user_groups', 'user_group_settings' => 'user_group_id',
            'user_user_groups' => 'user_user_group_id',
            'sessions' => 'session_id',
            'submissions', 'submission_settings' => 'submission_id',
            'publications', 'publication_settings' => 'publication_id',
            'authors', 'author_settings' => 'author_id',
            'publication_galleys', 'publication_galley_settings' => 'galley_id',
            'submission_files', 'submission_file_settings' => 'submission_file_id',
            'files' => 'file_id',
            'stage_assignments' => 'stage_assignment_id',
            'review_assignments', 'review_assignment_settings' => 'review_assignment_id',
            'review_rounds' => 'review_round_id',
            'review_forms', 'review_form_settings' => 'review_form_id',
            'review_form_elements' => 'review_form_element_id',
            'review_form_responses' => 'review_id',
            'edit_decisions' => 'edit_decision_id',
            'issues', 'issue_settings' => 'issue_id',
            'issue_galleys', 'issue_galley_settings' => 'galley_id',
            'sections', 'section_settings' => 'section_id',
            'categories', 'category_settings' => 'category_id',
            'journals', 'journal_settings' => 'journal_id',
            'site', 'site_settings' => 'site_id',
            'plugin_settings' => 'plugin_name',
            'announcements', 'announcement_settings' => 'announcement_id',
            'navigation_menus' => 'navigation_menu_id',
            'navigation_menu_items' => 'navigation_menu_item_id',
            'email_templates', 'email_templates_settings' => 'email_id',
            'email_log', 'email_log_users' => 'log_id',
            'event_log', 'event_log_settings' => 'log_id',
            'queries', 'query_participants' => 'query_id',
            'notes' => 'note_id',
            'citations', 'citation_settings' => 'citation_id',
            'dois', 'doi_settings' => 'doi_id',
            'institutions', 'institution_settings' => 'institution_id',
            'subscriptions' => 'subscription_id',
            'subscription_types' => 'type_id',
            'notifications', 'notification_settings' => 'notification_id',
            'user_interests' => 'user_id',
            'access_keys' => 'access_key_id',
            'invitations' => 'invitation_id',
            default => 'id',
        };
    }

    /**
     * Categorize a database table into a high-level audit category
     */
    public static function getCategoryForTable(string $table): string
    {
        if (in_array($table, ['sessions', 'access_keys'])) {
            return 'auth';
        }
        if (str_starts_with($table, 'user') || in_array($table, ['invitations'])) {
            return 'user';
        }
        if (str_starts_with($table, 'submission_file') || $table === 'files' || $table === 'review_files' || $table === 'review_round_files') {
            return 'file';
        }
        if (str_starts_with($table, 'review_') || $table === 'edit_decisions') {
            return 'review';
        }
        if (str_starts_with($table, 'publication') || str_starts_with($table, 'author') || str_starts_with($table, 'issue') || str_starts_with($table, 'doi') || str_starts_with($table, 'citation')) {
            return 'publication';
        }
        if (str_starts_with($table, 'submission') || $table === 'stage_assignments' || str_starts_with($table, 'quer') || $table === 'notes') {
            return 'submission';
        }
        if (str_starts_with($table, 'email') || str_starts_with($table, 'notification') || str_starts_with($table, 'announcement')) {
            return 'email';
        }
        if (str_starts_with($table, 'journal') || str_starts_with($table, 'site') || str_starts_with($table, 'plugin') || str_starts_with($table, 'section') || str_starts_with($table, 'navigation') || str_starts_with($table, 'category') || str_starts_with($table, 'genre')) {
            return 'settings';
        }

        return 'database';
    }

    /**
     * Format a database table name into a clean human-readable label
     */
    public static function formatTableName(string $table): string
    {
        $map = [
            'users' => 'Users',
            'user_settings' => 'User Settings',
            'user_groups' => 'User Roles / Groups',
            'user_group_settings' => 'User Group Settings',
            'user_user_groups' => 'User Role Assignments',
            'sessions' => 'User Sessions',
            'submissions' => 'Submissions',
            'submission_settings' => 'Submission Settings',
            'publications' => 'Publications',
            'publication_settings' => 'Publication Metadata',
            'authors' => 'Authors / Contributors',
            'author_settings' => 'Author Metadata',
            'publication_galleys' => 'Publication Galleys',
            'publication_galley_settings' => 'Galley Settings',
            'submission_files' => 'Submission Files',
            'submission_file_settings' => 'Submission File Settings',
            'submission_file_revisions' => 'File Revisions',
            'files' => 'Physical File Storage',
            'stage_assignments' => 'Workflow Stage Assignments',
            'review_assignments' => 'Peer Review Assignments',
            'review_assignment_settings' => 'Review Assignment Settings',
            'review_rounds' => 'Review Rounds',
            'review_files' => 'Reviewer Files',
            'review_round_files' => 'Review Round Files',
            'review_forms' => 'Review Forms',
            'review_form_responses' => 'Review Form Responses',
            'edit_decisions' => 'Editorial Decisions',
            'queries' => 'Workflow Discussions',
            'query_participants' => 'Discussion Participants',
            'notes' => 'Discussion Notes',
            'issues' => 'Journal Issues',
            'issue_settings' => 'Issue Settings',
            'issue_galleys' => 'Issue Galleys',
            'sections' => 'Journal Sections',
            'section_settings' => 'Section Settings',
            'categories' => 'Categories',
            'journals' => 'Journals',
            'journal_settings' => 'Journal Settings',
            'site' => 'Site Configuration',
            'site_settings' => 'Site Settings',
            'plugin_settings' => 'Plugin Settings',
            'email_templates' => 'Email Templates',
            'email_log' => 'Sent Emails Log',
            'event_log' => 'Submission Event Log',
            'event_log_settings' => 'Event Log Settings',
            'notifications' => 'System Notifications',
            'announcements' => 'Announcements',
            'navigation_menus' => 'Navigation Menus',
            'navigation_menu_items' => 'Navigation Menu Items',
            'dois' => 'DOIs',
            'citations' => 'Citations',
            'institutions' => 'Institutions',
            'subscriptions' => 'Subscriptions',
            'invitations' => 'User Invitations',
            'access_keys' => 'Access Keys',
            'jobs' => 'Background Queue Jobs',
            'failed_jobs' => 'Failed Queue Jobs',
        ];

        return $map[$table] ?? ucwords(str_replace('_', ' ', $table));
    }

    /**
     * Get badge metadata (icon, label, CSS class) for an event category
     */
    public static function getCategoryBadge(string $category): array
    {
        return match ($category) {
            'auth' => [
                'icon' => '🔐',
                'label' => self::translate('plugins.generic.userActivityPlugin.category.auth', [], 'Authentication'),
                'class' => 'ua-badge-auth',
            ],
            'user' => [
                'icon' => '👤',
                'label' => self::translate('plugins.generic.userActivityPlugin.category.user', [], 'Users & Roles'),
                'class' => 'ua-badge-user',
            ],
            'submission' => [
                'icon' => '📄',
                'label' => self::translate('plugins.generic.userActivityPlugin.category.submission', [], 'Submissions'),
                'class' => 'ua-badge-submission',
            ],
            'publication' => [
                'icon' => '📢',
                'label' => self::translate('plugins.generic.userActivityPlugin.category.publication', [], 'Publications & Issues'),
                'class' => 'ua-badge-publication',
            ],
            'review' => [
                'icon' => '⚖️',
                'label' => self::translate('plugins.generic.userActivityPlugin.category.review', [], 'Reviews & Decisions'),
                'class' => 'ua-badge-review',
            ],
            'file' => [
                'icon' => '📁',
                'label' => self::translate('plugins.generic.userActivityPlugin.category.file', [], 'Files'),
                'class' => 'ua-badge-file',
            ],
            'email' => [
                'icon' => '✉️',
                'label' => self::translate('plugins.generic.userActivityPlugin.category.email', [], 'Emails & Notices'),
                'class' => 'ua-badge-email',
            ],
            'settings' => [
                'icon' => '⚙️',
                'label' => self::translate('plugins.generic.userActivityPlugin.category.settings', [], 'Settings & System'),
                'class' => 'ua-badge-settings',
            ],
            default => [
                'icon' => '🗄️',
                'label' => self::translate('plugins.generic.userActivityPlugin.category.database', [], 'Database Action'),
                'class' => 'ua-badge-database',
            ],
        };
    }

    /**
     * Get badge styling for SQL/Action operation
     */
    public static function getOperationBadge(?string $operation): array
    {
        $op = strtoupper((string) $operation);
        return match ($op) {
            'INSERT', 'CREATE', 'ADD' => ['label' => $op, 'class' => 'ua-op-insert'],
            'UPDATE', 'EDIT', 'REPLACE' => ['label' => $op, 'class' => 'ua-op-update'],
            'DELETE', 'REMOVE' => ['label' => $op, 'class' => 'ua-op-delete'],
            'LOGIN', 'REGISTER' => ['label' => $op, 'class' => 'ua-op-login'],
            'LOGOUT' => ['label' => $op, 'class' => 'ua-op-logout'],
            'LOGIN_FAILED' => ['label' => 'FAILED LOGIN', 'class' => 'ua-op-failed'],
            default => ['label' => $op ?: 'ACTION', 'class' => 'ua-op-default'],
        };
    }
}
