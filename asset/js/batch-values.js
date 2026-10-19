/*
 * Batch Values: value inputs rendered with each data type's own form
 * template (the same markup as the item edit page), grouped by template.
 */
(function ($) {
    'use strict';

    // ------------------------------------------------------------------
    // Remove form: filter, select all visible, selected counter.
    // ------------------------------------------------------------------
    function initPicker($block) {
        var $rows = $block.find('.bv-distinct tbody tr');
        var update = function () {
            var n = $block.find('input[name="keys[]"]:checked').length;
            $block.find('.bv-selected-count').text(n ? '(' + n + ')' : '');
        };
        $block.on('input', '.bv-filter-input', function () {
            var q = $(this).val().toLowerCase().trim();
            $rows.each(function () {
                var text = $(this).find('.bv-value-cell').text().toLowerCase();
                $(this).toggle(q === '' || text.indexOf(q) !== -1);
            });
        });
        $block.on('change', '.bv-check-all', function () {
            $rows.filter(':visible').find('input[name="keys[]"]').prop('checked', this.checked);
            update();
        });
        $block.on('change', 'input[name="keys[]"]', update);
        update();
    }

    // ------------------------------------------------------------------
    // Add form.
    // ------------------------------------------------------------------
    function initTypedGroups($form, $box) {
        var i18n = $box.data('i18n') || {};
        var searchUrl = $box.data('search-url');
        var single = String($box.data('single')) === '1';
        var $templates = $('#bv-templates');

        var templateFor = function (type) {
            return $templates.children('.value.template').filter(function () {
                return $(this).attr('data-data-type') === type;
            }).first();
        };

        var setupResourcePicker = function ($value, type) {
            $value.find('.resource-select').remove();
            var $picker = $('<div class="bv-picker">');
            var $input = $('<input type="search" class="bv-picker-input">').attr('placeholder', i18n.search || '');
            var $results = $('<ul class="bv-picker-results">');
            $picker.append($input, $results);
            $value.find('.resource-inputs').append($picker);
            var timer = null;
            var run = function () {
                var q = $input.val().trim();
                $.getJSON(searchUrl, {type: type, q: q}).done(function (data) {
                    $results.empty();
                    if (!data.results || !data.results.length) {
                        $results.append($('<li class="bv-muted">').text(i18n.noResult || ''));
                        return;
                    }
                    data.results.forEach(function (r) {
                        var $a = $('<a href="#">').text(r.title + ' (#' + r.id + ')').data('resource', r);
                        $results.append($('<li>').append($a));
                    });
                });
            };
            $input.on('input', function () {
                clearTimeout(timer);
                timer = setTimeout(run, 300);
            });
            $input.on('focus', function () {
                if (!$results.children().length) {
                    run();
                }
            });
            $results.on('click', 'a', function (e) {
                e.preventDefault();
                var r = $(this).data('resource');
                $value.find('[data-value-key="value_resource_id"]').val(r.id);
                $value.find('span.default').hide();
                $value.find('.selected-resource .o-title').empty().removeClass().addClass('o-title ' + r.type)
                    .append($('<a>', {href: r.url, target: '_blank', text: r.title + ' (#' + r.id + ')'}));
                $value.find('.selected-resource').show();
                $results.empty();
                $input.val('');
            });
        };

        var setPublic = function ($value, isPublic) {
            $value.data('isPublic', isPublic).toggleClass('bv-private', !isPublic);
            $value.find('.bv-value-visibility')
                .toggleClass('o-icon-public', isPublic).toggleClass('o-icon-private', !isPublic)
                .attr('title', isPublic ? (i18n.makePrivate || '') : (i18n.makePublic || ''))
                .attr('aria-label', isPublic ? (i18n.makePrivate || '') : (i18n.makePublic || ''));
            $value.find('.bv-private-label').remove();
            if (!isPublic) {
                $value.find('.bv-value-visibility').after($('<span class="bv-private-label">').text(i18n.private || 'Private'));
            }
        };
        $form.on('click', '.bv-value-visibility', function (e) {
            e.preventDefault();
            var $value = $(this).closest('.value');
            setPublic($value, !$value.data('isPublic'));
        });

        var makeValue = function ($group, type) {
            var $tpl = templateFor(type);
            if (!$tpl.length) {
                return null;
            }
            var $value = $tpl.clone(true);
            $value.removeClass('template');
            $value.data('dataType', type);
            // Only the inputs matter here: no sorting, annotation or per-value actions.
            $value.find('.sortable-handle, .input-footer').remove();
            $value.find(':input').each(function () {
                if ($(this).data('valueKey')) {
                    $(this).removeAttr('name').val('');
                }
            });
            $value.append($('<a href="#" class="bv-remove-value o-icon-delete">').attr('title', i18n.remove || '').attr('aria-label', i18n.remove || ''));
            if (!single) {
                // Visibility of this value only (the eye of the item edit page).
                var $eye = $('<a href="#" class="bv-value-visibility">');
                $value.append($eye);
                setPublic($value, String($group.data('default-public')) !== '0');
            }
            if (type === 'resource' || type.indexOf('resource:') === 0) {
                setupResourcePicker($value, type);
            }
            // Same event as the item edit page, so data type modules set up
            // their own widgets (autocomplete, term selectors, etc.).
            $(document).trigger('o:prepare-value', [type, $value]);
            var lang = $group.data('default-lang');
            if (lang) {
                $value.find('.language-wrapper').addClass('active');
                $value.find('input.value-language').val(lang);
            }
            return $value;
        };

        var currentType = function ($group) {
            return $group.find('.bv-type-select').val() || $group.data('default-type');
        };

        var addValue = function ($group) {
            var type = currentType($group);
            if (!type) {
                return;
            }
            var $value = makeValue($group, type);
            if ($value) {
                $group.find('.bv-values').append($value);
                $value.find(':input:visible').first().trigger('focus');
            }
        };

        $box.find('.bv-group').each(function () {
            var $group = $(this);
            var def = $group.data('default-type');
            if (def) {
                $group.find('.bv-type-select').val(def);
            }
            if ($group.find('.bv-type-select').length) {
                addValue($group);
                // Do not steal focus on page load.
                $(document.activeElement).trigger('blur');
            }
        });

        $form.on('change', '.bv-type-select', function () {
            var $group = $(this).closest('.bv-group');
            $group.find('.bv-values').empty();
            addValue($group);
        });

        $form.on('click', '.bv-add-another', function (e) {
            e.preventDefault();
            addValue($(this).closest('.bv-group'));
        });

        $form.on('click', '.bv-remove-value', function (e) {
            e.preventDefault();
            $(this).closest('.value').remove();
        });

        // Value Suggest ("from this Omeka, same template") reads the template
        // of the item being edited: give it the template of the group typed in.
        $form.on('focusin', '.bv-group .value', function () {
            var tid = String($(this).closest('.bv-group').data('template-id') || '');
            $('#resource-template-select').val(tid === '0' ? '' : tid);
        });

        $form.on('change', '.bv-separately-input', function () {
            $box.find('.bv-group-all').prop('hidden', this.checked);
            $box.find('.bv-groups-separate').prop('hidden', !this.checked);
        });

        $form.on('change', '.bv-skip-input', function () {
            $(this).closest('.bv-group').toggleClass('bv-skipped', this.checked);
        });

        // Language toggle, as on the item edit page.
        $form.on('click', 'a.value-language', function (e) {
            e.preventDefault();
            var $wrapper = $(this).closest('.language-wrapper');
            $wrapper.toggleClass('active');
            if ($wrapper.hasClass('active')) {
                $wrapper.find('input.value-language').trigger('focus');
            }
        });

        var readValues = function ($group) {
            var values = [];
            $group.find('.bv-values > .value').each(function () {
                var $value = $(this);
                var obj = {type: $value.data('dataType') || $value.attr('data-data-type')};
                if (typeof $value.data('isPublic') !== 'undefined') {
                    obj.is_public = $value.data('isPublic') ? 1 : 0;
                }
                $value.find(':input[data-value-key]').each(function () {
                    var $input = $(this);
                    var key = $input.data('valueKey');
                    if (!key || $input.prop('disabled')) {
                        return;
                    }
                    obj[key] = $input.val();
                });
                values.push(obj);
            });
            return values;
        };

        $form.on('submit', function () {
            var groups = {};
            var $all = $box.find('.bv-group-all');
            var merged = $all.length && !$box.find('.bv-separately-input').prop('checked');
            if (merged) {
                // The same values for every template; each is checked on the
                // server against its own template.
                var values = readValues($all);
                $box.find('.bv-groups-separate .bv-group').each(function () {
                    groups[$(this).data('key')] = {skip: false, values: values};
                });
                $box.find('input[name="groups_json"]').val(JSON.stringify(groups));
                return;
            }
            $box.find('.bv-groups-separate .bv-group').each(function () {
                var $group = $(this);
                var values = readValues($group);
                groups[$group.data('key')] = {
                    skip: $group.find('.bv-skip-input').prop('checked') || !$group.find('.bv-type-select').length,
                    values: values
                };
            });
            $box.find('input[name="groups_json"]').val(JSON.stringify(groups));
        });
    }

    // ------------------------------------------------------------------
    // Our own long selects (field, resource class): the same Chosen as
    // Omeka, minus the group name in the selected line. A group name like
    // "In the templates of these resources" doubles the length of the line
    // once a field is picked and pushes the bar onto a second row.
    // (Omeka does the same for the sidebar selects in resource-selector.js.)
    // ------------------------------------------------------------------
    function initSelects($scope) {
        var $selects = ($scope || $(document)).find('select.bv-chosen');
        if (!$selects.length || !$.fn.chosen) {
            return;
        }
        var options = $.extend({}, (typeof chosenOptions === 'object' && chosenOptions) || {
            allow_single_deselect: true,
            disable_search_threshold: 10,
            width: '100%'
        });
        options.include_group_label_in_selected = false;
        $selects.each(function () {
            var $select = $(this);
            if ($select.next('.chosen-container').length) {
                return;
            }
            $select.chosen(options);
        });
    }

    function initChooser() {
        var $choose = $('.bv-choose');
        if (!$choose.length) {
            return;
        }
        var sync = function () {
            var $checked = $choose.find('input[name="operation"]:checked');
            var needs = !$checked.length || String($checked.data('needs-property')) === '1';
            $choose.find('.bv-property-field').prop('hidden', !needs);
            // "[All properties]" only for actions that can do it.
            var allOk = $checked.length && String($checked.data('all-properties')) === '1';
            var $select = $choose.find('#bv-property');
            var $allOption = $select.find('option[value="all"]');
            $allOption.prop('disabled', !allOk);
            if (!allOk && $select.val() === 'all') {
                $select.val('');
            }
            if ($select.data('chosen') || $select.next('.chosen-container').length) {
                $select.trigger('chosen:updated');
            }
            $choose.find('.bv-op').removeClass('is-picked');
            $checked.closest('.bv-op').addClass('is-picked');
            if ($checked.length) {
                $choose.find('.bv-choose-picked').removeClass('bv-muted')
                    .text($checked.closest('.bv-op').find('.bv-op-text > strong').first().text());
            }
        };
        $choose.on('change', 'input[name="operation"]', sync);
        sync();

        // One group open at a time (not while searching).
        $choose.find('.bv-menu-group').on('toggle', function () {
            if (this.open && !$choose.hasClass('bv-searching')) {
                $choose.find('.bv-menu-group').not(this).prop('open', false);
            }
        });

        // Search box: every word typed must appear in the name, help,
        // example or other words of an action.
        var $filter = $choose.find('.bv-menu-filter');
        $filter.on('input', function () {
            var words = $(this).val().toLowerCase().split(/\s+/).filter(function (w) {
                return w !== '';
            });
            var any = false;
            $choose.find('.bv-menu-group').each(function () {
                var $group = $(this);
                var shown = 0;
                $group.find('.bv-op').each(function () {
                    var text = String($(this).data('search') || '');
                    var ok = words.every(function (w) {
                        return text.indexOf(w) !== -1;
                    });
                    $(this).toggle(ok);
                    if (ok) {
                        shown++;
                    }
                });
                $group.toggle(shown > 0);
                // While searching, every group with a match is open.
                if (words.length) {
                    $group.prop('open', shown > 0);
                }
                any = any || shown > 0;
            });
            if (!words.length) {
                // Back to one open group: the one of the chosen action.
                $choose.find('.bv-menu-group').each(function () {
                    $(this).prop('open', $(this).find('input[name="operation"]:checked').length > 0);
                });
            }
            $choose.toggleClass('bv-searching', words.length > 0);
            $choose.find('.bv-menu-none').prop('hidden', any);
        });
        $filter.on('keydown', function (e) {
            // Enter picks the first action shown instead of sending the form.
            if (e.key === 'Enter') {
                e.preventDefault();
                $choose.find('.bv-op:visible').first().find('input[name="operation"]').prop('checked', true).trigger('change');
            }
        });
    }

    /**
     * Blocks shown only for one choice of a radio group:
     * data-switch-show="name=value" or data-switch-hide="name=value".
     */
    function initSwitches($form) {
        var sync = function () {
            $form.find('[data-switch-show], [data-switch-hide]').each(function () {
                var $el = $(this);
                var show = $el.is('[data-switch-show]');
                var spec = String($el.attr(show ? 'data-switch-show' : 'data-switch-hide')).split('=');
                var current = $form.find('input[name="' + spec[0] + '"]:checked').val();
                var match = current === spec[1];
                $el.prop('hidden', show ? !match : match);
            });
        };
        $form.on('change', 'input[type="radio"]', sync);
        sync();
    }

    // ------------------------------------------------------------------
    // Accession numbers: the numbers of one run, in a popup.
    //
    // A run can hold hundreds of numbers and the archive holds hundreds of
    // runs, so the page carries only the shape of each run; the numbers
    // themselves are read when the run is pressed (0.12.3).
    // ------------------------------------------------------------------
    function initAccessionRuns() {
        var $dialog = $('.bv-run-dialog');
        if (!$dialog.length) {
            return;
        }
        var dialog = $dialog[0];
        var $list = $dialog.find('.bv-run-list');
        var held = {};
        var draw = function (data) {
            var rows = (data && data.rows) || [];
            if (!rows.length) {
                $list.html($('<p class="bv-muted">').text($list.data('none')));
                return;
            }
            var $head = $('<p class="bv-muted bv-run-count">').text(
                String($list.data('counts') || '').replace('%1', data.values).replace('%2', data.numbers));
            var $ul = $('<ul class="bv-run-numbers">');
            $.each(rows, function (i, row) {
                var $li = $('<li>').attr('data-serial', row.serial);
                var $thumb = $('<span class="bv-thumb">');
                if (row.thumb) {
                    $('<img>').attr({src: row.thumb, alt: '', loading: 'lazy'}).appendTo($thumb);
                } else {
                    $('<span class="bv-thumb-none o-icon-items" aria-hidden="true">').appendTo($thumb);
                }
                $thumb.appendTo($li);
                var $text = $('<span class="bv-run-text">');
                $('<code>').text(row.value).appendTo($text);
                $('<a>').attr('href', row.url).text(row.title || ('#' + row.id)).appendTo($text);
                $('<span class="bv-muted">').text('#' + row.id).appendTo($text);
                $text.appendTo($li);
                $ul.append($li);
            });
            $list.empty().append($head).append($ul);
        };
        var open = function () {
            if (typeof dialog.showModal === 'function') {
                if (!dialog.open) {
                    dialog.showModal();
                }
            } else {
                $dialog.attr('open', 'open');
            }
        };
        var close = function () {
            if (typeof dialog.close === 'function') {
                dialog.close();
            } else {
                $dialog.removeAttr('open');
            }
        };
        $('.bv-accession-runs').on('click', '.bv-run-toggle', function () {
            var url = $(this).data('url');
            $dialog.find('.bv-run-dialog-title').text($(this).text());
            $list.scrollTop(0);
            open();
            if (held[url]) {
                draw(held[url]);
                return;
            }
            $list.html($('<p class="bv-muted">').text($list.data('loading')));
            $.getJSON(url).done(function (data) {
                held[url] = data;
                draw(data);
            }).fail(function () {
                $list.html($('<p class="bv-muted">').text($list.data('failed')));
            });
        });
        $dialog.on('click', '.bv-run-close', close);
        // Pressing the dark area around the popup closes it, as it does in
        // Omeka's own dialogs.
        $dialog.on('click', function (e) {
            if (e.target === dialog) {
                close();
            }
        });
    }

    $(function () {
        initSelects();
        initChooser();
        initAccessionRuns();
        var $form = $('#bv-op-form');
        if (!$form.length) {
            return;
        }
        $form.find('.bv-picker-block').each(function () {
            initPicker($(this));
        });
        initSwitches($form);
        $form.on('input', '.bv-asset-filter', function () {
            var q = $(this).val().toLowerCase().trim();
            $form.find('.bv-asset').each(function () {
                $(this).toggle(q === '' || String($(this).data('name')).indexOf(q) !== -1);
            });
        });
        $form.find('.bv-typed-groups').each(function () {
            initTypedGroups($form, $(this));
        });
        $form.on('submit', function (e) {
            var $picker = $form.find('.bv-picker-block');
            if ($picker.length && $picker.is(':visible') && $picker.find('.bv-distinct').length
                && $picker.find('input[name="pick_mode"]:checked').val() === 'list'
                && !$picker.find('input[name="keys[]"]:checked').length) {
                e.preventDefault();
                $picker.addClass('bv-attention');
                $('html, body').animate({scrollTop: $picker.offset().top - 80}, 200);
            }
        });
    });
})(jQuery);
