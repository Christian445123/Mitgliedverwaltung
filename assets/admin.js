// Ersetzt Inline-Event-Handler (onclick/onsubmit), damit eine strikte
// Content-Security-Policy ohne 'unsafe-inline' für Skripte möglich ist.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (event.submitter && event.submitter.hasAttribute('data-no-form-confirm')) {
                return;
            }
            if (!window.confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        });
    });

    // Buttons mit Rückfrage (data-confirm-button), z. B. Lizenz sperren
    document.querySelectorAll("button[data-confirm-button]").forEach(function (button) {
        button.addEventListener("click", function (event) {
            if (!window.confirm(button.dataset.confirmButton)) {
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
        if (counter) { counter.textContent = String(checked); }
        if (button) { button.disabled = checked === 0; }
        form.querySelectorAll('[data-needs-selection]').forEach(function (b) { b.disabled = checked === 0; });
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

// Mitgliederformular: weitere Eingabezeilen für neue Camps
document.addEventListener('DOMContentLoaded', function () {
    var box = document.querySelector('[data-new-camps]');
    if (!box) {
        return;
    }
    var button = box.querySelector('[data-add-camp]');
    button.addEventListener('click', function () {
        var row = document.createElement('div');
        row.className = 'new-camp-row';
        var input = document.createElement('input');
        input.type = 'text';
        input.name = 'new_camps[]';
        input.maxLength = 100;
        input.placeholder = 'Name des nächsten Camps';
        row.appendChild(input);
        box.insertBefore(row, button);
        input.focus();
    });
});

// Benutzerformular: Rechte-Übersicht (Durch Rolle / Ergebnis) live nach Rolle und Einzelrechten berechnen
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('user-form');
    if (!form) {
        return;
    }
    var roleSelect = form.querySelector('[data-role-select]');
    var note = form.querySelector('[data-admin-note]');
    var rows = form.querySelectorAll('tr[data-permission]');

    function update() {
        var option = roleSelect.options[roleSelect.selectedIndex];
        var isAdmin = option.dataset.admin === '1';
        var perms = [];
        try { perms = JSON.parse(option.dataset.perms || '[]'); } catch (e) { perms = []; }
        if (note) { note.hidden = !isAdmin; }

        rows.forEach(function (row) {
            var key = row.dataset.permission;
            var select = row.querySelector('[data-perm-select]');
            var byRole = isAdmin || perms.indexOf(key) !== -1;
            var choice = isAdmin ? 'default' : select.value;
            var result = choice === 'allow' ? true : choice === 'deny' ? false : byRole;

            select.disabled = isAdmin;
            row.querySelector('[data-role-cell]').innerHTML = byRole ? '<span class="perm-yes">✓ ja</span>' : '<span class="perm-no">– nein</span>';
            row.querySelector('[data-result-cell]').innerHTML = result ? '<span class="perm-yes">✓ darf</span>' : '<span class="perm-no">✗ darf nicht</span>';
        });
    }

    roleSelect.addEventListener('change', update);
    form.addEventListener('change', function (event) {
        if (event.target && event.target.matches('[data-perm-select]')) { update(); }
    });
    update();
});

// "Name & Vorname (automatisch)": zuerst Nachname, dann Vorname, live beim Tippen
document.addEventListener('DOMContentLoaded', function () {
    var auto = document.getElementById('name_vorname_auto');
    var last = document.getElementById('nachname');
    var first = document.getElementById('vorname');
    if (!auto || !last || !first) {
        return;
    }
    var update = function () {
        auto.value = (last.value.trim() + ' ' + first.value.trim()).trim();
    };
    last.addEventListener('input', update);
    first.addEventListener('input', update);
});
