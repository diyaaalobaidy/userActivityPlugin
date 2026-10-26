<?php

/**
 * @file plugins/generic/userActivityPlugin/UserActivityPlugin.php
 *
 * Copyright (c) 2026
 * Distributed under the GNU GPL v3.
 *
 * @class UserActivityPlugin
 * @ingroup plugins_generic_userActivityPlugin
 *
 * @brief System-wide OJS 3.5 plugin accessible strictly by Site Administrators
 *        to record and inspect all user activities from login/logout to any
 *        action performed on the database.
 */

namespace APP\plugins\generic\userActivityPlugin;

use APP\core\Application;
use APP\plugins\generic\userActivityPlugin\classes\handlers\UserActivityHandler;
use APP\plugins\generic\userActivityPlugin\classes\migration\UserActivitySchemaMigration;
use APP\plugins\generic\userActivityPlugin\classes\UserActivityHelper;
use APP\plugins\generic\userActivityPlugin\classes\UserActivityRecorder;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\DB;
use PKP\core\JSONMessage;
use PKP\core\PKPApplication;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\linkAction\request\RedirectAction;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

class UserActivityPlugin extends GenericPlugin
{
    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return UserActivityHelper::translate(
            'plugins.generic.userActivityPlugin.displayName',
            [],
            'User Activity & Database Audit System Plugin'
        );
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return UserActivityHelper::translate(
            'plugins.generic.userActivityPlugin.description',
            [],
            'System-wide security and audit plugin (Admin Only) that records and displays all user activities from login/logout to every INSERT, UPDATE, and DELETE action performed on the database.'
        );
    }

    /**
     * @copydoc Plugin::isSitePlugin()
     *
     * Marks this plugin as a site-wide system plugin so it operates across all journals
     * and can only be managed by Site Administrators.
     */
    public function isSitePlugin()
    {
        return true;
    }

    /**
     * @copydoc LazyLoadPlugin::getEnabled()
     *
     * Permanently active system-wide so no user activity can bypass recording.
     */
    public function getEnabled($contextId = null)
    {
        return true;
    }

    /**
     * @copydoc LazyLoadPlugin::getCanEnable()
     */
    public function getCanEnable()
    {
        return false;
    }

    /**
     * @copydoc LazyLoadPlugin::getCanDisable()
     */
    public function getCanDisable()
    {
        return false;
    }

    /**
     * @copydoc Plugin::getInstallMigration()
     */
    public function getInstallMigration()
    {
        return new UserActivitySchemaMigration();
    }

    /**
     * @copydoc Plugin::register()
     */
    public function register($category, $path, $mainContextId = null)
    {
        try {
            $success = parent::register($category, $path, $mainContextId);
            if (!$success) {
                return false;
            }

            $localePath = __DIR__ . '/locale';
            if (is_dir($localePath)) {
                \PKP\facades\Locale::registerPath($localePath);
            }

            if (Application::isUnderMaintenance()) {
                return true;
            }

            // Initialize universal system-wide activity & database recorder on every request
            UserActivityRecorder::init();

            // Register custom page handler (`userActivity`) for the Admin Audit Viewer
            Hook::add('LoadHandler', $this->callbackLoadHandler(...));

            // Also support ComponentRouter requests if invoked via $$$call$$$
            Hook::add('LoadComponentHandler', $this->callbackLoadComponentHandler(...));

            // Inject navigation link in the backend sidebar & Admin page for Site Administrators only
            Hook::add('TemplateManager::display', $this->callbackTemplateDisplay(...));

            return true;
        } catch (\Throwable $e) {
            UserActivityHelper::logError('Error registering UserActivityPlugin', $e);
            return false;
        }
    }

    /**
     * Route page requests `.../userActivity/{op}` to `UserActivityHandler`
     */
    public function callbackLoadHandler(string $hookName, array $args): bool
    {
        try {
            $page = $args[0] ?? '';
            if ($page === 'userActivity' || $page === 'user-a') {
                $args[3] = new UserActivityHandler($this);
                return Hook::ABORT;
            }
        } catch (\Throwable $e) {
            UserActivityHelper::logError('Error in callbackLoadHandler', $e);
        }

        return Hook::CONTINUE;
    }

    /**
     * Route component requests (`$$$call$$$`) if triggered from component router context
     */
    public function callbackLoadComponentHandler(string $hookName, mixed ...$args): bool
    {
        try {
            if (isset($args[0]) && is_array($args[0])) {
                $params = &$args[0];
                $component = &$params[0];
                $op = &$params[1];
                $componentInstance = &$params[2];
            } else {
                $component = &$args[0];
                $op = &$args[1];
                $componentInstance = &$args[2];
            }

            if (
                $component === 'userActivity'
                || $component === 'userA'
                || $component === 'UserActivityHandler'
                || str_ends_with((string) $component, 'UserActivityHandler')
            ) {
                $componentInstance = new UserActivityHandler($this);
                return Hook::ABORT;
            }
        } catch (\Throwable $e) {
            UserActivityHelper::logError('Error in callbackLoadComponentHandler', $e);
        }

        return Hook::CONTINUE;
    }

    /**
     * Build a guaranteed Page Router URL (`.../index.php/{context}/userActivity/{op}`)
     * regardless of whether the current request is handled by PageRouter or ComponentRouter (`$$$call$$$`).
     */
    public function getPageUrl($request, string $op = 'index', ?array $params = null): string
    {
        try {
            $context = $request->getContext();
            $contextPath = $context ? $context->getPath() : Application::SITE_CONTEXT_PATH;
            $dispatcher = $request->getDispatcher();
            if ($dispatcher) {
                return $dispatcher->url(
                    $request,
                    PKPApplication::ROUTE_PAGE,
                    $contextPath,
                    'userActivity',
                    $op,
                    null,
                    $params
                );
            }
        } catch (\Throwable $e) {
            UserActivityHelper::logError('Error in getPageUrl dispatcher', $e);
        }

        $baseUrl = rtrim($request->getBaseUrl(), '/');
        $contextPath = $request->getContext() ? $request->getContext()->getPath() : 'index';
        $url = "{$baseUrl}/index.php/{$contextPath}/userActivity/{$op}";
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        return $url;
    }

    /**
     * Inject the "User Activity Audit Log" menu item into the OJS 3.5 backend navigation menu
     * strictly when the current user is a Site Administrator.
     */
    public function callbackTemplateDisplay(string $hookName, array $args): bool
    {
        try {
            $request = Application::get()->getRequest();
            if (!UserActivityHelper::isCurrentUserAdmin($request)) {
                return Hook::CONTINUE;
            }

            $templateMgr = $args[0] ?? TemplateManager::getManager($request);
            if (!$templateMgr instanceof TemplateManager) {
                return Hook::CONTINUE;
            }

            $dashboardUrl = $this->getPageUrl($request, 'index');

            // Add menu item into OJS 3.5 backend sidebar menu state (`menu` variable)
            $menu = $templateMgr->getState('menu');
            if (is_array($menu)) {
                $menu['userActivityAudit'] = [
                    'name' => UserActivityHelper::translate(
                        'plugins.generic.userActivityPlugin.menuName',
                        [],
                        'User Activity & DB Audit'
                    ),
                    'url' => $dashboardUrl,
                    'isCurrent' => ($request->getRequestedPage() === 'userActivity'),
                    'icon' => 'Shield',
                ];
                $templateMgr->setState(['menu' => $menu]);
            }
        } catch (\Throwable $e) {
            UserActivityHelper::logError('Error in callbackTemplateDisplay', $e);
        }

        return Hook::CONTINUE;
    }

    /**
     * @copydoc Plugin::getActions()
     *
     * Exposes management actions in the Plugin Gallery ONLY to Site Administrators.
     */
    public function getActions($request, $actionArgs)
    {
        try {
            $actions = parent::getActions($request, $actionArgs);

            // Strictly hide plugin actions from non-administrators
            if (!UserActivityHelper::isCurrentUserAdmin($request)) {
                return [];
            }

            $router = $request->getRouter();
            $dashboardUrl = $this->getPageUrl($request, 'index');

            // 1. Direct link to full-page Audit Dashboard (using explicit ROUTE_PAGE URL)
            $dashboardAction = new LinkAction(
                'openAuditDashboard',
                new RedirectAction($dashboardUrl),
                UserActivityHelper::translate(
                    'plugins.generic.userActivityPlugin.openDashboard',
                    [],
                    'Activity & Database Audit Log'
                ),
                'information'
            );

            // 2. Quick Modal viewer inside Plugin Gallery
            $modalAction = new LinkAction(
                'quickStatsModal',
                new AjaxModal(
                    $router->url($request, null, null, 'manage', null, [
                        'verb' => 'summary',
                        'plugin' => $this->getName(),
                        'category' => 'generic',
                    ]),
                    $this->getDisplayName(),
                    'modal_information'
                ),
                UserActivityHelper::translate(
                    'plugins.generic.userActivityPlugin.quickSummary',
                    [],
                    'Quick Audit Summary'
                ),
                'settings'
            );

            array_unshift($actions, $dashboardAction, $modalAction);
            return $actions;
        } catch (\Throwable $e) {
            UserActivityHelper::logError('Error in getActions', $e);
            return parent::getActions($request, $actionArgs);
        }
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request)
    {
        try {
            // Strictly enforce Site Administrator access
            if (!UserActivityHelper::isCurrentUserAdmin($request)) {
                return new JSONMessage(
                    false,
                    UserActivityHelper::translate(
                        'plugins.generic.userActivityPlugin.adminOnlyError',
                        [],
                        'Access Denied: Only Site Administrators can access the User Activity & Database Audit Plugin.'
                    )
                );
            }

            UserActivityHelper::ensureTableExists();
            UserActivityRecorder::flushPendingLogs();

            // Ensure sitewide and non-lazy-load flags in versions table so OJS loads it on every request
            try {
                DB::table('versions')
                    ->where('product', 'userActivityPlugin')
                    ->update(['lazy_load' => 1, 'sitewide' => 1]);
            } catch (\Throwable) {
            }

            $verb = $request->getUserVar('verb') ?: ($args['verb'] ?? 'summary');

            if ($verb === 'summary') {
                $handler = new UserActivityHandler($this);
                $stats = $handler->getSummaryStats();
                $dashboardUrl = $this->getPageUrl($request, 'index');
                $exportCsvUrl = $this->getPageUrl($request, 'exportCsv');
                $exportJsonUrl = $this->getPageUrl($request, 'exportJson');

                $recentLogs = DB::table('user_activity_logs')
                    ->orderByDesc('activity_id')
                    ->limit(10)
                    ->get();

                $templateMgr = TemplateManager::getManager($request);
                $templateMgr->assign([
                    'stats' => $stats,
                    'recentLogs' => $recentLogs,
                    'dashboardUrl' => $dashboardUrl,
                    'exportCsvUrl' => $exportCsvUrl,
                    'exportJsonUrl' => $exportJsonUrl,
                ]);

                return new JSONMessage(true, $templateMgr->fetch($this->getTemplateResource('quickSummaryModal.tpl')));
            }

            return parent::manage($args, $request);
        } catch (\Throwable $e) {
            UserActivityHelper::logError('Error in manage()', $e);
            return new JSONMessage(false, 'An unexpected error occurred while loading the audit summary.');
        }
    }
}
