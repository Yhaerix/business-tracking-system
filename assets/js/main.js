/**
 * Main Application Global UI Controller
 * Small Business Sales and Expense Tracking System
 * Modern Design System (assets/js/main.js)
 */

(function () {
    'use strict';

    // -------------------------------------------------------------
    // Mobile Sidebar Drawer
    // -------------------------------------------------------------
    window.toggleSidebar = function () {
        const sidebar = document.getElementById('appSidebar');
        const backdrop = document.getElementById('sidebarBackdrop');
        if (sidebar) sidebar.classList.toggle('open');
        if (backdrop) {
            backdrop.classList.toggle('open');
            backdrop.classList.toggle('active');
        }
    };

    window.closeSidebar = function () {
        const sidebar = document.getElementById('appSidebar');
        const backdrop = document.getElementById('sidebarBackdrop');
        if (sidebar) sidebar.classList.remove('open');
        if (backdrop) {
            backdrop.classList.remove('open');
            backdrop.classList.remove('active');
        }
    };

    // -------------------------------------------------------------
    // Desktop Sidebar Collapsible State
    // -------------------------------------------------------------
    window.toggleSidebarCollapse = function () {
        const sidebar = document.getElementById('appSidebar');
        const isCollapsed = sidebar && sidebar.classList.toggle('collapsed');
        document.body.classList.toggle('sidebar-collapsed', !!isCollapsed);
        try {
            localStorage.setItem('bts_sidebar_collapsed', isCollapsed ? '1' : '0');
        } catch (e) { }
    };

    // Restore saved sidebar collapsed preference
    try {
        if (localStorage.getItem('bts_sidebar_collapsed') === '1') {
            const sidebar = document.getElementById('appSidebar');
            if (sidebar) sidebar.classList.add('collapsed');
            document.body.classList.add('sidebar-collapsed');
        }
    } catch (e) { }

    // -------------------------------------------------------------
    // Topbar Dropdowns (Notifications & Profile Menu)
    // -------------------------------------------------------------
    window.toggleNotifications = function (e) {
        if (e && e.stopPropagation) e.stopPropagation();
        const panel = document.getElementById('notifPanel');
        const profileMenu = document.getElementById('profileMenu');
        if (profileMenu) profileMenu.classList.remove('open');
        if (panel) panel.classList.toggle('open');
    };

    window.toggleProfileMenu = function (e) {
        if (e && e.stopPropagation) e.stopPropagation();
        const menu = document.getElementById('profileMenu');
        const notifPanel = document.getElementById('notifPanel');
        if (notifPanel) notifPanel.classList.remove('open');
        if (menu) menu.classList.toggle('open');
    };

    // -------------------------------------------------------------
    // Modal Manager (supports element ID string or HTMLElement)
    // -------------------------------------------------------------
    window.openModal = function (modalTarget) {
        const modal = typeof modalTarget === 'string' ? document.getElementById(modalTarget) : modalTarget;
        if (modal) {
            modal.classList.add('active');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('modal-open');
        }
    };

    window.closeModal = function (modalTarget) {
        const modal = typeof modalTarget === 'string' ? document.getElementById(modalTarget) : modalTarget;
        if (modal) {
            if (modal.id === 'chatDrawer' && typeof window.closeChat === 'function') {
                window.closeChat();
                return;
            }
            modal.classList.remove('active');
            modal.setAttribute('aria-hidden', 'true');
        }
        if (!document.querySelector('.modal.active, .modal-overlay.active')) {
            document.body.classList.remove('modal-open');
        }
    };

    // -------------------------------------------------------------
    // Toast Notification Utility
    // -------------------------------------------------------------
    window.showToast = function (message, type = 'info') {
        let container = document.getElementById('toastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toastContainer';
            container.className = 'toast-container';
            document.body.appendChild(container);
        }

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;

        container.appendChild(toast);
        setTimeout(() => toast.classList.add('show'), 10);

        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3500);
    };

    // -------------------------------------------------------------
    // Sales Trend Dual-Series Chart Generator (ApexCharts)
    // -------------------------------------------------------------
    window.renderSalesTrend = function (customData, customLabels) {
        const el = document.getElementById('salesTrendChart');
        if (!el) return;
        if (el._apexInstance && !customData && !customLabels) return;

        if (window.BTSCharts && window.BTSCharts.renderDashboardDualChart) {
            window.BTSCharts.renderDashboardDualChart('salesTrendChart', {
                sales: customData,
                labels: customLabels
            });
            return;
        }
    };

    // Auto-render sales chart if element exists
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            if (document.getElementById('salesTrendChart')) window.renderSalesTrend();
        });
    } else {
        if (document.getElementById('salesTrendChart')) window.renderSalesTrend();
    }

    // -------------------------------------------------------------
    // Global Event Listeners (Escape key & Click Outside)
    // -------------------------------------------------------------
    let modalMouseDownTarget = null;

    document.addEventListener('mousedown', function (e) {
        modalMouseDownTarget = e.target;
    });

    document.addEventListener('click', function (e) {
        // Close topbar menus when clicking outside
        const notifWrap = document.getElementById('notifWrap');
        const notifPanel = document.getElementById('notifPanel');
        if (notifPanel && notifPanel.classList.contains('open')) {
            if (notifWrap && !notifWrap.contains(e.target)) {
                notifPanel.classList.remove('open');
            }
        }

        const profileWrap = document.getElementById('profileWrap');
        const profileMenu = document.getElementById('profileMenu');
        if (profileMenu && profileMenu.classList.contains('open')) {
            if (profileWrap && !profileWrap.contains(e.target)) {
                profileMenu.classList.remove('open');
            }
        }

        // Close modal when clicking directly on the backdrop ONLY IF the mousedown also started on the backdrop.
        // This prevents modals from closing when users click and drag inside an input to highlight/select text.
        if (e.target.classList && (e.target.classList.contains('modal') || e.target.classList.contains('modal-overlay')) && e.target.classList.contains('active')) {
            if (modalMouseDownTarget === e.target) {
                window.closeModal(e.target.id);
            }
        }
        modalMouseDownTarget = null;
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            // Close active modals
            const activeModal = document.querySelector('.modal.active, .modal-overlay.active');
            if (activeModal) {
                window.closeModal(activeModal.id);
            }
            // Close open dropdowns
            const notifPanel = document.getElementById('notifPanel');
            if (notifPanel) notifPanel.classList.remove('open');
            const profileMenu = document.getElementById('profileMenu');
            if (profileMenu) profileMenu.classList.remove('open');
            // Close mobile sidebar
            window.closeSidebar();
        }
    });

})();
