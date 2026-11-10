<?php get_header(); ?>

<div class="as-container as-archive">
    <div class="as-blog-header">
        <h1 class="as-archive-title">博客</h1>
        <p class="as-blog-sub">选购指南 · 使用知识 · 保养建议</p>
    </div>

    <?php
    // 获取文章分类（排除空分类）
    $cats = get_terms( array(
        'taxonomy'   => 'category',
        'hide_empty' => true,
    ) );
    ?>

    <?php if ( $cats && ! is_wp_error( $cats ) ) : ?>
        <div class="as-tabs" role="group" aria-label="按分类筛选">
            <button type="button" class="as-tab active" data-target="all" aria-pressed="true">全部</button>
            <?php foreach ( $cats as $cat ) : ?>
                <button type="button" class="as-tab" data-target="<?php echo esc_attr( $cat->slug ); ?>" aria-pressed="false">
                    <?php echo esc_html( $cat->name ); ?>
                </button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="as-post-grid">
        <?php if ( have_posts() ) : while ( have_posts() ) : the_post();
            $pcats = get_the_category();
            $slugs = array();
            if ( $pcats ) {
                foreach ( $pcats as $c ) $slugs[] = $c->slug;
            }
        ?>
            <div class="as-grid-item" data-cats="<?php echo esc_attr( implode( ' ', $slugs ) ); ?>">
                <?php as_post_card(); ?>
            </div>
        <?php endwhile; else : ?>
            <p class="as-empty">还没有发布文章。去后台「文章 → 写文章」发布一篇试试。</p>
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
