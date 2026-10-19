/*
 * Resource form (item, item set, media): marks a value that is already in the
 * same field, as soon as it is chosen or typed, so the same linked item or
 * text is not added twice by mistake. Nothing is changed or blocked: the mark
 * only says where the other copy is.
 *
 * The same value means the same data type, the same content (text, URI,
 * label, linked resource), language and visibility, as Batch Values'
 * "Values entered twice" check counts it.
 */
// The Cancel button does not need jQuery: it works even when another
// script on the page failed.
(function () {
    // "Cancel" on the add or edit form: Omeka goes one step back in the
    // browser's history, which does nothing in a tab that was opened on the
    // form itself. Given an address, it goes there instead. Caught before
    // Omeka's own handler (capture), so it replaces it.
    document.addEventListener('click', function (e) {
        var cfg = document.getElementById('batchvalues-form-dup');
        var url = cfg ? cfg.getAttribute('data-cancel-url') : '';
        if (!url) {
            return;
        }
        var button = e.target.closest ? e.target.closest('.button.cancel') : null;
        if (!button || e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) {
            return;
        }
        // Where the person came from, when that is a page of this site and
        // not the form itself (so Cancel from a list goes back to the list,
        // as Omeka's own Cancel did); otherwise the page of the resource.
        var from = document.referrer || '';
        try {
            var here = new URL(window.location.href);
            var ref = from ? new URL(from) : null;
            if (ref && ref.origin === here.origin && ref.href !== here.href && !/\/(edit|add)(\/|$|\?|#)/.test(ref.pathname)) {
                url = ref.href;
            }
        } catch (err) {
            // Keep the address given by the module.
        }
        // Another module may ask this question itself, with more to say (the
        // archive's history shows what would be lost). Then this handler does
        // not touch the event at all: that module stops it, asks, and presses
        // the button again — and this handler sends it to the right page on
        // that second press (0.9.0).
        if (typeof window.batchValuesLetOthersAsk === 'function' && window.batchValuesLetOthersAsk()) {
            return;
        }
        e.preventDefault();
        e.stopImmediatePropagation();
        var go = function () {
            window.location.assign(url);
        };
        // Ask first, like the pages of Batch Values do, and only when there
        // is something to lose (0.9.0).
        var ask = window.batchValuesAskBeforeLeaving;
        if (typeof ask === 'function') {
            ask(go);
            return;
        }
        go();
    }, true);

})();

/*
 * Ask before saving and before leaving the add or edit form, and say when
 * the form is longer than the server accepts (0.9.0). No jQuery: it works
 * even when another script on the page failed.
 */
(function () {
    var cfg = document.getElementById('batchvalues-form-dup');
    if (!cfg) {
        return;
    }
    var form = document.querySelector('form.resource-form');
    var mode = cfg.getAttribute('data-confirm') || 'both';
    var maxVars = parseInt(cfg.getAttribute('data-max-input-vars'), 10) || 0;
    var postMax = parseInt(cfg.getAttribute('data-post-max'), 10) || 0;
    var attr = function (name) {
        return cfg.getAttribute(name) || '';
    };

    // ------------------------------------------------------------------
    // What the browser will send, and how much of it.
    // ------------------------------------------------------------------
    var fields = function () {
        if (!form) {
            return [];
        }
        return Array.prototype.filter.call(form.querySelectorAll('input, select, textarea'), function (el) {
            if (!el.name || el.disabled) {
                return false;
            }
            if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) {
                return false;
            }
            return true;
        });
    };
    // Omeka's form does not give the value inputs a name: it collects them
    // into one "values_json" field when the form is sent. So what was typed
    // or picked is read from the inputs themselves, in their order.
    var valueInputs = function () {
        if (!form) {
            return [];
        }
        return Array.prototype.slice.call(form.querySelectorAll('[data-value-key]'));
    };
    var state = function () {
        var parts = [];
        fields().forEach(function (el) {
            if (el.type === 'file') {
                return;
            }
            parts.push(el.name + '=' + String(el.value == null ? '' : el.value));
        });
        valueInputs().forEach(function (el, i) {
            var block = el.closest ? el.closest('.value') : null;
            parts.push('v' + i + ':' + String(el.getAttribute('data-value-key')) + '='
                + String(el.value == null ? '' : el.value)
                + (block && block.classList.contains('delete') ? '|deleted' : ''));
        });
        return parts;
    };
    var bodySize = function () {
        // The named fields, plus what the value inputs will weigh once Omeka
        // packs them into "values_json".
        var n = 0;
        state().forEach(function (part) {
            n += encodeURIComponent(part).length + 1;
        });
        return n;
    };
    var human = function (bytes) {
        if (bytes >= 1024 * 1024) {
            return (Math.round(bytes / (1024 * 1024) * 10) / 10) + ' MB';
        }
        return Math.max(1, Math.round(bytes / 1024)) + ' KB';
    };

    var baseline = null;
    var changedFields = function () {
        if (baseline === null) {
            return 0;
        }
        var now = state();
        if (now.length !== baseline.length) {
            return Math.abs(now.length - baseline.length);
        }
        var n = 0;
        for (var i = 0; i < now.length; i++) {
            if (now[i] !== baseline[i]) {
                n++;
            }
        }
        return n;
    };

    // ------------------------------------------------------------------
    // The question, in the style of the pages of Batch Values.
    // ------------------------------------------------------------------
    var open = null;
    var close = function () {
        if (open) {
            open.parentNode.removeChild(open);
            open = null;
            document.removeEventListener('keydown', onKey, true);
        }
    };
    var onKey = function (e) {
        if (e.key === 'Escape') {
            close();
        }
    };
    var askUser = function (options) {
        close();
        var box = document.createElement('div');
        box.className = 'bv-ask-back';
        box.innerHTML = '<div class="bv-ask" role="dialog" aria-modal="true">'
            + '<h3 class="bv-ask-title"></h3>'
            + '<p class="bv-ask-text"></p>'
            + '<p class="bv-ask-note bv-muted"></p>'
            + '<div class="bv-ask-buttons">'
            + '<button type="button" class="button bv-ask-ok"></button>'
            + '<button type="button" class="button bv-ask-back-button"></button>'
            + '</div></div>';
        box.querySelector('.bv-ask-title').textContent = options.title;
        box.querySelector('.bv-ask-text').textContent = options.text;
        var note = box.querySelector('.bv-ask-note');
        if (options.note) {
            note.textContent = options.note;
        } else {
            note.parentNode.removeChild(note);
        }
        var ok = box.querySelector('.bv-ask-ok');
        ok.textContent = options.ok;
        if (options.danger) {
            ok.className += ' bv-ask-danger';
        }
        var back = box.querySelector('.bv-ask-back-button');
        back.textContent = attr('data-confirm-back');
        ok.addEventListener('click', function () {
            close();
            options.then();
        });
        back.addEventListener('click', close);
        box.addEventListener('click', function (e) {
            if (e.target === box) {
                close();
            }
        });
        document.body.appendChild(box);
        open = box;
        document.addEventListener('keydown', onKey, true);
        ok.focus();
    };

    /**
     * Another module that asks this question itself, with more to say: the
     * revision history module (Revision History) shows what the save would change
     * and what leaving would lose, read from the server. When it is on this
     * page, this module does not ask the same thing again (0.9.0).
     */
    var othersAsk = function () {
        var other = window.TchConfirm;
        return !!(other && other.url && other.mode !== 'batch');
    };
    // True while that other module has not asked yet: the click is left alone
    // so it can. Once it has asked and pressed the button again, it releases
    // the form (Omeka's own mark), and this handler takes the click.
    var released = function () {
        try {
            return !!(window.jQuery && window.jQuery(form).data('omekaFormSubmitted'));
        } catch (err) {
            return false;
        }
    };
    window.batchValuesLetOthersAsk = function () {
        return othersAsk() && !!form && changedFields() > 0 && !released();
    };

    // Used by the Cancel handler above.
    window.batchValuesAskBeforeLeaving = function (then) {
        if (mode === 'off' || !form || changedFields() === 0 || othersAsk()) {
            then();
            return;
        }
        askUser({
            title: attr('data-confirm-cancel-title'),
            text: attr('data-confirm-cancel-text'),
            note: attr('data-changes-some').replace('%d', String(changedFields())),
            ok: attr('data-confirm-cancel-ok'),
            danger: true,
            then: then,
        });
    };

    if (!form) {
        return;
    }
    // The state of the form once the page has settled (Chosen, Value Suggest
    // and the other widgets fill inputs of their own).
    window.setTimeout(function () {
        baseline = state();
        warnIfTooLong(true);
    }, 700);

    // ------------------------------------------------------------------
    // Longer than the server accepts: say it before the work is lost.
    // ------------------------------------------------------------------
    var tooLong = function () {
        var n = fields().length;
        if (maxVars && n > maxVars * 0.9) {
            return { kind: 'vars', text: attr('data-too-long-text').replace('%1$d', String(maxVars)).replace('%2$d', String(n)) };
        }
        var size = bodySize();
        if (postMax && size > postMax * 0.9) {
            return { kind: 'size', text: attr('data-too-big-text').replace('%1$s', human(postMax)).replace('%2$s', human(size)) };
        }
        return null;
    };
    var warnIfTooLong = function (quiet) {
        var found = tooLong();
        var bar = document.getElementById('bv-too-long');
        if (!found) {
            if (bar) {
                bar.parentNode.removeChild(bar);
            }
            return null;
        }
        if (!bar) {
            bar = document.createElement('div');
            bar.id = 'bv-too-long';
            bar.className = 'bv-form-warning';
            form.insertBefore(bar, form.firstChild);
        }
        bar.textContent = attr('data-too-long-title') + ' — ' + found.text;
        return found;
    };

    var allowed = false;
    form.addEventListener('submit', function (e) {
        if (allowed) {
            return;
        }
        var long = warnIfTooLong();
        // The question about the save itself is left to the module that shows
        // what would change, when it is here. The warning about a form longer
        // than the server accepts is this module's own, and stays.
        var ask = long || (mode === 'both' && !othersAsk());
        if (!ask) {
            return;
        }
        e.preventDefault();
        var changes = changedFields();
        // When the buttons of this module were used on the page, they know
        // exactly what they did; that is better than a count of fields.
        var byTools = [];
        try {
            byTools = (window.batchValuesFormDone && window.batchValuesFormDone()) || [];
        } catch (err) {
            byTools = [];
        }
        var note = '';
        if (!long) {
            note = changes
                ? attr('data-changes-some').replace('%d', String(changes))
                : attr('data-changes-none');
            if (byTools.length && window.batchValuesFormDoneText) {
                note = window.batchValuesFormDoneText().join(' · ');
            }
        }
        askUser({
            title: long ? attr('data-too-long-title') : attr('data-confirm-save-title'),
            text: long ? long.text : attr('data-confirm-save-text'),
            note: note,
            ok: long ? attr('data-too-long-ok') : attr('data-confirm-save-ok'),
            danger: !!long,
            then: function () {
                allowed = true;
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            },
        });
    }, true);
})();

(function ($) {
    $(function () {
        var $form = $('#properties');
        var $cfg = $('#batchvalues-form-dup');
        if (!$form.length || !$cfg.length) {
            return;
        }
        var label = String($cfg.data('label'));
        var title = String($cfg.data('title'));

        var keyOf = function ($value) {
            var type = String($value.data('dataType') || '');
            // A link is the item it points to, whatever data type says so:
            // Omeka's form saves "Omeka resource" where the template does not
            // say which kind of link the field holds, and "Item" where it
            // does, so the same item can be entered twice without looking
            // like it (0.8.2).
            var linked = $value.children('.input-body').find(':input[data-value-key="value_resource_id"]').val();
            if (linked !== undefined && linked !== null && String(linked) !== '') {
                var lang = $value.children('.input-body').find(':input[data-value-key="@language"], :input[data-value-key="o:lang"]').val() || '';
                return 'resource#' + String(linked) + '\u0001' + String(lang)
                    + '\u0001public=' + String($value.find('input.is_public').val());
            }
            var parts = [type];
            var filled = false;
            $value.children('.input-body').find(':input[data-value-key]').each(function () {
                var $input = $(this);
                if ($input.closest('.value-annotation').length) {
                    return;
                }
                var v = $input.val();
                v = v === null || v === undefined ? '' : String(v);
                var k = String($input.data('valueKey'));
                if (k !== '@language' && k !== 'o:lang' && v !== '') {
                    filled = true;
                }
                parts.push(k + '=' + v);
            });
            if (!filled) {
                return null;
            }
            parts.sort();
            parts.push('public=' + String($value.find('input.is_public').val()));
            return parts.join('\u0001');
        };

        var check = function () {
            $form.find('.resource-property').each(function () {
                var seen = {};
                var n = 0;
                $(this).find('.values > .value').each(function () {
                    var $value = $(this);
                    n++;
                    $value.find('.bv-dup-mark').remove();
                    if ($value.hasClass('delete')) {
                        return;
                    }
                    var key = keyOf($value);
                    if (key === null) {
                        return;
                    }
                    if (seen[key] !== undefined) {
                        var $mark = $('<span class="bv-dup-mark"></span>')
                            .text(label.replace('%d', seen[key]))
                            .attr('title', title);
                        $value.children('.input-body').append($mark);
                    } else {
                        seen[key] = n;
                    }
                });
            });
        };

        var timer = null;
        var later = function () {
            clearTimeout(timer);
            timer = setTimeout(check, 150);
        };
        // Typing, choosing a resource in the sidebar (hidden inputs set by
        // script), removing or restoring a value, a new value added.
        $form.on('input change', ':input', later);
        $form.on('click', '.remove-value, .restore-value, .o-icon-private, .o-icon-public', later);
        $(document).on('o:resource-selected o:sidebar-content-loaded', later);
        if (window.MutationObserver) {
            var isMark = function (node) {
                return node.nodeType === 1 && node.classList.contains('bv-dup-mark');
            };
            new MutationObserver(function (mutations) {
                // The marks put by check() itself do not count.
                var other = mutations.some(function (m) {
                    if (m.type !== 'childList') {
                        return !isMark(m.target);
                    }
                    var nodes = Array.prototype.slice.call(m.addedNodes).concat(Array.prototype.slice.call(m.removedNodes));
                    return nodes.length === 0 || !nodes.every(isMark);
                });
                if (other) {
                    later();
                }
            }).observe($form[0], {childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'value']});
        }
        check();
    });
})(jQuery);
