<?php
/**
 * 首页 · 货架风格（默认）
 * --------------------------------------------------------------------------
 * v2.87.43 起首页拆成两套编排，这里是**货架**：各区块一样重、商品卡小而密，
 * 适合「已知要买什么」的找货 / 比价场景。另一套是 inc/home/showcase.php。
 *
 * ⚠️ 本文件由 front-page.php include，不单独访问。
 *
 * @package product-showcase-guide
 */

defined( 'ABSPATH' ) || exit;
?>

<?php
/*
 * 首页广告区（v2.75.0）—— 原来这里是「站名 + 描述 + 两个按钮」的 hero。
 *
 * 现在整块换成**横向自动滑动**的广告图（后台「主题设置 → 网站与小程序共用」配置，
 * 与小程序首页同一份数据）。
 *
 * ⚠️ 关于 h1：页面**必须有一个 h1**，否则 Lighthouse / 搜索引擎会判「缺少主标题」
 *（首页 SEO 原本是满分）。但视觉上已不需要显示站名 —— 所以用
 * `.as-sr-only`（仅屏幕阅读器可见）保留一个 h1，视觉完全不受影响。
 *
 * ⚠️ 没配广告时回退到原来的 hero —— 否则老站点升级后首页顶部会空一块。
 */
$as_ads = function_exists( 'as_home_ads' ) ? as_home_ads() : array();
?>

<?php if ( $as_ads ) : ?>
    <section class="as-hero as-hero-ads">
        <?php // 视觉隐藏的 h1：保住 SEO，又不占版面 ?>
        <h1 class="as-sr-only"><?php bloginfo( 'name' ); ?></h1>

        <div class="as-container">
            <div class="as-ads-viewport">
                <div class="as-ads-track">
                    <?php foreach ( $as_ads as $as_i => $as_ad ) : ?>
                        <?php
                        $as_url = function_exists( 'as_home_ad_url' ) ? as_home_ad_url( $as_ad ) : '';
                        if ( ! $as_url ) {
                            continue;
                        }
                        ?>
                        <a class="as-ad-slide" href="<?php echo esc_url( $as_url ); ?>">
                            <img src="<?php echo esc_url( $as_ad['img'] ); ?>"
                                alt="<?php echo esc_attr( $as_ad['title'] ); ?>"
                                width="1140" height="456"
                                loading="<?php echo ( 0 === $as_i ) ? 'eager' : 'lazy'; ?>"
                                decoding="async">
                        </a>
                    <?php endforeach; ?>
                </div>

                <?php if ( count( $as_ads ) > 1 ) : ?>
                    <?php // 指示点：一眼看出有几张、现在第几张（只有 1 张时不显示） ?>
                    <div class="as-ads-dots" aria-hidden="true">
                        <?php for ( $as_d = 0; $as_d < count( $as_ads ); $as_d++ ) : ?>
                            <span class="as-ads-dot<?php echo ( 0 === $as_d ) ? ' is-on' : ''; ?>"></span>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>
<?php else : ?>
    <section class="as-hero">
        <div class="as-container">
            <h1><?php bloginfo( 'name' ); ?></h1>
            <p><?php bloginfo( 'description' ); ?></p>
            <div class="as-hero-actions">
                <a class="as-btn-primary" href="<?php echo esc_url( get_post_type_archive_link( 'plm_product' ) ); ?>">浏览全部产品</a>
                <a class="as-btn-ghost" href="<?php echo esc_url( as_blog_url() ); ?>">阅读博客</a>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php
/*
 * 品类快捷入口（v2.84.2 改成图标卡片：图标在上、名称在下，三列一排）。
 * 图标按分类 slug 映射（as_cat_icon），没配到的品类用通用购物袋。
 */
$cats = get_terms( array( 'taxonomy' => 'plm_category', 'hide_empty' => true, 'number' => 8 ) );
if ( $cats && ! is_wp_error( $cats ) ) :
?>
<section class="as-section">
    <div class="as-container">
        <div class="as-section-head">
            <h2 class="as-section-title"><svg class="as-sec-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-6-5.3-6-10a6 6 0 1 1 12 0c0 4.7-6 10-6 10z"/><circle cx="12" cy="11" r="2"/></svg> 热门分类</h2>
            <a class="as-section-more" href="<?php echo esc_url( get_post_type_archive_link( 'plm_product' ) ); ?>">全部分类 →</a>
        </div>
        <div class="as-cat-quick">
            <?php foreach ( $cats as $cat ) : ?>
                <a class="as-cat-quick-item" href="<?php echo esc_url( get_term_link( $cat ) ); ?>">
                    <span class="as-cat-quick-icon" aria-hidden="true"><?php echo as_cat_icon( $cat ); ?></span>
                    <span class="as-cat-quick-name"><?php echo esc_html( $cat->name ); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php
/*
 * 限时特惠 —— 商品驱动：在「商品列表页」勾选「首页抢购」的商品即进本区块。
 *
 * 版式参考：顶部一行（标题 + 距结束倒计时），
 * 下面三列小网格（横版卡片）。卡片内不放券行（基础版不含优惠券），
 * 没有库存数据源，「仅剩 / 进度条」不造假，改显「已售 N 件」。
 * 显示数量在「主题设置 → 通用设置 → 限时特惠显示几个商品」配置。
 * 倒计时来自「主题设置 → 通用设置 → 限时活动结束时间」（留空则不显示）。
 */
$as_flash_limit = function_exists( 'as_mini_opt' ) ? (int) as_mini_opt( 'flash_count' ) : 4;
if ( $as_flash_limit < 1 ) {
    $as_flash_limit = 4;
}
/*
 * 商品池 = as_flash_product_ids()（后台勾了「首页抢购」的商品，限量取后台配置）。
 * 基础版不含优惠券，因此只有一个模块，不再按券切分。
 */
$as_flash_groups = function_exists( 'as_flash_coupon_groups' ) ? as_flash_coupon_groups( $as_flash_limit ) : array();
if ( $as_flash_groups ) :
?>
<section class="as-section as-section-alt">
    <div class="as-container">
        <div class="as-flash-head">
            <h2 class="as-flash-title-bar"><svg class="as-sec-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 2 4 14h6l-1 8 9-12h-6l1-8z"/></svg> 限时特惠</h2>
            <a class="as-flash-more" href="<?php echo esc_url( home_url( '/sale/' ) ); ?>">更多特惠 →</a>
        </div>
        <?php /* v2.86.58：一个券一个模块（模块头 = 券名 + 倒计时 + 商品数） */ ?>
        <?php foreach ( $as_flash_groups as $as_flash_g ) : ?>
            <div class="as-sale-group as-flash-group">
                <header class="as-sale-group-head<?php echo ! empty( $as_flash_g['name'] ) ? ' has-name' : ''; ?>">
                    <?php /* 名称 + 券信息包一层：没名称时 display:contents，版式与原来一致 */ ?>
                    <span class="as-sale-group-title">
                        <?php if ( ! empty( $as_flash_g['name'] ) ) : ?>
                            <span class="as-sale-group-name"><?php echo esc_html( $as_flash_g['name'] ); ?></span>
                        <?php endif; ?>
                        <?php if ( ! empty( $as_flash_g['label'] ) ) : ?>
                            <span class="as-sale-group-coupon"><?php echo esc_html( $as_flash_g['label'] ); ?></span>
                        <?php endif; ?>
                    </span>
                    <?php if ( $as_flash_g['end'] ) : ?>
                        <span class="as-sale-group-cd">距结束
                            <b data-countdown="<?php echo esc_attr( $as_flash_g['end'] ); ?>"><?php echo esc_html( $as_flash_g['text'] ); ?></b>
                        </span>
                    <?php endif; ?>
                    <span class="as-sale-group-num"><?php echo count( $as_flash_g['ids'] ); ?> 件商品</span>
                </header>
                <div class="as-flash-grid">
                    <?php foreach ( $as_flash_g['ids'] as $as_flash_id ) : ?>
                        <?php as_flash_card( $as_flash_id ); ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php
/*
 * 销量排行 —— 位置与小程序首页一致（品类 → 销量排行 → 热门推荐 → 最新上架）。
 * 数据来自 as_top_sales_ids()：前台销量 = 实际销量（已支付/已发货/已完成订单）+ 虚拟销量，
 * 与小程序的 top_sales 是同一份口径和同一个函数。
 */
$as_rank_ids = function_exists( 'as_top_sales_ids' ) ? as_top_sales_ids( 8 ) : array();
if ( $as_rank_ids ) :
?>
<section class="as-section">
    <div class="as-container">
        <div class="as-section-head">
            <h2 class="as-section-title"><svg class="as-sec-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3c1 3-2 4.5-2 7a2 2 0 0 0 4 0c0-1-.5-2-.5-2 2 1.5 3.5 3.5 3.5 6a5 5 0 0 1-10 0c0-4 5-6 5-11z"/></svg> 销量排行</h2>
        </div>

        <div class="as-rank-wrap">
        <div class="as-rank-scroll" data-rank-scroll>
            <div class="as-rank-row">
                <?php
                foreach ( $as_rank_ids as $as_rank_i => $as_rank_id ) :
                    $as_rank_title = get_the_title( $as_rank_id );
                    $as_rank_link  = get_permalink( $as_rank_id );
                    $as_rank_price = get_post_meta( $as_rank_id, 'dg_price', true );
                    $as_rank_sales = function_exists( 'as_product_sales' ) ? as_product_sales( $as_rank_id ) : 0;
                    // 封面口径与小程序一致：特色图像 → 图集第一张
                    $as_rank_cid = function_exists( 'as_product_cover_id' ) ? as_product_cover_id( $as_rank_id ) : 0;
                    ?>
                    <div class="as-rank-card">
                        <span class="as-rank-badge<?php echo $as_rank_i < 3 ? ' is-top' : ''; ?>"><?php echo (int) ( $as_rank_i + 1 ); ?></span>
                        <a class="as-rank-main" href="<?php echo esc_url( $as_rank_link ); ?>">
                            <?php
                            if ( $as_rank_cid ) {
                                echo wp_get_attachment_image( $as_rank_cid, 'as_product_card', false, array(
                                    'class' => 'as-rank-img',
                                    'alt'   => $as_rank_title,
                                ) );
                            } else {
                                echo as_placeholder_img( $as_rank_title, 400, 400, 'as-rank-img' );
                            }
                            ?>
                            <span class="as-rank-title"><?php echo esc_html( $as_rank_title ); ?></span>
                        </a>
                        <div class="as-rank-foot">
                            <span class="as-rank-price">¥<?php echo esc_html( $as_rank_price ? $as_rank_price : '—' ); ?></span>
                            <span class="as-rank-sales">已售 <?php echo (int) $as_rank_sales; ?> 件</span>
                        </div>
                        <?php
                        // 会员折扣提醒（控价 / 不参与 / 没得省 → 空串）
                        echo as_vip_notice_card( $as_rank_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 内部已转义
                        ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        /*
         * 滑动提示（v2.70.2，用户选定方案 B）
         *
         * 实测依据：手机端卡宽 132px、容器 358px → 一屏只放得下 2.7 张，
         * 第 3 张被切掉一半，而**用户不知道右边还能滑、还有 5 张没看到**。
         * 这里补一条进度条 + 「左右滑动」文案，把"可滑动"这件事说出来。
         *
         * 进度条由 assets/js/main.js 监听 scroll 事件更新宽度（.as-rank-bar 的
         * transform: scaleX），滑到最右时自动淡出 —— 没有 JS 时也能看到静态提示条。
         */
        ?>
        <div class="as-rank-hint" data-rank-hint>
            <span class="as-rank-bar"><i class="as-rank-bar-fill" data-rank-bar></i></span>
            <span class="as-rank-hint-txt">左右滑动查看更多</span>
        </div>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="as-section as-section-alt">
    <div class="as-container">
        <div class="as-section-head">
            <h2 class="as-section-title"><svg class="as-sec-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg> 热门推荐</h2>
        </div>
        <div class="as-grid">
            <?php
            // 手动勾选的热门产品优先；一个都没勾选时回落到按上架时间排序，
            // 避免编辑还没开始维护热门标记就出现空区块
            /*
             * 后台「首页热门推荐」勾选了几个就显示几个（上限 8）。
             * ⚠️ 原来 posts_per_page=8 但**没排除非热门商品** → 后台只勾了 4 个，
             *    前端却显示 6 个（多出来的 2 个是按时间抓的，不是热门），
             *    等于「勾了没用」。现在 meta_query 严格取 dg_hot=1。
             */
            $hot = new WP_Query( array(
                'post_type'      => 'plm_product',
                'posts_per_page' => 8,
                'meta_key'       => 'dg_hot',
                'meta_value'     => '1',
                'no_found_rows'  => true,
            ) );

            // 一个都没勾选时才回落到按上架时间排序，避免编辑还没维护就出现空区块
            if ( ! $hot->have_posts() ) {
                $hot = new WP_Query( array(
                    'post_type'      => 'plm_product',
                    'posts_per_page' => 8,
                    'orderby'        => 'date',
                    'order'          => 'DESC',
                    'no_found_rows'  => true,
                ) );
            }

            $hot_ids = array();

            if ( $hot->have_posts() ) :
                while ( $hot->have_posts() ) : $hot->the_post();
                    $hot_ids[] = get_the_ID();
                    as_product_card();
                endwhile;
                wp_reset_postdata();
            else :
                echo '<p class="as-empty">还没有产品，去后台「商城产品」添加吧。</p>';
            endif;
            ?>
        </div>
    </div>
</section>

<section class="as-section">
    <div class="as-container">
        <div class="as-section-head">
            <h2 class="as-section-title"><svg class="as-sec-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> 最新上架</h2>
            <a class="as-section-more" href="<?php echo esc_url( get_post_type_archive_link( 'plm_product' ) ); ?>">查看更多 →</a>
        </div>
        <div class="as-grid">
            <?php
            // 排除已经出现在「热门推荐」里的产品，两区不重复
            $new = new WP_Query( array(
                'post_type'      => 'plm_product',
                'posts_per_page' => 8,
                'post__not_in'   => $hot_ids,
            ) );
            if ( $new->have_posts() ) :
                while ( $new->have_posts() ) : $new->the_post();
                    as_product_card();
                endwhile;
                wp_reset_postdata();
            else :
                echo '<p class="as-empty">暂无更多产品。</p>';
            endif;
            ?>
        </div>
    </div>
</section>

<section class="as-section as-section-alt">
    <div class="as-container">
        <div class="as-section-head">
            <h2 class="as-section-title"><svg class="as-sec-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg> 精选阅读</h2>
            <a class="as-section-more" href="<?php echo esc_url( as_blog_url() ); ?>">更多文章 →</a>
        </div>
        <div class="as-post-grid">
            <?php
            $blog = function_exists( 'as_home_articles' ) ? as_home_articles() : array();
            if ( $blog ) :
                foreach ( $blog as $as_bp ) :
                    /*
                     * ⚠️ 必须同时给 $GLOBALS['post'] 赋值：setup_postdata() 只改
                     *    $wp_query->post，**不改全局 $post** → as_post_card() 里的
                     *    the_title() / the_permalink() / get_the_excerpt() 仍读旧的全局 post，
                     *    结果四张卡全渲染成同一篇（首页查询的最后一篇）。
                     */
                    $GLOBALS['post'] = $as_bp;
                    setup_postdata( $as_bp );
                    as_post_card();
                endforeach;
                wp_reset_postdata();
            else :
                echo '<p class="as-empty">还没有文章。</p>';
            endif;
            ?>
        </div>
    </div>
</section>
