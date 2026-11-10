<?php
/**
 * 高端倒计时组件（v2.87.45）
 * --------------------------------------------------------------------------
 * 用户 2026-11-06：「首页优惠模块和优惠页那边需要优化下，一个高端大气的倒计时，
 * 精确到秒」。
 *
 * 为什么单独做一个组件：
 *   · 首页优惠模块（深色带）与优惠汇总页 /sale/（浅底）**底色完全不同**，
 *     同一段倒计时 HTML 要能在两种底色下都好看 → 颜色交给 CSS 变量，
 *     这里只输出结构。
 *   · 两处都要「精确到秒」：服务端先算一份初始文案（避免 JS 执行前空白），
 *     前端每秒重算（沿用 main.js 的 [data-countdown] 机制，秒级时间戳是唯一口径）。
 *
 * 结构（数字块式，天/时/分/秒四段）：
 *
 *   <div class="as-cd" data-countdown="1793…" data-cd-variant="dark">
 *     <div class="as-cd-unit"><b>25</b><i>天</i></div>
 *     <div class="as-cd-unit"><b>11</b><i>时</i></div>
 *     <div class="as-cd-unit"><b>42</b><i>分</i></div>
 *     <div class="as-cd-unit"><b>08</b><i>秒</i></div>
 *   </div>
 *
 * ⚠️ 时间戳单位：**Unix 秒**（不是毫秒）。JS 侧 Date.now() 是毫秒，
 *    相减必须先 /1000 —— 全站统一这条，别在别处再写错。
 *
 * @param int    $end     结束时间戳（Unix 秒），0 = 不显示。
 * @param string $variant 'dark'（深色带）/ 'light'（浅底）/ 'hero'（首页大号）。
 * @param string $label   倒计时前面的说明文字（如「距结束」）。
 *
 * @package product-showcase-guide
 */

defined( 'ABSPATH' ) || exit;

/**
 * 输出高端倒计时。
 *
 * @param int    $end     结束时间戳（Unix 秒）。
 * @param string $variant 配色变体：dark / light / hero。
 * @param string $label   前置说明文字。
 */
function as_big_countdown( $end = 0, $variant = 'light', $label = '' ) {
	$end = (int) $end;
	if ( $end <= 0 ) {
		return;
	}

	$left = max( 0, $end - time() );
	$d = (int) floor( $left / DAY_IN_SECONDS );
	$h = (int) floor( ( $left % DAY_IN_SECONDS ) / HOUR_IN_SECONDS );
	$m = (int) floor( ( $left % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS );
	$s = (int) ( $left % MINUTE_IN_SECONDS );

	$units = array(
		array( $d, '天' ),
		array( $h, '时' ),
		array( $m, '分' ),
		array( $s, '秒' ),
	);

	$cls = 'as-cd is-' . sanitize_html_class( $variant );
	?>
	<div class="<?php echo esc_attr( $cls ); ?>" data-countdown="<?php echo esc_attr( (string) $end ); ?>" data-cd-blocks="1">
		<?php if ( $label ) : ?>
			<span class="as-cd-label"><?php echo esc_html( $label ); ?></span>
		<?php endif; ?>
		<span class="as-cd-units">
			<?php foreach ( $units as $i => $u ) : ?>
				<span class="as-cd-unit">
					<b><?php echo esc_html( str_pad( (string) $u[0], 2, '0', STR_PAD_LEFT ) ); ?></b>
					<i><?php echo esc_html( $u[1] ); ?></i>
				</span>
			<?php endforeach; ?>
		</span>
	</div>
	<?php
}
