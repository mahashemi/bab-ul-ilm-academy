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
    // Category nav: subcategory bar only appears for the field being
    // hovered (Udemy-style), not always-on. A short hide delay lets the
    // mouse travel from the category link down into the subcategory bar
    // without it disappearing first.
    var categoryGroup = document.getElementById('categoryNavGroup');
    if (categoryGroup) {
        var fieldLinks = categoryGroup.querySelectorAll('.category-nav a[data-field-id]');
        var panels = categoryGroup.querySelectorAll('.subcategory-nav');
        var hideTimer = null;
        fieldLinks.forEach(function (link) {
            link.addEventListener('mouseenter', function () {
                clearTimeout(hideTimer);
                var fid = link.getAttribute('data-field-id');
                var hasPanel = false;
                panels.forEach(function (p) {
                    var match = p.getAttribute('data-for-field') === fid;
                    p.classList.toggle('active-panel', match);
                    if (match) hasPanel = true;
                });
                categoryGroup.classList.toggle('open', hasPanel);
            });
        });
        categoryGroup.addEventListener('mouseleave', function () {
            hideTimer = setTimeout(function () { categoryGroup.classList.remove('open'); }, 200);
        });
    }

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

    // Shared "Copy Prompt" button for the AI-prompt boxes on the bulk-CSV-upload
    // and lesson/quiz/assignment helper pages (data-target points at the <pre> id).
    document.querySelectorAll('.copy-prompt-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = document.getElementById(btn.dataset.target);
            if (!target) return;
            navigator.clipboard.writeText(target.textContent).then(function () {
                var original = btn.innerHTML;
                btn.innerHTML = '<i data-lucide="check" class="lucide-icon"></i> Copied!';
                if (window.lucide) lucide.createIcons();
                setTimeout(function () { btn.innerHTML = original; if (window.lucide) lucide.createIcons(); }, 1800);
            });
        });
    });
});
