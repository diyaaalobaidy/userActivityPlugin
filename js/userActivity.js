/**
 * plugins/generic/userActivityPlugin/js/userActivity.js
 *
 * Copyright (c) 2026
 * Distributed under the GNU GPL v3.
 *
 * Interactive client-side controller for the OJS 3.5 Admin User Activity & Database Audit Dashboard.
 */

(function () {
    'use strict';

    function initUserActivityDashboard() {
        var container = document.getElementById('userActivityDashboard');
        if (!container || container.dataset.initialized === '1') {
            return;
        }
        container.dataset.initialized = '1';

        var apiUrls = {};
        try {
            apiUrls = JSON.parse(container.getAttribute('data-api-urls') || '{}');
        } catch (e) {
            console.error('[UserActivityPlugin] Failed parsing API URLs', e);
        }

        var currentPage = 1;
        var currentDetailItem = null;

        // DOM references
        var filterForm = document.getElementById('uaFilterForm');
        var searchInput = document.getElementById('uaSearchInput');
        var categorySelect = document.getElementById('uaCategorySelect');
        var operationSelect = document.getElementById('uaOperationSelect');
        var tableSelect = document.getElementById('uaTableSelect');
        var userSelect = document.getElementById('uaUserSelect');
        var contextSelect = document.getElementById('uaContextSelect');
        var ipInput = document.getElementById('uaIpInput');
        var dateFromInput = document.getElementById('uaDateFrom');
        var dateToInput = document.getElementById('uaDateTo');
        var perPageSelect = document.getElementById('uaPerPageSelect');

        var refreshBtn = document.getElementById('uaRefreshBtn');
        var resetBtn = document.getElementById('uaResetFilterBtn');
        var exportCsvBtn = document.getElementById('uaExportCsvBtn');
        var exportJsonBtn = document.getElementById('uaExportJsonBtn');

        var tableBody = document.getElementById('uaAuditTableBody');
        var showingInfo = document.getElementById('uaShowingInfo');
        var pageInfoBottom = document.getElementById('uaPageInfoBottom');
        var paginationTop = document.getElementById('uaPaginationTop');
        var paginationBottom = document.getElementById('uaPaginationBottom');

        // Modals
        var detailModal = document.getElementById('uaDetailModalBackdrop');
        var detailModalBody = document.getElementById('uaModalBodyContent');
        var detailCloseBtn = document.getElementById('uaModalCloseBtn');
        var detailFooterCloseBtn = document.getElementById('uaModalFooterCloseBtn');
        var copyJsonBtn = document.getElementById('uaCopyJsonBtn');

        var purgeOpenBtn = document.getElementById('uaPurgeOpenBtn');
        var purgeModal = document.getElementById('uaPurgeModalBackdrop');
        var purgeCloseBtn = document.getElementById('uaPurgeModalCloseBtn');
        var purgeCancelBtn = document.getElementById('uaPurgeCancelBtn');
        var purgeConfirmBtn = document.getElementById('uaPurgeConfirmBtn');
        var purgeDaysSelect = document.getElementById('uaPurgeDaysSelect');

        function escapeHtml(str) {
            if (str === null || str === undefined) {
                return '';
            }
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function getFilterParams(page) {
            var params = new URLSearchParams();
            params.set('page', String(page || currentPage || 1));
            params.set('perPage', perPageSelect ? perPageSelect.value : '25');

            if (searchInput && searchInput.value.trim() !== '') {
                params.set('search', searchInput.value.trim());
            }
            if (categorySelect && categorySelect.value !== 'all') {
                params.set('category', categorySelect.value);
            }
            if (operationSelect && operationSelect.value !== 'all') {
                params.set('operation', operationSelect.value);
            }
            if (tableSelect && tableSelect.value !== 'all') {
                params.set('tableName', tableSelect.value);
            }
            if (userSelect && userSelect.value !== 'all') {
                params.set('userId', userSelect.value);
            }
            if (contextSelect && contextSelect.value !== 'all') {
                params.set('contextId', contextSelect.value);
            }
            if (ipInput && ipInput.value.trim() !== '') {
                params.set('ipAddress', ipInput.value.trim());
            }
            if (dateFromInput && dateFromInput.value !== '') {
                params.set('dateFrom', dateFromInput.value);
            }
            if (dateToInput && dateToInput.value !== '') {
                params.set('dateTo', dateToInput.value);
            }

            return params;
        }

        function updateExportLinks() {
            var params = getFilterParams(1);
            params.delete('page');
            params.delete('perPage');
            var qs = params.toString();

            if (exportCsvBtn && apiUrls.exportCsv) {
                var sepCsv = apiUrls.exportCsv.indexOf('?') !== -1 ? '&' : '?';
                exportCsvBtn.href = qs ? (apiUrls.exportCsv + sepCsv + qs) : apiUrls.exportCsv;
            }
            if (exportJsonBtn && apiUrls.exportJson) {
                var sepJson = apiUrls.exportJson.indexOf('?') !== -1 ? '&' : '?';
                exportJsonBtn.href = qs ? (apiUrls.exportJson + sepJson + qs) : apiUrls.exportJson;
            }
        }

        function updateKpiStats(stats) {
            if (!stats) return;
            var formatNum = function (n) {
                return Number(n || 0).toLocaleString();
            };
            var elTotal = document.getElementById('uaStatTotal');
            var elToday = document.getElementById('uaStatToday');
            var elLogins = document.getElementById('uaStatLogins');
            var elFailed = document.getElementById('uaStatFailedLogins');
            var elDb = document.getElementById('uaStatDbMutations');
            var elUsers = document.getElementById('uaStatActiveUsers');

            if (elTotal) elTotal.textContent = formatNum(stats.total);
            if (elToday) elToday.textContent = formatNum(stats.today);
            if (elLogins) elLogins.textContent = formatNum(stats.logins);
            if (elFailed) elFailed.textContent = formatNum(stats.failedLogins);
            if (elDb) elDb.textContent = formatNum(stats.dbMutations);
            if (elUsers) elUsers.textContent = formatNum(stats.activeUsers);
        }

        function renderPaginationControls(pagination) {
            if (!pagination) return;
            currentPage = pagination.page || 1;
            var totalPages = pagination.totalPages || 1;

            if (showingInfo) {
                showingInfo.innerHTML = 'Showing <strong>' + escapeHtml(pagination.from) + '</strong> &ndash; <strong>' +
                    escapeHtml(pagination.to) + '</strong> of <strong>' + escapeHtml(Number(pagination.total || 0).toLocaleString()) + '</strong> recorded activities';
            }
            if (pageInfoBottom) {
                pageInfoBottom.textContent = 'Page ' + currentPage + ' of ' + totalPages;
            }

            var buildButtonsHtml = function () {
                var html = '';
                var prevDisabled = currentPage <= 1 ? 'disabled' : '';
                var nextDisabled = currentPage >= totalPages ? 'disabled' : '';

                html += '<button type="button" class="ua-page-btn" data-page="1" ' + prevDisabled + '>&laquo; First</button>';
                html += '<button type="button" class="ua-page-btn" data-page="' + (currentPage - 1) + '" ' + prevDisabled + '>&lsaquo; Prev</button>';

                var startP = Math.max(1, currentPage - 2);
                var endP = Math.min(totalPages, currentPage + 2);
                for (var p = startP; p <= endP; p++) {
                    var activeCls = (p === currentPage) ? 'ua-page-btn-active' : '';
                    html += '<button type="button" class="ua-page-btn ' + activeCls + '" data-page="' + p + '">' + p + '</button>';
                }

                html += '<button type="button" class="ua-page-btn" data-page="' + (currentPage + 1) + '" ' + nextDisabled + '>Next &rsaquo;</button>';
                html += '<button type="button" class="ua-page-btn" data-page="' + totalPages + '" ' + nextDisabled + '>Last &raquo;</button>';
                return html;
            };

            var controlsHtml = buildButtonsHtml();
            if (paginationTop) paginationTop.innerHTML = controlsHtml;
            if (paginationBottom) paginationBottom.innerHTML = controlsHtml;
        }

        function renderTableRows(items) {
            if (!tableBody) return;

            if (!items || items.length === 0) {
                tableBody.innerHTML = '<tr><td colspan="9" class="ua-empty-row">No matching user or database activity records found.</td></tr>';
                return;
            }

            var rowsHtml = '';
            for (var i = 0; i < items.length; i++) {
                var log = items[i];
                var catBadge = log.categoryBadge || { class: 'ua-badge-database', icon: '🗄️', label: log.eventCategory };
                var opBadge = log.operationBadge || { class: 'ua-op-default', label: log.operation };

                var userMeta = log.userId
                    ? '<span class="ua-mono">@' + escapeHtml(log.username) + '</span> (#' + escapeHtml(log.userId) + ')'
                    : '<span class="ua-text-muted">' + escapeHtml(log.username) + '</span>';

                var rolePill = log.userRoles
                    ? '<span class="ua-role-pill">' + escapeHtml(log.userRoles) + '</span>'
                    : '';

                var tableCell = log.tableName
                    ? '<div class="ua-table-name">' + escapeHtml(log.humanTable) + '</div>' +
                      '<div class="ua-mono ua-text-xs ua-text-muted">' + escapeHtml(log.tableName) +
                      (log.recordId && log.recordId !== '-' ? ' #' + escapeHtml(log.recordId) : '') + '</div>'
                    : '<span class="ua-text-muted">&mdash;</span>';

                var reqUrlShort = log.requestUrl || '';
                if (reqUrlShort.length > 85) {
                    reqUrlShort = reqUrlShort.substring(0, 85) + '...';
                }
                var reqLine = log.requestUrl
                    ? '<div class="ua-request-line ua-mono"><span class="ua-http-method">' + escapeHtml(log.httpMethod) + '</span> ' + escapeHtml(reqUrlShort) + '</div>'
                    : '';

                rowsHtml += '<tr data-activity-id="' + escapeHtml(log.activityId) + '">' +
                    '<td class="ua-mono ua-text-muted">#' + escapeHtml(log.activityId) + '</td>' +
                    '<td class="ua-date-cell">' + escapeHtml(log.createdAt) + '</td>' +
                    '<td class="ua-user-cell">' +
                        '<div class="ua-user-name">' + escapeHtml(log.userFullName) + '</div>' +
                        '<div class="ua-user-meta">' + userMeta + '</div>' +
                        rolePill +
                    '</td>' +
                    '<td><span class="ua-cat-badge ' + escapeHtml(catBadge.class) + '">' + escapeHtml(catBadge.icon) + ' ' + escapeHtml(catBadge.label) + '</span></td>' +
                    '<td><span class="ua-op-badge ' + escapeHtml(opBadge.class) + '">' + escapeHtml(opBadge.label) + '</span></td>' +
                    '<td>' + tableCell + '</td>' +
                    '<td class="ua-summary-cell"><div class="ua-summary-text">' + escapeHtml(log.actionSummary) + '</div>' + reqLine + '</td>' +
                    '<td><div class="ua-mono ua-ip-tag">' + escapeHtml(log.ipAddress) + '</div><div class="ua-context-tag">' + escapeHtml(log.contextPath) + '</div></td>' +
                    '<td style="text-align: center;"><button type="button" class="ua-btn ua-btn-xs ua-btn-inspect ua-inspect-btn" data-id="' + escapeHtml(log.activityId) + '">🔍 Inspect</button></td>' +
                '</tr>';
            }

            tableBody.innerHTML = rowsHtml;
        }

        function loadLogs(page) {
            if (!apiUrls.fetchLogs) return;
            updateExportLinks();

            var params = getFilterParams(page || 1);
            var sep = apiUrls.fetchLogs.indexOf('?') !== -1 ? '&' : '?';
            var url = apiUrls.fetchLogs + sep + params.toString();

            if (tableBody) {
                tableBody.style.opacity = '0.55';
            }

            fetch(url, {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (tableBody) tableBody.style.opacity = '1';
                    if (data && data.status) {
                        updateKpiStats(data.stats);
                        renderTableRows(data.items || []);
                        renderPaginationControls(data.pagination);
                    }
                })
                .catch(function (err) {
                    if (tableBody) tableBody.style.opacity = '1';
                    console.error('[UserActivityPlugin] Failed loading logs', err);
                });
        }

        function buildDiffTableHtml(oldObj, newObj) {
            if (!oldObj && !newObj) {
                return '';
            }
            var keysMap = {};
            if (oldObj && typeof oldObj === 'object') {
                Object.keys(oldObj).forEach(function (k) { keysMap[k] = true; });
            }
            if (newObj && typeof newObj === 'object') {
                Object.keys(newObj).forEach(function (k) { keysMap[k] = true; });
            }
            var keys = Object.keys(keysMap);
            if (keys.length === 0) {
                return '';
            }

            var formatVal = function (v) {
                if (v === undefined || v === null) return '<em class="ua-text-muted">NULL</em>';
                if (typeof v === 'object') return '<code>' + escapeHtml(JSON.stringify(v)) + '</code>';
                return escapeHtml(String(v));
            };

            var html = '<div class="ua-detail-section">' +
                '<h4 class="ua-detail-section-title">📊 Field-by-Field Data Changes (Before vs. After)</h4>' +
                '<table class="ua-diff-table">' +
                '<thead><tr><th style="width: 26%;">Column / Field</th><th style="width: 37%;">Old Value (Before)</th><th style="width: 37%;">New Value (After)</th></tr></thead><tbody>';

            for (var i = 0; i < keys.length; i++) {
                var k = keys[i];
                var oV = (oldObj && typeof oldObj === 'object') ? oldObj[k] : undefined;
                var nV = (newObj && typeof newObj === 'object') ? newObj[k] : undefined;
                html += '<tr>' +
                    '<td class="ua-mono ua-font-bold">' + escapeHtml(k) + '</td>' +
                    '<td class="ua-diff-old">' + formatVal(oV) + '</td>' +
                    '<td class="ua-diff-new">' + formatVal(nV) + '</td>' +
                '</tr>';
            }

            html += '</tbody></table></div>';
            return html;
        }

        function openInspectModal(activityId) {
            if (!detailModal || !detailModalBody || !apiUrls.viewDetails) return;
            currentDetailItem = null;
            detailModal.style.display = 'flex';
            detailModalBody.innerHTML = '<div class="ua-loading-spinner">Loading complete database audit record #' + escapeHtml(activityId) + '...</div>';

            var sep = apiUrls.viewDetails.indexOf('?') !== -1 ? '&' : '?';
            var url = apiUrls.viewDetails + sep + 'activityId=' + encodeURIComponent(activityId);

            fetch(url, {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data || !data.status || !data.item) {
                        detailModalBody.innerHTML = '<div class="ua-error-box">Could not load activity details.</div>';
                        return;
                    }

                    var item = data.item;
                    currentDetailItem = item;
                    var catBadge = item.categoryBadge || { class: 'ua-badge-database', icon: '🗄️', label: item.eventCategory };
                    var opBadge = item.operationBadge || { class: 'ua-op-default', label: item.operation };

                    var html = '<div class="ua-detail-header-card">' +
                        '<div class="ua-detail-badges">' +
                            '<span class="ua-cat-badge ' + escapeHtml(catBadge.class) + '">' + escapeHtml(catBadge.icon) + ' ' + escapeHtml(catBadge.label) + '</span> ' +
                            '<span class="ua-op-badge ' + escapeHtml(opBadge.class) + '">' + escapeHtml(opBadge.label) + '</span> ' +
                            '<span class="ua-mono ua-text-muted">Event Type: ' + escapeHtml(item.eventType) + '</span>' +
                        '</div>' +
                        '<h3 class="ua-detail-main-summary">' + escapeHtml(item.actionSummary) + '</h3>' +
                    '</div>';

                    // Metadata Grid
                    html += '<div class="ua-detail-meta-cards">' +
                        '<div class="ua-meta-card">' +
                            '<h5>👤 User & Session</h5>' +
                            '<div><strong>Full Name:</strong> ' + escapeHtml(item.userFullName) + '</div>' +
                            '<div><strong>Username:</strong> <span class="ua-mono">' + escapeHtml(item.username) + '</span>' + (item.userId ? ' (ID: #' + escapeHtml(item.userId) + ')' : '') + '</div>' +
                            '<div><strong>Email:</strong> ' + escapeHtml(item.userEmail || '-') + '</div>' +
                            '<div><strong>Roles:</strong> <span class="ua-role-pill">' + escapeHtml(item.userRoles) + '</span></div>' +
                            '<div><strong>Session ID:</strong> <span class="ua-mono ua-text-xs">' + escapeHtml(item.sessionId || '-') + '</span></div>' +
                        '</div>' +
                        '<div class="ua-meta-card">' +
                            '<h5>🌐 Request & Database Target</h5>' +
                            '<div><strong>Timestamp:</strong> ' + escapeHtml(item.createdAt) + '</div>' +
                            '<div><strong>Client IP Address:</strong> <span class="ua-mono ua-ip-tag">' + escapeHtml(item.ipAddress) + '</span></div>' +
                            '<div><strong>Journal / Context:</strong> ' + escapeHtml(item.contextPath) + (item.contextId ? ' (ID: ' + escapeHtml(item.contextId) + ')' : '') + '</div>' +
                            '<div><strong>Database Table:</strong> <span class="ua-mono">' + escapeHtml(item.tableName || '-') + '</span> (' + escapeHtml(item.humanTable) + ')</div>' +
                            '<div><strong>Record Primary Key:</strong> <span class="ua-mono">' + escapeHtml(item.recordId) + '</span></div>' +
                            '<div><strong>HTTP Endpoint:</strong> <span class="ua-mono">' + escapeHtml(item.httpMethod) + ' ' + escapeHtml(item.requestUrl || '-') + '</span></div>' +
                        '</div>' +
                    '</div>';

                    if (item.userAgent) {
                        html += '<div class="ua-user-agent-bar"><strong>User-Agent:</strong> <span class="ua-mono">' + escapeHtml(item.userAgent) + '</span></div>';
                    }

                    // Diff Table
                    html += buildDiffTableHtml(item.oldValues, item.newValues);

                    // Where Conditions
                    if (item.whereConditionsRaw) {
                        html += '<div class="ua-detail-section">' +
                            '<h4 class="ua-detail-section-title">🎯 SQL WHERE Conditions</h4>' +
                            '<pre class="ua-code-pre">' + escapeHtml(item.whereConditionsRaw) + '</pre>' +
                        '</div>';
                    }

                    // Full SQL Query
                    if (item.sqlQuery) {
                        html += '<div class="ua-detail-section">' +
                            '<h4 class="ua-detail-section-title">🗄️ Executed SQL Statement</h4>' +
                            '<pre class="ua-code-pre ua-sql-pre">' + escapeHtml(item.sqlQuery) + '</pre>' +
                        '</div>';
                    }

                    // Request Payload
                    if (item.requestPayload) {
                        html += '<div class="ua-detail-section">' +
                            '<h4 class="ua-detail-section-title">📥 HTTP Request Body Payload (Sanitized)</h4>' +
                            '<pre class="ua-code-pre">' + escapeHtml(item.requestPayload) + '</pre>' +
                        '</div>';
                    }

                    detailModalBody.innerHTML = html;
                })
                .catch(function (err) {
                    detailModalBody.innerHTML = '<div class="ua-error-box">Error loading details: ' + escapeHtml(err.message) + '</div>';
                });
        }

        // Event Listeners
        if (filterForm) {
            filterForm.addEventListener('submit', function (e) {
                e.preventDefault();
                loadLogs(1);
            });
        }

        [categorySelect, operationSelect, tableSelect, userSelect, contextSelect, dateFromInput, dateToInput, perPageSelect].forEach(function (el) {
            if (el) {
                el.addEventListener('change', function () {
                    loadLogs(1);
                });
            }
        });

        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                if (filterForm) filterForm.reset();
                loadLogs(1);
            });
        }

        if (refreshBtn) {
            refreshBtn.addEventListener('click', function () {
                loadLogs(currentPage);
            });
        }

        // Delegate pagination & inspect button clicks
        container.addEventListener('click', function (e) {
            var pageBtn = e.target.closest('.ua-page-btn');
            if (pageBtn && !pageBtn.disabled) {
                var targetPage = parseInt(pageBtn.getAttribute('data-page') || '1', 10);
                if (targetPage > 0) {
                    loadLogs(targetPage);
                }
                return;
            }

            var inspectBtn = e.target.closest('.ua-inspect-btn');
            if (inspectBtn) {
                var actId = inspectBtn.getAttribute('data-id');
                if (actId) {
                    openInspectModal(actId);
                }
            }
        });

        // Close Detail Modal
        var closeDetailModal = function () {
            if (detailModal) detailModal.style.display = 'none';
        };
        if (detailCloseBtn) detailCloseBtn.addEventListener('click', closeDetailModal);
        if (detailFooterCloseBtn) detailFooterCloseBtn.addEventListener('click', closeDetailModal);
        if (detailModal) {
            detailModal.addEventListener('click', function (e) {
                if (e.target === detailModal) closeDetailModal();
            });
        }

        if (copyJsonBtn) {
            copyJsonBtn.addEventListener('click', function () {
                if (!currentDetailItem) return;
                var jsonText = JSON.stringify(currentDetailItem, null, 2);
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(jsonText).then(function () {
                        var orig = copyJsonBtn.textContent;
                        copyJsonBtn.textContent = '✓ Copied JSON!';
                        setTimeout(function () { copyJsonBtn.textContent = orig; }, 2000);
                    });
                }
            });
        }

        // Purge Modal
        var closePurgeModal = function () {
            if (purgeModal) purgeModal.style.display = 'none';
        };
        if (purgeOpenBtn) {
            purgeOpenBtn.addEventListener('click', function () {
                if (purgeModal) purgeModal.style.display = 'flex';
            });
        }
        if (purgeCloseBtn) purgeCloseBtn.addEventListener('click', closePurgeModal);
        if (purgeCancelBtn) purgeCancelBtn.addEventListener('click', closePurgeModal);

        if (purgeConfirmBtn && apiUrls.purgeLogs) {
            purgeConfirmBtn.addEventListener('click', function () {
                var days = purgeDaysSelect ? purgeDaysSelect.value : '90';
                purgeConfirmBtn.disabled = true;

                var formData = new URLSearchParams();
                formData.set('days', days);

                fetch(apiUrls.purgeLogs, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'Accept': 'application/json'
                    },
                    credentials: 'same-origin',
                    body: formData.toString()
                })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        purgeConfirmBtn.disabled = false;
                        closePurgeModal();
                        if (data && data.status) {
                            loadLogs(1);
                        }
                    })
                    .catch(function () {
                        purgeConfirmBtn.disabled = false;
                        closePurgeModal();
                    });
            });
        }

        // Initialize initial pagination buttons
        if (paginationBottom) {
            var initPage = parseInt(paginationBottom.getAttribute('data-page') || '1', 10);
            var initTotalPages = parseInt(paginationBottom.getAttribute('data-total-pages') || '1', 10);
            renderPaginationControls({
                page: initPage,
                totalPages: initTotalPages,
                from: container.querySelectorAll('#uaAuditTableBody tr[data-activity-id]').length > 0 ? 1 : 0,
                to: container.querySelectorAll('#uaAuditTableBody tr[data-activity-id]').length,
                total: (document.getElementById('uaStatTotal') ? document.getElementById('uaStatTotal').textContent.replace(/,/g, '') : 0)
            });
        }

        updateExportLinks();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initUserActivityDashboard);
    } else {
        initUserActivityDashboard();
    }
})();
