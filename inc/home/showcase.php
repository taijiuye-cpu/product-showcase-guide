<?php
/**
 * 首页 · 橱窗风格（showcase）
 * --------------------------------------------------------------------------
 * 品牌大屏版式：价值主张优先、一屏一件事、明暗与留白制造节奏。
 * 另一套是 inc/home/shelf.php（货架，默认）。
 *
 * ⚠️ **只换版式，不换逻辑**：本文件取的数据全部来自既有函数
 *    （as_flash_coupon_groups / as_top_sales_ids / as_product_card / as_post_card…），
 *    价格、券、会员价、购买按钮走的还是原来那套逻辑与组件。
 *
 * ⚠️ 本文件由 front-page.php include，不单独访问。
 *
 * @package product-showcase-guide
 */

defined( 'ABSPATH' ) || exit;

/*
 * 小工具：区块头（英文小标 + 中文大标题 + 右侧更多链接）。
 * 两种风格都有这个结构，只是货架版是「图标 + 标题」，橱窗版是「小标 + 大标题」。
 */
if ( ! function_exists( 'as_sc_section_head' ) ) {
	/**
	 * @param string $kicker 英文小标。
	 * @param string $title  中文大标题。
	 * @param string $more   更多链接（空 = 不输出）。
	 * @param string $href   链接地址。
	 * @param bool   $center 是否居中。
	 */
	function as_sc_section_head( $kicker, $title, $more = '', $href = '', $center = false ) {
		?>
		<div class="as-sc-head<?php echo $center ? ' is-center' : ''; ?>">
			<div>
				<span class="as-sc-kicker"><?php echo esc_html( $kicker ); ?></span>
				<h2 class="as-sc-title"><?php echo esc_html( $title ); ?></h2>
			</div>
			<?php if ( $more && $href ) : ?>
				<a class="as-sc-more" href="<?php echo esc_url( $href ); ?>"><?php echo esc_html( $more ); ?> →</a>
			<?php endif; ?>
		</div>
		<?php
	}
}

/* ==========================================================================
 * ① Hero 大屏
 * ======================================================================== */

$as_sc_archive = get_post_type_archive_link( 'plm_product' );
$as_sc_site    = get_bloginfo( 'name' );
$as_sc_desc    = get_bloginfo( 'description' );
$as_sc_desc    = $as_sc_desc ? $as_sc_desc : '延时、增敏、护理、玩具 —— 按需求分类，参数与体验写清楚，不夸大、不刷单。';

// 服务说明标签（后台「网站与小程序共用」配置的那一份）拿来当眉标
$as_sc_tags = function_exists( 'as_mini_opt' ) ? (string) as_mini_opt( 'service_tags' ) : '';
$as_sc_tags = $as_sc_tags ? preg_split( '/[\s、,，]+/u', $as_sc_tags ) : array();
$as_sc_tags = array_slice( array_filter( $as_sc_tags ), 0, 3 );

// 数据带：四项都是现成的真实数据，不编数字
$as_sc_pcount   = wp_count_posts( 'plm_product' );
$as_sc_products = isset( $as_sc_pcount->publish ) ? (int) $as_sc_pcount->publish : 0;
$as_sc_cats     = get_terms( array( 'taxonomy' => 'plm_category', 'hide_empty' => true, 'number' => 20 ) );
$as_sc_cats     = ( $as_sc_cats && ! is_wp_error( $as_sc_cats ) ) ? count( $as_sc_cats ) : 0;
$as_sc_rate     = function_exists( 'as_mini_opt' ) ? (string) as_mini_opt( 'vip_rate' ) : '';
$as_sc_vip      = $as_sc_rate ? ( '0' === $as_sc_rate ? '无折扣' : rtrim( rtrim( $as_sc_rate, '0' ), '.' ) . ' 折' ) : '会员价';

/*
 * 会员身份（v2.87.94）：首页两处「成为会员」CTA 对已登录会员改成「会员中心」
 * —— 已是会员还劝开通是错的。按钮个数、位置、尺寸都不动，只换文字与链接目标。
 */
$as_sc_is_vip = function_exists( 'as_vip_is_vip' ) ? (bool) as_vip_is_vip() : false;
$as_sc_vip_url = home_url( '/member/' );
$as_sc_promise  = function_exists( 'as_service_tags' ) ? as_service_tags() : array();
$as_sc_promise  = array_slice( array_filter( $as_sc_promise ), 0, 4 );

/*
 * 右侧拼贴三张图（v2.87.45 改为动态随机）：
 *
 * 🔴 **首屏就直接输出随机三张**（用户 2026-11-06：「要随机三个」）：
 *   之前首屏用销量前三、等 JS 跑起来才换 → 用户盯着首屏看就是"一直显示前三"，
 *   感觉没生效。现在服务端就直接随机 → 打开页面第一眼就是随机的。
 *
 * 三层保障：
 *   1. 服务端首屏随机（orderby=rand）→ 打开即是随机，无 JS 也一样；
 *   2. 随机池 = 全部在售商品（限 16 个），前端每 3 秒**整组**随机替换三张
 *      （v2.87.46：交叉溶解过渡 + 三张错峰入场，不闪不晃）；
 *   3. 换图时链接 + alt 一起换 → 点图仍跳对应商品。
 */
$as_sc_hero_pool = array();
$as_sc_pool_ids  = get_posts( array(
	'post_type'      => 'plm_product',
	'post_status'    => 'publish',
	'posts_per_page' => 16,
	'orderby'        => 'rand',          // 每次刷新池子都不一样
	'fields'         => 'ids',
	'no_found_rows'  => true,
) );
foreach ( $as_sc_pool_ids as $as_sc_pool_id ) {
	$as_sc_pool_cid = function_exists( 'as_product_cover_id' ) ? as_product_cover_id( $as_sc_pool_id ) : 0;
	if ( ! $as_sc_pool_cid ) {
		continue;
	}
	$as_sc_pool_url = wp_get_attachment_image_url( $as_sc_pool_cid, 'as_product_card' );
	if ( ! $as_sc_pool_url ) {
		continue;
	}
	$as_sc_hero_pool[] = array(
		'id' => (int) $as_sc_pool_id,
		'u'  => $as_sc_pool_url,
		'l'  => get_permalink( $as_sc_pool_id ),
		'a'  => get_the_title( $as_sc_pool_id ),
	);
}

/* 首屏：直接从随机池里取前三个（保证是随机的，而不是销量前三） */
$as_sc_hero_ids = array();
foreach ( array_slice( $as_sc_hero_pool, 0, 3 ) as $as_sc_hp ) {
	$as_sc_hero_ids[] = $as_sc_hp['id'];
}
?>
<section class="as-sc-hero">
	<div class="as-container as-sc-hero-grid">
		<div class="as-sc-hero-copy">
			<?php if ( $as_sc_tags ) : ?>
				<span class="as-sc-eyebrow"><?php echo esc_html( implode( ' · ', $as_sc_tags ) ); ?></span>
			<?php endif; ?>
			<h1><?php echo esc_html( $as_sc_site ); ?></h1>
			<p class="as-sc-hero-desc"><?php echo esc_html( $as_sc_desc ); ?></p>
			<div class="as-sc-btns">
				<a class="as-sc-btn is-main" href="<?php echo esc_url( $as_sc_archive ); ?>">浏览全部商品</a>
				<a class="as-sc-btn" href="<?php echo esc_url( $as_sc_vip_url ); ?>"><?php echo $as_sc_is_vip ? '进入会员中心' : '成为会员享专属价'; ?></a>
			</div>
			<div class="as-sc-stats">
				<div><b><?php echo (int) $as_sc_products; ?></b><i>在售商品</i></div>
				<div><b><?php echo (int) $as_sc_cats; ?></b><i>商品分类</i></div>
				<div><b><?php echo esc_html( $as_sc_vip ); ?></b><i>会员折扣</i></div>
				<div><b><?php echo count( $as_sc_promise ); ?></b><i>服务承诺</i></div>
			</div>
		</div>
		<div class="as-sc-visual<?php echo $as_sc_hero_pool ? ' has-rotator' : ''; ?>"<?php echo $as_sc_hero_pool ? ' data-hero-pool="' . esc_attr( wp_json_encode( $as_sc_hero_pool ) ) . '"' : ''; ?>>
			<?php if ( $as_sc_hero_pool ) : ?>
				<span class="as-sc-visual-tag" aria-hidden="true">随机推荐</span>
			<?php endif; ?>
			<?php foreach ( $as_sc_hero_ids as $as_sc_i => $as_sc_pid ) :
				$as_sc_cid = function_exists( 'as_product_cover_id' ) ? as_product_cover_id( $as_sc_pid ) : 0;
				if ( ! $as_sc_cid ) {
					continue;
				}
				?>
				<a class="as-sc-photo as-sc-photo-<?php echo (int) ( $as_sc_i + 1 ); ?>" href="<?php echo esc_url( get_permalink( $as_sc_pid ) ); ?>">
					<?php echo wp_get_attachment_image( $as_sc_cid, 'as_product_card', false, array( 'alt' => get_the_title( $as_sc_pid ) ) ); ?>
				</a>
			<?php endforeach; ?>
		</div>
	</div>
</section>

<?php
/* ==========================================================================
 * ② 热门文章（榜单）
 * --------------------------------------------------------------------------
 * 放在 Hero 正下方（用户 2026-11-07：「放在 hero 底下，毕竟是导购博客」）。
 *
 * 形态选「榜单」而不是再来一套图文卡：
 *   · 最新文章那块已经是图文网格，两块都用卡片会像重复了一遍；
 *   · 榜单（大号序号 + 紧凑条目）是内容站的标准语言，一眼就知道是"大家在看"；
 *   · 窄屏天然适合纵向列表 —— 手机端横向滑动的卡片看不到第 2 项标题。
 *
 * 排序：后台勾选的优先，其余按阅读量兜底（见 as_home_hot_articles）。
 * ======================================================================== */

$as_sc_hot = function_exists( 'as_home_hot_articles' ) ? as_home_hot_articles( 5 ) : array();
if ( $as_sc_hot ) :
	// 第一名单独做大卡，其余 4 条排 2×2
	$as_sc_hot_lead = array_shift( $as_sc_hot );
	?>
	<section class="as-sc-sec as-sc-alt as-sc-hot">
		<div class="as-container">
			<?php
			as_sc_section_head( 'HOT ARTICLES', '热门文章', '更多文章', as_blog_url() );
			?>
			<div class="as-sc-hot-grid">

				<?php
				/* ---------- TOP 1：大卡 ---------- */
				$as_sc_h1_cats = get_the_category( $as_sc_hot_lead->ID );
				$as_sc_h1_cat  = $as_sc_h1_cats ? $as_sc_h1_cats[0]->name : '';
				// v2.87.56：**不再输出摘要**（用户 2026-11-07：手机端显示不协调）
				// → 手机端大卡从「宽图 + 标题 + 摘要」压成「方图 + 标题」，高度直接砍一半
				$as_sc_h1_views = function_exists( 'as_post_views' ) ? (int) as_post_views( $as_sc_hot_lead->ID ) : 0;
				?>
				<a class="as-sc-hot-lead" href="<?php echo esc_url( get_permalink( $as_sc_hot_lead->ID ) ); ?>">
					<span class="as-sc-hot-lead-photo">
						<?php echo as_post_thumb_html( $as_sc_hot_lead->ID, 'as_blog_thumb', 720, 400 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<b class="as-sc-hot-badge is-lead">1</b>
					</span>
					<span class="as-sc-hot-lead-body">
						<?php if ( $as_sc_h1_cat ) : ?>
							<span class="as-sc-hot-cat"><?php echo esc_html( $as_sc_h1_cat ); ?></span>
						<?php endif; ?>
						<h3 class="as-sc-hot-lead-title"><?php echo esc_html( get_the_title( $as_sc_hot_lead->ID ) ); ?></h3>
						<span class="as-sc-hot-meta">
							<em><?php echo esc_html( get_the_date( '', $as_sc_hot_lead->ID ) ); ?></em>
							<?php if ( $as_sc_h1_views > 0 ) : ?>
								<i><?php echo esc_html( number_format_i18n( $as_sc_h1_views ) ); ?> 阅读</i>
							<?php endif; ?>
						</span>
					</span>
				</a>

				<?php if ( $as_sc_hot ) : ?>
					<div class="as-sc-hot-rest">
						<?php
						foreach ( $as_sc_hot as $as_sc_hi => $as_sc_hp ) :
							$as_sc_n     = $as_sc_hi + 2;
							$as_sc_hc    = get_the_category( $as_sc_hp->ID );
							$as_sc_hcat  = $as_sc_hc ? $as_sc_hc[0]->name : '';
							$as_sc_hview = function_exists( 'as_post_views' ) ? (int) as_post_views( $as_sc_hp->ID ) : 0;
							?>
							<a class="as-sc-hot-item" href="<?php echo esc_url( get_permalink( $as_sc_hp->ID ) ); ?>">
								<span class="as-sc-hot-thumb">
									<?php echo as_post_thumb_html( $as_sc_hp->ID, 'as_blog_thumb', 720, 400 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<b class="as-sc-hot-badge<?php echo $as_sc_n <= 3 ? '' : ' is-plain'; ?>"><?php echo (int) $as_sc_n; ?></b>
								</span>
								<span class="as-sc-hot-txt">
									<span class="as-sc-hot-title"><?php echo esc_html( get_the_title( $as_sc_hp->ID ) ); ?></span>
									<span class="as-sc-hot-meta">
										<?php if ( $as_sc_hcat ) : ?>
											<em><?php echo esc_html( $as_sc_hcat ); ?></em>
										<?php endif; ?>
										<?php if ( $as_sc_hview > 0 ) : ?>
											<i><?php echo esc_html( number_format_i18n( $as_sc_hview ) ); ?> 阅读</i>
										<?php endif; ?>
									</span>
								</span>
							</a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

			</div>
		</div>
	</section>
	<?php
endif;

?>

<?php
/* ==========================================================================
 * ③ 分类带
 * ======================================================================== */

$as_sc_terms = get_terms( array( 'taxonomy' => 'plm_category', 'hide_empty' => true, 'number' => 5 ) );
if ( $as_sc_terms && ! is_wp_error( $as_sc_terms ) ) :
	?>
	<section class="as-sc-sec as-sc-cats">
		<div class="as-container">
			<?php
			as_sc_section_head(
				'SHOP BY CATEGORY',
				'按需求挑，比按品牌挑更快',
				'全部分类',
				$as_sc_archive
			);
			?>
			<div class="as-sc-cats-row">
				<?php
				foreach ( $as_sc_terms as $as_sc_term ) :
					/*
					 * 分类图 = 该分类下第一款商品的封面（特色图像 → 图集第一张）。
					 * 没有商品就没有图，这里就退化成纯色块，不显示空图。
					 */
					$as_sc_first = get_posts( array(
						'post_type'      => 'plm_product',
						'post_status'    => 'publish',
						'posts_per_page' => 1,
						'orderby'        => 'menu_order date',
						'order'          => 'ASC',
						'tax_query'      => array( array(
							'taxonomy' => 'plm_category',
							'field'    => 'term_id',
							'terms'    => $as_sc_term->term_id,
						) ),
						'no_found_rows'  => true,
					) );
					$as_sc_pid = $as_sc_first ? $as_sc_first[0]->ID : 0;
					$as_sc_cid = $as_sc_pid && function_exists( 'as_product_cover_id' ) ? as_product_cover_id( $as_sc_pid ) : 0;
					$as_sc_url = get_term_link( $as_sc_term );
					$as_sc_cnt = $as_sc_term->count;
					?>
					<a class="as-sc-cat" href="<?php echo esc_url( $as_sc_url ); ?>">
						<?php if ( $as_sc_cid ) : ?>
							<span class="as-sc-cat-photo" style="background-image:url('<?php echo esc_url( wp_get_attachment_image_url( $as_sc_cid, 'as_product_card' ) ); ?>');"></span>
						<?php endif; ?>
						<span class="as-sc-cat-mask"></span>
						<span class="as-sc-cat-info">
							<b><?php echo esc_html( $as_sc_term->name ); ?></b>
							<i><?php echo (int) $as_sc_cnt; ?> 款商品</i>
						</span>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
	</section>
	<?php
endif;

/* ==========================================================================
 * ④ 限时优惠（深色促销带）—— 与货架版同一份券分组数据
 * ======================================================================== */

$as_sc_groups = function_exists( 'as_flash_coupon_groups' ) ? as_flash_coupon_groups( 4 ) : array();
if ( $as_sc_groups ) :
	?>
	<section class="as-sc-sec as-sc-flash">
		<div class="as-container as-sc-flash-grid">
			<div class="as-sc-flash-copy">
				<span class="as-sc-kicker is-light">LIMITED DEAL</span>
				<?php
				// 多个模块时，标题取第一个模块的活动名；只有一个就用它自己的
				$as_sc_first_g = $as_sc_groups[0];
				$as_sc_gname    = ! empty( $as_sc_first_g['name'] ) ? $as_sc_first_g['name'] : '限时特惠';
				?>
				<?php
				/*
				 * 活动名称与倒计时**同行**：
				 *   · 桌面：名称 + 倒计时一行排开；
				 *   · 手机：宽度不够时倒计时自动换到下一行，仍然是「同行结构」，
				 *     但不会因为硬 nowrap 把标题挤到溢出。
				 */
				?>
				<div class="as-sc-flash-head">
					<h2 class="as-sc-flash-title"><?php echo esc_html( $as_sc_gname ); ?></h2>
					<?php
					// 基础版不含优惠券，因此这里不展示券胶囊；
					// 倒计时由后台「限时活动结束时间」配置驱动。
					$as_sc_cd = as_flash_countdown();
					if ( ! empty( $as_sc_cd['end'] ) && ! empty( $as_sc_cd['text'] ) ) :
						?>
						<span class="as-sc-flash-coupons">
							<span class="as-sc-flash-coupon"><?php echo esc_html( $as_sc_cd['text'] ); ?></span>
						</span>
					<?php endif; ?>
				</div>
				<p class="as-sc-flash-desc">精选商品限时特惠，数量有限售完即止。</p>
				<a class="as-sc-btn is-light" href="<?php echo esc_url( home_url( '/sale/' ) ); ?>">查看全部特惠</a>
			</div>
			<div class="as-sc-flash-goods">
				<?php
				foreach ( $as_sc_groups as $as_sc_g ) {
					foreach ( array_slice( $as_sc_g['ids'], 0, 4 ) as $as_sc_pid ) {
						echo '<div class="as-sc-mini">';
						as_sc_product_card( $as_sc_pid );
						echo '</div>';
					}
				}
				?>
			</div>
		</div>
	</section>
	<?php
endif;

/* ==========================================================================
 * ⑤ 热门推荐（v2.87.45 改名 + 修数量）
 * -----------------------------------------------------------------------
 * 用户 2026-11-06：这个区块取的就是后台「小程序商城 → 商城功能设置 →
 * 首页热门推荐」勾选的商品（dg_hot=1），那标题就该叫**热门推荐**，
 * 而不是「本周精选」（叫精选会让人以为是按时间挑的）。
 *
 * 🔴 数量原来写死 6 → 后台勾了 8 个，前端只显示 6 个，**白勾 2 个**。
 *    现在跟货架风格（inc/home/shelf.php）统一取 8 个，后台勾几个就显示几个（最多 8）。
 *    一个都没勾时回落到「最新上架」，避免编辑还没维护就出现空区块。
 * ======================================================================== */

$as_sc_pick = new WP_Query( array(
	'post_type'      => 'plm_product',
	'post_status'    => 'publish',
	'posts_per_page' => 8,
	'meta_key'       => 'dg_hot',
	'meta_value'     => '1',
	'no_found_rows'  => true,
) );
if ( ! $as_sc_pick->have_posts() ) {
	$as_sc_pick = new WP_Query( array(
		'post_type'      => 'plm_product',
		'post_status'    => 'publish',
		'posts_per_page' => 8,
		'no_found_rows'  => true,
	) );
}
$as_sc_hot_ids = array();
if ( $as_sc_pick->have_posts() ) :
	$as_sc_pick_n = count( $as_sc_pick->posts );
	foreach ( $as_sc_pick->posts as $as_sc_hp ) {
		$as_sc_hot_ids[] = (int) $as_sc_hp->ID;
	}
	?>
	<section class="as-sc-sec">
		<div class="as-container">
			<?php
			as_sc_section_head( 'POPULAR', '热门推荐', '更多', $as_sc_archive );
			?>
			<div class="as-sc-grid3">
				<?php
				while ( $as_sc_pick->have_posts() ) :
					$as_sc_pick->the_post();
					as_sc_product_card( get_the_ID() );
				endwhile;
				wp_reset_postdata();
				?>
			</div>
			<?php if ( $as_sc_pick_n > 4 ) : ?>
				<!-- 勾选数不是 4 的倍数时给个「查看全部」入口，别让最后一行孤零零 -->
				<div class="as-sc-more-row">
					<a class="as-sc-btn is-main" href="<?php echo esc_url( $as_sc_archive ); ?>">查看全部商品</a>
				</div>
			<?php endif; ?>
		</div>
	</section>
	<?php
endif;

/* ==========================================================================
 * ⑥ 销量排行
 * ======================================================================== */

$as_sc_rank_ids = function_exists( 'as_top_sales_ids' ) ? as_top_sales_ids( 4 ) : array();
if ( $as_sc_rank_ids ) :
	?>
	<section class="as-sc-sec as-sc-alt">
		<div class="as-container">
			<?php
			as_sc_section_head( 'BEST SELLERS', '销量排行', '查看榜单', $as_sc_archive );
			?>
			<div class="as-sc-grid4 as-sc-rank">
				<?php
				foreach ( $as_sc_rank_ids as $as_sc_i => $as_sc_pid ) :
					?>
					<div class="as-sc-rank-item">
						<span class="as-sc-rank-num"><?php echo (int) ( $as_sc_i + 1 ); ?></span>
						<?php as_sc_product_card( $as_sc_pid, 'is-rank' ); ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	</section>
	<?php
endif;

/* ==========================================================================
 * ⑦ 最新上架
 * ======================================================================== */

$as_sc_new = new WP_Query( array(
	'post_type'      => 'plm_product',
	'post_status'    => 'publish',
	'posts_per_page' => 4,
	// 排除已出现在「热门推荐」里的商品 —— 两区不重复（与货架风格口径一致）
	'post__not_in'   => $as_sc_hot_ids,
	'no_found_rows'  => true,
) );
if ( $as_sc_new->have_posts() ) :
	?>
	<section class="as-sc-sec">
		<div class="as-container">
			<?php
			as_sc_section_head( 'NEW ARRIVALS', '最新上架', '查看更多', $as_sc_archive );
			?>
			<div class="as-sc-grid4">
				<?php
				while ( $as_sc_new->have_posts() ) :
					$as_sc_new->the_post();
					as_sc_product_card( get_the_ID() );
				endwhile;
				wp_reset_postdata();
				?>
			</div>
		</div>
	</section>
	<?php
endif;

/* ==========================================================================
 * ⑧ 为什么选择我们（服务承诺）
 * ======================================================================== */

if ( $as_sc_promise ) :
	?>
	<section class="as-sc-sec as-sc-promise">
		<div class="as-container">
			<?php
			as_sc_section_head( 'WHY US', '为什么选择我们', '', '', true );
			?>
			<div class="as-sc-promise-grid">
				<?php foreach ( $as_sc_promise as $as_sc_p ) : ?>
					<div class="as-sc-promise-item">
						<span class="as-sc-promise-ic">✦</span>
						<b><?php echo esc_html( $as_sc_p ); ?></b>
					</div>
				<?php endforeach; ?>
			</div>
			<p class="as-sc-promise-note">以上承诺来自后台「服务说明」配置，全站所有商品通用。</p>
		</div>
	</section>
	<?php
endif;

/* ==========================================================================
 * ⑨ 最新文章
 * ======================================================================== */

$as_sc_blog = function_exists( 'as_home_articles' ) ? as_home_articles() : array();
if ( $as_sc_blog ) :
	?>
	<section class="as-sc-sec as-sc-alt">
		<div class="as-container">
			<?php
			as_sc_section_head( 'LATEST', '最新文章', '更多文章', as_blog_url() );
			?>
			<div class="as-sc-articles">
				<?php
				foreach ( $as_sc_blog as $as_sc_bp ) :
					/* ⚠️ 同货架版：setup_postdata() 不改全局 $post，必须手动给 $GLOBALS['post'] 赋值，
					   否则 as_post_card() 的 the_title()/the_permalink() 全指向同一篇。 */
					$GLOBALS['post'] = $as_sc_bp;
					setup_postdata( $as_sc_bp );
					as_post_card();
				endforeach;
				wp_reset_postdata();
				?>
			</div>
		</div>
	</section>
	<?php
endif;

/* ==========================================================================
 * ⑩ 结尾 CTA
 * ======================================================================== */
?>
<section class="as-sc-cta">
	<div class="as-container">
		<h2>不知道选哪个？先看指南</h2>
		<p>把「第一次买该买什么」「怎么挑不踩雷」写清楚，比什么推荐位都管用。</p>
		<div class="as-sc-btns is-center">
			<a class="as-sc-btn is-main" href="<?php echo esc_url( $as_sc_archive ); ?>">浏览全部商品</a>
			<a class="as-sc-btn" href="<?php echo esc_url( $as_sc_vip_url ); ?>"><?php echo $as_sc_is_vip ? '会员中心' : '成为会员'; ?></a>
		</div>
	</div>
</section>
