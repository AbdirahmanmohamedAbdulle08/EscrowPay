/* ================================================================
   ESCROW PAY — MAIN JAVASCRIPT
   ================================================================ */

'use strict';

// ── Globals ────────────────────────────────────────────────────
const APP_URL = document.documentElement.dataset.appUrl || '';

// ── DOM Ready ──────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    initSidebar();
    initDropdowns();
    initNotifications();
    initToasts();
    initTableSearch();
    initModalManager();
    initPasswordToggle();
    initDemoAccounts();
    initCharts();
    animateCounters();
    initFormValidation();
    initChatUI();
});

// ─────────────────────────────────────────────────────────────
// SIDEBAR
// ─────────────────────────────────────────────────────────────
function initSidebar() {
    const toggle  = document.getElementById('sidebarToggle');
    const overlay = document.getElementById('overlay');
    if (!toggle) return;

    toggle.addEventListener('click', () => {
        if (window.innerWidth <= 1024) {
            document.body.classList.toggle('sidebar-open');
        } else {
            document.body.classList.toggle('sidebar-collapsed');
        }
    });

    overlay?.addEventListener('click', () => {
        document.body.classList.remove('sidebar-open');
    });

    window.addEventListener('resize', () => {
        if (window.innerWidth > 1024) {
            document.body.classList.remove('sidebar-open');
        }
    });
}

// ─────────────────────────────────────────────────────────────
// DROPDOWNS (notifications + user menu)
// ─────────────────────────────────────────────────────────────
function initDropdowns() {
    // Notification dropdown
    const notifBtn    = document.getElementById('notifToggle');
    const notifDrop   = document.getElementById('notifDropdown');

    // User dropdown
    const userBtn     = document.getElementById('userMenuToggle');
    const userDrop    = document.getElementById('userDropdown');

    function closeAll() {
        notifDrop?.classList.remove('open');
        userDrop?.classList.remove('open');
    }

    notifBtn?.addEventListener('click', (e) => {
        e.stopPropagation();
        const isOpen = notifDrop.classList.toggle('open');
        userDrop?.classList.remove('open');
        if (isOpen) loadNotifications();
    });

    userBtn?.addEventListener('click', (e) => {
        e.stopPropagation();
        userDrop.classList.toggle('open');
        notifDrop?.classList.remove('open');
    });

    document.addEventListener('click', closeAll);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeAll(); });
}

// ─────────────────────────────────────────────────────────────
// NOTIFICATIONS — fetch from server
// ─────────────────────────────────────────────────────────────
function initNotifications() {}

async function loadNotifications() {
    const list = document.getElementById('notifList');
    if (!list) return;

    list.innerHTML = '<div class="notif-loading"><i class="ri-loader-4-line spin"></i> Loading…</div>';

    try {
        const res  = await fetch(APP_URL + '/api/notifications.php?limit=6');
        const data = await res.json();

        if (!data.success || data.items.length === 0) {
            list.innerHTML = `<div class="empty-state" style="padding:30px 16px">
                <i class="ri-notification-off-line" style="font-size:36px"></i>
                <p style="margin-top:8px">No notifications</p>
            </div>`;
            return;
        }

        const iconMap = { success: 'ri-checkbox-circle-line', danger: 'ri-error-warning-line', warning: 'ri-alert-line', info: 'ri-information-line' };
        const bgMap   = { success: 'badge-success', danger: 'badge-danger', warning: 'badge-warning', info: 'badge-info' };

        list.innerHTML = data.items.map(n => `
            <div class="notif-item ${n.is_read ? '' : 'unread'}" onclick="markNotifRead(${n.id})">
                <div class="notif-item-icon badge ${bgMap[n.type] || 'badge-info'}">
                    <i class="${iconMap[n.type] || 'ri-information-line'}"></i>
                </div>
                <div class="notif-item-body">
                    <div class="notif-item-title">${escHtml(n.title)}</div>
                    <div class="notif-item-msg">${escHtml(n.message)}</div>
                    <div class="notif-item-time">${escHtml(n.time_ago)}</div>
                </div>
            </div>
        `).join('');
    } catch(e) {
        list.innerHTML = '<div class="notif-loading" style="color:var(--danger)"><i class="ri-wifi-off-line"></i> Failed to load</div>';
    }
}

async function markNotifRead(id) {
    await fetch(APP_URL + '/api/notifications.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'mark_read', id })
    });
    loadNotifications();
    // Update badge
    const dot = document.querySelector('.topbar-icon-btn .badge-dot.badge-danger');
    if (dot) { let c = parseInt(dot.textContent) - 1; if (c <= 0) dot.remove(); else dot.textContent = c; }
}

// ─────────────────────────────────────────────────────────────
// TOAST
// ─────────────────────────────────────────────────────────────
function initToasts() {
    window.showToast = (message, type = 'info', duration = 4000) => {
        let container = document.querySelector('.toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container';
            document.body.appendChild(container);
        }

        const iconMap = { success: 'ri-checkbox-circle-fill', danger: 'ri-error-warning-fill', warning: 'ri-alert-fill', info: 'ri-information-fill' };
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `<i class="${iconMap[type] || iconMap.info}"></i><span>${escHtml(message)}</span>`;
        container.appendChild(toast);
        setTimeout(() => { toast.style.opacity = '0'; toast.style.transform = 'translateX(24px)'; toast.style.transition = 'all .3s'; setTimeout(() => toast.remove(), 350); }, duration);
    };

    // Show flash messages from PHP via data attributes
    const flash = document.getElementById('flashMessage');
    if (flash) { showToast(flash.dataset.message, flash.dataset.type || 'info'); flash.remove(); }
}

// ─────────────────────────────────────────────────────────────
// TABLE SEARCH / FILTER
// ─────────────────────────────────────────────────────────────
function initTableSearch() {
    document.querySelectorAll('[data-search-table]').forEach(input => {
        const tableId = input.dataset.searchTable;
        const table   = document.getElementById(tableId);
        if (!table) return;
        input.addEventListener('input', () => {
            const q = input.value.toLowerCase();
            table.querySelectorAll('tbody tr').forEach(row => {
                row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    });

    // Status filter
    document.querySelectorAll('[data-filter-table]').forEach(select => {
        const tableId = select.dataset.filterTable;
        const col     = parseInt(select.dataset.filterCol || 0);
        const table   = document.getElementById(tableId);
        if (!table) return;
        select.addEventListener('change', () => {
            const val = select.value;
            table.querySelectorAll('tbody tr').forEach(row => {
                const cell = row.cells[col];
                row.style.display = (!val || cell?.textContent.trim().toLowerCase() === val) ? '' : 'none';
            });
        });
    });
}

// ─────────────────────────────────────────────────────────────
// MODAL MANAGER
// ─────────────────────────────────────────────────────────────
function initModalManager() {
    // Open modal
    document.querySelectorAll('[data-modal-open]').forEach(btn => {
        btn.addEventListener('click', () => openModal(btn.dataset.modalOpen));
    });
    // Close buttons
    document.querySelectorAll('[data-modal-close]').forEach(btn => {
        btn.addEventListener('click', () => closeModal(btn.closest('.modal-overlay')?.id));
    });
    // Click outside
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', (e) => { if (e.target === overlay) closeModal(overlay.id); });
    });

    window.openModal  = (id) => document.getElementById(id)?.classList.add('open');
    window.closeModal = (id) => document.getElementById(id)?.classList.remove('open');
}

// ─────────────────────────────────────────────────────────────
// PASSWORD TOGGLE
// ─────────────────────────────────────────────────────────────
function initPasswordToggle() {
    document.querySelectorAll('.password-toggle').forEach(btn => {
        btn.addEventListener('click', () => {
            const input = btn.previousElementSibling || btn.parentElement.querySelector('input');
            if (!input) return;
            const isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';
            btn.className = `password-toggle ri-${isPassword ? 'eye-off' : 'eye'}-line`;
        });
    });
}

// ─────────────────────────────────────────────────────────────
// DEMO ACCOUNTS (login page autofill)
// ─────────────────────────────────────────────────────────────
function initDemoAccounts() {
    document.querySelectorAll('.demo-account').forEach(item => {
        item.addEventListener('click', () => {
            const email = item.querySelector('.demo-email')?.textContent?.trim();
            if (!email) return;
            const emailInput = document.getElementById('loginEmail');
            const passInput  = document.getElementById('loginPassword');
            if (emailInput) emailInput.value = email;
            if (passInput)  passInput.value  = 'password';
            showToast(`Filled: ${email}`, 'info', 2000);
        });
    });
}

// ─────────────────────────────────────────────────────────────
// CHARTS (Chart.js helpers)
// ─────────────────────────────────────────────────────────────
function initCharts() {
    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.color       = '#617088';

    // Revenue line chart
    const revenueEl = document.getElementById('revenueChart');
    if (revenueEl) {
        const labels = revenueEl.dataset.labels ? JSON.parse(revenueEl.dataset.labels) : [];
        const values = revenueEl.dataset.values ? JSON.parse(revenueEl.dataset.values) : [];
        new Chart(revenueEl, {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    label: 'Revenue',
                    data: values,
                    borderColor: '#1D3B8B',
                    backgroundColor: 'rgba(29,59,139,.08)',
                    borderWidth: 2.5,
                    pointBackgroundColor: '#1D3B8B',
                    pointRadius: 4,
                    fill: true,
                    tension: 0.4,
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { backgroundColor: '#1D3B8B', padding: 12, cornerRadius: 8 } },
                scales: { y: { grid: { color: '#f0f4fb' }, ticks: { callback: v => '$' + v.toLocaleString() } }, x: { grid: { display: false } } }
            }
        });
    }

    // Transaction status donut
    const statusEl = document.getElementById('statusChart');
    if (statusEl) {
        const labels = statusEl.dataset.labels ? JSON.parse(statusEl.dataset.labels) : [];
        const values = statusEl.dataset.values ? JSON.parse(statusEl.dataset.values) : [];
        new Chart(statusEl, {
            type: 'doughnut',
            data: {
                labels,
                datasets: [{
                    data: values,
                    backgroundColor: ['#1D3B8B','#10C87B','#f59e0b','#ef4444','#3b82f6','#8b5cf6'],
                    borderWidth: 0,
                    hoverOffset: 6,
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '70%',
                plugins: { legend: { position: 'bottom', labels: { padding: 16, usePointStyle: true, pointStyleWidth: 8 } }, tooltip: { backgroundColor: '#1D3B8B', padding: 12, cornerRadius: 8 } },
            }
        });
    }

    // Users by role bar chart
    const rolesEl = document.getElementById('rolesChart');
    if (rolesEl) {
        const labels = rolesEl.dataset.labels ? JSON.parse(rolesEl.dataset.labels) : [];
        const values = rolesEl.dataset.values ? JSON.parse(rolesEl.dataset.values) : [];
        new Chart(rolesEl, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Users',
                    data: values,
                    backgroundColor: ['rgba(29,59,139,.8)','rgba(59,130,246,.8)','rgba(16,200,123,.8)','rgba(245,158,11,.8)'],
                    borderRadius: 8,
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { backgroundColor: '#1D3B8B', padding: 12, cornerRadius: 8 } },
                scales: { y: { grid: { color: '#f0f4fb' }, beginAtZero: true }, x: { grid: { display: false } } }
            }
        });
    }

    // Monthly transactions bar chart
    const txEl = document.getElementById('txMonthChart');
    if (txEl) {
        const labels = txEl.dataset.labels ? JSON.parse(txEl.dataset.labels) : [];
        const values = txEl.dataset.values ? JSON.parse(txEl.dataset.values) : [];
        new Chart(txEl, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Transactions',
                    data: values,
                    backgroundColor: 'rgba(16,200,123,.7)',
                    borderRadius: 6,
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { grid: { color: '#f0f4fb' }, beginAtZero: true }, x: { grid: { display: false } } }
            }
        });
    }
}

// ─────────────────────────────────────────────────────────────
// ANIMATE COUNTERS (stat cards)
// ─────────────────────────────────────────────────────────────
function animateCounters() {
    document.querySelectorAll('[data-count]').forEach(el => {
        const rawCount = el.dataset.count ? el.dataset.count.replace(/,/g, '') : '0';
        const target  = parseFloat(rawCount) || 0;
        const prefix  = el.dataset.prefix  || '';
        const suffix  = el.dataset.suffix  || '';
        let decimal = 0;
        if (el.dataset.decimal !== undefined) {
            decimal = parseInt(el.dataset.decimal, 10);
        } else if (prefix === '$' || rawCount.includes('.') || (target % 1 !== 0)) {
            decimal = 2;
        }
        const dur     = 1200;
        const start   = performance.now();
        const update  = (now) => {
            const p = Math.min((now - start) / dur, 1);
            const ease = 1 - Math.pow(1 - p, 3);
            const val = (target * ease).toLocaleString('en-US', {
                minimumFractionDigits: decimal,
                maximumFractionDigits: decimal
            });
            el.textContent = prefix + val + suffix;
            if (p < 1) requestAnimationFrame(update);
        };
        requestAnimationFrame(update);
    });
}

// ─────────────────────────────────────────────────────────────
// FORM VALIDATION (client side)
// ─────────────────────────────────────────────────────────────
function initFormValidation() {
    document.querySelectorAll('form[data-validate]').forEach(form => {
        form.addEventListener('submit', (e) => {
            let valid = true;
            form.querySelectorAll('[required]').forEach(field => {
                const err = field.parentElement.querySelector('.form-error');
                if (!field.value.trim()) {
                    valid = false;
                    field.style.borderColor = 'var(--danger)';
                    if (err) err.textContent = 'This field is required.';
                } else {
                    field.style.borderColor = '';
                    if (err) err.textContent = '';
                }
            });
            if (!valid) e.preventDefault();
        });
    });
}

// ─────────────────────────────────────────────────────────────
// CHAT UI
// ─────────────────────────────────────────────────────────────
function initChatUI() {
    const msgForm = document.getElementById('msgForm');
    if (!msgForm) return;

    const msgInput   = document.getElementById('msgInput');
    const msgList    = document.getElementById('msgList');

    msgForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const text = msgInput.value.trim();
        if (!text) return;

        // Real-time Scam Shield: check with the AI before a message ever appears
        // in the recipient's conversation. Server-side moderation repeats this check.
        try {
            const shieldData = new FormData();
            shieldData.append('message', text);
            shieldData.append('receiver_id', new URL(msgForm.action, window.location.href).searchParams.get('with') || '');
            const shieldResponse = await fetch(APP_URL + '/api/ai_chat_moderate.php', { method: 'POST', body: shieldData });
            const shield = await shieldResponse.json();
            const verdict = shield.verdict || {};
            if (!shieldResponse.ok || !shield.success) throw new Error(shield.error || 'Scam Shield lama heli karo.');
            if (verdict.action !== 'allow') {
                showToast(verdict.reason_somali || 'AI Scam Shield ayaa fariintan xannibay si EscrowPay kuu ilaaliyo.', 'danger', 5500);
                return;
            }
        } catch (error) {
            showToast(error.message || 'Scam Shield lama heli karo; fariinta lama dirin.', 'danger', 4500);
            return;
        }

        // Optimistic UI
        const bubble = document.createElement('div');
        bubble.className = 'msg msg-out fade-in';
        bubble.innerHTML = `
            <div class="msg-content">
                <div class="msg-bubble">${escHtml(text)}</div>
                <div class="msg-time">Just now</div>
            </div>`;
        msgList.appendChild(bubble);
        msgInput.value = '';
        msgList.scrollTop = msgList.scrollHeight;

        // POST to server
        try {
            const fd = new FormData(msgForm);
            fd.set('message', text);
            await fetch(msgForm.action, { method: 'POST', body: fd });
        } catch(e) {
            showToast('Failed to send message', 'danger');
        }
    });
}

// ─────────────────────────────────────────────────────────────
// CONFIRM DIALOG (replace browser confirm)
// ─────────────────────────────────────────────────────────────
window.confirmAction = (message, callback) => {
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay open';
    overlay.innerHTML = `
        <div class="modal" style="max-width:400px">
            <div class="modal-header">
                <span class="modal-title"><i class="ri-alert-line" style="color:var(--warning)"></i> Confirm</span>
            </div>
            <div class="modal-body"><p style="font-size:14px;color:var(--neutral)">${escHtml(message)}</p></div>
            <div class="modal-footer">
                <button class="btn btn-ghost" id="cfCancel">Cancel</button>
                <button class="btn btn-danger" id="cfOk">Confirm</button>
            </div>
        </div>`;
    document.body.appendChild(overlay);
    document.getElementById('cfCancel').onclick = () => overlay.remove();
    document.getElementById('cfOk').onclick    = () => { overlay.remove(); callback(); };
};

// ─────────────────────────────────────────────────────────────
// UTILITY
// ─────────────────────────────────────────────────────────────
function escHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

// Global search
document.getElementById('globalSearch')?.addEventListener('input', function() {
    // Simple page-local search (could be AJAX expanded)
    const q = this.value.toLowerCase();
    document.querySelectorAll('.searchable-row').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});
