<?php
/**
 * Template Name: 全部品牌
 */
get_header();

$brands = get_terms( array(
    'taxonomy'   => 'plm_brand',
    'hide_empty' => true,
) );
?>

<div class="as-container as-archive">
    <div class="as-blog-header">
        <h1 class="as-archive-title">全部品牌</h1>
        <p class="as-blog-sub">按品牌选购，找到你信赖的产品</p>
    </div>

    <?php if ( $brands && ! is_wp_error( $brands ) ) : ?>
        <div class="as-brand-grid">
            <?php foreach ( $brands as $brand ) :
                $thumb_id = get_term_meta( $brand->term_id, 'brand_thumb', true );
                ?>
                <a class="as-brand-item" href="<?php echo esc_url( get_term_link( $brand ) ); ?>">
                    <div class="as-brand-logo">
                        <?php if ( $thumb_id ) : ?>
                            <img src="<?php echo esc_url( wp_get_attachment_url( $thumb_id ) ); ?>" alt="<?php echo esc_attr( $brand->name ); ?>">
                        <?php else : ?>
                            <span class="as-brand-initial"><?php echo esc_html( mb_substr( $brand->name, 0, 1 ) ); ?></span>
                        <?php endif; ?>
                    </div>
                    <span class="as-brand-name"><?php echo esc_html( $brand->name ); ?></span>
                    <span class="as-brand-count"><?php echo intval( $brand->count ); ?> 件产品</span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php else : ?>
        <p class="as-empty">还没有品牌。</p>
    <?php endif; ?>
</div>

<?php get_footer(); ?>