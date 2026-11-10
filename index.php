<?php
/**
 * 最终回落模板
 */
get_header();
?>
<div class="as-container as-archive">
    <?php as_breadcrumbs(); ?>

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
        <p class="as-empty">没有内容。</p>
    <?php endif; ?>
</div>
<?php get_footer(); ?>
