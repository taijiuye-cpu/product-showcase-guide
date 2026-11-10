<?php
/**
 * 主题初始化 —— 一键搭建站点基础结构
 * ---------------------------------------------------------------------------
 * 目的：装好主题后新站点是「空的」—— 没有页面、没有菜单、没有站点信息，
 *       前台首页是空的、页脚是空的、SEO 是自动生成的默认值。
 *       这个模块把这些**通用骨架**一次搭好。
 *
 * 🔴 边界（重要）：只初始化**与内容无关**的东西。
 *    商品、商品分类、品牌、文章、文章分类 —— 这些每个站点都不一样，
 *    主题无权替用户决定，所以**一律不碰**。
 *    示例数据请走「主题设置 → 演示数据导入」，那是另一个入口。
 *
 * 初始化项（全部幂等：已存在的会跳过，不会重复创建）：
 *   1. 页面：商品归档页 / 限时特惠页 / 全部品牌页 / 关于我们 / 隐私政策 / 文章归档页
 *   2. 导航菜单：主导航 + 底部导航，并挂到主题菜单位
 *   3. 站点信息：站点名/副标题/描述（用当前值兜底，不覆盖）
 *   4. SEO 基础项：首页标题/描述（留空则自动生成）
 *   5. 服务说明标签：给一个通用默认值
 *   6. 固定链接：切到「文章名」并刷新伪静态规则
 *
 * @package product-showcase-guide
 */

defined( 'ABSPATH' ) || exit;

/* ==========================================================================
 * 菜单挂载
 * ======================================================================== */

/**
 * 注册「主题初始化」子菜单。
 */
function as_init_admin_menu() {
	add_submenu_page(
		'as-site-seo',
		'主题初始化',
		'主题初始化',
		'manage_options',
		'as-site-init',
		'as_init_page'
	);
}
add_action( 'admin_menu', 'as_init_admin_menu', 20 );

/**
 * 后台页面的样式与脚本（跟主题设置页共用一套）。
 */
function as_init_admin_assets( $hook ) {
	if ( false === strpos( (string) $hook, 'as-site-init' ) ) {
		return;
	}
	wp_enqueue_style(
		'as-admin-mobile',
		get_template_directory_uri() . '/assets/css/admin-mobile.css',
		array(),
		as_asset_version( '/assets/css/admin-mobile.css' )
	);
}
add_action( 'admin_enqueue_scripts', 'as_init_admin_assets' );

/* ==========================================================================
 * 初始化项定义
 * ======================================================================== */

/**
 * 初始化项清单。
 *
 * 每项 = array(
 *   'key'      => 唯一键（也是表单里的复选框 name）
 *   'title'    => 后台显示的标题
 *   'desc'     => 说明
 *   'group'    => 分组（用于界面分区）
 *   'fn'       => 执行函数名
 * )
 *
 * @return array
 */
function as_init_tasks() {
	return array(
		'pages'    => array(
			'key'   => 'pages',
			'title' => '创建必要页面',
			'desc'  => '商品归档、限时特惠、全部品牌、关于我们、隐私政策、文章归档。已存在的页面会跳过，不会覆盖内容。',
			'group' => 'structure',
			'fn'    => 'as_init_task_pages',
		),
		'menu'     => array(
			'key'   => 'menu',
			'title' => '创建并挂载导航菜单',
			'desc'  => '建「主导航」与「底部导航」两个菜单，主导航自动挂到主题的主菜单位。已存在同名菜单会复用。',
			'group' => 'structure',
			'fn'    => 'as_init_task_menu',
		),
		'permalink' => array(
			'key'   => 'permalink',
			'title' => '设置固定链接并刷新伪静态',
			'desc'  => '切到「文章名」并刷新重写规则 —— 商品详情页依赖伪静态规则，不做这步商品页会 404。',
			'group' => 'structure',
			'fn'    => 'as_init_task_permalink',
		),
		'siteinfo' => array(
			'key'   => 'siteinfo',
			'title' => '补全站点基本信息',
			'desc'  => '站点标题、描述、副标题。已经有值的字段保持原样，只补空缺的那几个。',
			'group' => 'content',
			'fn'    => 'as_init_task_siteinfo',
		),
		'seo'      => array(
			'key'   => 'seo',
			'title' => '填入 SEO 基础项',
			'desc'  => '首页标题与描述。留空时前台会自动生成，这里只是给一个可编辑的起点。',
			'group' => 'content',
			'fn'    => 'as_init_task_seo',
		),
		'service'  => array(
			'key'   => 'service',
			'title' => '设置服务说明标签',
			'desc'  => '商品详情页顶部那排标签（隐私发货 / 正品保障等）。按空格分隔。',
			'group' => 'content',
			'fn'    => 'as_init_task_service',
		),
	);
}

/**
 * 分组标题。
 *
 * @return array
 */
function as_init_groups() {
	return array(
		'structure' => '站点结构',
		'content'   => '站点内容',
	);
}

/* ==========================================================================
 * 各初始化项的实现
 * ======================================================================== */

/**
 * 页面清单。
 *
 * 说明：
 *   · 「商品归档」「限时特惠」「全部品牌」是**内容页**，用固定 slug 建，
 *     链接稳定（/product/ /sale/ /brands/），不随标题变化。
 *   · 「关于我们」「隐私政策」用 slug 建，内容给一段通用骨架，
 *     用户可以在后台直接改。
 *   · 「文章归档」把站点副标题当标题，内容给通用引导。
 *
 * @return array
 */
function as_init_page_defs() {
	$brand_name = get_bloginfo( 'name' );
	$tagline    = get_bloginfo( 'description' );

	return array(
		'product' => array(
			'title'   => '全部商品',
			'slug'    => 'product',
			'content' => "<!-- wp:paragraph -->\n<p>这里会汇总全站所有商品。按品类或品牌筛选更快。</p>\n<!-- /wp:paragraph -->",
		),
		'sale'    => array(
			'title'   => '限时特惠',
			'slug'    => 'sale',
			'content' => "<!-- wp:paragraph -->\n<p>本站正在进行的特惠活动会显示在这里。</p>\n<!-- /wp:paragraph -->",
		),
		'brands'  => array(
			'title'   => '全部品牌',
			'slug'    => 'brands',
			'content' => "<!-- wp:paragraph -->\n<p>本站收录的品牌一览。</p>\n<!-- /wp:paragraph -->",
		),
		'about'   => array(
			'title'   => '关于我们',
			'slug'    => 'about',
			'content' => "<!-- wp:heading -->\n<h2>关于本站</h2>\n<!-- /wp:heading -->\n<!-- wp:paragraph -->\n<p>" .
				esc_html( $brand_name ) . " 是一个专注商品展示与选购指南的站点。我们把每件商品的参数、成分与真实体验写清楚，" .
				"不夸大、不刷单，帮助你按自己的需求挑，而不是被广告带着走。</p>\n" .
				"<!-- /wp:paragraph -->\n" .
				"<!-- wp:heading -->\n<h3>我们怎么选品</h3>\n<!-- /wp:heading -->\n<!-- wp:paragraph -->\n<p>" .
				"只收录能说清成分与规格的商品；有实测数据的会写明使用感受，没有数据的会直接说明，不编。</p>\n" .
				"<!-- /wp:paragraph -->\n" .
				"<!-- wp:heading -->\n<h3>服务承诺</h3>\n<!-- /wp:heading -->\n<!-- wp:list -->\n<ul>" .
				"<li>正品保障，不做假货生意</li>\n<li>隐私发货，不在外包装露出商品信息</li>\n<li>如实描述，不做夸大宣传</li>\n" .
				"</ul>\n<!-- /wp:list -->",
		),
		'privacy' => array(
			'title'   => '隐私政策',
			'slug'    => 'privacy',
			'content' => "<!-- wp:heading -->\n<h2>隐私政策</h2>\n<!-- /wp:heading -->\n<!-- wp:paragraph -->\n<p>" .
				esc_html( $brand_name . ' ' . $tagline ) . " 非常重视你的隐私。本政策说明我们如何处理访问者信息。</p>\n" .
				"<!-- /wp:paragraph -->\n" .
				"<!-- wp:heading -->\n<h3>我们收集什么</h3>\n<!-- /wp:heading -->\n<!-- wp:paragraph -->\n<p>" .
				"本站不收集、不上传任何用户数据到第三方。除常规的访问日志外，不建立用户画像、不做行为追踪。</p>\n" .
				"<!-- /wp:paragraph -->\n" .
				"<!-- wp:heading -->\n<h3>Cookie</h3>\n<!-- /wp:heading -->\n<!-- wp:paragraph -->\n<p>" .
				"WordPress 默认会写入用于登录与评论的 Cookie。除此之外不使用追踪类 Cookie。</p>\n" .
				"<!-- /wp:paragraph -->\n" .
				"<!-- wp:heading -->\n<h3>联系我们</h3>\n<!-- /wp:heading -->\n<!-- wp:paragraph -->\n<p>" .
				"如对本政策有疑问，可在页脚联系方式处与我们联系。</p>\n<!-- /wp:paragraph -->",
		),
		'blog'    => array(
			'title'   => '选购指南',
			'slug'    => 'blog',
			'content' => "<!-- wp:paragraph -->\n<p>选购指南、使用心得与知识科普都在这里。</p>\n<!-- /wp:paragraph -->",
		),
	);
}

/**
 * 找已存在的页面（只认**已发布**的）。
 *
 * 🔴 为什么不用 get_page_by_path()：WordPress 自带一个「隐私政策」草稿页
 *    （post_status = draft），get_page_by_path() 会把它也算进去 →
 *    初始化任务以为页面已存在就跳过 → 结果前台 /privacy/ 访问 404。
 *    所以这里额外要求 post_status 是 publish。
 *
 * @param string $slug 页面 slug。
 * @return WP_Post|null
 */
function as_init_find_page( $slug ) {
	$page = get_page_by_path( $slug );
	if ( $page && 'publish' === $page->post_status ) {
		return $page;
	}
	return null;
}

/**
 * 任务：创建页面。
 *
 * @return array 执行结果：array( 'ok' => bool, 'msg' => string, 'detail' => array )
 */
function as_init_task_pages() {
	$created = array();
	$skipped = array();

	foreach ( as_init_page_defs() as $key => $def ) {
		$existing = as_init_find_page( $def['slug'] );
		if ( $existing ) {
			$skipped[] = $def['title'];
			continue;
		}

		/*
		 * WordPress 自带一个「隐私政策」**草稿**页（post_status = draft）。
		 * 它没发布 → 前台访问 404 → 跟「页面不存在」效果一样。
		 * 这里把草稿发布出来并填上内容，而不是新建一个重复的页面。
		 */
		$draft = get_page_by_path( $def['slug'] );
		if ( $draft && 'publish' !== $draft->post_status ) {
			wp_update_post(
				array(
					'ID'           => $draft->ID,
					'post_status'  => 'publish',
					'post_title'   => $def['title'],
					'post_content' => $def['content'],
				)
			);
			$created[] = $def['title'] . '（由草稿发布）';
			continue;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => $def['title'],
				'post_name'    => $def['slug'],
				'post_content' => $def['content'],
				'post_status'  => 'publish',
				'post_type'    => 'page',
			),
			true
		);

		if ( is_wp_error( $page_id ) ) {
			return array(
				'ok'     => false,
				'msg'    => '创建页面「' . $def['title'] . '」失败：' . $page_id->get_error_message(),
				'detail' => array(),
			);
		}

		/*
		 * 「限时特惠」与「全部品牌」用页面模板渲染（page-sale.php / page-brands.php），
		 * 建好后把模板设上 —— 前台 /sale/ 走的是 rewrite 规则，
		 * 但从菜单进去时走的是这个页面，两条路都得能用。
		 */
		if ( 'sale' === $key ) {
			update_post_meta( $page_id, '_wp_page_template', 'page-sale.php' );
		} elseif ( 'brands' === $key ) {
			update_post_meta( $page_id, '_wp_page_template', 'page-brands.php' );
		}

		$created[] = $def['title'];
	}

	$msg = '';
	if ( $created ) {
		$msg .= '已创建页面：' . implode( '、', $created ) . '。';
	}
	if ( $skipped ) {
		$msg .= ( $created ? ' ' : '' ) . '已存在（跳过）：' . implode( '、', $skipped ) . '。';
	}
	if ( ! $msg ) {
		$msg = '没有需要创建的页面。';
	}

	return array(
		'ok'     => true,
		'msg'    => $msg,
		'detail' => array( 'created' => $created, 'skipped' => $skipped ),
	);
}

/**
 * 任务：创建并挂载导航菜单。
 *
 * @return array
 */
function as_init_task_menu() {
	$locs = get_theme_mod( 'nav_menu_locations', array() );
	if ( ! is_array( $locs ) ) {
		$locs = array();
	}

	$done = array();

	// —— 主导航 ——
	$primary = isset( $locs['primary'] ) ? (int) $locs['primary'] : 0;
	if ( $primary && get_term( $primary, 'nav_menu' ) ) {
		$done[] = '主导航已挂载，跳过。';
	} else {
		$menu_id = as_init_get_or_create_menu( '主导航' );
		if ( is_wp_error( $menu_id ) ) {
			return array( 'ok' => false, 'msg' => $menu_id->get_error_message(), 'detail' => array() );
		}

		as_init_fill_primary_menu( $menu_id );

		$locs['primary'] = $menu_id;
		set_theme_mod( 'nav_menu_locations', $locs );
		$done[] = '主导航已创建并挂载。';
	}

	// —— 底部导航 ——
	$footer = isset( $locs['footer'] ) ? (int) $locs['footer'] : 0;
	if ( $footer && get_term( $footer, 'nav_menu' ) ) {
		$done[] = '底部导航已挂载，跳过。';
	} else {
		$menu_id = as_init_get_or_create_menu( '底部导航' );
		if ( is_wp_error( $menu_id ) ) {
			return array( 'ok' => false, 'msg' => $menu_id->get_error_message(), 'detail' => array() );
		}

		as_init_fill_footer_menu( $menu_id );

		$locs['footer'] = $menu_id;
		set_theme_mod( 'nav_menu_locations', $locs );
		$done[] = '底部导航已创建并挂载。';
	}

	return array(
		'ok'     => true,
		'msg'    => implode( ' ', $done ),
		'detail' => array(),
	);
}

/**
 * 取同名菜单，没有就建。
 *
 * @param string $name 菜单名。
 * @return int|WP_Error 菜单 term_id。
 */
function as_init_get_or_create_menu( $name ) {
	$menu = wp_get_nav_menu_object( $name );
	if ( $menu ) {
		return (int) $menu->term_id;
	}

	$menu_id = wp_create_nav_menu( $name );
	if ( is_wp_error( $menu_id ) ) {
		return $menu_id;
	}
	return (int) $menu_id;
}

/**
 * 往主导航里塞通用条目（只加不存在的）。
 *
 * 条目刻意保持**通用** —— 首页、商品、选购指南、限时特惠、关于我们。
 * 分类、品牌这类内容相关的入口留给用户自己按需添加。
 *
 * @param int $menu_id 菜单 ID。
 */
function as_init_fill_primary_menu( $menu_id ) {
	$items = array(
		array( 'title' => '首页', 'url' => home_url( '/' ) ),
		array( 'title' => '全部商品', 'url' => home_url( '/product/' ) ),
		array( 'title' => '选购指南', 'url' => home_url( '/blog/' ) ),
		array( 'title' => '限时特惠', 'url' => home_url( '/sale/' ) ),
		array( 'title' => '全部品牌', 'url' => home_url( '/brands/' ) ),
		array( 'title' => '关于我们', 'url' => home_url( '/about/' ) ),
	);

	$existing = as_init_menu_urls( $menu_id );

	foreach ( $items as $item ) {
		if ( isset( $existing[ $item['url'] ] ) ) {
			continue;
		}
		wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'  => $item['title'],
				'menu-item-url'    => $item['url'],
				'menu-item-status' => 'publish',
			)
		);
	}
}

/**
 * 往底部导航里塞通用条目。
 *
 * @param int $menu_id 菜单 ID。
 */
function as_init_fill_footer_menu( $menu_id ) {
	$items = array(
		array( 'title' => '关于我们', 'url' => home_url( '/about/' ) ),
		array( 'title' => '隐私政策', 'url' => home_url( '/privacy/' ) ),
		array( 'title' => '选购指南', 'url' => home_url( '/blog/' ) ),
	);

	$existing = as_init_menu_urls( $menu_id );

	foreach ( $items as $item ) {
		if ( isset( $existing[ $item['url'] ] ) ) {
			continue;
		}
		wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'  => $item['title'],
				'menu-item-url'    => $item['url'],
				'menu-item-status' => 'publish',
			)
		);
	}
}

/**
 * 取菜单里已有的 URL（用于判重）。
 *
 * @param int $menu_id 菜单 ID。
 * @return array url => 1
 */
function as_init_menu_urls( $menu_id ) {
	$out  = array();
	$items = wp_get_nav_menu_items( $menu_id );
	if ( ! is_array( $items ) ) {
		return $out;
	}
	foreach ( $items as $item ) {
		if ( ! empty( $item->url ) ) {
			$out[ $item->url ] = 1;
		}
	}
	return $out;
}

/**
 * 任务：固定链接 + 刷新伪静态。
 *
 * @return array
 */
function as_init_task_permalink() {
	$old = get_option( 'permalink_structure' );
	update_option( 'permalink_structure', '/%postname%/' );

	// 强制刷新重写规则（商品详情页靠它）
	global $wp_rewrite;
	if ( $wp_rewrite ) {
		$wp_rewrite->init();
		$wp_rewrite->flush_rules( true );
	}

	$msg = '固定链接已设为「文章名」。';
	if ( $old && $old !== '/%postname%/' ) {
		$msg .= '（原为 ' . $old . '）';
	}
	$msg .= ' 伪静态规则已刷新。';

	return array(
		'ok'     => true,
		'msg'    => $msg,
		'detail' => array( 'from' => $old ),
	);
}

/**
 * 任务：补全站点基本信息（只补空缺，不覆盖已有值）。
 *
 * @return array
 */
function as_init_task_siteinfo() {
	$filled = array();

	if ( ! get_option( 'blogdescription' ) ) {
		update_option( 'blogdescription', '按需求挑，比按品牌挑更快' );
		$filled[] = '站点副标题';
	}

	// 站点描述：WordPress 用 tagline，主题的 SEO 描述另存 theme_mod
	if ( '' === (string) get_theme_mod( 'as_seo_desc', '' ) ) {
		set_theme_mod( 'as_seo_desc', '按需求挑，比按品牌挑更快 —— 每件商品写清参数、成分与真实体验。' );
		$filled[] = '站点描述';
	}

	$msg = $filled ? ( '已补全：' . implode( '、', $filled ) . '。' ) : '站点基本信息已完整，未改动。';

	return array(
		'ok'     => true,
		'msg'    => $msg,
		'detail' => array( 'filled' => $filled ),
	);
}

/**
 * 任务：SEO 基础项。
 *
 * @return array
 */
function as_init_task_seo() {
	$filled = array();

	if ( '' === (string) get_theme_mod( 'as_seo_title', '' ) ) {
		set_theme_mod( 'as_seo_title', get_bloginfo( 'name' ) . ' —— 按需求挑，比按品牌挑更快' );
		$filled[] = '首页标题';
	}

	if ( '' === (string) get_theme_mod( 'as_seo_keywords', '' ) ) {
		set_theme_mod( 'as_seo_keywords', '选购指南,商品评测,参数对比' );
		$filled[] = '首页关键词';
	}

	$msg = $filled ? ( '已填入：' . implode( '、', $filled ) . '。' ) : 'SEO 项已有值，未改动。';

	return array(
		'ok'     => true,
		'msg'    => $msg,
		'detail' => array( 'filled' => $filled ),
	);
}

/**
 * 任务：服务说明标签。
 *
 * @return array
 */
function as_init_task_service() {
	$cfg = get_option( 'as_mini_config', array() );
	if ( ! is_array( $cfg ) ) {
		$cfg = array();
	}

	if ( ! empty( $cfg['service_tags'] ) ) {
		return array(
			'ok'     => true,
			'msg'    => '服务说明标签已有值，未改动。',
			'detail' => array(),
		);
	}

	$cfg['service_tags'] = '正品保障 假一赔十 隐私发货 五点前当天发货';
	update_option( 'as_mini_config', $cfg );

	return array(
		'ok'     => true,
		'msg'    => '服务说明标签已填入（正品保障 / 假一赔十 / 隐私发货 / 五点前当天发货）。',
		'detail' => array(),
	);
}

/* ==========================================================================
 * 后台界面
 * ======================================================================== */

/**
 * 渲染「主题初始化」页面。
 */
function as_init_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	if ( 'as-site-init' !== $page ) {
		return;
	}

	$notice = '';

	// —— 执行 ——
	if ( isset( $_POST['as_init_run'] ) && check_admin_referer( 'as_init_run', 'as_init_nonce' ) ) {
		$keys   = isset( $_POST['as_init_tasks'] ) ? (array) wp_unslash( $_POST['as_init_tasks'] ) : array();
		$keys   = array_map( 'sanitize_key', $keys );
		$report = as_init_run_tasks( $keys );

		if ( $report['failed'] ) {
			$notice = '<div class="notice notice-error"><p><strong>部分项目未完成：</strong></p><ul style="list-style:disc;margin-left:20px">';
			foreach ( $report['results'] as $r ) {
				if ( ! $r['ok'] ) {
					$notice .= '<li>' . esc_html( $r['title'] . '：' . $r['msg'] ) . '</li>';
				}
			}
			$notice .= '</ul></div>';
		} else {
			$notice = '<div class="notice notice-success"><p><strong>初始化完成。</strong></p><ul style="list-style:disc;margin-left:20px">';
			foreach ( $report['results'] as $r ) {
				$notice .= '<li>' . esc_html( $r['title'] . '：' . $r['msg'] ) . '</li>';
			}
			$notice .= '</ul></div>';
		}
	}

	// —— 状态检测 ——
	$status = as_init_status();

	$tasks = as_init_tasks();
	$groups = as_init_groups();
	?>
	<div class="wrap">
		<h1>主题初始化</h1>
		<p class="description" style="max-width:780px">
			一键搭好站点的<b>通用骨架</b>：页面、导航菜单、站点信息、SEO 基础项、服务说明标签、固定链接。<br>
			<b>不碰商品、分类、品牌和文章</b> —— 那些内容每个站点都不一样，由你自己维护；
			需要示例内容请去「<a href="<?php echo esc_url( admin_url( 'admin.php?page=as-demo-import' ) ); ?>">演示数据导入</a>」。
		</p>

		<?php echo $notice; ?>

		<div class="card" style="max-width:900px;margin:16px 0;padding:12px 16px;">
			<h2 style="margin-top:8px;font-size:15px;">当前状态</h2>
			<table class="widefat striped" style="margin-top:8px;">
				<tbody>
				<?php foreach ( $status as $s ) : ?>
					<tr>
						<td style="width:200px;"><?php echo esc_html( $s['label'] ); ?></td>
						<td><?php echo $s['ok'] ? '<span style="color:#2271b1;">✓ ' . esc_html( $s['text'] ) . '</span>' : '<span style="color:#d63638;">✗ ' . esc_html( $s['text'] ) . '</span>'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<form method="post">
			<?php wp_nonce_field( 'as_init_run', 'as_init_nonce' ); ?>

			<?php foreach ( $groups as $group_key => $group_title ) : ?>
				<h2 style="font-size:15px;margin-top:24px;"><?php echo esc_html( $group_title ); ?></h2>
				<table class="widefat striped" style="max-width:900px;">
					<tbody>
					<?php
					foreach ( $tasks as $task ) :
						if ( $task['group'] !== $group_key ) {
							continue;
						}
						$checked = ( 'permalink' === $task['key'] ) ? ' checked' : '';
						?>
						<tr>
							<td style="width:36px;">
								<input type="checkbox" name="as_init_tasks[]" value="<?php echo esc_attr( $task['key'] ); ?>"<?php echo $checked; ?>>
							</td>
							<td>
								<strong><?php echo esc_html( $task['title'] ); ?></strong>
								<p class="description" style="margin:4px 0 0;"><?php echo esc_html( $task['desc'] ); ?></p>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endforeach; ?>

			<p style="margin-top:16px;">
				<button type="submit" name="as_init_run" value="1" class="button button-primary">执行选中的初始化项</button>
				<span class="description" style="margin-left:10px;">所有项都是幂等的，已存在的会跳过，不会覆盖你已有的内容。</span>
			</p>
		</form>
	</div>
	<?php
}

/**
 * 跑选中的初始化任务。
 *
 * @param array $keys 任务键列表。
 * @return array
 */
function as_init_run_tasks( $keys ) {
	$tasks   = as_init_tasks();
	$results = array();

	foreach ( $tasks as $task ) {
		if ( ! in_array( $task['key'], $keys, true ) ) {
			continue;
		}

		$out = call_user_func( $task['fn'] );

		$results[] = array(
			'key'   => $task['key'],
			'title' => $task['title'],
			'ok'    => ! empty( $out['ok'] ),
			'msg'   => isset( $out['msg'] ) ? $out['msg'] : '',
		);
	}

	if ( ! $results ) {
		return array(
			'results' => array(),
			'failed'  => true,
		);
	}

	$failed = false;
	foreach ( $results as $r ) {
		if ( ! $r['ok'] ) {
			$failed = true;
			break;
		}
	}

	return array(
		'results' => $results,
		'failed'  => $failed,
	);
}

/**
 * 站点状态检测（给后台页显示）。
 *
 * @return array
 */
function as_init_status() {
	$out = array();

	// 页面
	$page_defs = as_init_page_defs();
	$missing   = array();
	foreach ( $page_defs as $def ) {
		if ( ! as_init_find_page( $def['slug'] ) ) {
			$missing[] = $def['title'];
		}
	}
	$out[] = array(
		'label' => '必要页面',
		'ok'    => empty( $missing ),
		'text'  => empty( $missing ) ? '齐全' : ( '缺少 ' . implode( '、', $missing ) ),
	);

	// 菜单
	$locs = get_theme_mod( 'nav_menu_locations', array() );
	$has_primary = ! empty( $locs['primary'] ) && get_term( (int) $locs['primary'], 'nav_menu' );
	$has_footer  = ! empty( $locs['footer'] ) && get_term( (int) $locs['footer'], 'nav_menu' );
	$out[] = array(
		'label' => '导航菜单',
		'ok'    => $has_primary && $has_footer,
		'text'  => ( $has_primary ? '主导航已挂载' : '主导航未挂载' ) . '，' . ( $has_footer ? '底部导航已挂载' : '底部导航未挂载' ),
	);

	// 固定链接
	$perm = (string) get_option( 'permalink_structure' );
	$out[] = array(
		'label' => '固定链接',
		'ok'    => '' !== $perm,
		'text'  => '' === $perm ? '还是默认（/?p=123），商品页会 404' : $perm,
	);

	// 站点信息
	$out[] = array(
		'label' => '站点信息',
		'ok'    => '' !== (string) get_option( 'blogdescription' ),
		'text'  => '' !== (string) get_option( 'blogdescription' ) ? '已填写' : '副标题为空',
	);

	// 服务说明标签
	$cfg = get_option( 'as_mini_config', array() );
	$tags = ( is_array( $cfg ) && ! empty( $cfg['service_tags'] ) ) ? $cfg['service_tags'] : '';
	$out[] = array(
		'label' => '服务说明标签',
		'ok'    => '' !== (string) $tags,
		'text'  => '' !== (string) $tags ? (string) $tags : '未设置',
	);

	return $out;
}
