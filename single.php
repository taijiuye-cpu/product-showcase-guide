<?php get_header(); ?>

<?php while ( have_posts() ) : the_post(); ?>

<div class="as-container as-single-post">
    <?php as_breadcrumbs(); ?>

    <article class="as-post">
        <header class="as-post-header">
            <?php
            $as_cats = get_the_category();
            if ( $as_cats ) :
            ?>
            <div class="as-post-cats">
                <?php foreach ( $as_cats as $as_cat ) : ?>
                    <a class="as-post-cat" href="<?php echo esc_url( get_category_link( $as_cat->term_id ) ); ?>"><?php echo esc_html( $as_cat->name ); ?></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <h1 class="as-post-heading"><?php the_title(); ?></h1>

            <div class="as-post-meta">
                <time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
                <span class="as-post-meta-dot" aria-hidden="true"></span>
                <span><?php the_author(); ?></span>
                <?php // 阅读量（v2.87.15）：PV 口径，0 时不显示，避免新文章挂个「0 人浏览」 ?>
                <?php if ( as_post_views() > 0 ) : ?>
                    <span class="as-post-meta-dot" aria-hidden="true"></span>
                    <span><?php echo esc_html( number_format_i18n( as_post_views() ) ); ?> 人浏览</span>
                <?php endif; ?>
            </div>
        </header>

        <?php $as_thumb = as_post_thumbnail( get_the_ID(), 'large' ); ?>
        <?php if ( $as_thumb ) : ?>
            <div class="as-post-cover"><?php echo $as_thumb; ?></div>
        <?php endif; ?>

        <div class="as-post-content"><?php the_content(); ?></div>

        <?php
        /* 上一篇 / 下一篇（v2.87.121 加；v2.87.122 挪位置）：
           放在**正文结束处**（标签区之前），不是页面最底部。
           跨分类、全站按发布时间。 */
        ?>
        <?php as_prev_next_nav( get_the_ID(), '上一篇', '下一篇' ); ?>

        <footer class="as-post-footer">
            <?php the_tags( '<div class="as-post-tags">标签：', ' / ', '</div>' ); ?>
        </footer>
    </article>

    <?php
    /*
     * 相关推荐：**关联品类优先，不足时全站兜底**（v2.63.0）。
     *
     * 取数交给 as_article_related_product_ids() —— 和小程序文章页共用同一个函数，
     * 两端规则不会各写一份跑偏。用 post__in + orderby=post__in 保住取数顺序。
     * （对称设计：产品勾文章分类 → 产品页显示文章；文章选品类 → 文章页显示产品）
     */
    $as_pcat        = as_post_product_category( get_the_ID() );
    $as_product_ids = as_article_related_product_ids( get_the_ID() );
    $as_products    = $as_product_ids
        ? new WP_Query( array(
            'post_type'      => 'plm_product',
            'post_status'    => 'publish',
            'post__in'       => $as_product_ids,
            'orderby'        => 'post__in',
            'posts_per_page' => count( $as_product_ids ),
            'no_found_rows'  => true,
        ) )
        : null;
    ?>

    <?php if ( $as_products && $as_products->have_posts() ) : ?>
    <div class="as-section as-section-inset">
        <div class="as-section-head">
            <h2 class="as-section-title">相关推荐</h2>
            <?php
            $as_pcat_term = get_term( $as_pcat, 'plm_category' );
            if ( $as_pcat_term && ! is_wp_error( $as_pcat_term ) ) :
            ?>
                <a class="as-section-more" href="<?php echo esc_url( get_term_link( $as_pcat_term ) ); ?>">查看更多 →</a>
            <?php endif; ?>
        </div>
        <div class="as-grid">
            <?php
            while ( $as_products->have_posts() ) : $as_products->the_post();
                as_product_card();
            endwhile;
            wp_reset_postdata();
            ?>
        </div>
    </div>
    <?php endif; ?>

    <?php
    $cats = wp_get_post_categories( get_the_ID() );

    $related = new WP_Query( array(
        'post_type'      => 'post',
        'posts_per_page' => 3,
        'post__not_in'   => array( get_the_ID() ),
        'category__in'   => $cats ? $cats : array(),
    ) );
    ?>

    <?php if ( $related->have_posts() ) : ?>
    <div class="as-section as-section-inset">
        <h2 class="as-section-title">相关文章</h2>
        <div class="as-post-grid">
            <?php
            while ( $related->have_posts() ) : $related->the_post();
                as_post_card();
            endwhile;
            wp_reset_postdata();
            ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php endwhile; ?>

<?php get_footer(); ?>
