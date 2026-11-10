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

    <h1 class="as-archive-title"><?php single_term_title(); ?></h1>

    <?php
    $terms        = get_terms( array( 'taxonomy' => 'plm_category', 'hide_empty' => true ) );
    $current_term = get_queried_object();
    $current_slug = ( $current_term && ! is_wp_error( $current_term ) ) ? $current_term->slug : '';
    ?>

    <?php if ( $terms && ! is_wp_error( $terms ) ) : ?>
        <div class="as-tabs" aria-label="按品类切换">
            <a class="as-tab" href="<?php echo esc_url( get_post_type_archive_link( 'plm_product' ) ); ?>">全部</a>
            <?php foreach ( $terms as $term ) : ?>
                <a class="as-tab <?php echo $current_slug === $term->slug ? 'active' : ''; ?>"
                   href="<?php echo esc_url( get_term_link( $term ) ); ?>"
                   <?php echo $current_slug === $term->slug ? 'aria-current="page"' : ''; ?>>
                    <?php echo esc_html( $term->name ); ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ( term_description() ) : ?>
        <div class="as-term-desc"><?php echo wp_kses_post( term_description() ); ?></div>
    <?php endif; ?>

    <div class="as-grid">
        <?php if ( have_posts() ) : while ( have_posts() ) : the_post(); as_product_card(); endwhile; else : ?>
            <p class="as-empty">该分类下暂无产品。</p>
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
