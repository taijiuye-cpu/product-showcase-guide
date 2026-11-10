<?php get_header(); ?>

<?php
/*
 * 橱窗风格（v2.87.43）—— 列表页换成品牌大屏版式。
 * 只换排版：查询、筛选、分页都还是主查询，没加排序、没加筛选逻辑。
 */
if ( function_exists( 'as_home_style_is' ) && as_home_style_is( 'showcase' ) ) {
	require get_template_directory() . '/inc/home/list-showcase.php';
	get_footer();
	return;
}
?>

<div class="as-container as-archive">
    <?php as_breadcrumbs(); ?>

    <div class="as-search-head">
        <h1 class="as-archive-title">
            搜索：<?php echo esc_html( get_search_query() ); ?>
        </h1>
        <p class="as-search-count">
            <?php
            $found = (int) $GLOBALS['wp_query']->found_posts;
            echo $found ? sprintf( '共找到 %d 条结果', $found ) : '没有找到匹配的内容';
            ?>
        </p>
    </div>

    <?php if ( have_posts() ) : ?>

        <?php
        $products_html = '';
        $posts_html    = '';
        $product_count = 0;
        $post_count    = 0;

        while ( have_posts() ) : the_post();
            if ( 'plm_product' === get_post_type() ) {
                ob_start();
                as_product_card();
                $products_html .= ob_get_clean();
                $product_count++;
            } else {
                ob_start();
                as_post_card();
                $posts_html .= ob_get_clean();
                $post_count++;
            }
        endwhile;
        ?>

        <?php if ( $products_html ) : ?>
            <section class="as-section">
                <h2 class="as-section-title">产品（<?php echo intval( $product_count ); ?>）</h2>
                <div class="as-grid"><?php echo $products_html; ?></div>
            </section>
        <?php endif; ?>

        <?php if ( $posts_html ) : ?>
            <section class="as-section">
                <h2 class="as-section-title">文章（<?php echo intval( $post_count ); ?>）</h2>
                <div class="as-post-grid"><?php echo $posts_html; ?></div>
            </section>
        <?php endif; ?>

        <div class="as-pagination">
            <?php
            echo paginate_links( array(
                'prev_text' => '‹ 上一页',
                'next_text' => '下一页 ›',
                'mid_size'  => 2,
            ) );
            ?>
        </div>

    <?php else : ?>

        <div class="as-search-empty">
            <p class="as-empty">没有找到与「<?php echo esc_html( get_search_query() ); ?>」相关的内容，换个关键词试试。</p>
            <div class="as-search-again"><?php get_search_form(); ?></div>

            <?php
            $hot = new WP_Query( array(
                'post_type'      => 'plm_product',
                'posts_per_page' => 4,
                'orderby'        => 'date',
                'order'          => 'DESC',
            ) );
            if ( $hot->have_posts() ) :
            ?>
                <h2 class="as-section-title">热门推荐</h2>
                <div class="as-grid">
                    <?php
                    while ( $hot->have_posts() ) : $hot->the_post();
                        as_product_card();
                    endwhile;
                    wp_reset_postdata();
                    ?>
                </div>
            <?php endif; ?>
        </div>

    <?php endif; ?>
</div>

<?php get_footer(); ?>
