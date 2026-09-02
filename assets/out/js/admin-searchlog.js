/**
 * foun10 -> Suche -> Auswertung
 *
 * One job: every control in the toolbar reloads the screen. The report is read
 * only, so there is nothing to lose by submitting on change and nothing to save
 * - which is why there is no save button to press and no unsaved state to warn
 * about.
 *
 * The language select carries no name of its own, the way it does on the index
 * screen: it writes into the hidden field the controller reads, so the two
 * screens stay the same shape.
 */
(function (document) {
    'use strict';

    var form = document.getElementById('foun10LogForm');

    if (!form) {
        return;
    }

    Array.prototype.forEach.call(form.querySelectorAll('[data-reload]'), function (control) {
        control.addEventListener('change', function () {
            form.submit();
        });
    });

    var language = form.querySelector('[data-language-switch]');

    if (language) {
        language.addEventListener('change', function () {
            var target = form.querySelector('input[name="logLang"]');

            if (target) {
                target.value = language.value;
            }

            form.submit();
        });
    }
}(document));
