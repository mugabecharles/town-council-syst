/* ================================================================
   TCMS - Core JavaScript
   ================================================================ */

'use strict';

// ── Sidebar Toggle ──────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {

  const sidebar   = document.getElementById('sidebar');
  const menuToggle = document.getElementById('menuToggle');
  const overlay   = document.getElementById('sidebarOverlay');

  if (menuToggle && sidebar) {
    menuToggle.addEventListener('click', () => {
      sidebar.classList.toggle('open');
      if (overlay) overlay.classList.toggle('active');
      document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
    });
  }
  if (overlay) {
    overlay.addEventListener('click', () => {
      sidebar.classList.remove('open');
      overlay.classList.remove('active');
      document.body.style.overflow = '';
    });
  }

  // ── Auto-dismiss alerts ───────────────────────────────────────
  document.querySelectorAll('.alert[data-auto-dismiss]').forEach(el => {
    setTimeout(() => el.remove(), parseInt(el.dataset.autoDismiss) || 5000);
  });

  // ── Alert close buttons ──────────────────────────────────────
  document.querySelectorAll('.alert-close').forEach(btn => {
    btn.addEventListener('click', () => btn.closest('.alert').remove());
  });

  // ── Tabs ─────────────────────────────────────────────────────
  document.querySelectorAll('.tab-link').forEach(link => {
    link.addEventListener('click', function () {
      const group = this.closest('.tab-group') || document;
      group.querySelectorAll('.tab-link').forEach(l => l.classList.remove('active'));
      group.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
      this.classList.add('active');
      const target = document.getElementById(this.dataset.tab);
      if (target) target.classList.add('active');
    });
  });

  // ── Modals ───────────────────────────────────────────────────
  document.querySelectorAll('[data-modal]').forEach(trigger => {
    trigger.addEventListener('click', () => {
      const modal = document.getElementById(trigger.dataset.modal);
      if (modal) modal.classList.add('show');
    });
  });
  document.querySelectorAll('.modal-close, [data-modal-close]').forEach(btn => {
    btn.addEventListener('click', () => {
      btn.closest('.modal-backdrop').classList.remove('show');
    });
  });
  document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
    backdrop.addEventListener('click', function (e) {
      if (e.target === this) this.classList.remove('show');
    });
  });

  // ── Confirm dialogs ──────────────────────────────────────────
  document.querySelectorAll('[data-confirm]').forEach(el => {
    el.addEventListener('click', function (e) {
      if (!confirm(this.dataset.confirm)) e.preventDefault();
    });
  });

  // ── Mark notifications read ──────────────────────────────────
  const notifBtn = document.getElementById('notifBtn');
  if (notifBtn) {
    notifBtn.addEventListener('click', () => {
      fetch('api/notifications.php?action=mark_read', { method: 'POST' });
      const dot = notifBtn.querySelector('.notif-dot');
      if (dot) dot.remove();
    });
  }

  // ── Numeric formatting on blur ────────────────────────────────
  document.querySelectorAll('input[data-format="currency"]').forEach(el => {
    el.addEventListener('blur', function () {
      const val = parseFloat(this.value.replace(/,/g, ''));
      if (!isNaN(val)) this.value = val.toLocaleString('en-UG');
    });
    el.addEventListener('focus', function () {
      this.value = this.value.replace(/,/g, '');
    });
  });

  // ── Ward → Parish → Village cascades ─────────────────────────
  const wardSel    = document.getElementById('ward_id');
  const parishSel  = document.getElementById('parish_id');
  const villageSel = document.getElementById('village_id');

  if (wardSel && parishSel) {
    wardSel.addEventListener('change', function () {
      const wid = this.value;
      parishSel.innerHTML = '<option value="">-- Select Parish --</option>';
      if (villageSel) villageSel.innerHTML = '<option value="">-- Select Village --</option>';
      if (!wid) return;
      fetch(`api/locations.php?action=parishes&ward_id=${wid}`)
        .then(r => r.json())
        .then(data => {
          data.forEach(p => {
            parishSel.innerHTML += `<option value="${p.id}">${escHtml(p.name)}</option>`;
          });
        });
    });
  }
  if (parishSel && villageSel) {
    parishSel.addEventListener('change', function () {
      const pid = this.value;
      villageSel.innerHTML = '<option value="">-- Select Village --</option>';
      if (!pid) return;
      fetch(`api/locations.php?action=villages&parish_id=${pid}`)
        .then(r => r.json())
        .then(data => {
          data.forEach(v => {
            villageSel.innerHTML += `<option value="${v.id}">${escHtml(v.name)}</option>`;
          });
        });
    });
  }

  // ── Search shortcut ──────────────────────────────────────────
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
      e.preventDefault();
      var s = document.getElementById('globalSearch');
      if (s) { s.focus(); s.select(); }
    }
    // Escape closes modals
    if (e.key === 'Escape') {
      document.querySelectorAll('.modal-backdrop.show').forEach(function(m) {
        m.classList.remove('show');
      });
    }
  });

  // ── Print button ─────────────────────────────────────────────
  document.querySelectorAll('[data-print]').forEach(btn => {
    btn.addEventListener('click', () => window.print());
  });

});

// ── Helpers ──────────────────────────────────────────────────────
function escHtml(str) {
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function openModal(id) {
  const m = document.getElementById(id);
  if (m) m.classList.add('show');
}
function closeModal(id) {
  const m = document.getElementById(id);
  if (m) m.classList.remove('show');
}

function showAlert(message, type = 'info', container = '#alertContainer') {
  const icons = { success: '✓', danger: '✕', warning: '⚠', info: 'ℹ' };
  const html = `<div class="alert alert-${type}" data-auto-dismiss="5000">
    <span>${icons[type] || 'ℹ'}</span>
    <span>${escHtml(message)}</span>
    <button class="alert-close" aria-label="Close">✕</button>
  </div>`;
  const el = document.querySelector(container);
  if (el) { el.insertAdjacentHTML('beforeend', html); }
}

function formatUGX(amount) {
  return 'UGX ' + Number(amount).toLocaleString('en-UG', { minimumFractionDigits: 0 });
}

// ── Chart helper (wraps Chart.js) ────────────────────────────────
function makeBarChart(canvasId, labels, datasets, options = {}) {
  const ctx = document.getElementById(canvasId);
  if (!ctx || typeof Chart === 'undefined') return;
  return new Chart(ctx, {
    type: 'bar',
    data: { labels, datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } },
      scales: {
        y: { beginAtZero: true, ticks: { font: { size: 11 } } },
        x: { ticks: { font: { size: 11 } } }
      },
      ...options
    }
  });
}

function makeLineChart(canvasId, labels, datasets, options = {}) {
  const ctx = document.getElementById(canvasId);
  if (!ctx || typeof Chart === 'undefined') return;
  return new Chart(ctx, {
    type: 'line',
    data: { labels, datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } },
      scales: {
        y: { beginAtZero: true, ticks: { font: { size: 11 } } },
        x: { ticks: { font: { size: 11 } } }
      },
      ...options
    }
  });
}

function makeDoughnutChart(canvasId, labels, data, colors = []) {
  const ctx = document.getElementById(canvasId);
  if (!ctx || typeof Chart === 'undefined') return;
  const defaultColors = ['#1a3a5c','#c8a84b','#1e7e34','#bd2130','#117a8b','#6c757d','#d39e00'];
  return new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels,
      datasets: [{ data, backgroundColor: colors.length ? colors : defaultColors, borderWidth: 2, borderColor: '#fff' }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } },
      cutout: '65%'
    }
  });
}
