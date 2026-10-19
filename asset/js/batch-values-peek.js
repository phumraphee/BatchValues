/*
 * Quick look: a click on a resource in a Batch Values list opens a popup
 * with its picture and its values, instead of leaving the page. ← → go to
 * the previous / next resource of the same list, Esc closes. Ctrl/Cmd-click
 * still opens the resource page.
 */
(function ($) {
    'use strict';
    var cache = {};
    var $overlay = null;
    var list = [];
    var index = -1;

    function build() {
        if ($overlay) {
            return;
        }
        var t = $('#bv-peek-i18n').data() || {};
        $overlay = $(
            '<div class="bv-peek-overlay" role="dialog" aria-modal="true" hidden>' +
                '<div class="bv-peek-panel">' +
                    '<div class="bv-peek-nav">' +
                        '<button type="button" class="bv-peek-prev" aria-label="' + (t.prev || 'Previous') + '">‹</button>' +
                        '<span class="bv-peek-count"></span>' +
                        '<button type="button" class="bv-peek-next" aria-label="' + (t.next || 'Next') + '">›</button>' +
                        '<button type="button" class="bv-peek-close" aria-label="' + (t.close || 'Close') + '">×</button>' +
                    '</div>' +
                    '<div class="bv-peek-content"></div>' +
                '</div>' +
            '</div>'
        ).appendTo('body');
        $overlay.on('click', function (e) {
            if (e.target === this) {
                close();
            }
        });
        $overlay.on('click', '.bv-peek-close', close);
        $overlay.on('click', '.bv-peek-prev', function () { go(-1); });
        $overlay.on('click', '.bv-peek-next', function () { go(1); });
        $overlay.on('click', '.bv-peek-thumb', function () {
            var large = $(this).data('large');
            if (large) {
                $overlay.find('.bv-peek-image').attr('src', large);
                $overlay.find('.bv-peek-thumb').removeClass('is-current');
                $(this).addClass('is-current');
            }
        });
        // Phones: swipe left or right for the next or previous one.
        var touch = null;
        $overlay.on('touchstart', '.bv-peek-content', function (e) {
            var t = e.originalEvent.touches[0];
            touch = t ? {x: t.clientX, y: t.clientY} : null;
        });
        $overlay.on('touchend', '.bv-peek-content', function (e) {
            var t = e.originalEvent.changedTouches[0];
            if (!touch || !t) {
                return;
            }
            var dx = t.clientX - touch.x;
            var dy = t.clientY - touch.y;
            touch = null;
            if (Math.abs(dx) > 70 && Math.abs(dx) > 2 * Math.abs(dy)) {
                go(dx < 0 ? 1 : -1);
            }
        });
        $(document).on('keydown', function (e) {
            if (!$overlay || $overlay.prop('hidden')) {
                return;
            }
            if (e.key === 'Escape') {
                close();
            } else if (e.key === 'ArrowLeft') {
                go(-1);
            } else if (e.key === 'ArrowRight') {
                go(1);
            }
        });
    }

    function load(i) {
        index = i;
        var url = list[i];
        var $content = $overlay.find('.bv-peek-content');
        $overlay.find('.bv-peek-count').text((i + 1) + ' / ' + list.length);
        $content.scrollTop(0);
        $overlay.find('.bv-peek-prev').prop('disabled', i <= 0);
        $overlay.find('.bv-peek-next').prop('disabled', i >= list.length - 1);
        if (cache[url]) {
            $content.html(cache[url]);
            return;
        }
        $content.html('<div class="bv-peek-loading">…</div>');
        $.get(url).done(function (html) {
            cache[url] = html;
            if (list[index] === url) {
                $content.html(html);
            }
        }).fail(function () {
            $content.html('<p class="bv-note">Error</p>');
        });
        // Load the next one ahead of time.
        if (list[i + 1] && !cache[list[i + 1]]) {
            var next = list[i + 1];
            $.get(next).done(function (html) { cache[next] = html; });
        }
    }

    function go(step) {
        var i = index + step;
        if (i >= 0 && i < list.length) {
            load(i);
        }
    }

    function close() {
        $overlay.prop('hidden', true);
        $('body').removeClass('bv-peek-open');
    }

    // The quick look of a linked resource, from its address in the admin
    // (…/admin/item/12, …/admin/item-set/12, …/admin/media/12).
    var types = {'item': 'items', 'item-set': 'item_sets', 'media': 'media'};
    function peekUrlOf(href) {
        var cfg = $('#bv-peek-i18n');
        var template = String(cfg.data('url') || '');
        var m = String(href || '').match(/\/admin\/(item|item-set|media)\/(\d+)(?:[/?#]|$)/);
        if (!template || !m) {
            return null;
        }
        return template.replace('987654321', m[2]).replace('RESOURCETYPE', types[m[1]]);
    }

    function open(urls, i) {
        build();
        list = urls;
        $overlay.prop('hidden', false);
        $('body').addClass('bv-peek-open');
        load(Math.max(0, i));
        $overlay.find('.bv-peek-close').trigger('focus');
    }

    // Pictures of linked resources: in the quick look itself (to go on to a
    // related item), and on Omeka's show pages when "auto" is set. Only the
    // picture: the title stays a normal link.
    var autoSelector = '#content a.resource-link img, .bv-peek-values a.resource-link img';
    $(document).on('click', 'a.resource-link img:not([data-peek-url])', function (e) {
        if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button !== 0) {
            return;
        }
        var $img = $(this);
        var inPeek = $img.closest('.bv-peek-values').length > 0;
        if (!inPeek && !(String($('#bv-peek-i18n').data('auto')) === '1' && $img.is(autoSelector))) {
            return;
        }
        var url = peekUrlOf($img.closest('a.resource-link').attr('href'));
        if (!url) {
            return;
        }
        e.preventDefault();
        var $scope = $img.closest('[data-peek-list], .property, table, .meta-group, .bv-peek-values, #content');
        var $imgs = $scope.find('a.resource-link img:not([data-peek-url])').filter(function () {
            return peekUrlOf($(this).closest('a.resource-link').attr('href')) !== null;
        });
        var urls = $imgs.map(function () { return peekUrlOf($(this).closest('a.resource-link').attr('href')); }).get();
        open(urls, $imgs.index($img));
    });

    $(document).on('click', '[data-peek-url]', function (e) {
        if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button !== 0) {
            return;
        }
        e.preventDefault();
        build();
        var $link = $(this);
        var $scope = $link.closest('[data-peek-list]');
        var $links = $scope.length ? $scope.find('[data-peek-url]') : $link;
        open($links.map(function () { return $(this).data('peek-url'); }).get(), $links.index($link));
    });

    // Title on hover.
    $(document).on('mouseenter', 'a.resource-link img:not([data-peek-url])', function () {
        var $img = $(this);
        var auto = String($('#bv-peek-i18n').data('auto')) === '1' && $img.is(autoSelector);
        if ((auto || $img.closest('.bv-peek-values').length) && !$img.attr('title')) {
            $img.attr('title', String($('#bv-peek-i18n').data('title') || '')).addClass('bv-peek-trigger');
        }
    });
})(jQuery);
