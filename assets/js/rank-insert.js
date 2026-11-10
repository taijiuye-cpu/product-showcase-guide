/**
 * 后台：编辑器「插入排行榜」按钮 · 参数弹窗 v4
 * 用原生浮层（不依赖 jQuery UI dialog），手机端全宽可滚动、按钮常驻底部。
 * 选显示前几名 → 插 [sales_rank count="N"]。
 */
(function ($) {
    'use strict';

    function insertShortcode(text) {
        if (typeof window.tinymce !== 'undefined' &&
            window.tinymce.activeEditor &&
            !window.tinymce.activeEditor.isHidden()) {
            window.tinymce.activeEditor.execCommand('mceInsertContent', false, text);
            return;
        }
        var textarea = document.getElementById('content');
        if (!textarea) return;
        var start = textarea.selectionStart;
        var end = textarea.selectionEnd;
        textarea.value = textarea.value.substring(0, start) + text + textarea.value.substring(end);
        textarea.selectionStart = textarea.selectionEnd = start + text.length;
        textarea.focus();
    }

    function shortcode(n) {
        n = parseInt(n, 10);
        if (!n || n < 1) n = 3;
        if (n > 10) n = 10;
        return '[sales_rank count="' + n + '"]';
    }

    $(function () {
        var btn = $('#as-insert-rank-btn');
        if (!btn.length) return;

        btn.on('click', function (e) {
            e.preventDefault();

            var $ov = $('<div class="as-rank-overlay"></div>');
            var $box = $('<div class="as-rank-modal" role="dialog" aria-modal="true" aria-label="插入销量排行榜"></div>');

            var $hd = $('<div class="as-rank-modal-hd"></div>').appendTo($box);
            $('<h3></h3>').text('插入销量排行榜').appendTo($hd);
            var $x = $('<button type="button" class="as-rank-x" aria-label="关闭">&times;</button>').appendTo($hd);

            var $bd = $('<div class="as-rank-modal-bd"></div>').appendTo($box);
            $('<div class="as-rank-picker-label"></div>').text('显示前几名').appendTo($bd);

            var $chips = $('<div class="as-rank-chips"></div>').appendTo($bd);
            $.each([3, 5, 10], function (_, n) {
                $('<button type="button" class="as-rank-chip"></button>')
                    .attr('data-n', n)
                    .text('前 ' + n + ' 名')
                    .toggleClass('on', n === 3)
                    .appendTo($chips);
            });

            var $row = $('<div class="as-rank-custom"></div>').appendTo($bd);
            $('<label for="as-rank-count"></label>').text('自定义（1–10）').appendTo($row);
            var $input = $('<input type="number" id="as-rank-count" min="1" max="10" step="1" value="3">')
                .appendTo($row);

            $('<p class="as-rank-hint"></p>')
                .text('榜单按当前销量动态生成，销量变化后文章不用改。')
                .appendTo($bd);

            var $ft = $('<div class="as-rank-modal-ft"></div>').appendTo($box);
            var $cancel = $('<button type="button" class="as-rank-cancel">取消</button>').appendTo($ft);
            var $ok = $('<button type="button" class="as-rank-confirm">插入</button>').appendTo($ft);

            $chips.on('click', '.as-rank-chip', function () {
                $chips.find('.as-rank-chip').removeClass('on');
                $(this).addClass('on');
                $input.val($(this).attr('data-n'));
            });
            $input.on('input', function () {
                var v = parseInt($(this).val(), 10);
                $chips.find('.as-rank-chip').each(function () {
                    $(this).toggleClass('on', parseInt($(this).attr('data-n'), 10) === v);
                });
            });

            function close() {
                $(document).off('keydown.asRankModal');
                $ov.remove();
            }

            $x.on('click', close);
            $cancel.on('click', close);
            $ov.on('click', function (e) { if (e.target === $ov[0]) close(); });
            $(document).on('keydown.asRankModal', function (e) {
                if (e.key === 'Escape') close();
            });
            $ok.on('click', function () {
                insertShortcode(shortcode($input.val()));
                close();
            });

            $('body').append($ov.append($box));
        });
    });
})(jQuery);
