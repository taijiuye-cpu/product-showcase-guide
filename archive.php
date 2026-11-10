<?php get_header(); ?>

<div class="as-container as-archive">
    <?php as_breadcrumbs(); ?>

    <h1 class="as-archive-title">
        <?php
        if ( is_category() ) {
            single_cat_title();
        } elseif ( is_tag() ) {
            single_tag_title();
        } elseif ( is_author() ) {
            the_author();
        } elseif ( is_date() ) {
            echo esc_html( get_the_date( 'Y年n月' ) );
        } else {
            echo '博客';
        }
        ?>
    </h1>

    <?php if ( is_category() && term_description() ) : ?>
        <div class="as-term-desc"><?php echo wp_kses_post( term_description() ); ?></div>
    <?php endif; ?>

    <?php if ( have_posts() ) : ?>
        <div class="as-post-grid">
            <?php while ( have_posts() ) : the_post(); as_post_card(); endwhile; ?>
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
    <?php else : ?>
        <p class="as-empty">暂无文章。</p>
    <?php endif; ?>
</div>

<?php get_footer(); ?>
