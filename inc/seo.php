<?php
/**
 * SEO 优化 —— 网站前端的 meta 输出与后台设置。
 *
 * 设计原则（重要，改之前先读）：
 *
 * 1. 本文件**接管**了原先散落在 functions.php 里的 SEO 逻辑
 *    （as_get_meta_description / as_og_image / as_seo_meta / as_robots /
 *    as_product_schema），函数名与行为保持不变，所以小程序接口、
 *    页面模板、既有设置项都零影响。迁移是为了让 functions.php 不再臃肿。
 *
 * 2. **尊重第三方 SEO 插件**。装了 Yoast / Rank Math / AIOSEO / SEOPress / TSF 时，
 *    本主题的 meta 输出整体让路（沿用 as_seo_plugin_active() 的既有约定），
 *    避免两套 SEO 插件互相输出重复标签。
 *
 * 3. 装插件的用户依然能在「外观 → 自定义 → SEO 优化」里改三要素，只是不影响前台输出。
 *    （不让后台表单消失，避免用户以为设置丢了。）
 *
 * 4. **keywords 故意给得很克制**。主流搜索引擎（Google 已于2009 年弃用、
 *    百度 2019 年起不再weighs it）基本不用它，但它几乎零成本，
 *    且对国内长尾词仍可能被当作极弱的信号，所以保留但默认留空、默认不输出。
 *
 * 5. 所有输出都走 esc_attr() / esc_url()，设置项都有 sanitize 回调。
 *    后台存的原始值不会被直接吐到前台。
 *
 * @package product-showcase-guide
 */

defined( 'ABSPATH' ) || exit;

/* ==========================================================================
 * 1. 插件让路判断
 * ======================================================================== */

/**
 * 是否装了第三方 SEO 插件。
 *
 * 被本文件多处复用：让路 meta 输出，同时后台设置页也读它来提示用户。
 *
 * @return bool
 */
if ( ! function_exists( 'as_seo_plugin_active' ) ) {
	function as_seo_plugin_active() {
		return defined( 'WPSEO_VERSION' )              // Yoast SEO
			|| defined( 'RANK_MATH_VERSION' )          // Rank Math
			|| defined( 'AIOSEO_VERSION' )             // All in One SEO
			|| defined( 'SEOPRESS_VERSION' )           // SEOPress
			|| defined( 'THE_SEO_FRAMEWORK_VERSION' ); // The SEO Framework
	}
}

/* ==========================================================================
 * 2. 三要素的取值与拼装
 * ======================================================================== */

/**
 * 清洗关键词串：统一分隔符 → 去空 → 去重 → 限 10 个。
 *
 * @param string $raw 原始输入（中文逗号/顿号/分号/竖线都认）。
 * @return string 逗号分隔的关键词；没内容时返回空串。
 */
function as_seo_clean_keywords( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return '';
	}

	// 统一中英文逗号/顿号/分号 → 半角逗号，再去首尾空白
	$raw = str_replace( array( '，', '、', '；', ';', '|' ), ',', $raw );

	$parts = array_filter( array_map( 'trim', explode( ',', $raw ) ), 'strlen' );
	$parts = array_values( array_unique( $parts ) );

	return implode( ',', array_slice( $parts, 0, 10 ) );
}

/**
 * 取后台设置的「站点 SEO 关键词」。
 *
 * 会清洗掉多余空白、去掉结尾的连续逗号，避免输出成 "a,b,," 这种脏串。
 *
 * @return string 逗号分隔的关键词；没设置时返回空串。
 */
function as_seo_get_keywords() {
	return as_seo_clean_keywords( get_theme_mod( 'as_seo_keywords', '' ) );
}

/**
 * 后台填的「首页 SEO 关键词」（只作用于首页）。
 *
 * @return string
 */
function as_seo_home_keywords() {
	return as_seo_clean_keywords( get_theme_mod( 'as_home_keywords', '' ) );
}

/**
 * 首页 <title>。
 *
 * 优先用后台「首页 SEO 标题」；没填则用「站点名 - 站点副标题」。
 *
 * ⚠️ 为什么不用 WP 默认那套：默认会把站点名和副标题**拼起来**再加分隔符，
 *    得到的是「大人好物 – 成人情趣用品选购指南」—— 标题里出现两次品牌/关键词，
 *    搜索结果里可读性也差。这里让用户自己写一个纯粹的标题。
 *
 * @return string
 */
function as_seo_home_title() {
	$name = get_bloginfo( 'name' );
	$custom = trim( (string) get_theme_mod( 'as_home_title', '' ) );
	if ( '' !== $custom ) {
		/*
		 * 用户自己写的标题若已经带了站名就不再重复追加。
		 * 用 strpos（字节级比较）—— 中文按字节也是完整匹配，
		 * 不需要 mbstring（服务器没装，见项目记忆）。
		 */
		$custom = as_seo_decode_separator( $custom );
		if ( '' !== $name && false === strpos( $custom, $name ) ) {
			$custom .= ' – ' . $name;
		}
		return $custom;
	}

	$tagline = trim( (string) get_bloginfo( 'description' ) );

	/*
	 * v2.87.125：分隔符用**原字符**「–」而不是 ASCII「 - 」。
	 * WP 核心的 wptexturize/convert_chars 挂在 document_title 上，会把 ASCII
	 * 「 - 」规范化成实体「 &#8211; 」，源码里就出现 & 开头（其它页面不会）。
	 */
	return $tagline ? $name . ' – ' . $tagline : $name;
}

/**
 * 文章列表页（/blog/）的 <title>。
 *
 * WP 默认给的是「博客 – 站点名」——「博客」两个字没有任何搜索价值，
 * 这里换成站点副标题（本来就是一句面向访客的自我介绍）。
 *
 * @return string
 */
function as_seo_blog_title() {
	$name = get_bloginfo( 'name' );

	/*
	 * v2.87.124：原来这里用的是**站点副标题**，而副标题同时也是其它虚拟页
	 * （/member、/cart…）的默认标题 → 这几个页面的 <title> 完全一样，
	 * 搜索引擎会当成重复页面。改成一篇有搜索价值的页面主题。
	 */
	return '延时喷剂选购指南：怎么挑、怎么用、怎么保养 – ' . $name;
}

/**
 * 「虚拟页」SEO 映射表（query var 渲染出来的页面）。
 *
 * 购物车 / 结算 / 订单 / 会员中心这四类页面 WP 完全不认识，
 * document_title 与 description 都会落回站点默认值 → 六个页面标题一模一样。
 * 这里逐个给独立标题与描述；交易类页面再加 noindex（对排名没价值，
 * 收录只是重复内容）。
 */
function as_seo_virtual_pages() {
	return array(
		'as_cart_page'     => array(
			'title'   => '购物车',
			'desc'    => '查看已加入购物车的商品与结算价格，可随时调整数量或删除。',
			'noindex' => true,
		),
		'as_checkout_page' => array(
			'title'   => '结算',
			'desc'    => '确认收货信息与支付方式，完成下单。',
			'noindex' => true,
		),
		'as_order_page'    => array(
			'title'   => '下单成功',
			'desc'    => '订单已提交，可查看订单状态与物流进度。',
			'noindex' => true,
		),
		'as_orders_page'   => array(
			'title'   => '我的订单',
			'desc'    => '查看历史订单、物流进度与售后记录。',
			'noindex' => true,
		),
		'as_member_page'   => array(
			'title'   => '会员中心',
			'desc'    => '登录后查看会员价、专属优惠券、积分与订单。',
			'noindex' => true,
		),
	);
}

/**
 * 当前请求命中了哪个虚拟页（没有则 null）。
 *
 * @return array|null
 */
function as_seo_current_virtual_page() {
	foreach ( as_seo_virtual_pages() as $var => $cfg ) {
		if ( get_query_var( $var ) ) {
			return $cfg;
		}
	}
	return null;
}

/**
 * 单页/文章/商品的描述兜底（正文里没有可用文字时）。
 *
 * 商品详情页的正文常常只有图片（京东详情长图），摘不出文字 → 之前
 * meta description 直接输出空值。改成用标题 + 品牌 + 品类 + 价格拼一句。
 */
function as_seo_singular_fallback( $post ) {
	$name  = get_the_title( $post );
	$terms = array();
	foreach ( array( 'plm_brand', 'plm_category' ) as $tax ) {
		$ts = get_the_terms( $post, $tax );
		if ( is_array( $ts ) ) {
			foreach ( $ts as $t ) {
				if ( $t instanceof WP_Term ) {
					$terms[] = $t->name;
				}
			}
		}
	}
	$bits = array();
	if ( $terms ) {
		$bits[] = implode( ' ', $terms );
	}
	$price = get_post_meta( $post->ID, 'dg_price', true );
	if ( $price ) {
		$bits[] = '现价 ' . $price . ' 元';
	}
	$suffix = $bits ? ( '：' . implode( '，', $bits ) . '。价格、优惠券与选购建议。' ) : '：价格、优惠与选购建议。';

	return $name . $suffix;
}

/**
 * 页面级keywords：按页面类型自动拼，不用手填。
 *
 * 规则（从「具体」到「宽泛」）：
 *   - 首页：后台填的「首页 SEO 关键词」（没填才退回全站兜底）
 *   - 商品页：分类 + 品牌（都是真实存在的词，比全局关键词更贴页面）
 *   - 文章页：分类 + 标签
 *   - 分类/品牌页：分类名 + 全局关键词
 *   - 其它：全局关键词
 *
 * 全局关键词始终排在最后（权重最低），所以用户只填一项也能覆盖全站。
 *
 * @return string
 */
function as_seo_page_keywords() {
	global $post;

	$base = as_seo_get_keywords();
	$list = array();

	// 首页：只用后台填的首页关键词，不与全站兜底混着来
	if ( is_front_page() ) {
		$home = as_seo_home_keywords();
		return '' !== $home ? $home : $base;
	}

	if ( is_singular() ) {
		$obj = get_queried_object();
		if ( $obj instanceof WP_Post ) {
			// 商品页：分类 + 品牌 + 标题
			if ( 'plm_product' === $obj->post_type ) {
				foreach ( array( 'plm_category', 'plm_brand' ) as $tax ) {
					$terms = get_the_terms( $obj, $tax );
					if ( is_array( $terms ) ) {
						foreach ( $terms as $t ) {
							if ( ! $t instanceof WP_Term ) {
								continue;
							}
							$list[] = $t->name;
							// 商品标题本身做关键词没意义（过长），只取分类和品牌
						}
					}
				}
			} else {
				// 文章页：分类 + 标签
				foreach ( array( 'category', 'post_tag' ) as $tax ) {
					$terms = get_the_terms( $obj, $tax );
					if ( is_array( $terms ) ) {
						foreach ( $terms as $t ) {
							if ( $t instanceof WP_Term ) {
								$list[] = $t->name;
							}
						}
					}
				}
			}
		}
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();
		if ( $term && $term instanceof WP_Term ) {
			$list[] = $term->name;
		}
	}

	if ( $base ) {
		$list[] = $base;
	}

	// 去重（保持顺序）+ 过滤空值 + 限制总数（超过 10 个反而像堆砌）
	$list = array_values( array_unique( array_filter( array_map( 'trim', $list ), 'strlen' ) ) );

	return implode( ',', array_slice( $list, 0, 10 ) );
}

/**
 * 把标题里的 HTML 实体分隔符还原成原字符。
 *
 * 背景：WordPress 核心给 `document_title` 挂了 wptexturize / convert_chars，
 * 会把标题里的 ASCII「 - 」规范化成实体「 &#8211; 」（见 WP 核心
 * wp-includes/default-filters.php 里对 document_title 的两处 add_filter）。
 * 而 WP 自己拼标题时用的是 document_title_separator（主题已改成原字符「–」），
 * 所以**同一个站里两种页面的分隔符不一样** —— 首页/博客页是实体、其它页是原字符。
 * 这里统一成原字符：源码干净，搜索引擎读到的也是同一串字符。
 *
 * @param string $text
 * @return string
 */
function as_seo_decode_separator( $text ) {
	return str_replace(
		array( '&#8211;', '&#8212;', '&ndash;', '&mdash;', '&#x2013;', '&#x2014;' ),
		array( '–', '–', '–', '–', '–', '–' ),
		(string) $text
	);
}

/**
 * 取 meta description。
 *
 * 优先级（与 v2.49.0 行为完全一致，未改动）：
 *   1. 首页 → 后台「首页 SEO 描述」
 *   2. 文章页 → 摘要，没有摘要则取正文前 40 词
 *   3. 分类/品牌页 → 分类描述，没有则「XX 相关产品的价格…」
 *   4. 归档/搜索 → 固定文案或搜索词
 *   5. 兜底：全局 SEO 描述 → 站点副标题 → 站名
 *
 * 最后补的「全局兜底」是本次新增：以前所有页面都算不出描述时，
 * 整张meta description 会直接不输出（等于没有），对 SEO 不利。
 *
 * @return string
 */
function as_get_meta_description() {
	// 「全部优惠」页（/sale/，v2.86.54）：query var 页，WP 默认逻辑不认识它
	if ( get_query_var( 'as_sale_page' ) ) {
		return '正在进行中的限时优惠商品汇总：每款商品标注适用优惠券与结束倒计时，含会员价，错过即止。';
	}
	// 购物车 / 结算 / 订单 / 会员中心（v2.87.124）：逐页独立描述
	$virtual = as_seo_current_virtual_page();
	if ( $virtual ) {
		return $virtual['desc'];
	}
	$custom_home = trim( (string) get_theme_mod( 'as_home_description', '' ) );
	$global_desc  = trim( (string) get_theme_mod( 'as_seo_description', '' ) );

	// 首页必须最先判断：静态首页同时满足 is_singular()，
	// 若把 is_singular() 放前面，首页会走单页分支返回空值，首页描述永远出不来。
	if ( is_front_page() ) {
		if ( '' !== $custom_home ) {
			return $custom_home;
		}
		if ( '' !== $global_desc ) {
			return $global_desc;
		}

		$name    = get_bloginfo( 'name' );
		$tagline = trim( (string) get_bloginfo( 'description' ) );

		return $tagline ? $name . '：' . $tagline : $name;
	}

	if ( is_home() ) {
		/*
		 * 文章列表页（/blog/）。
		 * ⚠️ v2.87.52：这里**不再用首页描述**（as_home_description）。
		 * ⚠️ v2.87.124：也**不再用全局 SEO 描述** —— 全局描述是首页在用，
		 *    博客页再用一遍就成了重复内容（审计发现 /blog 与首页描述相同）。
		 *    博客页固定用自己的这段描述。
		 */
		$paged = max( (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
		$base  = '选购指南、使用知识与保养建议：延时喷剂怎么挑、增敏护理怎么做，全部写成能照着做的实操文章。';
		if ( $paged > 1 ) {
			$base .= '（第 ' . $paged . ' 页）';
		}
		return $base;
	}

	if ( is_404() ) {
		return '你访问的页面不存在或已被移动。可以搜索本站的商品与选购指南，或从首页重新进入。';
	}

	if ( is_singular() ) {
		$post = get_queried_object();
		if ( $post instanceof WP_Post ) {
			$raw = $post->post_excerpt ? $post->post_excerpt : $post->post_content;
			$raw = wp_strip_all_tags( strip_shortcodes( $raw ) );
			$raw = trim( preg_replace( '/\s+/u', ' ', $raw ) );
			if ( $raw ) {
				return wp_trim_words( $raw, 40, '' );
			}
			// 正文没有文字（如只有详情长图的商品页）：用标题 + 品牌品类价格兜底
			$fallback = as_seo_singular_fallback( $post );
			if ( $fallback ) {
				return $fallback;
			}
		}
		// 算不出就退回全局描述，而不是留空
		if ( '' !== $global_desc ) {
			return $global_desc;
		}
		$name = get_bloginfo( 'name' );
		$tag  = trim( (string) get_bloginfo( 'description' ) );
		return $tag ? $name . '：' . $tag : $name;
	}

	if ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();
		if ( $term && ! is_wp_error( $term ) ) {
			if ( ! empty( $term->description ) ) {
				return wp_trim_words( wp_strip_all_tags( $term->description ), 40, '' );
			}
			return sprintf( '%s 相关产品的价格、优惠与选购建议。', $term->name );
		}
		return $global_desc;
	}

	if ( is_post_type_archive( 'plm_product' ) ) {
		return '浏览全部产品，按品类与品牌筛选，查看价格、优惠券与购买链接。';
	}
	if ( is_search() ) {
		return sprintf( '“%s” 的搜索结果。', get_search_query() );
	}

	// 兜底：此前这里返回 ''，导致很多页面完全没有 description
	return $global_desc;
}

/**
 * og:image 取图。
 *
 * wp_head 阶段主循环还没开始，必须显式传 ID（不能依赖全局 $post）。
 *
 * @return string 图片 URL，取不到时返回空串。
 */
function as_og_image() {
	$info = as_og_image_info();
	return $info['url'];
}

/**
 * og:image 的完整信息（URL / 宽 / 高 / alt）。
 *
 * 为什么要带宽高：微信、Facebook、微博抓 og:image 时会先读 og:image:width/height
 * 来预留版面，缺了就可能按原图尺寸渲染出变形的大图。
 *
 * @return array array( 'url', 'width', 'height', 'alt' )；取不到图时 url 为空串。
 */
function as_og_image_info() {
	$name = get_bloginfo( 'name' );

	// 1. 详情页用特色图
	if ( is_singular() ) {
		$post_id = get_queried_object_id();
		if ( $post_id && has_post_thumbnail( $post_id ) ) {
			$att_id = get_post_thumbnail_id( $post_id );
			$img    = get_the_post_thumbnail_url( $post_id, 'large' );
			if ( $img ) {
				$alt = trim( (string) get_post_meta( $att_id, '_wp_attachment_image_alt', true ) );
				if ( '' === $alt ) {
					$alt = get_the_title( $post_id );
				}
				$meta = wp_get_attachment_metadata( $att_id );
				return array(
					'url'    => $img,
					'width'  => ( is_array( $meta ) && ! empty( $meta['width'] ) ) ? (int) $meta['width'] : 0,
					'height' => ( is_array( $meta ) && ! empty( $meta['height'] ) ) ? (int) $meta['height'] : 0,
					'alt'    => $alt,
				);
			}
		}

		/*
		 * 文章没设特色图时，退回**正文第一张图**。
		 *
		 * ⚠️ 为什么要加这一层：本站在线的文章大多没设特色图（前台缩略图是
		 *    as_post_thumb_html() 从正文里取的），og:image 跟着一起没有 →
		 *    分享到微信/微博时就是一张没有封面的纯文字卡片。
		 *    这里跟前台取图口径保持一致，分享图就有了。
		 */
		if ( is_singular( 'post' ) && function_exists( 'as_article_first_image' ) ) {
			$first = as_article_first_image( $post_id );
			if ( $first ) {
				$meta = array();
				if ( is_numeric( $first ) ) {
					$img  = wp_get_attachment_image_url( (int) $first, 'large' );
					$meta = wp_get_attachment_metadata( (int) $first );
				} else {
					$img = (string) $first;   // 外链图，原样用
				}
				if ( $img ) {
					return array(
						'url'    => $img,
						'width'  => ( is_array( $meta ) && ! empty( $meta['width'] ) ) ? (int) $meta['width'] : 0,
						'height' => ( is_array( $meta ) && ! empty( $meta['height'] ) ) ? (int) $meta['height'] : 0,
						'alt'    => get_the_title( $post_id ),
					);
				}
			}
		}
	}

	// 2. 后台可指定分享图
	$custom = trim( (string) get_theme_mod( 'as_seo_og_image', '' ) );
	if ( '' !== $custom ) {
		return array( 'url' => $custom, 'width' => 0, 'height' => 0, 'alt' => $name );
	}

	// 3. 退回站点 Logo
	$logo_id = get_theme_mod( 'custom_logo' );
	if ( $logo_id ) {
		$img = wp_get_attachment_image_url( $logo_id, 'full' );
		if ( $img ) {
			$meta = wp_get_attachment_metadata( $logo_id );
			return array(
				'url'    => $img,
				'width'  => ( is_array( $meta ) && ! empty( $meta['width'] ) ) ? (int) $meta['width'] : 0,
				'height' => ( is_array( $meta ) && ! empty( $meta['height'] ) ) ? (int) $meta['height'] : 0,
				'alt'    => $name,
			);
		}
	}

	return array( 'url' => '', 'width' => 0, 'height' => 0, 'alt' => '' );
}

/* ==========================================================================
 * 3. 前台 meta 输出
 * ======================================================================== */

/**
 * 输出 description / keywords / canonical / Open Graph / twitter:card。
 *
 * 装了第三方 SEO 插件就整体让路。
 */
function as_seo_meta() {
	if ( as_seo_plugin_active() ) {
		return;
	}

	$desc = as_get_meta_description();
	if ( $desc ) {
		echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
	}

	// keywords：默认不输出（没设置就不塞，搜索引擎已不看重它）
	if ( '1' === (string) get_theme_mod( 'as_seo_output_keywords', '0' ) ) {
		$kw = as_seo_page_keywords();
		if ( $kw ) {
			echo '<meta name="keywords" content="' . esc_attr( $kw ) . '">' . "\n";
		}
	}

	// 归档页 WordPress 默认不输出 canonical
	if ( ! is_singular() && ! is_front_page() && ! is_404() && ! is_search() ) {
		echo '<link rel="canonical" href="' . esc_url( as_current_url() ) . '">' . "\n";
	}

	$img    = as_og_image_info();
	$image  = $img['url'];
	$is_art = ( is_singular() && ! is_front_page() );

	$og = array(
		'og:locale'      => 'zh_CN',
		// 静态首页也满足 is_singular()，但它的 og:type 应该是 website 而不是 article
		'og:type'        => $is_art ? 'article' : 'website',
		'og:site_name'   => get_bloginfo( 'name' ),
		'og:title'       => wp_get_document_title(),
		'og:description' => $desc,
		'og:url'         => as_current_url(),
	);
	if ( $image ) {
		$og['og:image'] = $image;
		if ( $img['alt'] ) {
			$og['og:image:alt'] = $img['alt'];
		}
		// 宽高：抓图方靠它预留版面，缺了容易渲染变形
		if ( $img['width'] && $img['height'] ) {
			$og['og:image:width']  = (string) $img['width'];
			$og['og:image:height'] = (string) $img['height'];
		}
	}

	/*
	 * article:* —— 只在详情页输出（og:type=article 时这些标签才有意义）。
	 *
	 * article:published_time / modified_time 让搜索引擎判断内容新鲜度，
	 * 改动时间也能及时反映到索引里。
	 */
	$tags = array();
	if ( $is_art ) {
		$post_id = get_queried_object_id();
		$post    = get_post( $post_id );
		if ( $post ) {
			$og['article:published_time'] = get_post_time( DATE_W3C, true, $post );
			$og['article:modified_time']  = get_post_modified_time( DATE_W3C, true, $post );

			$author = get_the_author_meta( 'display_name', $post->post_author );
			if ( $author ) {
				$og['article:author'] = $author;
			}

			// 商品页用 plm_category，文章页用 category
			$tax = ( 'plm_product' === $post->post_type ) ? 'plm_category' : 'category';
			$sec = get_the_terms( $post_id, $tax );
			if ( $sec && ! is_wp_error( $sec ) ) {
				$og['article:section'] = $sec[0]->name;
			}

			// 标签可以多个 → 单独收集，稍后一条一个 meta 输出
			$tag_terms = get_the_terms( $post_id, 'post_tag' );
			if ( $tag_terms && ! is_wp_error( $tag_terms ) ) {
				foreach ( $tag_terms as $tt ) {
					if ( $tt instanceof WP_Term ) {
						$tags[] = $tt->name;
					}
				}
			}
		}
	}

	foreach ( $og as $prop => $value ) {
		if ( '' === $value || null === $value ) {
			continue;
		}
		echo '<meta property="' . esc_attr( $prop ) . '" content="' . esc_attr( $value ) . '">' . "\n";
	}

	// article:tag 支持多个值，一条一个 meta
	foreach ( $tags as $tag_name ) {
		echo '<meta property="article:tag" content="' . esc_attr( $tag_name ) . '">' . "\n";
	}

	/*
	 * twitter:* —— v2.87.52 补齐。
	 * 原来只有 twitter:card，X（Twitter）与部分国产分享组件会缺标题/描述/图。
	 */
	echo '<meta name="twitter:card" content="' . ( $image ? 'summary_large_image' : 'summary' ) . '">' . "\n";
	echo '<meta name="twitter:title" content="' . esc_attr( wp_get_document_title() ) . '">' . "\n";
	if ( $desc ) {
		echo '<meta name="twitter:description" content="' . esc_attr( $desc ) . '">' . "\n";
	}
	if ( $image ) {
		echo '<meta name="twitter:image" content="' . esc_url( $image ) . '">' . "\n";
	}
}
/*
 * 「全部优惠」页（/sale/）SEO 补丁（v2.86.54）。
 * 该页由 query var 渲染，WP 的 document_title / 描述逻辑不认识它 ——
 * 不补的话 <title> 只剩站点名、description 是站点默认文案。
 */
add_filter( 'pre_get_document_title', function ( $title ) {
	if ( get_query_var( 'as_sale_page' ) ) {
		return '全部优惠 – ' . get_bloginfo( 'name' );
	}
	// 购物车 / 结算 / 订单 / 会员中心（v2.87.124）：逐页独立标题
	$virtual = as_seo_current_virtual_page();
	if ( $virtual ) {
		return $virtual['title'] . ' – ' . get_bloginfo( 'name' );
	}
	return $title;
} );
add_filter( 'document_title_parts', function ( $parts ) {
	if ( get_query_var( 'as_sale_page' ) ) {
		$parts['title'] = '全部优惠';
		return $parts;
	}

	/*
	 * 首页（v2.87.52）：用后台「首页 SEO 标题」，并去掉 tagline。
	 *
	 * WP 默认行为是把「站点名」和「副标题」拼起来（"大人好物 – 成人情趣用品选购指南"），
	 * 标题里堆了两遍品牌和关键词，搜索结果里可读性差。
	 * 用户自己写的标题已经包含他想推的关键词，所以这里原样用。
	 */
	if ( is_front_page() ) {
		$parts['title'] = as_seo_home_title();
		unset( $parts['tagline'] );
		return $parts;
	}

	/*
	 * 文章列表页（/blog/）：WP 默认给「博客 – 站点名」，
	 * 「博客」两个字没有任何搜索价值 → 换成站点副标题。
	 *
	 * ⚠️ 必须同时 unset 'tagline' 和 'site'：
	 *    非首页时 WP 拼的是 parts['site'] = 站点名，而我们的标题里已经带了站名
	 *    → 不去掉会变成「副标题 - 大人好物 – 大人好物」，站名出现两次。
	 */
	if ( is_home() ) {
		$parts['title'] = as_seo_blog_title();
		unset( $parts['tagline'], $parts['site'] );
		return $parts;
	}

	/*
	 * 商品归档页（/product/）：WP 默认给的是 post type 的注册名「产品」，
	 * 搜索价值很低 → 换成用户实际会搜的「全部商品」。
	 */
	if ( is_post_type_archive( 'plm_product' ) ) {
		$parts['title'] = '全部商品';
		unset( $parts['tagline'] );
		return $parts;
	}

	/*
	 * 分页：从第 2 页起补页码。
	 *
	 * ⚠️ 不补的话「第 2 页」和「第 3 页」的 <title> 完全一样，
	 * 搜索引擎会当成重复页面，而且没法在搜索结果里区分。
	 *
	 * ⚠️ WP 核心自己也会往 parts 里塞一个英文的 'page' 键（"Page 2"），
	 *    这里要 unset 掉，否则会拼成「分类名 - 第 2 页 – Page 2」——
	 *    中英文重复，还很难看。
	 */
	$paged = max( (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
	if ( $paged > 1 && ! empty( $parts['title'] ) ) {
		unset( $parts['page'] );
		$parts['title'] = $parts['title'] . ' - 第 ' . $paged . ' 页';
	}

	return $parts;
} );

/*
 * 移除 WP 的 generator meta（v2.87.52）。
 *
 * 它会输出 <meta name="generator" content="WordPress 7.1.4" />，
 * 对 SEO 没有任何收益，只是把版本号告诉扫描器（多一分被利用的余地）。
 */
remove_action( 'wp_head', 'wp_generator' );

/*
 * v2.87.23：<title> 里的分隔符改成 UTF-8 原字符「–」。
 *
 * WordPress 的 document_title_separator 默认值本身就是字符串 `&#8211;`（HTML 实体），
 * 原样输出进 <title>。浏览器解析后显示为「–」，所以**渲染结果一直是正常的**，
 * 只是查看源码时看到 & 开头容易被误当成「被转义了」。
 *
 * 这里换成原字符：渲染结果完全一致（解析后的文本一字不差），源码更干净，
 * 搜索引擎读到的也是同一串字符，SEO 不受影响。
 */
add_filter( 'document_title_separator', function () {
	return '–';
} );

/*
 * v2.87.125：把标题里残留的实体分隔符还原成原字符。
 *
 * WP 核心在 default-filters.php 里给 document_title 挂了 wptexturize 与
 * convert_chars（优先级 10），会把 ASCII「 - 」变成实体「 &#8211; 」——
 * 首页/博客页因为是主题自己拼的标题，正好被命中；而分类、品牌、商品页由
 * WP 用 document_title_separator 拼（主题已改成原字符「–」），不受影响。
 * 于是同一个站里两种分隔符并存，源码看起来像「有的被转义了」。
 *
 * 这里在优先级 99 再过一道，把实体统一还原成「–」，全站一致。
 */
add_filter( 'document_title', 'as_seo_decode_separator', 99 );
add_action( 'wp_head', 'as_seo_meta', 2 );

/**
 * 搜索结果页与 404 页不收录。
 *
 * 顺带处理了后台勾选「不鼓励搜索引擎收录」的情况——整站 noindex 会覆盖
 * 搜索页的判断，优先级更高，所以先让位给它。
 *
 * @param array $robots WP 已算好的 robots 指令。
 * @return array
 */
function as_robots( $robots ) {
	// 用户主动选了整站不收录 → 不做任何事，尊重用户
	if ( ! empty( $robots['noindex'] ) && ! empty( $robots['index'] ) ) {
		return $robots;
	}

	if ( is_search() || is_404() ) {
		unset( $robots['index'], $robots['noarchive'] );
		$robots['noindex'] = true;
		$robots['follow']  = true;
	}

	/*
	 * 购物车 / 结算 / 订单 / 会员中心（v2.87.124）：
	 * 这些页面对排名没有价值，收录只会制造重复内容 → noindex。
	 */
	$virtual = as_seo_current_virtual_page();
	if ( $virtual && ! empty( $virtual['noindex'] ) ) {
		unset( $robots['index'], $robots['noarchive'] );
		$robots['noindex'] = true;
		$robots['follow']  = true;
	}

	return $robots;
}
add_filter( 'wp_robots', 'as_robots' );

/* ==========================================================================
 * 4. 结构化数据
 * ======================================================================== */

/**
 * 产品详情页输出 Product 结构化数据（JSON-LD）。
 *
 * 装了 SEO 插件就不输出，避免同一页出现两份 schema。
 */
function as_product_schema() {
	if ( as_seo_plugin_active() || ! is_singular( 'plm_product' ) ) {
		return;
	}

	$post_id = get_queried_object_id();
	$post    = get_post( $post_id );
	if ( ! $post ) {
		return;
	}

	$price  = get_post_meta( $post_id, 'dg_price', true );
	$brands = get_the_terms( $post_id, 'plm_brand' );
	$desc   = as_get_meta_description();

	$data = array(
		'@context'    => 'https://schema.org',
		'@type'       => 'Product',
		'name'        => get_the_title( $post_id ),
		'description' => $desc,
		'url'         => get_permalink( $post_id ),
	);

	if ( is_numeric( $price ) && (float) $price > 0 ) {
		$data['offers'] = array(
			'@type'         => 'Offer',
			'price'        => number_format( (float) $price, 2, '.', '' ),
			'priceCurrency' => 'CNY',
			'availability'  => 'https://schema.org/InStock',
			'url'           => get_permalink( $post_id ),
		);
	}

	if ( is_array( $brands ) && $brands ) {
		$names = array();
		foreach ( $brands as $b ) {
			if ( $b instanceof WP_Term ) {
				$names[] = $b->name;
			}
		}
		if ( $names ) {
			$data['brand'] = array(
				'@type' => 'Brand',
				'name'  => implode( ', ', $names ),
			);
		}
	}

	$image = get_the_post_thumbnail_url( $post_id, 'large' );
	if ( $image ) {
		$data['image'] = $image;
	}

	/*
	 * v2.87.52 补三个字段：
	 *   sku       —— Google 要求 Product 至少有 sku / mpn / productID 之一，
	 *                本站没有独立货号字段，用站内唯一的商品 ID 当 sku（不是编造的数据）。
	 *   category  —— 商品分类，Google 会用它做商品归类。
	 *   itemCondition —— 全是全新品，写死 NewCondition。
	 */
	$data['sku'] = 'AS-' . $post_id;

	$cats = get_the_terms( $post_id, 'plm_category' );
	if ( $cats && ! is_wp_error( $cats ) ) {
		$data['category'] = $cats[0]->name;
	}

	if ( isset( $data['offers'] ) ) {
		$data['offers']['itemCondition'] = 'https://schema.org/NewCondition';
	}

	// 手工拼 JSON 而不是 wp_json_encode()：
	// JSON-LD 里不能转义成 \uXXXX，否则部分爬虫解析中文会出问题。
	echo '<script type="application/ld+json">' . as_seo_json( $data ) . "</script>\n";
}
add_action( 'wp_head', 'as_product_schema', 3 );

/**
 * 发布方（站点本身）的 schema 数据。
 *
 * Article 的 publisher 和 Organization 结构化数据共用它，避免两处各写一套。
 *
 * @return array
 */
function as_seo_publisher_data() {
	$out = array(
		'@type' => 'Organization',
		'@id'   => home_url( '/' ) . '#organization',
		'name'  => get_bloginfo( 'name' ),
		'url'   => home_url( '/' ),
	);

	$logo_id = get_theme_mod( 'custom_logo' );
	if ( $logo_id ) {
		$logo = wp_get_attachment_image_url( $logo_id, 'full' );
		if ( $logo ) {
			$out['logo'] = array(
				'@type' => 'ImageObject',
				'url'   => $logo,
			);
		}
	}

	return $out;
}

/**
 * 文章详情页输出 Article 结构化数据（JSON-LD）。
 *
 * 装了 SEO 插件就不输出，避免同一页出现两份 schema。
 */
function as_article_schema() {
	if ( as_seo_plugin_active() || ! is_singular( 'post' ) ) {
		return;
	}

	$post_id = get_queried_object_id();
	$post    = get_post( $post_id );
	if ( ! $post ) {
		return;
	}

	$data = array(
		'@context'         => 'https://schema.org',
		'@type'            => 'Article',
		'headline'         => get_the_title( $post_id ),
		'description'      => as_get_meta_description(),
		'url'              => get_permalink( $post_id ),
		'mainEntityOfPage' => array(
			'@type' => 'WebPage',
			'@id'   => get_permalink( $post_id ),
		),
		'datePublished'    => get_post_time( DATE_W3C, true, $post ),
		'dateModified'     => get_post_modified_time( DATE_W3C, true, $post ),
		'author'           => array(
			'@type' => 'Person',
			'name'  => get_the_author_meta( 'display_name', $post->post_author ),
		),
		'publisher'        => as_seo_publisher_data(),
		'inLanguage'       => 'zh-CN',
	);

	$img = as_og_image_info();
	if ( $img['url'] ) {
		$data['image'] = array( $img['url'] );
	}

	$cats = get_the_terms( $post_id, 'category' );
	if ( $cats && ! is_wp_error( $cats ) ) {
		$data['articleSection'] = $cats[0]->name;
	}

	$tag_terms = get_the_terms( $post_id, 'post_tag' );
	if ( $tag_terms && ! is_wp_error( $tag_terms ) ) {
		$kw = array();
		foreach ( $tag_terms as $tt ) {
			if ( $tt instanceof WP_Term ) {
				$kw[] = $tt->name;
			}
		}
		if ( $kw ) {
			$data['keywords'] = $kw;
		}
	}

	echo '<script type="application/ld+json">' . as_seo_json( $data ) . "</script>\n";
}
add_action( 'wp_head', 'as_article_schema', 3 );

/**
 * 面包屑结构化数据（JSON-LD）。
 *
 * 数据来自 as_breadcrumb_items() —— 和前台显示的面包屑**同一份**，
 * 不会出现"页面上显示 A、给搜索引擎报 B"的情况。
 *
 * 只有 1 项（等于只有"首页"）时不输出：单节点的面包屑对搜索结果没有意义。
 */
function as_breadcrumb_schema() {
	if ( as_seo_plugin_active() ) {
		return;
	}

	$items = as_breadcrumb_items();
	if ( ! is_array( $items ) || count( $items ) < 2 ) {
		return;
	}

	$list = array();
	$i    = 0;
	foreach ( $items as $it ) {
		$entry = array(
			'@type'    => 'ListItem',
			'position' => ++$i,
			'name'     => $it['name'],
		);
		// 最后一项（当前页）没有链接，不给 item
		if ( ! empty( $it['url'] ) ) {
			$entry['item'] = $it['url'];
		}
		$list[] = $entry;
	}

	$data = array(
		'@context'        => 'https://schema.org',
		'@type'           => 'BreadcrumbList',
		'itemListElement' => $list,
	);

	echo '<script type="application/ld+json">' . as_seo_json( $data ) . "</script>\n";
}
add_action( 'wp_head', 'as_breadcrumb_schema', 4 );

/**
 * 首页输出 WebSite + Organization 结构化数据。
 *
 * 用 @graph 把两者放在一个 script 标签里，Organization 通过 @id 被引用，
 * 是 Google 推荐的组织标识写法（能同时声明站点名与品牌信息）。
 */
function as_site_schema() {
	if ( as_seo_plugin_active() || ! is_front_page() ) {
		return;
	}

	$name = get_bloginfo( 'name' );
	$desc = as_get_meta_description();

	$website = array(
		'@type'      => 'WebSite',
		'@id'        => home_url( '/' ) . '#website',
		'url'        => home_url( '/' ),
		'name'       => $name,
		'description' => $desc,
		'inLanguage' => 'zh-CN',
		// 站内搜索入口（Google 会据此在搜索结果里展示站内搜索框）
		'potentialAction' => array(
			'@type'       => 'SearchAction',
			'target'      => array(
				'@type'       => 'EntryPoint',
				'urlTemplate' => home_url( '/?s={search_term_string}' ),
			),
			'query-input' => 'required name=search_term_string',
		),
	);

	$org = as_seo_publisher_data();
	$org['description'] = $desc;

	// 联系方式：后台填了邮箱才报（不编造）
	$mail = trim( (string) get_theme_mod( 'as_contact_mail', '' ) );
	if ( '' !== $mail && false !== strpos( $mail, '@' ) ) {
		$org['contactPoint'] = array(
			'@type'       => 'ContactPoint',
			'contactType' => 'customer service',
			'email'       => $mail,
			'url'         => home_url( '/' ),
		);
	}

	// 社交主页：后台填了才报
	$same = array();
	foreach ( array( 'as_weibo', 'as_wechat' ) as $k ) {
		$u = trim( (string) get_theme_mod( $k, '' ) );
		if ( '' !== $u ) {
			$same[] = $u;
		}
	}
	if ( $same ) {
		$org['sameAs'] = $same;
	}

	$data = array(
		'@context' => 'https://schema.org',
		'@graph'   => array( $website, $org ),
	);

	echo '<script type="application/ld+json">' . as_seo_json( $data ) . "</script>\n";
}
add_action( 'wp_head', 'as_site_schema', 3 );

/**
 * 简单的 JSON 编码：转义斜杠与引号，保留中文原样。
 *
 * 之所以不用 wp_json_encode()，是因为它默认把非 ASCII 转成 \uXXXX，
 * 中文 schema 对部分爬虫不友好。
 *
 * @param mixed $data 待编码数据。
 * @return string
 */
function as_seo_json( $data ) {
	$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
	$json  = wp_json_encode( $data, $flags );
	// 防兜底：万一 wp_json_encode 不可用，退回原样
	return is_string( $json ) ? $json : '{}';
}

/* ==========================================================================
 * 5. 后台设置（外观 → 自定义 → SEO 优化）
 * ======================================================================== */

/**
 * 注册「SEO 优化」设置区与三要素设置项。
 *
 * 刻意放在「外观 → 自定义」而不是新建一级菜单：SEO 属于站点级配置，
 * 混进业务菜单会和「小程序商城」那些页面抢注意力。
 */
function as_seo_customize_register( $wp_customize ) {
	$plugin = as_seo_plugin_active();
	$hint   = $plugin
		? '检测到已安装第三方 SEO 插件（Yoast / Rank Math 等），本主题的 SEO 输出已自动让路，这里的设置暂不生效。卸载插件后即恢复。'
		: '三要素留空时前台会走自动生成（页面上真实的内容），不会被输出成空标签。';

	$wp_customize->add_section( 'as_seo_settings', array(
		'title'       => 'SEO 优化',
		'description' => '网站前端（搜索引擎与社交分享卡片）的三要素设置。' . $hint
			. '<br><b>完整的 SEO 设置（含首页描述与关键词）在「主题设置 → SEO 优化」里</b>，两边改的是同一份数据。',
		'priority'    => 29,
	) );

	$fields = array(
		'as_seo_description' => array(
			'label'    => '全局 SEO 描述',
			'type'     => 'textarea',
			'default'  => '',
			'sanitize' => 'sanitize_textarea_field',
			'desc'     => '约 60-120 字。作用范围：文章页/分类页等所有「算不出自身描述」的页面会退回用这段文案，属于兜底，不会覆盖文章自己的摘要。',
		),
		'as_seo_keywords'    => array(
			'label'    => '全局 SEO 关键词',
			'type'     => 'text',
			'default'  => '',
			'sanitize' => 'sanitize_text_field',
			'desc'     => '半角逗号分隔，如：成人用品,情趣内衣,私密护理。中英文逗号都认，会自动规整。留空则不输出 keywords。',
		),
		'as_seo_og_image'    => array(
			'label'    => '社交分享图（og:image）',
			'type'     => 'url',
			'default'  => '',
			'sanitize' => 'esc_url_raw',
			'desc'     => '微信、微博、Facebook 分享时显示的封面图。留空则优先用文章特色图，其次用站点 Logo。建议 1200×630、5:2 左右。',
		),
		'as_seo_output_keywords' => array(
			'label'    => '输出 keywords 标签',
			'type'     => 'checkbox',
			'default'  => false,
			'sanitize' => 'as_sanitize_checkbox',
			'desc'     => '默认关闭。Google 2009 年已弃用该标签、百度权重也极低，留空不输出更干净；勾选后会按页面类型自动拼（商品页=分类+品牌，文章页=分类+标签）。',
		),
	);

	foreach ( $fields as $key => $args ) {
		$wp_customize->add_setting( $key, array(
			'default'           => $args['default'],
			'sanitize_callback' => $args['sanitize'],
			// 用 refresh 而非 postMessage：SEO 标签在<title> 与 <head> 里，
			// 改完不刷新容易让人以为没生效
			'transport'         => 'refresh',
		) );

		$wp_customize->add_control( $key, array(
			'label'       => $args['label'],
			'section'     => 'as_seo_settings',
			'type'        => $args['type'],
			'description' => $args['desc'],
		) );
	}
}
add_action( 'customize_register', 'as_seo_customize_register' );

/**
 * 复选框清洗回调。
 *
 * functions.php 里已有同名函数（as_rename_uploads 用的），
 * 所以这里做存在性判断，避免重复定义导致致命错误。
 *
 * @param mixed $checked 表单值。
 * @return bool
 */
if ( ! function_exists( 'as_sanitize_checkbox' ) ) {
	function as_sanitize_checkbox( $checked ) {
		return (bool) $checked;
	}
}
