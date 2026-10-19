/*
 * Omeka's Items, Item sets and Media lists: the thumbnail of each row opens
 * the quick look (batch-values-peek.js). The row's resource id is read from
 * the address of its link.
 */
(function ($) {
    $(function () {
        var $cfg = $('#batchvalues-browse-peek');
        var $table = $('table.batch-edit, #content table.tablesaw').first();
        if (!$cfg.length || !$table.length) {
            return;
        }
        var url = String($cfg.data('url'));
        var title = String($cfg.data('title'));
        $table.attr('data-peek-list', '');
        $table.find('tbody tr').each(function () {
            var $link = $(this).find('a.resource-link').first();
            var $img = $link.find('img').first();
            var m = String($link.attr('href') || '').match(/\/(\d+)(?:[/?#]|$)/);
            if (!m) {
                return;
            }
            var peek = url.replace('987654321', m[1]);
            if ($img.length) {
                $img.attr('data-peek-url', peek)
                    .attr('title', title)
                    .addClass('bv-peek-trigger');
                return;
            }
            // No thumbnail (often item sets): a small button before the title.
            $('<button type="button" class="bv-peek-mini o-icon-search"></button>')
                .attr('data-peek-url', peek)
                .attr('title', title)
                .attr('aria-label', title)
                .insertBefore($link);
        });
    });
})(jQuery);
