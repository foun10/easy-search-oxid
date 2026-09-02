/**
 * Drag and drop for foun10 -> Suche -> Attribute.
 *
 * Two lists: unconfigured attributes and configured ones. The two roles - filter
 * and searchable - are checkboxes on each configured row rather than separate
 * lists, because an attribute is very often both and cannot sit in two lists at
 * once.
 *
 * Order within the configured list is meaningful: it is the order the facet
 * sidebar renders in the shop.
 *
 * Plain HTML5 drag and drop on purpose. The one other module with a sortable
 * admin vendors jQuery, jQuery UI and nestedSortable to get it; that is a lot of
 * third party code to carry for two lists of short rows.
 *
 * The three hidden fields are the source of truth on submit: the server reads
 * the complete arrangement from them rather than diffing anything.
 */
(function (window, document) {
    'use strict';

    var DRAG_CLASS = 'is-dragging';
    var OVER_CLASS = 'is-over';

    function lists() {
        return Array.prototype.slice.call(document.querySelectorAll('.foun10-admin__list'));
    }

    function itemsOf(list) {
        return Array.prototype.slice.call(list.querySelectorAll('.foun10-admin__item'));
    }

    function configuredList() {
        return document.querySelector('.foun10-admin__list[data-list="configured"]');
    }

    function unusedList() {
        return document.querySelector('.foun10-admin__list[data-list="unused"]');
    }

    function isChecked(item, role) {
        var box = item.querySelector('input[data-role="' + role + '"]');

        return !!(box && box.checked);
    }

    function setField(id, value) {
        var field = document.getElementById(id);

        if (field) {
            field.value = value;
        }
    }

    function syncFields() {
        var list = configuredList();

        if (!list) {
            return;
        }

        var order = [];
        var facets = [];
        var searchable = [];

        itemsOf(list).forEach(function (item) {
            var id = item.getAttribute('data-id');
            order.push(id);

            if (isChecked(item, 'facet')) {
                facets.push(id);
            }

            if (isChecked(item, 'searchable')) {
                searchable.push(id);
            }
        });

        setField('foun10OrderIds', order.join(','));
        setField('foun10FacetIds', facets.join(','));
        setField('foun10EasySearchableIds', searchable.join(','));
    }

    function parseData(list, name, fallback) {
        try {
            return JSON.parse((list && list.getAttribute(name)) || '') || fallback;
        } catch (error) {
            return fallback;
        }
    }

    /**
     * The per attribute settings: how the facet renders, and what the customer
     * sees it called in each language.
     *
     * These post as ordinary named fields, so they only have to exist inside
     * the row - moving the row moves them, and dragging one out removes them
     * with it.
     */
    function decorateConfig(item, labels) {
        if (item.querySelector('.foun10-admin__config')) {
            return;
        }

        var id = item.getAttribute('data-id');
        var config = document.createElement('span');
        config.className = 'foun10-admin__config';

        var displayField = document.createElement('label');
        displayField.className = 'foun10-admin__config-field';

        var displayCaption = document.createElement('span');
        displayCaption.className = 'foun10-admin__config-label';
        displayCaption.textContent = labels.display;
        displayField.appendChild(displayCaption);

        var select = document.createElement('select');
        select.className = 'foun10-admin__display';
        select.name = 'display[' + id + ']';

        labels.displays.forEach(function (mode) {
            var option = document.createElement('option');
            option.value = mode.value;
            option.textContent = mode.label;
            select.appendChild(option);
        });

        displayField.appendChild(select);
        config.appendChild(displayField);

        labels.languages.forEach(function (language) {
            var field = document.createElement('label');
            field.className = 'foun10-admin__config-field';

            var caption = document.createElement('span');
            caption.className = 'foun10-admin__config-label';
            caption.textContent = labels.title + ' ' + language.label;
            field.appendChild(caption);

            var input = document.createElement('input');
            input.type = 'text';
            input.className = 'foun10-admin__title';
            input.name = 'title[' + id + '][' + language.id + ']';
            input.placeholder = labels.titlePlaceholder;
            field.appendChild(input);

            config.appendChild(field);
        });

        item.appendChild(config);
    }

    /**
     * Rows only exist in the configured list with their checkboxes, so moving
     * between lists adds or strips that part of the row.
     */
    function decorate(item, labels) {
        if (item.querySelector('.foun10-admin__roles')) {
            decorateConfig(item, labels);

            return;
        }

        var roles = document.createElement('span');
        roles.className = 'foun10-admin__roles';

        [['facet', labels.facet], ['searchable', labels.searchable]].forEach(function (pair) {
            var label = document.createElement('label');
            label.className = 'foun10-admin__role';

            var box = document.createElement('input');
            box.type = 'checkbox';
            box.setAttribute('data-role', pair[0]);
            // Dropped in to be used, so start with both roles on; unticking is
            // quicker than hunting for the one that was meant.
            box.checked = true;

            label.appendChild(box);
            label.appendChild(document.createTextNode(' ' + pair[1]));
            roles.appendChild(label);
        });

        item.appendChild(roles);
        decorateConfig(item, labels);
        item.classList.add('foun10-admin__item--configured');
    }

    function strip(item) {
        var roles = item.querySelector('.foun10-admin__roles');

        if (roles) {
            roles.remove();
        }

        // Dropped back out of the list, so its settings go with it - leaving
        // the fields behind would post a label for an attribute that is no
        // longer configured.
        var config = item.querySelector('.foun10-admin__config');

        if (config) {
            config.remove();
        }

        item.classList.remove('foun10-admin__item--configured');
    }

    /**
     * The item the dragged one should be inserted before, decided by which half
     * of each row the pointer is in.
     */
    function insertionPoint(list, y) {
        var candidates = itemsOf(list).filter(function (item) {
            return !item.classList.contains(DRAG_CLASS);
        });

        for (var i = 0; i < candidates.length; i++) {
            var box = candidates[i].getBoundingClientRect();

            if (y < box.top + box.height / 2) {
                return candidates[i];
            }
        }

        return null;
    }

    function clearEmptyPlaceholder(list) {
        var placeholder = list.querySelector('.foun10-admin__empty');

        if (placeholder) {
            placeholder.remove();
        }
    }

    function init() {
        var dragged = null;
        // Translated in the template; scraping them out of an existing row would
        // break on an empty list.
        var target = configuredList();
        var labels = {
            facet: (target && target.getAttribute('data-label-facet')) || 'Filter',
            searchable: (target && target.getAttribute('data-label-searchable')) || 'Durchsuchbar',
            display: (target && target.getAttribute('data-label-display')) || 'Darstellung',
            title: (target && target.getAttribute('data-label-title')) || 'Label',
            titlePlaceholder: (target && target.getAttribute('data-title-placeholder')) || '',
            displays: parseData(target, 'data-displays', [{ value: 'default', label: 'Standard' }]),
            languages: parseData(target, 'data-languages', [])
        };

        lists().forEach(function (list) {
            list.addEventListener('dragover', function (event) {
                if (!dragged) {
                    return;
                }

                // Without this the drop event never fires.
                event.preventDefault();
                list.classList.add(OVER_CLASS);
                clearEmptyPlaceholder(list);

                var before = insertionPoint(list, event.clientY);

                if (before) {
                    list.insertBefore(dragged, before);
                } else {
                    list.appendChild(dragged);
                }
            });

            list.addEventListener('dragleave', function () {
                list.classList.remove(OVER_CLASS);
            });

            list.addEventListener('drop', function (event) {
                event.preventDefault();
                list.classList.remove(OVER_CLASS);

                if (dragged) {
                    if (list === configuredList()) {
                        decorate(dragged, labels);
                    } else {
                        strip(dragged);
                    }
                }

                syncFields();
            });
        });

        document.addEventListener('dragstart', function (event) {
            var item = event.target.closest ? event.target.closest('.foun10-admin__item') : null;

            if (!item) {
                return;
            }

            dragged = item;
            item.classList.add(DRAG_CLASS);

            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                // Firefox refuses to start a drag without payload.
                event.dataTransfer.setData('text/plain', item.getAttribute('data-id') || '');
            }
        });

        document.addEventListener('dragend', function () {
            if (dragged) {
                dragged.classList.remove(DRAG_CLASS);
                dragged = null;
            }

            lists().forEach(function (list) {
                list.classList.remove(OVER_CLASS);
            });

            syncFields();
        });

        // Unticking both roles means the attribute is no longer configured, so
        // it returns to the left hand list rather than sitting there as a row
        // that saves nothing.
        document.addEventListener('change', function (event) {
            var box = event.target;

            if (!box.getAttribute || !box.getAttribute('data-role')) {
                return;
            }

            var item = box.closest('.foun10-admin__item');

            if (item && !isChecked(item, 'facet') && !isChecked(item, 'searchable')) {
                strip(item);
                unusedList().appendChild(item);
            }

            syncFields();
        });

        // Saving reloads the page, and rebuilding the list with its preview
        // values takes a moment. Without a marker the screen just sits there
        // looking unchanged, so cover it until the new page paints.
        var form = document.getElementById('foun10EasySearchAttributeForm');
        var save = document.getElementById('foun10Save');
        var overlay = document.getElementById('foun10SaveOverlay');

        if (form && overlay) {
            var show = function () {
                overlay.hidden = false;
            };

            // Both, on purpose: the admin's own scripts submit forms named
            // myedit through form.submit(), which fires no submit event.
            form.addEventListener('submit', show);

            if (save) {
                save.addEventListener('click', show);
            }

            // Coming back through the browser's history cache would otherwise
            // show the page still covered.
            window.addEventListener('pageshow', function () {
                overlay.hidden = true;
            });
        }

        // Populate the fields once up front, so submitting without dragging
        // anything saves the arrangement as it stands rather than wiping it.
        syncFields();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);
