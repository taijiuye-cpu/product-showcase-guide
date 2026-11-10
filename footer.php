
</main>

<!-- 关注我们（二维码区块，位于页脚之上；后台「外观 → 自定义 → 页脚二维码」配置） -->
<?php $as_qr_list = as_qr_cards(); ?>
<?php if ( $as_qr_list ) : ?>
<section class="as-follow">
    <div class="as-container">
        <?php $as_qr_title = get_theme_mod( 'as_qr_section_title', '关注我们' ); ?>
        <?php if ( $as_qr_title ) : ?>
            <h2 class="as-follow-heading"><?php echo esc_html( $as_qr_title ); ?></h2>
        <?php endif; ?>

        <div class="as-follow-grid">
            <?php foreach ( $as_qr_list as $as_card ) : ?>
                <div class="as-follow-card">
                    <div class="as-follow-img">
                        <?php /*
                         * v2.74.1：补 width/height。Google Lighthouse 报「图片元素没有明确的
                         * width 和 height」—— 缺了浏览器就得等图加载完才知道占多大地方，
                         * 容易造成布局抖动（CLS）。这里的值按容器 .as-follow-img 实际内宽
                         *（150px − 8px×2 padding = 134px）写死，二维码都是方形。
                         * 实际显示尺寸仍由 CSS 控制，写属性只是给浏览器一个占位比例。
                         */ ?>
                        <img src="<?php echo esc_url( $as_card['img'] ); ?>"
                             alt="<?php echo esc_attr( $as_card['title'] ? $as_card['title'] . '二维码' : '二维码' ); ?>"
                             width="134" height="134"
                             loading="lazy" decoding="async">
                    </div>

                    <div class="as-follow-body">
                        <?php if ( $as_card['title'] ) : ?>
                            <p class="as-follow-name"><?php echo esc_html( $as_card['title'] ); ?></p>
                        <?php endif; ?>

                        <?php if ( $as_card['btn'] ) : ?>
                            <?php if ( $as_card['url'] ) : ?>
                                <a class="as-follow-btn" style="background-color:<?php echo esc_attr( $as_card['color'] ); ?>"
                                   href="<?php echo esc_url( $as_card['url'] ); ?>" target="_blank" rel="noopener">
                                    <?php echo esc_html( $as_card['btn'] ); ?>
                                    <span class="as-follow-arrow" aria-hidden="true">›</span>
                                </a>
                            <?php else : ?>
                                <span class="as-follow-btn is-static" style="background-color:<?php echo esc_attr( $as_card['color'] ); ?>">
                                    <?php echo esc_html( $as_card['btn'] ); ?>
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<footer class="as-footer">
    <div class="as-container">
        <div class="as-footer-grid">

            <!-- 品牌区 -->
            <div class="as-footer-col as-footer-brand-col">
                <div class="as-footer-logo">
                    <?php if ( has_custom_logo() ) : the_custom_logo(); else : ?>
                        <strong><?php bloginfo( 'name' ); ?></strong>
                    <?php endif; ?>
                </div>
                <p class="as-footer-desc">
                    专注成人用品导购与选购指南，帮你挑到合适的产品。<br>
                    所有商品均来自正规平台，购买请以商家页面为准。
                </p>
                <?php
                $as_mail   = get_theme_mod( 'as_contact_mail', '' );
                $as_wechat = get_theme_mod( 'as_wechat', '' );
                $as_weibo  = get_theme_mod( 'as_weibo', '' );                if ( $as_mail || $as_wechat || $as_weibo ) :
                ?>
                <div class="as-footer-social">
                    <?php if ( $as_wechat ) : ?>
                        <a href="<?php echo esc_url( $as_wechat ); ?>" target="_blank" rel="noopener">微信</a>
                    <?php endif; ?>
                    <?php if ( $as_weibo ) : ?>
                        <a href="<?php echo esc_url( $as_weibo ); ?>" target="_blank" rel="noopener">微博</a>
                    <?php endif; ?>
                    <?php if ( $as_mail ) : ?>
                        <a href="mailto:<?php echo esc_attr( $as_mail ); ?>">邮箱</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- 购物 -->
            <div class="as-footer-col">
                <h3>购物</h3>
                <ul>
                    <li><a href="<?php echo esc_url( get_post_type_archive_link( 'plm_product' ) ); ?>">全部产品</a></li>
                    <?php
                    $footer_cats = get_terms( array( 'taxonomy' => 'plm_category', 'hide_empty' => true, 'number' => 5 ) );
                    if ( $footer_cats && ! is_wp_error( $footer_cats ) ) :
                        foreach ( $footer_cats as $fc ) : ?>
                            <li><a href="<?php echo esc_url( get_term_link( $fc ) ); ?>"><?php echo esc_html( $fc->name ); ?></a></li>
                        <?php endforeach;
                    endif; ?>
                </ul>
            </div>

            <!-- 内容 -->
            <div class="as-footer-col">
                <h3>内容</h3>
                <ul>
                    <li><a href="<?php echo esc_url( as_blog_url() ); ?>">博客首页</a></li>
                    <?php
                    $footer_post_cats = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => true, 'number' => 4 ) );
                    if ( $footer_post_cats && ! is_wp_error( $footer_post_cats ) ) :
                        foreach ( $footer_post_cats as $fp ) : ?>
                            <li><a href="<?php echo esc_url( get_term_link( $fp ) ); ?>"><?php echo esc_html( $fp->name ); ?></a></li>
                        <?php endforeach;
                    endif; ?>
                </ul>
            </div>

            <!-- 帮助 -->
            <div class="as-footer-col">
                <h3>帮助与关于</h3>
                <?php if ( has_nav_menu( 'footer' ) ) : ?>
                    <ul>
                        <?php wp_nav_menu( array(
                            'theme_location' => 'footer',
                            'container'      => false,
                            'items_wrap'     => '%3$s',
                            'depth'          => 1,
                            'fallback_cb'    => false,
                        ) ); ?>
                    </ul>
                <?php else : ?>
                    <ul>
                        <li><a href="<?php echo esc_url( home_url( '/about/' ) ); ?>">关于我们</a></li>
                        <li><a href="<?php echo esc_url( home_url( '/disclaimer/' ) ); ?>">免责声明</a></li>
                        <li><a href="<?php echo esc_url( home_url( '/privacy/' ) ); ?>">隐私政策</a></li>
                        <li><a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>">联系我们</a></li>
                    </ul>
                <?php endif; ?>

                <?php
                $as_hours = get_theme_mod( 'as_work_hours', '周一至周五 9:00-18:00' );
                $as_mail2 = get_theme_mod( 'as_contact_mail', '' );
                if ( $as_mail2 || $as_hours ) :
                ?>
                <div class="as-footer-contact">
                    <?php if ( $as_mail2 ) : ?>
                        <p>邮箱：<a href="mailto:<?php echo esc_attr( $as_mail2 ); ?>"><?php echo esc_html( $as_mail2 ); ?></a></p>
                    <?php endif; ?>
                    <?php if ( $as_hours ) : ?>
                        <p>工作时间：<?php echo esc_html( $as_hours ); ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php
                // 管理员入口（页脚版）：和页头同一个链接；v2.87.45 后未登录不再输出
                $as_admin_url = as_admin_entry_url();
                if ( $as_admin_url ) :
                ?>
                <p class="as-footer-admin">
                    <a class="as-footer-admin-link is-in"
                       href="<?php echo esc_url( $as_admin_url ); ?>"
                       rel="nofollow noopener">进入网站后台</a>
                </p>
                <?php endif; ?>
            </div>

        </div>

        <!-- 合规提示 -->
        <?php $as_notice = get_theme_mod( 'as_footer_notice', as_default_footer_notice() ); ?>
        <?php if ( $as_notice ) : ?>
        <div class="as-footer-notice">
            <p>
                <strong>合规提示：</strong>
                <?php echo esc_html( $as_notice ); ?>
            </p>
        </div>
        <?php endif; ?>

        <!-- 版权条 -->
        <div class="as-footer-bottom">
            <p class="as-copyright">
                &copy; <?php echo esc_html( current_time( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?>. 保留所有权利。
            </p>
            <?php
            $as_icp     = get_theme_mod( 'as_icp_number', '' );
            $as_icp_url = get_theme_mod( 'as_icp_url', 'https://beian.miit.gov.cn/' );
            if ( $as_icp ) :
            ?>
            <p class="as-footer-icp">
                <a href="<?php echo esc_url( $as_icp_url ? $as_icp_url : 'https://beian.miit.gov.cn/' ); ?>" target="_blank" rel="nofollow noopener">
                    备案号：<?php echo esc_html( $as_icp ); ?>
                </a>
            </p>
            <?php endif; ?>
        </div>
    </div>
</footer>

<?php
// 网页端「注册 / 登录」弹窗（v2.87.43）—— 未登录时才有，已登录直接进个人中心
if ( function_exists( 'as_web_member_modal' ) ) {
	echo as_web_member_modal();
}
?>

<?php wp_footer(); ?>
</body>
</html>
