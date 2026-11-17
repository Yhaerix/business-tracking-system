<?php
/**
 * User Administration & Access Control View
 * Small Business Sales and Expense Tracking System
 */

declare(strict_types=1);

$pageTitle = 'User Management';

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

require_login();
require_permission('users_manage');

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$db = get_db();

// Pre-load available roles for dropdown filters and modal selects
$stmtRoles = $db->query("SELECT id, name, label, description FROM roles ORDER BY id ASC");
$roles = $stmtRoles->fetchAll();

// Pre-load user statistics for initial SSR render
$stmtCounts = $db->query("
    SELECT 
        COUNT(*) AS total_users,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_users,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) AS suspended_users
    FROM users
");
$userStats = $stmtCounts->fetch() ?: ['total_users' => 0, 'active_users' => 0, 'suspended_users' => 0];

require_once __DIR__ . '/../../includes/header.php';
?>

<style>
    /* User Management Specific Styling */
    .user-avatar-badge {
        width: 36px;
        height: 36px;
        border-radius: var(--radius-full);
        background: var(--color-primary-light);
        color: var(--color-primary);
        font-weight: 700;
        font-size: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-transform: uppercase;
        flex-shrink: 0;
    }

    .user-meta-cell {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .user-meta-info {
        display: flex;
        flex-direction: column;
    }

    .user-display-name {
        font-weight: 600;
        color: var(--text-main);
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .user-username-label {
        font-size: 12px;
        color: var(--text-muted);
    }

    .badge-self {
        background: #e0e7ff;
        color: #4338ca;
        font-size: 10px;
        padding: 2px 6px;
        border-radius: var(--radius-full);
        font-weight: 600;
        text-transform: uppercase;
    }

    .security-notice-box {
        background: #f8fafc;
        border: 1px dashed var(--border-color);
        border-radius: var(--radius-md);
        padding: 12px 14px;
        font-size: 12.5px;
        color: var(--text-muted);
        line-height: 1.45;
    }

    .action-buttons-group {
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
</style>

<!-- Page Header & Action Bar -->
<div class="page-header"
    style="display: flex; justify-content: flex-end; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 10px;">
    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <button type="button" class="btn btn-outline" id="refreshUsersBtn">
            🔄 Refresh
        </button>
        <button type="button" class="btn btn-primary" id="openCreateUserBtn">
            <span>+</span> Add New User
        </button>
    </div>
</div>

<!-- Stat KPI Cards Grid -->
<div class="stats-grid">
    <!-- Stat 1: Total Accounts -->
    <div class="card-stat">
        <div class="stat-header">
            <span class="stat-label">Total Accounts</span>
            <div class="stat-icon icon-primary">👥</div>
        </div>
        <div class="stat-value tabular-nums" id="kpiTotalUsers">
            <?= (int) $userStats['total_users'] ?>
        </div>
        <div class="stat-subtext">Registered system users</div>
    </div>

    <!-- Stat 2: Active Operators -->
    <div class="card-stat">
        <div class="stat-header">
            <span class="stat-label">Active Users</span>
            <div class="stat-icon icon-success">✅</div>
        </div>
        <div class="stat-value tabular-nums" id="kpiActiveUsers" style="color: var(--color-success);">
            <?= (int) $userStats['active_users'] ?>
        </div>
        <div class="stat-subtext">Permitted to sign in</div>
    </div>

    <!-- Stat 3: Suspended / Deactivated -->
    <div class="card-stat">
        <div class="stat-header">
            <span class="stat-label">Suspended Accounts</span>
            <div class="stat-icon icon-danger">⛔</div>
        </div>
        <div class="stat-value tabular-nums" id="kpiSuspendedUsers" style="color: var(--color-danger);">
            <?= (int) $userStats['suspended_users'] ?>
        </div>
        <div class="stat-subtext">Access revoked / blocked</div>
    </div>

    <!-- Stat 4: Active Session -->
    <div class="card-stat">
        <div class="stat-header">
            <span class="stat-label">Current Session</span>
            <div class="stat-icon icon-warning">🛡️</div>
        </div>
        <div class="stat-value" style="font-size: 18px; font-weight: 600; line-height: 1.4;">
            <?= htmlspecialchars($_SESSION['username'] ?? 'User', ENT_QUOTES, 'UTF-8') ?>
        </div>
        <div class="stat-subtext">Role: <?= htmlspecialchars($_SESSION['role_name'] ?? 'admin', ENT_QUOTES, 'UTF-8') ?>
        </div>
    </div>
</div>

<!-- Filter Toolbar -->
<div class="filter-bar">
    <div class="filter-group" style="flex: 2; min-width: 220px;">
        <input type="text" class="form-input" id="searchFilter" placeholder="🔍 Search by username or full name...">
    </div>

    <div class="filter-group">
        <select class="form-select" id="roleFilter">
            <option value="">All Roles</option>
            <?php foreach ($roles as $r): ?>
                <option value="<?= (int) $r['id'] ?>"><?= htmlspecialchars($r['label'], ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="filter-group">
        <select class="form-select" id="statusFilter">
            <option value="">All Statuses</option>
            <option value="1">Active</option>
            <option value="0">Suspended</option>
        </select>
    </div>

    <div class="filter-group-fixed">
        <button type="button" class="btn btn-outline" id="clearFiltersBtn">
            Reset Filters
        </button>
    </div>
</div>

<!-- Users Data Table Container -->
<div class="table-responsive">
    <table class="table" id="usersTable">
        <thead>
            <tr>
                <th style="width: 70px;">ID</th>
                <th>User Account</th>
                <th>Role</th>
                <th>Status</th>
                <th>Security / Lockout</th>
                <th>Last Login</th>
                <th>Created</th>
                <th class="text-right" style="width: 180px;">Actions</th>
            </tr>
        </thead>
        <tbody id="usersTableBody">
            <tr>
                <td colspan="8" style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
                    Loading system users...
                </td>
            </tr>
        </tbody>
    </table>
</div>

<!-- ========================================================================= -->
<!-- MODAL 1: Create User Modal                                                -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="createUserModal" role="dialog" aria-modal="true" aria-labelledby="createUserTitle">
    <div class="modal-card">
        <div class="modal-header">
            <h2 class="modal-title" id="createUserTitle">Add New System User</h2>
            <button type="button" class="modal-close" id="closeCreateModalBtn" aria-label="Close modal">&times;</button>
        </div>

        <form id="createUserForm" autocomplete="off">
            <div class="modal-body">
                <div id="createAlert" style="display: none;"></div>

                <div class="form-group">
                    <label class="form-label" for="newUsername">Username <span
                            style="color: var(--color-danger);">*</span></label>
                    <input type="text" class="form-input" id="newUsername" name="username" placeholder="e.g. john_doe"
                        required minlength="3" maxlength="50" pattern="^[a-zA-Z0-9_.-]+$">
                    <small style="font-size: 11.5px; color: var(--text-muted); display: block; margin-top: 4px;">
                        3 to 50 characters. Letters, numbers, dots, and underscores only.
                    </small>
                </div>

                <div class="form-group">
                    <label class="form-label" for="newFullName">Full Name <span
                            style="color: var(--color-danger);">*</span></label>
                    <input type="text" class="form-input" id="newFullName" name="full_name" placeholder="e.g. John Doe"
                        required maxlength="100">
                </div>

                <div class="form-group">
                    <label class="form-label" for="newEmail">Email Address <span
                            style="color: var(--color-danger);">*</span></label>
                    <input type="email" class="form-input" id="newEmail" name="email"
                        placeholder="e.g. manager@gmail.com" required maxlength="150">
                    <small style="font-size: 11.5px; color: var(--text-muted); display: block; margin-top: 4px;">
                        Required for receiving 6-digit login verification codes (OTP).
                    </small>
                </div>

                <div class="form-group">
                    <label class="form-label" for="newRoleId">System Role <span
                            style="color: var(--color-danger);">*</span></label>
                    <select class="form-select" id="newRoleId" name="role_id" required>
                        <option value="">Select an operational role...</option>
                        <?php foreach ($roles as $r): ?>
                            <option value="<?= (int) $r['id'] ?>">
                                <?= htmlspecialchars($r['label'], ENT_QUOTES, 'UTF-8') ?>
                                (<?= htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="newPassword">Password <span
                            style="color: var(--color-danger);">*</span></label>
                    <input type="password" class="form-input" id="newPassword" name="password"
                        placeholder="Minimum 8 characters" required minlength="8">
                </div>

                <div class="form-group">
                    <label class="form-label" for="newConfirmPassword">Confirm Password <span
                            style="color: var(--color-danger);">*</span></label>
                    <input type="password" class="form-input" id="newConfirmPassword" name="confirm_password"
                        placeholder="Re-enter password" required minlength="8">
                </div>

                <div class="security-notice-box">
                    🔒 <strong>Security Policy:</strong> Passwords are cryptographically hashed using standard Bcrypt
                    (cost 12). Plaintext passwords are never stored or logged.
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline" id="cancelCreateBtn">Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveCreateBtn">Create Account</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 2: Edit User Modal                                                  -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="editUserModal" role="dialog" aria-modal="true" aria-labelledby="editUserTitle">
    <div class="modal-card">
        <div class="modal-header">
            <h2 class="modal-title" id="editUserTitle">Edit Operator Account</h2>
            <button type="button" class="modal-close" id="closeEditModalBtn" aria-label="Close modal">&times;</button>
        </div>

        <form id="editUserForm" autocomplete="off">
            <input type="hidden" id="editUserId" name="id">

            <div class="modal-body">
                <div id="editAlert" style="display: none;"></div>

                <div class="form-group">
                    <label class="form-label">Username</label>
                    <input type="text" class="form-input" id="editUsernameDisplay" disabled
                        style="background-color: var(--bg-body); cursor: not-allowed; opacity: 0.85;">
                    <small style="font-size: 11.5px; color: var(--text-muted); display: block; margin-top: 4px;">
                        Usernames are permanent identifiers and cannot be altered.
                    </small>
                </div>

                <div class="form-group">
                    <label class="form-label" for="editFullName">Full Name <span
                            style="color: var(--color-danger);">*</span></label>
                    <input type="text" class="form-input" id="editFullName" name="full_name" required maxlength="100">
                </div>

                <div class="form-group">
                    <label class="form-label" for="editEmail">Email Address <span
                            style="color: var(--color-danger);">*</span></label>
                    <input type="email" class="form-input" id="editEmail" name="email"
                        placeholder="e.g. manager@gmail.com" required maxlength="150">
                    <small style="font-size: 11.5px; color: var(--text-muted); display: block; margin-top: 4px;">
                        Used for receiving 6-digit login verification codes (OTP).
                    </small>
                </div>

                <div class="form-group">
                    <label class="form-label" for="editRoleId">Assigned Role <span
                            style="color: var(--color-danger);">*</span></label>
                    <select class="form-select" id="editRoleId" name="role_id" required>
                        <?php foreach ($roles as $r): ?>
                            <option value="<?= (int) $r['id'] ?>">
                                <?= htmlspecialchars($r['label'], ENT_QUOTES, 'UTF-8') ?>
                                (<?= htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="selfRoleNotice" style="display: none; margin-top: 6px; font-size: 12px; color: #b45309;">
                        ⚠️ You cannot alter your own role to prevent revoking administrative rights on your current
                        session.
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="editIsActive">Account Status <span
                            style="color: var(--color-danger);">*</span></label>
                    <select class="form-select" id="editIsActive" name="is_active" required>
                        <option value="1">Active (Access Allowed)</option>
                        <option value="0">Suspended (Access Revoked)</option>
                    </select>
                    <div id="selfStatusNotice"
                        style="display: none; margin-top: 6px; font-size: 12px; color: var(--color-danger);">
                        ⚠️ You cannot deactivate or suspend your own active account.
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline" id="cancelEditBtn">Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveEditBtn">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 3: Reset Password Modal                                             -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="resetPasswordModal" role="dialog" aria-modal="true" aria-labelledby="resetPasswordTitle">
    <div class="modal-card">
        <div class="modal-header">
            <h2 class="modal-title" id="resetPasswordTitle">Reset User Password</h2>
            <button type="button" class="modal-close" id="closeResetModalBtn" aria-label="Close modal">&times;</button>
        </div>

        <form id="resetPasswordForm" autocomplete="off">
            <input type="hidden" id="resetUserId" name="id">

            <div class="modal-body">
                <div id="resetAlert" style="display: none;"></div>

                <div
                    style="background: var(--bg-body); border-radius: var(--radius-md); padding: 12px 14px; margin-bottom: 16px;">
                    <div
                        style="font-size: 12px; color: var(--text-muted); text-transform: uppercase; font-weight: 600;">
                        Resetting Password For:</div>
                    <div style="font-size: 14.5px; font-weight: 600; color: var(--text-main); margin-top: 2px;"
                        id="resetTargetUserDisplay">
                        username
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="resetPassword">New Password <span
                            style="color: var(--color-danger);">*</span></label>
                    <input type="password" class="form-input" id="resetPassword" name="password"
                        placeholder="Minimum 8 characters" required minlength="8">
                </div>

                <div class="form-group">
                    <label class="form-label" for="resetConfirmPassword">Confirm New Password <span
                            style="color: var(--color-danger);">*</span></label>
                    <input type="password" class="form-input" id="resetConfirmPassword" name="confirm_password"
                        placeholder="Re-type new password" required minlength="8">
                </div>

                <div class="security-notice-box">
                    ℹ️ <strong>Account Unlock:</strong> Setting a new password will immediately clear any temporary
                    lockouts and reset failed login attempt counters to zero.
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline" id="cancelResetBtn">Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveResetBtn">Update Password</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- Client-Side JavaScript Logic                                             -->
<!-- ========================================================================= -->
<script>
    document.addEventListener('DOMContentLoaded', () => {
        // Current active session user ID from PHP
        const CURRENT_USER_ID = <?= $currentUserId ?>;

        // Cache of fetched users
        let cachedUsers = [];

        // Table elements
        const usersTableBody = document.getElementById('usersTableBody');
        const searchFilter = document.getElementById('searchFilter');
        const roleFilter = document.getElementById('roleFilter');
        const statusFilter = document.getElementById('statusFilter');
        const clearFiltersBtn = document.getElementById('clearFiltersBtn');
        const refreshUsersBtn = document.getElementById('refreshUsersBtn');

        // KPI elements
        const kpiTotalUsers = document.getElementById('kpiTotalUsers');
        const kpiActiveUsers = document.getElementById('kpiActiveUsers');
        const kpiSuspendedUsers = document.getElementById('kpiSuspendedUsers');

        // Create Modal elements
        const createUserModal = document.getElementById('createUserModal');
        const openCreateUserBtn = document.getElementById('openCreateUserBtn');
        const closeCreateModalBtn = document.getElementById('closeCreateModalBtn');
        const cancelCreateBtn = document.getElementById('cancelCreateBtn');
        const createUserForm = document.getElementById('createUserForm');
        const saveCreateBtn = document.getElementById('saveCreateBtn');
        const createAlert = document.getElementById('createAlert');

        // Edit Modal elements
        const editUserModal = document.getElementById('editUserModal');
        const closeEditModalBtn = document.getElementById('closeEditModalBtn');
        const cancelEditBtn = document.getElementById('cancelEditBtn');
        const editUserForm = document.getElementById('editUserForm');
        const saveEditBtn = document.getElementById('saveEditBtn');
        const editAlert = document.getElementById('editAlert');
        const editUserId = document.getElementById('editUserId');
        const editUsernameDisplay = document.getElementById('editUsernameDisplay');
        const editFullName = document.getElementById('editFullName');
        const editEmail = document.getElementById('editEmail');
        const editRoleId = document.getElementById('editRoleId');
        const editIsActive = document.getElementById('editIsActive');
        const selfRoleNotice = document.getElementById('selfRoleNotice');
        const selfStatusNotice = document.getElementById('selfStatusNotice');

        // Reset Password Modal elements
        const resetPasswordModal = document.getElementById('resetPasswordModal');
        const closeResetModalBtn = document.getElementById('closeResetModalBtn');
        const cancelResetBtn = document.getElementById('cancelResetBtn');
        const resetPasswordForm = document.getElementById('resetPasswordForm');
        const saveResetBtn = document.getElementById('saveResetBtn');
        const resetAlert = document.getElementById('resetAlert');
        const resetUserId = document.getElementById('resetUserId');
        const resetTargetUserDisplay = document.getElementById('resetTargetUserDisplay');

        // Helper: HTML Escaping
        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // Helper: Modal Open / Close
        function openModal(modal) {
            if (!modal) return;
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modal) {
            if (!modal) return;
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }

        // Helper: Show Alert inside modal
        function showAlert(container, message, type = 'danger') {
            if (!container) return;
            container.className = `alert alert-${type}`;
            container.innerHTML = `<span>${type === 'danger' ? '⚠️' : '✅'}</span> <div>${escapeHtml(message)}</div>`;
            container.style.display = 'flex';
        }

        function hideAlert(container) {
            if (!container) return;
            container.style.display = 'none';
            container.innerHTML = '';
        }

        // Role badge selector
        function getRoleBadge(roleName, roleLabel) {
            const name = (roleName || '').toLowerCase();
            let badgeClass = 'badge-confirmed';
            if (name === 'admin') {
                badgeClass = 'badge-confirmed'; // Royal blue
            } else if (name === 'manager') {
                badgeClass = 'badge-info';      // Sky blue
            } else if (name === 'cashier') {
                badgeClass = 'badge-pending';   // Amber
            }
            return `<span class="badge ${badgeClass}">${escapeHtml(roleLabel || roleName || 'Unknown')}</span>`;
        }

        // Fetch Users from Backend
        async function loadUsers() {
            usersTableBody.innerHTML = `
            <tr>
                <td colspan="8" style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
                    Loading system users...
                </td>
            </tr>`;

            const res = await API.get('handler.php?action=list_users');

            if (!res || !res.success || !res.data) {
                usersTableBody.innerHTML = `
                <tr>
                    <td colspan="8" style="text-align: center; padding: 40px 20px; color: var(--color-danger);">
                        ${escapeHtml(res?.message || 'Failed to load users. Please check your connection.')}
                    </td>
                </tr>`;
                return;
            }

            cachedUsers = Array.isArray(res.data.users) ? res.data.users : [];
            updateKpis(cachedUsers);
            renderTable();
        }

        // Update KPI Card Numbers
        function updateKpis(users) {
            const total = users.length;
            const active = users.filter(u => parseInt(u.is_active, 10) === 1).length;
            const suspended = total - active;

            if (kpiTotalUsers) kpiTotalUsers.textContent = total;
            if (kpiActiveUsers) kpiActiveUsers.textContent = active;
            if (kpiSuspendedUsers) kpiSuspendedUsers.textContent = suspended;
        }

        // Filter & Render Table Rows
        function renderTable() {
            const query = searchFilter.value.trim().toLowerCase();
            const selectedRole = roleFilter.value;
            const selectedStatus = statusFilter.value;

            const filtered = cachedUsers.filter(u => {
                const matchesQuery = !query ||
                    (u.username && u.username.toLowerCase().includes(query)) ||
                    (u.full_name && u.full_name.toLowerCase().includes(query)) ||
                    (u.email && u.email.toLowerCase().includes(query));

                const matchesRole = !selectedRole || String(u.role_id) === String(selectedRole);
                const matchesStatus = selectedStatus === '' || String(u.is_active) === String(selectedStatus);

                return matchesQuery && matchesRole && matchesStatus;
            });

            if (filtered.length === 0) {
                usersTableBody.innerHTML = `
                <tr>
                    <td colspan="8" style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
                        No user accounts match the current filter criteria.
                    </td>
                </tr>`;
                return;
            }

            usersTableBody.innerHTML = filtered.map(u => {
                const uid = parseInt(u.id, 10);
                const isSelf = uid === CURRENT_USER_ID;
                const isActive = parseInt(u.is_active, 10) === 1;
                const initial = (u.full_name || u.username || '?').charAt(0).toUpperCase();

                // Status Badge
                const statusBadge = isActive
                    ? `<span class="badge badge-delivered">Active</span>`
                    : `<span class="badge badge-cancelled">Suspended</span>`;

                // Lockout / Security Status
                let securityCell = `<span style="color: var(--text-muted); font-size: 12.5px;">Normal</span>`;
                if (u.locked_until && new Date(u.locked_until) > new Date()) {
                    securityCell = `<span class="badge badge-danger" title="Locked until ${escapeHtml(u.locked_until)}">🔒 Locked</span>`;
                } else if (parseInt(u.failed_logins, 10) > 0) {
                    securityCell = `<span class="badge badge-warning" title="${parseInt(u.failed_logins, 10)} failed login attempts">${parseInt(u.failed_logins, 10)} Failed</span>`;
                }

                // Last Login formatted
                const lastLoginText = u.last_login_at
                    ? `<span class="tabular-nums" style="font-size: 12.5px;">${escapeHtml(u.last_login_at)}</span>`
                    : `<span style="color: var(--text-muted); font-size: 12px;">Never</span>`;

                // Created Date
                const createdText = u.created_at
                    ? `<span class="tabular-nums" style="font-size: 12.5px; color: var(--text-muted);">${escapeHtml(u.created_at.substring(0, 10))}</span>`
                    : '—';

                return `
                <tr>
                    <td class="tabular-nums" style="font-weight: 600; color: var(--text-muted);">${uid}</td>
                    <td>
                        <div class="user-meta-cell">
                            <div class="user-avatar-badge">${escapeHtml(initial)}</div>
                            <div class="user-meta-info">
                                <span class="user-display-name">
                                    ${escapeHtml(u.full_name)}
                                    ${isSelf ? '<span class="badge-self">You</span>' : ''}
                                </span>
                                <span class="user-username-label">@${escapeHtml(u.username)}</span>
                                ${u.email ? `<span style="font-size: 11.5px; color: var(--text-muted); display: block; margin-top: 2px;">✉️ ${escapeHtml(u.email)}</span>` : '<span style="font-size: 11px; color: #d97706; display: block; margin-top: 2px;">⚠️ No email configured</span>'}
                            </div>
                        </div>
                    </td>
                    <td>${getRoleBadge(u.role_name, u.role_label)}</td>
                    <td>${statusBadge}</td>
                    <td>${securityCell}</td>
                    <td>${lastLoginText}</td>
                    <td>${createdText}</td>
                    <td class="text-right">
                        <div class="action-buttons-group">
                            <button type="button" class="btn btn-outline btn-sm edit-user-btn" data-id="${uid}">
                                ✏️ Edit
                            </button>
                            <button type="button" class="btn btn-outline btn-sm reset-pwd-btn" data-id="${uid}">
                                🔑 Reset
                            </button>
                        </div>
                    </td>
                </tr>
            `;
            }).join('');
        }

        // Attach Table Action Event Listeners
        usersTableBody.addEventListener('click', (e) => {
            const editBtn = e.target.closest('.edit-user-btn');
            if (editBtn) {
                const uid = parseInt(editBtn.dataset.id, 10);
                openEditUser(uid);
                return;
            }

            const resetBtn = e.target.closest('.reset-pwd-btn');
            if (resetBtn) {
                const uid = parseInt(resetBtn.dataset.id, 10);
                openResetPassword(uid);
                return;
            }
        });

        // -------------------------------------------------------------------------
        // Create User Workflow
        // -------------------------------------------------------------------------
        openCreateUserBtn.addEventListener('click', () => {
            createUserForm.reset();
            hideAlert(createAlert);
            openModal(createUserModal);
        });

        closeCreateModalBtn.addEventListener('click', () => closeModal(createUserModal));
        cancelCreateBtn.addEventListener('click', () => closeModal(createUserModal));

        createUserForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            hideAlert(createAlert);

            const username = document.getElementById('newUsername').value.trim();
            const fullName = document.getElementById('newFullName').value.trim();
            const email = document.getElementById('newEmail').value.trim();
            const roleId = document.getElementById('newRoleId').value;
            const password = document.getElementById('newPassword').value;
            const confirmPassword = document.getElementById('newConfirmPassword').value;

            if (password !== confirmPassword) {
                showAlert(createAlert, 'Passwords do not match. Please verify and re-enter.');
                return;
            }

            if (password.length < 8) {
                showAlert(createAlert, 'Password must be at least 8 characters long.');
                return;
            }

            saveCreateBtn.disabled = true;
            saveCreateBtn.textContent = 'Creating...';

            const payload = {
                csrf_token: API.getCSRFToken(),
                username,
                full_name: fullName,
                email,
                role_id: parseInt(roleId, 10),
                password,
                confirm_password: confirmPassword
            };

            const res = await API.post('handler.php?action=create_user', payload);
            saveCreateBtn.disabled = false;
            saveCreateBtn.textContent = 'Create Account';

            if (res && res.success) {
                API.toast(res.message || 'User account created successfully!', 'success');
                closeModal(createUserModal);
                loadUsers();
            } else {
                const msg = res?.errors ? Object.values(res.errors).join(' ') : (res?.message || 'Failed to create user account.');
                showAlert(createAlert, msg);
            }
        });

        // -------------------------------------------------------------------------
        // Edit User Workflow & Self-Lockout Guards
        // -------------------------------------------------------------------------
        function openEditUser(userId) {
            const user = cachedUsers.find(u => parseInt(u.id, 10) === userId);
            if (!user) {
                API.toast('Selected user could not be found.', 'error');
                return;
            }

            hideAlert(editAlert);
            editUserId.value = user.id;
            editUsernameDisplay.value = user.username;
            editFullName.value = user.full_name;
            editEmail.value = user.email || '';
            editRoleId.value = user.role_id;
            editIsActive.value = String(user.is_active);

            // Active Session Guard: Disable self-deactivation & self-demotion
            const isSelf = (parseInt(user.id, 10) === CURRENT_USER_ID);
            if (isSelf) {
                editIsActive.disabled = true;
                editRoleId.disabled = true;
                selfStatusNotice.style.display = 'block';
                selfRoleNotice.style.display = 'block';
            } else {
                editIsActive.disabled = false;
                editRoleId.disabled = false;
                selfStatusNotice.style.display = 'none';
                selfRoleNotice.style.display = 'none';
            }

            openModal(editUserModal);
        }

        closeEditModalBtn.addEventListener('click', () => closeModal(editUserModal));
        cancelEditBtn.addEventListener('click', () => closeModal(editUserModal));

        editUserForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            hideAlert(editAlert);

            const uid = parseInt(editUserId.value, 10);
            const fullName = editFullName.value.trim();
            const email = editEmail.value.trim();
            const isSelf = (uid === CURRENT_USER_ID);

            // If self, keep existing role and active status
            const roleId = isSelf
                ? parseInt(cachedUsers.find(u => parseInt(u.id, 10) === uid)?.role_id, 10)
                : parseInt(editRoleId.value, 10);

            const isActive = isSelf
                ? 1
                : parseInt(editIsActive.value, 10);

            saveEditBtn.disabled = true;
            saveEditBtn.textContent = 'Saving...';

            const payload = {
                csrf_token: API.getCSRFToken(),
                id: uid,
                full_name: fullName,
                email: email,
                role_id: roleId,
                is_active: isActive
            };

            const res = await API.post('handler.php?action=update_user', payload);
            saveEditBtn.disabled = false;
            saveEditBtn.textContent = 'Save Changes';

            if (res && res.success) {
                API.toast(res.message || 'User account updated successfully!', 'success');
                closeModal(editUserModal);
                loadUsers();
            } else {
                const msg = res?.errors ? Object.values(res.errors).join(' ') : (res?.message || 'Failed to update user account.');
                showAlert(editAlert, msg);
            }
        });

        // -------------------------------------------------------------------------
        // Reset Password Workflow
        // -------------------------------------------------------------------------
        function openResetPassword(userId) {
            const user = cachedUsers.find(u => parseInt(u.id, 10) === userId);
            if (!user) {
                API.toast('Selected user could not be found.', 'error');
                return;
            }

            resetPasswordForm.reset();
            hideAlert(resetAlert);
            resetUserId.value = user.id;
            resetTargetUserDisplay.textContent = `${user.full_name} (@${user.username})`;

            openModal(resetPasswordModal);
        }

        closeResetModalBtn.addEventListener('click', () => closeModal(resetPasswordModal));
        cancelResetBtn.addEventListener('click', () => closeModal(resetPasswordModal));

        resetPasswordForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            hideAlert(resetAlert);

            const uid = parseInt(resetUserId.value, 10);
            const password = document.getElementById('resetPassword').value;
            const confirmPassword = document.getElementById('resetConfirmPassword').value;

            if (password !== confirmPassword) {
                showAlert(resetAlert, 'Passwords do not match. Please verify and re-enter.');
                return;
            }

            if (password.length < 8) {
                showAlert(resetAlert, 'Password must be at least 8 characters long.');
                return;
            }

            saveResetBtn.disabled = true;
            saveResetBtn.textContent = 'Updating...';

            const payload = {
                csrf_token: API.getCSRFToken(),
                id: uid,
                password,
                confirm_password: confirmPassword
            };

            const res = await API.post('handler.php?action=reset_password', payload);
            saveResetBtn.disabled = false;
            saveResetBtn.textContent = 'Update Password';

            if (res && res.success) {
                API.toast(res.message || 'Password reset successfully! Account unlocked.', 'success');
                closeModal(resetPasswordModal);
                loadUsers();
            } else {
                const msg = res?.errors ? Object.values(res.errors).join(' ') : (res?.message || 'Failed to reset password.');
                showAlert(resetAlert, msg);
            }
        });

        // -------------------------------------------------------------------------
        // Filters & Refresh
        // -------------------------------------------------------------------------
        searchFilter.addEventListener('input', () => renderTable());
        roleFilter.addEventListener('change', () => renderTable());
        statusFilter.addEventListener('change', () => renderTable());

        clearFiltersBtn.addEventListener('click', () => {
            searchFilter.value = '';
            roleFilter.value = '';
            statusFilter.value = '';
            renderTable();
        });

        refreshUsersBtn.addEventListener('click', () => {
            loadUsers();
            API.toast('Refreshed user directory.', 'info');
        });

        // Close modals on Escape key press
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeModal(createUserModal);
                closeModal(editUserModal);
                closeModal(resetPasswordModal);
            }
        });

        // Close modals on backdrop click (only if mousedown started on backdrop)
        let userMouseDownTarget = null;
        document.addEventListener('mousedown', (e) => {
            userMouseDownTarget = e.target;
        });
        [createUserModal, editUserModal, resetPasswordModal].forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal && userMouseDownTarget === modal) {
                    closeModal(modal);
                }
            });
        });

        // Initial Load
        loadUsers();
    });
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>