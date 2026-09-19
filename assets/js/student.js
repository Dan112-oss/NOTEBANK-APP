/**
 * Student portal interactions: catalogue filtering, cart add/remove,
 * and payment method selection. All of these call api/*.php, which
 * re-validates session, ownership and prices server-side — nothing
 * here is trusted for anything security-relevant (see the guide,
 * section 12).
 */
(function () {
  'use strict';

  var csrfToken = document.querySelector('meta[name="csrf-token"]');
  csrfToken = csrfToken ? csrfToken.content : '';

  function apiFetch(url, options) {
    options = options || {};
    options.headers = Object.assign({
      'Content-Type': 'application/json',
      'X-CSRF-Token': csrfToken,
    }, options.headers || {});
    options.credentials = 'same-origin';
    return fetch(url, options).then(function (res) {
      return res.json().then(function (body) { return { ok: res.ok, body: body }; });
    });
  }

  function showToast(message, isError) {
    var toast = document.createElement('div');
    toast.className = 'nb-alert ' + (isError ? 'nb-alert--error' : 'nb-alert--success');
    toast.textContent = message;
    toast.style.position = 'fixed';
    toast.style.bottom = '90px';
    toast.style.left = '50%';
    toast.style.transform = 'translateX(-50%)';
    toast.style.zIndex = '999';
    toast.style.boxShadow = '0 10px 30px rgba(11,37,89,0.25)';
    document.body.appendChild(toast);
    setTimeout(function () { toast.remove(); }, 3200);
  }

  function updateCartBadge(count) {
    document.querySelectorAll('.nb-cart-pill__count').forEach(function (el) {
      el.textContent = count;
    });
  }

  // ---- Catalogue filters (course.php / courses.php) ----
  var filterForm = document.getElementById('nbCatalogFilters');
  if (filterForm) {
    var resultsEl = document.getElementById('nbCatalogResults');
    var facultyEl = filterForm.querySelector('[name="faculty_id"]');
    var departmentEl = filterForm.querySelector('[name="department_id"]');
    var levelEl = filterForm.querySelector('[name="level_id"]');

    function currentFilters() {
      var data = new FormData(filterForm);
      var params = new URLSearchParams();
      data.forEach(function (value, key) { if (value) params.append(key, value); });
      return params;
    }

    function reload() {
      if (!resultsEl) { filterForm.submit(); return; }
      resultsEl.setAttribute('aria-busy', 'true');
      fetch('../api/courses.php?' + currentFilters().toString(), { credentials: 'same-origin' })
        .then(function (res) { return res.json(); })
        .then(function (json) {
          if (!json.success) { return; }
          renderCourseOptions(json.data.courses || []);
        })
        .finally(function () { resultsEl.removeAttribute('aria-busy'); });
    }

    function renderCourseOptions(courses) {
      var courseSelect = document.getElementById('nbCourseSelect');
      if (!courseSelect) { return; }
      courseSelect.innerHTML = '<option value="">All courses</option>';
      courses.forEach(function (c) {
        var opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = c.code + ' — ' + c.title;
        courseSelect.appendChild(opt);
      });
    }

    [facultyEl, departmentEl, levelEl].forEach(function (el) {
      if (el) { el.addEventListener('change', reload); }
    });
  }

  // ---- Add / remove from cart ----
  document.querySelectorAll('[data-add-to-cart]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var documentId = btn.getAttribute('data-add-to-cart');
      btn.disabled = true;
      var original = btn.textContent;
      btn.textContent = 'Adding…';
      apiFetch('../api/cart.php', {
        method: 'POST',
        body: JSON.stringify({ action: 'add', document_id: documentId }),
      }).then(function (res) {
        if (res.ok && res.body.success) {
          updateCartBadge(res.body.data.cart_count);
          btn.textContent = 'Added ✓';
          showToast('Added to cart.');
        } else {
          btn.textContent = original;
          showToast(res.body.error || 'Could not add to cart.', true);
        }
      }).catch(function () {
        btn.textContent = original;
        showToast('Network error. Please try again.', true);
      }).finally(function () {
        setTimeout(function () { btn.disabled = false; }, 900);
      });
    });
  });

  document.querySelectorAll('[data-remove-from-cart]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var itemId = btn.getAttribute('data-remove-from-cart');
      var row = document.getElementById('cart-row-' + itemId);
      apiFetch('../api/cart.php', {
        method: 'POST',
        body: JSON.stringify({ action: 'remove', cart_item_id: itemId }),
      }).then(function (res) {
        if (res.ok && res.body.success) {
          if (row) { row.remove(); }
          updateCartBadge(res.body.data.cart_count);
          var totalEl = document.getElementById('nbCartTotal');
          if (totalEl && res.body.data.total_formatted) { totalEl.textContent = res.body.data.total_formatted; }
          if (res.body.data.cart_count === 0) { window.location.reload(); }
        } else {
          showToast(res.body.error || 'Could not remove item.', true);
        }
      });
    });
  });

  // ---- Payment method picker ----
  document.querySelectorAll('.nb-pay-method').forEach(function (label) {
    var input = label.querySelector('input[type="radio"]');
    if (!input) { return; }
    function sync() {
      document.querySelectorAll('.nb-pay-method').forEach(function (l) { l.classList.remove('is-selected'); });
      if (input.checked) { label.classList.add('is-selected'); }
      var details = document.querySelectorAll('[data-pay-details]');
      details.forEach(function (d) {
        d.hidden = d.getAttribute('data-pay-details') !== input.value;
      });
    }
    input.addEventListener('change', sync);
    if (input.checked) { sync(); }
  });
})();
