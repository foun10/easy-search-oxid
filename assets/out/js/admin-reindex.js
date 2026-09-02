/**
 * Drives a reindex from the browser, one tick at a time.
 *
 * A web request cannot rebuild a 150k catalogue, so the server does a slice per
 * call and the cursor lives here. That is what keeps the endpoint stateless:
 * closing the tab halfway through leaves nothing behind to clean up, and
 * nothing to resume either.
 *
 * Which phases run is the button's decision, not this file's. Every button
 * carries data-phases, and the chain is walked in that order for each language
 * of the shop. The attribute screen asks for what its own settings affect; the
 * index screen offers each phase on its own and one button for all of them.
 *
 * Phases come in two shapes:
 *  - clear and index are batched, so they repeat until the server says finished;
 *  - category and dictionary are one call each.
 */
(function (window, document) {
    'use strict';

    var BATCHED = ['clear', 'index'];

    function format(template, values) {
        var index = 0;

        return String(template || '').replace(/%(\d+\$)?s/g, function (match, position) {
            var i = position ? parseInt(position, 10) - 1 : index++;

            return values[i] !== undefined ? values[i] : '';
        });
    }

    function parseJson(value, fallback) {
        try {
            return JSON.parse(value || '') || fallback;
        } catch (error) {
            return fallback;
        }
    }

    window.foun10InitReindex = function (options) {
        options = options || {};

        var labels = options.labels || {};
        var modal = document.getElementById('foun10ReindexModal');
        var status = document.getElementById('foun10ReindexStatus');
        var bar = document.getElementById('foun10ReindexBar');
        var close = document.getElementById('foun10ReindexClose');
        var spinner = document.getElementById('foun10ReindexSpinner');
        var buttons = Array.prototype.slice.call(document.querySelectorAll('[data-reindex]'));

        if (!modal || !buttons.length) {
            return;
        }

        function setBar(done, total) {
            bar.style.width = (total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0) + '%';
        }

        function finish(text, failed) {
            status.textContent = text;
            modal.classList.toggle('is-failed', !!failed);

            if (spinner) {
                spinner.hidden = true;
            }

            close.disabled = false;
        }

        /**
         * What to say once a run is over.
         *
         * Only a run that indexed documents can talk about articles - saying
         * "0 Artikel indiziert" after rebuilding the categories is worse than
         * saying nothing, because it reads as a rebuild that found nothing.
         */
        function doneText(state) {
            if (state.hasIndex) {
                return format(labels.done, [state.totalDone]);
            }

            var parts = [];

            if (state.results.category !== null) {
                parts.push(format(labels.doneCategories, [state.results.category]));
            }

            if (state.results.dictionary !== null) {
                parts.push(format(labels.doneDictionary, [state.results.dictionary]));
            }

            return parts.length ? parts.join(' ') : (labels.doneSimple || '');
        }

        /**
         * The label for a phase that has no progress of its own to report.
         */
        function phaseLabel(phase) {
            if (phase === 'clear') {
                return labels.clearing || '';
            }

            if (phase === 'category') {
                return labels.categories || '';
            }

            if (phase === 'dictionary') {
                return labels.dictionary || '';
            }

            return '';
        }

        function currentPhase(state) {
            return state.phases[state.phaseIndex];
        }

        /**
         * Moves to the next phase of this language, or to the next language.
         */
        function advance(state) {
            state.phaseIndex++;

            if (state.phaseIndex < state.phases.length) {
                state.lastId = '';
                status.textContent = phaseLabel(currentPhase(state));
                tick(state);

                return;
            }

            state.totalDone += state.done;
            state.scopeIndex++;

            if (state.scopeIndex >= state.scopes.length) {
                setBar(1, 1);

                // A refusal already put its own explanation on screen and is the
                // thing worth reading; overwriting it with "done" would hide the
                // one message the run existed to deliver.
                if (state.refused) {
                    close.disabled = false;

                    if (spinner) {
                        spinner.hidden = true;
                    }

                    return;
                }

                finish(doneText(state), false);

                return;
            }

            startScope(state);
        }

        // How long one tick should take. Most of a tick is the shop booting, so
        // a short target spends the run on boots; a long one risks the request
        // limit and makes the progress bar look stuck. Four seconds keeps the
        // boot share small and stays far inside any sane max_execution_time.
        var TICK_TARGET_MS = 4000;
        var BATCH_MIN = 50;
        var BATCH_MAX = 2000;

        /**
         * The batch size for the next tick, from how long the last one took.
         *
         * Growth is capped at double per step: the first ticks of a scope are
         * the fastest ones - nothing is written yet, no index to update - and
         * sizing the whole run from them would overshoot badly.
         */
        function nextBatchSize(current, elapsedMs) {
            if (!elapsedMs) {
                return current;
            }

            var scaled = Math.round(current * (TICK_TARGET_MS / elapsedMs));

            scaled = Math.min(scaled, current * 2);
            scaled = Math.max(scaled, Math.round(current / 2));

            return Math.max(BATCH_MIN, Math.min(BATCH_MAX, scaled));
        }

        function tick(state) {
            var phase = currentPhase(state);
            var body = new window.URLSearchParams();
            var startedAt = window.Date.now();

            body.append('cl', state.cl);
            body.append('fnc', 'reindexTick');
            body.append('phase', phase);
            body.append('langId', state.langId);
            body.append('lastId', state.lastId);
            body.append('done', state.done);
            body.append('total', state.total);
            body.append('batchSize', state.batchSize);

            window.fetch(state.url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
                .then(function (response) {
                    return response.json();
                })
                .then(function (payload) {
                    if (!payload || !payload.ok) {
                        finish(format(labels.failed, [(payload && payload.message) || '?']), true);

                        return;
                    }

                    // A refused category rebuild is not a failure - the previous
                    // assignments are still serving - but it must be visible, or
                    // an import collision looks like a clean run.
                    if (payload.phase === 'category') {
                        state.results.category = (state.results.category || 0) + (payload.categories || 0);

                        if (payload.published === false) {
                            status.textContent = payload.message || '';
                            modal.classList.add('is-failed');
                            state.refused = true;
                        }
                    }

                    if (payload.phase === 'dictionary') {
                        state.results.dictionary = (state.results.dictionary || 0) + (payload.terms || 0);
                    }

                    if (payload.phase === 'index') {
                        state.done = payload.done;
                        state.total = payload.total;
                        state.lastId = payload.lastId;

                        // Tuned against the size the server actually used, which
                        // is the requested one clamped to what it allows.
                        state.batchSize = nextBatchSize(
                            payload.batchSize || state.batchSize,
                            window.Date.now() - startedAt
                        );

                        setBar(state.done, state.total);
                        status.textContent = format(labels.progress, [state.done, state.total, state.langId]);
                    }

                    if (BATCHED.indexOf(payload.phase) !== -1 && !payload.finished) {
                        tick(state);

                        return;
                    }

                    advance(state);
                })
                .catch(function (error) {
                    finish(format(labels.failed, [(error && error.message) || '?']), true);
                });
        }

        function startScope(state) {
            state.langId = state.scopes[state.scopeIndex].langId;
            state.phaseIndex = 0;
            state.lastId = '';
            state.done = 0;
            state.total = 0;

            status.textContent = phaseLabel(currentPhase(state));
            tick(state);
        }

        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                var scopes = parseJson(button.getAttribute('data-langs'), []);
                var phases = (button.getAttribute('data-phases') || '').split(',').filter(Boolean);

                if (!scopes.length || !phases.length) {
                    return;
                }

                // Only the document pass reports progress per batch; without
                // it there is nothing to fill a bar with.
                var hasIndex = phases.indexOf('index') !== -1;

                modal.hidden = false;
                modal.classList.remove('is-failed');
                modal.classList.toggle('is-busy', !hasIndex);
                close.disabled = true;
                bar.style.width = '0%';
                status.textContent = '';

                if (spinner) {
                    spinner.hidden = hasIndex;
                }

                startScope({
                    url: button.getAttribute('data-url') || '',
                    cl: button.getAttribute('data-cl') || '',
                    scopes: scopes,
                    phases: phases,
                    hasIndex: hasIndex,
                    results: { category: null, dictionary: null },
                    refused: false,
                    scopeIndex: 0,
                    phaseIndex: 0,
                    totalDone: 0,
                    langId: 0,
                    lastId: '',
                    done: 0,
                    total: 0,
                    // Starting point only: the first tick times itself and the
                    // size follows the machine from there.
                    batchSize: 200
                });
            });
        });

        // The language switch reloads the screen with the chosen scope. Sent
        // through its form rather than as a constructed URL: the form already
        // carries the session token and the class, and an empty fnc means the
        // controller renders instead of doing anything.
        var language = document.querySelector('[data-language-switch]');

        if (language) {
            language.addEventListener('change', function () {
                var form = language.closest('form');
                var target = form && form.querySelector('input[name="indexLang"]');

                if (!form) {
                    return;
                }

                if (target) {
                    target.value = language.value;
                }

                form.submit();
            });
        }

        close.addEventListener('click', function () {
            modal.hidden = true;
            // The shop reads the new index immediately; reloading also refreshes
            // the counts and the sample values the rebuild may have changed.
            window.location.reload();
        });
    };
}(window, document));
