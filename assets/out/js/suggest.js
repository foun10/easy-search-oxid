/**
 * Suggest dropdown for the shop search box.
 *
 * Talks to index.php?cl=foun10easysearchsuggest, which answers with
 * {terms: [], products: []}. The endpoint returns IDs resolved into real
 * article data server-side, so everything here only has to render.
 *
 * Deliberately dependency-free: the themes load Bootstrap and jQuery, but the
 * search box sits in the header on every single page and should not wait for
 * either.
 *
 * Every failure path ends in a closed dropdown rather than an error - the
 * customer can always ignore it and submit the form.
 */
(function (window, document) {
    'use strict';

    function createElement(tag, className, text) {
        var node = document.createElement(tag);

        if (className) {
            node.className = className;
        }

        // textContent, never innerHTML: titles come straight from the catalogue.
        if (text !== undefined && text !== null) {
            node.textContent = text;
        }

        return node;
    }

    function buildTerms(terms, label, onPick) {
        if (!terms.length) {
            return null;
        }

        var column = createElement('div', 'foun10-suggest__group');
        column.appendChild(createElement('div', 'foun10-suggest__label', label));

        terms.forEach(function (term) {
            var row = createElement('button', 'foun10-suggest__term', term);
            row.type = 'button';
            row.setAttribute('role', 'option');
            row.addEventListener('click', function () {
                onPick(term);
            });
            column.appendChild(row);
        });

        return column;
    }

    function buildProducts(products, label) {
        if (!products.length) {
            return null;
        }

        var column = createElement('div', 'foun10-suggest__col foun10-suggest__col--products');
        column.appendChild(createElement('div', 'foun10-suggest__label', label));

        products.forEach(function (product) {
            var row = createElement('a', 'foun10-suggest__product');
            row.href = product.url;
            row.setAttribute('role', 'option');

            var thumb = createElement('span', 'foun10-suggest__thumb');

            if (product.image) {
                var image = document.createElement('img');
                image.src = product.image;
                image.alt = '';
                image.loading = 'lazy';
                thumb.appendChild(image);
            }

            row.appendChild(thumb);

            var body = createElement('span', 'foun10-suggest__body');

            if (product.brand) {
                body.appendChild(createElement('span', 'foun10-suggest__brand', product.brand));
            }

            body.appendChild(createElement('span', 'foun10-suggest__title', product.title));
            row.appendChild(body);

            // A reduced article shows the pair the product box shows: what it
            // costs now in the sale colour, and what it cost struck through.
            var price = createElement('span', 'foun10-suggest__price');

            if (product.oldPrice) {
                price.className += ' foun10-suggest__price--sale';
                price.appendChild(createElement('span', 'foun10-suggest__price-now', product.price));
                price.appendChild(createElement('span', 'foun10-suggest__price-old', product.oldPrice));
            } else {
                price.appendChild(document.createTextNode(product.price));
            }

            row.appendChild(price);

            column.appendChild(row);
        });

        return column;
    }

    function buildCategories(categories, label) {
        if (!categories.length) {
            return null;
        }

        var group = createElement('div', 'foun10-suggest__group');
        group.appendChild(createElement('div', 'foun10-suggest__label', label));

        categories.forEach(function (category) {
            var row = createElement('a', 'foun10-suggest__category');
            row.href = category.url;
            row.setAttribute('role', 'option');

            row.appendChild(createElement('span', 'foun10-suggest__category-title', category.title));

            // The path disambiguates categories that share a name.
            if (category.path) {
                row.appendChild(createElement('span', 'foun10-suggest__category-path', category.path));
            }

            group.appendChild(row);
        });

        return group;
    }

    function buildFooter(payload, label) {
        if (!payload.allUrl || !payload.total) {
            return null;
        }

        var footer = createElement('div', 'foun10-suggest__footer');
        var link = createElement('a', 'foun10-suggest__all', label.replace('%s', payload.total));
        link.href = payload.allUrl;
        footer.appendChild(link);

        return footer;
    }

    /**
     * @param {Object} options suggestId, inputId, url, param, minLength, delay, labels
     */
    window.foun10InitSuggest = function (options) {
        var box = document.getElementById(options.suggestId);
        var input = document.getElementById(options.inputId);

        if (!box || !input) {
            return;
        }

        var form = input.form;
        var labels = options.labels || {};
        var minLength = options.minLength || 2;
        var delay = options.delay || 150;
        var timer = null;
        var controller = null;
        // Which term the box currently shows, so refocusing can decide between
        // reopening what is already rendered and asking again.
        var renderedTerm = null;
        var pendingTerm = null;

        function close() {
            box.hidden = true;
            input.setAttribute('aria-expanded', 'false');
        }

        function hasRenderedContent() {
            return box.childNodes.length > 0;
        }

        /**
         * On a narrow screen the dropdown spans the viewport rather than the
         * search box, and that cannot be done in CSS alone: breaking out of the
         * box with viewport units only lands correctly if the box happens to be
         * centred, and taking the positioning context away instead leaves
         * nothing to anchor the top to. So it is fixed to the viewport and told
         * where the input ends.
         *
         * Re-measured on every open because the header moves - it collapses on
         * scroll in most themes.
         */
        function positionForNarrowScreens() {
            if (!window.matchMedia('(max-width: 767.98px)').matches) {
                box.style.top = '';

                return;
            }

            box.style.top = Math.round(input.getBoundingClientRect().bottom) + 'px';
        }

        function open() {
            box.hidden = false;
            positionForNarrowScreens();
            input.setAttribute('aria-expanded', 'true');
        }

        function submitWith(term) {
            input.value = term;

            if (form) {
                form.submit();
            }
        }

        function render(payload) {
            renderedTerm = pendingTerm;
            box.textContent = '';

            var inner = createElement('div', 'foun10-suggest__inner');
            var terms = buildTerms(payload.terms || [], labels.terms || '', submitWith);
            var categories = buildCategories(payload.categories || [], labels.categories || '');
            var products = buildProducts(payload.products || [], labels.products || '');

            if (!terms && !categories && !products) {
                close();

                return;
            }

            // Terms and categories share the left column.
            if (terms || categories) {
                var side = createElement('div', 'foun10-suggest__col foun10-suggest__col--terms');

                if (terms) {
                    side.appendChild(terms);
                }

                if (categories) {
                    side.appendChild(categories);
                }

                inner.appendChild(side);
            }

            if (products) {
                inner.appendChild(products);
            }

            box.appendChild(inner);

            var footer = buildFooter(payload, labels.all || '');

            if (footer) {
                box.appendChild(footer);
            }

            open();
        }

        function request(term) {
            pendingTerm = term;

            // Drop the previous request: typing fast otherwise races slow
            // answers on top of newer ones.
            if (controller) {
                controller.abort();
            }

            controller = new AbortController();

            window.fetch(options.url + '&' + options.param + '=' + encodeURIComponent(term), {
                signal: controller.signal,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
                .then(function (response) {
                    return response.json();
                })
                .then(render)
                .catch(function () {
                    // Aborted or failed - leave the form alone.
                });
        }

        input.setAttribute('autocomplete', 'off');
        input.setAttribute('aria-expanded', 'false');

        input.addEventListener('input', function () {
            var term = input.value.trim();

            window.clearTimeout(timer);

            if (term.length < minLength) {
                close();

                return;
            }

            timer = window.setTimeout(function () {
                request(term);
            }, delay);
        });

        input.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                close();
            }
        });

        // Coming back to the field should bring back what was on screen before,
        // rather than making the customer retype to see it again.
        input.addEventListener('focus', function () {
            var term = input.value.trim();

            if (term.length < minLength) {
                return;
            }

            if (term === renderedTerm && hasRenderedContent()) {
                open();

                return;
            }

            // The field changed since the last render (or nothing was rendered
            // yet), so what we have is stale.
            request(term);
        });

        document.addEventListener('click', function (event) {
            if (!box.contains(event.target) && event.target !== input) {
                close();
            }
        });
    };
})(window, document);
