/**
 * 后台：品牌 Logo 选择器（新增 / 编辑品牌页共用）
 */
(function ($) {
    'use strict';

    $(function () {
        var btn = $('#brand_thumb_btn');
        if (!btn.length || typeof wp === 'undefined' || !wp.media) return;

        var frame = null;

        btn.on('click', function (e) {
            e.preventDefault();

            if (frame) {
                frame.open();
                return;
            }

            frame = wp.media({
                title: '选择品牌 Logo',
                button: { text: '使用这张图片' },
                library: { type: 'image' },
                multiple: false
            });

            frame.on('select', function () {
                var att = frame.state().get('selection').first().toJSON();
                $('#brand_thumb').val(att.id);
                btn.val('已选择：' + (att.filename || att.title || att.id));

                var preview = btn.siblings('p').find('img');
                var url = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
                if (preview.length) {
                    preview.attr('src', url);
                } else {
                    $('<p></p>').append($('<img>').attr('src', url).css({ maxWidth: '80px', borderRadius: '8px' }))
                        .insertAfter(btn);
                }
            });

            frame.open();
        });
    });
})(jQuery);
