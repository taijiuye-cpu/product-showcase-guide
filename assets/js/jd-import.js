/**
 * 后台：京东详情图导入弹窗（v2.87.107）
 * 依赖 jQuery（WP 后台自带）。只做两件事：
 *   1) as_jd_fetch  —— 粘贴链接，只读解析出详情图清单
 *   2) as_jd_import —— 把勾选的图下载进本站媒体库，返回 HTML 插入编辑器
 * 弹窗里的缩略图直接引用京东外链，仅用于后台挑选，绝不写进正文。
 */
(function ($) {
    'use strict';

    var cfg = window.asJdCfg || {};
    var S = { images: [], selected: {}, busy: false, sku: '' };
    var $mask, $input, $fetchBtn, $steps, $result, $grid, $source, $count, $insertBtn, $msg;

    function el(tag, cls, text) {
        var $e = $('<' + tag + '></' + tag + '>');
        if (cls) { $e.addClass(cls); }
        if (text !== undefined && text !== null) { $e.text(text); }
        return $e;
    }

    function showMsg(text, kind) {
        $msg.text(text || '').toggleClass('is-err', kind === 'err').toggleClass('is-ok', kind === 'ok');
        if (text) { $msg.addClass('show'); } else { $msg.removeClass('show'); }
    }

    function toast(text) {
        var $t = el('div', 'as-jd-toast', text).appendTo('body');
        setTimeout(function () { $t.addClass('out'); setTimeout(function () { $t.remove(); }, 320); }, 3300);
    }

    function build() {
        $mask = el('div', 'as-jd-mask').attr('id', 'as-jd-mask');
        var $dlg = el('div', 'as-jd-dlg').attr('role', 'dialog').attr('aria-label', '京东详情图导入');

        var $head = el('div', 'as-jd-head')
            .append(el('strong', '', '京东详情图导入'))
            .append(el('span', 'as-jd-tag', '只抓详情图 · 存到本站'))
            .append(el('button', 'as-jd-x', '×').attr({ type: 'button', 'aria-label': '关闭' }));

        var $body = el('div', 'as-jd-body');
        var $row = el('div', 'as-jd-inputrow');
        $input = el('input', '').attr({
            type: 'text',
            placeholder: '粘贴京东商品链接，如 https://item.jd.com/100012043978.html',
            'aria-label': '京东商品链接'
        });
        $fetchBtn = el('button', 'as-jd-primary', '抓取详情图').attr('type', 'button');
        $row.append($input).append($fetchBtn);
        $body.append($row);
        $body.append(el('p', 'as-jd-hint', '只提取图文详情里的长图，不含主图与图集；确认后才会下载到本站媒体库，图片只插入详情正文，不会进入商品图集。'));

        $msg = el('div', 'as-jd-msg');
        $body.append($msg);

        $steps = el('div', 'as-jd-steps');
        $body.append($steps);

        $result = el('div', 'as-jd-result');
        var $stat = el('div', 'as-jd-stat');
        $source = el('span', 'as-jd-source', '');
        var $acts = el('div', 'as-jd-acts')
            .append(el('button', 'as-jd-mini', '全选').attr({ type: 'button', 'data-act': 'all' }))
            .append(el('button', 'as-jd-mini', '反选').attr({ type: 'button', 'data-act': 'invert' }));
        $count = el('b', '', '0');
        $stat.append(el('span', '', '已选 ').append($count).append(document.createTextNode(' 张')))
             .append($acts).append($source);
        $grid = el('div', 'as-jd-grid');
        $result.append($stat).append($grid);
        $body.append($result);

        var $foot = el('div', 'as-jd-foot')
            .append(el('span', 'as-jd-footnote', '勾选的图片会下载到 /wp-content/uploads/，只插入本篇详情正文（不进商品图集）'));
        $insertBtn = el('button', 'as-jd-primary is-lg', '插入到编辑器').attr({ type: 'button', disabled: true });
        $foot.append($insertBtn);

        $dlg.append($head).append($body).append($foot);
        $mask.append($dlg).appendTo('body');

        $head.find('.as-jd-x').on('click', close);
        $mask.on('click', function (e) { if (e.target === $mask[0]) { close(); } });
        $(document).on('keydown.asjd', function (e) { if (e.key === 'Escape' && $mask.hasClass('show')) { close(); } });
        $fetchBtn.on('click', doFetch);
        $input.on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); doFetch(); } });
        $insertBtn.on('click', doInsert);
        $stat.on('click', '[data-act]', function () {
            var act = $(this).attr('data-act');
            S.images.forEach(function (im) {
                S.selected[im.url] = (act === 'all') ? true : !S.selected[im.url];
            });
            syncTiles();
            updateStat();
        });
    }

    function open() {
        if (!$mask) { build(); }
        $mask.addClass('show');
        setTimeout(function () { $input.trigger('focus'); }, 60);
    }
    function close() { if ($mask) { $mask.removeClass('show'); } }

    function runSteps() {
        $steps.empty().addClass('show');
        var labels = ['识别商品编号', '请求京东详情描述（含主商品 ID 回退）', '提取详情长图（含 CSS 背景图）', '整理图片清单'];
        var rows = [];
        labels.forEach(function (t) {
            var $r = el('div', 'as-jd-step').append(el('span', 'dot')).append(el('span', 'txt', t));
            $steps.append($r); rows.push($r);
        });
        var i = 0;
        var timer = setInterval(function () {
            if (i > 0) { rows[i - 1].removeClass('run').addClass('done'); }
            if (i < rows.length) { rows[i].addClass('run'); i++; }
            else { clearInterval(timer); }
        }, 300);
        return function () {
            clearInterval(timer);
            rows.forEach(function ($r) { $r.removeClass('run').addClass('done'); });
        };
    }

    function doFetch() {
        var value = $.trim($input.val());
        if (!value) { showMsg('请先粘贴京东商品链接，或直接填 SKU 数字。', 'err'); return; }
        if (S.busy) { return; }
        S.busy = true;
        $fetchBtn.prop('disabled', true).text('抓取中…');
        showMsg('');
        $result.removeClass('show');
        var finish = runSteps();
        $.post(cfg.ajaxUrl, { action: 'as_jd_fetch', nonce: cfg.nonce, url: value })
            .done(function (res) {
                finish();
                if (!res || !res.success) {
                    showMsg((res && res.data && res.data.message) || '抓取失败，请稍后重试。', 'err');
                    return;
                }
                S.images = res.data.images || [];
                S.sku = res.data.sku || '';
                S.selected = {};
                S.images.forEach(function (im) { S.selected[im.url] = true; });
                renderGrid(res.data.source || '', res.data.count || S.images.length);
                showMsg('抓到 ' + S.images.length + ' 张详情图，勾选后点「插入到编辑器」。', 'ok');
            })
            .fail(function (xhr) {
                finish();
                showMsg('请求失败（HTTP ' + xhr.status + '），请检查网络后重试。', 'err');
            })
            .always(function () {
                S.busy = false;
                $fetchBtn.prop('disabled', false).text('抓取详情图');
            });
    }

    function renderGrid(source, count) {
        $grid.empty();
        S.images.forEach(function (im, i) {
            var $tile = el('div', 'as-jd-tile sel').attr('data-i', i);
            var $img = $('<img>').attr({ src: im.url, alt: '详情图 ' + im.index, loading: 'lazy' });
            $img.on('error', function () {
                $tile.addClass('broken');
                $(this).replaceWith(el('div', 'as-jd-ph', '预览失败'));
            });
            $tile.append($img).append(el('span', 'no', '#' + im.index));
            $tile.on('click', function () {
                S.selected[im.url] = !S.selected[im.url];
                $tile.toggleClass('sel', !!S.selected[im.url]);
                updateStat();
            });
            $grid.append($tile);
        });
        $source.text('来源：' + source + ' · SKU ' + S.sku + ' · 共 ' + count + ' 张');
        $result.addClass('show');
        updateStat();
    }

    function syncTiles() {
        $grid.children('.as-jd-tile').each(function () {
            var i = parseInt($(this).attr('data-i'), 10);
            var im = S.images[i];
            $(this).toggleClass('sel', im ? !!S.selected[im.url] : false);
        });
    }

    function updateStat() {
        var n = 0;
        S.images.forEach(function (im) { if (S.selected[im.url]) { n++; } });
        $count.text(n);
        $insertBtn.prop('disabled', n === 0);
    }

    function insertToEditor(html) {
        // 经典编辑器两种模式：可视化 TinyMCE / 文本模式 textarea
        if (window.tinymce && window.tinymce.activeEditor && !window.tinymce.activeEditor.isHidden()) {
            window.tinymce.activeEditor.insertContent(html);
            return;
        }
        var ta = document.getElementById('content');
        if (ta) {
            ta.value = ta.value.replace(/\s*$/, '') + '\n\n' + html;
            ta.focus();
            return;
        }
        if (typeof window.send_to_editor === 'function') { window.send_to_editor(html); }
    }

    function doInsert() {
        var urls = [];
        S.images.forEach(function (im) { if (S.selected[im.url]) { urls.push(im.url); } });
        if (!urls.length || S.busy) { return; }
        var postId = parseInt($('#post_ID').val(), 10) || 0;
        S.busy = true;
        $insertBtn.prop('disabled', true).text('正在下载到媒体库…');
        $.post(cfg.ajaxUrl, {
            action: 'as_jd_import',
            nonce: cfg.nonce,
            post_id: postId,
            urls: urls
        }).done(function (res) {
            if (!res || !res.success) {
                showMsg((res && res.data && res.data.message) || '导入失败，请重试。', 'err');
                return;
            }
            insertToEditor(res.data.html);
            close();
            toast('已保存 ' + res.data.count + ' 张详情图到媒体库并插入编辑器' +
                (res.data.failed ? '（' + res.data.failed + ' 张下载失败）' : '') + '，记得点「更新」保存。');
        }).fail(function (xhr) {
            showMsg('导入请求失败（HTTP ' + xhr.status + '），请重试。', 'err');
        }).always(function () {
            S.busy = false;
            $insertBtn.prop('disabled', false).text('插入到编辑器');
            updateStat();
        });
    }

    $(function () {
        $('#as-jd-import-btn').on('click', function (e) {
            e.preventDefault();
            open();
        });
    });
})(jQuery);
