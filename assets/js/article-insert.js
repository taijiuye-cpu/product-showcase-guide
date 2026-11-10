/**
 * 后台：编辑器「插入文章」按钮
 * 默认列最近 10 篇，输入关键词后在全部文章里过滤。
 * 用 DOM 构建节点（不拼字符串），避免文章标题里的引号/尖括号破坏结构。
 */
(function ($) {
    'use strict';

    $(function () {
        var btn = $('#as-insert-article-btn');
        if (!btn.length) return;

        btn.on('click', function (e) {
            e.preventDefault();
            openArticleDialog();
        });

        function openArticleDialog() {
            var articles = window.asArticles || [];

            if (!articles.length) {
                window.alert('还没有发布任何文章，先去写两篇再回来。');
                return;
            }

            var $wrap = $('<div class="as-article-picker"></div>');

            $('<input type="search" id="as-article-search" placeholder="搜索文章标题…" autocomplete="off">')
                .appendTo($wrap);

            var $list = $('<div class="as-article-list"></div>').appendTo($wrap);
            var $hint = $('<p class="as-article-hint"></p>').appendTo($wrap);

            function render(kw) {
                kw = String(kw || '').toLowerCase().trim();

                var pool = articles;
                if (kw) {
                    pool = $.grep(articles, function (a) {
                        return String(a.title).toLowerCase().indexOf(kw) !== -1;
                    });
                }

                // 默认最近 10 篇；搜索时最多显示 20 条，避免长列表拖慢手机
                var limit = kw ? 20 : 10;
                var shown = pool.slice(0, limit);

                $list.empty();

                if (!shown.length) {
                    $('<div class="as-article-empty"></div>')
                        .text('没有匹配的文章')
                        .appendTo($list);
                    $hint.text('换个关键词试试。');
                    return;
                }

                $.each(shown, function (_, a) {
                    var $item = $('<div class="as-article-item"></div>')
                        .attr('data-id', a.id)
                        .attr('role', 'button')
                        .attr('tabindex', '0');

                    $('<strong></strong>').text(a.title).appendTo($item);
                    $('<span class="as-article-meta"></span>')
                        .text([a.cat, a.date].filter(Boolean).join(' · '))
                        .appendTo($item);

                    $item.appendTo($list);
                });

                $hint.text(kw
                    ? '找到 ' + pool.length + ' 篇，显示前 ' + shown.length + ' 篇。点一下即可插入。'
                    : '默认显示最近 10 篇；搜索可查全部 ' + articles.length + ' 篇。点一下即可插入。');
            }

            render('');

            // jQuery UI dialog 固定 520 会在 375px 屏溢出，按视口收窄
            var dlgWidth = Math.min(520, Math.max(280, $(window).width() - 24));

            var $dialog = $('<div></div>').append($wrap).dialog({
                title: '插入文章',
                width: dlgWidth,
                modal: true,
                resizable: false,
                dialogClass: 'as-article-dialog',
                buttons: {
                    '关闭': function () {
                        $(this).dialog('close');
                    }
                },
                close: function () {
                    $(this).dialog('destroy').remove();
                }
            });

            $dialog.find('#as-article-search').on('input', function () {
                render($(this).val());
            });

            $dialog.on('click', '.as-article-item', function () {
                var id = parseInt($(this).attr('data-id'), 10);
                if (!id) return;
                insertShortcode('[article_card id="' + id + '"]');
                $dialog.dialog('close');
            });

            // 键盘也能选中
            $dialog.on('keydown', '.as-article-item', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    $(this).trigger('click');
                }
            });
        }

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
    });
})(jQuery);
