<?php
/**
 * 橱窗风格专用产品卡（v2.87.43）
 * --------------------------------------------------------------------------
 * 为什么单独写一个组件：货架风格那张卡（as_product_card）的信息是**竖着排**的
 * （标题 / 销量 / 价格 / 券 / 会员价各占一整行），而橱窗风格要的是**成对同行**：
 *
 *     标题（最多 2 行）
 *     已售 N 件            ¥259 ¥399      ← 销量与价格一行
 *     满150减30            会员到手 ¥183   ← 优惠与会员价一行
 *     [      去购买      ]                ← 通栏，贴卡片底部
 *
 * 要做这种「两两同行」的排布，纯 CSS 改不动（元素顺序由 DOM 决定），
 * 所以单独出一个组件；**数据来源仍是原来那套函数**（as_web_price_info /
 * as_product_sales / as_buy_button），价格、券、会员价、购买行为一字不改。
 *
 * ⚠️ 网格里**不输出券倒计时**（用户 2026-11-05 要求）：倒计时只在优惠带
 *    左侧的大号文案里出现一次，卡片里重复是噪音。
 *
 * @package product-showcase-guide
 */

defined( 'ABSPATH' ) || exit;

/**
 * 橱窗风格产品卡。
 *
 * @param int    $post_id  商品 ID（0 = 当前文章）。
 * @param string $variant  变体 class（is-rank / is-list）。
 */
function as_sc_product_card( $post_id = 0, $variant = '' ) {
	$post_id = $post_id ? (int) $post_id : get_the_ID();
	if ( ! $post_id ) {
		return;
	}

	$title     = get_the_title( $post_id );
	$price     = get_post_meta( $post_id, 'dg_price', true );
	$old       = get_post_meta( $post_id, 'dg_old_price', true );
	$permalink = get_permalink( $post_id );
	$sales     = function_exists( 'as_product_sales' ) ? (int) as_product_sales( $post_id ) : 0;

	// 价格 / 券 / 会员到手价：与 as_web_price_info() 同一份口径
	$info  = function_exists( 'as_web_price_info' ) ? as_web_price_info( $post_id ) : array();
	$promo = ! empty( $info['coupons'] ) ? (string) $info['coupons'][0]['label'] : '';
	$na    = empty( $info['coupons'] ) && ! empty( $info['na'] );
	$vip   = '';
	if ( ! empty( $info['vip'] ) && isset( $info['final'] ) && null !== $info['final'] && function_exists( 'as_vip_money' ) ) {
		// v2.87.104：标签走 as_web_price_info()['tag']，会员「到手价」/ 非会员「会员价」
		$as_card_tag = ! empty( $info['tag'] ) ? (string) $info['tag'] : '会员价';
		$vip         = $as_card_tag . ' ¥' . as_vip_money( $info['final'] );
	}

	// 左上角券徽章：只有「有可用券 + 非控价商品」才出（v2.87.96）
	$badge = function_exists( 'as_coupon_badge_name' ) ? as_coupon_badge_name( $post_id ) : '';

	// 封面：特色图像 → 图集第一张（与全站口径一致）
	$cid = function_exists( 'as_product_cover_id' ) ? as_product_cover_id( $post_id ) : 0;
	$img = $cid ? wp_get_attachment_image( $cid, 'as_product_card', false, array( 'alt' => $title ) ) : '';
	if ( ! $img ) {
		$img = as_placeholder_img( $title, 600, 600 );
	}
	?>
	<div class="as-sc-card<?php echo $variant ? ' ' . esc_attr( $variant ) : ''; ?>">
		<a class="as-sc-card-img" href="<?php echo esc_url( $permalink ); ?>"><?php if ( '' !== $badge ) : ?><span class="as-coupon-badge"><?php echo esc_html( $badge ); ?></span><?php endif; ?><?php echo $img; ?></a>
		<div class="as-sc-card-body">
			<h3 class="as-sc-card-title"><a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a></h3>

			<div class="as-sc-card-row">
				<span class="as-sc-card-sales"><?php echo $sales > 0 ? '已售 ' . (int) $sales . ' 件' : ''; ?></span>
				<span class="as-sc-card-price">
					<b>¥<?php echo esc_html( $price ? $price : '—' ); ?></b>
					<?php if ( $old ) : ?><s>¥<?php echo esc_html( $old ); ?></s><?php endif; ?>
				</span>
			</div>

			<?php if ( $promo || $na || $vip ) : ?>
				<div class="as-sc-card-row is-tags">
					<?php if ( $promo ) : ?>
						<span class="as-sc-card-promo"><?php echo esc_html( $promo ); ?></span>
					<?php elseif ( $na ) : ?>
						<span class="as-sc-card-promo is-na">优惠券不可用</span>
					<?php endif; ?>
					<?php if ( $vip ) : ?>
						<span class="as-sc-card-vip"><?php echo esc_html( $vip ); ?></span>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="as-sc-card-buy"><?php echo as_buy_button( $post_id ); ?></div>
		</div>
	</div>
	<?php
}
