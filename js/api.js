// ============================================================
//  js/api.js  —  Shared API helper for all POS pages
//  Include this on every page:  <script src="js/api.js"></script>
// ============================================================

const API_BASE = 'api'; // relative path — works on any server

const api = {
    // Generic fetch wrapper
    async request(endpoint, action, method = 'GET', body = null) {
        const url = `${API_BASE}/${endpoint}.php?action=${action}`;
        const opts = {
            method,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
        };
        if (body) opts.body = JSON.stringify(body);
        const res = await fetch(url, opts);
        const data = await res.json();
        return data;
    },

    // ── AUTH ──────────────────────────────────────────────────
    auth: {
        login: (email, password, role) =>
            api.request('auth', 'login', 'POST', { email, password, role }),
        register: (payload) =>
            api.request('auth', 'register', 'POST', payload),
        logout: () => api.request('auth', 'logout', 'POST'),
        me: () => api.request('auth', 'me'),
    },

    // ── PRODUCTS ─────────────────────────────────────────────
    products: {
        list: (params = {}) => {
            const qs = new URLSearchParams({ action: 'list', ...params }).toString();
            return fetch(`${API_BASE}/products.php?${qs}`, { credentials: 'same-origin' }).then(r => r.json());
        },
        create: (formData) => {
            // formData can be FormData (with image) or plain object
            if (formData instanceof FormData) {
                return fetch(`${API_BASE}/products.php?action=create`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData,
                }).then(r => r.json());
            }
            return api.request('products', 'create', 'POST', formData);
        },
        update: (id, data) => {
            // Accept FormData (with image) or plain object
            if (data instanceof FormData) {
                return fetch(`${API_BASE}/products.php?action=update&id=${id}`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: data, // browser sets multipart header automatically
                }).then(r => r.json());
            }
            return fetch(`${API_BASE}/products.php?action=update&id=${id}`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
            }).then(r => r.json());
        },
        delete: (id) =>
            fetch(`${API_BASE}/products.php?action=delete&id=${id}`, {
                method: 'POST',
                credentials: 'same-origin',
            }).then(r => r.json()),
        adjustStock: (id, delta) =>
            fetch(`${API_BASE}/products.php?action=adjust_stock&id=${id}`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ delta }),
            }).then(r => r.json()),
    },

    // ── ORDERS ───────────────────────────────────────────────
    orders: {
        place: (payload) => api.request('orders', 'place', 'POST', payload),
        list: (params = {}) => {
            const qs = new URLSearchParams({ action: 'list', ...params }).toString();
            return fetch(`${API_BASE}/orders.php?${qs}`, { credentials: 'same-origin' }).then(r => r.json());
        },
        get: (id) =>
            fetch(`${API_BASE}/orders.php?action=get&id=${id}`, { credentials: 'same-origin' }).then(r => r.json()),
        void: (id) =>
            fetch(`${API_BASE}/orders.php?action=void&id=${id}`, {
                method: 'POST',
                credentials: 'same-origin',
            }).then(r => r.json()),
    },

    // ── DASHBOARD ─────────────────────────────────────────────
    dashboard: {
        kpis: (branchId = '') =>
            fetch(`${API_BASE}/dashboard.php?action=kpis${branchId ? '&branch_id=' + branchId : ''}`, { credentials: 'same-origin' }).then(r => r.json()),
        revenueTrend: (branchId = '') =>
            fetch(`${API_BASE}/dashboard.php?action=revenue_trend${branchId ? '&branch_id=' + branchId : ''}`, { credentials: 'same-origin' }).then(r => r.json()),
        orderSources: (branchId = '') =>
            fetch(`${API_BASE}/dashboard.php?action=order_sources${branchId ? '&branch_id=' + branchId : ''}`, { credentials: 'same-origin' }).then(r => r.json()),
        topProducts: (branchId = '') =>
            fetch(`${API_BASE}/dashboard.php?action=top_products${branchId ? '&branch_id=' + branchId : ''}`, { credentials: 'same-origin' }).then(r => r.json()),
    },

    // ── SALES REPORT ─────────────────────────────────────────
    salesReport: {
        get: (params = {}) => {
            const qs = new URLSearchParams(params).toString();
            return fetch(`${API_BASE}/sales_report.php?${qs}`, { credentials: 'same-origin' }).then(r => r.json());
        },
    },

    // ── CUSTOMERS ────────────────────────────────────────────
    customers: {
        list: (search = '') =>
            fetch(`${API_BASE}/customers.php?action=list${search ? '&search=' + encodeURIComponent(search) : ''}`, { credentials: 'same-origin' }).then(r => r.json()),
        get: (id) =>
            fetch(`${API_BASE}/customers.php?action=get&id=${id}`, { credentials: 'same-origin' }).then(r => r.json()),
        create: (data) => api.request('customers', 'create', 'POST', data),
        update: (id, data) =>
            fetch(`${API_BASE}/customers.php?action=update&id=${id}`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
            }).then(r => r.json()),
        delete: (id) =>
            fetch(`${API_BASE}/customers.php?action=delete&id=${id}`, {
                method: 'POST',
                credentials: 'same-origin',
            }).then(r => r.json()),
    },
};

// ── Auth Guard ────────────────────────────────────────────────
// Call this at the top of any protected page
async function requireLogin(redirectToAdmin = false) {
    const res = await api.auth.me().catch(() => null);
    if (!res || !res.success) {
        window.location.href = 'login.html';
        return null;
    }
    // Optionally update header greeting
    const el = document.getElementById('userGreeting');
    if (el) el.textContent = res.user.name;
    return res.user;
}

// Convenience: format Philippine Peso
function fmt(n) {
    return '₱' + parseFloat(n || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}