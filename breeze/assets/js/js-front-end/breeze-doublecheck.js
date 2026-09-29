/**
 * Breeze HTML Double-Check (DC) — standalone mirror of the inline runtime script.
 *
 * Fetches an uncached copy of this URL and copies selected subtrees into the
 * live (cached) DOM so fragments such as mini-cart or greetings stay fresh.
 * Dispatches breeze:dc:swapped, breeze:dc:skipped, and breeze:dc:done.
 *
 * Expects window.breezeDoublecheckElements and window.breezeDoublecheckCachedQueryKeys.
 * Runtime source of truth is Breeze_Doublecheck::get_script() (inline). Keep this file in sync.
 *
 * @return {void}
 */
function breezeDoublecheckOnLoad() {
    // Safety guard: prevent duplicate DC bootstrap on the same page load.
    // This does not limit selector processing; it only blocks a second full DC run.
    if (window.__breezeDcHasRun === true) {
        return;
    }
    window.__breezeDcHasRun = true;

    // Normalize selector input defensively so runtime logic always receives
    // a clean array of unique, non-empty CSS selector strings.
    var elementsReload = Array.isArray(breezeDoublecheckElements) ? breezeDoublecheckElements : [];
    elementsReload = elementsReload
        .map(function(selector) {
            return typeof selector === 'string' ? selector.trim() : '';
        })
        .filter(function(selector) {
            return selector !== '';
        })
        .filter(function(selector, index, selectors) {
            return selectors.indexOf(selector) === index;
        });

    // Cache Query Strings + built-in always-cache vars. Values come from the live URL.
    var cachedQueryPatterns = Array.isArray(window.breezeDoublecheckCachedQueryKeys) ? window.breezeDoublecheckCachedQueryKeys : [];
    cachedQueryPatterns = cachedQueryPatterns
        .map(function(pattern) {
            return typeof pattern === 'string' ? pattern.trim() : '';
        })
        .filter(function(pattern) {
            return pattern !== '' && pattern.toLowerCase() !== 'nocache';
        })
        .filter(function(pattern, index, patterns) {
            return patterns.indexOf(pattern) === index;
        });

    /**
     * Current epoch milliseconds.
     *
     * @return {number}
     */
    function getTimestamp() {
        if (Date.now) {
            return Date.now();
        }

        return new Date().getTime();
    }

    /**
     * Random hex token for nocache= and request_id. Prefers Web Crypto.
     *
     * @return {string}
     */
    function generateRequestToken() {
        if (window.crypto && window.crypto.getRandomValues) {
            var tokenArray = new Uint32Array(2);
            window.crypto.getRandomValues(tokenArray);
            return tokenArray[0].toString(16) + tokenArray[1].toString(16);
        }

        return Math.random().toString(16).slice(2) + getTimestamp().toString(16);
    }

    /**
     * Match a query key against an exact name or a Breeze wildcard (city(.*)).
     *
     * @param {string} key     Decoded query parameter name.
     * @param {string} pattern Allowlist entry from Cache Query Strings.
     * @return {boolean}
     */
    function matchesCachedQueryPattern(key, pattern) {
        if (key === pattern) {
            return true;
        }

        if (pattern.indexOf('(.*)') === -1) {
            return false;
        }

        // Escape regex metacharacters, then restore the Breeze (.*) wildcard.
        var placeholder = 'REG_EXP_ALL';
        var quoted = pattern.split('(.*)') .join(placeholder);
        var specials = '.*+?^$()[]{}|\\';
        var escaped = '';
        var i;
        for (i = 0; i < quoted.length; i++) {
            var ch = quoted.charAt(i);
            if (specials.indexOf(ch) !== -1) {
                escaped += '\\' + ch;
            } else {
                escaped += ch;
            }
        }
        quoted = escaped.split(placeholder).join('(.*)');

        try {
            return new RegExp('^' + quoted + '$').test(key);
        } catch (error) {
            return false;
        }
    }

    /**
     * True when this query key is a Breeze cache variant (allowlist / wildcard).
     * nocache is never forwarded; we append our own token later.
     *
     * @param {string} key Decoded query parameter name (foo[] stripped for matching).
     * @return {boolean}
     */
    function isAllowedCachedQueryKey(key) {
        if (typeof key !== 'string' || key === '') {
            return false;
        }

        if (key.toLowerCase() === 'nocache') {
            return false;
        }

        var baseKey = key;
        if (baseKey.length > 2 && baseKey.slice(-2) === '[]') {
            baseKey = baseKey.slice(0, -2);
        }

        var i;
        for (i = 0; i < cachedQueryPatterns.length; i++) {
            var pattern = cachedQueryPatterns[i];
            if (typeof pattern !== 'string' || pattern === '') {
                continue;
            }

            if (matchesCachedQueryPattern(key, pattern) || matchesCachedQueryPattern(baseKey, pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build origin+path + allowed current query pairs + nocache.
     * Keeps original pair encoding/order (repeats and foo[] included).
     *
     * @param {string} cacheBusterValue Token written to nocache=.
     * @return {string} Absolute URL for the DC XHR.
     */
    function buildDoublecheckRefreshUrl(cacheBusterValue) {
        var baseUrl = window.location.origin + window.location.pathname;
        var preservedPairs = [];
        var search = window.location.search ? window.location.search.substring(1) : '';

        if (search !== '') {
            var rawPairs = search.split('&');
            var p;
            for (p = 0; p < rawPairs.length; p++) {
                var rawPair = rawPairs[p];
                if (rawPair === '') {
                    continue;
                }

                var eqPos = rawPair.indexOf('=');
                // Keep the raw pair for the URL; only decode the key for allowlist matching.
                var rawKey = -1 === eqPos ? rawPair : rawPair.substring(0, eqPos);
                var decodedKey = rawKey;
                try {
                    decodedKey = decodeURIComponent(rawKey.split('+').join(' '));
                } catch (error) {
                    decodedKey = rawKey;
                }

                if (!isAllowedCachedQueryKey(decodedKey)) {
                    continue;
                }

                preservedPairs.push(rawPair);
            }
        }

        preservedPairs.push('nocache=' + encodeURIComponent(cacheBusterValue));
        return baseUrl + '?' + preservedPairs.join('&');
    }

    // Per-run metrics and flags.
    var dcRequestId = 'dc_' + generateRequestToken();
    var dcUrlPath = window.location.pathname || '/';
    var xhrTimeoutMs = 15000;
    var runStartedAt = getTimestamp();
    var swappedCount = 0;
    var skippedCount = 0;
    var doneEmitted = false;
    var selectorMismatchState = {};
    var selectorMismatchSuppressedCount = 0;

    /**
     * Sum mismatch hits across selectors (including suppressed repeats).
     *
     * @return {number}
     */
    function getSelectorMismatchTotal() {
        var total = 0;

        Object.keys(selectorMismatchState).forEach(function(selector) {
            total += selectorMismatchState[selector].count;
        });

        return total;
    }

    /**
     * First mismatch per selector emits breeze:dc:skipped; later repeats are counted only.
     *
     * @param {string} selector   CSS selector that failed to pair live vs fresh nodes.
     * @param {number} liveCount  Matches in the live document.
     * @param {number} freshCount Matches in the parsed fresh HTML.
     * @return {void}
     */
    function trackSelectorMismatch(selector, liveCount, freshCount) {
        var selectorKey = typeof selector === 'string' ? selector : '';

        if (!selectorMismatchState[selectorKey]) {
            selectorMismatchState[selectorKey] = {
                count: 0,
                notified: false
            };
        }

        selectorMismatchState[selectorKey].count++;

        if (selectorMismatchState[selectorKey].notified) {
            selectorMismatchSuppressedCount++;
            return;
        }

        selectorMismatchState[selectorKey].notified = true;
        emitSkipped('selector-mismatch', {
            scope: 'global',
            selector: selector,
            phase: 'swap',
            matched_live_count: liveCount,
            matched_fresh_count: freshCount,
            mismatch_count: selectorMismatchState[selectorKey].count,
            suppressed_repeats: false
        });
    }

    /**
     * Terminal event for this DC run. Always strips leftover loader overlay classes.
     *
     * @param {number|null} xhrStatus   XHR status, or null when no request ran.
     * @param {string}      doneReason  Short reason code on breeze:dc:done.
     * @param {Object}      extraDetail Optional extra keys merged into event.detail.
     * @return {void}
     */
    function emitDone(xhrStatus, doneReason, extraDetail) {
        if (doneEmitted) {
            return;
        }
        doneEmitted = true;

        var leftoverLoaders = document.querySelectorAll('.breeze-dc-elem');
        leftoverLoaders.forEach(function(element) {
            element.classList.remove('breeze-dc-elem');
        });

        var detail = {
            request_id: dcRequestId,
            url_path: dcUrlPath,
            ts: getTimestamp(),
            duration_ms: getTimestamp() - runStartedAt,
            swapped_count: swappedCount,
            skipped_count: skippedCount,
            xhr_status: typeof xhrStatus === 'number' ? xhrStatus : null,
            reason: doneReason,
            selector_mismatch_total: getSelectorMismatchTotal(),
            selector_mismatch_suppressed: selectorMismatchSuppressedCount,
            selector_mismatch_selectors: Object.keys(selectorMismatchState).length
        };

        if (extraDetail && typeof extraDetail === 'object') {
            Object.keys(extraDetail).forEach(function(key) {
                detail[key] = extraDetail[key];
            });
        }

        document.dispatchEvent(new CustomEvent('breeze:dc:done', {
            bubbles: true,
            detail: detail
        }));
    }

    /**
     * True when the selector SUBJECT is html, body, or head (body.home).
     * Descendant targets such as body .mini-cart are allowed.
     * Fresh HTML is a real Document (DOMParser), so those tags exist; swapping
     * them as the subject is still unsupported.
     *
     * @param {string} selector CSS selector from settings.
     * @return {boolean}
     */
    function isUnsupportedRootSelector(selector) {
        if (typeof selector !== 'string') {
            return false;
        }

        var normalized = selector.replace(/\s+/g, ' ').trim();
        if (normalized === '') {
            return false;
        }

        // Subject is the right-most compound after combinators (descendant, >, +, ~).
        var segments = normalized.split(/\s*(?:>|\+|~)\s*|\s+/);
        if (!segments.length) {
            return false;
        }

        var subject = segments[segments.length - 1];
        var match = subject.match(/^([A-Za-z][A-Za-z0-9_-]*)/);
        if (!match) {
            return false;
        }

        var tag = match[1].toLowerCase();
        return tag === 'html' || tag === 'body' || tag === 'head';
    }

    /**
     * Dispatch breeze:dc:skipped with a stable detail payload (reason, request_id, path).
     *
     * @param {string}  reason      Skip reason code.
     * @param {Object}  extraDetail Optional extra keys merged into event.detail.
     * @param {Element} target      Optional event target; defaults to document.
     * @return {void}
     */
    function emitSkipped(reason, extraDetail, target) {
        skippedCount++;

        var detail = {
            reason: reason,
            scope: 'global',
            request_id: dcRequestId,
            url_path: dcUrlPath,
            ts: getTimestamp()
        };

        if (extraDetail && typeof extraDetail === 'object') {
            Object.keys(extraDetail).forEach(function(key) {
                detail[key] = extraDetail[key];
            });
        }

        if (!detail.scope) {
            detail.scope = 'global';
        }

        var eventTarget = target && target.dispatchEvent ? target : document;
        eventTarget.dispatchEvent(new CustomEvent('breeze:dc:skipped', {
            bubbles: true,
            detail: detail
        }));
    }

    // Drop html/body/head subjects before any XHR. Other selectors keep running.
    var usableSelectors = [];
    elementsReload.forEach(function(selector) {
        if (isUnsupportedRootSelector(selector)) {
            emitSkipped('unsupported-root-selector', {
                selector: selector,
                phase: 'bootstrap'
            });
            return;
        }
        usableSelectors.push(selector);
    });
    elementsReload = usableSelectors;

    if (!elementsReload.length) {
        // Nothing usable to process: emit skip event for observability.
        emitSkipped('invalid-selectors', {
            selector_count: 0,
            phase: 'bootstrap'
        });
        emitDone(null, 'invalid-selectors');
        return;
    }

    /**
     * querySelectorAll wrapper. Invalid selectors skip instead of aborting the run.
     *
     * @param {ParentNode} root     Document or parsed container.
     * @param {string}     selector CSS selector.
     * @param {string}     phase    Label stored on skip events for debugging.
     * @return {NodeList|Array}
     */
    function queryElements(root, selector, phase) {
        try {
            if (!root || typeof root.querySelectorAll !== 'function') {
                return [];
            }
            return root.querySelectorAll(selector);
        } catch (error) {
            // Invalid selector must not crash the whole refresh flow.
            // Emit detail so integrations/debug tooling can track bad selectors.
            emitSkipped('invalid-selector', {
                selector: selector,
                phase: phase
            });
            return [];
        }
    }

    /**
     * Parse nocache HTML as a Document. Do not assign the full markup to innerHTML.
     *
     * @param {string} markup Fresh HTML from the XHR.
     * @return {Document|null}
     */
    function parseFreshHtmlDocument(markup) {
        if (typeof markup !== 'string' || typeof DOMParser === 'undefined') {
            return null;
        }

        try {
            return new DOMParser().parseFromString(markup, 'text/html');
        } catch (error) {
            return null;
        }
    }

    var hasLiveTargets = false;

    // Pre-check selectors against the live DOM.
    // We add the loader class only to elements that already exist on page.
    elementsReload.forEach(function(selector) {
        var elements = queryElements(document, selector, 'precheck');
        if (elements.length > 0) {
            hasLiveTargets = true;
        }
        elements.forEach(function(element) {
            element.classList.add("breeze-dc-elem");
        });
    });

    if (!hasLiveTargets) {
        // Avoid the extra network/render cost when current page has no matching targets.
        emitSkipped('no-targets', {
            selector_count: elementsReload.length,
            phase: 'precheck'
        });
        emitDone(null, 'no-targets');
        return;
    }

    // Use secure random cache-buster for the refresh request.
    // Fallback keeps compatibility where Web Crypto is unavailable.
    var randomValue = generateRequestToken();
    var urlWithCacheBuster = buildDoublecheckRefreshUrl(randomValue);

    // Request fresh uncached HTML, then copy only selected subtrees.
    var xhr = new XMLHttpRequest();
    // Fail-safe timeout: if the refresh call hangs, release loader state and continue.
    xhr.timeout = xhrTimeoutMs;
    xhr.ontimeout = function() {
        elementsReload.forEach(function(selector) {
            var elements = queryElements(document, selector, 'xhr-timeout-cleanup');
            elements.forEach(function(element) {
                element.classList.remove("breeze-dc-elem");
            });
        });
        emitSkipped('xhr-timeout', {
            phase: 'xhr',
            timeout_ms: xhrTimeoutMs,
            status: xhr.status
        });
        emitDone(xhr.status, 'xhr-timeout', {
            timeout_ms: xhrTimeoutMs
        });
    };
    xhr.onerror = function() {
        elementsReload.forEach(function(selector) {
            var elements = queryElements(document, selector, 'xhr-error-cleanup');
            elements.forEach(function(element) {
                element.classList.remove("breeze-dc-elem");
            });
        });
        emitSkipped('xhr-error', {
            phase: 'xhr',
            status: xhr.status
        });
        emitDone(xhr.status, 'xhr-error');
    };
    xhr.onabort = function() {
        elementsReload.forEach(function(selector) {
            var elements = queryElements(document, selector, 'xhr-abort-cleanup');
            elements.forEach(function(element) {
                element.classList.remove("breeze-dc-elem");
            });
        });
        emitSkipped('xhr-abort', {
            phase: 'xhr',
            status: xhr.status
        });
        emitDone(xhr.status, 'xhr-abort');
    };
    xhr.onreadystatechange = function() {
        if (xhr.readyState === XMLHttpRequest.DONE) {
            if (xhr.status === 200) {
                // Parse as a Document so unused page regions are not assigned via innerHTML.
                var freshDocument = parseFreshHtmlDocument(xhr.responseText);
                if (!freshDocument) {
                    emitSkipped('parse-error', {
                        phase: 'swap'
                    });
                    emitDone(xhr.status, 'parse-error');
                    return;
                }

                // For each selector, pair live nodes with fresh nodes by index.
                elementsReload.forEach(function(selector) {
                    var foundElementsInDOM = queryElements(document, selector, 'swap-live-dom');
                    var foundElementsInVirtualDOM = queryElements(freshDocument, selector, 'swap-fresh-dom');

                    if (foundElementsInDOM.length && foundElementsInVirtualDOM.length) {
                        foundElementsInDOM.forEach(function(elem, i) {
                            var freshElem = foundElementsInVirtualDOM[i];
                            if (!freshElem) {
                                emitSkipped('missing-fresh-node', {
                                    scope: 'element',
                                    selector: selector,
                                    index: i,
                                    phase: 'swap',
                                    matched_live_count: foundElementsInDOM.length,
                                    matched_fresh_count: foundElementsInVirtualDOM.length
                                }, elem);
                                return;
                            }

                            var nonceFields = Array.prototype.slice.call(elem.querySelectorAll('input[type="hidden"]'));
                            nonceFields = nonceFields.concat(Array.prototype.slice.call(freshElem.querySelectorAll('input[type="hidden"]')));
                            var containsNonce = Array.prototype.some.call(nonceFields, function(field) {
                                var fieldName = (field.getAttribute('name') || '').toLowerCase();
                                var fieldId = (field.getAttribute('id') || '').toLowerCase();
                                return fieldName.indexOf('nonce') !== -1 || fieldId.indexOf('nonce') !== -1;
                            });
                            var containsForm = elem.matches('form') || null !== elem.querySelector('form') ||
                                freshElem.matches('form') || null !== freshElem.querySelector('form');

                            // Do not replace forms or nonce fields because changing them mid-session can invalidate submissions.
                            if (containsForm || containsNonce) {
                                emitSkipped(
                                    containsForm ? 'contains-form' : 'contains-nonce',
                                    {
                                        scope: 'element',
                                        selector: selector,
                                        element: elem,
                                        index: i,
                                        phase: 'swap'
                                    },
                                    elem
                                );
                                return;
                            }

                            // Safe swap: replace only element inner content, not outer node identity.
                            // Consumers can reinitialize dynamic widgets via the swapped event.
                            elem.innerHTML = freshElem.innerHTML;
                            // Notify integrations so interactive components can rebind listeners.
                            elem.dispatchEvent(new CustomEvent('breeze:dc:swapped', {
                                bubbles: true,
                                detail: {
                                    selector: selector,
                                    element: elem,
                                    index: i
                                }
                            }));
                            swappedCount++;
                            console.log("Updated content for:", selector);
                        });
                        // Remove loader state once this selector batch is processed.
                        foundElementsInDOM.forEach(function(elem) {
                            elem.classList.remove("breeze-dc-elem");
                        });
                    } else {
                        trackSelectorMismatch(selector, foundElementsInDOM.length, foundElementsInVirtualDOM.length);
                        // Keep cleanup deterministic even when no fresh match exists.
                        foundElementsInDOM.forEach(function(elem) {
                            elem.classList.remove("breeze-dc-elem");
                        });
                    }
                });
                emitDone(xhr.status, 'success');
            } else {
                // Network/status failure: remove any temporary loader classes.
                elementsReload.forEach(function(selector) {
                    var elements = queryElements(document, selector, 'xhr-failure-cleanup');
                    elements.forEach(function(element) {
                        element.classList.remove("breeze-dc-elem");
                    });
                });
                emitSkipped('xhr-status', {
                    phase: 'xhr',
                    status: xhr.status
                });
                emitDone(xhr.status, 'xhr-status');
            }
        }
    };
    xhr.open('GET', urlWithCacheBuster);
    // Lets the server skip injecting DC again on this refresh request.
    xhr.setRequestHeader('X-Breeze-DC', '1');
    xhr.send();
}

if (document.readyState === 'complete') {
    breezeDoublecheckOnLoad();
} else {
    // Cooperative load hook: addEventListener, never window.onload assignment.
    window.addEventListener('load', breezeDoublecheckOnLoad);
    // If load never fires, start DC once. __breezeDcHasRun blocks a second run.
    window.setTimeout(function() {
        if (window.__breezeDcHasRun !== true) {
            breezeDoublecheckOnLoad();
        }
    }, 20000);
}
