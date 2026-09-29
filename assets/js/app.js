/* CRM «Учет рабочих» — клиентский JS без сборки */
(function () {
  'use strict';

  // Мобильное меню
  var btn = document.getElementById('btnSidebarToggle');
  if (btn) btn.addEventListener('click', function () {
    document.getElementById('crmSidebar').classList.toggle('show');
  });

  var csrfMeta = document.querySelector('meta[name="csrf-token"]');
  window.CRM_CSRF = csrfMeta ? csrfMeta.content : '';

  // Глобальный AJAX-поиск (частичное совпадение)
  var input = document.getElementById('globalSearch');
  var box = document.getElementById('searchResults');
  if (!input || !box) return;
  var timer = null;

  input.addEventListener('input', function () {
    clearTimeout(timer);
    var q = this.value.trim();
    if (q.length < 2) { box.classList.remove('open'); box.innerHTML = ''; return; }
    timer = setTimeout(function () {
      fetch('api/search.php?q=' + encodeURIComponent(q), {headers: {'X-Requested-With': 'XMLHttpRequest'}})
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data.ok) { box.classList.remove('open'); return; }
          if (!data.results.length) {
            box.innerHTML = '<div class="p-2 text-muted small">Ничего не найдено</div>';
            box.classList.add('open'); return;
          }
          box.innerHTML = data.results.map(function (w) {
            return '<a href="workers/view.php?id=' + w.id + '">' +
              '<span class="sr-fio">' + esc(w.fio) + '</span> <span class="text-muted">#' + w.id + '</span>' +
              '<div class="sr-meta">' + esc(w.phone || '') + ' · ' + esc(w.status_label || '') + ' · ' + esc(w.object || 'без объекта') + '</div></a>';
          }).join('') + '<div class="p-2 border-top"><a class="text-primary small" href="workers/index.php?search=' + encodeURIComponent(input.value.trim()) + '">Показать всех в списке →</a></div>';
          box.classList.add('open');
        })
        .catch(function () {});
    }, 250);
  });

  document.addEventListener('click', function (ev) {
    if (!box.contains(ev.target) && ev.target !== input) box.classList.remove('open');
  });

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  window.crmGlobalSearch = function (ev) {
    ev.preventDefault();
    var q = input.value.trim();
    if (q) location.href = 'workers/index.php?search=' + encodeURIComponent(q);
    return false;
  };

  // Подтверждение удаления
  document.addEventListener('click', function (ev) {
    var el = ev.target.closest('[data-confirm]');
    if (el && !confirm(el.getAttribute('data-confirm'))) {
      ev.preventDefault();
      ev.stopPropagation();
    }
  }, true);

  // Чекбоксы массового выбора
  document.addEventListener('change', function (ev) {
    if (ev.target.id === 'checkAll') {
      document.querySelectorAll('.row-check').forEach(function (cb) { cb.checked = ev.target.checked; });
    }
  });
})();
