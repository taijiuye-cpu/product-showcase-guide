<?php get_header(); ?>

<div class="as-container as-404">
    <p class="as-404-code">404</p>
    <h1>页面走丢了</h1>
    <p class="as-404-desc">你要找的页面不存在，试试搜索，或者看看下面的热门产品。</p>

    <div class="as-404-search"><?php get_search_form(); ?></div>

    <div class="as-404-actions">
        <a class="as-btn-primary" href="<?php echo esc_url( home_url( '/' ) ); ?>">返回首页</a>
        <a class="as-btn-ghost as-btn-ghost-light" href="<?php echo esc_url( get_post_type_archive_link( 'plm_product' ) ); ?>">浏览全部产品</a>
    </div>

    <?php
    $hot = new WP_Query( array(
        'post_type'      => 'plm_product',
        'posts_per_page' => 4,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ) );
    if ( $hot->have_posts() ) :
    ?>
        <div class="as-404-recommend">
            <h2 class="as-section-title">热门推荐</h2>
            <div class="as-grid">
                <?php
                while ( $hot->have_posts() ) : $hot->the_post();
                    as_product_card();
                endwhile;
                wp_reset_postdata();
                ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php get_footer(); ?>
