<?php
/**
 * Template Name: 限时特惠页
 *
 * 路由由 functions.php 的 rewrite 提供（/sale/），也可在后台建页选此模板。
 * 商品池 = as_sale_product_ids()：全部已发布商品（基础版不含优惠券，
 * 因此不再按券筛选），按发布日期倒序展示。
 *
 * @package product-showcase-guide
 */

get_header();

/*
 * v2.86.57：按优惠券分组 ——「一个券一个模块」，模块头给券名 + 倒计时 + 商品数，
 * 模块内是能用这张券的商品（主流电商大促页的通用结构）。
 * 页面头部不再重复展示全局券信息（每个模块头自带），只留标题与说明。
 */
$as_sale_groups = function_exists( 'as_sale_coupon_groups' ) ? as_sale_coupon_groups() : array();
$as_sale_count  = 0;
foreach ( $as_sale_groups as $as_sale_g ) {
	$as_sale_count += count( $as_sale_g['ids'] );
}
?>

<main class="as-section">
    <div class="as-container">
        <div class="as-sale-head">
            <div>
                <h1 class="as-sale-title">限时特惠</h1>
                <p class="as-sale-desc">全部在售商品，按上架时间排序；首页「限时特惠」区块里的商品会带上活动倒计时。</p>
            </div>
            <?php if ( $as_sale_count ) : ?>
                <span class="as-sale-total">共 <?php echo (int) $as_sale_count; ?> 件商品</span>
            <?php endif; ?>
        </div>

        <?php if ( $as_sale_groups ) : ?>
            <?php foreach ( $as_sale_groups as $as_sale_g ) : ?>
                <section class="as-sale-group">
                    <header class="as-sale-group-head<?php echo ! empty( $as_sale_g['name'] ) ? ' has-name' : ''; ?>">
                        <?php
                        /*
                         * 名称 + 券信息包一层 .as-sale-group-title（v2.87.42）：
                         *   · 没有名称时 .as-sale-group-title 是 display:contents → 子元素仍是
                         *     模块头的 flex 项，版式与改动前**逐像素一致**；
                         *   · 有名称时它是一个整体，手机端才好在 grid 里整块换行（见 main.css）。
                         */
                        ?>
                        <span class="as-sale-group-title">
                            <?php if ( ! empty( $as_sale_g['name'] ) ) : ?>
                                <span class="as-sale-group-name"><?php echo esc_html( $as_sale_g['name'] ); ?></span>
                            <?php endif; ?>
                            <?php if ( ! empty( $as_sale_g['label'] ) ) : ?>
                                <span class="as-sale-group-coupon"><?php echo esc_html( $as_sale_g['label'] ); ?></span>
                            <?php endif; ?>
                        </span>
                        <?php if ( $as_sale_g['end'] ) : ?>
                            <?php
                            /*
                             * 高端倒计时（v2.87.45）：原来是「距结束 <b>剩25天11小时</b>」一行小字，
                             * 现在换成天/时/分/秒四段数字块，每秒跳动、精确到秒。
                             */
                            ?>
                            <span class="as-sale-group-cd">
                                <?php as_big_countdown( $as_sale_g['end'], 'light', '距结束' ); ?>
                            </span>
                        <?php else : ?>
                            <?php
                            /*
                             * v2.87.100（用户 2026-11-09）：券没设结束时间（长期券）时
                             * 原来整块留空 —— 两个券模块并排看，一个有时间一个没有，像是漏了。
                             * 这里补一个同尺寸的静态块「长期有效」，两个模块头视觉齐平。
                             * 想让它真的倒数：去后台给这张券填「结束时间」即可，这里会自动切成倒计时。
                             */
                            ?>
                            <span class="as-sale-group-cd is-endless">
                                <span class="as-sale-endless">长期有效</span>
                            </span>
                        <?php endif; ?>
                        <span class="as-sale-group-num"><?php echo count( $as_sale_g['ids'] ); ?> 件商品</span>
                    </header>
                    <?php
                    /*
                     * v2.87.90：卡片改用**商品归档页同款**橱窗卡（as_sc_product_card +
                     * .as-sc-list-items）。原来用的是 as_flash_card()（横版抢购卡），
                     * 用户要求「跟所有产品归档页一样」，卡片结构/信息层级全部对齐
                     * /product、/brand、分类页、搜索结果页。
                     * 优惠信息（券 / 倒计时 / 件数）留在上面的活动带里，不再进卡片。
                     */
                    ?>
                    <div class="as-sc-list-items">
                        <?php foreach ( $as_sale_g['ids'] as $as_sale_id ) : ?>
                            <?php as_sc_product_card( $as_sale_id, 'is-list' ); ?>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        <?php else : ?>
            <div class="as-sale-empty">
                <p class="as-sale-empty-lead">当前还没有已发布的商品。</p>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php
get_footer();
