/**
 * foun10 -> Suche -> Synonyme
 *
 * Four small jobs, none of which needs a framework:
 *
 *  - add a row, cloning the last one so the type options stay in sync with
 *    whatever the server rendered;
 *  - remove a row, keeping at least one so the table never becomes a dead end;
 *  - mirror each active checkbox into its hidden field, because a cleared
 *    checkbox posts nothing at all and the row would come back active;
 *  - switch language, warning first if anything was typed - the switch reloads
 *    the screen and unsaved rows would be gone without a word.
 *
 * Row indices only have to be unique, never contiguous: PHP rebuilds
 * rules[n][...] as an array in document order, so removing a row in the middle
 * is safe and nothing needs renumbering.
 */
(function (window, document) {
    'use strict';

    var nextIndex = 0;
    var dirty = false;

    function table() {
        return document.getElementById('foun10SynonymRules');
    }

    function rows() {
        var body = table() ? table().querySelector('tbody') : null;

        return body ? Array.prototype.slice.call(body.querySelectorAll('.foun10-rules__row')) : [];
    }

    /**
     * Highest index the server already used, so a cloned row cannot collide
     * with an existing one.
     */
    function findNextIndex() {
        var highest = -1;

        rows().forEach(function (row) {
            var field = row.querySelector('[name]');

            if (!field) {
                return;
            }

            var match = /^rules\[(\d+)\]/.exec(field.getAttribute('name'));

            if (match) {
                highest = Math.max(highest, parseInt(match[1], 10));
            }
        });

        return highest + 1;
    }

    function reindexRow(row, index) {
        Array.prototype.forEach.call(row.querySelectorAll('[name]'), function (field) {
            field.setAttribute(
                'name',
                field.getAttribute('name').replace(/^rules\[\d+\]/, 'rules[' + index + ']')
            );
        });
    }

    function clearRow(row) {
        Array.prototype.forEach.call(row.querySelectorAll('input[type="text"]'), function (field) {
            field.value = '';
        });

        var checkbox = row.querySelector('.foun10-rules__active');
        var hidden = row.querySelector('input[type="hidden"]');

        if (checkbox) {
            checkbox.checked = true;
        }

        if (hidden) {
            hidden.value = '1';
        }
    }

    function addRow() {
        var existing = rows();

        if (existing.length === 0) {
            return null;
        }

        var row = existing[existing.length - 1].cloneNode(true);

        reindexRow(row, nextIndex++);
        clearRow(row);
        existing[existing.length - 1].parentNode.appendChild(row);

        var term = row.querySelector('input[type="text"]');

        if (term) {
            term.focus();
        }

        return row;
    }

    function removeRow(row) {
        // The last row stays; emptying it is the same thing to the server,
        // which drops incomplete rules, and it leaves somewhere to type.
        if (rows().length <= 1) {
            clearRow(row);
            return;
        }

        row.parentNode.removeChild(row);
        dirty = true;
    }

    /**
     * A checkbox is only ever a control here - the hidden field beside it is
     * what the form actually posts.
     */
    function syncActive(checkbox) {
        var row = checkbox.closest('.foun10-rules__row');
        var hidden = row ? row.querySelector('input[type="hidden"]') : null;

        if (hidden) {
            hidden.value = checkbox.checked ? '1' : '0';
        }
    }

    /**
     * Covers the screen until the saved page paints.
     *
     * Saving reloads, and between the click and the new page the screen simply
     * sits there looking untouched - which reads as a button that did nothing.
     */
    function showOverlay() {
        var overlay = document.getElementById('foun10SynonymSaveOverlay');

        if (overlay) {
            overlay.hidden = false;
        }
    }

    function bind(options) {
        var form = document.getElementById('foun10SynonymForm');
        var add = document.getElementById('foun10SynonymAdd');
        var language = document.getElementById('foun10SynonymLanguage');

        if (!form) {
            return;
        }

        form.addEventListener('click', function (event) {
            var remove = event.target.closest('.foun10-rules__remove');

            if (remove) {
                event.preventDefault();
                removeRow(remove.closest('.foun10-rules__row'));
            }
        });

        form.addEventListener('change', function (event) {
            dirty = true;

            if (event.target.classList.contains('foun10-rules__active')) {
                syncActive(event.target);
            }
        });

        form.addEventListener('input', function () {
            dirty = true;
        });

        // Submitting is not losing anything, so the language switch must stop
        // warning once the form is on its way.
        form.addEventListener('submit', function () {
            dirty = false;
            showOverlay();
        });

        // Both, on purpose: the admin's own scripts submit forms named myedit
        // through form.submit(), which fires no submit event.
        var save = document.getElementById('foun10SynonymSave');

        if (save) {
            save.addEventListener('click', showOverlay);
        }

        // Coming back through the browser's history cache would otherwise show
        // the page still covered.
        window.addEventListener('pageshow', function () {
            var overlay = document.getElementById('foun10SynonymSaveOverlay');

            if (overlay) {
                overlay.hidden = true;
            }
        });

        if (add) {
            add.addEventListener('click', function (event) {
                event.preventDefault();
                addRow();
            });
        }

        if (language) {
            language.addEventListener('change', function () {
                if (dirty && !window.confirm(options.labels.unsaved)) {
                    language.value = language.getAttribute('data-current');

                    return;
                }

                // Submitted through the form rather than as a constructed URL:
                // the form already carries the session token and the class, and
                // an empty fnc means the controller renders instead of saving.
                var target = form.querySelector('input[name="synonymLang"]');
                var fnc = form.querySelector('input[name="fnc"]');

                if (target) {
                    target.value = language.value;
                }

                if (fnc) {
                    fnc.value = '';
                }

                // form.submit() fires no submit event, so the guard that
                // normally clears this on the way out never runs - and neither
                // does the overlay.
                dirty = false;
                showOverlay();
                form.submit();
            });

            language.setAttribute('data-current', language.value);
        }
    }

    window.foun10InitSynonyms = function (options) {
        options = options || {};
        options.labels = options.labels || {};

        nextIndex = findNextIndex();

        // The rendered checkboxes and their hidden twins start out agreeing;
        // this keeps them that way if a browser restores a checkbox state on a
        // back navigation without firing a change event.
        rows().forEach(function (row) {
            var checkbox = row.querySelector('.foun10-rules__active');

            if (checkbox) {
                syncActive(checkbox);
            }
        });

        bind(options);
    };
}(window, document));
