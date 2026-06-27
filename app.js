function toggleNav() {
    document.querySelector('.nav-links').classList.toggle('nav-open');
    document.querySelector('.nav-scrim').classList.toggle('show');
}

// Scoped to whichever trigger was actually clicked (closest .nav-account
// ancestor) rather than a global "the first .nav-account on the page"
// lookup, since pages can now have two of these (account menu + language
// switcher) -- the old global-querySelector version only ever opened/closed
// the first one regardless of which button was pressed.
function toggleAccountMenu(e) {
    if (e) e.stopPropagation();
    var el = (e && e.currentTarget) ? e.currentTarget.closest('.nav-account') : document.querySelector('.nav-account');
    if (el) el.classList.toggle('open');
}
document.addEventListener('click', function (e) {
    document.querySelectorAll('.nav-account.open').forEach(function (el) {
        if (!el.contains(e.target)) el.classList.remove('open');
    });
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

    // Mobile search: the same .nav-search form on every page collapses to a
    // round icon button under 768px (see style.css) and expands in place
    // into a full-screen overlay on tap, instead of a separate dedicated
    // page or duplicated markup. Desktop is untouched -- the bar there is
    // already a normal always-visible search box.
    document.querySelectorAll('.nav-search').forEach(function (form) {
        var input = form.querySelector('input');
        if (!input) return;

        function openSearch() {
            form.classList.add('search-open');
            if (!form.querySelector('.nav-search-close')) {
                var closeBtn = document.createElement('button');
                closeBtn.type = 'button';
                closeBtn.className = 'nav-search-close';
                closeBtn.setAttribute('aria-label', 'Close search');
                closeBtn.innerHTML = '<i data-lucide="x" class="lucide-icon"></i>';
                closeBtn.addEventListener('click', function (e) { e.stopPropagation(); closeSearch(); });
                form.appendChild(closeBtn);
                if (window.lucide) lucide.createIcons();
            }
            setTimeout(function () { input.focus(); }, 60);
        }
        function closeSearch() {
            form.classList.remove('search-open');
        }

        form.addEventListener('click', function (e) {
            if (window.innerWidth > 768) return;
            if (!form.classList.contains('search-open')) {
                e.preventDefault();
                openSearch();
            } else if (e.target === form) {
                // tapped the overlay's own background, not the input/icon/close button
                closeSearch();
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && form.classList.contains('search-open')) closeSearch();
        });
    });
});
