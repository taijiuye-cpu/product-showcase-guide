<?php get_header(); ?>

<?php while ( have_posts() ) : the_post(); ?>

<?php
$as_id       = get_the_ID();
$price       = get_post_meta( $as_id, 'dg_price', true );
$old         = get_post_meta( $as_id, 'dg_old_price', true );
$service_tags = function_exists( 'as_service_tags' ) ? as_service_tags() : array();
$brands      = get_the_terms( $as_id, 'plm_brand' );
$cats        = get_the_terms( $as_id, 'plm_category' );
$specs       = as_product_spec_rows( $as_id );
$pros        = as_meta_lines( $as_id, 'dg_pros' );
$cons        = as_meta_lines( $as_id, 'dg_cons' );
$reason      = get_post_meta( $as_id, 'dg_reason', true );

// 规格参数已移到顶部信息区，这里只判断下方区块是否还有内容
$has_extra   = $pros || $cons || $reason;
?>

<div class="as-container as-single">
    <?php as_breadcrumbs(); ?>

    <div class="as-single-top">
        <div class="as-single-img">
            <?php
            /*
             * 商品图集（京东那套：主图轮播 + 缩略图条 + 左右箭头 + 计数器）。
             * 只有一张图时 as_product_gallery_html() 返回空串，
             * 自动落回原来的单图分支，版面与改动前完全一致。
             */
            $as_gallery = as_product_gallery_html( $as_id, get_the_title() );
            ?>
            <?php if ( $as_gallery ) : ?>
                <?php echo $as_gallery; ?>
            <?php else : ?>
                <?php
                /*
                 * 单图分支（图集只有 1 张，或只有特色图像）。
                 * 用全站统一的封面口径：特色图像 → 图集第一张。
                 * 否则"只往图集加了一张图、没设特色图像"时会误显示占位灰图（明明有图）。
                 */
                $as_cover = as_product_cover_id( $as_id );
                $as_thumb = $as_cover ? wp_get_attachment_image( $as_cover, 'as_product_large', false, array(
                    'alt'           => get_the_title(),
                    'fetchpriority' => 'high',
                ) ) : '';
                ?>
                <?php if ( $as_thumb ) : ?>
                    <?php echo $as_thumb; ?>
                <?php else : ?>
                    <?php echo as_placeholder_img( get_the_title(), 800, 800 ); ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="as-single-info">
            <h1><?php the_title(); ?></h1>

            <!-- 品牌 / 品类在下方「规格参数」里已经有了，这里不再重复 -->
            <div class="as-single-meta">
                <?php foreach ( $service_tags as $svc ) : ?><span class="as-tag as-tag-green"><?php echo esc_html( $svc ); ?></span><?php endforeach; ?>
            </div>

            <?php
            /* =====================================================================
               价格区（v2.64.0）
               ---------------------------------------------------------------------
               三行，从上到下：
                 ① 现价 + 原价（划线）  —— 永远显示，作为「省多少」的对照基准
                 ② 券信息 + 剩余时间（有券才出现）
                 ③ 到手价一行：会员到手价 ¥166 + 省 ¥53 + 仅小程序可入会
               顺序有讲究：先看标价，再看能减多少，最后看实际要付多少。

               金额口径（服务端算完再输出，与小程序、结算页同一套）：
                 到手价 = (现价 − 券) × 会员折扣率   （v2.87.0：先减券、再打会员折）
                 省多少 = 现价 − 到手价        **已含会员折扣 + 券折扣两部分**
               ===================================================================== */
            $as_price_now = (float) $price;

            /*
             * 价格区口径的**唯一实现**在 as_web_price_info()（inc/vip.php）——
             * 详情页与所有商品卡片共用，免得几处各算一份又跑偏。
             *
             * 到手价（服务端算完再输出）：
             *   · 普通商品 → 会员价 − 券减免（券只取最划算的那一张）
             *   · 特殊商品（现价够门槛、会员价却低于券门槛、且会员价还比券后价高）
             *     → 会员价本身已被重锚成「券后价 − 后台差额」，券**不再叠加**
             *   · 没有会员价（未启用 / 单品不参与）但有券 → 现价 − 券，标签「券后价」
             *   · 两样都没有 → final = null，这一行不输出（上面仍有现价/原价行，页面不会空）
             */
            $as_info       = function_exists( 'as_web_price_info' ) ? as_web_price_info( $as_id ) : array();
            $as_vip        = isset( $as_info['vip'] ) ? $as_info['vip'] : null;
            $as_promos     = isset( $as_info['coupons'] ) ? $as_info['coupons'] : array();
            $as_final      = isset( $as_info['final'] ) ? $as_info['final'] : null;
            $as_final_tag  = isset( $as_info['tag'] ) ? $as_info['tag'] : '会员价';
            $as_final_save = isset( $as_info['save'] ) ? $as_info['save'] : 0.0;

            // 「加入会员」入口（v2.87.40）：原来的「仅小程序可入会」已是错的，也不可点
            $as_vip_join = ( $as_vip && function_exists( 'as_vip_join_entry' ) ) ? as_vip_join_entry() : array();
            $as_vip_tome = function_exists( 'as_vip_is_vip' ) ? (bool) as_vip_is_vip() : false;
            ?>

            <!-- ① 标价行：现价 + 原价 + 销量。永远显示，且排在优惠券上面 —— 它是「省多少」的对照基准 -->
            <div class="as-single-price">
                <span class="as-price-now">¥<?php echo esc_html( $price ? $price : '—' ); ?></span>
                <?php if ( $old ) : ?><span class="as-price-old">¥<?php echo esc_html( $old ); ?></span><?php endif; ?>
                <?php
                /*
                 * 销量（v2.74.0）：放价格行右侧，与商品卡片、首页销量榜、小程序同一口径
                 *（as_product_sales = 实际销量 + 虚拟销量）。为 0 时不输出。
                 */
                $as_single_sales = function_exists( 'as_product_sales' ) ? (int) as_product_sales( get_the_ID() ) : 0;
                ?>
                <?php if ( $as_single_sales > 0 ) : ?>
                    <span class="as-single-sales">已售 <?php echo (int) $as_single_sales; ?> 件</span>
                <?php endif; ?>
            </div>

            <?php if ( $as_promos || $as_info['na'] ) : ?>
                <?php
                /*
                 * 倒计时：结束时间戳 + 初始文案统一由 as_coupon_countdown() 计算
                 * （与商品卡片共用同一份逻辑与文案格式，改一处两端都变）。
                 */
                $as_cd = as_coupon_countdown( $as_promos );
                ?>
                <div class="as-single-promos">
                    <?php if ( $as_promos ) : ?>
                        <?php
                        /*
                         * v2.86.56 多券显示（用户 2026-10-14 定）：
                         * 一个商品**只适用一张券**（as_mini_coupons_for_product 已按减免额
                         * DESC 排序，[0] 即「对用户最划算」那张），到手价也按它算。
                         * 所以这里只显示最优券一条 + 倒计时用**它自己的** end_at；
                         * 还有 N 张别的券不再逐张罗列（罗列会让人以为能叠加/任选）。
                         * v2.87.112：按用户要求，连「另有 N 张可用券，结算时自动选
                         * 最划算的一张」那行弱提示也不再输出；结算自动选最优券的
                         * 逻辑不变，只是不写在页面上。
                         */
                        $as_best_promo = $as_promos[0];
                        $as_best_cd   = as_coupon_countdown( array( $as_best_promo ) );
                        ?>
                        <div class="as-promo">
                            <span class="as-promo-badge">券</span>
                            <span class="as-promo-label"><?php echo esc_html( $as_best_promo['label'] ); ?></span>
                            <?php
                            /*
                             * v2.87.0：「非会员可用」标已取消 —— 新口径是「先减券、再打会员折」，
                             * 券门槛恒按现价判，会员与非会员看到同一张券，不存在"这张券会员用不上"。
                             */
                            ?>
                            <?php if ( $as_best_cd['end'] ) : ?>
                                <span class="as-promo-cd" data-countdown="<?php echo esc_attr( $as_best_cd['end'] ); ?>"><?php echo esc_html( $as_best_cd['text'] ); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php else : ?>
                        <?php
                        // 有生效券、范围也含本商品，但**现价本身就够不到门槛** → 不静默消失，明说不适用
                        ?>
                        <div class="as-promo as-promo-na">
                            <span class="as-promo-badge as-promo-badge-na">券</span>
                            <span class="as-promo-label">优惠券不可用</span>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ( null !== $as_final ) : ?>
                <div class="as-vip-box">
                    <div class="as-vip-line">
                        <span class="as-vip-tag"><?php echo esc_html( $as_final_tag ); ?></span>
                        <span class="as-vip-price">¥<?php echo esc_html( as_vip_money( $as_final ) ); ?></span>
                        <?php if ( $as_final_save > 0 ) : ?>
                            <?php
                            // 「省」= 现价 − 到手价，**已含会员折扣与券折扣两部分**。
                            // 对照基准是上面那行的现价，所以这里不再重复摆一个划线价。
                            ?>
                            <span class="as-vip-save">省 ¥<?php echo esc_html( as_vip_money( $as_final_save ) ); ?></span>
                        <?php endif; ?>
                        <?php if ( $as_vip_tome ) : ?>
                            <?php // 已经是会员：不再劝开通，只报「这个价你已享」 ?>
                            <span class="as-vip-joined">已享会员价</span>
                        <?php elseif ( ! empty( $as_vip_join['url'] ) ) : ?>
                            <a class="as-vip-join" href="<?php echo esc_url( $as_vip_join['url'] ); ?>"><?php echo esc_html( $as_vip_join['text'] ); ?><span class="as-vip-join-arw" aria-hidden="true">›</span></a>
                        <?php elseif ( ! empty( $as_vip_join['text'] ) ) : ?>
                            <?php // 没开网站商城、后台也没填链接：退回纯说明文字，别给一个点不动的假入口 ?>
                            <span class="as-vip-where"><?php echo esc_html( $as_vip_join['text'] ); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ( $specs ) : ?>
            <dl class="as-spec-list as-spec-list-top">
                <?php
                /*
                 * v2.87.110：给「品牌 / 品类 / 效果类型 / 规格」打上 as-spec-row-key 标记。
                 * 电脑端两栏布局据此把这四项排成「标签一行 + 对应值一行」的紧凑表；
                 * 手机端 CSS 不读这个 class，DOM 顺序与外观完全不变。
                 * v2.87.112：按用户要求去掉「商品参数 · 共 N 项」标题行，
                 * 参数平铺展开、不做卡内滑动，所以不再需要 data-count 属性。
                 * 用 label 白名单而不是 nth-child：某个字段没填时列数会自动收缩，不会把
                 * 「适合人群」这种长文本误当成第 4 列。
                 */
                $as_key_spec_labels = array( '品牌', '品类', '效果类型', '规格' );
                ?>
                <?php foreach ( $specs as $row ) : ?>
                    <div class="as-spec-row<?php echo in_array( $row['label'], $as_key_spec_labels, true ) ? ' as-spec-row-key' : ''; ?>">
                        <?php if ( '' !== $row['label'] ) : ?>
                            <dt><?php echo esc_html( $row['label'] ); ?></dt>
                        <?php else : ?>
                            <dt class="as-spec-row-plain" aria-hidden="true">—</dt>
                        <?php endif; ?>
                        <dd>
                            <?php if ( ! empty( $row['link'] ) && ! is_wp_error( $row['link'] ) ) : ?>
                                <a href="<?php echo esc_url( $row['link'] ); ?>"><?php echo esc_html( $row['value'] ); ?></a>
                            <?php else : ?>
                                <?php echo esc_html( $row['value'] ); ?>
                            <?php endif; ?>
                        </dd>
                    </div>
                <?php endforeach; ?>
            </dl>
            <?php endif; ?>

            <div class="as-single-buy">
                <?php
                /*
                 * 三个动作**恒在同一行**（v2.87.40）：加入购物车 / 立即购买 / 小程序下单。
                 *   · 立即购买 = 实心主色（唯一主操作，本站下单最省事）
                 *   · 小程序下单 = 浅底描边（次要，跳第三方平台）
                 *   · 加入购物车 = 白底灰边（备用，先囤后买）
                 * 断点只收字号与内边距，**不允许换行** —— 换行后三个按钮高低不齐，
                 * 手机端还会白占一整行。
                 *
                 * 🔴 v2.87.50：手机端新增**吸底购买栏** —— 整组按钮固定在屏幕底部。
                 *   · 按钮组由 as_product_buy_group() 输出**两份**（页内版 / 吸底版），
                 *     HTML 结构完全一致，差别只有外层 class；
                 *   · CSS 负责切换：桌面端只显示页内版，手机端只显示吸底版；
                 *   · 吸底版**保留 data-as-mp* 属性** —— wx-jump.js 靠
                 *     closest('[data-as-mp]') 找按钮，属性丢了小程序下单就废；
                 *   · 吸底版不依赖 JS（纯 CSS 固定），JS 没了照样能点。
                 * v2.87.112：按用户要求删掉页内那句「本站可直接下单付款；
                 * 「小程序下单」跳转第三方平台选购（电脑端扫码，手机端直达）」。
                 * 手机端吸底栏里的短提示不受影响，仍保留在下面。
                 */
                if ( function_exists( 'as_web_mall_enabled' ) && as_web_mall_enabled() ) :
                    ?>
                    <?php as_product_buy_group( $as_id, 'as-shop-buy-inline' ); ?>
                    <?php
                    /*
                     * 吸底版（v2.87.50）：外层 sticky-wrap 负责固定定位 + 承载合规提示小字。
                     *   · 按钮组在 wrap 里保持静态定位，排版跟页内版完全一致；
                     *   · 那句「小程序下单跳转第三方平台选购」的手机端版本放在按钮下面
                     *     —— 页内那句在手机端会被吸底栏切一半，观感差。
                     */
                    ?>
                    <div class="as-shop-buy-sticky-wrap">
                        <?php as_product_buy_group( $as_id, 'as-shop-buy-sticky' ); ?>
                        <p class="as-shop-buy-tip">「小程序下单」跳转第三方平台选购</p>
                    </div>
                <?php else : ?>
                    <?php
                    /*
                     * 网站商城没开：只剩跳第三方平台这一条路（v2.87.40 文案对齐）。
                     * 开了「网页 → 小程序」时按钮实际走的是小程序码/小程序链接，
                     * 叫「立即购买」会让人以为是本站下单 —— 统一叫「小程序下单」。
                     */
                    $as_off_jump = function_exists( 'as_wx_jump_enabled' ) && as_wx_jump_enabled();
                    $as_off_tip  = $as_off_jump ? '「小程序下单」跳转第三方平台选购' : '下单前请确认商家资质';
                    ?>
                    <div class="as-shop-buy as-shop-buy-inline">
                        <?php echo as_buy_button( $as_id, $as_off_jump ? '小程序下单' : '立即购买', false ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    </div>
                    <p class="as-buy-note"><?php echo esc_html( $as_off_tip ); ?></p>
                    <div class="as-shop-buy-sticky-wrap">
                        <div class="as-shop-buy as-shop-buy-sticky">
                            <?php echo as_buy_button( $as_id, $as_off_jump ? '小程序下单' : '立即购买', false ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        </div>
                        <p class="as-shop-buy-tip"><?php echo esc_html( $as_off_tip ); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ( $has_extra ) : ?>
    <div class="as-product-extra">

        <?php if ( $pros || $cons ) : ?>
        <section class="as-extra-block">
            <h2 class="as-extra-title">优缺点</h2>
            <div class="as-pros-cons">
                <?php if ( $pros ) : ?>
                    <div class="as-pros">
                        <h3 class="as-verdict-head as-verdict-head-pro">优点</h3>
                        <ul>
                            <?php foreach ( $pros as $item ) : ?>
                                <li><?php echo esc_html( $item ); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ( $cons ) : ?>
                    <div class="as-cons">
                        <h3 class="as-verdict-head as-verdict-head-con">缺点</h3>
                        <ul>
                            <?php foreach ( $cons as $item ) : ?>
                                <li><?php echo esc_html( $item ); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ( $reason ) : ?>
        <section class="as-extra-block as-reason">
            <h2 class="as-extra-title">推荐理由</h2>
            <p><?php echo esc_html( $reason ); ?></p>
        </section>
        <?php endif; ?>

    </div>
    <?php endif; ?>

    <div class="as-single-desc">
        <h2>商品详情</h2>
        <?php the_content(); ?>
    </div>

    <?php
    /*
     * 上一个 / 下一个产品（v2.87.121，用户 2026-11-21 加；v2.87.122 挪位置）：
     * 放在**商品详情结束处**、相关推荐之前 —— 不是页面最底部。
     * 商品页是左右两栏，所以它占**左栏**（grid-column: 1），不压右栏。
     * 跨分类、全站按发布时间取相邻商品。
     * 右栏的吸顶/吸底落地线以这个区块为锚 —— 导航完全进入视口后，
     * 右栏就放手随页面滚走（single-two-col.js）。
     */
    ?>
    <?php as_prev_next_nav( $as_id, '上一个产品', '下一个产品' ); ?>

    <?php
    $related_cat = ( $cats && ! is_wp_error( $cats ) ) ? $cats[0]->term_id : 0;

    /*
     * 相关推荐：**同分类同品牌 → 同分类 → 全部产品** 三级递进（v2.69.0）。
     *
     * 取数交给 as_product_related_product_ids() —— 和小程序商品页共用同一个函数，
     * 两端不会各写一份跑偏。用 post__in + orderby=post__in 保住取数顺序。
     * 完全没有同分类时，函数内部第三级「全部产品」兜底，推荐区不会空。
     */
    $as_rel_ids = as_product_related_product_ids( $as_id );
    $related    = $as_rel_ids
        ? new WP_Query( array(
            'post_type'      => 'plm_product',
            'post_status'    => 'publish',
            'post__in'       => $as_rel_ids,
            'orderby'        => 'post__in',
            'posts_per_page' => count( $as_rel_ids ),
            'no_found_rows'  => true,
        ) )
        : null;
    ?>

    <?php if ( $related && $related->have_posts() ) : ?>
    <div class="as-section as-section-inset">
        <h2 class="as-section-title"><?php echo $related_cat ? '相关推荐' : '更多产品'; ?></h2>
        <div class="as-grid">
            <?php
            while ( $related->have_posts() ) : $related->the_post();
                as_product_card();
            endwhile;
            wp_reset_postdata();
            ?>
        </div>
    </div>
    <?php endif; ?>

    <?php
    /*
     * 相关文章：**手选优先 + 分类兜底**（v2.62.0）。
     *
     * 取数交给 as_product_related_article_ids() —— 和小程序详情页共用同一个函数，
     * 两端规则不会各写一份跑偏。用 post__in + orderby=post__in 保住手选的顺序。
     */
    $related_ids   = as_product_related_article_ids( $as_id );
    $related_posts = $related_ids
        ? new WP_Query( array(
            'post_type'           => 'post',
            'post_status'         => 'publish',
            'post__in'            => $related_ids,
            'orderby'             => 'post__in',
            'posts_per_page'      => count( $related_ids ),
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
        ) )
        : null;
    ?>

    <?php if ( $related_posts && $related_posts->have_posts() ) : ?>
    <div class="as-section as-section-inset">
        <h2 class="as-section-title">相关文章</h2>
        <div class="as-post-grid">
            <?php
            while ( $related_posts->have_posts() ) : $related_posts->the_post();
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
