{{-- Progressive enhancement for the list and the post page; everything works without it.
     1. Remembers which group sections are open (localStorage "groups-open", per browser). Filtered
        lists are left alone: there the matching sections are opened by the server.
     2. Shows the new-group name field only while "New group…" is selected. --}}
<script>
(function () {
    var KEY = 'groups-open';
    function read() {
        try {
            var v = JSON.parse(localStorage.getItem(KEY) || '[]');
            return Array.isArray(v) ? v : [];
        } catch (e) {
            return [];
        }
    }
    function write(v) {
        try { localStorage.setItem(KEY, JSON.stringify(v)); } catch (e) {}
    }

    var list = document.querySelector('[data-group-list]');
    if (list && !list.hasAttribute('data-filtered')) {
        var sections = Array.prototype.slice.call(list.querySelectorAll('details[data-group-key]'));
        var keys = sections.map(function (d) { return d.getAttribute('data-group-key'); });
        var stored = read();
        sections.forEach(function (d) {
            if (stored.indexOf(d.getAttribute('data-group-key')) !== -1) { d.open = true; }
        });
        // "toggle" also fires for sections opened while the page loads; only record the user's clicks.
        var ready = false;
        setTimeout(function () { ready = true; }, 0);
        sections.forEach(function (d) {
            d.addEventListener('toggle', function () {
                if (!ready) { return; }
                var key = d.getAttribute('data-group-key');
                // Forget deleted groups; keep "none" even when Ungrouped is not shown right now.
                var cur = read().filter(function (k) { return k === 'none' || keys.indexOf(k) !== -1; });
                var i = cur.indexOf(key);
                if (d.open && i === -1) { cur.push(key); }
                if (!d.open && i !== -1) { cur.splice(i, 1); }
                write(cur);
            });
        });
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-meta-form]'), function (form) {
        var select = form.querySelector('[data-group-select]');
        var box = form.querySelector('[data-group-new]');
        if (!select || !box) { return; }
        var input = box.querySelector('input');
        function sync() { box.hidden = select.value !== 'new'; }
        select.addEventListener('change', function () {
            sync();
            if (select.value === 'new' && input) { input.focus(); }
        });
        sync();
    });
})();
</script>
