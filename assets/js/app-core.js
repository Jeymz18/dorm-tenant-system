/* Shared core UI helpers. */
(function (window, document) {
  'use strict';
  const App = window.App = window.App || {};

  App.toast = function (message, type) {
    const variant = type === 'error' ? 'danger' : (type || 'success');
    let container = document.querySelector('.global-toast-container');
    if (!container) { 
      container = document.createElement('div'); 
      container.className = 'global-toast-container toast-container position-fixed top-0 end-0 p-3'; 
      container.style.zIndex = '1090'; 
      document.body.appendChild(container); 
    }
    const toast = document.createElement('div'); 
    toast.className = 'toast text-bg-' + variant + ' border-0'; 
    toast.setAttribute('role', 'status');
    toast.innerHTML = '<div class="d-flex"><div class="toast-body"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>';
    toast.querySelector('.toast-body').textContent = message; 
    container.appendChild(toast);
    const instance = bootstrap.Toast.getOrCreateInstance(toast, { delay: 4000 }); 
    toast.addEventListener('hidden.bs.toast', function () { toast.remove(); }); 
    instance.show();
  };

  App.btnLoading = function (button, loading) {
    if (!button) return;
    if (loading === false) { 
      button.disabled = false; 
      if (button.dataset.originalHtml) button.innerHTML = button.dataset.originalHtml; 
      return; 
    }
    if (!button.dataset.originalHtml) button.dataset.originalHtml = button.innerHTML;
    button.disabled = true; 
    button.innerHTML = '<span class="spinner-border global-submit-spinner" aria-hidden="true"></span><span>Processing...</span>';
  };

  App.chart = function (canvas, config) {
    if (!canvas || !window.Chart) return null;
    const existing = Chart.getChart(canvas); 
    if (existing) existing.destroy();
    return new Chart(canvas, config);
  };

  document.addEventListener('DOMContentLoaded', function () {
    (function () {
      const relativeApiUrl = (window.location.pathname.includes('/admin/') || window.location.pathname.includes('/tenant/'))
        ? '../api/get_unread_notifications.php'
        : 'api/get_unread_notifications.php';

      const badgeSelectors = {
        tenants: '[data-badge-type="badge-tenants"], #nav-badge-tenants',
        payments: '[data-badge-type="badge-payments"], #nav-badge-payments',
        maintenance: '[data-badge-type="badge-maintenance"], #nav-badge-maintenance',
        notifications: '[data-badge-type="badge-notifications"], #nav-badge-notifications'
      };

      const dotSelector = '[data-badge-type="dot-notifications"], #nav-dot-notifications';

      const setIndicatorVisible = function (element, visible) {
        element.hidden = !visible;
        element.classList.toggle('hidden', !visible);
        element.classList.toggle('d-none', !visible);
      };

      const getBadgeElements = function (tab) {
        return Array.from(document.querySelectorAll(badgeSelectors[tab]));
      };

      const getNotificationDots = function () {
        return Array.from(document.querySelectorAll(dotSelector));
      };

      // Fetch updated counts
      fetch(relativeApiUrl, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(function (response) {
          if (!response.ok) return null;
          return response.json().catch(function () { return null; });
        })
        .then(function (data) {
          if (!data || data.status !== 'success' || !data.counts) return;
          const counts = data.counts || {};

          ['tenants', 'payments', 'maintenance', 'notifications'].forEach(function (tab) {
            const badges = getBadgeElements(tab);
            const count = Number(counts[tab]) || 0;

            badges.forEach(function (badge) {
              badge.textContent = count > 99 ? '99+' : String(count);
              setIndicatorVisible(badge, count > 0);
            });
          });

          const announcementCount = Number(counts.announcements) || 0;
          getNotificationDots().forEach(function (dot) {
            setIndicatorVisible(dot, announcementCount > 0);
          });
        })
        .catch(function () { return null; });
    })();

    document.querySelectorAll('.alert:not([data-app-alert])').forEach(function (alert) {
      alert.dataset.appAlert = 'true'; 
      window.setTimeout(function () { bootstrap.Alert.getOrCreateInstance(alert).close(); }, 4000);
    });

    document.addEventListener('submit', function (event) {
      if (event.defaultPrevented) return;
      const form = event.target; 
      const button = form instanceof HTMLFormElement && form.querySelector('button[type="submit"], button:not([type])');
      if (!button || form.dataset.appLoading) return;
      form.dataset.appLoading = 'true'; 
      App.btnLoading(button);
    });

    document.querySelectorAll('.btn-print, [data-action="print"]').forEach(function (button) { 
      button.addEventListener('click', function (event) { event.preventDefault(); window.print(); }); 
    });
  });
})(window, document);