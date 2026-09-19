/**
 * Shared behaviour across both portals: flash auto-dismiss, mobile
 * sidebar toggle, and a small helper for wiring up confirm-before-submit
 * forms without inline onclick handlers.
 */
(function () {
  'use strict';

  document.querySelectorAll('.nb-alert').forEach(function (el) {
    setTimeout(function () {
      el.style.transition = 'opacity 0.4s ease';
      el.style.opacity = '0';
      setTimeout(function () { el.remove(); }, 400);
    }, 6000);
  });

  var toggle = document.getElementById('nbSidebarToggle');
  var sidebar = document.getElementById('nbSidebar');
  if (toggle && sidebar) {
    toggle.style.display = 'inline-flex';
    toggle.addEventListener('click', function () {
      sidebar.classList.toggle('is-open');
    });
  }

  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('submit', function (evt) {
      if (!window.confirm(el.getAttribute('data-confirm'))) {
        evt.preventDefault();
      }
    });
  });

  // Disable submit buttons on submit to prevent double-submission and
  // give visible loading feedback (guide 13: loading states).
  document.querySelectorAll('form').forEach(function (form) {
    form.addEventListener('submit', function () {
      var btn = form.querySelector('button[type="submit"]');
      if (btn && !btn.disabled) {
        btn.disabled = true;
        btn.dataset.originalText = btn.textContent;
        btn.textContent = btn.dataset.loadingText || 'Please wait…';
      }
    });
  });
})();
