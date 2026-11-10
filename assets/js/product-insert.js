/**
 * 后台：编辑器"插入产品"按钮
 * 用 DOM 构建节点（而非字符串拼接），避免产品标题里的引号/尖括号破坏结构
 */
(function ($) {
    'use strict';

    $(function () {
        var btn = $('#as-insert-product-btn');
        if (!btn.length) return;

        btn.on('click', function (e) {
            e.preventDefault();
            openProductDialog();
        });

        function openProductDialog() {
            var products = window.asProducts || [];

            if (!products.length) {
                window.alert('还没有发布任何产品，请先去「商城产品」添加。');
                return;
            }

            var $wrap = $('<div class="as-product-picker"></div>');

            $('<input type="text" id="as-product-search" placeholder="搜索产品名称...">')
                .css({ width: '100%', padding: '8px', marginBottom: '12px', border: '1px solid #ddd', borderRadius: '6px' })
                .appendTo($wrap);

            var $list = $('<div id="as-product-list"></div>')
                .css({ maxHeight: '400px', overflowY: 'auto' })
                .appendTo($wrap);

            $.each(products, function (_, p) {
                var $item = $('<div class="as-product-item"></div>')
                    .attr('data-id', p.id)
                    .attr('data-title', p.title)
                    .css({ padding: '10px 12px', border: '1px solid #eee', borderRadius: '6px', marginBottom: '6px', cursor: 'pointer' })
                    .appendTo($list);

                $('<strong></strong>').text(p.title).appendTo($item);

                if (p.price) {
                    $('<span></span>').text(p.price).css({ color: '#c2185b', marginLeft: '8px' }).appendTo($item);
                }
            });

            $('<p></p>')
                .text('点击产品即可插入短代码，前台会显示为产品卡片。')
                .css({ marginTop: '12px', color: '#666', fontSize: '12px' })
                .appendTo($wrap);

            var $dialog = $('<div></div>').append($wrap).dialog({
                title: '插入产品',
                width: 500,
                modal: true,
                resizable: false,
                dialogClass: 'as-product-dialog',
                buttons: {
                    '关闭': function () {
                        $(this).dialog('close');
                    }
                },
                close: function () {
                    $(this).dialog('destroy').remove();
                }
            });

            $dialog.find('#as-product-search').on('input', function () {
                var kw = String($(this).val()).toLowerCase();
                $dialog.find('.as-product-item').each(function () {
                    var title = String($(this).attr('data-title') || '').toLowerCase();
                    $(this).toggle(title.indexOf(kw) !== -1);
                });
            });

            $dialog.find('.as-product-item').on('click', function () {
                var id = parseInt($(this).attr('data-id'), 10);
                if (!id) return;
                insertShortcode('[product_card id="' + id + '"]');
                $dialog.dialog('close');
            });
        }

        function insertShortcode(text) {
            if (typeof window.tinymce !== 'undefined' && window.tinymce.activeEditor && !window.tinymce.activeEditor.isHidden()) {
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
    });
})(jQuery);
