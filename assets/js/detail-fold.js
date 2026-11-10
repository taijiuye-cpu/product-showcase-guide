/**
 * 前台：商品详情长图折叠（v2.87.108 · A 方案：按高度折叠 + 渐隐）
 * ---------------------------------------------------------------------------
 * 规则：默认只露出开头一小部分（桌面 460px / 手机 340px，高度由 CSS 控制），
 *      底部渐隐蒙层 + 通栏「展开全部详情图」按钮；点击平滑展开，再点收起。
 *      不依赖图片张数，任意商品首屏高度一致。
 *
 * 🔴 零侵入 / 渐进增强：
 *   · 只认 <img class="as-jd-detail">，历史正文和手动插的图一律不动。
 *   · 容器 .as-detail-fold 与按钮都由本脚本在运行期创建 ——
 *     CSS 里不存在任何「默认隐藏」规则，禁用 JS 时所有图片天然全部显示，
 *     SEO / 爬虫 / 无脚本环境都不受影响。
 *   · 图片少于 3 张不生成按钮；中间夹了正文文字则不折叠（保守，不打乱顺序）。
 *
 * 🔴 v2.87.108：按「图片元素」计数，不按「图片块」计数。经典编辑器会把
 *    导入的 22 张长图合并进同一个 <p>，按块数只数到 1 块 → 折叠不生效、全屏平铺。
 *
 * 🔴 只用 max-height + overflow 隐藏，不用 transform / filter / backdrop-filter
 *    包容器 —— 这些属性会创建 containing block，破坏手机端 position:fixed 吸底购买条。
 */
(function () {
    'use strict';

    var MIN_IMAGES = 3;   // 少于这个数不折叠
    var ANIM_MS = 470;    // 与 CSS 的 max-height .45s 对齐

    var CHEV = '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3 6l5 5 5-5"/></svg>';

    function isBlank(node) {
        if (!node || node.nodeType !== 1) { return false; }
        if (node.querySelector('img')) { return false; }
        return (node.textContent || '').replace(/\s/g, '') === '';
    }

    /** 找出 node 所属的 container 顶层子元素下标 */
    function topIndex(container, node, kids) {
        while (node && node.parentNode !== container) { node = node.parentNode; }
        return node ? kids.indexOf(node) : -1;
    }

    function build(container) {
        if (container.dataset.foldReady === '1') { return; }
        container.dataset.foldReady = '1';

        var imgs = container.querySelectorAll('img.as-jd-detail');
        if (imgs.length < MIN_IMAGES) { return; }

        var kids = Array.prototype.slice.call(container.children);
        var first = topIndex(container, imgs[0], kids);
        var last = topIndex(container, imgs[imgs.length - 1], kids);
        if (first < 0 || last < first) { return; }

        // 中间夹了正文文字就放弃，避免把商品描述一起裁进渐隐区
        for (var j = first; j <= last; j++) {
            var kid = kids[j];
            if (kid.querySelectorAll && kid.querySelectorAll('img.as-jd-detail').length) { continue; }
            if (!isBlank(kid)) { return; }
        }

        var group = kids.slice(first, last + 1);
        var total = imgs.length;

        var fold = document.createElement('div');
        fold.className = 'as-detail-fold is-foldable';
        container.insertBefore(fold, group[0]);
        group.forEach(function (node) { fold.appendChild(node); });

        var veil = document.createElement('div');
        veil.className = 'as-detail-fold-veil';
        fold.appendChild(veil);

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'as-detail-fold-toggle';
        btn.setAttribute('aria-expanded', 'false');
        btn.innerHTML =
            '<span class="t">展开全部详情图</span>' +
            '<span class="n">共 ' + total + ' 张</span>' +
            '<span class="chev">' + CHEV + '</span>';
        container.insertBefore(btn, fold.nextSibling);

        var hint = document.createElement('p');
        hint.className = 'as-detail-fold-hint';
        hint.textContent = '默认只显示开头一小部分，点击展开查看完整图文详情';
        container.insertBefore(hint, btn.nextSibling);

        var timer = null;

        /*
         * v2.87.126：展开时把**当前视口内**的详情图改成 eager 立即下载。
         * 折叠态这些图是 loading="lazy"（原生懒加载，浏览器不下载），
         * 展开后容器一下撑到全高，未加载的图只剩带尺寸的空白占位 ——
         * 这里让看得见的那几张马上开始下载，其余的继续交给原生 lazy 滚动时加载。
         */
        function eagerVisibleImages() {
            var list = fold.querySelectorAll('img.as-jd-detail');
            var vh = window.innerHeight || 0;
            for (var i = 0; i < list.length; i++) {
                var im = list[i];
                if (im.getAttribute('data-as-eager') === '1') { continue; }
                var r = im.getBoundingClientRect();
                if (r.top < vh * 1.2 && r.bottom > 0) {
                    im.setAttribute('loading', 'eager');
                    im.setAttribute('data-as-eager', '1');
                }
            }
        }

        function setOpen(open) {
            if (timer) { clearTimeout(timer); timer = null; }
            if (open) {
                // 先给一个具体高度，max-height 才有过渡目标；过渡结束放开为 none，
                // 之后图片再加载也不会被裁掉。
                fold.style.maxHeight = fold.scrollHeight + 'px';
                fold.classList.add('is-open');
                eagerVisibleImages();
                timer = setTimeout(function () {
                    if (fold.classList.contains('is-open')) { fold.style.maxHeight = 'none'; }
                }, ANIM_MS);
            } else {
                fold.style.maxHeight = fold.scrollHeight + 'px';
                void fold.offsetHeight; // 强制 reflow，让回到 CSS 高度的过渡生效
                fold.classList.remove('is-open');
                requestAnimationFrame(function () {
                    requestAnimationFrame(function () { fold.style.maxHeight = ''; });
                });
            }
            btn.classList.toggle('is-open', open);
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            btn.querySelector('.t').textContent = open ? '收起详情图' : '展开全部详情图';
            btn.querySelector('.n').textContent = open ? '已展开 ' + total + ' 张' : '共 ' + total + ' 张';
            hint.textContent = open ? '已显示全部 ' + total + ' 张详情图' : '默认只显示开头一小部分，点击展开查看完整图文详情';
        }

        btn.addEventListener('click', function () {
            setOpen(!fold.classList.contains('is-open'));
        });
    }

    function init() {
        var containers = document.querySelectorAll('.as-single-desc, .as-post-content');
        Array.prototype.forEach.call(containers, function (c) {
            if (c.querySelector('img.as-jd-detail')) { build(c); }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
