/**
 * Admin portal interactions.
 *
 * Dependent dropdowns for the academic hierarchy (course creation) work
 * off a small JSON tree the page embeds as window.NB_HIERARCHY — the
 * catalogue is small enough per-university that this avoids a network
 * round trip per keystroke, while the server still re-validates every
 * relationship on submit (never trust the client for authorization or
 * referential integrity — see includes/validation.php).
 */
(function () {
  'use strict';

  function populate(select, items, placeholder) {
    select.innerHTML = '';
    var opt = document.createElement('option');
    opt.value = '';
    opt.textContent = placeholder;
    select.appendChild(opt);
    items.forEach(function (item) {
      var o = document.createElement('option');
      o.value = item.id;
      o.textContent = item.name;
      select.appendChild(o);
    });
    select.disabled = items.length === 0;
  }

  function wireHierarchy() {
    var tree = window.NB_HIERARCHY;
    var uniSelect = document.getElementById('nbUniversity');
    var facSelect = document.getElementById('nbFaculty');
    var deptSelect = document.getElementById('nbDepartment');
    var levelSelect = document.getElementById('nbLevel');
    if (!tree || !uniSelect || !facSelect || !deptSelect || !levelSelect) {
      return;
    }

    function onUniChange() {
      var uni = tree.universities.find(function (u) { return String(u.id) === uniSelect.value; });
      populate(facSelect, uni ? uni.faculties : [], 'Select faculty');
      populate(deptSelect, [], 'Select department');
      populate(levelSelect, [], 'Select level');
    }
    function onFacChange() {
      var uni = tree.universities.find(function (u) { return String(u.id) === uniSelect.value; });
      var fac = uni && uni.faculties.find(function (f) { return String(f.id) === facSelect.value; });
      populate(deptSelect, fac ? fac.departments : [], 'Select department');
      populate(levelSelect, [], 'Select level');
    }
    function onDeptChange() {
      var uni = tree.universities.find(function (u) { return String(u.id) === uniSelect.value; });
      var fac = uni && uni.faculties.find(function (f) { return String(f.id) === facSelect.value; });
      var dept = fac && fac.departments.find(function (d) { return String(d.id) === deptSelect.value; });
      populate(levelSelect, dept ? dept.levels : [], 'Select level');
    }

    uniSelect.addEventListener('change', onUniChange);
    facSelect.addEventListener('change', onFacChange);
    deptSelect.addEventListener('change', onDeptChange);

    // Pre-selected values (edit form): trigger cascades once on load.
    if (uniSelect.value) {
      onUniChange();
      if (facSelect.dataset.selected) { facSelect.value = facSelect.dataset.selected; onFacChange(); }
      if (deptSelect.dataset.selected) { deptSelect.value = deptSelect.dataset.selected; onDeptChange(); }
      if (levelSelect.dataset.selected) { levelSelect.value = levelSelect.dataset.selected; }
    }
  }

  document.addEventListener('DOMContentLoaded', wireHierarchy);

  // File input UX: show the chosen filename and a size check before submit.
  document.querySelectorAll('input[type="file"][data-max-mb]').forEach(function (input) {
    input.addEventListener('change', function () {
      var label = document.querySelector('[data-file-label-for="' + input.id + '"]');
      var maxMb = parseFloat(input.dataset.maxMb);
      if (input.files && input.files[0]) {
        var file = input.files[0];
        var mb = file.size / (1024 * 1024);
        if (label) {
          label.textContent = file.name + ' (' + mb.toFixed(1) + ' MB)';
          label.style.color = mb > maxMb ? 'var(--nb-danger)' : 'var(--nb-muted)';
        }
      }
    });
  });
})();
