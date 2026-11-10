/**
 * 商品详情页 · 电脑端「京东式两栏」（v2.87.118）
 * ----------------------------------------------------------------------------
 * 目标（用户 2026-11-09 定稿）：**上下都要吸** —— 跟京东商品详情一个道理。
 *
 * 京东桌面商品页右栏是什么样，这里就是什么样：
 *   · 右栏整体固定在视口内（高度 = 视口可用高），**顶部贴菜单下沿**；
 *   · 栏内顺序：标题/价格/券/会员价 → 商品参数（占满中部）→ 三个按钮
 *     （**底部贴浏览器下沿**）；
 *   · 参数多到装不下时，只在**栏内**滚动（细 6px 滚动条），页面本身不滚；
 *   · 滚到「相关推荐」时右栏随页面走（右栏让位），这是用户之前明确要的。
 *
 * 实现只有两件事：
 *   ① 锁定高度：JS 按右栏当前 top 写 height
 *        h = innerHeight − max(top, 页头高) − 8
 *      首屏 top≈136 → 756；吸顶后 top=65 → 827。底部恒定贴住视口下沿上方 8px。
 *   ② 落地线：Chrome 对 sticky 网格项的约束矩形是整个 grid 容器，实测滚到
 *      「相关推荐」时右栏仍钉在页头下沿 → 滚动时动态调 sticky 的 top：
 *        top = min( 页头高, 详情区底部 − h − 当前滚动位置 )
 *      滚到详情末尾时右栏底部贴住详情区底部，之后 top 变负、右栏随页面滚走。
 *
 * 🔴 只在 ≥992px 生效；手机端清空内联值，DOM / 样式 / 吸底购买栏零影响。
 *    禁用 JS 时退回 CSS 的 `top:65px` + `height:calc(100vh - 73px)`。
 */
( function () {
    'use strict';

    var OFFSET_FALLBACK = 65;  // 页头 .as-header（64px + 1px 边框）实测高度，兜底用
    var GAP    = 8;    // 卡片底部距视口下沿
    var MIN_H  = 420;  // 极矮视口的下限，避免卡片被压成一条

    function init() {
        var container = document.querySelector( '.as-single' );
        if ( ! container ) {
            return;
        }
        var info = container.querySelector( '.as-single-info' );
        var desc = container.querySelector( '.as-single-desc' );
        var head = document.querySelector( '.as-header' );
        /* 底部「上一个 / 下一个」导航（v2.87.121）：落地线以它为锚 ——
           导航完全进入视口后，右栏就放手随页面滚走，不再吸顶/吸底。 */
        var nav = container.querySelector( '.as-prevnext' );
        if ( ! info || ! desc ) {
            return;
        }

        /* 吸顶锚点 = 页头实际高度：右栏贴在**菜单下沿**，不留空隙。
           运行时实测，页头高度改了（比如加高导航）也自动跟随。 */
        function headOffset() {
            if ( ! head ) {
                return OFFSET_FALLBACK;
            }
            var h = head.getBoundingClientRect().height;
            return h > 0 ? Math.round( h ) : OFFSET_FALLBACK;
        }

        var mq  = window.matchMedia( '(min-width: 992px)' );
        var raf = 0;

        function update() {
            raf = 0;
            // 手机端：完全交回给原 CSS（内联值全清）
            if ( ! mq.matches ) {
                if ( info.style.top ) {
                    info.style.top = '';
                }
                if ( info.style.height ) {
                    info.style.height = '';
                }
                if ( info.style.minHeight ) {
                    info.style.minHeight = '';
                }
                return;
            }

            var vh = window.innerHeight;
            var y  = window.pageYOffset || document.documentElement.scrollTop || 0;
            var r  = info.getBoundingClientRect();
            var OFFSET = headOffset();

            // ① 锁定右栏高度 = 视口可用高 → 顶贴菜单下沿、底贴浏览器下沿
            var effTop = Math.max( r.top, OFFSET );
            var h      = Math.max( MIN_H, Math.round( vh - effTop - GAP ) );
            var cssH   = h + 'px';
            if ( info.style.height !== cssH ) {
                info.style.height = cssH;
            }

            // ② 落地线：锚点 = 底部「上一个/下一个」导航的**底边**（没有则用详情区底部）
            //    用底边而不是顶边：导航在左栏、右栏在右列，横向本来就不重叠；
            //    用底边 → 右栏底边与导航底边在同一水平线上（用户要的「底部对齐」）。
            var anchorEdge = nav
                ? ( nav.getBoundingClientRect().bottom + y )
                : ( desc.getBoundingClientRect().bottom + y );
            var top        = Math.min( OFFSET, Math.round( anchorEdge - h - y ) );
            var cssTop     = top + 'px';
            if ( info.style.top !== cssTop ) {
                info.style.top = cssTop;
            }
        }

        function schedule() {
            if ( ! raf ) {
                raf = window.requestAnimationFrame( update );
            }
        }

        window.addEventListener( 'scroll', schedule, { passive: true } );
        window.addEventListener( 'resize', schedule );
        window.addEventListener( 'load', schedule );

        // A 方案折叠展开、图片加载会改详情区高度 → 自动重算落地线
        if ( 'ResizeObserver' in window ) {
            var ro = new ResizeObserver( schedule );
            ro.observe( desc );
        }
        // 兼容不支持 matchMedia 事件的老浏览器
        if ( mq.addEventListener ) {
            mq.addEventListener( 'change', schedule );
        } else if ( mq.addListener ) {
            mq.addListener( schedule );
        }

        schedule();
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }
} )();
