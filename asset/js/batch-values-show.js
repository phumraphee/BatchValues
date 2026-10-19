/* Live progress of a running change set. */
(function ($) {
    $(function () {
        var $p = $('.bv-progress');
        if (!$p.length || $p.data('running') !== 1) {
            return;
        }
        var url = $p.data('progress-url');
        var tick = function () {
            $.getJSON(url).done(function (d) {
                if (d.error) {
                    return;
                }
                var pct = d.total ? Math.round(100 * d.processed / d.total) : 0;
                $p.find('.bv-progress-bar span').css('width', pct + '%');
                $p.find('.bv-p-processed').text(d.processed);
                $p.find('.bv-p-changed').text(d.changed);
                $p.find('.bv-p-skipped').text(d.skipped);
                $p.find('.bv-p-failed').text(d.failed);
                if (d.status === 'running') {
                    setTimeout(tick, 2000);
                } else {
                    window.location.reload();
                }
            }).fail(function () {
                setTimeout(tick, 5000);
            });
        };
        setTimeout(tick, 1500);
    });
})(jQuery);
