/**
 * Centralized API & Fetch Wrapper
 * Small Business Sales and Expense Tracking System
 * Handles CSRF token injection, error handling, and toast notifications.
 */

const API = {
    /**
     * Retrieve CSRF token from document meta tag
     */
    getCSRFToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    },

    /**
     * Centralized request executor
     */
    async request(url, options = {}) {
        const defaultHeaders = {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': this.getCSRFToken()
        };

        // If body is not FormData, set JSON content type
        if (options.body && !(options.body instanceof FormData)) {
            defaultHeaders['Content-Type'] = 'application/json';
            if (typeof options.body === 'object') {
                options.body = JSON.stringify(options.body);
            }
        }

        options.headers = { ...defaultHeaders, ...(options.headers || {}) };

        try {
            const response = await fetch(url, options);
            const data = await response.json().catch(() => null);

            // Handle unauthenticated session (401)
            if (response.status === 401) {
                // If on login page or attempting login, return the server's error response directly
                if (window.location.pathname.includes('/auth/login.php') || url.includes('handler.php')) {
                    return data || { success: false, message: 'Invalid username or password' };
                }

                // If on an authenticated page and session expired, notify and redirect
                this.toast('Session expired. Redirecting to login...', 'error');
                setTimeout(() => {
                    window.location.href = '/Business%20Tracking%20System/modules/auth/login.php';
                }, 1500);
                return { success: false, message: 'Unauthorized' };
            }

            // Handle forbidden RBAC (403)
            if (response.status === 403) {
                const message = (data && data.message) ? data.message : 'Access denied: You do not have permission for this action.';
                this.toast(message, 'error');
                return data || { success: false, message: message };
            }

            return data || { success: false, message: 'Invalid server response' };
        } catch (error) {
            console.error('[API Fetch Error]', error);
            this.toast('A network error occurred. Please try again.', 'error');
            return { success: false, message: error.message };
        }
    },

    get(url) {
        return this.request(url, { method: 'GET' });
    },

    post(url, body) {
        return this.request(url, { method: 'POST', body });
    },

    /**
     * Lightweight Toast Notification Helper
     */
    toast(message, type = 'info') {
        let container = document.getElementById('toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toast-container';
            container.style.cssText = `
                position: fixed;
                bottom: 24px;
                right: 24px;
                z-index: 9999;
                display: flex;
                flex-direction: column;
                gap: 10px;
            `;
            document.body.appendChild(container);
        }

        const toast = document.createElement('div');
        toast.className = `alert alert-${type === 'error' ? 'danger' : type}`;
        toast.style.cssText = `
            min-width: 280px;
            max-width: 400px;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
            animation: slideIn 0.2s ease forwards;
        `;
        toast.innerHTML = `<span>${message}</span>`;

        container.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, 3500);
    }
};

// Global helper alias
window.API = API;

