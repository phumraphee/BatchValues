/*
 * Adds one "Add/remove values" entry to the batch actions of the admin items
 * browse page. Ticked items are used when there are any, otherwise every item
 * of the current search. Choosing the entry opens Batch Values at once (no Go
 * needed): nothing is written before its preview is confirmed.
 */
(function ($) {
    $(function () {
        var $cfg = $('#batchvalues-browse');
        var $select = $('.batch-actions-select');
        var $form = $('#batch-form');
        if (!$cfg.length || !$select.length || !$form.length) {
            return;
        }
        if (String($cfg.data('hide-core')) === '1') {
            // Omeka's own batch edit is replaced by Batch Values (module
            // setting); deleting stays where it is.
            $select.find('option[value="update-selected"], option[value="update-all"]').remove();
            $('.batch-actions .update-selected, .batch-actions .update-all').remove();
        }
        var $option = $('<option>', {value: 'batchvalues'});
        // Right after the edit entries (or where they were), above the
        // delete ones.
        var $delete = $select.find('option[value^="delete"]').first();
        if ($delete.length) {
            $option.insertBefore($delete);
        } else {
            $select.append($option);
        }
        var $mode = $('<input>', {type: 'hidden', name: 'batchvalues_from_browse', value: 'all', disabled: true});
        $form.append($mode);

        var ticked = function () {
            return $('.batch-edit td input[name="resource_ids[]"]:checked').length;
        };
        var label = function () {
            var n = ticked();
            $option.text(n
                ? $cfg.data('label-selected').replace('%d', n)
                : $cfg.data('label-all'));
        };
        $(document).on('change', '.batch-edit td input[type="checkbox"], .select-all', function () {
            setTimeout(label, 0);
        });
        label();

        $select.on('change', function () {
            if ($select.val() !== 'batchvalues') {
                return;
            }
            $mode.val(ticked() ? 'selected' : 'all').prop('disabled', false);
            $form.attr('action', $cfg.data('url'));
            // Keep Omeka's unsaved-changes warning out of the way.
            $form.addClass('disable-unsaved-warning');
            $form[0].submit();
        });
        // Coming back with the browser's Back button: reset the menu.
        $(window).on('pageshow', function () {
            if ($select.val() === 'batchvalues') {
                $select.val('default');
            }
            $mode.prop('disabled', true);
        });
    });
})(jQuery);
