/**
 * Filter panel without a page reload.
 *
 * The panel is rendered by the server and every value is a link, which is what
 * a customer without JavaScript still gets: one click, one reload, one narrowed
 * list. This takes over from there. Clicks are intercepted, the selection is
 * kept in the DOM, and index.php?cl=foun10easysearchfacets is asked what would
 * still be reachable - so choosing three filters costs three small JSON
 * requests instead of three full page renders, and the list behind the panel is
 * loaded once, when the customer applies.
 *
 * What comes back is availability, not numbers: a value that leads nowhere is
 * taken off the panel, and the only figure on screen is the total on the apply
 * button. That is deliberate - per value counts are the one thing the two
 * search engines disagree about, and the one thing this interaction can do
 * without.
 *
 * The panel also **grows**. A page loaded with a filter already applied was
 * rendered by the server with only the values reachable under it, so widening
 * the selection asks for values that were never in the markup - a size that
 * does not exist in the colour the customer arrived with, for instance. Every
 * value the endpoint reports is therefore created when it is missing, group and
 * all, from what the payload carries: id, label, colour.
 */
(function () {
    'use strict';

    var DEBOUNCE_MS = 120;

    function foun10InitFacets(options) {
        var panel = document.getElementById(options.panelId || 'searchFacets');

        if (!panel || panel.dataset.foun10FacetsReady === '1') {
            return;
        }

        panel.dataset.foun10FacetsReady = '1';

        var state = {
            panel: panel,
            options: options,
            timer: null,
            controller: null,
            applyButton: panel.querySelector('.foun10-facets__apply-button')
        };

        panel.addEventListener('click', function (event) {
            var item = event.target.closest('[data-value]');

            if (!item || !panel.contains(item)) {
                return;
            }

            event.preventDefault();

            if (item.classList.contains('disabled')) {
                return;
            }

            item.classList.toggle('active');
            updateSelectedBadge(item.closest('.foun10-facet'));
            schedule(state);
        });

        if (state.applyButton) {
            state.applyButton.addEventListener('click', function () {
                window.location.href = buildApplyUrl(collectSelection(panel));
            });
        }
    }

    /**
     * Selected values per attribute, read off the DOM rather than kept beside
     * it - the markup is the state, so a class toggled anywhere stays in step.
     */
    function collectSelection(panel) {
        var selection = {};

        panel.querySelectorAll('.foun10-facet[data-facet-id]').forEach(function (group) {
            var attributeId = group.dataset.facetId;

            group.querySelectorAll('[data-value].active').forEach(function (item) {
                selection[attributeId] = selection[attributeId] || [];
                selection[attributeId].push(item.dataset.value);
            });
        });

        return selection;
    }

    function updateSelectedBadge(group) {
        if (!group) {
            return;
        }

        var badge = group.querySelector('.foun10-facet__selected');
        var count = group.querySelectorAll('[data-value].active').length;

        if (!badge) {
            return;
        }

        badge.textContent = count > 0 ? String(count) : '';
        badge.hidden = count === 0;
    }

    function schedule(state) {
        window.clearTimeout(state.timer);

        state.timer = window.setTimeout(function () {
            refresh(state);
        }, DEBOUNCE_MS);
    }

    function refresh(state) {
        var parameters = new URLSearchParams();
        var context = state.options.context || {};

        Object.keys(context).forEach(function (name) {
            if (context[name] !== '' && context[name] !== null) {
                parameters.append(name, context[name]);
            }
        });

        appendSelection(parameters, collectSelection(state.panel));

        // A click while a request is in flight makes that request pointless -
        // its answer describes a selection the customer has already left.
        if (state.controller) {
            state.controller.abort();
        }

        state.controller = new AbortController();
        state.panel.dataset.loading = '1';

        window.fetch(state.options.url + '&' + parameters.toString(), {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            signal: state.controller.signal
        }).then(function (response) {
            return response.ok ? response.json() : null;
        }).then(function (payload) {
            state.panel.dataset.loading = '0';

            if (payload) {
                apply(state, payload);
            }
        }).catch(function () {
            // An aborted or failed request leaves the panel as it is. The
            // selection is still valid and the apply button still navigates -
            // the customer only loses the availability hints.
            state.panel.dataset.loading = '0';
        });
    }

    function appendSelection(parameters, selection) {
        Object.keys(selection).forEach(function (attributeId) {
            selection[attributeId].forEach(function (valueId) {
                parameters.append('foun10filter[' + attributeId + '][]', valueId);
            });
        });
    }

    /**
     * The markup of one value, matching what the server renders.
     *
     * A span rather than a link: inside the panel a click is intercepted and
     * applied through the button at the bottom, so there is nothing for an href
     * to do. The colour tile rule is the template's - a value is a tile exactly
     * when it carries a hex code.
     */
    function createValue(value) {
        var item = document.createElement('span');

        // The module's own classes first, so the shipped stylesheet works in any
        // theme; variant-select__item and color-tile follow as an optional hook
        // for themes that already have a value picker of their own and would
        // rather reuse its styling than restyle these.
        item.className = 'foun10-facet__value variant-select__item'
            + (value.hex ? ' foun10-facet__value--color color-tile' : '');
        item.dataset.value = value.id;
        item.setAttribute('aria-label', value.label || '');
        item.setAttribute('title', value.label || '');

        if (value.hex) {
            item.style.backgroundColor = value.hex;
            item.innerHTML = '&nbsp;';

            return item;
        }

        var label = document.createElement('span');

        label.className = 'foun10-facet__label';
        label.textContent = value.label || '';
        item.appendChild(label);

        return item;
    }

    /**
     * A facet the server left out entirely, because none of its values were
     * reachable under the filter the page was loaded with.
     */
    function createGroup(panel, facet) {
        var container = panel.querySelector('.foun10-facets');

        if (!container) {
            return null;
        }

        var group = document.createElement('div');

        group.className = 'foun10-facet';
        group.dataset.facetId = facet.id;

        var head = document.createElement('div');

        head.className = 'foun10-facet__head';

        var title = document.createElement('span');

        title.className = 'foun10-facet__title';
        title.textContent = facet.title || '';
        head.appendChild(title);

        var selected = document.createElement('span');

        selected.className = 'foun10-facet__selected';
        selected.hidden = true;
        head.appendChild(selected);

        var values = document.createElement('div');

        values.className = 'foun10-facet__values variant-select';

        group.appendChild(head);
        group.appendChild(values);
        container.appendChild(group);

        return group;
    }

    /**
     * Adds the values of one facet that are not in the markup yet.
     *
     * Appended rather than sorted into place: the server ordered this list once
     * and re-ordering it under the customer's finger on every request would be
     * the more confusing of the two wrongs.
     */
    function addMissingValues(group, facet) {
        var values = group.querySelector('.foun10-facet__values');

        if (!values) {
            return;
        }

        (facet.values || []).forEach(function (value) {
            if (!value.available && !value.selected) {
                return;
            }

            if (group.querySelector('[data-value="' + value.id + '"]')) {
                return;
            }

            values.appendChild(createValue(value));
        });
    }

    function apply(state, payload) {
        var availability = {};

        (payload.facets || []).forEach(function (facet) {
            availability[facet.id] = { truncated: Boolean(facet.truncated), values: {} };

            (facet.values || []).forEach(function (value) {
                availability[facet.id].values[value.id] = value;
            });
        });

        // Grow first, hide second: a value that arrives in this answer has to
        // exist before the pass below decides whether it stays.
        (payload.facets || []).forEach(function (facet) {
            var group = state.panel.querySelector('.foun10-facet[data-facet-id="' + facet.id + '"]')
                || createGroup(state.panel, facet);

            if (group) {
                addMissingValues(group, facet);
            }
        });

        state.panel.querySelectorAll('.foun10-facet[data-facet-id]').forEach(function (group) {
            var values = availability[group.dataset.facetId];
            var visible = 0;

            group.querySelectorAll('[data-value]').forEach(function (item) {
                var value = values ? values.values[item.dataset.value] : null;

                // Absence normally means "leads nowhere" - the endpoint returns
                // every reachable value. It means nothing, though, when the
                // list was cut off at the display limit, so such a facet is
                // left exactly as the server rendered it.
                if (!value && values && values.truncated) {
                    visible += item.hidden ? 0 : 1;

                    return;
                }

                // A value the customer has picked stays, whatever the answer
                // says about it - taking away the control that switches a
                // filter off is the one thing this must never do.
                var keep = Boolean(value && value.available) || item.classList.contains('active');

                item.hidden = !keep;
                visible += keep ? 1 : 0;
            });

            // A headline with nothing under it is worse than no headline: the
            // whole group goes when its last reachable value does.
            group.hidden = visible === 0;
        });

        updateApplyButton(state, payload.total || 0);
    }

    function updateApplyButton(state, total) {
        if (!state.applyButton) {
            return;
        }

        var label = state.options.labels && state.options.labels.showResults
            ? state.options.labels.showResults
            : '%s';

        state.applyButton.textContent = label.replace('%s', formatNumber(total));
        state.applyButton.disabled = total === 0;
    }

    function formatNumber(value) {
        try {
            return new Intl.NumberFormat(document.documentElement.lang || undefined).format(value);
        } catch (error) {
            return String(value);
        }
    }

    /**
     * Where the apply button goes.
     *
     * Built from the address bar rather than from the server, so a SEO category
     * URL stays the SEO URL and everything the page already carries - sorting,
     * a search term, a price range - survives. Only the filters are rewritten,
     * and the page number is dropped: a changed selection always starts at page
     * one.
     */
    function buildApplyUrl(selection) {
        var url = new URL(window.location.href);
        var parameters = new URLSearchParams(url.search);

        Array.from(parameters.keys()).forEach(function (name) {
            if (name.indexOf('foun10filter') === 0 || name === 'pgNr') {
                parameters.delete(name);
            }
        });

        appendSelection(parameters, selection);

        var query = parameters.toString();

        return url.pathname + (query === '' ? '' : '?' + query) + url.hash;
    }

    window.foun10InitFacets = foun10InitFacets;
})();
