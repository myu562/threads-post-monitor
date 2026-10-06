/*
 * Light / dark theme toggle.
 *
 * The first paint is handled by the inline script in partials/theme-init (it sets
 * <html data-theme> before the stylesheet loads, so there is no flash). This file only wires
 * up the toggle buttons and follows the system theme until the user picks one.
 *
 * The user's pick is stored in localStorage under "theme" ("light" or "dark"). localStorage can
 * throw (private mode, blocked site data), so every access is wrapped; without it the toggle
 * still works for the current page.
 */
(function () {
    'use strict';

    var KEY = 'theme';
    var root = document.documentElement;
    var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    function stored() {
        try {
            var v = window.localStorage.getItem(KEY);
            return v === 'light' || v === 'dark' ? v : null;
        } catch (e) {
            return null;
        }
    }

    function store(theme) {
        try {
            window.localStorage.setItem(KEY, theme);
        } catch (e) {
            // Not persisted; the choice still applies to this page.
        }
    }

    function systemTheme() {
        return media && media.matches ? 'dark' : 'light';
    }

    function current() {
        return root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    }

    function sync() {
        var dark = current() === 'dark';
        var buttons = document.querySelectorAll('[data-theme-toggle]');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].setAttribute('aria-pressed', dark ? 'true' : 'false');
            // Tooltip texts come from the page, in the interface language (partials/theme-toggle).
            var title = buttons[i].getAttribute(dark ? 'data-title-to-light' : 'data-title-to-dark');
            if (title) {
                buttons[i].setAttribute('title', title);
            }
        }
    }

    function apply(theme) {
        root.setAttribute('data-theme', theme);
        root.style.colorScheme = theme; // browser canvas and form controls match, even before CSS loads
        sync();
    }

    // Re-check on load: the inline script already ran, this only covers edge cases (e.g. it was
    // blocked) and syncs aria-pressed with the theme that is actually shown.
    apply(stored() || (root.getAttribute('data-theme') === 'dark' || root.getAttribute('data-theme') === 'light' ? current() : systemTheme()));

    document.addEventListener('click', function (event) {
        var target = event.target;
        var button = target && target.closest ? target.closest('[data-theme-toggle]') : null;
        if (!button) {
            return;
        }
        var next = current() === 'dark' ? 'light' : 'dark';
        store(next);
        apply(next);
    });

    // Follow the system theme only while the user has not picked one.
    if (media) {
        var onChange = function () {
            if (!stored()) {
                apply(systemTheme());
            }
        };
        if (media.addEventListener) {
            media.addEventListener('change', onChange);
        } else if (media.addListener) {
            media.addListener(onChange);
        }
    }

    // Keep other open tabs in step.
    window.addEventListener('storage', function (event) {
        if (event.key === KEY) {
            apply(stored() || systemTheme());
        }
    });
})();
