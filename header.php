<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="as-skip-link" href="#as-main">跳到主要内容</a>

<header class="as-header">
    <div class="as-container as-header-inner">
        <div class="as-logo">
            <?php if ( has_custom_logo() ) : the_custom_logo(); else : ?>
                <a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php bloginfo( 'name' ); ?></a>
            <?php endif; ?>
        </div>

        <nav class="as-nav" id="as-primary-nav" aria-label="主导航">
            <?php
            wp_nav_menu( array(
                'theme_location' => 'primary',
                'container'      => false,
                'fallback_cb'    => 'as_nav_fallback',
                'menu_id'        => 'as-primary-menu',
            ) );
            ?>
            <div class="as-nav-mobile-search">
                <?php get_search_form(); ?>
            </div>
        </nav>

        <div class="as-header-search">
            <?php get_search_form(); ?>
        </div>

        <?php
        // 网站商城购物车入口（仅在商城开启时出现）
        if ( function_exists( 'as_web_mall_enabled' ) && as_web_mall_enabled() ) :
            $as_cart_n = (int) as_web_cart_count();
            $as_m_uid  = function_exists( 'as_web_current_uid' ) ? (int) as_web_current_uid() : 0;
            $as_m_vip  = ( $as_m_uid && function_exists( 'as_vip_is_vip' ) ) ? (bool) as_vip_is_vip( $as_m_uid ) : false;
        ?>
        <a class="as-cart-entry<?php echo $as_cart_n ? ' is-has' : ''; ?>"
           href="<?php echo esc_url( home_url( '/cart/' ) ); ?>"
           aria-label="购物车<?php echo $as_cart_n ? '，' . $as_cart_n . ' 件商品' : ''; ?>"
           title="购物车">
            <span class="as-cart-entry-ico" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="9.5" cy="19.5" r="1.5"/><circle cx="17.5" cy="19.5" r="1.5"/><path d="M2.6 3.6h2.3l2.5 10.9a1.8 1.8 0 0 0 1.8 1.4h8.4a1.8 1.8 0 0 0 1.8-1.4l1.8-7.6H6"/></svg>
                <?php if ( $as_cart_n ) : ?>
                    <span class="as-cart-entry-n"><?php echo (int) $as_cart_n; ?></span>
                <?php endif; ?>
            </span>
            <span class="as-cart-entry-txt">购物车</span>
        </a>
        <?php
        /*
         * 人头像（v2.87.43）：
         *   · 已有账号（已登录）→ 直接进个人中心 /member/；
         *   · 还没有账号 → 弹「注册 / 登录」弹窗（默认停在注册）。
         * href 仍指向 /member/：没 JS 时退回原来的会员页，功能不丢。
         */
        ?>
        <a class="as-member-entry<?php echo $as_m_vip ? ' is-vip' : ''; ?>"
           href="<?php echo esc_url( home_url( '/member/' ) ); ?>"
           <?php echo $as_m_uid ? '' : 'data-as-member-open="1"'; ?>
           aria-label="<?php echo esc_attr( $as_m_uid ? ( $as_m_vip ? '我的会员（已是会员）' : '我的会员' ) : '注册 / 登录' ); ?>"
           title="<?php echo esc_attr( $as_m_uid ? ( $as_m_vip ? '我的会员（会员）' : '我的会员' ) : '注册 / 登录' ); ?>">
            <span class="as-member-entry-ico" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8.2" r="3.4"/><path d="M5.4 20a6.6 6.6 0 0 1 13.2 0"/></svg>
            </span>
            <span class="as-member-entry-txt"><?php echo $as_m_uid ? '会员' : '注册'; ?></span>
        </a>
        <?php endif; ?>

        <?php
        /*
         * 管理员入口（v2.87.45）：**只给管理员看**。
         * 之前未登录时会输出一个「后台管理」锁形图标 → 点了直接进 wp-login.php，
         * 等于在前台给所有访客指了后台入口。现在 as_admin_entry_url() 未登录返回空，
         * 整块不输出；站长直接访问 /wp-admin/ 仍会被 WP 引到登录页，登录后入口自动出现。
         */
        $as_admin_url = as_admin_entry_url();
        if ( $as_admin_url ) :
        ?>
        <a class="as-admin-entry is-in"
           href="<?php echo esc_url( $as_admin_url ); ?>"
           rel="nofollow noopener"
           aria-label="进入网站后台"
           title="进入网站后台">
            <span class="as-admin-entry-ico" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3.1"/><path d="M12 2.8v2.4M12 18.8v2.4M4.5 7.5l2 1.2M17.5 15.3l2 1.2M4.5 16.5l2-1.2M17.5 8.7l2-1.2"/></svg>
            </span>
            <span class="as-admin-entry-txt">进入后台</span>
        </a>
        <?php endif; ?>

        <button type="button" class="as-nav-toggle" aria-expanded="false" aria-controls="as-primary-nav" aria-label="打开导航菜单">
            <span></span>
        </button>
    </div>
</header>
<main class="as-main" id="as-main">
