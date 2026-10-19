document.addEventListener('click', function (ev) {
  var t = ev.target.closest('[data-confirm],[data-print],[data-mark-all],[data-nav-toggle]');
  if (!t) return;
  if (t.hasAttribute('data-nav-toggle')) {
    t.closest('.side').classList.toggle('open');
  } else if (t.hasAttribute('data-print')) {
    ev.preventDefault();
    window.print();
  } else if (t.hasAttribute('data-mark-all')) {
    ev.preventDefault();
    var v = t.getAttribute('data-mark-all');
    document.querySelectorAll('input[type=radio][value="' + v + '"]').forEach(function (r) { r.checked = true; });
  } else if (t.hasAttribute('data-confirm') && !window.confirm(t.getAttribute('data-confirm'))) {
    ev.preventDefault();
  }
});
