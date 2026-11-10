/**
 * 私享商城 - 前台交互
 * 1. 移动端抽屉导航
 * 2. 归档页 Tab 前端筛选
 * 3. 返回顶部
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    var MOBILE_QUERY = '(max-width: 900px)';

    function isMobile() {
        return window.matchMedia && window.matchMedia(MOBILE_QUERY).matches;
    }

    ready(function () {
        initNav();
        initTabs();
        initBackTop();
    });

    /* -------------------------------------------
     * 1. 移动端抽屉导航
     * ----------------------------------------- */
    function initNav() {
        var toggle = document.querySelector('.as-nav-toggle');
        var nav = document.querySelector('.as-nav');
        if (!toggle || !nav) return;

        var subToggles = buildSubToggles(nav);
        // 只有成功注入了开关按钮，才隐藏链接自带的下拉三角（无 JS 时保持原样）
        if (subToggles.length) {
            nav.classList.add('has-sub-toggles');
        }

        function setOpen(open) {
            nav.classList.toggle('open', open);
            toggle.classList.toggle('is-active', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggle.setAttribute('aria-label', open ? '关闭导航菜单' : '打开导航菜单');
            document.body.classList.toggle('as-nav-open', open);
            if (!open) closeAllSubs();
        }

        function closeAllSubs() {
            var opened = nav.querySelectorAll('li.sub-open');
            for (var i = 0; i < opened.length; i++) {
                opened[i].classList.remove('sub-open');
            }
            for (var j = 0; j < subToggles.length; j++) {
                syncSubToggle(subToggles[j], false);
            }
        }

        /**
         * 为每个含子菜单的 li 注入一个独立的箭头按钮。
         * 这样父级菜单行就有了两个点击区：点正文跳转到父页面，点箭头展开/折叠。
         */
        function buildSubToggles(scope) {
            var items = scope.querySelectorAll('li.menu-item-has-children');
            var buttons = [];

            for (var i = 0; i < items.length; i++) {
                var li = items[i];
                var link = directLink(li);
                if (!link) continue;

                var name = (link.textContent || '').replace(/\s+/g, ' ').trim();

                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'as-submenu-toggle';
                btn.setAttribute('data-name', name);
                btn.innerHTML = '<span class="as-submenu-toggle-icon" aria-hidden="true"></span>';

                var submenu = li.querySelector('ul.sub-menu');
                if (submenu) {
                    if (!submenu.id) {
                        submenu.id = 'as-submenu-' + i;
                    }
                    btn.setAttribute('aria-controls', submenu.id);
                }

                syncSubToggle(btn, false);

                li.insertBefore(btn, link.nextSibling);

                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();

                    var li = this.parentNode;
                    var open = !li.classList.contains('sub-open');

                    li.classList.toggle('sub-open', open);
                    syncSubToggle(this, open);
                });

                buttons.push(btn);
            }

            return buttons;
        }

        function directLink(li) {
            for (var i = 0; i < li.children.length; i++) {
                if ('A' === li.children[i].tagName) {
                    return li.children[i];
                }
            }
            return null;
        }

        function syncSubToggle(btn, open) {
            var name = btn.getAttribute('data-name') || '';
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            btn.setAttribute('aria-label', (open ? '折叠' : '展开') + (name ? '「' + name + '」' : '') + '子菜单');
        }

        toggle.addEventListener('click', function () {
            setOpen(!nav.classList.contains('open'));
        });

        // 点击遮罩 / 空白处关闭
        document.addEventListener('click', function (e) {
            if (!nav.classList.contains('open')) return;
            if (nav.contains(e.target) || toggle.contains(e.target)) return;
            setOpen(false);
        });

        // Esc 关闭
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && nav.classList.contains('open')) {
                setOpen(false);
                toggle.focus();
            }
        });

        // 回到桌面尺寸时复位
        window.addEventListener('resize', function () {
            if (isMobile()) return;
            if (nav.classList.contains('open')) setOpen(false);
            closeAllSubs();
        });
    }

    /* -------------------------------------------
     * 2. Tab 前端筛选（按钮型 .as-tab[data-target]）
     * ----------------------------------------- */
    function initTabs() {
        var bars = document.querySelectorAll('.as-tabs');

        for (var i = 0; i < bars.length; i++) {
            (function (bar) {
                var tabs = bar.querySelectorAll('.as-tab[data-target]');
                if (!tabs.length) return;

                // Tab 所在容器内的所有筛选项
                var scope = bar.parentNode;
                var items = scope.querySelectorAll('.as-grid-item');
                if (!items.length) return;

                var empty = document.createElement('p');
                empty.className = 'as-empty as-filter-empty';
                empty.textContent = '该分类下暂无内容。';
                empty.style.display = 'none';
                var firstItem = items[0];
                firstItem.parentNode.appendChild(empty);

                function apply(target) {
                    var visible = 0;
                    for (var n = 0; n < items.length; n++) {
                        var cats = ' ' + (items[n].getAttribute('data-cats') || '') + ' ';
                        var match = target === 'all' || cats.indexOf(' ' + target + ' ') !== -1;
                        items[n].style.display = match ? '' : 'none';
                        if (match) visible++;
                    }
                    empty.style.display = visible ? 'none' : 'block';
                }

                function activate(tab) {
                    for (var t = 0; t < tabs.length; t++) {
                        tabs[t].classList.remove('active');
                        tabs[t].setAttribute('aria-pressed', 'false');
                    }
                    tab.classList.add('active');
                    tab.setAttribute('aria-pressed', 'true');
                    apply(tab.getAttribute('data-target'));
                }

                for (var t = 0; t < tabs.length; t++) {
                    tabs[t].setAttribute('aria-pressed', tabs[t].classList.contains('active') ? 'true' : 'false');
                    tabs[t].addEventListener('click', function () {
                        activate(this);
                    });
                }
            })(bars[i]);
        }
    }

    /* -------------------------------------------
     * 3. 返回顶部
     * ----------------------------------------- */
    function initBackTop() {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'as-back-top';
        btn.setAttribute('aria-label', '返回顶部');
        btn.innerHTML = '↑';
        document.body.appendChild(btn);

        btn.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        function onScroll() {
            btn.classList.toggle('is-visible', window.pageYOffset > 600);
        }
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }
})();

/* ==========================================================================
 * 优惠券倒计时
 * --------------------------------------------------------------------------
 * PHP 输出 <span class="as-promo-cd" data-countdown="<unix 秒>"></span>，
 * 这里每秒刷新剩余时间。用 data 属性而不是内联 <script>，是为了以后用 AJAX
 * 追加内容时重新调用一次 init() 就能生效。
 * ======================================================================== */
(function () {
    function pad(n) {
        return n < 10 ? '0' + n : String(n);
    }

    // ⚠️ 格式必须与 PHP 的 as_coupon_countdown() **完全一致**（都不带空格），
    //    否则首次 tick 后文案会"跳"一下（服务端渲染是宽的、JS 刷新是紧的）。
    function fmt(left) {
        if (left <= 0) return '优惠已结束';
        var d = Math.floor(left / 86400);
        var h = Math.floor((left % 86400) / 3600);
        var m = Math.floor((left % 3600) / 60);
        var s = left % 60;
        if (d > 0) return '剩' + d + '天' + h + '小时';
        if (h > 0) return '剩' + h + '小时' + m + '分';
        return '剩' + m + '分' + pad(s) + '秒';
    }

    function initCountdown() {
        /*
         * ⚠️🔴 v2.87.45：这里必须**排除高端倒计时组件**（带 data-cd-blocks 的）。
         *   原来的选择器是 [data-countdown]（无差别匹配所有），
         *   而它更新方式是 `node.textContent = ...` → **会把子元素整个清空**，
         *   于是高端倒计时的数字块 HTML 被抹成一行「剩25天11小时」（v2.87.45 实测踩到）。
         *   两套格式不同（券行是紧凑文案 / 数字块是天时分秒四段），必须分开处理。
         */
        var nodes = document.querySelectorAll('[data-countdown]:not([data-cd-blocks])');
        if (!nodes.length) return;

        function tick() {
            var now = Math.floor(Date.now() / 1000);
            for (var i = 0; i < nodes.length; i++) {
                var end = parseInt(nodes[i].getAttribute('data-countdown'), 10) || 0;
                if (!end) continue;
                nodes[i].textContent = fmt(end - now);
            }
        }

        tick();
        setInterval(tick, 1000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCountdown);
    } else {
        initCountdown();
    }
})();

/* ==========================================================================
 * 限时抢购模块倒计时（首页「⚡ 限时抢购」顶部那一行）
 * --------------------------------------------------------------------------
 * PHP 输出 <b data-flash-cd="<unix 秒>">距结束文案</b>，这里每秒刷新。
 * 与上面的 [data-countdown]（券行、「剩X天X小时」格式）是**两套**：
 *   · data-countdown → 剩X天X小时 / 剩X小时X分 / 剩X分X秒（服务端 as_coupon_countdown）
 *   · data-flash-cd  → >24h「X天 HH:MM」、≤24h「HH:MM:SS」补零（服务端 as_flash_countdown）
 * 格式必须与 PHP 侧一致，否则首次 tick 后文案会"跳"一下。
 * ======================================================================== */
(function () {
    function pad(n) {
        return n < 10 ? '0' + n : String(n);
    }

    function fmt(left) {
        if (left <= 0) return '已结束';
        var d = Math.floor(left / 86400);
        var h = Math.floor((left % 86400) / 3600);
        var m = Math.floor((left % 3600) / 60);
        var s = left % 60;
        if (d > 0) return d + '天 ' + pad(h) + ':' + pad(m);
        return pad(h) + ':' + pad(m) + ':' + pad(s);
    }

    function initFlashCountdown() {
        var nodes = document.querySelectorAll('[data-flash-cd]');
        if (!nodes.length) return;

        function tick() {
            var now = Math.floor(Date.now() / 1000);
            for (var i = 0; i < nodes.length; i++) {
                var end = parseInt(nodes[i].getAttribute('data-flash-cd'), 10) || 0;
                if (!end) continue;
                nodes[i].textContent = fmt(end - now);
            }
        }

        tick();
        setInterval(tick, 1000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFlashCountdown);
    } else {
        initFlashCountdown();
    }
})();

/* ==========================================================================
   商品图集轮播（前台产品详情页）
   ---------------------------------------------------------------------------
   与后台 / 小程序共用同一份数据（附件 post_parent + as_gallery_order），
   所以后台加完图，三端同时生效。
   单图商品不会输出这套结构（PHP 侧返回空串），所以这里的节点只出现在多图商品页。

   交互：点缩略图 / 点左右箭头 / 键盘方向键 / 移动端左右滑动。
   ========================================================================== */
(function () {
    'use strict';

    function initGallery(root) {
        if (root.__asGalleryInited) return;
        root.__asGalleryInited = true;

        var slides = Array.prototype.slice.call(root.querySelectorAll('.as-gallery-slide'));
        var thumbs = Array.prototype.slice.call(root.querySelectorAll('.as-gallery-thumb'));
        var prevBtn = root.querySelector('.as-gallery-prev');
        var nextBtn = root.querySelector('.as-gallery-next');
        var counter = root.querySelector('.as-gallery-counter b');
        var total = slides.length;
        var cur = 0;

        if (!total) return;

        function show(i) {
            // 取模，箭头可以一直点
            cur = ((i % total) + total) % total;
            for (var s = 0; s < total; s++) {
                slides[s].classList.toggle('is-active', s === cur);
            }
            for (var t = 0; t < thumbs.length; t++) {
                var on = t === cur;
                thumbs[t].classList.toggle('is-active', on);
                // 键盘可达性：只有当前图能被 Tab 聚焦
                thumbs[t].tabIndex = on ? 0 : -1;
            }
            if (counter) counter.textContent = String(cur + 1);
        }

        thumbs.forEach(function (btn, i) {
            btn.addEventListener('click', function () { show(i); });
        });
        if (prevBtn) prevBtn.addEventListener('click', function () { show(cur - 1); });
        if (nextBtn) nextBtn.addEventListener('click', function () { show(cur + 1); });

        // 键盘：左右方向键切换（缩略图或大图区域聚焦时）
        root.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowLeft') { show(cur - 1); e.preventDefault(); }
            else if (e.key === 'ArrowRight') { show(cur + 1); e.preventDefault(); }
        });

        // 移动端左右滑动
        var x0 = null, y0 = null;
        var stage = root.querySelector('.as-gallery-stage');
        if (stage) {
            stage.addEventListener('touchstart', function (e) {
                var t = e.changedTouches[0];
                x0 = t.clientX; y0 = t.clientY;
            }, { passive: true });
            stage.addEventListener('touchend', function (e) {
                if (x0 === null) return;
                var t = e.changedTouches[0];
                var dx = t.clientX - x0;
                var dy = t.clientY - y0;
                // 横向位移明显大于纵向才切换，避免误触
                if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) {
                    show(dx < 0 ? cur + 1 : cur - 1);
                }
                x0 = y0 = null;
            }, { passive: true });
            stage.addEventListener('touchcancel', function () { x0 = y0 = null; });
        }

        show(0);
    }

    function initAll() {
        var roots = document.querySelectorAll('[data-as-gallery]');
        for (var i = 0; i < roots.length; i++) {
            initGallery(roots[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();

/* ==========================================================================
 * 排行榜滑动提示（v2.70.2）
 * --------------------------------------------------------------------------
 * 实测问题：手机端卡宽 132px、容器 358px → 一屏只放得下 2.7 张，第 3 张被切掉一半，
 * 用户不知道右边还能滑、还有 5 张没看到。
 *
 * 这里做两件事：
 *   1. 首次渲染时按「可视比例」给进度条一个初始宽度（--rank-ratio）
 *   2. 滚动时实时更新；滑到最右给提示条加 .is-done 让它淡出（别一直占位置）
 *
 * ⚠️ 只有**真的溢出**时才显示提示（scrollWidth > clientWidth）。
 *    桌面端一屏放得完 8 张 → 不显示，避免多一行没用的东西。
 * ========================================================================== */
(function () {
    function initRankHint() {
        var scrolls = document.querySelectorAll('[data-rank-scroll]');
        for (var i = 0; i < scrolls.length; i++) {
            (function (el) {
                var hint = el.parentNode.querySelector('[data-rank-hint]');
                var fill = el.parentNode.querySelector('[data-rank-bar]');
                if (!hint || !fill) return;

                function ratio() {
                    var max = el.scrollWidth - el.clientWidth;
                    return max > 0 ? el.clientWidth / el.scrollWidth : 1;
                }

                function sync() {
                    var max = el.scrollWidth - el.clientWidth;
                    // 不溢出（桌面端）→ 隐藏提示
                    if (max <= 4) {
                        hint.style.display = 'none';
                        return;
                    }
                    hint.style.display = '';
                    var r = ratio();
                    fill.style.transform = 'scaleX(' + r + ')';
                    // 滑到最右（或差 4px 内）→ 淡出
                    if (el.scrollLeft >= max - 4) {
                        hint.classList.add('is-done');
                    } else {
                        hint.classList.remove('is-done');
                    }
                }

                var ticking = false;
                el.addEventListener('scroll', function () {
                    if (ticking) return;
                    ticking = true;
                    window.requestAnimationFrame(function () {
                        sync();
                        ticking = false;
                    });
                }, { passive: true });

                // 图片加载完宽度会变（scrollWidth 变化）→ 补一次
                window.addEventListener('load', sync);
                window.addEventListener('resize', sync);
                sync();
                // 字体/图片是异步的，延迟再校正一次
                setTimeout(sync, 600);
            })(scrolls[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initRankHint);
    } else {
        initRankHint();
    }
})();

/**
 * 首页广告区轮播（v2.75.2）
 *
 * 一次显示一张，每隔几秒**整张**滑走、下一张滑进来。
 * （最初做的是"一组图连续滚动"，用户要求改成一张一张切换 —— 更像轮播图。）
 *
 * 实现：轨道 flex 横排、每张占 100% 宽，改 track 的 translateX(-index*100%)，
 * 位移配合 CSS 的 transition 就是滑动效果。不用任何库。
 *
 * 只有 1 张时什么都不做（静态图即可，指示点也不渲染）。
 */
(function () {
    'use strict';

    function initHomeAds() {
        var viewport = document.querySelector('.as-ads-viewport');
        if (!viewport) return;

        var track = viewport.querySelector('.as-ads-track');
        var slides = viewport.querySelectorAll('.as-ad-slide');
        var dots = viewport.querySelectorAll('.as-ads-dot');
        if (!track || slides.length < 2) return;   // 1 张 = 静态，不轮播

        var index = 0;
        var INTERVAL = 4000;   // 每张停留 4 秒（与小程序 swiper 的 interval 一致）
        var timer = null;

        // 系统开了"减少动态效果"就完全不自动播
        var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduce) return;

        function show(i) {
            index = (i + slides.length) % slides.length;
            track.style.transform = 'translateX(' + (-index * 100) + '%)';
            for (var d = 0; d < dots.length; d++) {
                dots[d].className = 'as-ads-dot' + (d === index ? ' is-on' : '');
            }
        }

        function start() {
            stop();
            timer = setInterval(function () { show(index + 1); }, INTERVAL);
        }

        function stop() {
            if (timer) { clearInterval(timer); timer = null; }
        }

        start();

        // 鼠标悬停暂停 —— 用户要看清或点图时不希望它跑走
        viewport.addEventListener('mouseenter', stop);
        viewport.addEventListener('mouseleave', start);

        // 切到别的标签页时停掉，回来再继续（省资源，也避免回来后连着跳好几张）
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) { stop(); } else { start(); }
        });

        // 页面被 bfcache 恢复时（浏览器后退），重置到第一张
        window.addEventListener('pageshow', function (e) {
            if (e.persisted) { show(0); start(); }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initHomeAds);
    } else {
        initHomeAds();
    }
})();

/* ==========================================================================
   高端倒计时组件（v2.87.45）
   --------------------------------------------------------------------------
   首页优惠模块（深色带）与优惠汇总页 /sale/ 用同一个组件：
   PHP 输出 <div class="as-cd" data-countdown="<unix 秒>" data-cd-blocks="1">，
   里面是 天/时/分/秒 四个数字块，这里每秒重算数字。

   ⚠️ 时间戳单位铁律：data-countdown 是 **Unix 秒**，Date.now() 是毫秒，
      相减必须先 /1000。全站统一，别在别处再写成 Date.now() 直接减。
   ⚠️ 与上面那个 [data-countdown] 券行倒计时**格式不同**：
      券行是「剩X天X小时」紧凑文案（卡片里空间小），
      这里是大号数字块（天/时/分/秒各一段）→ 所以用 data-cd-blocks 区分，
      不共用同一个格式化函数。
   ========================================================================== */
(function () {
    function pad(n) {
        return n < 10 ? '0' + n : String(n);
    }

    function initBigCountdown() {
        var nodes = document.querySelectorAll('[data-cd-blocks]');
        if (!nodes.length) return;

        function tick() {
            var now = Math.floor(Date.now() / 1000);
            for (var i = 0; i < nodes.length; i++) {
                var node = nodes[i];
                var end = parseInt(node.getAttribute('data-countdown'), 10) || 0;
                if (!end) continue;

                var left = end - now;
                if (left <= 0) {
                    /* 结束了：把数字全归零，并加一个 is-over 类让样式变灰 */
                    node.classList.add('is-over');
                    var zeros = node.querySelectorAll('.as-cd-unit b');
                    for (var z = 0; z < zeros.length; z++) { zeros[z].textContent = '00'; }
                    continue;
                }
                node.classList.remove('is-over');

                var d = Math.floor(left / 86400);
                var h = Math.floor((left % 86400) / 3600);
                var m = Math.floor((left % 3600) / 60);
                var s = left % 60;
                var vals = [d, h, m, s];

                var nums = node.querySelectorAll('.as-cd-unit b');
                for (var k = 0; k < nums.length; k++) {
                    var v = vals[k];
                    var txt = v < 10 ? '0' + v : String(v);
                    if (nums[k].textContent !== txt) { nums[k].textContent = txt; }
                }
            }
        }

        tick();
        setInterval(tick, 1000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initBigCountdown);
    } else {
        initBigCountdown();
    }
})();

/* ==========================================================================
   首页 Hero 拼贴 · 动态随机换图（v2.87.46 重做过渡）
   --------------------------------------------------------------------------
   PHP 在 .as-sc-visual 上挂了 data-hero-pool（JSON 数组：{u:图片URL, l:链接, a:标题}），
   这里每 3 秒随机给三个图框换一张图。

   规则：
   · **首屏不变** —— 服务端输出的仍是随机三张，第一次打开看到的就是随机结果；
     轮播从 3 秒后才开始（不打断首屏观感）。
   · 换图时 **链接 + alt 一起换** → 点图仍然跳到对应商品，屏幕阅读器也念对商品名。
   · 预加载：先把池子里的图 load 完再开始轮播，避免切换时白闪。
   · 每张图不重复当前这张（连着两次不显示同一张）。
   · 尊重 prefers-reduced-motion：用户要求减少动画时**不轮播**（保持静态）。
   · 无 JS / 池子为空 → 什么都不做，页面照常显示。
   · 鼠标悬停 / 切到别的标签页 → **暂停轮播**（用户想点图时别把它换走）。

   🔴 v2.87.46 过渡方式重做（用户：「闪一闪的，有点晃眼」）：
     旧做法 = 改同一个 <img> 的 src + CSS animation 从 opacity:0 入场
             → 旧图被硬切掉、新图又从全透明开始 → 整块区域"闪"一下；
     新做法 = **交叉溶解**：新图作为第二层叠在旧图上面淡入，旧图同时淡出
             → 任何一帧屏幕上都有图在，不会出现"消失再出现"；
             另外三张**错峰**入场（0 / 160 / 320ms），有先后节奏不齐刷刷抖。
   ========================================================================== */
(function () {
    'use strict';

    function initHeroRotator() {
        var root = document.querySelector('[data-hero-pool]');
        if (!root) return;

        var pool;
        try {
            pool = JSON.parse(root.getAttribute('data-hero-pool') || '[]');
        } catch (e) {
            return;
        }
        if (!pool.length || pool.length < 2) return;

        /* 用户要求减少动画 → 保持静态 */
        try {
            if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                return;
            }
        } catch (e) { /* 老浏览器忽略 */ }

        var frames = root.querySelectorAll('.as-sc-photo');
        if (!frames.length) return;

        /* 每个框记住「当前显示的是池子里第几张」（用 URL 匹配） */
        var current = [];
        var initialSrc = [];
        frames.forEach(function (f) {
            var img = f.querySelector('img');
            var src = img ? (img.getAttribute('src') || '') : '';
            current.push(src);
            initialSrc.push(src);
        });

        /* 预加载池子里的图，load 完再开始轮播 */
        var loaded = 0;
        pool.forEach(function (item) {
            var pre = new Image();
            pre.onload = pre.onerror = function () { loaded++; };
            pre.src = item.u;
        });

        function pick(curUrl) {
            /* 排除当前这张，避免连着两次一样 */
            var choices = [];
            for (var i = 0; i < pool.length; i++) {
                if (pool[i].u !== curUrl) choices.push(pool[i]);
            }
            if (!choices.length) choices = pool;
            return choices[Math.floor(Math.random() * choices.length)];
        }

        /**
         * 交叉溶解换图（v2.87.47）。
         *
         * 流程（**顺序是关键**）：
         *   1. 新建一个 <img>（只给 src，不给 srcset —— 池子里的 URL 已是定尺图，
         *      而 WordPress 输出的 srcset 优先级高于 src，会导致"改了 src 却没换图"）；
         *   2. 先把新图插进 DOM（初始 opacity:0，盖在旧图上面）→ **此时旧图还完整可见**；
         *   3. 🔴 **等新图真正 load 完**，才让旧图淡出、新图淡入；
         *      （v2.87.46 的 bug：新图还没 decode 就开始淡出旧图 →
         *        画面上出现一个**空框**，这正是用户说的"闪"！）
         *   4. 过渡结束后把旧图从 DOM 里摘掉。
         *
         * ⚠️ 关键：屏幕上**任何一帧都有完整图片**，不存在"图片没了"的空档。
         */
        function swap(frame, index, given) {
            /* 取「当前正在显示的那张」（排除正在退场的旧图） */
            var oldImg = frame.querySelector('img:not(.as-sc-out)');
            if (!oldImg) return;
            var item = given || pick(current[index]);
            current[index] = item.u;
            frame.setAttribute('href', item.l);

            /* 清掉可能残留的"待入场但没成功"的透明图（加载失败/被中断） */
            var stale = frame.querySelectorAll('img.as-sc-next');
            for (var s = 0; s < stale.length; s++) {
                if (stale[s].parentNode) { stale[s].parentNode.removeChild(stale[s]); }
            }

            var newImg = document.createElement('img');
            /* 继承旧图的 class（对齐 WP 输出的尺寸 class）+ 换图用的状态 class */
            newImg.className = (oldImg.className || '') + ' as-sc-next';
            newImg.setAttribute('alt', item.a || '');
            newImg.setAttribute('decoding', 'async');
            /* 🔴 只给 src，不给 srcset/sizes（见函数头注释） */
            newImg.setAttribute('src', item.u);

            /* 新图先挂上去，但完全透明 → 旧图依旧完整显示，不会露空 */
            frame.appendChild(newImg);

            /* 溶解开始：新图淡入 + 旧图淡出 */
            function beginFade() {
                oldImg.classList.remove('as-sc-next', 'as-sc-on');
                oldImg.classList.add('as-sc-out');
                /* 强制重排后再让新图入场 → 过渡才会真的播（而不是直接跳到终态） */
                void newImg.offsetWidth;
                newImg.classList.remove('as-sc-next');
                newImg.classList.add('as-sc-on');
                /* 过渡结束（约 .62s）后摘掉旧图，别让 DOM 里堆一堆 */
                window.setTimeout(function () {
                    if (oldImg.parentNode) { oldImg.parentNode.removeChild(oldImg); }
                }, 800);
                /* 外框只留一圈很淡很慢的高光（不再闪玫红描边） */
                frame.classList.remove('is-swap');
                void frame.offsetWidth;
                frame.classList.add('is-swap');
            }

            /* 🔴 必须等新图**加载完成**再开始淡出旧图 */
            if (newImg.complete && newImg.naturalWidth > 0) {
                /* 命中缓存 → 已就绪（池子图早就预加载过，走这条） */
                beginFade();
            } else {
                newImg.addEventListener('load', function once() {
                    newImg.removeEventListener('load', once);
                    beginFade();
                });
                /* 新图加载失败 → 直接丢弃，旧图继续留着（绝不留空白框） */
                newImg.addEventListener('error', function once() {
                    newImg.removeEventListener('error', once);
                    if (newImg.parentNode) { newImg.parentNode.removeChild(newImg); }
                });
            }
        }

        /**
         * **三张作为一个整体一起换**（用户 2026-11-06 明确要求）。
         *
         * 做法：先从池子里挑出 3 个**互不相同**的商品（顺序随机），
         *      然后一次性写进三个框 —— 整组同时刷新，不会出现「一张换了、
         *      另两张还是旧的」那种半吊子状态。
         *
         * v2.87.46：三张**错峰**入场（0 / 160 / 320ms）→ 有先后节奏，
         *      不会三张齐刷刷一起变，看着没那么晃。
         */
        function swapAll() {
            var picked = [];
            var used = {};
            var guard = 0;
            /* 三个框各挑一个，且互不重复 */
            while (picked.length < frames.length && guard < 60) {
                guard++;
                var item = pool[Math.floor(Math.random() * pool.length)];
                if (!item || used[item.u]) continue;
                used[item.u] = 1;
                picked.push(item);
            }
            /* 池子不够 3 张时兜底：允许重复 */
            while (picked.length < frames.length) {
                picked.push(pool[Math.floor(Math.random() * pool.length)]);
            }
            /* 整组一起写进去（带错峰） */
            for (var i = 0; i < frames.length; i++) {
                (function (fr, idx, it) {
                    if (idx === 0) {
                        swap(fr, idx, it);
                    } else {
                        window.setTimeout(function () { swap(fr, idx, it); }, idx * 160);
                    }
                })(frames[i], i, picked[i]);
            }
        }

        /* 池子里的图都预加载完（约几百毫秒）后再开始 */
        var started = false;
        var timer = null;
        function start() {
            if (started) return;
            started = true;
            timer = window.setInterval(swapAll, 3000);
            /*
             * 兜底：5 秒后再确认一次「图确实换过」。
             * 万一浏览器缓存了旧版 main.js（不会走到这里）或者预加载一直失败，
             * 这一步会强制换一次，保证用户一定能看到动态效果。
             */
            window.setTimeout(function () {
                var changed = false;
                for (var c = 0; c < current.length; c++) {
                    if (current[c] !== initialSrc[c]) { changed = true; break; }
                }
                if (!changed) { swapAll(); }
            }, 5000);
        }
        function stop() {
            if (timer) { window.clearInterval(timer); timer = null; }
        }
        function resume() {
            if (started && !timer) { timer = window.setInterval(swapAll, 3000); }
        }

        /*
         * 鼠标悬停暂停 —— 用户正想点某个商品时，别把图换走了。
         * 🔴 **只在真能 hover 的设备上启用**：手机上「点一下」浏览器也会补发
         *    mouseenter（但不会再发 mouseleave）→ 轮播会永久停住，用户以为坏了。
         *    用 (hover: hover) 判定，触屏设备直接跳过这套暂停逻辑。
         */
        var canHover = true;
        try {
            canHover = !window.matchMedia || window.matchMedia('(hover: hover)').matches;
        } catch (e) { /* 老浏览器按可 hover 处理 */ }
        if (canHover) {
            root.addEventListener('mouseenter', stop);
            root.addEventListener('mouseleave', resume);
        }
        /* 切到别的标签页也停，回来再继续 */
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) { stop(); } else { resume(); }
        });

        if (loaded >= pool.length) {
            start();
        } else {
            var wait = window.setInterval(function () {
                if (loaded >= pool.length) {
                    window.clearInterval(wait);
                    start();
                }
            }, 120);
            /* 兜底：3 秒后无论如何都开起来（免得某张图一直加载失败卡住） */
            window.setTimeout(function () { window.clearInterval(wait); start(); }, 3000);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initHeroRotator);
    } else {
        initHeroRotator();
    }
})();
