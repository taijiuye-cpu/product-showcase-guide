<?php
/**
 * 网站设置 —— 后台菜单形式的站点设置页。
 *
 * 为什么要做这个（v2.52.0）：
 * 原来网站侧的设置全在「外观 → 自定义」里，跟「小程序商城」菜单分属两个地方，
 * 找设置要来回切。现在统一搬到后台菜单，所有设置都能在一个地方找到。
 *
 * 关键设计：
 * 1. **数据零迁移**——仍然读写 theme_mod（as_icp_number / as_seo_* / as_qr_* …），
 *    同一个键、同一个存储位置。**用户之前在「外观→自定义」填过的值全部还在**，
 *    不会出现"升级后设置没了"。只是换了个编辑入口。
 *
 * 2. **外观自定义里同步隐藏这些项**，避免两个地方都能改、造成困惑
 *    （改了一处以另一处为准，是最难查的问题）。但设置项本身仍然注册，
 *    这样通过 WP API 读写 theme_mod 的老代码路径不受影响。
 *
 * 3. 图片类字段用 Media Library 弹窗（as_media_picker JS + 媒体按钮），
 *    不再依赖 Customizer 的 WP_Customize_Image_Control。
 *
 * @package product-showcase-guide
 */

defined( 'ABSPATH' ) || exit;

/* ==========================================================================
 * 菜单
 * ======================================================================== */

/**
 * 「主题设置」菜单的子页定义。
 *
 * slug => [标题, 菜单位置, 说明]
 *
 * 「网站与小程序共用」原是独立一级菜单（as-shared），v2.53.0 并入这里——
 * 它管的本来就是主题层面同时影响网站与小程序的东西，放一起更符合直觉。
 *
 * @return array
 */
function as_site_settings_tabs() {
	return array(
		'seo'    => array(
			'title' => 'SEO 优化',
			'menu'  => 'SEO 优化',
			'desc'  => '网站前端（搜索引擎与社交分享卡片）的三要素。留空的项前台会走自动生成，不会输出空标签。',
		),
		'footer' => array(
			'title' => '站点信息与页脚',
			'menu'  => '站点信息',
			'desc'  => '页脚显示的备案号、联系方式与合规提示。留空则前台自动隐藏对应行。',
		),
		'qr'     => array(
			'title' => '关注我们（二维码）',
			'menu'  => '关注我们',
			'desc'  => '上传二维码图片后，会在页面底部、页脚上方显示「关注我们」区块。没有上传图片的卡片不会出现，三张都不传则整个区块自动隐藏。建议使用不小于 300×300 的正方形图片。',
		),
		'media'  => array(
			'title' => '媒体上传',
			'menu'  => '媒体上传',
			'desc'  => '控制上传图片的文件命名方式。',
		),
		'style'  => array(
			'title' => '外观风格',
			'menu'  => '外观风格',
			'desc'  => '切换首页与商品列表页的版式。两种风格用的商品、价格、模块完全一样，只换排版和配色，随时可以切回来。',
		),
		'read'   => array(
			'title' => '阅读设置',
			'menu'  => '阅读设置',
			'desc'  => '首页文章区（精选阅读 / 选购指南）显示多少篇、从哪些文章里选。两种首页风格共用这一份设置。',
		),
		'shared' => array(
			'title' => '通用设置',
			'menu'  => '通用设置',
			'desc'  => '站点通用设置：服务说明标签、首页优惠模块显示数量等。',
		),
	);
}

function as_site_settings_menu() {
	add_menu_page(
		'主题设置',
		'主题设置',
		'manage_options',
		'as-site-seo',
		'as_site_settings_page',
		'dashicons-admin-appearance',
		57
	);

	$slugs = array(
		'seo'    => 'as-site-seo',
		'footer' => 'as-site-footer',
		'qr'     => 'as-site-qr',
		'media'  => 'as-site-media',
		'style'  => 'as-site-style',
		'read'   => 'as-site-read',
		'shared' => 'as-shared',
	);

	foreach ( as_site_settings_tabs() as $tab => $meta ) {
		add_submenu_page(
			'as-site-seo',
			$meta['title'],
			$meta['menu'],
			'manage_options',
			$slugs[ $tab ],
			// 共用设置的原页面回调在 miniapp-admin.php，这里仍用主题设置的页
			'as_site_settings_page'
		);
	}

	/*
	 * 演示数据导入（原独立一级菜单 as-demo-import）并入主题设置。
	 * 仍然用as_demo_import_page 回调 —— 它要处理导入动作和 nonce，
	 * 与主题设置的保存机制完全不同，不要合并进同一套表单逻辑。
	 * 回调里已加 `if ('as-demo-import' !== $_GET['page']) return;` 守卫，
	 * 这样挂到子菜单上也能正常渲染。
	 */
	add_submenu_page(
		'as-site-seo',
		'演示数据导入',
		'演示数据导入',
		'manage_options',
		'as-demo-import',
		'as_demo_import_page'
	);
}
add_action( 'admin_menu', 'as_site_settings_menu' );

/**
 * 演示数据导入原来的独立菜单已并入主题设置，这里取消它。
 *
 * 只取消「一级菜单」（add_menu_page）；它作为主题设置的子菜单在
 * as_site_settings_menu() 里注册，所以仍然可用。
 */
function as_demo_remove_own_menu() {
	remove_menu_page( 'as-demo-import' );
}
add_action( 'admin_menu', 'as_demo_remove_own_menu', 999 );

/**
 * 从当前请求的 page slug 反推出 tab 名。
 *
 * @param string $page_slug $_GET['page']。
 * @return string
 */
function as_site_settings_current_tab( $page_slug ) {
	$map = array(
		'as-site-seo'    => 'seo',
		'as-site-footer' => 'footer',
		'as-site-qr'     => 'qr',
		'as-site-media'  => 'media',
		'as-site-style'  => 'style',
		'as-site-read'   => 'read',
		'as-shared'      => 'shared',
	);
	return isset( $map[ $page_slug ] ) ? $map[ $page_slug ] : 'seo';
}

/**
 * 旧菜单 slug → 新位置的跳转。
 *
 * v2.53.0 把「共用设置」并入主题设置、把「演示数据导入」也挂成子菜单后，
 * 用户浏览器里收藏的旧链接（admin.php?page=as-shared 等）仍在。
 * 不做跳转的话那些链接会直接报「您无权访问此页面」，看着像功能坏了。
 *
 * 这里在 admin_init 阶段拦截并跳转，避免渲染出一个空表单。
 */
function as_site_settings_redirect_legacy() {
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	if ( ! $page ) {
		return;
	}

	$map = array(
		// 「共用设置」旧的一级菜单 → 主题设置 → 网站与小程序共用
		'as-shared'  => 'as-shared',
		// 旧的「网站设置」页名 → 主题设置 → SEO 优化
		'as-site'    => 'as-site-seo',
	);
	if ( isset( $map[ $page ] ) && $map[ $page ] !== $page ) {
		wp_safe_redirect( admin_url( 'admin.php?page=' . $map[ $page ] ) );
		exit;
	}
}
add_action( 'admin_init', 'as_site_settings_redirect_legacy' );

/* ==========================================================================
 * 保存
 * ======================================================================== */

/**
 * 各 tab 的字段定义 —— 保存与渲染共用一份，避免两边漂移。
 *
 * type: text | textarea | url | email | checkbox | color | media
 *
 * @param string $tab 当前 tab。
 * @return array
 */
function as_site_settings_fields( $tab ) {
	$media_on = array( 'as_rename_uploads' );

	switch ( $tab ) {
		case 'seo':
			/*
			 * SEO 设置（v2.87.52）。
			 *
			 * 🔴 之前这一页是**空的**：代码里早就读了 `as_home_description`
			 *    （as_get_meta_description() 的首页分支），但后台没有输入框 →
			 *    用户根本没法填，等于那个设置项一直是死的。
			 *    本次补齐首页三要素 + 全站兜底 + 分享图 + keywords 开关。
			 *
			 * 存储统一用 theme_mod（和 inc/seo.php 的 get_theme_mod 对得上）。
			 * ⚠️ 「输出 keywords 标签」是 checkbox，未勾选时 POST 里没这个键，
			 *    保存循环会显式写 false —— 不会出现"关不掉"。
			 */
			return array(
				'as_home_title'       => array(
					'label'       => '首页 SEO 标题',
					'type'        => 'text',
					'input_value' => '',
					'desc'        => '留空则用「站点名 - 站点副标题」。建议 20-30 字，包含主关键词，如「成人用品选购指南_延时喷剂怎么挑_大人好物」。',
				),
				'as_home_description' => array(
					'label'       => '首页 SEO 描述',
					'type'        => 'textarea',
					'input_value' => '',
					'desc'        => '建议 80-120 字，说清站点提供什么（延时喷剂 / 增敏 / 护理 / 情趣玩具）+ 特色（参数真实、不刷单）。<b>这段会原样作为首页的 meta description 输出</b>。',
				),
				'as_home_keywords'    => array(
					'label'       => '首页 SEO 关键词',
					'type'        => 'text',
					'input_value' => '',
					'desc'        => '半角逗号分隔，如：成人用品,延时喷剂,情趣内衣。中英文逗号都认，会自动规整。<b>只作用于首页</b>；其它页面自动按内容拼关键词。',
				),
				'as_seo_description'  => array(
					'label'       => '全站兜底描述',
					'type'        => 'textarea',
					'input_value' => '',
					'desc'        => '约 60-120 字。作用范围：分类页、品牌页等<b>算不出自身描述</b>的页面会退回用这段文案。不会覆盖文章自己的摘要。',
				),
				'as_seo_keywords'     => array(
					'label'       => '全站兜底关键词',
					'type'        => 'text',
					'input_value' => '',
					'desc'        => '半角逗号分隔。会追加到所有页面关键词的末尾（权重最低），同时也在「输出 keywords 标签」打开时作为兜底。',
				),
				'as_seo_og_image'     => array(
					'label'       => '社交分享图（og:image）',
					'type'        => 'url',
					'input_value' => '',
					'desc'        => '微信、微博、Facebook 分享时显示的封面图。留空则优先用文章特色图，其次用站点 Logo。建议 1200×630、5:2 左右。',
				),
				'as_seo_output_keywords' => array(
					'label'       => '输出 keywords 标签',
					'type'        => 'checkbox',
					'input_value' => '',
					'desc'        => '默认关闭。Google 2009 年已弃用该标签、百度权重也极低，留空不输出更干净；勾选后按页面类型自动拼（商品页=分类+品牌，文章页=分类+标签，首页=上面填的首页关键词）。',
				),
			);

		case 'footer':
			return array(
				/*
				 * 🔴 网站 Logo（v2.87.59）
				 *
				 * 这一项**不是普通 theme_mod**，它直接读写 WP 核心的 `custom_logo`：
				 *   · WP 的 `has_custom_logo()` / `the_custom_logo()` 读的就是这个键；
				 *   · 「外观 → 自定义 → 站点标志」也是写这个键。
				 * ⇒ 两边天然同步：在本页上传，自定义器里立刻能看到；
				 *   在自定义器里换了，本页刷新也显示新图。不会各存一份。
				 *
				 * 存储用**附件 ID**（不是图片 URL）—— WP 核心就是用 ID，
				 * 用 URL 的话换图后旧图残留在媒体库、还拿不到 alt 文本。
				 */
				'custom_logo'      => array(
					'label'       => '网站 Logo',
					'type'        => 'logo',
					'desc'        => '显示在页头左上角（替换站名文字）和页脚。留空则显示站名文字。<b>推荐 200 × 60 像素</b>（横向长方形），透明背景 PNG；上传后会自动等比缩放，超宽或超高的图也不会撑破页头。同一张图也会用于社交分享的默认封面。',
				),
				'as_icp_number'    => array(
					'label' => 'ICP 备案号',
					'type'  => 'text',
				),
				'as_icp_url'       => array(
					'label'       => '备案查询链接',
					'type'        => 'url',
					'input_value' => 'https://beian.miit.gov.cn/',
					'desc'        => '页脚「备案号」旁的查询链接。',
				),
				'as_contact_mail'  => array(
					'label' => '联系邮箱',
					'type'  => 'email',
					'desc'  => '页脚显示的联系邮箱，留空则不显示。',
				),
				'as_work_hours'    => array(
					'label'       => '工作时间',
					'type'        => 'text',
					'input_value' => '周一至周五 9:00-18:00',
				),
				'as_wechat'        => array(
					'label' => '微信链接',
					'type'  => 'url',
				),
				'as_weibo'         => array(
					'label' => '微博链接',
					'type'  => 'url',
				),
				'as_footer_notice' => array(
					'label' => '页脚合规提示',
					'type'  => 'textarea',
					'rows'  => 4,
					'desc'  => '留空则使用默认的合规提示文案。',
				),
			);

		case 'qr':
			$fields = array(
				'as_qr_section_title' => array(
					'label'       => '区块标题',
					'type'        => 'text',
					'input_value' => '关注我们',
					'desc'        => '留空则只显示卡片、不显示标题。',
				),
			);
			foreach ( as_qr_cards_config() as $index => $defaults ) {
				$prefix = 'as_qr_' . $index . '_';

				$fields[ $prefix . 'img' ] = array(
					'label' => sprintf( '卡片 %d · 二维码图片', $index ),
					'type'  => 'media',
					'desc'  => sprintf( '默认「%s」。从媒体库选择或直接上传，建议白底、四周留白，扫码识别率更高。', $defaults['name'] ),
				);
				$fields[ $prefix . 'title' ] = array(
					'label'       => sprintf( '卡片 %d · 标题', $index ),
					'type'        => 'text',
					'input_value' => $defaults['name'],
					'desc'        => '显示在二维码下方。留空则不显示。',
				);
				$fields[ $prefix . 'btn' ] = array(
					'label'       => sprintf( '卡片 %d · 按钮文字', $index ),
					'type'        => 'text',
					'input_value' => $defaults['btn'],
					'desc'        => '留空则不显示按钮。',
				);
				$fields[ $prefix . 'url' ] = array(
					'label' => sprintf( '卡片 %d · 按钮链接', $index ),
					'type'  => 'url',
					'desc'  => '留空则按钮只作展示、不可点击（扫码类卡片一般留空）。要能点击请填完整地址。',
				);
				$fields[ $prefix . 'color' ] = array(
					'label'       => sprintf( '卡片 %d · 按钮颜色', $index ),
					'type'        => 'color',
					'input_value' => $defaults['color'],
				);
			}
			return $fields;

		case 'shared':
			/*
			 * 这一组是**网站与小程序同时生效**的配置，但存的是 as_mini_config
			 * option（不是 theme_mod）—— 小程序侧 as_mini_opt() 会先看 wp-config
			 * 常量、再看这个 option。前台与小程序读取逻辑一行都不用改，
			 * 这里只负责在后台编辑它。
			 *
			 * 存储适配见下方 as_site_settings_shared_save()。
			 */
			return array(
				'service_tags'   => array(
					'label'       => '服务说明标签',
					'type'        => 'text',
					'source'      => 'option',
					'opt_key'     => 'service_tags',
					'input_value' => '',
					'desc'        => '所有商品共用的服务说明标签，显示在商品详情页顶部（网站 + 小程序同一份）。用空格、顿号或逗号分隔，前台自动拆成一排标签，例如「隐私发货 正品保证 顺丰包邮」。留空则不显示。',
				),
			'flash_count'    => array(
				'label'       => '限时特惠显示几个商品',
				'type'        => 'select',
				'source'      => 'option',
				'opt_key'     => 'flash_count',
				'options'     => array(
					'2' => '2 个',
					'4' => '4 个',
					'6' => '6 个',
					'8' => '8 个',
				),
				'input_value' => '4',
				'desc'        => '首页「限时特惠」区块最多显示几个商品。商品要出现在那里需在「商品列表页」勾选「首页抢购」。',
			),
			'flash_end_at'   => array(
				'label'       => '限时活动结束时间',
				'type'        => 'text',
				'source'      => 'option',
				'opt_key'     => 'flash_end_at',
				'input_value' => '',
				'desc'        => '填了就在「限时特惠」区块显示倒计时。格式 <code>2026-12-31 23:59:59</code>；留空则不显示倒计时。',
			),
		);

		case 'read':
			return array(
				'as_home_articles_count' => array(
					'label'       => '首页文章显示几篇',
					'type'        => 'text',
					'input_value' => '6',
					'desc'        => '首页「精选阅读 / 选购指南」那一块显示多少篇文章，<b>0 = 不显示这块</b>，最多 30 篇。两种首页风格（货架 / 橱窗）都按这个数字走。',
				),
				'as_home_articles_mode'  => array(
					'label'       => '文章来源',
					'type'        => 'select',
					'options'     => array(
						'category' => '覆盖全部分类（每个分类轮流取，推荐更均衡）',
						'latest'   => '按发布时间取最新',
					),
					'input_value' => 'category',
					'desc'        => '<b>覆盖全部分类</b>：从每个分类里各取几篇，凑够上面设定的篇数 —— 推荐区不会全是最近那一个分类的文章。<br><b>按发布时间取最新</b>：老规矩，最新的 N 篇。',
				),
			);

		case 'style':
			return array(
				'as_home_style' => array(
					'label'       => '首页与商品列表页风格',
					'type'        => 'select',
					'options'     => array(
						'shelf'    => '货架风格（默认 · 商品密集，适合找货比价）',
						'showcase' => '橱窗风格（品牌大屏 · 大图留白，适合讲故事拉新）',
					),
					'input_value' => 'shelf',
					'desc'        => '「货架」= 现在的样子：各区块一样重、商品卡小而密，找东西最快。<br>「橱窗」= 品牌大屏：首页顶部一句主张 + 大图 + 数据带，模块之间用深浅色做节奏，适合讲故事、拉新客。<br>两种风格的<b>商品、价格、券、会员价、购物车、支付完全一样</b>，只是排版不同；顶部导航、底部、商品详情页不受影响。',
				),
			);

		case 'media':
			return array(
				'as_rename_uploads' => array(
					'label' => '上传图片自动重命名为时间戳',
					'type'  => 'checkbox',
					'desc'  => '把上传的图片文件名改成时间戳（如 1759456789.jpg），避免中文产品名出现在图片地址里。原始文件名会保留为附件标题，媒体库仍然可读。只对新上传的图片生效。',
				),
			);
	}

	// SEO —— 与 inc/seo.php 的设置项一致（同一批 theme_mod 键）
	return array(
		'as_seo_description' => array(
			'label' => '全局 SEO 描述',
			'type'  => 'textarea',
			'rows'  => 4,
			'desc'  => '约 60-120 字。作用范围：文章页/分类页等所有「算不出自身描述」的页面会退回用这段文案，属于兜底，不会覆盖文章自己的摘要。',
		),
		'as_seo_keywords'    => array(
			'label' => '全局 SEO 关键词',
			'type'  => 'text',
			'desc'  => '半角逗号分隔，如：成人用品,情趣内衣,私密护理。中英文逗号都认，会自动规整。留空则不输出 keywords。',
		),
		'as_seo_og_image'    => array(
			'label' => '社交分享图（og:image）',
			'type'  => 'media',
			'desc'  => '微信、微博、Facebook 分享时显示的封面图。留空则优先用文章特色图，其次用站点 Logo。建议 1200×630。',
		),
		'as_home_description' => array(
			'label' => '首页 SEO 描述',
			'type'  => 'textarea',
			'rows'  => 4,
			'desc'  => '会作为首页的 meta description 和 og:description 输出，建议 60-120 字。留空则用「站名：副标题」。优先级高于上面的全局描述。',
		),
		'as_seo_output_keywords' => array(
			'label' => '输出 keywords 标签',
			'type'  => 'checkbox',
			'desc'  => '默认关闭。Google 2009 年已弃用该标签、百度权重也极低，留空不输出更干净；勾选后会按页面类型自动拼（商品页=分类+品牌，文章页=分类+标签）。',
		),
	);
}

/**
 * 字段的清洗回调。
 *
 * @param array $field 字段定义。
 * @return callable
 */
function as_site_settings_sanitizer( $field ) {
	switch ( $field['type'] ) {
		case 'url':
		case 'media':
			return 'esc_url_raw';
		case 'logo':
			// 附件 ID：正整数 = 已选图，0 = 未选
			return 'absint';
		case 'email':
			return 'sanitize_email';
		case 'textarea':
			return 'sanitize_textarea_field';
		case 'color':
			return function_exists( 'as_sanitize_hex_color' ) ? 'as_sanitize_hex_color' : 'sanitize_hex_color';
		case 'checkbox':
			return function_exists( 'as_sanitize_checkbox' ) ? 'as_sanitize_checkbox' : 'sanitize_text_field';
		default:
			return 'sanitize_text_field';
	}
}

/**
 * 读字段当前值。
 *
 * 两类来源：
 *  - theme_mod（默认）：纯网站设置
 *  - option（source=option）：读 as_mini_config，用 as_mini_opt() 而非直接
 *    get_option()，因为 as_mini_opt() 会优先返回 wp-config 里定义的常量——
 *    若直接读 option，用户用常量配的值会在界面上显示成"没配"，误导。
 *
 * @param string $key   字段键（option 源时是表单键名）。
 * @param array  $field 字段定义。
 * @return mixed
 */
function as_site_settings_value( $key, $field ) {
	$default = isset( $field['input_value'] ) ? $field['input_value'] : '';

	if ( isset( $field['source'] ) && 'option' === $field['source'] ) {
		// as_mini_opt() 有常量优先级，且读 wx-jump 等键都走它，保持一致
		return as_mini_opt( $field['opt_key'] );
	}

	return get_theme_mod( $key, $default );
}

/* ==========================================================================
 * 页面
 * ======================================================================== */

/**
 * 网站设置页。
 *
 * 保存与小程序那边是同样的思路：**只覆盖本页提交的字段，其余原样保留**。
 * theme_mod 本来就是"键 → 值"独立存储，不会互相牵连，但仍然按字段逐个写，
 * 这样未勾选的 checkbox 也能正确置 false（否则永远关不掉）。
 */
function as_site_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '无权访问' );
	}

	$page_slug = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'as-site-seo';
	$tab       = as_site_settings_current_tab( $page_slug );
	$tabs      = as_site_settings_tabs();
	$meta      = $tabs[ $tab ];
	$fields    = as_site_settings_fields( $tab );

	$saved = false;

	if ( isset( $_POST['as_site_save'] ) && check_admin_referer( 'as_site_settings' ) ) {
		/*
		 * 两类字段分别处理（v2.53.0）：
		 *  - source 缺省 → theme_mod（纯网站设置）
		 *  - source = option → as_mini_config（网站与小程序共用）
		 *
		 * 两套存储不能混写，各自只覆盖自己的键。
		 * option 那边尤其要小心：它和「小程序商城」各页共用同一个 option，
		 * 覆盖前先读旧值，改完只写回本页的键，别把别页的配置抹掉。
		 */
		$opt_before = get_option( 'as_mini_config', array() );
		$opt_before = is_array( $opt_before ) ? $opt_before : array();
		$opt_after  = $opt_before;

		foreach ( $fields as $key => $field ) {
			$is_option = isset( $field['source'] ) && 'option' === $field['source'];
			$opt_key   = $is_option ? $field['opt_key'] : $key;

			if ( 'checkbox' === $field['type'] ) {
				// 未勾选时 POST 里没有该字段，必须显式置 false（否则永远关不掉）
				$val = ! empty( $_POST[ $opt_key ] );
			} elseif ( 'logo' === $field['type'] ) {
				/*
				 * 🔴 网站 Logo（v2.87.59）：存附件 ID。
				 *
				 * 必须校验附件真实存在且是图片 —— 否则前台 `the_custom_logo()`
				 * 会输出一个空 img 或直接报错（WP 的 get_custom_logo 对无效 ID
				 * 会 return 空字符串，但 has_custom_logo() 仍为 true → 页头 logo 位空白）。
				 */
				$logo_id = isset( $_POST[ $opt_key ] ) ? absint( wp_unslash( $_POST[ $opt_key ] ) ) : 0;
				if ( $logo_id && ! wp_attachment_is_image( $logo_id ) ) {
					$logo_id = 0;
				}
				$val = $logo_id;
			} else {
				$raw = wp_unslash( $_POST[ $opt_key ] ?? '' );
				$val = call_user_func( as_site_settings_sanitizer( $field ), $raw );
			}

			// 空值且定义了默认值 → 写回默认值，避免"清空了但显示还是默认"的不一致
			if ( '' === $val && isset( $field['input_value'] ) ) {
				$val = $field['input_value'];
			}

			if ( $is_option ) {
				$opt_after[ $opt_key ] = $val;
			} else {
				/*
				 * Logo 移除时用 remove_theme_mod（而不是写 0）：
				 * WP 的 has_custom_logo() 判的是 `get_theme_mod('custom_logo')` 非空，
				 * 写 0 也是 falsy 能过，但自定义器那边读的是「键是否存在」，
				 * remove 更干净 —— 移除后自定义器里会回到「未设置」状态。
				 */
				if ( 'logo' === $field['type'] ) {
					if ( $val ) {
						set_theme_mod( $key, $val );
					} else {
						remove_theme_mod( $key );
					}
					continue;
				}
				set_theme_mod( $key, $val );
			}
		}

/*
		 * 首页广告区（v2.75.0）：结构是个**数组**，走不了上面那个一维字段循环，
		 * 这里单独收一条、清洗、再写回 `home_ads` 键。
		 *
		 * ⚠️ 只在 shared 这一页处理 —— 别的 tab 提交时不带这段表单，
		 *    若不加判断会把 home_ads 冲成空数组（等于把广告全清了）。
		 */
		if ( 'shared' === $tab && isset( $_POST['as_home_ads'] ) && is_array( $_POST['as_home_ads'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- 下面逐项清洗
			$ads_raw = wp_unslash( $_POST['as_home_ads'] );
			$ads     = array();

			foreach ( $ads_raw as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$img  = isset( $row['img'] ) ? esc_url_raw( trim( (string) $row['img'] ) ) : '';
				$type = isset( $row['type'] ) ? sanitize_key( (string) $row['type'] ) : '';
				$id   = isset( $row['target_id'] ) ? absint( $row['target_id'] ) : 0;

				// 只留「类型合法 + 有目标 ID」的条目；图片可后补，
				// 但没图没目标的半截配置直接丢掉（前端渲染时也会再过滤一遍）
				if ( ! in_array( $type, array( 'product', 'post' ), true ) || 0 === $id ) {
					continue;
				}
				$ads[] = array(
					'img'       => $img,
					'type'      => $type,
					'target_id' => $id,
				);

				if ( count( $ads ) >= as_home_ads_max() ) {
					break;
				}
			}

			$opt_after['home_ads'] = $ads;
		}

		if ( $opt_after !== $opt_before ) {
			update_option( 'as_mini_config', $opt_after );
		}
		$saved = true;
	}

	wp_enqueue_media();
	?>
	<div class="wrap">
		<h1><?php echo esc_html( $meta['title'] ); ?></h1>
		<p class="description" style="max-width:780px;"><?php echo wp_kses_post( $meta['desc'] ); ?></p>

		<?php if ( $saved ) : ?>
			<div class="notice notice-success is-dismissible"><p>已保存。</p></div>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( 'as_site_settings' ); ?>
			<input type="hidden" name="as_site_save" value="1">
			<table class="form-table" role="presentation">
				<?php foreach ( $fields as $key => $field ) : ?>
					<?php
					// option 源的表单 name 要用 opt_key（真正落在 as_mini_config 里的键），
					// 但 label/id 仍用 $key（它才是这页的字段标识）。
					$name_key = ( isset( $field['source'] ) && 'option' === $field['source'] )
						? $field['opt_key']
						: $key;
					$val = as_site_settings_value( $key, $field );
					?>
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( $name_key ); ?>">
								<?php echo esc_html( $field['label'] ); ?>
							</label>
						</th>
						<td>
							<?php as_site_settings_render_field( $name_key, $field, $val ); ?>
							<?php if ( ! empty( $field['desc'] ) ) : ?>
								<p class="description"><?php echo wp_kses_post( $field['desc'] ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>

			<?php
			// 广告区是数组结构，单独一块（不属于 form-table 的一维字段）
			if ( 'shared' === $tab ) {
				as_site_settings_home_ads_block();
			}
			?>
			<?php submit_button( '保存设置', 'primary' ); ?>
		</form>

		<p class="description" style="margin-top:16px;">
			这些设置只影响<b>网站前台</b>。
		</p>
	</div>
	<?php
}

/**
 * 渲染单个字段。
 *
 * @param string $key   theme_mod 键。
 * @param array  $field 字段定义。
 * @param mixed  $val   当前值。
 */
function as_site_settings_render_field( $key, $field, $val ) {
	switch ( $field['type'] ) {
		case 'checkbox':
			/*
			 * label 已经在左侧 th 里输出了，这里只留一个不带文字的勾选框，
			 * 否则「上传图片自动重命名为时间戳」会在一行里出现两遍。
			 */
			?>
			<input type="checkbox" name="<?php echo esc_attr( $key ); ?>"
				id="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( (bool) $val ); ?>>
			<?php
			break;

		case 'textarea':
			?>
			<textarea name="<?php echo esc_attr( $key ); ?>"
				id="<?php echo esc_attr( $key ); ?>"
				rows="<?php echo isset( $field['rows'] ) ? (int) $field['rows'] : 4; ?>"
				class="large-text"><?php echo esc_textarea( (string) $val ); ?></textarea>
			<?php
			break;

		case 'select':
			?>
			<select name="<?php echo esc_attr( $key ); ?>" id="<?php echo esc_attr( $key ); ?>">
				<?php foreach ( $field['options'] as $oval => $otext ) : ?>
					<option value="<?php echo esc_attr( $oval ); ?>" <?php selected( (string) $oval === (string) $val ); ?>>
						<?php echo esc_html( $otext ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php
			break;

		case 'media':
			?>
			<input type="url" name="<?php echo esc_attr( $key ); ?>"
				id="<?php echo esc_attr( $key ); ?>"
				value="<?php echo esc_attr( (string) $val ); ?>"
				class="large-text" placeholder="留空则自动">
			<p>
				<button type="button" class="button as-pick-img"
					data-target="<?php echo esc_attr( $key ); ?>">从媒体库选择</button>
				<?php if ( ! empty( $val ) ) : ?>
					<img src="<?php echo esc_url( (string) $val ); ?>" alt=""
						style="max-height:80px;vertical-align:middle;margin-left:8px;border:1px solid #dcdcde;border-radius:4px;">
				<?php endif; ?>
			</p>
			<?php
			break;

		case 'logo':
			/*
			 * 网站 Logo 选择器（v2.87.59）
			 *
			 * 与普通 media 字段的差别：
			 *   · 存的是**附件 ID**（WP 核心 custom_logo 要的就是 ID）；
			 *   · 有缩略图预览 + 「移除」按钮；
			 *   · 复用 .as-pick-img 的媒体库弹窗，但要从弹窗里取 **ID** 而不是 URL
			 *     → 给按钮加 data-mode="id"，media-picker.js 会按这个分支写值。
			 */
			$as_logo_id  = absint( $val );
			$as_logo_url = $as_logo_id ? wp_get_attachment_image_url( $as_logo_id, 'medium' ) : '';
			$as_logo_full = $as_logo_id ? wp_get_attachment_image_url( $as_logo_id, 'full' ) : '';
			$as_logo_dim = '';
			if ( $as_logo_id ) {
				$as_logo_meta = wp_get_attachment_metadata( $as_logo_id );
				if ( is_array( $as_logo_meta ) && ! empty( $as_logo_meta['width'] ) && ! empty( $as_logo_meta['height'] ) ) {
					$as_logo_dim = sprintf( '%d × %d', (int) $as_logo_meta['width'], (int) $as_logo_meta['height'] );
				}
			}
			?>
			<input type="hidden" name="<?php echo esc_attr( $key ); ?>"
				id="<?php echo esc_attr( $key ); ?>"
				value="<?php echo $as_logo_id ? esc_attr( $as_logo_id ) : ''; ?>">
			<div class="as-logo-picker">
				<div class="as-logo-picker-prev<?php echo $as_logo_url ? '' : ' is-empty'; ?>">
					<?php if ( $as_logo_url ) : ?>
						<img src="<?php echo esc_url( $as_logo_url ); ?>" alt=""
							style="max-height:56px;max-width:220px;background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:4px;">
					<?php else : ?>
						<span style="color:#8c8f94;">未设置 Logo</span>
					<?php endif; ?>
				</div>
				<div class="as-logo-picker-ops">
					<button type="button" class="button as-pick-img"
						data-target="<?php echo esc_attr( $key ); ?>"
						data-mode="id"><?php echo $as_logo_id ? '更换 Logo' : '从媒体库选择'; ?></button>
					<?php if ( $as_logo_id ) : ?>
						<button type="button" class="button button-link-delete as-logo-remove"
							data-target="<?php echo esc_attr( $key ); ?>">移除</button>
						<span class="description" style="display:block;margin-top:6px;">
							当前图片：<?php echo esc_html( $as_logo_dim ? $as_logo_dim . ' 像素' : '尺寸未知' ); ?>
							<?php echo $as_logo_full ? '<br>' . esc_html( basename( $as_logo_full ) ) : ''; ?>
						</span>
					<?php endif; ?>
				</div>
			</div>
			<?php
			break;

		case 'color':
			?>
			<input type="text" name="<?php echo esc_attr( $key ); ?>"
				id="<?php echo esc_attr( $key ); ?>"
				value="<?php echo esc_attr( (string) $val ); ?>"
				class="as-color-field" data-default="<?php echo esc_attr( isset( $field['input_value'] ) ? $field['input_value'] : '' ); ?>">
			<button type="button" class="button as-pick-color"
				data-target="<?php echo esc_attr( $key ); ?>"
				data-default="<?php echo esc_attr( isset( $field['input_value'] ) ? $field['input_value'] : '' ); ?>">选择颜色</button>
			<?php
			break;

		default:
			$type = in_array( $field['type'], array( 'url', 'email' ), true ) ? $field['type'] : 'text';
			?>
			<input type="<?php echo esc_attr( $type ); ?>"
				name="<?php echo esc_attr( $key ); ?>"
				id="<?php echo esc_attr( $key ); ?>"
				value="<?php echo esc_attr( (string) $val ); ?>"
				class="regular-text">
			<?php
			break;
	}
}

/* ==========================================================================
 * 首页广告区（v2.75.0）—— 后台配置块
 * ======================================================================== */

/**
 * 渲染「首页广告区」配置块（只在「网站与小程序共用」这一页出现）。
 *
 * 每条广告三件事：**图**、**跳哪类**（商品/文章）、**跳哪个**（关键词搜索选）。
 *
 * 为什么不用一维字段循环：广告是**一组**（最多 6 条），每条三个值，
 * 用 `as_home_ads[0][img]` 这种数组名提交最直白，保存端单独收（见上面保存分支）。
 *
 * 「跳哪个」用关键词搜索：商品和文章都可能有几百条，下拉框会卡死且不好找，
 * 输入关键词 → AJAX 搜索 → 点结果填入 ID，并回显标题让用户确认没选错。
 */
function as_site_settings_home_ads_block() {
	$max = function_exists( 'as_home_ads_max' ) ? as_home_ads_max() : 6;
	$raw = function_exists( 'as_mini_opt' ) ? as_mini_opt( 'home_ads' ) : array();
	$ads = is_array( $raw ) ? $raw : array();

	// 补到至少 1 行，让页面上有可填的入口
	if ( empty( $ads ) ) {
		$ads = array( array( 'img' => '', 'type' => 'product', 'target_id' => 0 ) );
	}
	?>
	<hr style="margin:24px 0 16px;">
	<h2 style="margin-bottom:4px;">首页广告区</h2>
	<p class="description" style="max-width:780px;">
		配置后，<b>网站首页顶部</b>与<b>小程序首页</b>都会显示这组图片，点击跳转到指定商品或文章。
		横向自动滑动，最多 <?php echo (int) $max; ?> 张。留空图片或不选目标则该条不显示。
	</p>

	<table class="form-table" role="presentation" id="as-home-ads">
		<tbody>
		<?php foreach ( $ads as $i => $ad ) : ?>
			<?php
			$img  = isset( $ad['img'] ) ? (string) $ad['img'] : '';
			$type = isset( $ad['type'] ) ? (string) $ad['type'] : 'product';
			$tid  = isset( $ad['target_id'] ) ? (int) $ad['target_id'] : 0;

			// 回显目标标题，让用户确认没选错
			$title = '';
			if ( $tid ) {
				$p = get_post( $tid );
				if ( $p ) {
					$title = get_the_title( $p );
				}
			}
			?>
			<tr class="as-ad-row">
				<th scope="row" style="width:70px;padding-top:22px;">
					#<?php echo (int) ( $i + 1 ); ?>
				</th>
				<td>
					<div style="display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap;">
						<!-- 图 -->
						<div>
							<input type="url" class="regular-text"
								name="as_home_ads[<?php echo (int) $i; ?>][img]"
								id="as_ad_img_<?php echo (int) $i; ?>"
								value="<?php echo esc_attr( $img ); ?>"
								placeholder="图片地址">
							<p style="margin:4px 0 0;">
								<button type="button" class="button as-pick-img"
									data-target="as_ad_img_<?php echo (int) $i; ?>">从媒体库选择</button>
							</p>
							<?php if ( $img ) : ?>
								<img src="<?php echo esc_url( $img ); ?>" alt=""
									style="max-width:120px;max-height:60px;margin-top:6px;border:1px solid #dcdcde;border-radius:4px;">
							<?php endif; ?>
						</div>

						<!-- 类型 -->
						<div>
							<select name="as_home_ads[<?php echo (int) $i; ?>][type]"
								class="as-ad-type">
								<option value="product" <?php selected( $type, 'product' ); ?>>商品</option>
								<option value="post" <?php selected( $type, 'post' ); ?>>文章</option>
							</select>
							<p class="description" style="margin:4px 0 0;">跳转类型</p>
						</div>

						<!-- 目标：关键词搜索 -->
						<div style="min-width:280px;flex:1;">
							<input type="text" class="regular-text as-ad-search"
								placeholder="输入关键词搜索商品 / 文章…" autocomplete="off">
							<div class="as-ad-results" style="margin-top:4px;"></div>
							<input type="hidden" class="as-ad-target"
								name="as_home_ads[<?php echo (int) $i; ?>][target_id]"
								value="<?php echo esc_attr( (string) $tid ); ?>">
							<p class="description as-ad-picked" style="margin:4px 0 0;">
								<?php if ( $tid && $title ) : ?>
									✅ 已选：<?php echo esc_html( $title ); ?>（ID <?php echo (int) $tid; ?>）
								<?php else : ?>
									<span style="color:#9aa0a8;">未选择目标</span>
								<?php endif; ?>
							</p>
						</div>

						<button type="button" class="button as-ad-remove"
							style="margin-top:2px;">移除</button>
					</div>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<p>
		<button type="button" class="button" id="as-ad-add"
			data-max="<?php echo (int) $max; ?>">+ 添加一条广告</button>
		<span class="description">最多 <?php echo (int) $max; ?> 条</span>
	</p>
	<?php
}

/**
 * AJAX：按关键词搜索商品 / 文章，供广告区「跳哪个」使用。
 *
 * 只返回 **id + 标题 + 类型** 三项，够用且不会把正文等大字段带出去。
 */
function as_site_settings_ads_search() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => '无权操作' ), 403 );
	}
	check_ajax_referer( 'as_site_settings', 'nonce' );

	$kw   = isset( $_POST['kw'] ) ? sanitize_text_field( wp_unslash( $_POST['kw'] ) ) : '';
	$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'product';

	if ( '' === $kw || ! in_array( $type, array( 'product', 'post' ), true ) ) {
		wp_send_json_success( array() );
	}

	$args = array(
		'post_type'      => ( 'product' === $type ) ? 'plm_product' : 'post',
		'post_status'    => 'publish',
		'posts_per_page' => 10,
		's'              => $kw,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	);

	$ids  = get_posts( $args );
	$out  = array();
	foreach ( $ids as $id ) {
		$out[] = array(
			'id'    => (int) $id,
			'title' => get_the_title( $id ),
			'type'  => $type,
		);
	}

	wp_send_json_success( $out );
}
add_action( 'wp_ajax_as_ads_search', 'as_site_settings_ads_search' );

/* ==========================================================================
 * 资源
 * ======================================================================== */

/**
 * 本页需要的样式与脚本。
 *
 * 媒体库选择走 `assets/js/media-picker.js`（与「小程序商城」设置页**共用同一个文件**）。
 *
 * 🔴 v2.73.4 修 bug：原先这里只 `wp_enqueue_media()`、**没有加载任何初始化 JS**
 *（那段 `.as-pick-img` 代码内联在 miniapp-admin.php 的页面 <script> 里，
 * 只在该页渲染 —— 主题设置页按了「从媒体库选择」毫无反应）。
 * `wp_enqueue_media()` 只负责加载媒体弹窗的依赖，**不负责绑定按钮**。
 *
 * 🔴 v2.73.5 再修：守卫条件写成 `strpos( $hook, 'as-site-' )` —— 但「网站与小程序共用」
 * 那张子菜单的 slug 是 **`as-shared`**（见下方 $slugs 映射表，五个里只有它不带前缀），
 * WordPress 传给本函数的 $hook 是 `主题设置_page_as-shared`，**不含 `as-site-`**
 * → 被守卫拦掉 → 脚本仍不加载 → 「自定义小程序码」那栏点了还是没反应。
 * 现在改成按**真实 slug 列表**判断，不再靠前缀猜。
 */
function as_site_settings_assets( $hook ) {
	$slugs = array( 'as-site-seo', 'as-site-footer', 'as-site-qr', 'as-site-media', 'as-shared', 'as-demo-import' );
	$hit   = false;
	foreach ( $slugs as $slug ) {
		if ( false !== strpos( (string) $hook, $slug ) ) {
			$hit = true;
			break;
		}
	}
	/*
	 * 🔴 v2.84.0 修：原来是 `foreach(...){ if(命中) break; } if ( ! $slug ) return;` ——
	 *    `$slug` 是 foreach 的循环变量，循环结束后**保留最后一个元素**，永远非空 →
	 *    那个 return 永远不执行 → admin-mobile.css 与 media-picker.js 被灌进**所有后台页**
	 *    （与 functions.php 的 as_admin_mobile_styles() 重复加载同一份 CSS）。
	 */
	if ( ! $hit ) {
		return;
	}

	wp_enqueue_media();
	wp_enqueue_style( 'as-mini-admin', get_template_directory_uri() . '/assets/css/admin-mobile.css', array(), AS_THEME_VERSION );

	wp_enqueue_script(
		'as-media-picker',
		get_template_directory_uri() . '/assets/js/media-picker.js',
		array( 'jquery' ),
		AS_THEME_VERSION,
		true
	);

	// 首页广告区编辑器（v2.75.0）
	wp_enqueue_script(
		'as-home-ads',
		get_template_directory_uri() . '/assets/js/home-ads.js',
		array( 'jquery' ),
		AS_THEME_VERSION,
		true
	);
	wp_localize_script(
		'as-home-ads',
		'asHomeAds',
		array(
			'ajaxUrl' => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
			'nonce'   => wp_create_nonce( 'as_site_settings' ),
			'max'     => function_exists( 'as_home_ads_max' ) ? as_home_ads_max() : 6,
		)
	);

	wp_add_inline_style(
		'as-mini-admin',
		'
		.as-color-field { max-width:120px; }
		.as-color-swatch { display:inline-block;width:22px;height:22px;border:1px solid #dcdcde;border-radius:4px;margin-left:8px;vertical-align:middle; }
		/* 网站 Logo 选择器（v2.87.59） */
		.as-logo-picker { display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap; }
		.as-logo-picker-prev { min-width:120px;min-height:64px;display:flex;align-items:center;justify-content:center;background:#f6f7f7;border:1px dashed #c3c4c7;border-radius:6px;padding:8px; }
		.as-logo-picker-prev.is-empty { color:#8c8f94;font-size:13px; }
		.as-logo-picker-ops { display:flex;align-items:center;gap:10px;flex-wrap:wrap; }
		.as-logo-picker-ops .description { margin-left:0; }
		'
	);
}
add_action( 'admin_enqueue_scripts', 'as_site_settings_assets' );

/* ==========================================================================
 * 与「外观 → 自定义」的关系
 * ======================================================================== */

/**
 * 这些设置已经搬到这里，外观自定义里就不再重复出现，避免两处可改造成困惑。
 *
 * 注意：这里只是**不注册 Customizer 的 section**（界面上不显示），
 * theme_mod 本身照旧由本文件读写，前台取值的代码一行都不用改。
 *
 * 保留注册反而更安全的做法是只隐藏 section 但保留 setting；
 * 但 Customizer 没法「隐藏 section 又保留可编辑」，所以直接从注册里剔除。
 */
function as_site_settings_hide_from_customizer( $wp_customize ) {
	// 站点信息（含首页 SEO 描述）与媒体上传 —— 由 as_site_settings_fields() 接管
	foreach ( array( 'as_site_info', 'as_media_settings', 'as_follow_qr', 'as_seo_settings' ) as $section ) {
		$wp_customize->remove_section( $section );
	}
}
add_action( 'customize_register', 'as_site_settings_hide_from_customizer', 20 );