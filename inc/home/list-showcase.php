<?php
/**
 * 商品列表页 · 橱窗风格（showcase）
 * --------------------------------------------------------------------------
 * 版式换成品牌大屏：大页头 + 分类 chips + 左侧分类/会员侧栏 + 3 列竖版大图卡。
 * 数据仍然是主查询（当前筛选、分页都照旧），只是排版不同 —— **没有加排序、没有加筛选逻辑**。
 *
 * ⚠️ 本文件由各列表模板 include，不单独访问。
 *
 * @package product-showcase-guide
 */

defined( 'ABSPATH' ) || exit;

/*
 * 🔴 搜索页与商品列表页**不是一回事**（v2.87.45 修）：
 *   1. 搜索结果可能命中**文章**（post）—— 不能一律用商品卡渲染，
 *      否则文章会套上商品卡（价格空、链接指向错误的商品永久链接）。
 *   2. 搜索页**不该出现分类 chips 和左侧分类栏** —— 那是分类导航，
 *      点进去是分类页，与当前搜索无关，会把用户带离搜索结果。
 *   3. 计数要说「找到 N 条结果」，不是「共 N 件在售」。
 *   4. 搜索页要有面包屑 + 搜索框（能在结果页改关键词）+ 空结果提示。
 *
 * 下面按 is_search() 分成两套：$as_sc_is_search 决定用哪套模板。
 */
$as_sc_is_search = is_search();

/*
 * 会员身份（v2.87.94）：侧栏那块会员引导对**已登录会员**不该再劝开通
 * —— 改成「你已是会员」。只换文案与按钮文字，DOM 结构（b + p + a）保持不变，
 * 盒子高度不因结构改变而跳动。
 */
$as_sc_is_vip = function_exists( 'as_vip_is_vip' ) ? (bool) as_vip_is_vip() : false;

$as_sc_terms = $as_sc_is_search ? array() : get_terms( array( 'taxonomy' => 'plm_category', 'hide_empty' => true, 'number' => 20 ) );
$as_sc_terms = ( $as_sc_terms && ! is_wp_error( $as_sc_terms ) ) ? $as_sc_terms : array();

/* 标题：归档 / 分类 / 搜索，各取各的 */
if ( $as_sc_is_search ) {
	$as_sc_lt = '搜索：' . get_search_query();
} elseif ( is_tax( 'plm_brand' ) ) {
	$as_sc_lt = single_term_title( '', false );
} elseif ( is_tax() ) {
	$as_sc_lt = single_term_title( '', false );
} else {
	$as_sc_lt = '全部商品';
}
$as_sc_current = ( is_tax() && ! is_wp_error( get_queried_object() ) ) ? get_queried_object() : null;
$as_sc_cur_id  = $as_sc_current && isset( $as_sc_current->term_id ) ? (int) $as_sc_current->term_id : 0;
$as_sc_total   = (int) $GLOBALS['wp_query']->found_posts;

/* 搜索页把结果按类型分成两拨：商品 / 文章（避免文章被套上商品卡） */
$as_sc_prod_ids  = array();
$as_sc_post_ids  = array();
if ( $as_sc_is_search && have_posts() ) {
	$as_sc_gp = $GLOBALS['wp_query'];
	$as_sc_n  = 0;
	foreach ( $as_sc_gp->posts as $as_sc_p ) {
		if ( 'plm_product' === $as_sc_p->post_type ) {
			$as_sc_prod_ids[] = (int) $as_sc_p->ID;
		} else {
			$as_sc_post_ids[] = (int) $as_sc_p->ID;
		}
		$as_sc_n++;
	}
	$as_sc_prod_ids = array_slice( $as_sc_prod_ids, 0, $as_sc_n );
	$as_sc_post_ids = array_slice( $as_sc_post_ids, 0, $as_sc_n );
}
?>
<section class="as-sc-list-head<?php echo $as_sc_is_search ? ' is-search' : ''; ?>">
	<div class="as-container">
		<span class="as-sc-kicker"><?php echo $as_sc_is_search ? 'SEARCH' : 'ALL PRODUCTS'; ?></span>
		<h1><?php echo esc_html( $as_sc_lt ); ?></h1>
		<p>
			<?php if ( $as_sc_is_search ) : ?>
				找到 <b><?php echo (int) $as_sc_total; ?></b> 条与「<?php echo esc_html( get_search_query() ); ?>」相关的结果
				<?php if ( $as_sc_prod_ids ) : ?>
					<span class="as-sc-sep">·</span> 其中 <?php echo count( $as_sc_prod_ids ); ?> 件商品
				<?php endif; ?>
				<?php if ( $as_sc_post_ids ) : ?>
					<span class="as-sc-sep">·</span> <?php echo count( $as_sc_post_ids ); ?> 篇文章
				<?php endif; ?>
			<?php else : ?>
				共 <?php echo (int) $as_sc_total; ?> 件在售 · 会员价全站通用，结算页自动抵扣
			<?php endif; ?>
		</p>

		<?php if ( $as_sc_is_search ) : ?>
			<?php
			/*
			 * 页头**不放搜索框**：只有「没找到结果」时才需要它（那里才要给用户改词的机会）；
			 * 有结果时头部已经有导航栏的搜索框，再放一个就是重复。
			 */
			?>
		<?php elseif ( $as_sc_terms ) : ?>
			<div class="as-sc-chips">
				<a class="as-sc-chip<?php echo $as_sc_cur_id ? '' : ' is-on'; ?>" href="<?php echo esc_url( get_post_type_archive_link( 'plm_product' ) ); ?>">全部</a>
				<?php foreach ( $as_sc_terms as $as_sc_t ) : ?>
					<a class="as-sc-chip<?php echo ( $as_sc_cur_id === (int) $as_sc_t->term_id ) ? ' is-on' : ''; ?>"
						href="<?php echo esc_url( get_term_link( $as_sc_t ) ); ?>"><?php echo esc_html( $as_sc_t->name ); ?></a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
</section>

<section class="as-sc-list-body">
	<div class="as-container as-sc-list-grid<?php echo $as_sc_is_search ? ' is-search' : ''; ?>">
		<?php if ( ! $as_sc_is_search ) : ?>
			<!-- 搜索页**不显示分类侧栏**：那是分类导航，点进去会离开搜索结果 -->
			<aside class="as-sc-side">
				<?php if ( $as_sc_terms ) : ?>
					<div class="as-sc-side-box">
						<h3>商品分类</h3>
						<?php foreach ( $as_sc_terms as $as_sc_t ) : ?>
							<a class="as-sc-side-item<?php echo ( $as_sc_cur_id === (int) $as_sc_t->term_id ) ? ' is-on' : ''; ?>"
								href="<?php echo esc_url( get_term_link( $as_sc_t ) ); ?>">
								<span><?php echo esc_html( $as_sc_t->name ); ?></span>
								<i><?php echo (int) $as_sc_t->count; ?></i>
							</a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php if ( ! function_exists( 'as_has_member_system' ) || as_has_member_system() ) : ?>
				<div class="as-sc-side-box is-vip">
					<?php if ( $as_sc_is_vip ) : ?>
						<?php /* 会员态：文案长度刻意与访客态对齐（都是 3 行左右），避免侧栏高度变化把下方内容顶动 */ ?>
						<b>你已是会员</b>
						<p>全站商品已按会员价显示，结算页自动抵扣，与小程序同价同折。</p>
						<a class="as-sc-btn is-main is-sm" href="<?php echo esc_url( home_url( '/member/' ) ); ?>">我的会员中心</a>
					<?php else : ?>
						<b>会员专属价</b>
						<p>注册即享全站会员价，结算页自动抵扣，与小程序同价同折。</p>
						<a class="as-sc-btn is-main is-sm" href="<?php echo esc_url( home_url( '/member/' ) ); ?>">成为会员</a>
					<?php endif; ?>
				</div>
				<?php endif; ?>
			</aside>
		<?php endif; ?>

		<div class="as-sc-list-main">
			<?php if ( $as_sc_is_search ) : ?>
				<?php if ( ! $as_sc_total ) : ?>
					<!-- 没找到：给搜索框 + 热门商品，别让用户面对空白 -->
					<div class="as-sc-search-empty">
						<p>没有找到与「<?php echo esc_html( get_search_query() ); ?>」相关的内容，换个关键词试试。</p>
						<div class="as-sc-search-box"><?php get_search_form(); ?></div>
						<?php
						$as_sc_hot = new WP_Query( array(
							'post_type'      => 'plm_product',
							'posts_per_page' => 4,
							'orderby'        => 'date',
							'order'          => 'DESC',
							'no_found_rows'  => true,
						) );
						if ( $as_sc_hot->have_posts() ) :
						?>
							<h2 class="as-sc-sub-title">热门商品</h2>
							<div class="as-sc-list-items">
								<?php
								while ( $as_sc_hot->have_posts() ) : $as_sc_hot->the_post();
									as_sc_product_card( get_the_ID(), 'is-list' );
								endwhile;
								wp_reset_postdata();
								?>
							</div>
						<?php endif; ?>
					</div>
				<?php else : ?>

					<?php if ( $as_sc_prod_ids ) : ?>
						<div class="as-sc-list-bar">
							<span>商品（<?php echo count( $as_sc_prod_ids ); ?>）</span>
						</div>
						<div class="as-sc-list-items">
							<?php
							foreach ( $as_sc_prod_ids as $as_sc_pid ) {
								as_sc_product_card( $as_sc_pid, 'is-list' );
							}
							?>
						</div>
					<?php endif; ?>

					<?php if ( $as_sc_post_ids ) : ?>
						<div class="as-sc-list-bar">
							<span>文章（<?php echo count( $as_sc_post_ids ); ?>）</span>
						</div>
						<div class="as-sc-articles">
							<?php
							foreach ( $as_sc_post_ids as $as_sc_bid ) {
								$GLOBALS['post'] = get_post( $as_sc_bid );
								setup_postdata( $GLOBALS['post'] );
								as_post_card();
							}
							wp_reset_postdata();
							?>
						</div>
					<?php endif; ?>

					<div class="as-pagination">
						<?php
						echo paginate_links( array(
							'prev_text' => '‹ 上一页',
							'next_text' => '下一页 ›',
							'mid_size'  => 2,
						) );
						?>
					</div>
				<?php endif; ?>
			<?php else : ?>
				<div class="as-sc-list-bar">
					<span>共 <?php echo (int) $as_sc_total; ?> 件商品</span>
				</div>

				<div class="as-sc-list-items">
					<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
						<?php as_sc_product_card( get_the_ID(), 'is-list' ); ?>
					<?php endwhile; else : ?>
						<p class="as-empty">该分类下暂无产品。</p>
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
			<?php endif; ?>
		</div>
	</div>
</section>
