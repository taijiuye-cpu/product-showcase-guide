/**
 * 后台：媒体库图片选择器（`.as-pick-img` 通用实现）
 *
 * 背景（v2.73.4 修）：这段逻辑原先**内联在 `inc/miniapp-admin.php` 的页面 `<script>` 里**，
 * 只在该页面渲染时输出。`inc/site-settings.php` 的「网站与小程序共用」区里
 * 也有同样class 的按钮（置顶小程序码等），但那两段JS 互不相干 →
 * 主题设置页的「从媒体库选择」点了没反应。
 *
 * 现在抽成独立文件，由两个页面各自 enqueue，一处实现两处可用。
 *
 * 用法：按钮写 `class="as-pick-img" data-target="字段id"`，目标 input 的 id 与之相同。
 *
 * 🔴 v2.87.59：新增 `data-mode="id"`
 *   · 默认（无 data-mode）：写**图片 URL**（媒体库普通字段，如二维码、分享图）
 *   · data-mode="id"：写**附件 ID**（网站 Logo —— WP 核心 custom_logo 要的就是 ID，
 *     用 URL 的话拿不到 alt 文本、换图后旧图残留在媒体库）
 *   · data-mode="id" 时还会顺带刷新旁边的缩略图预览。
 */
(function ($) {
    'use strict';

    $(function () {
        if (typeof wp === 'undefined' || !wp.media) return;

        $(document).on('click', '.as-pick-img', function (e) {
            e.preventDefault();

            var target = $('#' + $(this).data('target'));
            if (!target.length) return;

            var mode = $(this).data('mode') || 'url';

            // 复用已打开的弹窗，避免每次点击都新建一个
            var frame = wp.media({
                title: '选择图片',
                button: { text: '使用这张图片' },
                library: { type: 'image' },
                multiple: false
            });

            frame.on('select', function () {
                var att = frame.state().get('selection').first().toJSON();

                if ('id' === mode) {
                    target.val(att.id);
                    // 刷新预览缩略图（.as-logo-picker-prev 容器）
                    var prev = target.closest('.as-logo-picker').find('.as-logo-picker-prev');
                    if (prev.length) {
                        if (att.url) {
                            prev.removeClass('is-empty').html(
                                $('<img>', {
                                    src: att.url,
                                    alt: '',
                                    style: 'max-height:56px;max-width:220px;background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:4px;'
                                })
                            );
                        }
                    }
                } else {
                    target.val(att.url);
                }

                // 触发 change 让依赖该值的逻辑（如预览图）跟着更新
                target.trigger('change');
            });

            frame.open();
        });

        /*
         * 🔴 v2.87.59：网站 Logo 的「移除」按钮。
         * 清空隐藏域的值 + 清掉预览图 —— 光清值的话预览还在，用户以为没删。
         */
        $(document).on('click', '.as-logo-remove', function (e) {
            e.preventDefault();

            var target = $('#' + $(this).data('target'));
            if (!target.length) return;

            target.val('');

            var box = target.closest('.as-logo-picker');
            if (box.length) {
                box.find('.as-logo-picker-prev')
                    .addClass('is-empty')
                    .html('<span style="color:#8c8f94;">未设置 Logo</span>');
                box.find('.as-logo-remove').remove();
                var btn = box.find('.as-pick-img');
                if (btn.length) {
                    btn.text('从媒体库选择');
                }
                box.find('.as-logo-picker-ops .description').remove();
            }

            target.trigger('change');
        });
    });
})(jQuery);
