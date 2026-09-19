// Ersetzt Inline-Event-Handler (onclick/onsubmit), damit eine strikte
// Content-Security-Policy ohne 'unsafe-inline' für Skripte möglich ist.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('input[data-select-on-click]').forEach(function (input) {
        input.addEventListener('click', function () {
            input.select();
        });
    });

    document.querySelectorAll('button[data-copy-target]').forEach(function (button) {
        button.addEventListener('click', function () {
            var target = document.getElementById(button.dataset.copyTarget);
            if (!target) {
                return;
            }
            navigator.clipboard.writeText(target.value).then(function () {
                button.textContent = 'Kopiert!';
            });
        });
    });
});

// Filter-Auswahlfelder senden das Formular sofort ab (ohne Inline-Skript wegen CSP)
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('select[data-autosubmit]').forEach(function (select) {
        select.addEventListener('change', function () {
            if (select.form) {
                select.form.submit();
            }
        });
    });
});

// Mehrfachauswahl in der Mitgliederliste: "Alle auswählen" und Zähler am Lösch-Button
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('bulk-form');
    if (!form) {
        return;
    }
    var boxes = function () { return form.querySelectorAll('input[data-row-check]'); };
    var all = form.querySelector('input[data-select-all]');
    var button = document.getElementById('bulk-delete');
    var counter = document.getElementById('bulk-count');

    function update() {
        var checked = 0;
        boxes().forEach(function (box) { if (box.checked) { checked++; } });
        counter.textContent = String(checked);
        button.disabled = checked === 0;
        if (all) {
            all.checked = checked > 0 && checked === boxes().length;
            all.indeterminate = checked > 0 && checked < boxes().length;
        }
    }

    if (all) {
        all.addEventListener('change', function () {
            boxes().forEach(function (box) { box.checked = all.checked; });
            update();
        });
    }
    form.addEventListener('change', function (event) {
        if (event.target && event.target.matches('input[data-row-check]')) {
            update();
        }
    });
    update();
});

// Mobiles Menü: Seitenleiste auf Tablet/Handy auf- und zuklappen
document.addEventListener('DOMContentLoaded', function () {
    var toggle = document.querySelector('[data-nav-toggle]');
    var sidebar = document.querySelector('.sidebar');
    if (!toggle || !sidebar) {
        return;
    }
    toggle.addEventListener('click', function () {
        var open = sidebar.classList.toggle('nav-open');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
});

// Feld-Rechte: Schnellwahl für alle Spieler-Felder
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-bulk-player]').forEach(function (button) {
        button.addEventListener('click', function () {
            document.querySelectorAll('select[data-player-select]').forEach(function (select) {
                select.value = button.dataset.bulkPlayer;
            });
        });
    });
});
