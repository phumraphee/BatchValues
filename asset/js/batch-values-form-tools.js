/**
 * Batch Values 0.10.0 — sorting and moving values on Omeka's own add and
 * edit form.
 *
 * Two jobs people were opening Batch Values for on a single item:
 *
 *  - "Sort the values" of one field. Omeka already lets a value be dragged by
 *    the handle on its left; these buttons do the whole field in one press.
 *    The order is worked out by the server, with the same collator as the
 *    batch operation, so the two never disagree.
 *  - "Move to another property". A value (or every value of a field) is taken
 *    to another field. Omeka reads the field of a value from the block it sits
 *    in, so moving the block is all there is to it.
 *
 * Nothing is written until the person presses Save: this only rearranges the
 * form. There is no preview and no undo of Batch Values; the archive's history
 * records the save like any other save made by a person.
 */
(function ($) {
    'use strict';

    $(function () {
        var $cfg = $('#batchvalues-form-tools');
        var $properties = $('#properties');
        if (!$cfg.length || !$properties.length) {
            return;
        }
        var sortUrl = String($cfg.data('sortUrl') || '');
        // The strings come from the server as data attributes, written the
        // way HTML writes them (data-move-one-text); they are asked for here
        // the way JavaScript writes them (moveOneText).
        var t = function (name) {
            var attribute = 'data-' + name.replace(/[A-Z]/g, function (c) {
                return '-' + c.toLowerCase();
            });
            var v = $cfg.attr(attribute);
            return v === undefined || v === null ? '' : String(v);
        };

        // --------------------------------------------------------------
        // Reading the form
        // --------------------------------------------------------------

        var isTemplate = function ($field) {
            return $field.hasClass('template');
        };
        var valuesOf = function ($field) {
            return $field.find('.values').first().children('.value').not('.delete');
        };
        var bodyOf = function ($value) {
            return $value.children('.input-body');
        };
        var inputVal = function ($value, key) {
            var $input = bodyOf($value).find(':input[data-value-key="' + key + '"]').filter(function () {
                return !$(this).closest('.value-annotation').length;
            }).first();
            return $input.length ? String($input.val() === null || $input.val() === undefined ? '' : $input.val()) : '';
        };
        /**
         * What the person sees, which is what both this and the batch
         * operation sort by: the title of a linked item, the label of a term
         * or URI, the text itself.
         */
        var displayText = function ($value) {
            var linked = inputVal($value, 'value_resource_id');
            if (linked !== '') {
                var title = bodyOf($value).find('.selected-resource .o-title').first().text();
                title = $.trim(title.replace(/\s+/g, ' '));
                if (title !== '') {
                    return title;
                }
            }
            var label = $.trim(inputVal($value, 'o:label'));
            if (label !== '') {
                return label;
            }
            var text = $.trim(inputVal($value, '@value'));
            if (text !== '') {
                return text.replace(/\s+/g, ' ');
            }
            var uri = $.trim(inputVal($value, '@id'));
            if (uri !== '') {
                return uri;
            }
            // A data type from another module: take what it shows.
            return $.trim(bodyOf($value).text().replace(/\s+/g, ' '));
        };
        var langOf = function ($value) {
            return inputVal($value, '@language') || inputVal($value, 'o:lang') || '';
        };
        /**
         * What kind of value this is, for the quick picks: a link to another
         * item, a URI, or text.
         */
        var kindOf = function ($value) {
            if (inputVal($value, 'value_resource_id') !== '') {
                return 'link';
            }
            if (inputVal($value, '@id') !== '') {
                return 'uri';
            }
            return 'text';
        };
        var labelOf = function ($field) {
            var label = $.trim($field.find('.field-label').first().text());
            return label !== '' ? label : String($field.attr('data-property-term') || '');
        };
        var typesOf = function ($field) {
            var raw = String($field.attr('data-data-types') || '');
            return raw === '' ? [] : raw.split(',');
        };

        // --------------------------------------------------------------
        // The question, in the style of the pages of Batch Values
        // --------------------------------------------------------------

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
                e.preventDefault();
                close();
            }
        };
        /**
         * options: title, body (a jQuery node), ok, then
         */
        var ask = function (options) {
            close();
            var box = document.createElement('div');
            box.className = 'bv-ask-back';
            box.innerHTML = '<div class="bv-ask" role="dialog" aria-modal="true">'
                + '<h3 class="bv-ask-title"></h3>'
                + '<div class="bv-ask-body"></div>'
                + '<div class="bv-ask-buttons">'
                + '<button type="button" class="button bv-ask-ok"></button>'
                + '<button type="button" class="button bv-ask-back-button"></button>'
                + '</div></div>';
            box.querySelector('.bv-ask-title').textContent = options.title;
            $(box).find('.bv-ask-body').append(options.body);
            var ok = box.querySelector('.bv-ask-ok');
            ok.textContent = options.ok;
            var back = box.querySelector('.bv-ask-back-button');
            back.textContent = t('back');
            ok.addEventListener('click', function () {
                var keep = options.then();
                if (keep !== false) {
                    close();
                }
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
            var first = box.querySelector('select, input, button');
            if (first) {
                first.focus();
            }
        };

        // --------------------------------------------------------------
        // What these buttons did on this page
        // --------------------------------------------------------------

        /**
         * Every press is kept, shown on the form until the page is saved, and
         * sent with the save so that the archive's log can record what the
         * buttons touched — and only that.
         */
        var done = [];
        var termOf = function ($field) {
            return String($field.attr('data-property-term') || '');
        };
        var record = function (entry) {
            done.push(entry);
            showDone();
            sendDone();
        };
        /**
         * The same lines the panel shows, for the question asked before saving.
         */
        var doneText = function () {
            return done.map(function (entry) {
                if (entry.kind === 'sort') {
                    return t('doneSort').replace('%1$s', entry.field).replace('%2$s', entry.direction === 'desc' ? t('desc') : t('asc'));
                }
                return (entry.kind === 'copy' ? t('doneCopy') : t('doneMove'))
                    .replace('%1$d', String(entry.n))
                    .replace('%2$s', entry.field)
                    .replace('%3$s', entry.target);
            });
        };
        window.batchValuesFormDoneText = doneText;
        var showDone = function () {
            var $panel = $('#bv-form-done');
            if (!done.length) {
                $panel.remove();
                return;
            }
            if (!$panel.length) {
                $panel = $('<div id="bv-form-done" class="bv-form-done"></div>')
                    .append($('<strong></strong>').text(t('doneTitle')))
                    .append($('<ul></ul>'));
                $properties.before($panel);
            }
            var $list = $panel.find('ul').empty();
            doneText().forEach(function (line) {
                $list.append($('<li></li>').text(line));
            });
        };
        var sendDone = function () {
            var form = $properties.closest('form')[0];
            if (!form) {
                return;
            }
            var terms = [];
            done.forEach(function (entry) {
                [entry.term, entry.targetTerm].forEach(function (term) {
                    if (term && terms.indexOf(term) === -1) {
                        terms.push(term);
                    }
                });
            });
            var $input = $(form).find('input[name="batchvalues_form_actions"]');
            if (!$input.length) {
                $input = $('<input type="hidden" name="batchvalues_form_actions">').appendTo(form);
            }
            $input.val(JSON.stringify({ fields: terms, actions: done }));
        };
        // Read by the module's own question before saving.
        window.batchValuesFormDone = function () {
            return done.slice();
        };

        // --------------------------------------------------------------
        // Sorting
        // --------------------------------------------------------------

        var reorder = function ($field, order) {
            var $values = valuesOf($field);
            if (!$values.length) {
                return;
            }
            var $box = $field.find('.values').first();
            var nodes = $values.toArray();
            order.forEach(function (i) {
                if (nodes[i]) {
                    $box.append(nodes[i]);
                }
            });
            // A value marked for removal keeps its place at the end.
            $field.find('.values').first().children('.value.delete').appendTo($box);
            $field.trigger('change');
        };

        /**
         * The browser's own collator, used only when the server cannot be
         * reached. It is close to the server's but not the same thing, so the
         * server is asked first.
         */
        var localOrder = function (texts, desc) {
            var order = texts.map(function (text, i) { return i; });
            var collator = window.Intl && window.Intl.Collator
                ? new window.Intl.Collator('th-TH', { numeric: true })
                : null;
            order.sort(function (a, b) {
                var c = collator
                    ? collator.compare(texts[a], texts[b])
                    : (texts[a] < texts[b] ? -1 : (texts[a] > texts[b] ? 1 : 0));
                if (desc) {
                    c = -c;
                }
                return c || (a - b);
            });
            return order;
        };

        var sortField = function ($field, direction) {
            var $values = valuesOf($field);
            if ($values.length < 2) {
                return;
            }
            var texts = [];
            var langs = [];
            $values.each(function () {
                texts.push(displayText($(this)));
                langs.push(langOf($(this)));
            });
            var finish = function (order) {
                reorder($field, order);
                note($field, t('sorted').replace('%d', String(texts.length)));
                record({ kind: 'sort', direction: direction, field: labelOf($field), term: termOf($field), n: texts.length });
            };
            if (!sortUrl) {
                finish(localOrder(texts, direction === 'desc'));
                return;
            }
            $.ajax({
                url: sortUrl,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ texts: texts, langs: langs, direction: direction }),
                dataType: 'json'
            }).done(function (data) {
                if (data && $.isArray(data.order) && data.order.length === texts.length) {
                    finish(data.order);
                } else {
                    finish(localOrder(texts, direction === 'desc'));
                }
            }).fail(function () {
                finish(localOrder(texts, direction === 'desc'));
            });
        };

        var note = function ($field, text) {
            var $bar = $field.find('.bv-field-tools').first();
            if (!$bar.length) {
                // A field just added to the form does not have its bar yet.
                addTools();
                $bar = $field.find('.bv-field-tools').first();
            }
            if (!$bar.length) {
                return;
            }
            var $note = $bar.find('.bv-field-note');
            if (!$note.length) {
                $note = $('<span class="bv-field-note"></span>').appendTo($bar);
            }
            $note.text(text);
            window.setTimeout(function () { $note.text(''); }, 4000);
        };

        // --------------------------------------------------------------
        // Moving and copying
        // --------------------------------------------------------------

        // --------------------------------------------------------------
        // The fields this person keeps moving values to
        // --------------------------------------------------------------

        /**
         * People move values to the same few fields over and over. Those are
         * kept here — in this browser only, nothing is sent anywhere — so they
         * are offered first next time. A field that is used once and never
         * again drops off as others overtake it.
         */
        var RECENT_KEY = 'batchValues.moveTargets';
        var RECENT_SHOWN = 5;
        var RECENT_KEPT = 40;
        var recentRead = function () {
            try {
                var raw = window.localStorage.getItem(RECENT_KEY);
                var data = raw ? JSON.parse(raw) : null;
                return data && typeof data === 'object' ? data : {};
            } catch (err) {
                // A browser that refuses storage: the list is simply not kept.
                return {};
            }
        };
        var recentAdd = function (term) {
            if (!term) {
                return;
            }
            try {
                var data = recentRead();
                var was = data[term] && typeof data[term] === 'object' ? data[term] : { n: 0, t: 0 };
                data[term] = { n: (parseInt(was.n, 10) || 0) + 1, t: Date.now() };
                var terms = Object.keys(data);
                if (terms.length > RECENT_KEPT) {
                    // Keep the ones used most, then the ones used last.
                    terms.sort(function (a, b) { return recentRank(data, b) - recentRank(data, a); });
                    var kept = {};
                    terms.slice(0, RECENT_KEPT).forEach(function (k) { kept[k] = data[k]; });
                    data = kept;
                }
                window.localStorage.setItem(RECENT_KEY, JSON.stringify(data));
            } catch (err) {
                // Nothing to do: this is a convenience, not the work.
            }
        };
        var recentRank = function (data, term) {
            var row = data[term] || {};
            return (parseInt(row.n, 10) || 0) * 1e13 + (parseInt(row.t, 10) || 0);
        };
        /**
         * The terms to offer first: most used, then most recently used.
         */
        var recentTerms = function (skip) {
            var data = recentRead();
            var terms = Object.keys(data).filter(function (term) {
                return term !== skip;
            });
            terms.sort(function (a, b) { return recentRank(data, b) - recentRank(data, a); });
            return terms.slice(0, RECENT_SHOWN);
        };

        /**
         * Every property of the archive, read from Omeka's own property
         * selector, grouped by vocabulary as it groups them.
         */
        var propertyGroups = function () {
            var groups = [];
            $('#property-selector .selector-parent').each(function () {
                var $parent = $(this);
                var name = $.trim($parent.children('span').first().text());
                var options = [];
                $parent.find('li.selector-child').each(function () {
                    var $li = $(this);
                    options.push({
                        term: String($li.data('propertyTerm')),
                        label: String($li.data('childSearch') || $li.find('.selectable').text())
                    });
                });
                if (options.length) {
                    groups.push({ name: name, options: options });
                }
            });
            return groups;
        };

        var labelsByTerm = null;
        var labelOfTerm = function (term) {
            if (labelsByTerm === null) {
                labelsByTerm = {};
                propertyGroups().forEach(function (group) {
                    group.options.forEach(function (option) {
                        labelsByTerm[option.term] = option.label;
                    });
                });
            }
            return labelsByTerm[term] || term;
        };

        /**
         * The field on the form for a property, asking Omeka to add it when it
         * is not there (its own property selector does that).
         */
        var fieldFor = function (term) {
            var $field = $properties.children('.resource-property').filter(function () {
                return String($(this).attr('data-property-term')) === term && !isTemplate($(this));
            }).first();
            if ($field.length) {
                return $field;
            }
            var $li = $('#property-selector li.selector-child').filter(function () {
                return String($(this).data('propertyTerm')) === term;
            }).first();
            if (!$li.length) {
                return $();
            }
            $li.trigger('click');
            return $properties.children('.resource-property').filter(function () {
                return String($(this).attr('data-property-term')) === term && !isTemplate($(this));
            }).first();
        };

        var moveOrCopy = function ($field, $values, term, copy) {
            var $target = fieldFor(term);
            if (!$target.length) {
                return false;
            }
            var $box = $target.find('.values').first();
            var n = 0;
            $values.each(function () {
                var $value = $(this);
                // The "More actions" menu of the value it was started from is
                // open; neither the value that moves nor its copy keeps it.
                $value.find('.more-actions').removeClass('active');
                if (copy) {
                    var $copy = $value.clone(true);
                    $copy.find('.more-actions').removeClass('active');
                    $box.append($copy);
                } else {
                    $box.append($value);
                }
                n++;
            });
            $target.trigger('change');
            $field.trigger('change');
            if (window.Omeka && typeof window.Omeka.scrollTo === 'function') {
                window.Omeka.scrollTo($target);
            }
            note($target, (copy ? t('copied') : t('moved'))
                .replace('%1$d', String(n))
                .replace('%2$s', labelOf($field)));
            recentAdd(termOf($target));
            record({
                kind: copy ? 'copy' : 'move',
                n: n,
                field: labelOf($field),
                term: termOf($field),
                target: labelOf($target),
                targetTerm: termOf($target)
            });
            return true;
        };

        var askMoveOrCopy = function ($field, $values) {
            var $body = $('<div class="bv-move-form"></div>');
            var $count = $('<p class="bv-muted"></p>');
            $body.append($count);
            var nodes = $values.toArray();
            // More than one value: each one can be left behind. The quick
            // picks are the question people actually ask — "the linked items,
            // not the text".
            var $list = null;
            var $picks = null;
            if (nodes.length > 1) {
                $picks = $('<div class="bv-move-picks"></div>');
                $list = $('<ul class="bv-move-list"></ul>');
                nodes.forEach(function (node, i) {
                    var $value = $(node);
                    var kind = kindOf($value);
                    var text = displayText($value);
                    $list.append($('<li></li>').append(
                        $('<label></label>')
                            .append($('<input type="checkbox" checked>').attr('data-i', String(i)).attr('data-kind', kind))
                            .append($('<span class="bv-move-text"></span>').text(text === '' ? t('empty') : text))
                            .append($('<span class="bv-move-kind"></span>').text(t('kind' + kind.charAt(0).toUpperCase() + kind.slice(1))))
                    ));
                });
                var kinds = [];
                nodes.forEach(function (node) {
                    var kind = kindOf($(node));
                    if (kinds.indexOf(kind) === -1) {
                        kinds.push(kind);
                    }
                });
                var pick = function (label, filter) {
                    $picks.append($('<button type="button" class="bv-move-pick"></button>').text(label).on('click', function (e) {
                        e.preventDefault();
                        $list.find('input[type=checkbox]').each(function () {
                            this.checked = filter(String($(this).data('kind')));
                        });
                        recount();
                    }));
                };
                pick(t('pickAll'), function () { return true; });
                pick(t('pickNone'), function () { return false; });
                // Only offered when the field really holds more than one kind.
                if (kinds.length > 1) {
                    if (kinds.indexOf('link') > -1) { pick(t('pickLinks'), function (k) { return k === 'link'; }); }
                    if (kinds.indexOf('text') > -1) { pick(t('pickText'), function (k) { return k === 'text'; }); }
                    if (kinds.indexOf('uri') > -1) { pick(t('pickUri'), function (k) { return k === 'uri'; }); }
                }
                $body.append($picks).append($list);
            }
            var picked = function () {
                if (!$list) {
                    return $values;
                }
                var chosen = [];
                $list.find('input[type=checkbox]:checked').each(function () {
                    var node = nodes[parseInt(String($(this).data('i')), 10)];
                    if (node) {
                        chosen.push(node);
                    }
                });
                return $(chosen);
            };
            var recount = function () {
                var n = picked().length;
                $count.text(
                    (nodes.length === 1 ? t('moveOneText') : t('moveManyText'))
                        .replace('%1$d', String(n))
                        .replace('%2$d', String(nodes.length))
                        .replace('%3$s', labelOf($field))
                );
                warn();
            };
            if ($list) {
                $list.on('change', 'input[type=checkbox]', recount);
            }
            var here = String($field.attr('data-property-term'));
            var $select = $('<select class="bv-move-target"></select>');
            // The archive has hundreds of properties; the ones worth offering
            // first are the fields already on this page — which, on a resource
            // template, is every field the template defines. Everything else is
            // one tick away, for the times a value belongs somewhere new.
            var $all = $('<input type="checkbox">');
            var $allLabel = $('<label class="bv-move-all"></label>')
                .append($all)
                .append($('<span></span>').text(' ' + t('showAll')));
            var fill = function () {
                var keep = String($select.val() || '');
                $select.empty().append($('<option value=""></option>').text(t('choose')));
                var seen = {};
                var add = function ($group, term, label) {
                    if (term === '' || term === here || seen[term]) {
                        return;
                    }
                    seen[term] = true;
                    $group.append($('<option></option>').attr('value', term).text(label));
                };
                // The ones this person keeps moving values to, first of all.
                var $often = $('<optgroup></optgroup>').attr('label', t('groupOften'));
                recentTerms(here).forEach(function (term) {
                    add($often, term, labelOfTerm(term));
                });
                if ($often.children().length) {
                    $select.append($often);
                }
                // Then the fields of this page, in the order they appear on it.
                var $here = $('<optgroup></optgroup>').attr('label', t('groupOnPage'));
                $properties.children('.resource-property').each(function () {
                    var $f = $(this);
                    if (isTemplate($f)) {
                        return;
                    }
                    add($here, String($f.attr('data-property-term') || ''), labelOf($f));
                });
                if ($here.children().length) {
                    $select.append($here);
                }
                if ($all.prop('checked')) {
                    propertyGroups().forEach(function (group) {
                        var $group = $('<optgroup></optgroup>').attr('label', group.name);
                        group.options.forEach(function (option) {
                            add($group, option.term, option.label);
                        });
                        if ($group.children().length) {
                            $select.append($group);
                        }
                    });
                }
                if (keep !== '' && $select.find('option[value="' + keep + '"]').length) {
                    $select.val(keep);
                }
                searchable();
            };
            /**
             * Typing to find a field, with Omeka's own widget when it is there.
             * A long list is unusable on a phone without it.
             */
            var searchable = function () {
                // Only once the dialog is on the page: the widget measures the
                // select, and a select nobody has put in the document yet has
                // no width to measure.
                if (!$.fn.chosen || !$select[0] || !document.body.contains($select[0])) {
                    return;
                }
                try {
                    if ($select.next('.chosen-container').length) {
                        $select.trigger('chosen:updated');
                        return;
                    }
                    $select.chosen({
                        allow_single_deselect: false,
                        disable_search_threshold: 0,
                        include_group_label_in_selected: false,
                        search_contains: true,
                        width: '100%',
                        no_results_text: t('noResults'),
                        placeholder_text_single: t('choose')
                    });
                    // Chosen fires "change" on the select it hides, so the
                    // warning and the counter keep working as they are.
                } catch (err) {
                    // Without it the plain select still works.
                }
            };
            $all.on('change', function () {
                fill();
                warn();
            });
            fill();
            // Nothing else is on the page yet: then the whole archive is the
            // only thing to offer.
            if (!$select.find('optgroup').length) {
                $all.prop('checked', true);
                fill();
            }
            var $label = $('<label class="bv-move-label"></label>').text(t('field') + ' ').append($select);
            $body.append($label).append($allLabel);
            var $how = $('<div class="bv-move-how"></div>');
            var name = 'bv-move-how-' + Date.now();
            $how.append($('<label></label>').append(
                $('<input type="radio" value="move" checked>').attr('name', name)
            ).append($('<span></span>').text(' ' + t('move'))));
            $how.append($('<label></label>').append(
                $('<input type="radio" value="copy">').attr('name', name)
            ).append($('<span></span>').text(' ' + t('copy'))));
            $body.append($how);
            var $warning = $('<p class="bv-move-warning" hidden></p>');
            $body.append($warning);
            // A field whose template says which data types it holds: the value
            // is still moved and still saved (Omeka does not refuse it), but
            // it is worth seeing before it is done.
            var warn = function () {
                var types = [];
                picked().each(function () {
                    var type = String($(this).attr('data-data-type') || '');
                    if (type !== '' && types.indexOf(type) === -1) {
                        types.push(type);
                    }
                });
                var term = String($select.val() || '');
                $warning.attr('hidden', true).text('');
                if (term === '') {
                    return;
                }
                var $target = $properties.children('.resource-property').filter(function () {
                    return String($(this).attr('data-property-term')) === term && !isTemplate($(this));
                }).first();
                if (!$target.length) {
                    return;
                }
                var allowed = typesOf($target);
                if (!allowed.length) {
                    return;
                }
                var odd = types.filter(function (type) { return allowed.indexOf(type) === -1; });
                if (odd.length) {
                    $warning.text(t('typeWarning')
                        .replace('%1$s', odd.join(', '))
                        .replace('%2$s', labelOf($target))
                        .replace('%3$s', allowed.join(', ')))
                        .removeAttr('hidden');
                }
            };
            $select.on('change', warn);
            recount();
            ask({
                title: t('moveTitle'),
                body: $body,
                ok: t('moveOk'),
                then: function () {
                    var term = String($select.val() || '');
                    if (term === '') {
                        $select.focus();
                        return false;
                    }
                    var $chosen = picked();
                    if (!$chosen.length) {
                        return false;
                    }
                    var copy = $how.find('input:checked').val() === 'copy';
                    return moveOrCopy($field, $chosen, term, copy) === false ? false : true;
                }
            });
            searchable();
        };

        // --------------------------------------------------------------
        // The controls on the form
        // --------------------------------------------------------------

        var addTools = function () {
            $properties.children('.resource-property').each(function () {
                var $field = $(this);
                if (isTemplate($field)) {
                    return;
                }
                var $inputs = $field.children('.inputs');
                if (!$inputs.length) {
                    return;
                }
                var $bar = $inputs.children('.bv-field-tools').first();
                if (!$bar.length) {
                    $bar = $('<div class="bv-field-tools"></div>')
                        .append($('<button type="button" class="bv-field-sort" data-direction="asc"></button>')
                            .text(t('asc')).attr('title', t('ascTitle')))
                        .append($('<button type="button" class="bv-field-sort" data-direction="desc"></button>')
                            .text(t('desc')).attr('title', t('descTitle')))
                        .append($('<button type="button" class="bv-field-move"></button>')
                            .text(t('moveField')).attr('title', t('moveFieldTitle')));
                    $inputs.prepend($bar);
                }
                var n = valuesOf($field).length;
                $bar.find('.bv-field-sort').attr('hidden', n < 2 ? true : null);
                $bar.find('.bv-field-move').attr('hidden', n < 1 ? true : null);
                $bar.attr('hidden', n < 1 ? true : null);
            });
            // One entry in the value's own "More actions" menu.
            $properties.find('.values > .value').each(function () {
                var $value = $(this);
                var $menu = $value.children('.input-footer').find('.more-actions > ul').first();
                if (!$menu.length || $menu.children('.bv-value-move-item').length) {
                    return;
                }
                // An icon in the row of icons Omeka puts there, not a line of
                // text: the menu is a narrow column.
                $menu.append($('<li class="bv-value-move-item"></li>').append(
                    $('<a href="#" class="bv-value-move o-icon-external"></a>')
                        .attr('title', t('moveValue'))
                        .attr('aria-label', t('moveValue'))
                ));
            });
        };

        $properties.on('click', '.bv-field-sort', function (e) {
            e.preventDefault();
            sortField($(this).closest('.resource-property'), String($(this).data('direction')));
        });
        $properties.on('click', '.bv-field-move', function (e) {
            e.preventDefault();
            var $field = $(this).closest('.resource-property');
            var $values = valuesOf($field);
            if ($values.length) {
                askMoveOrCopy($field, $values);
            }
        });
        $properties.on('click', '.bv-value-move', function (e) {
            e.preventDefault();
            var $value = $(this).closest('.value');
            askMoveOrCopy($value.closest('.resource-property'), $value);
        });

        var timer = null;
        var later = function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(addTools, 120);
        };
        $properties.on('click', '.add-value, .remove-value, .restore-value', later);
        $(document).on('o:property-added o:form-loaded o:resource-selected', later);
        if (window.MutationObserver) {
            new MutationObserver(function (mutations) {
                var ours = mutations.every(function (m) {
                    var nodes = Array.prototype.slice.call(m.addedNodes).concat(Array.prototype.slice.call(m.removedNodes));
                    return nodes.length > 0 && nodes.every(function (node) {
                        return node.nodeType === 1 && (
                            node.classList.contains('bv-field-tools')
                            || node.classList.contains('bv-value-move-item')
                            || node.classList.contains('bv-field-note')
                        );
                    });
                });
                if (!ours) {
                    later();
                }
            }).observe($properties[0], { childList: true, subtree: true });
        }
        addTools();
    });
})(jQuery);
