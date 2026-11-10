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

    <h1 class="as-archive-title">全部产品</h1>

    <?php
    $terms = get_terms( array(
        'taxonomy'   => 'plm_category',
        'hide_empty' => true,
    ) );
    ?>

    <?php if ( $terms && ! is_wp_error( $terms ) ) : ?>
        <div class="as-tabs" role="group" aria-label="按品类筛选">
            <button type="button" class="as-tab active" data-target="all" aria-pressed="true">全部</button>
            <?php foreach ( $terms as $term ) : ?>
                <button type="button" class="as-tab" data-target="<?php echo esc_attr( $term->slug ); ?>" aria-pressed="false">
                    <?php echo esc_html( $term->name ); ?>
                </button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="as-grid">
        <?php if ( have_posts() ) : while ( have_posts() ) : the_post();
            $pterms = get_the_terms( get_the_ID(), 'plm_category' );
            $slugs  = array();
            if ( $pterms && ! is_wp_error( $pterms ) ) {
                foreach ( $pterms as $t ) $slugs[] = $t->slug;
            }
        ?>
            <div class="as-grid-item" data-cats="<?php echo esc_attr( implode( ' ', $slugs ) ); ?>">
                <?php as_product_card(); ?>
            </div>
        <?php endwhile; else : ?>
            <p class="as-empty">暂无产品。</p>
        <?php endif; ?>
    </div>

    <div class="as-pagination">
        <?php
        echo paginate_links( array(
            'prev_text' => '‹ 上一页',
            'next_text' => '下一页 ›',
            'mid_size'  => 2,
        ) );
        ?>
    </div>
</div>

<?php get_footer(); ?>
