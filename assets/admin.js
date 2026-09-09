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
