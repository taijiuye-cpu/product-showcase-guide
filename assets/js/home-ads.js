/**
 * 后台：首页广告区编辑器（v2.75.0）
 *
 * 三件事：
 *   ① 「跳哪个」—— 输入关键词 → AJAX 搜商品/文章 → 点结果填入 ID
 *   ② 「添加一条」 —— 复制最后一行的结构，索引 +1（受上限约束）
 *   ③ 「移除」 —— 删掉这一行（至少保留一行，否则页面没入口）
 *
 * 依赖：jQuery（后台自带）、页面里的 wp nonce（写在 #as-home-ads 的 data 上会更稳，
 * 这里直接从隐藏的 nonce 字段取）。
 */
(function ($) {
    'use strict';

    // 由 wp_localize_script('as-home-ads') 注入，避免依赖全局 ajaxurl / 表单里的 nonce 字段
    var CFG = (typeof asHomeAds !== 'undefined') ? asHomeAds : {};
    var AJAX = CFG.ajaxUrl || '/wp-admin/admin-ajax.php';

    function nonce() {
        return CFG.nonce || '';
    }

    /* ① 关键词搜索：输入停顿 300ms 再发请求，避免每敲一个字都查一次 */
    $(document).on('input', '.as-ad-search', function () {
        var $input = $(this);
        var $row = $input.closest('.as-ad-row');
        var kw = $.trim($input.val());
        var type = $row.find('.as-ad-type').val();
        var $box = $row.find('.as-ad-results');

        if (kw.length < 1) {
            $box.empty();
            return;
        }

        clearTimeout($input.data('timer'));
        $input.data('timer', setTimeout(function () {
            $.post(AJAX, {
                action: 'as_ads_search',
                nonce: nonce(),
                kw: kw,
                type: type
            }, function (res) {
                if (!res || !res.success || !res.data) {
                    $box.html('<span style="color:#9aa0a8;">没有匹配的结果</span>');
                    return;
                }
                if (!res.data.length) {
                    $box.html('<span style="color:#9aa0a8;">没有匹配的结果</span>');
                    return;
                }
                var ul = $('<ul style="margin:0;padding:0;list-style:none;"></ul>');
                $.each(res.data, function (i, item) {
                    var li = $('<li></li>').css({
                        padding: '6px 8px',
                        cursor: 'pointer',
                        borderBottom: '1px solid #f0f0f1'
                    }).text(item.title + '（ID ' + item.id + '）');
                    li.on('click', function () {
                        // 填入隐藏 ID + 回显标题，让用户确认没选错
                        $row.find('.as-ad-target').val(item.id);
                        $row.find('.as-ad-picked').html('✅ 已选：<span></span>（ID ' + item.id + '）');
                        $row.find('.as-ad-picked span').text(item.title);
                        $box.empty();
                        $input.val('');
                    });
                    ul.append(li);
                });
                $box.html(ul);
            });
        }, 300));
    });

    /* 切换类型时清掉已选目标 —— 商品 ID 和文章 ID 不是一回事，留着会跳错 */
    $(document).on('change', '.as-ad-type', function () {
        var $row = $(this).closest('.as-ad-row');
        $row.find('.as-ad-target').val(0);
        $row.find('.as-ad-picked').html('<span style="color:#9aa0a8;">未选择目标</span>');
        $row.find('.as-ad-results').empty();
    });

    /* ② 添加一条 */
    $('#as-ad-add').on('click', function () {
        var max = parseInt($(this).data('max'), 10) || 6;
        var $rows = $('#as-home-ads .as-ad-row');
        if ($rows.length >= max) {
            alert('最多 ' + max + ' 条广告。');
            return;
        }

        var $last = $rows.last();
        var $clone = $last.clone();

        // 重编号：把行里所有 as_home_ads[N] 的索引换成新的
        var newIndex = $rows.length;
        $clone.find('[name^="as_home_ads["]').each(function () {
            var name = $(this).attr('name');
            $(this).attr('name', name.replace(/as_home_ads\[\d+\]/, 'as_home_ads[' + newIndex + ']'));
        });
        // id 也要跟着换，否则「从媒体库选择」会填错行
        $clone.find('[id^="as_ad_img_"]').each(function () {
            $(this).attr('id', 'as_ad_img_' + newIndex);
        });
        $clone.find('.as-pick-img').attr('data-target', 'as_ad_img_' + newIndex);

        // 清空内容
        $clone.find('input[type="url"]').val('');
        $clone.find('.as-ad-target').val(0);
        $clone.find('.as-ad-search').val('');
        $clone.find('.as-ad-results').empty();
        $clone.find('.as-ad-picked').html('<span style="color:#9aa0a8;">未选择目标</span>');
        $clone.find('img').remove();
        $clone.find('th').text('#' + (newIndex + 1));

        $last.after($clone);
    });

    /* ③ 移除一条（至少留一行） */
    $(document).on('click', '.as-ad-remove', function () {
        var $rows = $('#as-home-ads .as-ad-row');
        if ($rows.length <= 1) {
            // 只剩一行时不删，改成清空内容，免得页面变成没有入口
            var $row = $rows.first();
            $row.find('input[type="url"]').val('');
            $row.find('.as-ad-target').val(0);
            $row.find('.as-ad-picked').html('<span style="color:#9aa0a8;">未选择目标</span>');
            $row.find('img').remove();
            return;
        }
        $(this).closest('.as-ad-row').remove();
        // 重排序号
        $('#as-home-ads .as-ad-row').each(function (i) {
            $(this).find('th').text('#' + (i + 1));
        });
    });
})(jQuery);
