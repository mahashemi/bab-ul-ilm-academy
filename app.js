function toggleNav() {
    document.querySelector('.nav-links').classList.toggle('nav-open');
    document.querySelector('.nav-scrim').classList.toggle('show');
}

function toggleAccountMenu(e) {
    if (e) e.stopPropagation();
    var el = document.querySelector('.nav-account');
    if (el) el.classList.toggle('open');
}
document.addEventListener('click', function (e) {
    var el = document.querySelector('.nav-account');
    if (el && el.classList.contains('open') && !el.contains(e.target)) {
        el.classList.remove('open');
    }
});

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('table.table').forEach(function (table) {
        var headers = Array.prototype.map.call(table.querySelectorAll('thead th'), function (th) {
            return th.textContent.trim();
        });
        table.querySelectorAll('tbody tr').forEach(function (tr) {
            Array.prototype.forEach.call(tr.children, function (td, i) {
                if (headers[i] && !td.hasAttribute('data-label')) {
                    td.setAttribute('data-label', headers[i]);
                }
            });
        });
    });
});
