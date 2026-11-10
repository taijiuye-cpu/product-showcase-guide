<?php
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'AS_THEME_VERSION', '2.87.136' );

/* ==========================================================================
   上传图片自动重命名为时间戳
   --------------------------------------------------------------------------
   动机：默认会保留原始文件名，中文产品名会被编码成 %e5%a4%9c%e5%8a%b2... 出现在
   图片地址里；对成人用品站来说，图片 URL 会出现在地址栏、Referer、浏览器历史和
   图片搜索里，等于把商品名暴露出去。改成时间戳数字可以规避这个问题。

   代价：图片文件名失去关键词，对图片搜索有一点影响（正文里的 alt 已经兜底）。

   注意：只影响之后新上传的图片，已有附件不会被改动（改已有文件名会波及
   postmeta 里的 _wp_attached_file 和正文里写死的图片地址，风险太大）。
   ========================================================================== */

/**
 * 是否为图片扩展名
 */
function as_is_image_ext( $filename ) {
    $ext = strtolower( pathinfo( (string) $filename, PATHINFO_EXTENSION ) );

    return in_array( $ext, array(
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp',
        'heic', 'heif', 'tiff', 'tif', 'svg',
    ), true );
}

/**
 * 上传时把图片文件名改成时间戳
 */
function as_timestamp_upload_name( $file ) {
    if ( empty( $file['name'] ) || ! as_is_image_ext( $file['name'] ) ) {
        return $file;
    }

    // 默认开启；外观 → 自定义 → 媒体上传 可关，也可以用过滤器硬关
    $enabled = apply_filters(
        'as_rename_upload_to_timestamp',
        (bool) get_theme_mod( 'as_rename_uploads', true )
    );

    if ( ! $enabled ) {
        return $file;
    }

    $original = $file['name'];
    $ext      = strtolower( pathinfo( $original, PATHINFO_EXTENSION ) );

    // 同一请求里连续上传（批量传图）时用递增避免同秒撞名；
    // 跨请求撞名交给 WordPress 的 wp_unique_filename 自动补 -1
    static $seq = 0;
    $name = (string) ( time() + $seq );
    $seq++;

    if ( '' !== $ext ) {
        $name .= '.' . $ext;
    }

    $file['name'] = $name;

    // 记下原始名，稍后还原成附件标题，免得媒体库里全是一串数字
    if ( ! isset( $GLOBALS['as_upload_original_titles'] ) || ! is_array( $GLOBALS['as_upload_original_titles'] ) ) {
        $GLOBALS['as_upload_original_titles'] = array();
    }
    $GLOBALS['as_upload_original_titles'][ $name ] = preg_replace( '/\.[^.]+$/', '', $original );

    return $file;
}
add_filter( 'wp_handle_upload_prefilter', 'as_timestamp_upload_name' );

/**
 * 把原始文件名还原成附件标题
 * WordPress 默认拿文件名当标题，改名后媒体库会全变成 "1759456789"，
 * 所以这里把原始中文名写回去，媒体库仍然可读、可搜索。
 */
function as_restore_attachment_title( $attachment_id ) {
    if ( empty( $GLOBALS['as_upload_original_titles'] ) || ! is_array( $GLOBALS['as_upload_original_titles'] ) ) {
        return;
    }

    $post = get_post( $attachment_id );
    if ( ! $post || 'attachment' !== $post->post_type ) {
        return;
    }

    $base = wp_basename( (string) $post->guid );
    if ( '' === $base ) {
        return;
    }

    // wp_unique_filename 遇到同名会补 -1 / -2，这时要去掉后缀再找一次
    $key = $base;
    if ( empty( $GLOBALS['as_upload_original_titles'][ $key ] ) ) {
        $stripped = preg_replace( '/-\d+(\.[^.]+)$/', '$1', $base );
        if ( $stripped && ! empty( $GLOBALS['as_upload_original_titles'][ $stripped ] ) ) {
            $key = $stripped;
        }
    }

    if ( empty( $GLOBALS['as_upload_original_titles'][ $key ] ) ) {
        return;
    }

    $title = trim( $GLOBALS['as_upload_original_titles'][ $key ] );
    unset( $GLOBALS['as_upload_original_titles'][ $key ] );

    if ( '' === $title || $title === $post->post_title ) {
        return;
    }

    wp_update_post( array(
        'ID'         => $attachment_id,
        'post_title' => $title,
    ) );
}
add_action( 'add_attachment', 'as_restore_attachment_title' );

/**
 * 产品缩略图：自动补 alt + 显式控制懒加载
 *
 * alt：图片文件名变成时间戳后就没有语义了，alt 必须靠这里兜底。
 *      媒体库里手动填过 alt 的话优先用填的。
 *
 * 懒加载：交给 WordPress 自动判断时，首页（front-page.php 用的是自定义
 *        WP_Query，不走主循环）会出现 22 张图全部同步加载的情况，
 *        首屏直接吃掉 500+ KB。这里改成显式控制：
 *          页面上第 1 张图 → 不懒加载 + fetchpriority=high（LCP 候选）
 *          其余全部 → loading=lazy（进入视口前不下载）
 */
function as_post_thumbnail( $post_id, $size = 'post-thumbnail', $attr = array() ) {
    $post_id = $post_id ? $post_id : get_the_ID();

    if ( ! has_post_thumbnail( $post_id ) ) {
        return '';
    }

    $thumb_id = get_post_thumbnail_id( $post_id );
    $alt      = $thumb_id ? trim( (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) ) : '';

    if ( '' === $alt ) {
        $alt = get_the_title( $post_id );
    }

    $attr        = is_array( $attr ) ? $attr : array();
    $attr['alt'] = $alt;

    // 页面上已输出的缩略图张数（静态计数，每次请求从 0 开始）
    static $as_thumb_seen = 0;

    if ( ! isset( $attr['loading'] ) && ! isset( $attr['fetchpriority'] ) ) {
        if ( 0 === $as_thumb_seen ) {
            // 第一张：作为 LCP 候选，尽快加载
            $attr['fetchpriority'] = 'high';
        } else {
            $attr['loading'] = 'lazy';
        }
    }
    $as_thumb_seen++;

    return get_the_post_thumbnail( $post_id, $size, $attr );
}

/**
 * 文章卡片的缩略图：优先特色图像，没设就从正文抓第一张图
 *
 * 抓取规则（按可靠性排序，找到就停）：
 *   1. 正文 <img> 里 src 指向本站 uploads 的（能转缩略图尺寸）
 *   2. 任意 <img> 的 src（含外链图，只能原样用）
 *   3. 编辑器里贴了图链接但没插入图片块（内容是裸 URL 或 <a href> 指向图片）
 *
 * 返回 attachment ID（int）或图片 URL（string）；都没有返回 ''。
 * 结果按文章 ID 缓存，一个请求里列表页会反复调用，避免重复正则。
 */
function as_article_first_image( $post_id ) {
    static $cache = array();

    $post_id = (int) $post_id;
    if ( ! $post_id ) {
        return '';
    }

    if ( array_key_exists( $post_id, $cache ) ) {
        return $cache[ $post_id ];
    }

    $post = get_post( $post_id );
    if ( ! $post ) {
        $cache[ $post_id ] = '';
        return '';
    }

    // 去掉区块注释，避免把 <!-- wp:image {"id":12} --> 里的数字误认成图片
    $content = preg_replace( '/<!--.*?-->/s', '', (string) $post->post_content );
    $content = strip_shortcodes( $content );

    $uploads    = wp_get_upload_dir();
    $upload_dir = ! empty( $uploads['baseurl'] ) ? $uploads['baseurl'] : '';

    $fallback = '';

    // 1 + 2：扫 <img>，本站图优先
    if ( preg_match_all( '/<img\b[^>]*>/i', $content, $m ) ) {
        foreach ( $m[0] as $tag ) {
            if ( ! preg_match( '/\bsrc\s*=\s*(["\'])(.*?)\1/i', $tag, $s ) ) {
                continue;
            }
            $src = html_entity_decode( trim( $s[2] ), ENT_QUOTES, 'UTF-8' );
            if ( '' === $src || 0 === strpos( $src, 'data:' ) ) {
                continue;
            }

            // 本站 uploads 的图 → 反查附件 ID，交给调用方转成合适的尺寸
            if ( $upload_dir && false !== strpos( $src, $upload_dir ) ) {
                $aid = attachment_url_to_postid( $src );
                if ( $aid ) {
                    $cache[ $post_id ] = (int) $aid;
                    return $cache[ $post_id ];
                }
            }

            // 外链图：先记下，没更好的就用它
            if ( ! $fallback ) {
                $fallback = $src;
            }
        }
    }

    // 3：裸图片 URL（编辑器里只贴了链接的情况）
    if ( ! $fallback && preg_match( '#\bhttps?://[^\s"\'<>()]+\.(?:jpe?g|png|gif|webp|avif)#i', $content, $u ) ) {
        $url = html_entity_decode( $u[0], ENT_QUOTES, 'UTF-8' );
        if ( $upload_dir && false !== strpos( $url, $upload_dir ) ) {
            $aid = attachment_url_to_postid( $url );
            if ( $aid ) {
                $cache[ $post_id ] = (int) $aid;
                return $cache[ $post_id ];
            }
        }
        $fallback = $url;
    }

    $cache[ $post_id ] = $fallback;

    return $cache[ $post_id ];
}

/**
 * 文章卡片缩略图 HTML：特色图像 → 正文首图 → 占位图
 *
 * @param int    $post_id 文章 ID
 * @param string $size    特色图/正文图的尺寸名（正文外链图只能原尺寸）
 * @param int    $pw      占位图宽
 * @param int    $ph      占位图高
 * @param string $class   占位图 class（有真图时用 WP 自带 class）
 */
function as_post_thumb_html( $post_id, $size = 'as_blog_thumb', $pw = 720, $ph = 400, $class = '' ) {
    $post_id = $post_id ? $post_id : get_the_ID();

    // 1. 特色图像
    $html = as_post_thumbnail( $post_id, $size );
    if ( $html ) {
        return $html;
    }

    // 2. 正文第一张图
    $first = as_article_first_image( $post_id );
    if ( $first ) {
        if ( is_numeric( $first ) ) {
            // 站内附件：转成对应尺寸，省流量
            $html = wp_get_attachment_image( (int) $first, $size, false, array(
                'loading' => 'lazy',
                'decoding' => 'async',
            ) );
            if ( $html ) {
                return $html;
            }
            $url = wp_get_attachment_image_url( (int) $first, $size );
            if ( $url ) {
                return sprintf(
                    '<img src="%s" alt="%s" loading="lazy" decoding="async">',
                    esc_url( $url ),
                    esc_attr( get_the_title( $post_id ) )
                );
            }
        } else {
            // 外链图：原样输出
            return sprintf(
                '<img src="%s" alt="%s" loading="lazy" decoding="async">',
                esc_url( $first ),
                esc_attr( get_the_title( $post_id ) )
            );
        }
    }

    // 3. 占位图兜底
    return as_placeholder_img( get_the_title( $post_id ), $pw, $ph, $class );
}

/**
 * 文章阅读量（v2.87.15）
 *
 * 口径：**PV** —— 每打开一次文章页就 +1，不去重（用户 2026-10-15 定：「不去重了，
 * 只要打开一次就计算一次」）。存储用 post meta `as_post_views`，不建新表。
 *
 * 🔴 计数入口只有两处，别在列表/相关推荐里调 as_post_views_bump()：
 *   · 网页端：as_count_post_view() 挂 wp_head，仅 is_singular('post') 时触发
 *   · 小程序端：inc/miniapp-api.php 的 as_mini_article()（/articles/<id> 详情接口）
 * as_mini_article_row() 只**读** views 供展示，列表页因此不会误加。
 */
function as_post_views( $post_id = null ) {
    $post_id = $post_id ? $post_id : get_the_ID();
    if ( ! $post_id ) {
        return 0;
    }
    return (int) get_post_meta( $post_id, 'as_post_views', true );
}

/** 阅读量 +1，返回加成后的数值（非文章 / ID 非法时返回 0 且不写库） */
function as_post_views_bump( $post_id ) {
    $post_id = (int) $post_id;
    if ( $post_id < 1 || 'post' !== get_post_type( $post_id ) ) {
        return 0;
    }
    $n = as_post_views( $post_id ) + 1;
    update_post_meta( $post_id, 'as_post_views', $n );
    return $n;
}

/** 网页端计数：只在文章正文页触发（排除预览与后台） */
function as_count_post_view() {
    if ( is_admin() || is_preview() || ! is_singular( 'post' ) ) {
        return;
    }
    as_post_views_bump( get_the_ID() );
}
add_action( 'wp_head', 'as_count_post_view' );

/**
 * 主题基础设置
 */
function as_theme_setup() {
    load_theme_textdomain( 'product-showcase-guide', get_template_directory() . '/languages' );

    add_theme_support( 'post-thumbnails' );
    add_theme_support( 'title-tag' );
    add_theme_support( 'automatic-feed-links' );
    add_theme_support( 'responsive-embeds' );
    add_theme_support( 'align-wide' );
    add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'comment-form', 'comment-list', 'script', 'style' ) );
    add_theme_support( 'custom-logo', array( 'height' => 60, 'width' => 200, 'flex-height' => true, 'flex-width' => true ) );

    $GLOBALS['content_width'] = 1080;

    add_image_size( 'as_product_card', 400, 400, true );
    add_image_size( 'as_product_large', 800, 800, true );
    add_image_size( 'as_blog_thumb', 720, 400, true );
    // 内嵌卡缩略图。⚠️ v2.86.7：as_pc_thumb 是 200x200 **硬裁**（第三个参数 true），
    // 非方图（如实测 800x776）会被左右各切掉约 3%，瓶身/包装边缘被削 → 用户反馈
    // 「图片显示不全」。所以另注册一个**等比缩放、不裁切**的尺寸专供内嵌卡用。
    add_image_size( 'as_pc_thumb', 200, 200, true ); // 保留兼容（旧缩略图仍在用）
    add_image_size( 'as_pc_fit', 400, 400, false ); // 等比缩放到 400 宽，绝不裁像素
    /*
     * 上面 4 个尺寸都是硬裁切（crop = true）。硬裁切要求原图**不小于**目标尺寸，
     * 否则 WordPress 返回 WP_Error「无法计算调整后的图片尺寸」，整条上传流程中断
     * （后台表现：上传多图直接停住、连一张都传不上去）。
     *
     * 实测踩到的例子：800×690 的图想裁成 800×800（as_product_large），
     * 高度不够 → 报错 → 传不上去。
     *
     * as_skip_unreachable_thumbnail_size() 在下面兜住这种情况。
     */

    register_nav_menus( array(
        'primary' => '主导航',
        'footer'  => '底部导航',
    ) );
}
/*
 * 「全部优惠」页路由（v2.86.52）：/sale/ → page-sale.php 模板。
 * 不建 WP 页面（免后台操作），用 query var + template_include 渲染。
 * ⚠️ 新规则要靠一次 flush_rewrite_rules() 才生效 —— 下面按主题版本号
 * 自动触发（升级后首次访问刷一次，之后不再刷）。
 */
add_action( 'init', function () {
	add_rewrite_rule( '^sale/?$', 'index.php?as_sale_page=1', 'top' );
} );
add_filter( 'query_vars', function ( $vars ) {
	$vars[] = 'as_sale_page';
	return $vars;
} );
add_action( 'init', function () {
	if ( get_option( 'as_theme_rewrite_ver' ) !== AS_THEME_VERSION ) {
		flush_rewrite_rules();
		update_option( 'as_theme_rewrite_ver', AS_THEME_VERSION );
	}
} );
add_filter( 'template_include', function ( $template ) {
	if ( get_query_var( 'as_sale_page' ) ) {
		$file = get_template_directory() . '/page-sale.php';
		if ( file_exists( $file ) ) {
			return $file;
		}
	}
	return $template;
} );

/*
 * 基础版不含网站商城页面（/cart/ /checkout/ /order/ /member/ /orders/），
 * 交易类模板已随支付模块一并移除，对应路由不再注册。
 */

add_action( 'after_setup_theme', 'as_theme_setup' );

/**
 * 小图裁不出目标尺寸时跳过该尺寸，而不是让整条上传失败。
 *
 * 背景：WP 生成缩略图时，若原图比例与硬裁切目标不一致会报
 * 「无法计算调整后的图片尺寸」。多图批量上传时任意一档失败，整批就停在那里。
 *
 * 这里在 intermediate_image_sizes_advanced 阶段先把「裁不出来」的尺寸
 * 从列表里去掉，剩下的正常生成。缺的那一档前台会由 WP 自动回退到原图，
 * 不会白屏、也不会丢图。
 *
 * 判定方式：对每个硬裁切尺寸，比较原图与目标的最短边。
 *   原图最短边 >= 目标最短边 → 能裁（720×400 这类非正方形也适用）
 *   否则 → 裁不出来，跳过
 *
 * 只对硬裁切尺寸生效；等比缩放尺寸（crop = false）本来就不会失败，不动。
 */
function as_skip_unreachable_thumbnail_size( $sizes ) {
	$file = get_attached_file( get_post() ? get_post()->ID : 0 );
	if ( ! $file || ! file_exists( $file ) ) {
		return $sizes;
	}

	// 读原图尺寸；读不到就直接放行，交给 WP 自己报错（不要在这里掩盖真实问题）
	$img = wp_getimagesize( $file );
	if ( empty( $img[0] ) || empty( $img[1] ) ) {
		return $sizes;
	}

	$src_min = min( $img[0], $img[1] );

	foreach ( $sizes as $name => $size ) {
		if ( empty( $size['crop'] ) ) {
			continue; // 等比缩放不会失败
		}
		$dst_min = min( (int) $size['width'], (int) $size['height'] );
		if ( $dst_min > 0 && $src_min < $dst_min ) {
			unset( $sizes[ $name ] );
		}
	}

	return $sizes;
}
add_filter( 'intermediate_image_sizes_advanced', 'as_skip_unreachable_thumbnail_size' );

/**
 * 静态资源版本号：用文件修改时间，避免改完 CSS/JS 后浏览器还在用旧缓存
 */
function as_asset_version( $relative_path ) {
    $path = get_template_directory() . $relative_path;
    return file_exists( $path ) ? (string) filemtime( $path ) : AS_THEME_VERSION;
}

/**
 * 管理员入口：链接地址
 *
 * 两种情况（v2.87.45）：
 *   1. 已登录且能管网站 → 直接进后台（wp-admin/）
 *   2. 其余（未登录 / 权限不够）→ **不显示入口**（返回空串）
 *
 * ⚠️ v2.87.45 之前未登录时会返回 wp_login_url()，于是**任何访客**在前台导航里
 *    都能看到一个「后台管理」锁形图标，点进去就是 wp-login.php —— 等于给所有人
 *    指了后台入口（撞库面 + 品牌观感差）。现在未登录一律不输出。
 *    站长自己访问 /wp-admin/ 时 WP 本来就会跳登录页，登录后入口自动出现，不受影响。
 *
 * 返回空串表示"不该显示入口"，调用方要判断。
 */
function as_admin_entry_url() {
    // 已登录：只有能管网站的人才给入口，避免出现在编辑者/作者面前
    return current_user_can( 'manage_options' ) ? admin_url() : '';
}

/**
 * 管理员入口：当前是否已登录（决定文案与图标）
 */
function as_admin_entry_is_logged_in() {
    return is_user_logged_in() && current_user_can( 'manage_options' );
}

/**
 * 普通会员访问 wp-admin 时，送回前端会员中心（v2.87.37）。
 *
 * WP 默认允许任何登录用户进 wp-admin/profile.php 改自己的资料 ——
 * 但本站的「会员」是购物账号，他们的个人中心是前端 /member/。
 * 放进来只会看到「仪表盘 / 个人资料 / 工具栏 / 管理区配色」这些跟购物无关的东西，
 * 既困惑又容易误操作。
 *
 * 只放行 manage_options（管理员），其余一律跳 /member/。
 */
add_action( 'admin_init', function () {
    if ( ! is_user_logged_in() ) {
        return; // 未登录交给 WP 自己的 auth_redirect 去登录页
    }
    if ( current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( wp_doing_ajax() || wp_doing_cron() ) {
        return;
    }
    global $pagenow;
    if ( 'admin-post.php' === $pagenow ) {
        return; // WP 表单提交入口，部分插件依赖，不拦
    }
    wp_safe_redirect( home_url( '/member/' ) );
    exit;
} );

/**
 * 会员在前台不显示 WP 顶部管理条 —— 那是后台的东西，对购物用户没有意义。
 */
add_filter( 'show_admin_bar', function ( $show ) {
    return current_user_can( 'manage_options' ) ? $show : false;
} );

/* ==========================================================================
   首页文章区（v2.87.44）
   --------------------------------------------------------------------------
   · 显示几篇：后台「主题设置 → 阅读设置 → 首页文章显示几篇」（0 = 不显示该区块）。
   · 来源两种：
 *     category —— **覆盖全部分类**（默认）：每个分类轮流取，推荐区不会全是最近那一个分类的文章；
 *     latest   —— 纯按时间取最新。
   · 两种首页风格（货架 / 橱窗）共用这一套取数，后台改一次两边同时生效。
   ========================================================================== */

/** 首页文章显示几篇（0～30，0 = 不显示）。 */
function as_home_articles_count() {
	$n = (int) get_theme_mod( 'as_home_articles_count', 6 );
	if ( $n < 0 ) {
		$n = 0;
	}
	return min( 30, $n );
}

/** 取文方式：category（覆盖全部分类）| latest（最新优先）。 */
function as_home_articles_mode() {
	$m = (string) get_theme_mod( 'as_home_articles_mode', 'category' );
	return in_array( $m, array( 'category', 'latest' ), true ) ? $m : 'category';
}

/**
 * 首页文章区取文。
 *
 * @param int $limit 传 0 用后台设置的篇数。
 * @return WP_Post[]
 */
function as_home_articles( $limit = 0 ) {
	$limit = $limit ? (int) $limit : as_home_articles_count();
	if ( $limit < 1 ) {
		return array();
	}

	// 按时间取最新
	if ( 'latest' === as_home_articles_mode() ) {
		$q = new WP_Query( array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'no_found_rows'  => true,
		) );
		return $q->posts;
	}

	// 覆盖全部分类：每个分类轮流取
	$cats = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => true, 'number' => 20 ) );
	$cats = ( $cats && ! is_wp_error( $cats ) ) ? $cats : array();
	if ( ! $cats ) {
		$q = new WP_Query( array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'no_found_rows'  => true,
		) );
		return $q->posts;
	}

	$per = max( 1, (int) ceil( $limit / count( $cats ) ) );
	$ids = array();
	foreach ( $cats as $cat ) {
		$q = new WP_Query( array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => $per,
			'category__in'   => array( (int) $cat->term_id ),
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );
		foreach ( (array) $q->posts as $pid ) {
			$ids[] = (int) $pid;
			if ( count( $ids ) >= $limit ) {
				break 2;
			}
		}
	}

	// 不够篇数：用最新文章补齐（去重）
	if ( count( $ids ) < $limit ) {
		$q = new WP_Query( array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => $limit * 2,
			'post__not_in'   => $ids,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );
		foreach ( (array) $q->posts as $pid ) {
			$ids[] = (int) $pid;
			if ( count( $ids ) >= $limit ) {
				break;
			}
		}
	}

	$posts = array_map( 'get_post', $ids );
	$posts = array_filter( $posts );
	usort(
		$posts,
		function ( $a, $b ) {
			return strcmp( $b->post_date, $a->post_date );
		}
	);
	return array_slice( $posts, 0, $limit );
}

/**
 * 首页「热门文章」取文（v2.87.51）
 *
 * 排序口径（用户 2026-11-07 定：**后台勾选优先 + 浏览量兜底**）：
 *   ① 后台勾了「热门文章」的（meta `dg_hot`=1）→ 排在最前，
 *      同为勾选的按**后勾的在前**（ID 倒序）—— 编辑刚标的那篇自然置顶；
 *   ② 没勾的 → 按**阅读量（as_post_views）降序**，量相同按 ID 倒序（新篇优先）。
 *
 * ⚠️ 为什么不用「纯阅读量」：新站 PV 最高才几十，纯 PV 排出来的"热门"
 *    和"最新"没区别；而且 PV 只增不减 → 半年后老文章永远霸榜，新文章进不去。
 *    交给编辑勾选才有导向性，跟商品的「热门推荐 dg_hot」是同一套逻辑。
 *
 * @param int $limit 最多几篇。
 * @return WP_Post[]
 */
function as_home_hot_articles( $limit = 5 ) {
	$limit = max( 1, (int) $limit );

	$all = get_posts( array(
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'posts_per_page' => 50,
		'no_found_rows'  => true,
	) );
	if ( ! $all ) {
		return array();
	}

	$hot  = array();
	$rest = array();
	foreach ( $all as $p ) {
		// meta 已随 WP_Query 进缓存，这里是内存读取，不会产生额外 SQL
		if ( '1' === (string) get_post_meta( $p->ID, 'dg_hot', true ) ) {
			$hot[] = $p;
		} else {
			$rest[] = $p;
		}
	}

	usort(
		$hot,
		function ( $a, $b ) {
			return $b->ID - $a->ID;
		}
	);
	usort(
		$rest,
		function ( $a, $b ) {
			$va = function_exists( 'as_post_views' ) ? (int) as_post_views( $a->ID ) : 0;
			$vb = function_exists( 'as_post_views' ) ? (int) as_post_views( $b->ID ) : 0;
			if ( $va !== $vb ) {
				return $vb - $va;
			}
			return $b->ID - $a->ID;
		}
	);

	return array_slice( array_merge( $hot, $rest ), 0, $limit );
}

/* ==========================================================================
   首页风格（v2.87.43）：货架 shelf / 橱窗 showcase
   --------------------------------------------------------------------------
   shelf（默认）：现行版式 —— 区块等权、卡片密集、商品卡是唯一单元，
                  适合「已知要买什么」的找货 / 比价场景。
   showcase：品牌大屏版式 —— 价值主张优先、一屏一件事、明暗与留白制造节奏，
             更像品牌官网，适合讲故事、拉新客。
   ⚠️ 只换**版式与配色**：取数、价格、券、会员价、购物车、支付、订单
      全部走同一套函数，两种风格共用，不存在「切了风格功能就变了」。
   ========================================================================== */

/** 当前首页风格：'shelf'（默认）| 'showcase' */
function as_home_style() {
	$s = get_theme_mod( 'as_home_style', 'shelf' );
	$s = is_string( $s ) ? strtolower( trim( $s ) ) : '';
	return in_array( $s, array( 'shelf', 'showcase' ), true ) ? $s : 'shelf';
}

/**
 * 是否为某种风格。
 *
 * @param string $style 风格 ID。
 * @return bool
 */
function as_home_style_is( $style ) {
	return as_home_style() === (string) $style;
}

/**
 * 橱窗风格会接管哪些页面（决定要不要挂 showcase 的样式表）。
 *
 * @return bool
 */
function as_home_style_showcase_pages() {
	if ( is_front_page() ) {
		return true;
	}
	if ( is_post_type_archive( 'plm_product' ) ) {
		return true;
	}
	if ( is_tax( array( 'plm_category', 'plm_brand' ) ) ) {
		return true;
	}
	/*
	 * 搜索页（v2.87.45 修）：
	 * 之前要求 post_type === 'product' 才算橱窗页，但本站搜索框提交的是
	 * **普通 ?s= 搜索**（post_type 为空）→ 搜索页整页没加载 style-showcase.css，
	 * 于是拿货架的 CSS 去渲染橱窗的 HTML → 布局全乱（.as-sc-search-box 等样式全部失效）。
	 * 搜索页无论带不带 post_type，都属于橱窗范围。
	 */
	if ( is_search() ) {
		return true;
	}
	/*
	 * 「全部优惠」页（v2.87.90）：
	 * 卡片已改用归档页同款的橱窗卡（.as-sc-card），必须一并加载 style-showcase.css，
	 * 否则 .as-sc-list-items 的网格与 .as-sc-card 的卡内布局全部失效（卡片会摊成一条）。
	 * 该页是 query var + template_include 渲染的，is_page_template() 判不出来。
	 */
	if ( get_query_var( 'as_sale_page' ) ) {
		return true;
	}
	return false;
}

/**
 * body 上挂一个风格类 —— CSS 里靠它把「橱窗」版式限定在对应页面，
 * 不用给每个模块都加一遍前缀。
 */
add_filter( 'body_class', function ( $classes ) {
	if ( as_home_style_is( 'showcase' ) ) {
		$classes[] = 'as-style-showcase';
	}
	/*
	 * 商品页有吸底购买栏（v2.87.50）→ 给 body 挂个类，
	 * CSS 靠它给页面底部留出等高空间（否则吸底栏会盖住最后一段内容）。
	 */
	if ( is_singular( 'plm_product' ) ) {
		$classes[] = 'as-has-sticky-buy';
	}
	return $classes;
} );

/**
 * CSS 合并包（v2.87.127 性能优化）
 * ---------------------------------------------------------------------------
 * 背景：前台原来一次要下 4~5 个 CSS（gzip 后 66~82KB），CSS 是渲染阻塞资源，
 *      多一个文件就多一次往返 —— 首屏 FCP 里 CSS 占了很大一块。
 *
 * 现在按「页面类型」合并成 3 个包，每个页面**只请求 1 个 CSS**：
 *      app.min.css      = main + web-shop + refresh
 *      showcase.min.css = main + web-shop + showcase + refresh   （橱窗范围）
 *      single.min.css   = main + web-shop + refresh + single-two-col（商品详情页）
 *
 * 🔴 为什么不能简单拼成一个大包：
 *    WP 现在的输出顺序是
 *        橱窗页：main → web-shop → showcase → refresh
 *        商品页：main → web-shop → refresh → single-two-col
 *    style-showcase 是「橱窗范围」才加载的，而 style-refresh 是**覆盖层**
 *    （必须排在 showcase 之后，否则配色会被橱窗样式盖掉）。
 *    所以每个包的内部顺序 = 原输出顺序 → 外观零变化。
 *
 * 🔴 三重回退（任一命中就自动用源文件，绝不会「样式不生效」）：
 *    1) wp-config.php 里 define( 'AS_CSS_RAW', true ) —— 线上紧急开关，不用重新部署；
 *    2) 合并包文件不存在（没跑构建脚本 / 部署漏了 dist 目录）；
 *    3) 合并包比它包含的任何一个源文件都旧 —— 改了源 CSS 但忘跑
 *       `python .tools/build_css.py` 时自动退回源文件（只会慢，不会错）。
 *
 * 构建脚本：.tools/build_css.py（源文件永不改动，永远只改源文件再重新构建）。
 */
function as_css_bundle_info() {
    static $info = null;
    if ( null !== $info ) {
        return $info;
    }

    $dir   = get_template_directory();
    $dist  = $dir . '/assets/css/dist/';
    $info  = array(
        'use_bundle' => false,
        'file'       => '',
        'url'        => '',
        'version'    => '',
    );

    // ① 线上紧急开关
    if ( defined( 'AS_CSS_RAW' ) && AS_CSS_RAW ) {
        return $info;
    }

    // ② 选哪个包
    if ( is_singular( 'plm_product' ) ) {
        $bundle = 'single.min.css';
    } elseif ( as_home_style_is( 'showcase' ) && as_home_style_showcase_pages() ) {
        $bundle = 'showcase.min.css';
    } else {
        $bundle = 'app.min.css';
    }

    $path = $dist . $bundle;
    if ( ! file_exists( $path ) ) {
        return $info;
    }

    // ③ 合并包比源文件旧 → 自动退回源文件
    $sources = array(
        'main.css',
        'web-shop.css',
        'style-refresh.css',
        'style-showcase.css',
        'single-two-col.css',
    );
    $bundle_mtime = filemtime( $path );
    foreach ( $sources as $s ) {
        $sp = $dir . '/assets/css/' . $s;
        if ( file_exists( $sp ) && filemtime( $sp ) > $bundle_mtime ) {
            return $info;   // 源文件更新 → 用源文件
        }
    }

    $info['use_bundle'] = true;
    $info['file']       = 'assets/css/dist/' . $bundle;
    $info['url']        = get_template_directory_uri() . '/assets/css/dist/' . $bundle;
    $info['version']    = (string) $bundle_mtime;

    return $info;
}

/**
 * 加载样式与脚本
 */
function as_enqueue_assets() {
    $as_bundle = as_css_bundle_info();

    if ( $as_bundle['use_bundle'] ) {
        // 合并包：一个页面只请求一个 CSS（v2.87.127）
        wp_enqueue_style( 'as-app', $as_bundle['url'], array(), $as_bundle['version'] );
    } else {
        $as_main_uri = get_template_directory_uri() . '/assets/css/';

        wp_enqueue_style(
            'as-main',
            $as_main_uri . 'main.css',
            array(),
            as_asset_version( '/assets/css/main.css' )
        );

        // style.css 里只有主题头注释、没有任何样式规则，前端不再单独请求它（省 1 个请求）。
        // 子主题依然可以正常 enqueue 自己的 style.css。

        // 网站商城（购物车 / 结算 / 支付）样式 —— 头部有购物车入口，全站都要用
        wp_enqueue_style(
            'as-web-shop',
            $as_main_uri . 'web-shop.css',
            array( 'as-main' ),
            as_asset_version( '/assets/css/web-shop.css' )
        );

        // 橱窗风格样式 —— 只在「风格 = 橱窗」且页面属于橱窗范围时才加载
        // （货架风格一个字节都不多下，切换开关也不会拖慢默认站）
        if ( as_home_style_is( 'showcase' ) && as_home_style_showcase_pages() ) {
            wp_enqueue_style(
                'as-style-showcase',
                $as_main_uri . 'style-showcase.css',
                array( 'as-main', 'as-web-shop' ),
                as_asset_version( '/assets/css/style-showcase.css' )
            );
        }

        // 全站视觉刷新（纯 CSS 覆盖层，必须排在上面几份之后）。
        // 🔴 deps 只写 as-main / as-web-shop：写 as-style-showcase 会让详情页（不加载橱窗样式）
        //    因依赖未注册而整表不输出（v2.87.60 踩过这个坑）。
        wp_enqueue_style(
            'as-style-refresh',
            $as_main_uri . 'style-refresh.css',
            array( 'as-main', 'as-web-shop' ),
            as_asset_version( '/assets/css/style-refresh.css' )
        );

        /*
         * 商品详情页电脑端「京东式两栏」（v2.87.109）
         *   · 只在商品单页加载，其它页面一个字节都不多下；
         *   · 纯 CSS 覆盖层，手机端靠文件内的 @media (min-width:992px) 完全不介入；
         *   · 必须排在 style-refresh.css 之后（deps 里三个都是无条件注册的样式，
         *     不存在「依赖未注册导致整表不输出」的问题）。
         */
        if ( is_singular( 'plm_product' ) ) {
            wp_enqueue_style(
                'as-single-two-col',
                $as_main_uri . 'single-two-col.css',
                array( 'as-main', 'as-web-shop', 'as-style-refresh' ),
                as_asset_version( '/assets/css/single-two-col.css' )
            );
        }
    }

    // 前台交互脚本（移动端导航 / Tab 筛选 / 评论轮播 / 返回顶部）
    wp_enqueue_script(
        'as-main',
        get_template_directory_uri() . '/assets/js/main.js',
        array(),
        as_asset_version( '/assets/js/main.js' ),
        true
    );

    // 商品详情长图折叠（v2.87.108，A 方案：按高度折叠 + 渐隐 + 通栏展开按钮）
    // 只在可能有导入图的正文页加载；脚本本身会自行判断有无 .as-jd-detail。
    if ( is_singular( 'plm_product' ) || is_singular( 'post' ) || is_page() ) {
        wp_enqueue_script(
            'as-detail-fold',
            get_template_directory_uri() . '/assets/js/detail-fold.js',
            array(),
            as_asset_version( '/assets/js/detail-fold.js' ),
            true
        );
    }

    /*
     * 电脑端两栏：右栏吸顶「落地」（v2.87.109）
     *   纯 CSS 的 grid-row 边界在 Chrome 上没法约束 sticky，靠脚本动态调 top，
     *   让右栏在商品详情结束、相关推荐接上时随页面滚走。脚本内部只在 ≥992px 生效。
     */
    if ( is_singular( 'plm_product' ) ) {
        wp_enqueue_script(
            'as-single-two-col',
            get_template_directory_uri() . '/assets/js/single-two-col.js',
            array(),
            as_asset_version( '/assets/js/single-two-col.js' ),
            true
        );
    }

    // 网站商城脚本（支付状态轮询 / 支付宝收银台自动提交 / 加购 AJAX）
    wp_enqueue_script(
        'as-web-shop',
        get_template_directory_uri() . '/assets/js/web-shop.js',
        array(),
        as_asset_version( '/assets/js/web-shop.js' ),
        true
    );

    /*
     * 加购 AJAX 的配置（v2.87.50）：
     *   · ajaxUrl —— admin-ajax.php 地址（不依赖全局 ajaxurl 变量，
     *     那个在部分环境没定义，用 localize 注入最稳，跟 home-ads.js 一个套路）；
     *   · nonce  —— 页面里每个加购 form 都自带 _wpnonce，
     *     这里只多给一份，方便 JS 兜底构造请求。
     */
    wp_localize_script(
        'as-web-shop',
        'asShopCfg',
        array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'as_web_shop' ),
        )
    );

    // 评论回复脚本：只有独立页面还显示评论区，文章页已改成相关推荐，不再需要
    if ( is_page() && comments_open() && get_option( 'thread_comments' ) ) {
        wp_enqueue_script( 'comment-reply' );
    }
}

/**
 * 清理 WordPress 默认输出里本站用不到的资源
 *
 * 主题内容是纯文章/短代码，没有用区块编辑器排版，所以：
 *   - 区块库 CSS（wp-block-library / classic-theme-styles / global-styles）
 *     是 WordPress 全局打印的，本站用不上，约 14 KB 内联样式
 *   - emoji 检测脚本与样式（前台没有用 emoji）
 *   - wp-embed（没有需要嵌入的站外内容）
 *
 * 想关掉这一整块优化，在主题里加一行即可：
 *   add_filter( 'as_trim_wp_assets', '__return_false' );
 */
function as_trim_wp_assets() {
    if ( is_admin() || ! apply_filters( 'as_trim_wp_assets', true ) ) {
        return;
    }

    // 1. 区块编辑器相关样式
    wp_dequeue_style( 'wp-block-library' );
    wp_dequeue_style( 'wp-block-library-theme' );
    wp_dequeue_style( 'classic-theme-styles' );
    wp_dequeue_style( 'global-styles' );

    // 2. emoji：去掉检测脚本 + 内联样式 + DNS 预取
    remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
    remove_action( 'wp_print_styles', 'print_emoji_styles' );
    remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
    remove_action( 'admin_print_styles', 'print_emoji_styles' );
    remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
    remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
    remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );

    // 3. 站外嵌入脚本
    wp_deregister_script( 'wp-embed' );
}
add_action( 'wp_enqueue_scripts', 'as_trim_wp_assets', 100 );

/**
 * emoji 用的 DNS 预取也一起去掉
 */
function as_remove_emoji_dns_prefetch( $urls, $relation_type ) {
    if ( 'dns-prefetch' === $relation_type && apply_filters( 'as_trim_wp_assets', true ) ) {
        $urls = array_filter( $urls, function ( $u ) {
            return false === strpos( $u, 's.w.org' );
        } );
    }

    return $urls;
}
add_filter( 'wp_resource_hints', 'as_remove_emoji_dns_prefetch', 10, 2 );
add_action( 'wp_enqueue_scripts', 'as_enqueue_assets' );

/**
 * 顶部进度条颜色（浏览器地址栏 / 移动端主题色）
 */
function as_theme_color_meta() {
    echo '<meta name="theme-color" content="#c2185b">' . "\n";
}
add_action( 'wp_head', 'as_theme_color_meta', 1 );

/**
 * 禁用古腾堡：**所有**文章类型统一使用经典编辑器
 *
 * 2026-10-11 起不再只挑 post / page / plm_product 三个类型。
 * 线上实测（PHP 探针跑 use_block_editor_for_post_type()）当时仍有漏网：
 *   · 样板 wp_block  → 区块编辑器
 *   · 导航菜单 wp_navigation → 区块编辑器
 *   · 小工具页       → 区块版小工具（见下方 use_widgets_block_editor 那两个钩子）
 * 用户要求「所有编辑器都是经典编辑器」，所以这里一律 false。
 *
 * 保留具名函数（而不是直接接 __return_false）的原因：子主题或别处以后可能
 * 按名字 remove_filter() 掉它，留个具名函数更好接管。
 */
function as_disable_gutenberg( $use_block_editor, $post_type = '' ) {
    return false;
}
add_filter( 'use_block_editor_for_post_type', 'as_disable_gutenberg', 10, 2 );
add_filter( 'gutenberg_can_edit_post_type', 'as_disable_gutenberg', 10, 2 );

/**
 * 后台「小工具」页也回到经典小工具（不要区块版）
 *
 * 实测两站的侧边栏都是空的、6 个区块小工具全部躺在「未启用的小工具」里，
 * 所以切回经典版不会丢任何在用的配置。
 * 想恢复区块版：把下面两行注释掉即可。
 */
add_filter( 'use_widgets_block_editor', '__return_false' );
add_filter( 'gutenberg_use_widgets_block_editor', '__return_false' );

/**
 * 博客列表页链接（统一入口）
 */
function as_blog_url() {
    $page_for_posts = get_option( 'page_for_posts' );
    if ( $page_for_posts ) {
        return get_permalink( $page_for_posts );
    }
    return home_url( '/blog/' );
}

/**
 * 当前页面 URL（用于 canonical / og:url）
 */
function as_current_url() {
    // 首页请求路径为空，user_trailingslashit('') 会原样返回，
    // 站点固定链接不以 / 结尾时会得到 https://site.com（少一个斜杠），与 canonical 不一致
    if ( is_front_page() ) {
        return home_url( '/' );
    }

    global $wp;
    $request = isset( $wp->request ) ? trim( (string) $wp->request, '/' ) : '';
    if ( '' === $request ) {
        return home_url( '/' );
    }

    return user_trailingslashit( home_url( '/' . $request ), 'category' );
}

/**
 * 是否已启用主流 SEO 插件（避免重复输出 meta）
 */
function as_seo_plugin_active() {
    return defined( 'WPSEO_VERSION' )            // Yoast
        || defined( 'RANK_MATH_VERSION' )        // Rank Math
        || defined( 'AIOSEO_VERSION' )           // All in One SEO
        || defined( 'SEOPRESS_VERSION' )         // SEOPress
        || defined( 'THE_SEO_FRAMEWORK_VERSION' ); // TSF
}

/**
 * 占位图：内联 SVG，不依赖外部图床（原来的 picsum.photos 在国内会拖慢首屏）
 */
function as_placeholder_src( $title = '', $w = 600, $h = 600 ) {
    $char = '';
    if ( $title ) {
        $title = wp_strip_all_tags( (string) $title );
        $char  = function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 1, 'UTF-8' ) : substr( $title, 0, 1 );
    }

    $font = max( 14, (int) round( $w / 7 ) );
    $svg  = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '">'
          . '<rect width="100%" height="100%" fill="#f1f2f5"/>'
          . '<text x="50%" y="50%" dy="0.35em" text-anchor="middle" fill="#c9ced6" '
          . 'font-family="-apple-system,PingFang SC,Microsoft YaHei,sans-serif" font-size="' . $font . '">'
          . htmlspecialchars( $char, ENT_QUOTES | ENT_XML1, 'UTF-8' )
          . '</text></svg>';

    return 'data:image/svg+xml;charset=utf-8,' . rawurlencode( $svg );
}

/**
 * 占位图 <img>（带宽高，避免 CLS）
 */
function as_placeholder_img( $title = '', $w = 600, $h = 600, $class = '' ) {
    return sprintf(
        '<img src="%s" width="%d" height="%d" alt="%s" loading="lazy" decoding="async"%s>',
        esc_attr( as_placeholder_src( $title, $w, $h ) ),
        (int) $w,
        (int) $h,
        esc_attr( $title ),
        $class ? ' class="' . esc_attr( $class ) . '"' : ''
    );
}

/**
 * 主导航未设置菜单时的兜底（避免页头空白）
 */
function as_nav_fallback() {
    $links = array(
        array( 'name' => '首页',     'url' => home_url( '/' ) ),
        array( 'name' => '全部产品', 'url' => get_post_type_archive_link( 'plm_product' ) ),
        array( 'name' => '博客',     'url' => as_blog_url() ),
    );

    echo '<ul id="as-primary-menu" class="as-fallback-menu">';
    foreach ( $links as $link ) {
        if ( ! $link['url'] ) continue;
        echo '<li><a href="' . esc_url( $link['url'] ) . '">' . esc_html( $link['name'] ) . '</a></li>';
    }
    echo '</ul>';
}

/**
 * 注册产品自定义文章类型
 */
function as_register_product_cpt() {
    register_post_type( 'plm_product', array(
        'labels' => array(
            'name'          => '产品',
            'singular_name' => '产品',
            'add_new'       => '添加产品',
            'add_new_item'  => '添加新产品',
            'edit_item'     => '编辑产品',
            'all_items'     => '所有产品',
            'menu_name'     => '商城产品',
        ),
        'public'       => true,
        'menu_position'=> 5,
        'menu_icon'    => 'dashicons-heart',
        'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
        'has_archive'  => true,
        'rewrite'      => array( 'slug' => 'product', 'with_front' => false ),
        'show_in_rest' => true,
    ) );
}
add_action( 'init', 'as_register_product_cpt' );

/**
 * 注册分类法：品牌 + 品类
 */
function as_register_taxonomies() {
    register_taxonomy( 'plm_brand', 'plm_product', array(
        'labels' => array(
            'name'          => '品牌',
            'singular_name' => '品牌',
            'add_new_item'  => '添加品牌',
            'menu_name'     => '品牌',
        ),
        'hierarchical' => true,
        'show_in_rest' => true,
        'rewrite'      => array( 'slug' => 'brand', 'with_front' => false ),
    ) );

    register_taxonomy( 'plm_category', 'plm_product', array(
        'labels' => array(
            'name'          => '品类',
            'singular_name' => '品类',
            'add_new_item'  => '添加品类',
            'menu_name'     => '品类',
        ),
        'hierarchical' => true,
        'show_in_rest' => true,
        // 品类归档挂在 /product/{别名}/ 下，与详情页 /product/{ID}.html 共用前缀，
        // 靠 .html 后缀区分（WordPress 的别名不允许包含点号，所以不会撞车）
        'rewrite'      => array( 'slug' => 'product', 'with_front' => false ),
    ) );
}
add_action( 'init', 'as_register_taxonomies' );

/**
 * 切换主题时刷新伪静态规则
 * 否则新装的站点访问 /product/ 会 404，必须手动去"设置-固定链接"保存一次
 */
function as_flush_rewrite_on_switch() {
    as_register_product_cpt();
    as_register_taxonomies();
    flush_rewrite_rules();
}
add_action( 'after_switch_theme', 'as_flush_rewrite_on_switch' );

/* ==========================================================================
   分类永久链接去掉 /category/ 前缀
   --------------------------------------------------------------------------
   后台「设置 → 固定链接 → 分类目录前缀」留空会被 WordPress 还原成 category；
   填一个英文句点（.）是流传很广的偏方，但在较新的 WordPress 上会导致无限重定向，
   不可靠。这里改用显式注册重写规则的方式，并顺带给旧地址做 301，
   避免已经收录的 /category/xxx 直接变 404。

   注意：这是"站点级功能"，严格说更适合放插件。换主题时请把这一整段
   搬到 mu-plugin 或独立小插件里，否则会失效。
   ========================================================================== */

define( 'AS_CATEGORY_RULE_VER', '1' );

/**
 * 是否由主题接管"去掉分类前缀"
 */
function as_category_base_should_strip() {
    // 已装 Yoast 并开启了它自带的同名功能 → 交给它处理，避免两边重复注册规则
    if ( defined( 'WPSEO_VERSION' ) && class_exists( 'WPSEO_Options' ) ) {
        if ( WPSEO_Options::get( 'stripcategorybase' ) ) {
            return false;
        }
    }

    return (bool) apply_filters( 'as_strip_category_base', true );
}

/**
 * 当前生效的分类基础前缀（默认 category）
 */
function as_category_base_slug() {
    $base = get_option( 'category_base' );
    $base = is_string( $base ) ? trim( $base, '/' ) : '';

    return $base ? $base : 'category';
}

/**
 * 生成分类链接时去掉 /category/
 * term_link 传 ($termlink, $term, $taxonomy)；category_link 传 ($termlink, $term_id)
 * 替换是幂等的，两个钩子同时挂着也不会把 URL 改坏。
 */
function as_strip_category_base_link( $termlink, $term = null, $taxonomy = 'category' ) {
    if ( ! as_category_base_should_strip() ) {
        return $termlink;
    }

    if ( is_object( $term ) && isset( $term->taxonomy ) ) {
        $taxonomy = $term->taxonomy;
    }

    if ( 'category' !== $taxonomy ) {
        return $termlink;
    }

    $base = as_category_base_slug();
    if ( ! $base ) {
        return $termlink;
    }

    $link = str_replace( '/' . $base . '/', '/', $termlink );

    // 主题的固定链接结构不以 / 结尾（/%postname%.html），分类也该保持一致，
    // 否则输出的 /dgzn/ 每次访问都会多吃一次 301 才到 /dgzn。
    global $wp_rewrite;
    if ( isset( $wp_rewrite ) && empty( $wp_rewrite->use_trailing_slashes ) ) {
        $link = untrailingslashit( $link );
    }

    return $link;
}
add_filter( 'term_link', 'as_strip_category_base_link', 10, 3 );
add_filter( 'category_link', 'as_strip_category_base_link', 10, 3 );

/**
 * 注册不带 category 前缀的分类重写规则
 */
function as_category_rewrite_rules( $rules ) {
    if ( ! as_category_base_should_strip() ) {
        return $rules;
    }

    $categories = get_categories( array( 'hide_empty' => false ) );
    if ( empty( $categories ) || is_wp_error( $categories ) ) {
        return $rules;
    }

    $new = array();

    foreach ( $categories as $category ) {
        $slug = $category->slug;

        // 支持父子分类：父/子
        if ( $category->parent ) {
            $parents = get_category_parents( $category->parent, false, '/', true );
            if ( ! is_wp_error( $parents ) ) {
                $slug = $parents . $slug;
            }
        }

        $slug = trim( $slug, '/' );
        if ( '' === $slug ) {
            continue;
        }

        $q = preg_quote( $slug, '#' );

        // ⚠️ slug 必须包在捕获组里，否则 $matches[1] 指向的是别的分组（或根本不存在），
        // category_name 会被赋成空值，WordPress 收到空参数就退化成「文章首页」——
        // 表现就是分类页显示成博客列表。
        $new[ '(' . $q . ')/(?:feed/)?(feed|rdf|rss|rss2|atom)/?$' ] = 'index.php?category_name=$matches[1]&feed=$matches[2]';
        $new[ '(' . $q . ')/page/?([0-9]{1,})/?$' ]                  = 'index.php?category_name=$matches[1]&paged=$matches[2]';
        $new[ '(' . $q . ')/?$' ]                                    = 'index.php?category_name=$matches[1]';
    }

    // 旧地址 /<base>/xxx/ → 301 到新地址
    $base = as_category_base_slug();
    if ( $base ) {
        $new[ preg_quote( $base, '#' ) . '/(.*)$' ] = 'index.php?as_category_redirect=$matches[1]';
    }

    // 整组替换默认的 category 规则；旧地址已由上面的 301 接管
    return $new;
}
add_filter( 'category_rewrite_rules', 'as_category_rewrite_rules' );

/**
 * 处理旧地址 301 跳转
 */
function as_category_base_redirect( $query_vars ) {
    if ( empty( $query_vars['as_category_redirect'] ) ) {
        return $query_vars;
    }

    $path = ltrim( (string) $query_vars['as_category_redirect'], '/' );

    // wp_sanitize_redirect 会剔除控制字符 / 换行 / 引号，防止头部注入；
    // 再交给 wp_safe_redirect 校验域名，避免被构造成开放重定向。
    $path = wp_sanitize_redirect( '/' . $path );
    $url  = user_trailingslashit( home_url( $path ), 'category' );

    wp_safe_redirect( $url, 301 );
    exit;
}
add_filter( 'request', 'as_category_base_redirect' );

/**
 * 规则变更后自动刷新一次伪静态，避免用户手动去点"保存更改"
 */
function as_category_base_maybe_flush() {
    if ( AS_CATEGORY_RULE_VER === get_option( 'as_category_base_rule_ver' ) ) {
        return;
    }

    flush_rewrite_rules( false );
    update_option( 'as_category_base_rule_ver', AS_CATEGORY_RULE_VER, false );
}
add_action( 'init', 'as_category_base_maybe_flush', 99 );

/* ==========================================================================
   产品链接结构
   --------------------------------------------------------------------------
   详情页：/product/{ID}.html         例：/product/123.html
   品类页：/product/{品类别名}/       例：/product/yan-shi-pen-ji/

   两者共用 product 前缀，靠 .html 后缀区分（WordPress 的别名经 sanitize_title
   处理，不允许包含点号，所以 /product/xxx.html 永远只可能是详情页）。

   关键陷阱：CPT 默认会生成一条 `product/([^/]+)/?$` 规则，它会
     ① 吃掉 /product/{品类别名}/ 这个层级
     ② 抢在 .html 规则之前把 /product/123.html 判成一个"别名为 123.html 的产品"
   所以下面要重建 CPT 的规则集，并把 .html 规则用 add_rewrite_rule 'top' 钉到最前面。
   ========================================================================== */

define( 'AS_PERMALINK_VER', '1' );

/**
 * 产品详情链接：/product/{ID}.html
 * 预览草稿时也走这个地址，靠 ?preview= 参数区分，所以这里不做状态判断。
 */
function as_product_permalink( $url, $post ) {
    $post = get_post( $post );

    if ( ! $post || 'plm_product' !== $post->post_type ) {
        return $url;
    }

    return home_url( '/product/' . $post->ID . '.html' );
}
add_filter( 'post_type_link', 'as_product_permalink', 10, 2 );

/**
 * 重建产品 CPT 的重写规则
 * 只保留"归档"相关规则，丢掉所有"按别名匹配单篇"的规则，
 * 把 /product/{一段} 这个层级完整让给品类分类。
 */
function as_product_rewrite_rules( $rules ) {
    return array(
        'product/?$'                                    => 'index.php?post_type=plm_product',
        'product/page/?([0-9]{1,})/?$'                  => 'index.php?post_type=plm_product&paged=$matches[1]',
        'product/(?:feed/)?(feed|rdf|rss|rss2|atom)/?$' => 'index.php?post_type=plm_product&feed=$matches[1]',
    );
}
add_filter( 'plm_product_rewrite_rules', 'as_product_rewrite_rules' );

/**
 * 详情页规则挂到最前面，确保排在品类规则之前
 */
function as_product_detail_rules() {
    add_rewrite_rule(
        '^product/([0-9]+)\.html$',
        'index.php?post_type=plm_product&p=$matches[1]',
        'top'
    );
    add_rewrite_rule(
        '^product/([0-9]+)\.html/(?:feed/)?(feed|rdf|rss|rss2|atom)/?$',
        'index.php?post_type=plm_product&p=$matches[1]&feed=$matches[2]',
        'top'
    );
    add_rewrite_rule(
        '^product/([0-9]+)\.html/page/?([0-9]{1,})/?$',
        'index.php?post_type=plm_product&p=$matches[1]&paged=$matches[2]',
        'top'
    );
}
add_action( 'init', 'as_product_detail_rules', 12 );

/**
 * 旧品类前缀 /product-category/xxx/ → 301 到 /product/xxx/
 */
function as_legacy_taxonomy_base_rule() {
    add_rewrite_rule(
        '^product-category/(.*)$',
        'index.php?as_legacy_product_category=$matches[1]',
        'top'
    );
}
add_action( 'init', 'as_legacy_taxonomy_base_rule', 12 );

function as_legacy_taxonomy_redirect( $query_vars ) {
    if ( empty( $query_vars['as_legacy_product_category'] ) ) {
        return $query_vars;
    }

    $path = ltrim( (string) $query_vars['as_legacy_product_category'], '/' );
    $path = ltrim( wp_sanitize_redirect( '/' . $path ), '/' );

    wp_safe_redirect( user_trailingslashit( home_url( '/product/' . $path ), 'category' ), 301 );
    exit;
}
add_filter( 'request', 'as_legacy_taxonomy_redirect' );

/**
 * 旧的"按别名"产品地址 /product/{中文别名} → 301 到 /product/{ID}.html
 *
 * 这类地址改完之后会落到品类规则上、查不到对应词条而 404，
 * 所以放在 404 时兜底处理——比给每个产品都枚举一条重写规则轻得多，
 * 也不会让规则表随产品数量线性膨胀。
 */
function as_legacy_product_redirect() {
    if ( ! is_404() ) {
        return;
    }

    $path = as_request_path();
    if ( 0 !== strpos( $path, 'product/' ) ) {
        return;
    }

    $slug = substr( $path, strlen( 'product/' ) );

    // 只处理单层的 /product/xxx，多层的交给品类规则
    if ( '' === $slug || false !== strpos( $slug, '/' ) ) {
        return;
    }

    $found = get_posts( array(
        'post_type'      => 'plm_product',
        'name'           => $slug,
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ) );

    if ( ! $found ) {
        return;
    }

    wp_safe_redirect( get_permalink( $found[0] ), 301 );
    exit;
}
add_action( 'template_redirect', 'as_legacy_product_redirect' );

/**
 * 取当前请求路径：已解码、去掉站点子目录前缀、无首尾斜杠
 */function as_request_path() {
    $uri  = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
    $path = parse_url( $uri, PHP_URL_PATH );
    $path = is_string( $path ) ? urldecode( $path ) : '';

    $home = parse_url( home_url(), PHP_URL_PATH );
    if ( $home && '/' !== $home && 0 === strpos( $path, $home ) ) {
        $path = substr( $path, strlen( $home ) );
    }

    return trim( $path, '/' );
}

/**
 * 阻止 canonical 给 /product/{ID}.html 补结尾斜杠
 * 站点的固定链接结构以 / 结尾时，redirect_canonical 会把 /product/123.html
 * 改写成 /product/123.html/，而这条地址匹配不到重写规则，产品页会直接 404。
 * 这里让"已经是标准形式"的地址原样返回，等于取消这次跳转。
 */
function as_disable_canonical_for_product_html( $redirect_url, $requested_url ) {
    if ( preg_match( '#/product/[0-9]+\.html$#', $requested_url ) ) {
        return $requested_url;
    }

    return $redirect_url;
}
add_filter( 'redirect_canonical', 'as_disable_canonical_for_product_html', 10, 2 );

/**
 * 链接结构变更后自动刷新伪静态
 */
function as_permalink_maybe_flush() {
    if ( AS_PERMALINK_VER === get_option( 'as_permalink_rule_ver' ) ) {
        return;
    }

    flush_rewrite_rules( false );
    update_option( 'as_permalink_rule_ver', AS_PERMALINK_VER, false );
}
add_action( 'init', 'as_permalink_maybe_flush', 99 );



/**
 * 产品列表页每页显示数量（Tab 是前端筛选，需要同页展示全部）
 */
function as_product_per_page() {
    return (int) apply_filters( 'as_product_archive_per_page', 100 );
}

/**
 * 主查询调整：产品归档数量 + 搜索结果包含产品
 */
function as_pre_get_posts( $query ) {
    if ( is_admin() || ! $query->is_main_query() ) return;

    if ( $query->is_post_type_archive( 'plm_product' ) || $query->is_tax( 'plm_category' ) || $query->is_tax( 'plm_brand' ) ) {
        $query->set( 'posts_per_page', as_product_per_page() );
    }

    // 搜索表单写的是"搜索产品或文章"，这里把产品也纳入搜索范围
    if ( $query->is_search() && ! $query->get( 'post_type' ) ) {
        $query->set( 'post_type', array( 'post', 'plm_product' ) );
        $query->set( 'posts_per_page', 12 );
    }
}
add_action( 'pre_get_posts', 'as_pre_get_posts' );

/**
 * 产品自定义字段 Meta Box
 */
function as_add_product_meta_box() {
    add_meta_box( 'as_product_fields', '产品信息', 'as_render_product_meta_box', 'plm_product', 'normal', 'high' );
}
add_action( 'add_meta_boxes', 'as_add_product_meta_box' );

/**
 * 产品编辑页的「商品图集」区块。
 *
 * 存储方式：**不新增字段**，直接用 WordPress 原生的「附件归属于文章」机制
 * （get_attached_media('image', $post_id)）。前台
 * inc/miniapp-api.php 的 as_mini_product_images() 已经是按这个读的，
 * 所以这里加图后小程序详情页立刻就有图集，不用改前台任何代码。
 *
 * 顺序：用一个 meta 记录附件 ID 的显示顺序（as_gallery_order），
 * 因为 WP 默认按 ID 升序返回，没法控制。顺序只影响展示顺序。
 *
 * 主图：仍走右侧「特色图像」（_thumbnail_id），与前台约定一致，
 * 这里只做提示，不在这里设主图——避免两处设置打架。
 *
 * @param int $post_id 产品 ID。
 * @return string HTML。
 */
function as_product_gallery_field_html( $post_id ) {
	$post_id = (int) $post_id;

	// 当前图集 = 挂在该产品下的图片附件 + 按记录排序
	$atts = get_attached_media( 'image', $post_id );
	$ids  = wp_list_pluck( $atts ? $atts : array(), 'ID' );

	$order = get_post_meta( $post_id, 'as_gallery_order', true );
	$order = is_array( $order ) ? array_map( 'intval', $order ) : array();

	if ( $order ) {
		// 记录的顺序优先，没记录过的追加在后面
		$sorted = array();
		foreach ( $order as $oid ) {
			if ( in_array( $oid, $ids, true ) ) {
				$sorted[] = $oid;
			}
		}
		foreach ( $ids as $id ) {
			if ( ! in_array( $id, $sorted, true ) ) {
				$sorted[] = (int) $id;
			}
		}
		$ids = $sorted;
	}

	$main_id = (int) get_post_thumbnail_id( $post_id );

	$html  = '<div class="as-gallery" data-post="' . esc_attr( $post_id ) . '">';
	$html .= '<div class="as-gallery-head">';
	$html .= '<h4>商品图集</h4>';
	$html .= '<span class="as-meta-hint">详情页轮播用。← → 调顺序；点「主图」把它设为封面（= 右侧「特色图像」，也是小程序列表图）；× 只从图集移除，不改封面，原图仍在媒体库。</span>';
	$html .= '</div>';

	/*
	 * 未指定主图时给一句说明。
	 *
	 * 主图（特色图像）就是小程序列表的封面 `cover`。没指定也不会空白：
	 * 小程序接口会退回图集第一张（见 as_mini_product_thumb_id()）。
	 * 这里只是告诉用户"想精确指定封面该怎么点"。
	 */
	if ( ! $main_id && $ids ) {
		$html .= '<p class="as-gallery-note">未指定主图：小程序列表的封面会自动取<b>图集第一张</b>。想指定某张作封面，点它上面的「主图」。</p>';
	}

	// 已选图片列表
	$html .= '<ul class="as-gallery-list">';
	if ( $ids ) {
		foreach ( $ids as $id ) {
			$src = wp_get_attachment_image_url( $id, 'thumbnail' );
			if ( ! $src ) {
				continue;
			}
			$is_main = ( $id === $main_id );
			$html   .= '<li class="as-gallery-item' . ( $is_main ? ' is-main' : '' ) . '" data-id="' . esc_attr( $id ) . '">';
			if ( $is_main ) {
				$html .= '<span class="as-gallery-badge">主图</span>';
			}
			$html .= '<img src="' . esc_url( $src ) . '" alt="">';
			$html .= '<div class="as-gallery-ops">';
			$html .= '<button type="button" class="as-gallery-prev" title="前移">←</button>';
			$html .= '<button type="button" class="as-gallery-next" title="后移">→</button>';
			if ( ! $is_main ) {
				$html .= '<button type="button" class="as-gallery-main" title="设为主图（封面）">主图</button>';
			}
			$html .= '<button type="button" class="as-gallery-del" title="从图集移除">×</button>';
			$html .= '</div>';
			$html .= '</li>';
		}
	} else {
		$html .= '<li class="as-gallery-empty">还没有图集，点下面「添加图片」从媒体库选；也可以先上传再选。</li>';
	}
	$html .= '</ul>';

	// 添加按钮
	$html .= '<button type="button" class="button as-gallery-add">添加图片</button> ';
	$html .= '<button type="button" class="button as-gallery-clear"' . ( $ids ? '' : ' style="display:none"' ) . '>清空图集</button>';
	$html .= '</div>';

	return $html;
}

/**
 * 实际销量表：product_id => 已售件数（只统计已支付 / 已发货 / 已完成的订单）。
 * 全站共用一个静态缓存，一次请求只扫一遍订单表。
 *
 * 放在 functions.php 而不是 miniapp-api.php：**网站前台首页的「销量排行」
 * 和小程序端都要用**，属于两端共用口径（和 as_product_cover_id 同理）。
 */
function as_sales_map() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}
	$map = array();
	global $wpdb;
	$rows = $wpdb->get_col(
		"SELECT items FROM {$wpdb->prefix}as_orders WHERE status IN ('paid','shipped','completed') LIMIT 2000"
	);
	foreach ( (array) $rows as $raw ) {
		$items = maybe_unserialize( $raw );
		if ( ! is_array( $items ) ) {
			continue;
		}
		foreach ( $items as $it ) {
			$pid = isset( $it['product_id'] ) ? (int) $it['product_id'] : 0;
			$qty = isset( $it['qty'] ) ? (int) $it['qty'] : 1;
			if ( $pid <= 0 || $qty <= 0 ) {
				continue;
			}
			$map[ $pid ] = ( isset( $map[ $pid ] ) ? (int) $map[ $pid ] : 0 ) + $qty;
		}
	}
	return $map;
}

/** 前台销量 = 实际销量 + 虚拟销量（两端同一口径） */
function as_product_sales( $post_id ) {
	$post_id = (int) $post_id;
	$virtual = (int) get_post_meta( $post_id, 'dg_sales_virtual', true );
	$map     = as_sales_map();
	$actual  = isset( $map[ $post_id ] ) ? (int) $map[ $post_id ] : 0;
	return $actual + $virtual;
}

/**
 * 销量榜前 N 个商品 ID（按前台销量降序）。
 *
 * 两端共用：小程序 `/home` 接口的 `top_sales` 和网站首页「销量排行」都调这个。
 * 商品量级是几十个，一次全量取回再本地排序；若将来上千再改成 SQL 排序。
 */
function as_top_sales_ids( $limit = 8 ) {
	$ids = get_posts(
		array(
			'post_type'      => 'plm_product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);
	$scored = array();
	foreach ( $ids as $id ) {
		$scored[ $id ] = as_product_sales( $id );
	}
	arsort( $scored );
	return array_slice( array_keys( $scored ), 0, $limit );
}

/**
 * 商品封面图 ID —— 全站唯一口径：特色图像优先，没有则退回图集第一张。
 *
 * 为什么要退回：特色图像（`_thumbnail_id`）就是"主图/封面"，小程序列表的 `cover`
 * 和前台详情页的大图都以它为准。但只往图集里加了图、没点「主图」时它是空的 ——
 * 小程序列表会出现没有封面的商品、前台会显示占位灰图（明明有图）。
 * 这里统一退回图集第一张，保证"只要有图就有封面"。
 * 想精确指定封面，在后台图集里点某张图的「主图」即可（会写回特色图像）。
 *
 * ⚠️ `wp_list_pluck()` 会**保留原数组的键**，而 `get_attached_media()` 返回的数组
 * 是以附件 ID 为键的 → 必须 `array_values()`，否则取不到 `[0]`（踩过）。
 *
 * @param int $post_id 商品 ID。
 * @return int 附件 ID；没有任何图片返回 0。
 */
function as_product_cover_id( $post_id ) {
	$post_id = (int) $post_id;

	$thumb = (int) get_post_thumbnail_id( $post_id );
	if ( $thumb ) {
		return $thumb;
	}

	$atts = get_attached_media( 'image', $post_id );
	if ( ! $atts ) {
		return 0;
	}
	$ids = array_values( array_map( 'intval', wp_list_pluck( $atts, 'ID' ) ) );

	// 顺序与后台图集一致：as_gallery_order 优先，其余按 WP 默认顺序追加
	$order = get_post_meta( $post_id, 'as_gallery_order', true );
	if ( is_array( $order ) && $order ) {
		$ordered = array();
		foreach ( array_map( 'intval', $order ) as $oid ) {
			if ( in_array( $oid, $ids, true ) && ! in_array( $oid, $ordered, true ) ) {
				$ordered[] = $oid;
			}
		}
		foreach ( $ids as $aid ) {
			if ( ! in_array( $aid, $ordered, true ) ) {
				$ordered[] = $aid;
			}
		}
		$ids = $ordered;
	}

	return isset( $ids[0] ) ? (int) $ids[0] : 0;
}

/**
 * 前台商品图集：主图 + 附属图，按后台设定的顺序。
 *
 * 与后台 / 小程序共用同一套数据（附件 post_parent + as_gallery_order），
 * 所以后台加完图，这里和小程序同时生效，三端一致。
 *
 * @param int $post_id 产品 ID。
 * @param string $size 取图尺寸。
 * @return array 数组，每项 { id, url, alt }；没有图返回空数组。
 */
function as_product_gallery( $post_id, $size = 'as_product_large' ) {
	$post_id = (int) $post_id;
	$main_id = (int) get_post_thumbnail_id( $post_id );
	$atts    = get_attached_media( 'image', $post_id );
	$ids     = wp_list_pluck( $atts ? $atts : array(), 'ID' );
	$ids     = array_map( 'intval', $ids );

	// 顺序：后台保存的 as_gallery_order 优先，没记录过的追加在后面
	$order = get_post_meta( $post_id, 'as_gallery_order', true );
	if ( is_array( $order ) && $order ) {
		$order  = array_map( 'intval', $order );
		$sorted = array();
		foreach ( $order as $oid ) {
			if ( in_array( $oid, $ids, true ) ) {
				$sorted[] = $oid;
			}
		}
		foreach ( $ids as $id ) {
			if ( ! in_array( $id, $sorted, true ) ) {
				$sorted[] = $id;
			}
		}
		$ids = $sorted;
	}

	// 主图排在最前，并去重
	$all = array();
	if ( $main_id ) {
		$all[] = $main_id;
	}
	foreach ( $ids as $id ) {
		if ( ! in_array( $id, $all, true ) ) {
			$all[] = $id;
		}
	}

	$out = array();
	foreach ( $all as $id ) {
		$url = wp_get_attachment_image_url( $id, $size );
		if ( ! $url ) {
			$url = wp_get_attachment_url( $id );
		}
		if ( ! $url ) {
			continue;
		}
		$out[] = array(
			'id'  => $id,
			'url' => $url,
			'alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
		);
	}
	return $out;
}

/**
 * 前台商品图集 —— 轮播区块 HTML（京东那套：主图轮播 + 缩略图条 + 左右箭头）。
 *
 * 只有一张图时**返回空串**：调用方继续走原来的单图分支，
 * 页面不会出现多余的缩略图条和空箭头 —— 单图商品版面必须和改动前一致。
 *
 * @param int    $post_id 产品 ID。
 * @param string $title   用于无障碍标签。
 * @return string HTML；单图或无图时返回空串。
 */
function as_product_gallery_html( $post_id, $title = '' ) {
	$imgs = as_product_gallery( $post_id, 'as_product_large' );

	if ( count( $imgs ) < 2 ) {
		return '';
	}

	$label = $title ? $title : get_the_title( $post_id );
	$total = count( $imgs );

	$html  = '<div class="as-gallery" data-as-gallery>';
	$html .= '<div class="as-gallery-stage">';

	// 大图：全部输出，靠 is-active 控制显示（京东那种淡入切换）
	foreach ( $imgs as $i => $im ) {
		$html .= '<button type="button" class="as-gallery-slide' . ( 0 === $i ? ' is-active' : '' ) . '"';
		$html .= ' data-index="' . (int) $i . '"';
		$html .= ' aria-label="' . esc_attr( sprintf( '%s 第 %d 张，共 %d 张', $label, $i + 1, $total ) ) . '"';
		$html .= ' tabindex="-1">';
		$html .= '<img src="' . esc_url( $im['url'] ) . '"';
		$html .= ' alt="' . esc_attr( $im['alt'] ? $im['alt'] : $label ) . '"';
		$html .= ' loading="' . ( 0 === $i ? 'eager' : 'lazy' ) . '" decoding="async">';
		$html .= '</button>';
	}

	// 左右箭头
	$html .= '<button type="button" class="as-gallery-nav as-gallery-prev" aria-label="上一张">‹</button>';
	$html .= '<button type="button" class="as-gallery-nav as-gallery-next" aria-label="下一张">›</button>';

	// 计数器（京东右上角那个 1/5）
	$html .= '<span class="as-gallery-counter" aria-hidden="true"><b>1</b> / ' . (int) $total . '</span>';

	$html .= '</div>';

	// 缩略图条
	$html .= '<ul class="as-gallery-thumbs">';
	foreach ( $imgs as $i => $im ) {
		$thumb = wp_get_attachment_image_url( $im['id'], 'as_product_card' );
		if ( ! $thumb ) {
			$thumb = $im['url'];
		}
		$html .= '<li class="as-gallery-thumb-item">';
		$html .= '<button type="button" class="as-gallery-thumb' . ( 0 === $i ? ' is-active' : '' ) . '"';
		$html .= ' data-index="' . (int) $i . '"';
		$html .= ' aria-label="查看第 ' . ( $i + 1 ) . ' 张">';
		$html .= '<img src="' . esc_url( $thumb ) . '" alt="" loading="lazy" decoding="async">';
		$html .= '</button></li>';
	}
	$html .= '</ul>';

	$html .= '</div>';

	return $html;
}

/**
 * AJAX：把图片挂到产品下（进图集）。
 */
function as_gallery_attach() {
	check_ajax_referer( 'as_gallery', 'nonce' );

	$post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
	$ids     = isset( $_POST['ids'] ) ? (array) wp_unslash( $_POST['ids'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

	if ( ! $post_id || 'plm_product' !== get_post_type( $post_id ) ) {
		wp_send_json_error( array( 'msg' => '产品不存在' ), 400 );
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( array( 'msg' => '无权编辑该产品' ), 403 );
	}

	$current = wp_list_pluck( get_attached_media( 'image', $post_id ) ?: array(), 'ID' );
	$current = array_map( 'intval', $current );
	$added   = 0;

	foreach ( $ids as $raw ) {
		$aid = intval( $raw );
		if ( $aid <= 0 || ! wp_attachment_is_image( $aid ) ) {
			continue;
		}
		if ( in_array( $aid, $current, true ) ) {
			continue;
		}
		// 挂到产品下：改 post_parent，前台 get_attached_media('image', $post_id) 就能读到
		wp_update_post( array(
			'ID'          => $aid,
			'post_parent' => $post_id,
		) );
		$current[] = $aid;
		$added++;
	}

	wp_send_json_success( array(
		'added' => $added,
		// 返回最新的图集 HTML：前端局部替换，不用整页刷新（不丢未保存内容）
		'html'  => as_product_gallery_field_html( $post_id ),
		// 当前主图（特色图像）ID：前端拿它同步 WP 原生面板，见下面 detach 的说明
		'thumb' => (int) get_post_thumbnail_id( $post_id ),
	) );
}
add_action( 'wp_ajax_as_gallery_attach', 'as_gallery_attach' );

/**
 * AJAX：从图集移除某张图（只解绑，不删媒体库原图）。
 */
function as_gallery_detach() {
	check_ajax_referer( 'as_gallery', 'nonce' );

	$post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
	$att_id  = isset( $_POST['attachment_id'] ) ? intval( $_POST['attachment_id'] ) : 0;

	if ( ! $post_id || 'plm_product' !== get_post_type( $post_id ) ) {
		wp_send_json_error( array( 'msg' => '产品不存在' ), 400 );
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( array( 'msg' => '无权编辑该产品' ), 403 );
	}

	/*
	 * 只解绑，**不碰封面（特色图像）**。
	 *
	 * 封面和图集是两个概念：封面决定小程序列表的 cover，图集是详情页轮播。
	 * 从图集移除一张图时顺手清掉封面，会让小程序列表**突然没有封面**，
	 * 而后台完全看不出发生了什么（早期版本更糟：直接跳过解绑、却返回 success，
	 * 点 × 看着成功却没变化）。所以这里两条都不做：
	 * 该解绑的照常解绑，封面留给另一张图的「主图」按钮去换。
	 */
	if ( $att_id > 0 ) {
		wp_update_post( array( 'ID' => $att_id, 'post_parent' => 0 ) );
	}

	// 从顺序记录里剔除
	$order = get_post_meta( $post_id, 'as_gallery_order', true );
	if ( is_array( $order ) ) {
		$order = array_values( array_diff( array_map( 'intval', $order ), array( $att_id ) ) );
		update_post_meta( $post_id, 'as_gallery_order', $order );
	}

	wp_send_json_success( array(
		'html'  => as_product_gallery_field_html( $post_id ),
		/*
		 * 🔴 必须把「当前主图 ID」一起回给前端（v2.61.1）。
		 *
		 * 原因：WordPress 的表单里有个隐藏字段 `_thumbnail_id`，值取自**页面渲染那一刻**
		 * （没有特色图像时是 -1）。我们在这几个 AJAX 里改了特色图像，但那个字段还是旧值，
		 * 于是用户点「更新」提交表单时，wp_insert_post() 会拿旧值把刚设好的主图覆盖掉
		 * （-1 等于删除）—— 表现为「网页刷新后主图没被标记」。
		 * 前端拿到这个值会同步回那个字段（并刷新 WP 的特色图像面板）。
		 */
		'thumb' => (int) get_post_thumbnail_id( $post_id ),
	) );
}
add_action( 'wp_ajax_as_gallery_detach', 'as_gallery_detach' );

/**
 * AJAX：保存图集显示顺序。
 */
function as_gallery_save_order() {
	check_ajax_referer( 'as_gallery', 'nonce' );

	$post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
	$order   = isset( $_POST['order'] ) ? (array) wp_unslash( $_POST['order'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

	if ( ! $post_id || 'plm_product' !== get_post_type( $post_id ) ) {
		wp_send_json_error( array( 'msg' => '产品不存在' ), 400 );
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( array( 'msg' => '无权编辑该产品' ), 403 );
	}

	// 只保留仍然挂在该产品下的附件，避免被前端伪造
	$valid = array_map( 'intval', wp_list_pluck( get_attached_media( 'image', $post_id ) ?: array(), 'ID' ) );
	$clean = array();
	foreach ( $order as $raw ) {
		$aid = intval( $raw );
		if ( in_array( $aid, $valid, true ) && ! in_array( $aid, $clean, true ) ) {
			$clean[] = $aid;
		}
	}
	// 有效但前端没传的，追加到末尾，避免丢图
	foreach ( $valid as $aid ) {
		if ( ! in_array( $aid, $clean, true ) ) {
			$clean[] = $aid;
		}
	}

	update_post_meta( $post_id, 'as_gallery_order', $clean );
	wp_send_json_success( array(
		'html'  => as_product_gallery_field_html( $post_id ),
		/*
		 * 🔴 必须把「当前主图 ID」一起回给前端（v2.61.1）。
		 *
		 * 原因：WordPress 的表单里有个隐藏字段 `_thumbnail_id`，值取自**页面渲染那一刻**
		 * （没有特色图像时是 -1）。我们在这几个 AJAX 里改了特色图像，但那个字段还是旧值，
		 * 于是用户点「更新」提交表单时，wp_insert_post() 会拿旧值把刚设好的主图覆盖掉
		 * （-1 等于删除）—— 表现为「网页刷新后主图没被标记」。
		 * 前端拿到这个值会同步回那个字段（并刷新 WP 的特色图像面板）。
		 */
		'thumb' => (int) get_post_thumbnail_id( $post_id ),
	) );
}
add_action( 'wp_ajax_as_gallery_save_order', 'as_gallery_save_order' );

/**
 * AJAX：清空图集（全部解绑，主图不动）。
 */
function as_gallery_clear() {
	check_ajax_referer( 'as_gallery', 'nonce' );

	$post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
	if ( ! $post_id || 'plm_product' !== get_post_type( $post_id ) ) {
		wp_send_json_error( array( 'msg' => '产品不存在' ), 400 );
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( array( 'msg' => '无权编辑该产品' ), 403 );
	}

	$main = (int) get_post_thumbnail_id( $post_id );
	foreach ( wp_list_pluck( get_attached_media( 'image', $post_id ) ?: array(), 'ID' ) as $aid ) {
		$aid = (int) $aid;
		if ( $aid && $aid !== $main ) {
			wp_update_post( array( 'ID' => $aid, 'post_parent' => 0 ) );
		}
	}
	delete_post_meta( $post_id, 'as_gallery_order' );

	wp_send_json_success( array(
		'html'  => as_product_gallery_field_html( $post_id ),
		/*
		 * 🔴 必须把「当前主图 ID」一起回给前端（v2.61.1）。
		 *
		 * 原因：WordPress 的表单里有个隐藏字段 `_thumbnail_id`，值取自**页面渲染那一刻**
		 * （没有特色图像时是 -1）。我们在这几个 AJAX 里改了特色图像，但那个字段还是旧值，
		 * 于是用户点「更新」提交表单时，wp_insert_post() 会拿旧值把刚设好的主图覆盖掉
		 * （-1 等于删除）—— 表现为「网页刷新后主图没被标记」。
		 * 前端拿到这个值会同步回那个字段（并刷新 WP 的特色图像面板）。
		 */
		'thumb' => (int) get_post_thumbnail_id( $post_id ),
	) );
}
add_action( 'wp_ajax_as_gallery_clear', 'as_gallery_clear' );

/**
 * AJAX：把某张图集图片设为产品主图（写入特色图像 _thumbnail_id）。
 *
 * 图集本身存的是「附件归属于产品」，主图走 WordPress 原生的特色图像，
 * 两者是两套机制；这里把图集里选的图提升为主图，省得再去右侧面板选一遍。
 */
function as_gallery_set_main() {
	check_ajax_referer( 'as_gallery', 'nonce' );

	$post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
	$att_id  = isset( $_POST['attachment_id'] ) ? intval( $_POST['attachment_id'] ) : 0;

	if ( ! $post_id || 'plm_product' !== get_post_type( $post_id ) ) {
		wp_send_json_error( array( 'msg' => '产品不存在' ), 400 );
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( array( 'msg' => '无权编辑该产品' ), 403 );
	}
	if ( $att_id <= 0 || ! wp_attachment_is_image( $att_id ) ) {
		wp_send_json_error( array( 'msg' => '这张图片不存在' ), 400 );
	}

	// 必须先确保它挂在产品下，否则前台图集列表里看不到它
	wp_update_post( array( 'ID' => $att_id, 'post_parent' => $post_id ) );
	set_post_thumbnail( $post_id, $att_id );

	wp_send_json_success( array(
		'html'  => as_product_gallery_field_html( $post_id ),
		/*
		 * 🔴 必须把「当前主图 ID」一起回给前端（v2.61.1）。
		 *
		 * 原因：WordPress 的表单里有个隐藏字段 `_thumbnail_id`，值取自**页面渲染那一刻**
		 * （没有特色图像时是 -1）。我们在这几个 AJAX 里改了特色图像，但那个字段还是旧值，
		 * 于是用户点「更新」提交表单时，wp_insert_post() 会拿旧值把刚设好的主图覆盖掉
		 * （-1 等于删除）—— 表现为「网页刷新后主图没被标记」。
		 * 前端拿到这个值会同步回那个字段（并刷新 WP 的特色图像面板）。
		 */
		'thumb' => (int) get_post_thumbnail_id( $post_id ),
	) );
}
add_action( 'wp_ajax_as_gallery_set_main', 'as_gallery_set_main' );

/**
 * 产品编辑页加载逐行编辑器脚本（优点 / 缺点 / 自定义属性）
 */
function as_admin_product_fields_script( $hook ) {
    if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
        return;
    }

    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || 'plm_product' !== $screen->post_type ) {
        return;
    }

    wp_enqueue_script(
        'as-product-fields',
        get_template_directory_uri() . '/assets/js/product-fields.js',
        array(),
        as_asset_version( '/assets/js/product-fields.js' ),
        true
    );

    /*
     * 图集脚本：wp.media 选图 + 删除 / 设主图 / 调整顺序。
     *
     * wp_enqueue_media() 必须在这里显式调 —— 产品编辑页默认不加载 wp.media，
     * 不加的话 product-gallery.js 里的 `if (! wp.media)` 会命中并直接return，
     * 表现为点「添加图片」毫无反应。
     */
    wp_enqueue_media();

    wp_enqueue_script(
        'as-product-gallery',
        get_template_directory_uri() . '/assets/js/product-gallery.js',
        // 依赖 media-views：wp_enqueue_media() 注册的句柄，保证 wp.media 先于本脚本就绪
        array( 'as-product-fields', 'media-views' ),
        as_asset_version( '/assets/js/product-gallery.js' ),
        true
    );

    // 图集操作走 AJAX，需要 nonce 与产品 ID
    wp_localize_script( 'as-product-gallery', 'asGallery', array(
        'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
        'nonce'        => wp_create_nonce( 'as_gallery' ),
        // postId 是兜底：admin_enqueue_scripts 阶段主循环未建立时可能为 0，
        // 前端优先读 DOM 上的 data-post。见 product-gallery.js 的 postId()。
        'postId'       => get_the_ID() ? (int) get_the_ID() : 0,
        'confirmDel'   => '把这张图从图集移除？媒体库里的原图不会被删除。',
        'confirmClear' => '清空图集？主图不受影响，媒体库原图不会被删除。',
    ) );

    /*
     * 关联文章选择器：搜索走 WP 自带 REST（不新增接口），保存走原生表单提交。
     * 只在产品编辑页入队，和另外两个脚本一起工作。
 */
    wp_enqueue_script(
        'as-product-related',
        get_template_directory_uri() . '/assets/js/product-related.js',
        array( 'jquery' ),
        as_asset_version( '/assets/js/product-related.js' ),
        true
    );

    wp_localize_script( 'as-product-related', 'asRelated', array(
        'restUrl' => esc_url_raw( rest_url( 'wp/v2/posts' ) ),
        'max'     => function_exists( 'as_related_articles_limit' ) ? as_related_articles_limit() : 3,
        'i18n'    => array(
            'searching' => '搜索中…',
            'empty'     => '没有找到已发布的文章',
            'full'      => '最多 3 篇，要换先移除一篇',
            'ph'        => '输入标题关键词搜索文章…',
        ),
    ) );
}
add_action( 'admin_enqueue_scripts', 'as_admin_product_fields_script' );

/**
 * 产品字段配置（Meta Box、保存、演示导入共用同一份定义）
 *
 * 必备属性：主图（特色图像）、标题、品牌（分类法）、购买链接、现价、原价、商品详情（正文）
 * 补充属性：填了才在前台显示
 * 自定义属性：最多三个，方便前端按统一样式渲染
 */
/**
 * 「效果类型」的候选值 —— 唯一来源。
 *
 * 属性面板的下拉、后台列表列的下拉、行内编辑的校验都取这里，
 * 以后要改选项（比如加「超强」）只改这一处。
 */
function as_effect_options() {
    return array( '温和', '均衡', '强效' );
}

function as_product_fields_config() {
    return array(
        'required' => array(
            'title'  => '必备信息',
            'desc'   => '主图请在右侧「特色图像」设置，标题用上方标题栏，品牌在右侧「品牌」里勾选，商品详情写在正文编辑器。',
            'fields' => array(
                'dg_buy_url'   => array( 'label' => '购买链接', 'type' => 'url',    'placeholder' => 'https://...' ),
                'dg_price'     => array( 'label' => '现价',     'type' => 'text',   'placeholder' => '例如 199.00' ),
                'dg_old_price' => array( 'label' => '原价',     'type' => 'text',   'placeholder' => '例如 299.00' ),
                'dg_sales_virtual' => array( 'label' => '虚拟销量', 'type' => 'number', 'placeholder' => '例如 500', 'desc' => '前台销量 = 实际销量 + 虚拟销量。只填数字即可。' ),
            ),
        ),
        'optional' => array(
            'title'  => '补充信息（选填）',
            'desc'   => '留空的属性不会在前台显示，不会出现空行。「优点 / 缺点」可以点 + 逐行添加，留空的行会被自动忽略。',
            'fields' => array(
                /*
                 * 效果类型：三选一的产品属性（不是分类法，别和「品类」混）。
                 * 值会作为一行「效果类型」进规格参数表 → 网站详情页与小程序详情页同时显示。
                 */
                'dg_effect'      => array(
                    'label'       => '效果类型',
                    'type'        => 'select',
                    'empty_label' => '未设置',
                    'options'     => as_effect_options(),
                    'desc'        => '三选一。会显示在详情页的规格参数表里（网站与小程序共用），留空则不显示这一行。',
                ),
                'dg_spec'        => array( 'label' => '规格',     'type' => 'text',     'placeholder' => '例如 10ml' ),
                'dg_audience'    => array( 'label' => '适合人群', 'type' => 'text',     'placeholder' => '例如 基础时间 2-5 分钟' ),
                'dg_ingredients' => array( 'label' => '成分',     'type' => 'textarea', 'placeholder' => '主要成分，会显示在详情页的规格参数表里' ),
                // 'dg_coupon'（优惠信息）字段已彻底下线：优惠统一由「小程序商城 → 优惠券」
                // 管理，避免两套优惠并存。编辑界面不再出现该字段、前台也不读取；
                // 已确认线上数据库无残留数据（wp_postmeta 里 dg_coupon 为 0 行），
                // 因此不提供迁移入口，也不需要清理脚本。
                'dg_reason'      => array( 'label' => '推荐理由', 'type' => 'textarea', 'placeholder' => '一句话说清为什么推荐它' ),
                'dg_pros'        => array(
                    'label'       => '优点',
                    'type'        => 'rows',
                    'placeholder' => '例如 体感自然',
                    'desc'        => '一条一行，详情页左侧显示，带 ✓ 图标。',
                ),
                'dg_cons'        => array(
                    'label'       => '缺点',
                    'type'        => 'rows',
                    'placeholder' => '例如 价格偏高',
                    'desc'        => '一条一行，详情页右侧显示，带 ✕ 图标。',
                ),
            ),
        ),
        'custom' => array(
            'title'  => '自定义属性',
            'desc'   => '每行填「名称」和「值」两个框，点 + 可继续添加，数量不限。会按顺序显示在详情页右侧的规格参数表里。',
            'fields' => array(
                'dg_custom' => array(
                    'label' => '自定义属性',
                    'type'  => 'pairs',
                    'desc'  => '名称留空时，这一行会把「值」整条显示在参数表里。',
                ),
            ),
        ),
        'relation' => array(
            'title'  => '关联与运营',
            'desc'   => '控制详情页「相关推荐」下方展示哪些相关文章。「首页热门推荐 / 首页限时抢购」的开关在商品列表页勾选。',
            'fields' => array(
                'dg_related_posts' => array(
                    'label' => '关联文章',
                    'type'  => 'posts',
                    'desc'  => '最多 3 篇，可上下排序。详情页「相关文章」优先按这里显示；不够 3 篇时，自动用下面「关联文章分类」里的最新文章补足。搜不到说明文章还没发布。',
                ),
                'dg_related_cat' => array(
                    'label' => '关联文章分类（兜底）',
                    'type'  => 'post_categories',
                    'desc'  => '可多选。上面「关联文章」没选满 3 篇时，用这里选中的分类下最新文章补足；两处都不选才不显示相关文章区块。',
                ),
                // dg_hot / dg_flash v2.84.4 起移到「小程序商城 → 商城功能设置」
                // 集中勾选（唯一入口），理由同上：避免两个入口互相覆盖。
            ),
        ),
    );
}

/**
 * 取某个字段的配置
 */
function as_product_field_config( $field ) {
    foreach ( as_product_fields_config() as $group ) {
        if ( isset( $group['fields'][ $field ] ) ) {
            return $group['fields'][ $field ];
        }
    }
    return array();
}

/**
 * 取某个字段的类型
 */
function as_product_field_type( $field ) {
    $config = as_product_field_config( $field );
    return isset( $config['type'] ) ? $config['type'] : 'text';
}

/**
 * 所有产品字段的 meta key
 */
function as_product_all_field_keys() {
    $keys = array();
    foreach ( as_product_fields_config() as $group ) {
        $keys = array_merge( $keys, array_keys( $group['fields'] ) );
    }
    return $keys;
}

/**
 * 需要按多行文本清洗的字段（从字段配置里自动推导）
 */
function as_product_textarea_keys() {
    $keys = array();

    foreach ( as_product_fields_config() as $group ) {
        foreach ( $group['fields'] as $key => $field ) {
            if ( isset( $field['type'] ) && 'textarea' === $field['type'] ) {
                $keys[] = $key;
            }
        }
    }

    return $keys;
}

function as_render_product_meta_box( $post ) {
    wp_nonce_field( 'as_save_product_meta', 'as_product_meta_nonce' );
    ?>
    <style>
        /* 产品属性面板 —— 表格布局：左列标签、右列控件，逐行对齐，不受字段类型影响 */
        .as-meta-group { margin: 0 0 24px; }
        .as-meta-group:last-child { margin-bottom: 0; }
        .as-meta-group-title {
            margin: 0 0 10px;
            padding: 8px 12px;
            background: #f6f7f7;
            border-left: 3px solid #c2185b;
            font-size: 13px;
            font-weight: 600;
            line-height: 1.4;
        }
        .as-meta-group-desc { margin: 0 0 12px; color: #666; font-size: 12px; line-height: 1.6; }

        .as-meta-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .as-meta-table th,
        .as-meta-table td {
            padding: 9px 0;
            vertical-align: top;
            text-align: left;
            border-bottom: 1px solid #f0f0f1;
        }
        .as-meta-table tr:last-child th,
        .as-meta-table tr:last-child td { border-bottom: none; }
        .as-meta-table th { width: 132px; padding-right: 16px; }
        .as-meta-table th label {
            display: block;
            padding-top: 4px;
            color: #1d2327;
            font-size: 13px;
            font-weight: 600;
        }
        .as-meta-table td input[type="text"],
        .as-meta-table td input[type="url"],
        .as-meta-table td input[type="number"],
        .as-meta-table td select,
        .as-meta-table td textarea { width: 100%; box-sizing: border-box; }
        .as-meta-table td textarea { min-height: 62px; }

        /* 整行控件（复选框） */
        .as-meta-row-full td { padding-top: 4px; }
        .as-meta-row-full label { display: block; line-height: 1.7; }
        .as-meta-row-full input[type="checkbox"] { margin-right: 6px; vertical-align: middle; }
        .as-meta-hint { color: #666; font-size: 12px; font-weight: 400; }

        /* 文章分类多选 */
        .as-meta-cats {
            max-height: 180px;
            overflow-y: auto;
            border: 1px solid #dcdcde;
            border-radius: 4px;
            padding: 8px 12px;
            background: #fff;
        }
        .as-meta-cats label { display: block; padding: 3px 0; }
        .as-meta-cats input[type="checkbox"] { margin-right: 6px; }
        .as-meta-value-desc { margin: 8px 0 0; color: #666; font-size: 12px; line-height: 1.6; }

        /* 逐行编辑器（优点 / 缺点 / 自定义属性） */
        .as-rp-list { display: flex; flex-direction: column; gap: 8px; }
        .as-rp-row { display: flex; align-items: flex-start; gap: 8px; }
        .as-rp-row input[type="text"] { flex: 1 1 auto; min-width: 0; }
        .as-rp-row textarea { flex: 1 1 auto; min-width: 0; min-height: 54px; resize: vertical; }
        .as-rp-del {
            flex: 0 0 auto;
            width: 30px;
            height: 30px;
            padding: 0;
            border: 1px solid #dcdcde;
            border-radius: 4px;
            background: #fff;
            color: #b32d2e;
            font-size: 16px;
            line-height: 1;
            cursor: pointer;
        }
        .as-rp-del:hover { border-color: #b32d2e; background: #fcf0f0; }
        .as-rp-add {
            margin-top: 10px;
            padding: 4px 12px;
            border: 1px dashed #c3c4c7;
            border-radius: 4px;
            background: #f6f7f7;
            color: #2271b1;
            font-size: 12px;
            cursor: pointer;
        }
        .as-rp-add:hover { border-color: #2271b1; background: #f0f6fc; }
        /* 自定义属性：名称窄、值宽 */
        .as-rp[data-type="pairs"] .as-rp-row input:first-child { flex: 0 0 30%; }
        /* 逐行编辑器在单元格里，宽度交给 flex，别被表格的 width:100% 撑坏 */
        .as-meta-table td .as-rp-row input[type="text"],
        .as-meta-table td .as-rp-row textarea { width: auto; min-height: 0; }
        .as-meta-table td .as-rp-row textarea { min-height: 54px; }

        /* ---------- 商品图集 ---------- */
        .as-gallery-head {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }
        .as-gallery-head h4 { margin: 0; font-size: 14px; }
        .as-gallery-head .as-meta-hint { font-weight: 400; }
        .as-gallery-note {
            margin: 0 0 10px;
            padding: 6px 10px;
            border-left: 4px solid #2271b1;
            background: #f0f6fc;
            color: #1d2327;
            font-size: 12px;
            line-height: 1.6;
        }
        .as-gallery-list { display: flex; flex-wrap: wrap; gap: 10px; margin: 0 0 10px; padding: 0; list-style: none; }
        .as-gallery-item {
            position: relative;
            width: 96px;
            border: 1px solid #dcdcde;
            border-radius: 4px;
            background: #fff;
            padding: 4px;
        }
        .as-gallery-item.is-main { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; }
        .as-gallery-item img { display: block; width: 100%; height: 72px; object-fit: cover; border-radius: 2px; }
        .as-gallery-ops { display: flex; gap: 4px; margin-top: 4px; }
        .as-gallery-ops button {
            flex: 1;
            font-size: 12px;
            line-height: 1.6;
            padding: 2px 0;
            cursor: pointer;
            border: 1px solid #dcdcde;
            border-radius: 3px;
            background: #f6f7f7;
        }
        .as-gallery-ops button:hover { border-color: #2271b1; color: #2271b1; }
        .as-gallery-ops button.as-gallery-del:hover { border-color: #d63638; color: #d63638; }
        .as-gallery-badge {
            position: absolute;
            top: 8px;
            left: 8px;
            background: #2271b1;
            color: #fff;
            font-size: 11px;
            line-height: 1.5;
            padding: 1px 6px;
            border-radius: 2px;
        }
        .as-gallery-empty {
            padding: 14px;
            border: 1px dashed #c3c4c7;
            border-radius: 4px;
            color: #787c82;
            font-size: 13px;
            margin-bottom: 10px;
        }
        /* ===== 关联文章选择器（v2.62.0）===== */
        .as-rel-box { max-width: 560px; }
        .as-rel-items { margin: 0 0 8px; padding: 0; list-style: none; }
        .as-rel-items:empty::after {
            content: '还没选文章（下面搜索添加）';
            display: block;
            padding: 4px 0;
            color: #646970;
            font-size: 12px;
        }
        .as-rel-item {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 4px;
            padding: 4px 6px;
            border: 1px solid #dcdcde;
            border-radius: 3px;
            background: #fff;
        }
        .as-rel-ops { display: flex; flex-shrink: 0; gap: 2px; }
        .as-rel-ops button,
        .as-rel-del {
            padding: 1px 6px;
            border: 1px solid #dcdcde;
            border-radius: 3px;
            background: #f6f7f7;
            line-height: 1.5;
            cursor: pointer;
        }
        .as-rel-ops button:hover:not(:disabled) { border-color: #2271b1; color: #2271b1; }
        .as-rel-ops button:disabled { opacity: .4; cursor: default; }
        .as-rel-del { margin-left: auto; color: #d63638; }
        .as-rel-del:hover { border-color: #d63638; }
        .as-rel-title { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .as-rel-search { position: relative; }
        .as-rel-results {
            position: absolute;
            z-index: 20;
            right: 0;
            left: 0;
            max-height: 240px;
            overflow-y: auto;
            margin-top: 2px;
            border: 1px solid #8c8f94;
            border-radius: 3px;
            background: #fff;
        }
        .as-rel-results button {
            display: block;
            width: 100%;
            padding: 6px 10px;
            border: 0;
            background: none;
            font-size: 13px;
            line-height: 1.5;
            text-align: left;
            cursor: pointer;
        }
        .as-rel-results button:hover:not(:disabled) { background: #f0f6fc; }
        .as-rel-results button:disabled { color: #8c8f94; cursor: default; }
        .as-rel-empty { padding: 8px 10px; color: #646970; font-size: 12px; }
        .as-rel-box.is-full .as-meta-value-desc { color: #d63638; }

    </style>
    <?php

/*
 * 商品图集：**只在循环外渲染一次**，放在「必备信息」之前。
 * （曾误放在 foreach 内 → 每个分组都重复输出一个图集区块；
 *   也曾写成 if ( isset( $group['gallery'] ) )，但循环外根本没有 $group。）
 */
    echo '<div class="as-meta-group">';
    echo '<table class="as-meta-table as-gallery-table"><tbody>';
    echo '<tr class="as-meta-row-full"><td colspan="2">';
    echo as_product_gallery_field_html( $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 内部已全部转义
    echo '</td></tr></tbody></table>';
    echo '</div>';

    foreach ( as_product_fields_config() as $group ) {
        echo '<div class="as-meta-group">';
        echo '<h4 class="as-meta-group-title">' . esc_html( $group['title'] ) . '</h4>';
        if ( ! empty( $group['desc'] ) ) {
            echo '<p class="as-meta-group-desc">' . esc_html( $group['desc'] ) . '</p>';
        }
        echo '<table class="as-meta-table"><tbody>';

        foreach ( $group['fields'] as $key => $field ) {
            $value       = get_post_meta( $post->ID, $key, true );
            $type        = isset( $field['type'] ) ? $field['type'] : 'text';
            $placeholder = isset( $field['placeholder'] ) ? $field['placeholder'] : '';

            // 复选框：整行显示（跨两列）
            if ( 'checkbox' === $type ) {
                $checked = in_array( (string) $value, array( '1', '是', 'yes', 'on' ), true );
                echo '<tr class="as-meta-row-full"><td colspan="2">';
                echo '<label for="' . esc_attr( $key ) . '">';
                echo '<input type="checkbox" name="' . esc_attr( $key ) . '" id="' . esc_attr( $key ) . '" value="1" ' . checked( $checked, true, false ) . '>';
                echo '<strong>' . esc_html( $field['label'] ) . '</strong>';
                if ( ! empty( $field['text'] ) ) {
                    echo ' <span class="as-meta-hint">' . esc_html( $field['text'] ) . '</span>';
                }
                echo '</label>';
                echo '</td></tr>';
                continue;
            }

            echo '<tr>';
            echo '<th scope="row"><label for="' . esc_attr( $key ) . '">' . esc_html( $field['label'] ) . '</label></th>';
            echo '<td>';

            // 文章分类多选
            if ( 'post_categories' === $type ) {
                $cats     = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false, 'number' => 300 ) );
                $selected = is_array( $value ) ? array_map( 'intval', $value ) : array();

                echo '<div class="as-meta-cats">';
                if ( $cats && ! is_wp_error( $cats ) ) {
                    foreach ( $cats as $cat ) {
                        echo '<label><input type="checkbox" name="' . esc_attr( $key ) . '[]" value="' . intval( $cat->term_id ) . '" '
                            . checked( in_array( (int) $cat->term_id, $selected, true ), true, false ) . '>'
                            . esc_html( $cat->name )
                            . ' <span class="as-meta-hint">(' . intval( $cat->count ) . ')</span></label>';
                    }
                } else {
                    echo '<em>还没有文章分类，先去「文章 → 分类」建几个。</em>';
                }
                echo '</div>';
                if ( ! empty( $field['desc'] ) ) {
                    echo '<p class="as-meta-value-desc">' . esc_html( $field['desc'] ) . '</p>';
                }
                echo '</td></tr>';
                continue;
            }

            /*
             * 关联文章：搜索 + 已选列表（可排序）。
             * 已选项的 DOM 顺序就是展示顺序，每项带一个 hidden input（name=字段名[]），
             * 保存走 WP 原生表单提交 —— 不额外发 AJAX，也就不会和「更新」按钮抢数据。
             */
            if ( 'posts' === $type ) {
                $limit = as_related_articles_limit();
                $sel   = as_product_related_posts( $post->ID );

                echo '<div class="as-rel-box" data-key="' . esc_attr( $key ) . '" data-max="' . intval( $limit ) . '">';

                echo '<ul class="as-rel-items">';
                foreach ( $sel as $sid ) {
                    $sp = get_post( $sid );
                    if ( ! $sp ) {
                        continue;
                    }
                    echo '<li class="as-rel-item" data-id="' . intval( $sid ) . '">';
                    echo '<span class="as-rel-ops"><button type="button" class="as-rel-up" aria-label="上移">↑</button>'
                        . '<button type="button" class="as-rel-down" aria-label="下移">↓</button></span>';
                    echo '<span class="as-rel-title">' . esc_html( get_the_title( $sid ) )
                        . ( 'publish' === $sp->post_status ? '' : '（未发布，前台不显示）' ) . '</span>';
                    echo '<button type="button" class="as-rel-del" aria-label="移除">&times;</button>';
                    echo '<input type="hidden" name="' . esc_attr( $key ) . '[]" value="' . intval( $sid ) . '">';
                    echo '</li>';
                }
                echo '</ul>';

                echo '<div class="as-rel-search">';
                echo '<input type="text" class="as-rel-input" placeholder="输入标题关键词搜索文章…" autocomplete="off">';
                echo '<div class="as-rel-results" hidden></div>';
                echo '</div>';

                echo '<p class="as-meta-value-desc">已选 <span class="as-rel-count">' . count( $sel ) . '</span> / ' . intval( $limit ) . ' 篇';
                if ( ! empty( $field['desc'] ) ) {
                    echo ' · ' . esc_html( $field['desc'] );
                }
                echo '</p>';
                echo '</div>';

                echo '</td></tr>';
                continue;
            }

            // 逐行编辑器：rows（每行一个输入框）/ pairs（每行「名称 + 值」两个输入框）
            if ( 'rows' === $type || 'pairs' === $type ) {
                $item_type = isset( $field['item'] ) ? $field['item'] : 'text';
                $items     = array();

                if ( 'pairs' === $type ) {
                    foreach ( as_product_custom_attrs( $post->ID ) as $pair ) {
                        $items[] = array( 'name' => $pair['label'], 'value' => $pair['value'] );
                    }
                } else {
                    foreach ( as_split_lines( is_string( $value ) ? $value : '' ) as $line ) {
                        $items[] = array( 'text' => $line );
                    }
                }

                // 一条内容都没有时给一个空行，方便直接开始填
                if ( ! $items ) {
                    $items[] = ( 'pairs' === $type )
                        ? array( 'name' => '', 'value' => '' )
                        : array( 'text' => '' );
                }

                echo '<div class="as-rp"'
                    . ' data-key="' . esc_attr( $key ) . '"'
                    . ' data-type="' . esc_attr( $type ) . '"'
                    . ' data-item="' . esc_attr( $item_type ) . '"'
                    . ' data-placeholder="' . esc_attr( $placeholder ) . '">';
                echo '<div class="as-rp-list">';

                foreach ( $items as $item ) {
                    echo '<div class="as-rp-row">';

                    if ( 'pairs' === $type ) {
                        echo '<input type="text" name="' . esc_attr( $key ) . '_name[]" value="' . esc_attr( $item['name'] ) . '" placeholder="名称">';
                        echo '<input type="text" name="' . esc_attr( $key ) . '_value[]" value="' . esc_attr( $item['value'] ) . '" placeholder="值">';
                    } elseif ( 'textarea' === $item_type ) {
                        echo '<textarea name="' . esc_attr( $key ) . '[]" rows="2" placeholder="' . esc_attr( $placeholder ) . '">' . esc_textarea( $item['text'] ) . '</textarea>';
                    } else {
                        echo '<input type="text" name="' . esc_attr( $key ) . '[]" value="' . esc_attr( $item['text'] ) . '" placeholder="' . esc_attr( $placeholder ) . '">';
                    }

                    echo '<button type="button" class="as-rp-del" aria-label="删除这一行">&times;</button>';
                    echo '</div>';
                }

                echo '</div>';
                echo '<button type="button" class="as-rp-add">+ 添加一行</button>';
                if ( ! empty( $field['desc'] ) ) {
                    echo '<p class="as-meta-value-desc">' . esc_html( $field['desc'] ) . '</p>';
                }
                echo '</td></tr>';
                continue;
            }

            if ( 'select' === $type ) {
                echo '<select name="' . esc_attr( $key ) . '" id="' . esc_attr( $key ) . '">';
                echo '<option value="">' . esc_html( isset( $field['empty_label'] ) ? $field['empty_label'] : '请选择' ) . '</option>';
                foreach ( $field['options'] as $opt ) {
                    echo '<option value="' . esc_attr( $opt ) . '" ' . selected( $value, $opt, false ) . '>' . esc_html( $opt ) . '</option>';
                }
                echo '</select>';
            } elseif ( 'textarea' === $type ) {
                $rows = isset( $field['rows'] ) ? intval( $field['rows'] ) : 3;
                echo '<textarea name="' . esc_attr( $key ) . '" id="' . esc_attr( $key ) . '" rows="' . $rows . '" placeholder="' . esc_attr( $placeholder ) . '">' . esc_textarea( is_string( $value ) ? $value : '' ) . '</textarea>';
            } else {
                echo '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $key ) . '" id="' . esc_attr( $key ) . '" value="' . esc_attr( is_scalar( $value ) ? $value : '' ) . '" placeholder="' . esc_attr( $placeholder ) . '">';
            }

            // 普通控件的说明文字（逐行编辑器 / 分类多选已各自处理）
            if ( ! empty( $field['desc'] ) ) {
                echo '<p class="as-meta-value-desc">' . esc_html( $field['desc'] ) . '</p>';
            }

            echo '</td></tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }
}

function as_save_product_meta( $post_id ) {
    if ( ! isset( $_POST['as_product_meta_nonce'] ) || ! wp_verify_nonce( $_POST['as_product_meta_nonce'], 'as_save_product_meta' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    $textareas = as_product_textarea_keys();

    /*
     * ⚠️ 本函数要求**整表单提交**：数组类字段（post_categories / posts）如果不在 $_POST 里，
     *    会被当成「用户把勾选都取消了」而 delete_post_meta。
     *    后台的 Meta Box 天然满足这个前提（所有字段一起提交），
     *    但**千万不要用「部分字段的 $_POST」调它**（测试脚本、局部 AJAX 保存都会中招 ——
     *    2026-10-09 就因为这个把商品 360 的「关联文章分类」冲掉过一次）。
     */
    foreach ( as_product_all_field_keys() as $field ) {
        $type = as_product_field_type( $field );

        // 复选框：未勾选时不会进 $_POST，必须显式删除，否则旧值会一直留着
        if ( 'checkbox' === $type ) {
            if ( empty( $_POST[ $field ] ) ) {
                delete_post_meta( $post_id, $field );
            } else {
                update_post_meta( $post_id, $field, '1' );
            }
            continue;
        }

        // 文章分类多选：存成 term_id 数组
        if ( 'post_categories' === $type ) {
            $ids = array();

            if ( isset( $_POST[ $field ] ) && is_array( $_POST[ $field ] ) ) {
                foreach ( $_POST[ $field ] as $raw_id ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
                    $raw_id = intval( $raw_id );
                    if ( $raw_id > 0 ) {
                        $ids[] = $raw_id;
                    }
                }
            }

            if ( $ids ) {
                update_post_meta( $post_id, $field, array_values( array_unique( $ids ) ) );
            } else {
                delete_post_meta( $post_id, $field );
            }
            continue;
        }

        // 关联文章（手选）：存成 ID 数组，**顺序即展示顺序**；超过上限的裁掉
        if ( 'posts' === $type ) {
            $ids = array();

            if ( isset( $_POST[ $field ] ) && is_array( $_POST[ $field ] ) ) {
                foreach ( $_POST[ $field ] as $raw_id ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
                    $raw_id = intval( $raw_id );
                    if ( $raw_id > 0 && 'post' === get_post_type( $raw_id ) ) {
                        $ids[] = $raw_id;
                    }
                }
            }

            $ids = array_slice( array_values( array_unique( $ids ) ), 0, as_related_articles_limit() );

            if ( $ids ) {
                update_post_meta( $post_id, $field, $ids );
            } else {
                delete_post_meta( $post_id, $field );
            }
            continue;
        }

        // 逐行编辑器：rows（每行一个值）/ pairs（每行「名称 + 值」）
        if ( 'rows' === $type || 'pairs' === $type ) {
            $lines = array();

            if ( 'pairs' === $type ) {
                $names  = ( isset( $_POST[ $field . '_name' ] ) && is_array( $_POST[ $field . '_name' ] ) ) ? wp_unslash( $_POST[ $field . '_name' ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
                $values = ( isset( $_POST[ $field . '_value' ] ) && is_array( $_POST[ $field . '_value' ] ) ) ? wp_unslash( $_POST[ $field . '_value' ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
                $total  = max( count( $names ), count( $values ) );

                for ( $i = 0; $i < $total; $i++ ) {
                    $name  = isset( $names[ $i ] ) ? as_clean_line( $names[ $i ] ) : '';
                    $value = isset( $values[ $i ] ) ? as_clean_line( $values[ $i ] ) : '';

                    if ( '' === $name && '' === $value ) {
                        continue;
                    }

                    // 名称留空 → 只存值，前台整行显示；否则存「名称：值」由前台拆成键值行
                    $lines[] = ( '' === $name ) ? $value : $name . '：' . $value;
                }
            } else {
                $items = ( isset( $_POST[ $field ] ) && is_array( $_POST[ $field ] ) ) ? wp_unslash( $_POST[ $field ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

                foreach ( $items as $item ) {
                    $item = as_clean_line( $item );
                    if ( '' !== $item ) {
                        $lines[] = $item;
                    }
                }
            }

            if ( $lines ) {
                update_post_meta( $post_id, $field, implode( "\n", $lines ) );
            } else {
                delete_post_meta( $post_id, $field );
            }
            continue;
        }

        if ( ! isset( $_POST[ $field ] ) ) continue;

        $raw = wp_unslash( $_POST[ $field ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

        if ( 'dg_buy_url' === $field ) {
            $value = esc_url_raw( $raw );
        } elseif ( 'dg_vip_price' === $field ) {
            // 单独会员价金额，统一由 inc/vip.php 清洗（非负、最多两位小数；非法存空）
            $value = function_exists( 'as_vip_clean_price' )
                ? (string) ( as_vip_clean_price( $raw ) ?? '' )
                : sanitize_text_field( $raw );
        } elseif ( in_array( $field, $textareas, true ) ) {
            $value = sanitize_textarea_field( $raw );
        } else {
            $value = sanitize_text_field( $raw );
        }

        // 清空即删除，避免数据库里留一堆空 meta
        if ( '' === $value ) {
            delete_post_meta( $post_id, $field );
        } else {
            update_post_meta( $post_id, $field, $value );
        }
    }
}
add_action( 'save_post', 'as_save_product_meta' );

/**
 * 把多行文本拆成数组
 * 同时支持换行和分号分隔——后台按"一行一个"填，演示数据里用分号写在一行
 */
/**
 * 清洗单行文本：去掉标签、把各种空白压成一个空格、去掉首尾空白
 *
 * 注意：trim() 只认真半角空白，全角空格 U+3000 和不换行空格 U+00A0
 * 它是不认的。用 \p{Z} 才能把这些也当空白处理，否则「只填了空白的行」
 * 会被当成有效内容，前台就会渲染出空条目。
 */
function as_clean_line( $text ) {
    if ( ! is_string( $text ) ) {
        return '';
    }

    $text = sanitize_textarea_field( $text );

    return trim( (string) preg_replace( '/[\s\p{Z}]+/u', ' ', $text ) );
}

/**
 * 只按换行拆分（不按分号）
 *
 * 后台的「优点 / 缺点 / 自定义属性」是逐行编辑器，
 * 保存时一条一行。内容里的分号属于内容本身，不能再被当成分隔符切开。
 */
function as_split_lines( $raw ) {
    if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
        return array();
    }

    $out = array();

    foreach ( preg_split( '/[\r\n]+/', $raw ) as $line ) {
        $line = as_clean_line( $line );
        if ( '' !== $line ) {
            $out[] = $line;
        }
    }

    return $out;
}

/**
 * 读取逐行编辑器字段，返回条目数组
 */
function as_meta_lines( $post_id, $key ) {
    $post_id = $post_id ? $post_id : get_the_ID();

    return as_split_lines( get_post_meta( $post_id, $key, true ) );
}

/**
 * 把多行文本拆成条目数组（演示数据用）
 * 中英文换行、中英文分号都视为分隔符
 *
 * 注意：后台逐行编辑器保存的内容请用 as_split_lines()，
 * 那里不按分号拆，避免把内容里的分号切开。
 */
function as_text_lines( $text ) {
    if ( ! is_string( $text ) || '' === trim( $text ) ) {
        return array();
    }

    $lines = array();

    foreach ( preg_split( '/[\r\n]+/', $text ) as $line ) {
        foreach ( preg_split( '/[;；]+/', $line ) as $part ) {
            $part = as_clean_line( $part );
            if ( '' !== $part ) {
                $lines[] = $part;
            }
        }
    }

    return $lines;
}

/**
 * 自定义属性原始行（数量不限）
 * 优先读 dg_custom（一行一个），没有就兼容早期的 dg_custom_1 ~ _3
 */
/**
 * 自定义属性：返回「名称 / 值」键值对数组
 *
 * 存储格式仍是一行一条：名称与值之间用冒号分隔（如「产地：日本」）。
 * 名称留空时只存值，前台整行显示。兼容旧主题留下的 dg_custom_1~3。
 */
function as_product_custom_attrs( $post_id = null ) {
    $post_id = $post_id ? $post_id : get_the_ID();
    $raw     = get_post_meta( $post_id, 'dg_custom', true );

    if ( '' === trim( (string) $raw ) ) {
        $legacy = array();
        for ( $i = 1; $i <= 3; $i++ ) {
            $value = trim( (string) get_post_meta( $post_id, 'dg_custom_' . $i, true ) );
            if ( '' !== $value ) {
                $legacy[] = $value;
            }
        }
        $raw = implode( "\n", $legacy );
    }

    $pairs = array();

    foreach ( as_split_lines( $raw ) as $line ) {
        $parts = preg_split( '/[:：]/u', $line, 2 );

        if ( is_array( $parts ) && 2 === count( $parts ) && '' !== trim( $parts[0] ) && '' !== trim( $parts[1] ) ) {
            $pairs[] = array( 'label' => trim( $parts[0] ), 'value' => trim( $parts[1] ) );
        } else {
            $pairs[] = array( 'label' => '', 'value' => $line );
        }
    }

    return $pairs;
}

/**
 * 是否勾选为首页热门推荐
 */
function as_product_is_hot( $post_id = null ) {
    $post_id = $post_id ? $post_id : get_the_ID();
    $value   = get_post_meta( $post_id, 'dg_hot', true );

    return in_array( (string) $value, array( '1', '是', 'yes', 'on' ), true );
}

/**
 * 「相关文章」显示几条 —— 网页与小程序共用同一个上限。
 *
 * 只改这里：属性面板的选择器上限、保存时的裁剪、两端取数都用它。
 */
function as_related_articles_limit() {
    return (int) apply_filters( 'as_related_articles_limit', 3 );
}

/**
 * 商品**手选**的关联文章 ID（保序；过滤掉已删除、非文章、自引用）。
 *
 * 编辑页保存时原样存；这里不做状态过滤 —— 草稿也要能在后台看到并移除，
 * 前台展示时才按 publish 过滤（见 as_product_related_article_ids()）。
 */
function as_product_related_posts( $post_id = null ) {
    $post_id = $post_id ? (int) $post_id : (int) get_the_ID();
    if ( ! $post_id ) {
        return array();
    }

    $value = get_post_meta( $post_id, 'dg_related_posts', true );
    if ( is_array( $value ) ) {
        $ids = array_map( 'intval', $value );
    } else {
        $ids = array_map( 'intval', array_filter( explode( ',', (string) $value ) ) );
    }

    $out = array();
    foreach ( $ids as $id ) {
        $id = (int) $id;
        if ( $id <= 0 || $id === $post_id || 'post' !== get_post_type( $id ) ) {
            continue;
        }
        if ( ! in_array( $id, $out, true ) ) {
            $out[] = $id;
        }
    }

    return $out;
}

/**
 * 商品详情页「相关文章」的取数 —— **两端唯一实现**（网页与小程序的规则必须一致）。
 *
 *   ① 先取**手选**的（保序、只留已发布）
 *   ② 不够 $limit 条时，用「关联文章分类」下的最新文章**补足**（排除已入选的）
 *   ③ 还不够时，用**全部分类**下的最新文章补足（保证区块不空）
 *   ④ 依然不够就有什么显示什么（上限不是保底数量）
 *
 * 三级递进的理由：手选最精确 → 关联分类够贴题 → 全站兜底只为「别空着」。
 * 顺序不能乱，否则精挑的文章会被"最新"挤掉。
 *
 * @param int      $post_id 商品 ID
 * @param int|null $limit   条数上限，null = as_related_articles_limit()
 * @return array 文章 ID 数组
 */
function as_product_related_article_ids( $post_id = null, $limit = null ) {
    $post_id = $post_id ? (int) $post_id : (int) get_the_ID();
    $limit   = ( null === $limit ) ? as_related_articles_limit() : (int) $limit;

    if ( ! $post_id || $limit <= 0 ) {
        return array();
    }

    $base   = array( 'post_type' => 'post', 'post__not_in' => array( $post_id ) );
    $picked = array();

    // ① 手选（草稿/回收站里的不显示，也不占名额）
    foreach ( as_product_related_posts( $post_id ) as $id ) {
        if ( 'publish' !== get_post_status( $id ) ) {
            continue;
        }
        $picked[] = $id;
        if ( count( $picked ) >= $limit ) {
            return $picked;
        }
    }

    // ② 关联文章分类兜底（关掉 sticky 干扰，不然置顶文章会一直霸榜）
    $cats = as_product_related_categories( $post_id );
    if ( $cats ) {
        $picked = as_related_fill( $picked, array_merge( $base, array( 'category__in' => $cats ) ), $limit );
        if ( count( $picked ) >= $limit ) {
            return $picked;
        }
    }

    // ③ 全部分类兜底
    return as_related_fill( $picked, $base, $limit );
}

/**
 * 关联的文章分类 ID（过滤掉已被删除的分类）
 */
function as_product_related_categories( $post_id = null ) {
    $post_id = $post_id ? $post_id : get_the_ID();
    $value   = get_post_meta( $post_id, 'dg_related_cat', true );

    if ( is_array( $value ) ) {
        $ids = array_map( 'intval', $value );
    } else {
        $ids = array_map( 'intval', explode( ',', (string) $value ) );
    }

    $out = array();
    foreach ( array_filter( $ids ) as $id ) {
        $term = get_term( $id, 'category' );
        if ( $term && ! is_wp_error( $term ) ) {
            $out[] = $id;
        }
    }

    return $out;
}

/**
 * 相关取数的通用「补足」件：按 $args 查一批 ID 追加到 $picked。
 *
 * 统一负责三件事：去重、保序（已在 $picked 里的不再出现）、到 $limit 就停。
 * `post__not_in` 由本函数接管（自动带上已选项 + $args 里显式给的排除项），
 * 调用方只需要关心「用什么条件查」。
 *
 * @param array $picked 已有 ID（保序）
 * @param array $args   get_posts 参数，至少含 post_type；可含 tax_query / category__in
 * @param int   $limit  目标条数
 * @return array 补足后的 ID 数组
 */
function as_related_fill( $picked, $args, $limit ) {
    $args = wp_parse_args(
        $args,
        array(
            'post_status'         => 'publish',
            'posts_per_page'      => $limit,
            'ignore_sticky_posts' => true,
            'fields'              => 'ids',
            'no_found_rows'       => true,
        )
    );

    $args['post__not_in'] = array_values(
        array_unique(
            array_merge(
                array_map( 'intval', $picked ),
                isset( $args['post__not_in'] ) ? array_map( 'intval', (array) $args['post__not_in'] ) : array()
            )
        )
    );

    foreach ( get_posts( $args ) as $id ) {
        $id = (int) $id;
        if ( ! in_array( $id, $picked, true ) ) {
            $picked[] = $id;
        }
        if ( count( $picked ) >= $limit ) {
            break;
        }
    }

    return $picked;
}

/**
 * 文章页「相关推荐」显示几个产品 —— 网页与小程序共用一个上限。
 */
function as_related_products_limit() {
    return (int) apply_filters( 'as_related_products_limit', 4 );
}

/**
 * 文章 → 产品 的取数 —— **两端唯一实现**（网页 single.php 与小程序文章页共用）。
 *
 *   ① 先取「关联品类」下的产品
 *   ② 不够 $limit 个时，用**全部品类**的产品补足（排除已入选的）
 *   ③ 还不够就有什么显示什么（上限不是保底数量）
 *
 * 和「商品 → 文章」正好对称：那边是手选优先 → 关联分类 → 全部分类。
 * 这边没有手选，所以是 关联品类 → 全部分类 两段。
 *
 * @param int      $post_id 文章 ID
 * @param int|null $limit   个数上限，null = as_related_products_limit()
 * @return array 产品 ID 数组
 */
function as_article_related_product_ids( $post_id = null, $limit = null ) {
    $post_id = $post_id ? (int) $post_id : (int) get_the_ID();
    $limit   = ( null === $limit ) ? as_related_products_limit() : (int) $limit;

    if ( ! $post_id || $limit <= 0 ) {
        return array();
    }

    $base    = array( 'post_type' => 'plm_product', 'post__not_in' => array( $post_id ) );
    $picked  = array();
    $cat     = as_post_product_category( $post_id );

    // ① 关联品类下的产品
    if ( $cat ) {
        $picked = as_related_fill(
            $picked,
            array_merge(
                $base,
                array(
                    'tax_query' => array(
                        array(
                            'taxonomy' => 'plm_category',
                            'field'    => 'term_id',
                            'terms'    => $cat,
                        ),
                    ),
                )
            ),
            $limit
        );
        if ( count( $picked ) >= $limit ) {
            return $picked;
        }
    }

    // ② 全部品类的产品兜底
    return as_related_fill( $picked, $base, $limit );
}

/**
 * 商品 → 相关产品的取数 —— **两端唯一实现**（网页详情页与小程序商品页共用）。
 *
 * 三级递进（用户定的顺序）：
 *   ① **同一分类下的同一品牌** —— 最对口，优先占位
 *   ② 同一分类下的其他品牌     —— 补齐
 *   ③ 全部产品                 —— 还不够就跨分类凑
 *   ④ 依然不够就有什么显示什么（上限不是保底数量）
 *
 * 顺序不能乱：同品牌最贴题，放前面；跨分类只是兜底。
 *
 * @param int      $post_id 商品 ID
 * @param int|null $limit   个数上限，null = as_related_products_limit()
 * @return array 产品 ID 数组
 */
function as_product_related_product_ids( $post_id = null, $limit = null ) {
    $post_id = $post_id ? (int) $post_id : (int) get_the_ID();
    $limit   = ( null === $limit ) ? as_related_products_limit() : (int) $limit;

    if ( ! $post_id || $limit <= 0 ) {
        return array();
    }

    $cats   = wp_get_post_terms( $post_id, 'plm_category', array( 'fields' => 'ids' ) );
    $brands = wp_get_post_terms( $post_id, 'plm_brand', array( 'fields' => 'ids' ) );
    $cats   = ( $cats && ! is_wp_error( $cats ) ) ? array_map( 'intval', $cats ) : array();
    $brands = ( $brands && ! is_wp_error( $brands ) ) ? array_map( 'intval', $brands ) : array();

    $base   = array( 'post_type' => 'plm_product', 'post__not_in' => array( $post_id ) );
    $picked = array();

    // ① 同分类 + 同品牌（tax_query 多条件默认是 AND，正好就是「同一分类下的同一品牌」）
    if ( $cats && $brands ) {
        $picked = as_related_fill(
            $picked,
            array_merge(
                $base,
                array(
                    'tax_query' => array(
                        array( 'taxonomy' => 'plm_category', 'field' => 'term_id', 'terms' => $cats ),
                        array( 'taxonomy' => 'plm_brand', 'field' => 'term_id', 'terms' => $brands ),
                    ),
                )
            ),
            $limit
        );
        if ( count( $picked ) >= $limit ) {
            return $picked;
        }
    }

    // ② 同分类（其他品牌）
    if ( $cats ) {
        $picked = as_related_fill(
            $picked,
            array_merge(
                $base,
                array(
                    'tax_query' => array(
                        array( 'taxonomy' => 'plm_category', 'field' => 'term_id', 'terms' => $cats ),
                    ),
                )
            ),
            $limit
        );
        if ( count( $picked ) >= $limit ) {
            return $picked;
        }
    }

    // ③ 全部产品兜底
    return as_related_fill( $picked, $base, $limit );
}

/* ------------------------------------------------------------------
 * 文章 ⇄ 品类
 * 和产品的「关联文章分类」正好对称：
 *   产品勾选文章分类 → 产品页显示该分类下的文章
 *   文章选择一个品类 → 文章页显示该品类下的产品
 * ------------------------------------------------------------------ */

/**
 * 在文章编辑页侧栏注册「关联品类」+「热门文章」
 */
function as_add_post_meta_box() {
    add_meta_box(
        'as_post_product_cat',
        '关联品类',
        'as_render_post_product_cat_box',
        'post',
        'side',
        'default'
    );
    // v2.87.51：热门文章标记（与商品的 dg_hot 同一套逻辑）
    add_meta_box(
        'as_post_hot',
        '热门文章',
        'as_render_post_hot_box',
        'post',
        'side',
        'default'
    );
}
add_action( 'add_meta_boxes', 'as_add_post_meta_box' );

/**
 * 渲染「热门文章」勾选框
 */
function as_render_post_hot_box( $post ) {
    wp_nonce_field( 'as_save_post_hot', 'as_post_hot_nonce' );
    $on = '1' === (string) get_post_meta( $post->ID, 'dg_hot', true );

    echo '<p style="margin:0 0 10px;color:#646970;">勾上后，这篇文章会进入首页顶部的「热门文章」榜单。' .
         '榜单最多 5 篇，<b>勾选的排在前面</b>，没勾的按阅读量自动补齐。</p>';

    echo '<label style="display:flex;align-items:center;gap:8px;font-weight:600;">';
    echo '<input type="checkbox" name="as_post_hot" value="1"' . checked( $on, true, false ) . '>';
    echo '设为热门文章</label>';
}

/**
 * 保存「热门文章」勾选（勾上写 1，取消勾选删掉 meta）
 */
function as_save_post_hot( $post_id ) {
    if ( ! isset( $_POST['as_post_hot_nonce'] )
        || ! wp_verify_nonce( $_POST['as_post_hot_nonce'], 'as_save_post_hot' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }
    if ( 'post' !== get_post_type( $post_id ) ) {
        return;
    }

    if ( ! empty( $_POST['as_post_hot'] ) ) {
        update_post_meta( $post_id, 'dg_hot', '1' );
    } else {
        delete_post_meta( $post_id, 'dg_hot' );
    }
}
add_action( 'save_post', 'as_save_post_hot' );

/**
 * 渲染下拉选择框
 */
function as_render_post_product_cat_box( $post ) {
    wp_nonce_field( 'as_save_post_product_cat', 'as_post_product_cat_nonce' );

    $current = intval( get_post_meta( $post->ID, 'dg_product_cat', true ) );
    $terms   = get_terms( array( 'taxonomy' => 'plm_category', 'hide_empty' => false ) );

    echo '<p style="margin:0 0 8px;color:#646970;">选择后，文章底部会显示该品类下的产品（最多 4 个）。留空则不显示这个区块。</p>';

    if ( is_wp_error( $terms ) || ! $terms ) {
        echo '<p><em>还没有品类。请先到「商城产品 → 品类」里创建。</em></p>';
        return;
    }

    echo '<select name="as_post_product_cat" id="as_post_product_cat" style="width:100%;">';
    echo '<option value="0">— 不关联 —</option>';

    foreach ( $terms as $term ) {
        printf(
            '<option value="%d"%s>%s（%d 个产品）</option>',
            intval( $term->term_id ),
            selected( $current, $term->term_id, false ),
            esc_html( $term->name ),
            intval( $term->count )
        );
    }

    echo '</select>';
}

/**
 * 保存关联品类（单选；选「不关联」时删除 meta）
 */
function as_save_post_product_cat( $post_id ) {
    if ( ! isset( $_POST['as_post_product_cat_nonce'] )
        || ! wp_verify_nonce( $_POST['as_post_product_cat_nonce'], 'as_save_post_product_cat' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    $cat = isset( $_POST['as_post_product_cat'] ) ? intval( $_POST['as_post_product_cat'] ) : 0;

    // 校验这个 term 确实存在，避免手工构造请求写进脏数据
    if ( $cat ) {
        $term = get_term( $cat, 'plm_category' );
        if ( ! $term || is_wp_error( $term ) ) {
            $cat = 0;
        }
    }

    if ( $cat ) {
        update_post_meta( $post_id, 'dg_product_cat', $cat );
    } else {
        delete_post_meta( $post_id, 'dg_product_cat' );
    }
}
add_action( 'save_post', 'as_save_post_product_cat' );

/**
 * 取文章关联的品类 term_id（无效或已删除时返回 0）
 */
function as_post_product_category( $post_id = null ) {
    $post_id = $post_id ? $post_id : get_the_ID();
    $cat_id  = intval( get_post_meta( $post_id, 'dg_product_cat', true ) );

    if ( ! $cat_id ) {
        return 0;
    }

    $term = get_term( $cat_id, 'plm_category' );

    return ( $term && ! is_wp_error( $term ) ) ? $cat_id : 0;
}

/**
 * 规格参数行：品牌 / 品类 / 规格 / 适合人群 / 成分 / 自定义属性
 * 只返回有值的行，前端可直接遍历渲染
 */
/**
 * 上一篇 / 下一篇（跨分类 · 全站按发布时间）
 * ---------------------------------------------------------------------------
 * 用户 2026-11-21 定：**不限分类**，全站按发布时间排序 ——
 * 上一篇 = 更早发布的一篇，下一篇 = 更晚发布的一篇。
 * 用 $wpdb 直查而不用 get_previous_post()：
 *   · get_previous_post() 依赖全局 $post，在循环里容易取错对象；
 *   · 它默认同分类，取不到跨分类的相邻内容。
 * 同一条 post_date（秒级相同）时用 ID 兜底。
 */
function as_adjacent_post_ids( $post_id = null ) {
    $post_id = $post_id ? $post_id : get_the_ID();
    global $wpdb;
    $post = get_post( $post_id );
    if ( ! $post ) {
        return array( 'prev' => 0, 'next' => 0 );
    }
    $type = $post->post_type;
    $date = $post->post_date;
    $prev_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
          WHERE post_type = %s AND post_status = 'publish'
            AND ( post_date < %s OR ( post_date = %s AND ID < %d ) )
          ORDER BY post_date DESC, ID DESC LIMIT 1",
        $type, $date, $date, (int) $post_id
    ) );
    $next_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
          WHERE post_type = %s AND post_status = 'publish'
            AND ( post_date > %s OR ( post_date = %s AND ID > %d ) )
          ORDER BY post_date ASC, ID ASC LIMIT 1",
        $type, $date, $date, (int) $post_id
    ) );
    return array(
        'prev' => (int) $prev_id,
        'next' => (int) $next_id,
    );
}

/**
 * 输出「上一个 / 下一个」导航条（产品页与文章页共用）
 * ---------------------------------------------------------------------------
 * 纯文字按钮、左右并排；只有一侧存在时只显示那一侧（用户 2026-11-21 定）。
 * 放在页面**最底部** —— 产品页在两栏布局里要占满整行（grid-column: 1/-1）。
 */
function as_prev_next_nav( $post_id = null, $prev_label = '上一个产品', $next_label = '下一个产品' ) {
    $ids = as_adjacent_post_ids( $post_id );
    $prev = $ids['prev'] ? get_post( $ids['prev'] ) : null;
    $next = $ids['next'] ? get_post( $ids['next'] ) : null;
    if ( ! $prev && ! $next ) {
        return;
    }
    echo '<nav class="as-prevnext">';
    if ( $prev ) {
        echo '<a class="as-prevnext-item is-prev" href="' . esc_url( get_permalink( $prev ) ) . '">'
            . '<span class="as-prevnext-arrow" aria-hidden="true">‹</span>'
            . '<span class="as-prevnext-text">' . esc_html( $prev_label ) . '</span></a>';
    }
    if ( $next ) {
        echo '<a class="as-prevnext-item is-next" href="' . esc_url( get_permalink( $next ) ) . '">'
            . '<span class="as-prevnext-text">' . esc_html( $next_label ) . '</span>'
            . '<span class="as-prevnext-arrow" aria-hidden="true">›</span></a>';
    }
    echo '</nav>';
}

function as_product_spec_rows( $post_id = null ) {
    $post_id = $post_id ? $post_id : get_the_ID();
    $rows    = array();

    $brands = get_the_terms( $post_id, 'plm_brand' );
    if ( $brands && ! is_wp_error( $brands ) ) {
        $rows[] = array( 'label' => '品牌', 'value' => $brands[0]->name, 'link' => get_term_link( $brands[0] ) );
    }

    $cats = get_the_terms( $post_id, 'plm_category' );
    if ( $cats && ! is_wp_error( $cats ) ) {
        $rows[] = array( 'label' => '品类', 'value' => $cats[0]->name, 'link' => get_term_link( $cats[0] ) );
    }

    // 效果类型（产品属性，三选一）：紧跟在品类后面，网站与小程序详情页共用这一行
    $effect = get_post_meta( $post_id, 'dg_effect', true );
    if ( $effect ) {
        $rows[] = array( 'label' => '效果类型', 'value' => $effect, 'link' => '' );
    }

    // 规格：优先新字段，兼容旧主题留下的 dg_material
    $spec = get_post_meta( $post_id, 'dg_spec', true );
    if ( ! $spec ) {
        $spec = get_post_meta( $post_id, 'dg_material', true );
    }
    if ( $spec ) {
        $rows[] = array( 'label' => '规格', 'value' => $spec, 'link' => '' );
    }

    $audience = get_post_meta( $post_id, 'dg_audience', true );
    if ( $audience ) {
        $rows[] = array( 'label' => '适合人群', 'value' => $audience, 'link' => '' );
    }

    // 成分：合并进规格参数表，不再单独占一个区块
    $ingredients = get_post_meta( $post_id, 'dg_ingredients', true );
    if ( $ingredients ) {
        $rows[] = array(
            'label' => '成分',
            'value' => trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $ingredients ) ) ),
            'link'  => '',
        );
    }

    // 自定义属性：名称 + 值（名称留空则整行作为值）
    foreach ( as_product_custom_attrs( $post_id ) as $pair ) {
        $rows[] = array( 'label' => $pair['label'], 'value' => $pair['value'], 'link' => '' );
    }

    return $rows;
}

/**
 * 服务说明标签 —— **彻底全局化**（v2.42.0 起）。
 * 所有商品共用「小程序商城 → 设置 → 服务说明」这一套，不再读单个商品的 dg_discreet。
 * 全局值用空格 / 顿号 / 逗号 / 斜杠分隔，拆成一排绿色标签。
 * 保留 $post_id 参数只为兼容旧调用点，实际忽略。
 *
 * @return array 标签数组
 */
function as_service_tags( $post_id = null ) {
    $raw = '';
    if ( function_exists( 'as_mini_get_config' ) ) {
        $cfg = as_mini_get_config();
        $raw = trim( (string) ( $cfg['service_tags'] ?? '' ) );
    }
    if ( '' === $raw ) {
        return array();
    }
    $parts = preg_split( '/[\s、,，\/|+·]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY );
    $parts = array_map( 'trim', (array) $parts );
    return array_values( array_unique( array_filter( $parts, 'strlen' ) ) );
}

/**
 * 辅助函数：购买按钮
 */
function as_buy_button( $post_id = null, $text = '去购买', $allow_choose = true ) {
    $post_id = $post_id ? $post_id : get_the_ID();
    $url     = get_post_meta( $post_id, 'dg_buy_url', true );   // 商品属性里的「购买链接」= 小程序下单的手机端落地页

    /*
     * 站点开启「网页 → 小程序」后，按钮交给前端 JS：
     *   卡片（$allow_choose = true） —— 先弹「小程序下单 / 网站下单」选择层
     *        · 小程序下单：电脑端弹后台上传的小程序码；手机端跳商品属性里填的购买链接
     *        · 网站下单  ：直接打开本站商品详情页（那里能加入购物车 / 立即购买）
     *   详情页（$allow_choose = false）—— 按钮本身就是「小程序下单」，不弹层，直接走上面的小程序逻辑
     *
     * href 仍保留原外链 —— JS 未加载或拿不到链接时退回原行为，不至于点了没反应。
     */
    if ( function_exists( 'as_wx_jump_enabled' ) && as_wx_jump_enabled() ) {
        $site_url = (string) get_permalink( $post_id );
        // 电脑端弹的码：后台「网站与小程序共用 → 自定义小程序码」上传的那张（全站共用）
        $mp_qr    = function_exists( 'as_wx_jump_qr_custom' ) ? (string) as_wx_jump_qr_custom() : '';

        $attrs = sprintf(
            'href="%s" data-as-mp="%d" data-as-mp-url="%s" data-as-mp-qr="%s"',
            esc_url( $url ? $url : ( $site_url ? $site_url : '#' ) ),
            (int) $post_id,
            esc_url( (string) $url ),
            esc_url( $mp_qr )
        );

        if ( ! $allow_choose ) {
            // 详情页：不弹选择层
            return sprintf(
                '<a class="as-buy-btn" %s data-as-mp-only="1" data-as-site="%s" rel="nofollow noopener">%s</a>',
                $attrs,
                esc_url( $site_url ),
                esc_html( $text )
            );
        }

        return sprintf(
            '<a class="as-buy-btn" %s data-as-site="%s" rel="nofollow noopener">%s</a>',
            $attrs,
            esc_url( $site_url ),
            esc_html( $text )
        );
    }

    if ( ! $url ) return '';

    return sprintf(
        '<a class="as-buy-btn" href="%s" target="_blank" rel="nofollow sponsored noopener">%s</a>',
        esc_url( $url ),
        esc_html( $text )
    );
}

/**
 * 辅助函数：商品卡片（列表页 / 首页 / 相关推荐）
 * 展示除「商品详情」外的必备属性：主图、标题、现价、原价、购买链接
 * （品牌与推荐指数均不在卡片展示：品牌保留在详情页规格参数，推荐指数属性已整体移除）
 */
/**
 * 卡片上的会员折扣提醒（紧凑一行 HTML）。
 *
 * 详情页用的是 as_vip_web_notice() + 模板里的大块样式（含「成为会员」按钮）；
 * 卡片这里只出文字 —— 卡片本身是个链接，再塞按钮会让点击目标变乱，
 * 想开通的人点进详情页即可。
 *
 * 控价商品（dg_vip_hide）/ 单品不参与 / 折后没便宜 → 返回空串，整行不输出。
 *
 * @param int $pid 商品 ID。
 * @return string 可直接 echo 的 HTML；不显示时为空串。
 */
/**
 * 商品卡左上角「券徽章」要显示的券名（空串 = 不出徽章）。
 *
 * 用户 2026-11-09 要求：有券时卡片左上角盖一个徽章，显示**券的名称**
 * （后台 as_coupons.name，例如「双十一优惠」），而不是「满150减30」这种面额
 * —— 面额在卡片右侧的券标签上已经写了，徽章只负责"喊一声这里有活动"。
 *
 * 🔴 控价商品（dg_vip_hide）的徽章**与券完全同口径**（v2.87.97 修正）：
 *    用 as_vip_product_hide_coupon()（= hidden && !is_vip）判断 ——
 *    非会员看不到券，也就不该看到徽章；会员本来就能看见券，徽章照给。
 *    不能一刀切"控价商品永不挂徽章"：那会让会员在一个明明有券的卡上看不到活动名，
 *    与卡片右侧的券标签自相矛盾（用户 2026-11-09 指出）。
 *
 * 取值顺序：name（活动名称）→ title（券标题）→ 都空则不显示。
 * 不回落 label（面额），否则徽章和旁边的券标签内容重复。
 *
 * @param int $pid 商品 ID。
 * @return string 可直接 esc_html 的券名；空串 = 不显示。
 */
function as_coupon_badge_name( $pid ) {
	$pid = (int) $pid;
	if ( ! $pid ) {
		return '';
	}

	// 控价商品对非会员：连券都不给看，徽章也不给（与券同口径）
	if ( function_exists( 'as_vip_product_hide_coupon' ) && as_vip_product_hide_coupon( $pid ) ) {
		return '';
	}

	if ( ! function_exists( 'as_coupons_for_viewer' ) ) {
		return '';
	}
	$coupons = as_coupons_for_viewer( $pid );
	if ( empty( $coupons[0] ) || ! is_array( $coupons[0] ) ) {
		return '';
	}

	$c    = $coupons[0];
	$name = isset( $c['name'] ) ? trim( (string) $c['name'] ) : '';
	if ( '' === $name && isset( $c['title'] ) ) {
		$name = trim( (string) $c['title'] );
	}
	return $name;
}

function as_vip_notice_card( $pid ) {
    /*
     * 与详情页同一口径（统一到 as_web_price_info()）：
     *   到手价 = 会员价 − 券减免（只取最划算的那一张）；特殊商品则直接用重锚后的会员价
     *
     * v2.68.0：**只报「会员到手价」，不再报「省 ¥xx」**（用户要求）。
     * 理由同详情页去掉「本单可省 ¥xx」一样 —— 卡片上同时有现价和到手价，
     * 省多少本来就一眼算得出来；窄卡上那句「· 省 ¥60」还会把整行撑成两行。
     * 详情页保留「省 ¥xx」（那里现价与到手价不在同一行、没有可对照的数字）。
     */
    if ( ! function_exists( 'as_web_price_info' ) ) {
        return '';
    }
    $info = as_web_price_info( $pid );
    if ( empty( $info['vip'] ) || null === $info['final'] ) {
        return '';
    }

    // v2.87.104：标签由 as_web_price_info() 统一按身份给（会员「到手价」/ 非会员「会员价」）
    $as_hint_tag = ! empty( $info['tag'] ) ? (string) $info['tag'] : '会员价';
    return sprintf(
        '<div class="as-vip-hint"><b>%s ¥%s</b></div>',
        esc_html( $as_hint_tag ),
        esc_html( as_vip_money( $info['final'] ) )
    );
}

/**
 * 券倒计时的「结束时间戳 + 初始文案」。
 *
 * 详情页与卡片共用：服务端先算一份文案（避免 JS 执行前出现空标签闪动），
 * 前端 main.js 每秒按 `[data-countdown]` 重算（选择器是通用的，卡片无需额外 JS）。
 * 文案格式与详情页完全一致：剩 X 天 X 小时 / 剩 X 小时 X 分 / 剩 X 分 X 秒。
 *
 * @param array $coupons 券列表（as_mini_coupons_for_product() 的返回值）
 * @return array end = 0 表示没有可用截止时间
 */
function as_coupon_countdown( $coupons ) {
    $end = 0;
    foreach ( (array) $coupons as $c ) {
        if ( empty( $c['end_at'] ) ) {
            continue;
        }
        $dt = date_create( $c['end_at'], wp_timezone() );
        $ts = $dt ? $dt->getTimestamp() : 0;
        // 多张券时取最先到期的那张
        if ( $ts && ( ! $end || $ts < $end ) ) {
            $end = $ts;
        }
    }

    if ( ! $end ) {
        return array( 'end' => 0, 'text' => '' );
    }

    /*
     * 文案**不加空格**：「剩1天15小时」比「剩 1 天 15 小时」窄约 15px ——
     * 手机端券行就差这点宽度才能一行放完（见 main.css 末尾那块），
     * 窄的卡片券标签也一起受益。JS 侧同格式，见 assets/js/main.js。
     */
    $left = max( 0, $end - time() );
    if ( $left <= 0 ) {
        $text = '优惠已结束';
    } elseif ( $left >= DAY_IN_SECONDS ) {
        $text = '剩' . floor( $left / DAY_IN_SECONDS ) . '天' . floor( ( $left % DAY_IN_SECONDS ) / HOUR_IN_SECONDS ) . '小时';
    } elseif ( $left >= HOUR_IN_SECONDS ) {
        $text = '剩' . floor( $left / HOUR_IN_SECONDS ) . '小时' . floor( ( $left % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS ) . '分';
    } else {
        $text = '剩' . floor( $left / MINUTE_IN_SECONDS ) . '分' . ( $left % MINUTE_IN_SECONDS ) . '秒';
    }

    return array( 'end' => $end, 'text' => $text );
}

/* ==========================================================================
 * 限时抢购
 * --------------------------------------------------------------------------
 * 商品池 = 后台勾选了「首页抢购」（dg_flash）的商品。
 *
 * 基础版不含优惠券系统，因此**不再以「有券可用」作为入池条件** ——
 * 勾了就上首页，模块头部显示活动名与倒计时区。
 * （完整版里券与抢购是绑定的：两头都不配就不显示。）
 *
 *   · as_flash_product_ids()  勾选了 dg_flash 的已发布商品
 *   · as_flash_coupon_groups() 把商品池切成模块（基础版恒为单个模块）
 *
 * 卡片复用 as_product_card()：价格、购买按钮走的是原来那套逻辑与组件。
 * ======================================================================== */

/**
 * 限时抢购商品 ID。
 *
 * 入池条件：后台勾选了「首页抢购」（dg_flash = '1'）且商品已发布。
 *
 * @param int $limit 显示几个（后台「主题设置 → 通用设置」可配）。
 * @return int[]
 */
function as_flash_product_ids( $limit = 0 ) {
	$limit = (int) $limit;
	if ( $limit < 1 ) {
		$limit = 4;
	}

	$q = new WP_Query( array(
		'post_type'      => 'plm_product',
		'post_status'    => 'publish',
		'posts_per_page' => max( 12, $limit * 3 ),
		'meta_key'       => 'dg_flash',
		'meta_value'     => '1',
		'no_found_rows'  => true,
		'orderby'        => 'date',
		'order'          => 'DESC',
	) );

	$out = array();
	while ( $q->have_posts() ) {
		$q->the_post();
		$pid = (int) get_the_ID();
		if ( ! $pid ) {
			continue;
		}
		$out[] = $pid;
		if ( count( $out ) >= $limit ) {
			break;
		}
	}
	wp_reset_postdata();

	return $out;
}

/**
 * 「限时特惠」页商品 ID。
 *
 * 与首页 as_flash_product_ids() 的区别：**不要求勾 dg_flash** ——
 * 列出全部已发布商品，由页面自己按分类分组展示。
 *
 * 排序：商品发布日期 DESC（与首页池子一致，新的在前）。
 *
 * @param int $limit 0 = 不限
 * @return int[]
 */
function as_sale_product_ids( $limit = 0 ) {
	$limit = (int) $limit;

	$q = new WP_Query( array(
		'post_type'      => 'plm_product',
		'post_status'    => 'publish',
		'posts_per_page' => 200,
		'no_found_rows'  => true,
		'orderby'        => 'date',
		'order'          => 'DESC',
	) );

	$out = array();
	while ( $q->have_posts() ) {
		$q->the_post();
		$pid = (int) get_the_ID();
		if ( ! $pid ) {
			continue;
		}
		$out[] = $pid;
		if ( $limit > 0 && count( $out ) >= $limit ) {
			break;
		}
	}
	wp_reset_postdata();

	return $out;
}

/**
 * 把一组商品切成模块。
 *
 * 基础版没有优惠券，因此**不按券分组** —— 只产出单个模块，
 * 结构与完整版保持一致（key / name / label / end / text / ids[]），
 * 所以首页与「限时特惠」页的模板不需要改。
 *
 * @param int[] $ids       商品 ID 列表。
 * @param int   $per_group 模块内最多几件，0 = 不限。
 * @return array 每个元素 { key, name, label, end, text, ids[] }
 */
function as_coupon_groups_by_ids( $ids, $per_group = 0 ) {
	$ids = array_filter( array_map( 'intval', (array) $ids ) );
	if ( ! $ids ) {
		return array();
	}

	$per_group = (int) $per_group;
	if ( $per_group > 0 && count( $ids ) > $per_group ) {
		$ids = array_slice( $ids, 0, $per_group );
	}

	$cd = as_flash_end_at();

	return array(
		array(
			'key'   => 'all',
			'name'  => '限时特惠',
			'label' => '',
			'end'   => ! empty( $cd['end'] ) ? (int) $cd['end'] : 0,
			'text'  => isset( $cd['text'] ) ? $cd['text'] : '',
			'ids'   => $ids,
		),
	);
}

/**
 * 「限时特惠」页的模块（= 全部商品的分组）。
 *
 * @return array 同 as_coupon_groups_by_ids()
 */
function as_sale_coupon_groups() {
	if ( ! function_exists( 'as_sale_product_ids' ) ) {
		return array();
	}
	// 页面列出全部商品，不截断
	return as_coupon_groups_by_ids( as_sale_product_ids(), 0 );
}

/**
 * 首页「限时特惠」模块（= 首页限量池的分组）。
 *
 * 商品池 = as_flash_product_ids( $limit )（后台勾选了「首页抢购」的商品），
 * 只是再按模块切一层 —— 与 /sale/ 页同一套版式，区别只是数量受限（后台可配）。
 *
 * @param int $per_group 每个模块最多几件，0 = 用后台配置。
 * @return array 同 as_coupon_groups_by_ids()
 */
function as_flash_coupon_groups( $per_group = 0 ) {
	if ( ! function_exists( 'as_flash_product_ids' ) ) {
		return array();
	}
	if ( (int) $per_group < 1 ) {
		$per_group = function_exists( 'as_mini_opt' ) ? (int) as_mini_opt( 'flash_count' ) : 0;
	}
	if ( $per_group < 1 ) {
		$per_group = 4;
	}
	// 商品池**不限量**：上限是「每模块」的事，不能在这里先砍一刀
	return as_coupon_groups_by_ids( as_flash_product_ids( 500 ), $per_group );
}

/**
 * 品类快捷入口的图标（v2.84.2 首页分类改图标卡片用）。
 * 按 slug 映射，没配到的品类用通用购物袋 —— 新增品类不用改代码也有图标。
 *
 * @param WP_Term|string $cat 分类对象或 slug。
 * @return string emoji
 */
/**
 * 品类快捷入口图标（v2.84.2 首页分类改图标卡片用；v2.85.0 emoji → 线性 SVG）。
 *
 * 按 slug 映射，没配到的品类用通用购物袋。
 * SVG 统一 1.5px 描边、圆角线帽，stroke 用 currentColor —— 颜色交给 CSS，
 * 深色底/浅色底都不用改这里。
 *
 * @param WP_Term|string $cat 分类对象或 slug。
 * @return string 内联 SVG  HTML（调用方已包 span，直接 echo）
 */
function as_cat_icon( $cat ) {
	$clock = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 2.5M9 2h6M12 2v3"/></svg>';
	$bottle = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 8h6l-1 12H10L9 8z"/><path d="M10 8V6a2 2 0 0 1 4 0v2M9 12h6"/></svg>';
	$bolt = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 4 14h6l-1 8 9-12h-6l1-8z"/></svg>';
	$bag = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 8h14l-1.2 12H6.2L5 8z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg>';
	$map = array(
		'yspj' => $clock,  // 延时喷剂
		'byxf' => $bottle, // 保养修复
		'kgzq' => $bolt,   // 快感增强
	);
	$slug = is_object( $cat ) && isset( $cat->slug ) ? (string) $cat->slug : (string) $cat;
	return isset( $map[ $slug ] ) ? $map[ $slug ] : $bag;
}

/**
 * 限时抢购模块的结束时间戳 + 初始文案（v2.84.4 改版用）。
 *
 * 限时特惠的倒计时（结束时间来自后台「限时活动结束时间」配置）。
 * 文案格式与卡片倒计时（as_coupon_countdown）**不同**，这是模块头部的「距结束」：
 *   · 超过 24 小时 →「X天 HH:MM」
 *   · 24 小时内  →「HH:MM:SS」（补零）
 * 前端 main.js 按 [data-flash-cd] 每秒刷新，格式与此处保持一致。
 *
 * @return array end = 0 表示没有可用截止时间
 */
/**
 * 限时活动的结束时间（后台「主题设置 → 通用设置 → 限时活动结束时间」配置）。
 *
 * 基础版没有优惠券，因此活动结束时间**直接由管理员填写**，
 * 而不是从券的 end_at 推导。
 *
 * @return array { end: int Unix 时间戳（0 = 未配置）, text: string }
 */
function as_flash_end_at() {
	$raw = function_exists( 'as_mini_opt' ) ? (string) as_mini_opt( 'flash_end_at' ) : '';
	$raw = trim( $raw );
	if ( '' === $raw ) {
		return array( 'end' => 0, 'text' => '' );
	}

	$dt = date_create( $raw, wp_timezone() );
	if ( ! $dt ) {
		return array( 'end' => 0, 'text' => '' );
	}
	$ts = (int) $dt->getTimestamp();
	if ( $ts <= 0 ) {
		return array( 'end' => 0, 'text' => '' );
	}

	if ( $ts <= time() ) {
		return array( 'end' => $ts, 'text' => '已结束' );
	}

	$left = $ts - time();
	if ( $left >= DAY_IN_SECONDS ) {
		$text = '剩' . floor( $left / DAY_IN_SECONDS ) . '天' . floor( ( $left % DAY_IN_SECONDS ) / HOUR_IN_SECONDS ) . '小时';
	} elseif ( $left >= HOUR_IN_SECONDS ) {
		$text = '剩' . floor( $left / HOUR_IN_SECONDS ) . '小时' . floor( ( $left % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS ) . '分';
	} else {
		$text = '剩' . floor( $left / MINUTE_IN_SECONDS ) . '分' . ( $left % MINUTE_IN_SECONDS ) . '秒';
	}

	return array( 'end' => $ts, 'text' => $text );
}

function as_flash_countdown() {
	$cd = as_flash_end_at();
	if ( empty( $cd['end'] ) ) {
		return array( 'end' => 0, 'text' => '' );
	}
	$left = max( 0, $cd['end'] - time() );
	if ( $left <= 0 ) {
		return array( 'end' => $cd['end'], 'text' => '已结束' );
	}
	$d = (int) floor( $left / DAY_IN_SECONDS );
	$h = (int) floor( ( $left % DAY_IN_SECONDS ) / HOUR_IN_SECONDS );
	$m = (int) floor( ( $left % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS );
	$s = (int) ( $left % MINUTE_IN_SECONDS );
	if ( $d > 0 ) {
		$text = $d . '天 ' . sprintf( '%02d:%02d', $h, $m );
	} else {
		$text = sprintf( '%02d:%02d:%02d', $h, $m, $s );
	}
	return array( 'end' => $cd['end'], 'text' => $text );
}

/**
 * 限时抢购卡片（v2.84.5 横版改版）。
 *
 * 与普通卡片 as_product_card() 的区别：
 *   · **横版：图在左、信息在右**（v2.84.5），三列小网格，卡片整体更小
 *   · 封面左上角折扣标「-XX%」—— 按「**现价 vs 到手价**」算（v2.86.69 改口径，
 *     会员折扣/券一改就变）；没有到手价（无会员折扣也无券）时不显示
 *   · **不显示**优惠券行 —— 券信息已在模块顶部那一行
 *   · 库存字段全站没有 → 参考图里的「仅剩 N 件 / 已抢 68%」无真实数据源，不造假：
 *     改显「已售 N 件」（as_product_sales，为 0 时不输出）
 *   · 底部「立即抢购」按钮 → **独立走购买链路**（v2.84.11）：as_buy_button()
 *     （电脑端弹小程序码 / 手机端跳 URL Link / 商品外链），都没配才退回详情页
 *   · 🔴 会员到手价必显（v2.84.5 用户要求）：走 as_web_price_info() 全站统一口径，
 *     控价商品 / 不参与会员折扣 / 折后没便宜 → 天然不输出，无需自己判 dg_vip_hide
 *
 * @param int $post_id 商品 ID。
 */
function as_flash_card( $post_id, $with_coupon = false ) {
	$post_id   = (int) $post_id;
	$title     = get_the_title( $post_id );
	$price     = get_post_meta( $post_id, 'dg_price', true );
	$old       = get_post_meta( $post_id, 'dg_old_price', true );
	$permalink = get_permalink( $post_id );

	// 到手价（as_web_price_info 的 final）：有会员信息 = 会员到手价，否则 = 券后价
	$as_info   = function_exists( 'as_web_price_info' ) ? as_web_price_info( $post_id ) : array();
	$vip_final = null;
	if ( ! empty( $as_info['vip'] ) && isset( $as_info['final'] ) && null !== $as_info['final'] ) {
		$vip_final = $as_info['final'];
	}

	/*
	 * 折扣标「-XX%」（v2.86.69 改口径）：**现价 vs 到手价**。
	 *
	 * 🔴 旧口径是「原价 vs 现价」—— 与会员折扣**无关**，所以用户改了会员折扣
	 * 折扣标纹丝不动（2026-10-14 用户反馈「改个会员折扣没有变动」）。
	 * 新口径与卡片上显示的「会员到手 ¥X」同源，会员折扣 / 券一改就跟着变，
	 * 且标上的百分比与那个到手价数字自洽。
	 * 没有到手价（既无会员折扣也无券）→ 不输出，避免出现「-0%」。
	 */
	$off = '';
	if ( $price > 0 && isset( $as_info['final'] ) && null !== $as_info['final']
		&& (float) $as_info['final'] < (float) $price ) {
		$off = '-' . (int) round( ( (float) $price - (float) $as_info['final'] ) / (float) $price * 100 ) . '%';
	}

	$sales = function_exists( 'as_product_sales' ) ? (int) as_product_sales( $post_id ) : 0;
	$vip_text = ( null !== $vip_final && function_exists( 'as_vip_money' ) ) ? as_vip_money( $vip_final ) : '';

	/*
	 * 服务说明标签（v2.85.2 用户参考图定）：调后台「小程序商城 → 设置 → 服务说明」
	 * 那一套全局标签（as_service_tags，如「隐私发货 正品保证 顺丰包邮」），
	 * 显示在抢购卡底部独立横列里、购买按钮右侧 —— 商品页原来就有的信任信息，
	 * 这里只是复用，不新增配置项。没配则整块不输出。
	 */
	$as_flash_services = function_exists( 'as_service_tags' ) ? as_service_tags( $post_id ) : array();
	?>
	<div class="as-flash-card">
		<a class="as-flash-img" href="<?php echo esc_url( $permalink ); ?>">
			<?php if ( $off ) : ?>
				<span class="as-flash-off"><?php echo esc_html( $off ); ?></span>
			<?php endif; ?>
			<?php
			// 封面口径与普通卡片一致：特色图像 → 图集第一张
			$as_thumb = as_post_thumbnail( $post_id, 'as_product_card' );
			if ( ! $as_thumb && function_exists( 'as_product_cover_id' ) ) {
				$as_cid   = as_product_cover_id( $post_id );
				$as_thumb = $as_cid ? wp_get_attachment_image( $as_cid, 'as_product_card', false, array( 'alt' => $title ) ) : '';
			}
			?>
			<?php if ( $as_thumb ) : ?>
				<?php echo $as_thumb; ?>
			<?php else : ?>
				<?php echo as_placeholder_img( $title, 600, 600 ); ?>
			<?php endif; ?>
		</a>
		<div class="as-flash-body">
			<h3 class="as-flash-title"><a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a></h3>
			<div class="as-flash-price">
				<span class="as-price-now">¥<?php echo esc_html( $price ? $price : '—' ); ?></span>
				<?php if ( $old ) : ?>
					<span class="as-price-old">¥<?php echo esc_html( $old ); ?></span>
				<?php endif; ?>
			</div>
			<?php
			/*
			 * 券标签 + 倒计时（v2.86.52「全部优惠」页专用，传 $with_coupon=true 才输出）。
			 * 口径与卡片 promo 行同一数据源：as_mini_coupons_for_product() 取本商品
			 * 可用券的第一张（前端按 data-countdown 每秒刷新，与详情页同机制）。
			 */
			if ( $with_coupon && function_exists( 'as_mini_coupons_for_product' ) ) {
				// v2.87.98：券门槛基准统一（单独定价商品的会员按单独价判）
				$as_pc_base    = function_exists( 'as_coupon_base_for_viewer' )
					? as_coupon_base_for_viewer( $post_id )
					: (float) get_post_meta( $post_id, 'dg_price', true );
				$as_pc_coupons = as_mini_coupons_for_product( $post_id, $as_pc_base );
				if ( $as_pc_coupons ) {
					/*
					 * v2.86.56：一个商品只适用一张券（[0] = 减免最多的那张），
					 * 倒计时必须是**这张券自己的** end_at —— 不能用整组的
					 * （as_coupon_countdown() 取整组最早，会出现「标签是券 A、
					 * 时间却是券 B」的错配）。
					 */
					$as_pc_cd = function_exists( 'as_coupon_countdown' ) ? as_coupon_countdown( array( $as_pc_coupons[0] ) ) : array();
					echo '<div class="as-sale-card-coupon">';
					echo esc_html( $as_pc_coupons[0]['label'] );
					if ( ! empty( $as_pc_cd['end'] ) ) {
						echo ' <span class="as-sale-card-coupon-cd" data-countdown="' . esc_attr( $as_pc_cd['end'] ) . '">' . esc_html( $as_pc_cd['text'] ) . '</span>';
					}
					echo '</div>';
				}
			}
			?>
			<?php if ( '' !== $vip_text ) : ?>
				<div class="as-flash-vip"><?php echo esc_html( ! empty( $as_info['tag'] ) ? (string) $as_info['tag'] : '会员价' ); ?> <b>¥<?php echo esc_html( $vip_text ); ?></b></div>
			<?php endif; ?>
			<?php if ( $sales > 0 ) : ?>
				<div class="as-flash-sales">已售 <?php echo (int) $sales; ?> 件</div>
			<?php endif; ?>
		</div>
		<?php
		/*
		 * 右侧行动竖列（v2.86.1）：服务说明 ✓ 标签 + CTA。
		 * 会员到手价节点在信息列（DOM 唯一一份），桌面端靠自然顺序落在已售之上；
		 * 手机端由 CSS Grid 把它显式定位到本列按钮上方 —— 见 main.css
		 * 「v2.86.1 手机端Grid 重排」段。底色深一档 + 左边线与信息区分隔。
		 * CTA 走 as_buy_button()（独立于卡片点击）：
		 *   · 开启「网页 → 小程序」→ 电脑端弹小程序码 / 手机端跳 URL Link（JS 接管）
		 *   · 否则走商品外链 dg_buy_url
		 * 两条路都不可用 → 退回商品详情页，按钮不消失。
		 */
		$as_flash_buy = as_buy_button( $post_id, '立即抢购' );
		?>
		<div class="as-flash-act">
			<?php if ( $as_flash_services ) : ?>
				<div class="as-flash-services">
					<?php foreach ( $as_flash_services as $as_flash_svc ) : ?>
						<span class="as-flash-svc"><?php echo esc_html( $as_flash_svc ); ?></span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<?php if ( $as_flash_buy ) : ?>
				<?php echo $as_flash_buy; // 已由 as_buy_button() 内部 esc 过 ?>
			<?php else : ?>
				<a class="as-flash-btn" href="<?php echo esc_url( $permalink ); ?>">立即抢购</a>
			<?php endif; ?>
		</div>
</div>
<?php
}

function as_product_card( $post_id = null, $extra_class = '' ) {
    $post_id   = $post_id ? $post_id : get_the_ID();
    $title     = get_the_title( $post_id );
    $price     = get_post_meta( $post_id, 'dg_price', true );
    $old       = get_post_meta( $post_id, 'dg_old_price', true );
    $permalink = get_permalink( $post_id );
    // 左上角券徽章（v2.87.96）：有可用券 + 非控价商品才出
    $as_badge  = function_exists( 'as_coupon_badge_name' ) ? as_coupon_badge_name( $post_id ) : '';
    ?>
    <div class="as-card<?php echo $extra_class ? ' ' . esc_attr( $extra_class ) : ''; ?>">
        <a class="as-card-img" href="<?php echo esc_url( $permalink ); ?>">
            <?php if ( '' !== $as_badge ) : ?><span class="as-coupon-badge"><?php echo esc_html( $as_badge ); ?></span><?php endif; ?>
            <?php
            /*
             * 封面口径与小程序一致：特色图像 → 图集第一张。
             * 只用 as_post_thumbnail() 的话，"加了图集但没设特色图像"的商品
             * 在列表里会显示灰底占位 —— 明明有图。
             */
            $as_thumb = as_post_thumbnail( $post_id, 'as_product_card' );
            if ( ! $as_thumb && function_exists( 'as_product_cover_id' ) ) {
                $as_cid   = as_product_cover_id( $post_id );
                $as_thumb = $as_cid ? wp_get_attachment_image( $as_cid, 'as_product_card', false, array( 'alt' => $title ) ) : '';
            }
            ?>
            <?php if ( $as_thumb ) : ?>
                <?php echo $as_thumb; ?>
            <?php else : ?>
                <?php echo as_placeholder_img( $title, 600, 600 ); ?>
            <?php endif; ?>
        </a>
        <div class="as-card-body">
            <h3 class="as-card-title"><a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a></h3>

<?php
            /*
             * 销量（v2.74.0 加，v2.74.4 挪位）：**独立一行**，不放在价格行里。
             *
             * 原来塞在价格行右侧，卡片窄（桌面网格 4 列只有 270px、手机 2 列 169px）
             * 就会把「现价 + 原价 + 销量」挤到换行 —— 实测首页 14 张卡全部换行。
             * 现在改成跟**小程序卡片一致**：标题下方单独一行「已售 N 件」。
             *
             * 口径同 as_product_sales()（实际 + 虚拟），为 0 时不输出。
             */
            $as_card_sales = function_exists( 'as_product_sales' ) ? (int) as_product_sales( $post_id ) : 0;
            ?>
            <?php if ( $as_card_sales > 0 ) : ?>
                <div class="as-card-sales">已售 <?php echo (int) $as_card_sales; ?> 件</div>
            <?php endif; ?>

            <div class="as-card-price">
                <span class="as-price-now">¥<?php echo esc_html( $price ? $price : '—' ); ?></span>
                <?php if ( $old ) : ?>
                    <span class="as-price-old">¥<?php echo esc_html( $old ); ?></span>
                <?php endif; ?>
            </div>
            <?php
            /*
             * 卡片上的券标签 —— 与价格区**同一口径**（as_web_price_info）：
             * 门槛恒按**现价**筛（v2.87.0），只出最划算的那一张。没有券就什么都不输出。
             */
            $as_card_info  = function_exists( 'as_web_price_info' ) ? as_web_price_info( $post_id ) : array();
            $as_card_promo = ! empty( $as_card_info['coupons'] ) ? $as_card_info['coupons'][0] : null;
            ?>
            <?php if ( $as_card_promo ) : ?>
                <?php $as_card_cd = as_coupon_countdown( array( $as_card_promo ) ); ?>
                <div class="as-card-promo">
                    <?php echo esc_html( $as_card_promo['label'] ); ?>
                    <?php if ( $as_card_cd['end'] ) : ?>
                        <span class="as-card-promo-cd" data-countdown="<?php echo esc_attr( $as_card_cd['end'] ); ?>"><?php echo esc_html( $as_card_cd['text'] ); ?></span>
                    <?php endif; ?>
                </div>
            <?php elseif ( ! empty( $as_card_info['na'] ) ) : ?>
                <?php // 有券但现价够不到门槛 → 明说不可用，不静默消失 ?>
                <div class="as-card-promo as-card-promo-na">优惠券不可用</div>
            <?php endif; ?>
            <?php
            // 会员折扣提醒（紧凑一行）：会员价 + 能省多少。
            // 控价 / 不参与 / 没得省 → 返回空串，整行不输出。
            echo as_vip_notice_card( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 内部已转义
            ?>
            <?php echo as_buy_button( $post_id ); ?>
        </div>
    </div>
    <?php
}

/**
 * 辅助函数：博客文章卡片
 */
function as_post_card() {
    $excerpt = wp_trim_words( wp_strip_all_tags( strip_shortcodes( get_the_excerpt() ) ), 30, '…' );
    ?>
    <article class="as-post-card">
        <a class="as-post-thumb" href="<?php the_permalink(); ?>">
            <?php echo as_post_thumb_html( get_the_ID(), 'as_blog_thumb', 720, 400 ); ?>
        </a>
        <div class="as-post-body">
            <div class="as-post-meta">
                <span><?php echo esc_html( get_the_date() ); ?></span>
                <span>·</span>
                <span><?php the_category( ' / ' ); ?></span>
            </div>
            <h3 class="as-post-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
            <?php if ( $excerpt ) : ?>
                <p class="as-post-excerpt"><?php echo esc_html( $excerpt ); ?></p>
            <?php endif; ?>
        </div>
    </article>
    <?php
}

/**
 * 面包屑数据（v2.87.52）
 *
 * 把「面包屑项」的计算与「怎么显示」拆开：
 *   · as_breadcrumb_items() 返回结构化数组 —— 前台面包屑导航用它渲染，
 *     inc/seo.php 的 BreadcrumbList 结构化数据也用它，两边永远一致
 *     （以前只有 echo 版，结构化数据要重写一遍逻辑，很容易对不上）。
 *
 * @return array 每项 array( 'name' => 名称, 'url' => 链接；最后一项 url 为空 )。
 */
function as_breadcrumb_items() {
    if ( is_front_page() ) {
        return array();
    }

    $items = array( array( 'name' => '首页', 'url' => home_url( '/' ) ) );

    if ( is_singular( 'plm_product' ) ) {
        $items[] = array( 'name' => '全部产品', 'url' => get_post_type_archive_link( 'plm_product' ) );
        $terms   = get_the_terms( get_the_ID(), 'plm_category' );
        if ( $terms && ! is_wp_error( $terms ) ) {
            $items[] = array( 'name' => $terms[0]->name, 'url' => get_term_link( $terms[0] ) );
        }
        $items[] = array( 'name' => get_the_title(), 'url' => '' );
    } elseif ( is_singular( 'post' ) ) {
        $items[] = array( 'name' => '博客', 'url' => as_blog_url() );
        $cats    = get_the_category();
        if ( $cats ) {
            $items[] = array( 'name' => $cats[0]->name, 'url' => get_category_link( $cats[0]->term_id ) );
        }
        $items[] = array( 'name' => get_the_title(), 'url' => '' );
    } elseif ( is_singular( 'page' ) ) {
        $items[] = array( 'name' => get_the_title(), 'url' => '' );
    } elseif ( is_search() ) {
        $items[] = array( 'name' => '搜索：' . get_search_query(), 'url' => '' );
    } elseif ( is_tax( 'plm_category' ) ) {
        $items[] = array( 'name' => '全部产品', 'url' => get_post_type_archive_link( 'plm_product' ) );
        $items[] = array( 'name' => single_term_title( '', false ), 'url' => '' );
    } elseif ( is_tax( 'plm_brand' ) ) {
        $items[] = array( 'name' => '全部产品', 'url' => get_post_type_archive_link( 'plm_product' ) );
        $items[] = array( 'name' => single_term_title( '', false ), 'url' => '' );
    } elseif ( is_post_type_archive( 'plm_product' ) ) {
        $items[] = array( 'name' => '全部产品', 'url' => '' );
    } elseif ( is_home() ) {
        $items[] = array( 'name' => '博客', 'url' => '' );
    } elseif ( is_category() || is_tag() ) {
        $items[] = array( 'name' => '博客', 'url' => as_blog_url() );
        $items[] = array( 'name' => single_term_title( '', false ), 'url' => '' );
    } elseif ( is_404() ) {
        $items[] = array( 'name' => '页面不存在', 'url' => '' );
    } else {
        return array();
    }

    return $items;
}

/**
 * 面包屑导航
 */
function as_breadcrumbs() {
    $items = as_breadcrumb_items();
    if ( ! $items ) {
        return;
    }

    echo '<nav class="as-breadcrumb" aria-label="面包屑导航"><ol>';
    $last = count( $items ) - 1;
    foreach ( $items as $i => $item ) {
        if ( $item['url'] && $i < $last ) {
            echo '<li><a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['name'] ) . '</a></li>';
        } else {
            echo '<li aria-current="page">' . esc_html( $item['name'] ) . '</li>';
        }
    }
    echo '</ol></nav>';
}

/**
 * SEO 相关逻辑（meta description / canonical / Open Graph / twitter:card /
 * robots / Product 结构化数据）已迁移到 inc/seo.php，便于集中维护与扩展。
 *
 * 迁移时函数名与输出行为保持不变，所以页面模板、小程序接口、
 * 已有主题设置项都零影响。引入见本文件下方 require_once。
 */

/**
 * 引入演示数据导入器
 */
require_once get_template_directory() . '/inc/demo-importer.php';

/**
 * 基础版兼容层 —— 交易类功能（支付 / 会员 / 订单 / 物流 / 小程序商城）
 * 在开源基础版里是关闭的，这里提供语义等价的降级实现，
 * 让前台模板无需为「功能关闭」写分支即可正常运行。
 */
require_once get_template_directory() . '/inc/core-compat.php';

/**
 * 橱窗风格专用产品卡（v2.87.43）—— 成对同行的信息区，与货架那张卡结构不同。
 */
require_once get_template_directory() . '/inc/home/card.php';

/**
 * 高端倒计时组件（v2.87.45）—— 首页优惠模块与 /sale/ 页共用。
 */
require_once get_template_directory() . '/inc/home/countdown.php';

/**
 * 京东详情图导入（v2.87.105）
 * 后台编辑器按钮 + 两个 AJAX（只读解析 / 勾选后入库）+ 前台长图折叠。
 * 函数定义无条件加载，方便服务端诊断脚本直接调用解析函数；
 * 各钩子只在后台编辑页 / admin-ajax / 前台正文页对应场景触发。
 */
require_once get_template_directory() . '/inc/jd-import.php';

/**
 * 前台图片性能优化（v2.87.126）
 *
 * 商品页的 22 张京东详情长图原本全部被浏览器下载（首屏 load 11.5 秒 / 5.5MB），
 * 这里给它们加 loading="lazy" + 固有尺寸，折叠态首屏只下载视口附近的图。
 * 纯输出层过滤器，零 DB 写。
 */
require_once get_template_directory() . '/inc/img-perf.php';

/**
 * 上传时自动生成 WebP 副本（v2.87.128 全站 WebP）
 *
 * 站点的图片由 Apache 做服务端内容协商（浏览器发 Accept: image/webp 才给
 * WebP），前端零改动。这个文件负责给新上传的图补上 .webp 副本，
 * 否则新图会一直走 JPEG。
 */
require_once get_template_directory() . '/inc/webp.php';

/**
 * 整页缓存（v2.87.131 性能优化）
 *
 * 未登录访客的静态型页面（首页 / 商品页 / 文章页 / 归档页）渲染完成后存成文件，
 * 下次同一路径直接读文件输出 → 省掉 WP_Query 与模板渲染。
 * 会员、已加购、带 query 的 URL、交易类页面一律走实时渲染。
 */
require_once get_template_directory() . '/inc/page-cache.php';

if ( is_admin() ) {
	require_once get_template_directory() . '/inc/health.php';
	// 后台「商城产品」列表行内编辑（现价 / 虚拟销量 / 热门）
	require_once get_template_directory() . '/inc/product-list-edit.php';
}

/**
 * 朱雀 AI 检测（发文前自检）—— API Key 只在服务端，走腾讯云 EdgeOne Makers。
 */
require_once get_template_directory() . '/inc/zhuque.php';

if ( is_admin() ) {
	require_once get_template_directory() . '/inc/zhuque-admin.php';
}

/*
 * 历史：v2.0.0 之前用过云端数据库方案（inc/cloud-sync.php / inc/wechat-pay.php），
 * v2.15.1 起这两个文件已从包内删除（无任何代码调用，且内含硬编码的云同步口令）。
 * 需要旧实现请从 v2.15.0 及更早的主题 zip 里取。
 */

/**
 * 侧边栏小工具区（sidebar.php 使用）
 */
function as_widgets_init() {
    register_sidebar( array(
        'name'          => '侧边栏',
        'id'            => 'sidebar-1',
        'description'   => '文章页 / 归档页侧边栏（可选，多为空）',
        'before_widget' => '<section id="%1$s" class="as-widget %2$s">',
        'after_widget'  => '</section>',
        'before_title'  => '<h3 class="as-widget-title">',
        'after_title'   => '</h3>',
    ) );
}
add_action( 'widgets_init', 'as_widgets_init' );

/**
 * 页脚合规提示默认文案（Customizer 与模板共用）
 */
function as_default_footer_notice() {
	/*
	 * 网站商城开启 → 站点是**自营下单**，「不直接销售商品」的说法就不成立了，
	 * 换成与实际业务一致的合规提示。
	 * ⚠️ 管理员在「主题设置 → 站点信息」里自己填过文案的话，那份优先（下面的
	 * get_theme_mod 会取后台值，不用这里的默认值）—— 这里只管默认值。
	 */
	if ( function_exists( 'as_web_mall_enabled' ) && as_web_mall_enabled() ) {
		return '本站提供商品在线下单与支付服务，付款通过微信支付 / 支付宝官方通道完成，商品与价格以下单页面为准。未成年人请勿浏览本站内容。';
	}
	return '本站为导购信息平台，不直接销售商品，所有购买行为均跳转至第三方平台完成。部分链接为推广链接，我们可能从中获得佣金，这不影响你的购买价格。未成年人请勿浏览本站内容。';
}

/**
 * 复选框净化回调
 */
function as_sanitize_checkbox( $value ) {
    return (bool) $value;
}

/**
 * 页脚「关注我们」二维码卡片的配置定义
 *
 * 三张卡片各有一组设置：图片 / 标题 / 按钮文字 / 按钮链接 / 按钮颜色。
 * 这里只声明默认值，实际取值统一走 as_qr_card()。
 */
function as_qr_cards_config() {
    return array(
        1 => array( 'name' => '个人微信',   'btn' => '添加好友', 'color' => '#1a6fb5' ),
        2 => array( 'name' => '微信公众号', 'btn' => '获取资讯', 'color' => '#2e9e5b' ),
        3 => array( 'name' => '微信小店',   'btn' => '购买产品', 'color' => '#c4650a' ),
    );
}

/**
 * 十六进制颜色清洗（空值返回空串，由调用方回落到默认色）
 */
function as_sanitize_hex_color( $color ) {
    $color = trim( (string) $color );

    return preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $color ) ? $color : '';
}

/**
 * 读取一张二维码卡片的实际配置（后台值优先，未填回落默认）
 */
function as_qr_card( $index ) {
    $config = as_qr_cards_config();
    if ( ! isset( $config[ $index ] ) ) {
        return null;
    }

    $defaults = $config[ $index ];
    $color    = (string) get_theme_mod( "as_qr_{$index}_color", $defaults['color'] );

    return array(
        'img'   => (string) get_theme_mod( "as_qr_{$index}_img", '' ),
        'title' => (string) get_theme_mod( "as_qr_{$index}_title", $defaults['name'] ),
        'btn'   => (string) get_theme_mod( "as_qr_{$index}_btn", $defaults['btn'] ),
        'url'   => (string) get_theme_mod( "as_qr_{$index}_url", '' ),
        'color' => $color ? $color : $defaults['color'],
    );
}

/**
 * 当前需要展示的二维码卡片（只有传了图片的才返回）
 */
function as_qr_cards() {
    $cards = array();

    foreach ( array_keys( as_qr_cards_config() ) as $index ) {
        $card = as_qr_card( $index );

        if ( $card && '' !== $card['img'] ) {
            $cards[] = $card;
        }
    }

    return $cards;
}

/**
 * 后台站点信息配置（页脚备案号 / 联系方式 / 合规提示 / 上传设置）
 */
function as_customize_register( $wp_customize ) {
    $wp_customize->add_section( 'as_site_info', array(
        'title'       => '站点信息（页脚）',
        'description' => '页脚显示的备案号、联系方式与合规提示。留空则前台自动隐藏。',
        'priority'    => 30,
    ) );

    $wp_customize->add_section( 'as_media_settings', array(
        'title'       => '媒体上传',
        'description' => '控制上传图片的文件命名方式。',
        'priority'    => 31,
    ) );

    $fields = array(
        'as_home_description' => array(
            'label'    => '首页 SEO 描述',
            'default'  => '',
            'sanitize' => 'sanitize_textarea_field',
            'type'     => 'textarea',
            'desc'     => '会作为首页的 meta description 和 og:description 输出，建议 60-120 字。留空则用「站名：副标题」。',
        ),
        'as_icp_number'    => array( 'label' => 'ICP 备案号',   'default' => '', 'sanitize' => 'sanitize_text_field' ),
        'as_icp_url'       => array( 'label' => '备案查询链接', 'default' => 'https://beian.miit.gov.cn/', 'sanitize' => 'esc_url_raw' ),
        'as_contact_mail'  => array( 'label' => '联系邮箱',     'default' => '', 'sanitize' => 'sanitize_email' ),
        'as_work_hours'    => array( 'label' => '工作时间',     'default' => '周一至周五 9:00-18:00', 'sanitize' => 'sanitize_text_field' ),
        'as_wechat'        => array( 'label' => '微信链接',     'default' => '', 'sanitize' => 'esc_url_raw' ),
        'as_weibo'         => array( 'label' => '微博链接',     'default' => '', 'sanitize' => 'esc_url_raw' ),
        'as_footer_notice' => array(
            'label'    => '页脚合规提示',
            'default'  => as_default_footer_notice(),
            'sanitize' => 'sanitize_textarea_field',
            'type'     => 'textarea',
        ),
        'as_rename_uploads' => array(
            'label'    => '上传图片自动重命名为时间戳',
            'default'  => true,
            'sanitize' => 'as_sanitize_checkbox',
            'type'     => 'checkbox',
            'section'  => 'as_media_settings',
            'desc'     => '把上传的图片文件名改成时间戳（如 1759456789.jpg），避免中文产品名出现在图片地址里。原始文件名会保留为附件标题，媒体库仍然可读。只对新上传的图片生效。',
        ),
    );

    foreach ( $fields as $key => $args ) {
        $wp_customize->add_setting( $key, array(
            'default'           => $args['default'],
            'sanitize_callback' => $args['sanitize'],
            'transport'         => 'refresh',
        ) );
        $wp_customize->add_control( $key, array(
            'label'       => $args['label'],
            'section'     => isset( $args['section'] ) ? $args['section'] : 'as_site_info',
            'type'        => isset( $args['type'] ) ? $args['type'] : 'text',
            'description' => isset( $args['desc'] ) ? $args['desc'] : '',
        ) );
    }

    /* ------------------------------------------------------------------
     * 页脚「关注我们」二维码卡片
     * ------------------------------------------------------------------ */
    $wp_customize->add_section( 'as_follow_qr', array(
        'title'       => '关注我们（二维码）',
        'description' => '上传二维码图片后，会在页面底部、页脚上方显示「关注我们」区块。没有上传图片的卡片不会出现，三张都不传则整个区块自动隐藏。建议使用不小于 300×300 的正方形图片。',
        'priority'    => 32,
    ) );

    $wp_customize->add_setting( 'as_qr_section_title', array(
        'default'           => '关注我们',
        'sanitize_callback' => 'sanitize_text_field',
        'transport'         => 'refresh',
    ) );
    $wp_customize->add_control( 'as_qr_section_title', array(
        'label'       => '区块标题',
        'description' => '留空则只显示卡片、不显示标题。',
        'section'     => 'as_follow_qr',
        'type'        => 'text',
    ) );

    foreach ( as_qr_cards_config() as $index => $defaults ) {
        $prefix = 'as_qr_' . $index . '_';

        // 图片用媒体库选择器，其余是普通字段
        $wp_customize->add_setting( $prefix . 'img', array(
            'default'           => '',
            'sanitize_callback' => 'esc_url_raw',
            'transport'         => 'refresh',
        ) );
        $wp_customize->add_control( new WP_Customize_Image_Control( $wp_customize, $prefix . 'img', array(
            'label'       => sprintf( '卡片 %d · 二维码图片', $index ),
            'description' => '从媒体库选择或直接上传。建议白底、四周留白，扫码识别率更高。',
            'section'     => 'as_follow_qr',
        ) ) );

        $qr_fields = array(
            $prefix . 'title' => array(
                'label'    => sprintf( '卡片 %d · 标题', $index ),
                'default'  => $defaults['name'],
                'sanitize' => 'sanitize_text_field',
                'desc'     => '显示在二维码下方。留空则不显示。',
                'type'     => 'text',
            ),
            $prefix . 'btn'   => array(
                'label'    => sprintf( '卡片 %d · 按钮文字', $index ),
                'default'  => $defaults['btn'],
                'sanitize' => 'sanitize_text_field',
                'desc'     => '留空则不显示按钮。',
                'type'     => 'text',
            ),
            $prefix . 'url'   => array(
                'label'    => sprintf( '卡片 %d · 按钮链接', $index ),
                'default'  => '',
                'sanitize' => 'esc_url_raw',
                'desc'     => '留空则按钮只作展示、不可点击（扫码类卡片一般留空）。要能点击请填完整地址，以 http:// 或 https:// 开头。',
                'type'     => 'url',
            ),
            $prefix . 'color' => array(
                'label'    => sprintf( '卡片 %d · 按钮颜色', $index ),
                'default'  => $defaults['color'],
                'sanitize' => 'as_sanitize_hex_color',
                'desc'     => '',
                'type'     => 'color',
            ),
        );

        foreach ( $qr_fields as $key => $args ) {
            $wp_customize->add_setting( $key, array(
                'default'           => $args['default'],
                'sanitize_callback' => $args['sanitize'],
                'transport'         => 'refresh',
            ) );

            if ( 'color' === $args['type'] ) {
                $wp_customize->add_control( new WP_Customize_Color_Control( $wp_customize, $key, array(
                    'label'   => $args['label'],
                    'section' => 'as_follow_qr',
                ) ) );
            } else {
                $wp_customize->add_control( $key, array(
                    'label'       => $args['label'],
                    'description' => $args['desc'],
                    'section'     => 'as_follow_qr',
                    'type'        => $args['type'],
                ) );
            }
        }
    }
}
add_action( 'customize_register', 'as_customize_register' );

/**
 * SEO 优化 —— meta 标签输出 + 「外观 → 自定义 → SEO 优化」设置项。
 *
 * 放在**所有 require 和 as_customize_register 之后**引入，两个原因：
 *   1. 依赖本文件的as_current_url() / as_sanitize_checkbox()，放在后面最稳；
 *   2. 它自己挂 customize_register（优先级默认 10）来注册「SEO 优化」设置区。
 *      若先于 as_customize_register 引入，同一 hook 同优先级时后注册的 section
 *      可能被覆盖，用户的 SEO 设置会「消失」——所以必须放在它后面。
 *
 * 无条件引入：前台要输出 meta，后台要注册设置项，两边都需要。
 */
require_once get_template_directory() . '/inc/seo.php';

/*
 * 内容创作 —— AI 文章重写（v2.84.0 起**已从主题移除**）
 *
 * 原因：该功能与主题模板无关，已抽成独立插件 `ai-rewrite`（通用、可装到任意 WP 站）。
 * 主题侧不再 require inc/ai-writer.php —— 后台「内容创作」菜单由插件提供。
 * 部署顺序有讲究：**必须先更新主题、再启用插件**（旧主题仍带 ai-writer.php 时，
 * 插件会检测到该文件而主动让路，避免两边同时声明 as_ai_* 造成 Cannot redeclare）。
 * 留档见 .tools/removed-v2.84.0/ai-writer.php。
 */

/**
 * 网站设置 —— 后台菜单形式的站点/SEO/二维码设置页（v2.59.1）。
 *
 * 与 inc/seo.php 的关系：
 *   - 两者读写**同一批 theme_mod 键**，只是编辑入口不同；
 *   - site-settings.php 负责「外观 → 自定义」以外的编辑界面；
 *   - seo.php 负责前台 meta 输出，并在 customize_register(优先级20) 里
 *     把已搬走的 section 从自定义里移除，避免两处都能改。
 * 依赖 as_qr_cards_config() / as_sanitize_hex_color() / as_sanitize_checkbox()
 * （都在本文件中、且定义在本 require 之前），所以必须放在这里。
 */
require_once get_template_directory() . '/inc/site-settings.php';

/**
 * 品牌添加 Logo 图片字段
 */
function as_brand_add_logo_field() {
    ?>
    <div class="form-field">
        <label for="brand_thumb">品牌 Logo</label>
        <input type="button" class="button" id="brand_thumb_btn" value="选择图片">
        <input type="hidden" id="brand_thumb" name="brand_thumb" value="">
        <p class="description">建议上传正方形图片，展示效果最好。</p>
    </div>
    <?php
}
add_action( 'plm_brand_add_form_fields', 'as_brand_add_logo_field' );

function as_brand_edit_logo_field( $term ) {
    $thumb_id = get_term_meta( $term->term_id, 'brand_thumb', true );
    ?>
    <tr class="form-field">
        <th><label for="brand_thumb">品牌 Logo</label></th>
        <td>
            <input type="button" class="button" id="brand_thumb_btn" value="<?php echo $thumb_id ? '更换图片' : '选择图片'; ?>">
            <input type="hidden" id="brand_thumb" name="brand_thumb" value="<?php echo esc_attr( $thumb_id ); ?>">
            <?php if ( $thumb_id ) : ?>
                <p><img src="<?php echo esc_url( wp_get_attachment_url( $thumb_id ) ); ?>" style="max-width:80px;border-radius:8px;"></p>
            <?php endif; ?>
            <p class="description">建议上传正方形图片。</p>
        </td>
    </tr>
    <?php
}
add_action( 'plm_brand_edit_form_fields', 'as_brand_edit_logo_field' );

function as_save_brand_logo( $term_id ) {
    if ( ! current_user_can( 'manage_categories' ) ) return;
    if ( isset( $_POST['brand_thumb'] ) ) {
        update_term_meta( $term_id, 'brand_thumb', intval( $_POST['brand_thumb'] ) );
    }
}
add_action( 'created_plm_brand', 'as_save_brand_logo' );
add_action( 'edited_plm_brand',  'as_save_brand_logo' );

/**
 * 品牌后台加载媒体库 + Logo 选择器脚本
 */
function as_brand_admin_scripts( $hook ) {
    if ( 'edit-tags.php' !== $hook && 'term.php' !== $hook ) return;

    wp_enqueue_media();

    wp_enqueue_script(
        'as-brand-logo',
        get_template_directory_uri() . '/assets/js/brand-logo.js',
        array( 'jquery' ),
        as_asset_version( '/assets/js/brand-logo.js' ),
        true
    );
}
add_action( 'admin_enqueue_scripts', 'as_brand_admin_scripts' );

/**
 * 短代码 [product_card id="123"]
 * 文章内嵌产品卡片（独立结构 .as-pc，不复用列表页卡片）
 */
function as_product_card_shortcode( $atts ) {
    $atts = shortcode_atts( array(
        'id'    => 0,
        'style' => 'card',
    ), $atts );

    $post_id = intval( $atts['id'] );
    if ( ! $post_id ) return '';

    $product = get_post( $post_id );
    if ( ! $product || $product->post_type !== 'plm_product' || 'publish' !== $product->post_status ) return '';

    $title     = $product->post_title;
    $url       = get_post_meta( $post_id, 'dg_buy_url', true );
    $price     = get_post_meta( $post_id, 'dg_price', true );
    $old       = get_post_meta( $post_id, 'dg_old_price', true );
    $permalink = get_permalink( $post_id );

    // 规格：优先新字段，兼容旧数据
    $spec = get_post_meta( $post_id, 'dg_spec', true );
    if ( ! $spec ) {
        $spec = get_post_meta( $post_id, 'dg_material', true );
    }

    $brands     = get_the_terms( $post_id, 'plm_brand' );
    $brand_name = ( $brands && ! is_wp_error( $brands ) ) ? $brands[0]->name : '';
    $meta_bits  = array_filter( array( $brand_name, $spec ) );

    /*
     * 会员到手价金额（v2.86.13 移入右列）：口径与网页价格区/商品卡片/
     * 小程序完全一致 —— 都读 as_web_price_info()['vip']/['final']。
     * 控价 / 不参与 / 没得省时为空串 → 右列那一块不输出。
     */
    /*
     * 折扣标「-XX%」（v2.86.69 改口径，与抢购卡 as_flash_card() 同一算法）：
     * **现价 vs 到手价** —— 会员折扣/券一改就变；没有到手价则不输出。
     */
    $as_pc_vip_info = function_exists( 'as_web_price_info' ) ? as_web_price_info( $post_id ) : array();
    $off_pc = '';
    if ( $price > 0 && isset( $as_pc_vip_info['final'] ) && null !== $as_pc_vip_info['final']
        && (float) $as_pc_vip_info['final'] < (float) $price ) {
        $off_pc = '-' . (int) round( ( (float) $price - (float) $as_pc_vip_info['final'] ) / (float) $price * 100 ) . '%';
    }
    $sales_pc = function_exists( 'as_product_sales' ) ? (int) as_product_sales( $post_id ) : 0;

    $as_pc_vip_money = '';
    if ( function_exists( 'as_vip_money' ) ) {
        if ( ! empty( $as_pc_vip_info['vip'] ) && null !== $as_pc_vip_info['final'] ) {
            $as_pc_vip_money = as_vip_money( $as_pc_vip_info['final'] );
        }
    }



    // 缩略图（v2.86.47 重设计，推倒重来）：
    // 🔴 必须用 **'full' 原图**（wp_get_attachment_image_src 第二个参数），
    // 绝不再调用任何缩略图尺寸 —— 之前用 as_pc_fit / as_product_card /
    // as-pc-trim(GD) 全都在服务端裁过或缩放过，CSS object-fit 再调也救不回
    // 已丢失的像素（用户判定的问题根源）。原图 + CSS contain = 完整显示。
    // 特色图优先；没设特色图但传了图集的商品回退图集第一张
    // （与商品卡 as_flash_card() 同一口径 as_product_cover_id()）。
    $as_pc_cid = (int) get_post_thumbnail_id( $post_id );
    if ( ! $as_pc_cid && function_exists( 'as_product_cover_id' ) ) {
        $as_pc_cid = as_product_cover_id( $post_id );
    }
    $thumb = '';
    if ( $as_pc_cid ) {
        $as_pc_full = wp_get_attachment_image_src( $as_pc_cid, 'full' );
        if ( ! empty( $as_pc_full[0] ) ) {
            $thumb = sprintf(
                '<img class="as-pc-img" src="%s" alt="%s" decoding="async">',
                esc_url( $as_pc_full[0] ),
                esc_attr( $title )
            );
        }
    }
    if ( ! $thumb ) {
        $thumb = as_placeholder_img( $title, 200, 200, 'as-pc-img' );
    }

    // 内联版
    if ( 'inline' === $atts['style'] ) {
        if ( ! $url ) {
            return '';
        }
        return sprintf(
            '<a class="as-inline-product" href="%s" target="_blank" rel="nofollow sponsored noopener">🛒 %s%s</a>',
            esc_url( $url ),
            esc_html( $title ),
            $price ? ' ¥' . esc_html( $price ) : ''
        );
    }

    // 卡片版
    ob_start();
    ?>
    <div class="as-pc">
        <a class="as-pc-thumb" href="<?php echo esc_url( $permalink ); ?>">
            <?php echo $thumb; ?>
        </a>
        <div class="as-pc-info">
            <h3 class="as-pc-title"><a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a></h3>
            <?php
            /* v2.86.40：浅色卡专用价格类（as-pc-price/now/old），
               不再复用深色卡的 .as-flash-price。
               券标签仍不输出（用户明确「优惠券信息就算了」）。*/
            ?>
            <div class="as-pc-price">
                <span class="as-pc-now">¥<?php echo esc_html( $price ? $price : '—' ); ?></span>
                <?php if ( $old ) : ?>
                    <span class="as-pc-old">¥<?php echo esc_html( $old ); ?></span>
                <?php endif; ?>
            </div>
            <?php if ( $sales_pc > 0 ) : ?>
                <div class="as-pc-sales">已售 <?php echo (int) $sales_pc; ?> 件</div>
            <?php endif; ?>
        </div>
        <?php
        /*
         * v2.86.47 重设计：CTA 为 .as-pc 的第三个直接子元素，flex 横排
         * 落在**右侧竖列**（参考用户给定的标准结构：图 / 信息 / 按钮）。
         * v2.87.2：会员到手价也挪进这一列，与「查看详情」竖排（用户要求）——
         * 原来挂在中列，手机窄屏下中列被按钮挤窄，「会员到手」四字会折行。
         * 仍跳本商品详情页（同页），不用 as_buy_button()
         * —— 那个是「立即抢购」行为（跳 dg_buy_url 外链 / 交给小程序跳转 JS）。
         */
        ?>
        <div class="as-pc-act">
            <?php
            /* v2.87.3：会员到手价放在「查看详情」**上方**（用户要求）。
               口径与商品卡片/详情页完全一致 —— 全站只此一个输出函数
               as_vip_notice_card()，控价 / 不参与 / 没得省时它自己返回空串，
               这里无需判断；返回空串时按钮自动回到列首。*/
            echo as_vip_notice_card( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 内部已转义
            ?>
            <a class="as-flash-btn" href="<?php echo esc_url( $permalink ); ?>">查看详情</a>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'product_card', 'as_product_card_shortcode' );

/**
 * 正文内嵌文章卡：[article_card id="123"]
 * 整张卡就是一个 <a>，桌面/手机都好点；无缩略图时用文字占位，不出现破图。
 */
function as_article_card_shortcode( $atts ) {
    $atts = shortcode_atts( array( 'id' => 0 ), $atts, 'article_card' );
    $id   = absint( $atts['id'] );
    if ( ! $id ) {
        return '';
    }

    $p = get_post( $id );
    if ( ! $p || 'post' !== $p->post_type || 'publish' !== $p->post_status ) {
        return '';
    }

    $url   = get_permalink( $p );
    $title = get_the_title( $p );

    // 摘要优先用 excerpt，没有就从正文里截（先去掉短代码和标签）
    $desc = trim( wp_strip_all_tags( get_the_excerpt( $p ) ) );
    // WP 自动摘要会带 &#8221; / [&hellip;] 这类实体，显示出来很脏
    $desc = html_entity_decode( $desc, ENT_QUOTES, 'UTF-8' );
    $desc = preg_replace( '/\s*\[\s*…\s*\]\s*$/u', '…', $desc );
    $desc = trim( $desc );    if ( '' === $desc ) {
        $desc = wp_trim_words(
            wp_strip_all_tags( strip_shortcodes( $p->post_content ) ),
            36,
            '…'
        );
    }

    // 站内惯例不设特色图，所以优先用特色图，没有就取正文第一张图
    $thumb = '';
    if ( has_post_thumbnail( $p ) ) {
        $thumb = get_the_post_thumbnail( $p, 'medium', array(
            'loading' => 'lazy',
            'alt'     => esc_attr( $title ),
        ) );
    } elseif ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $p->post_content, $m ) ) {
        $thumb = '<img src="' . esc_url( $m[1] ) . '" alt="' . esc_attr( $title ) . '" loading="lazy">';
    }

    $cats = get_the_category( $p );
    $cat  = ! empty( $cats ) ? $cats[0]->name : '';
    $date = get_the_date( 'Y-m-d', $p );
    $meta = implode( ' · ', array_filter( array( $cat, $date ) ) );

    ob_start();
    ?>
    <a class="as-ac" href="<?php echo esc_url( $url ); ?>">
        <span class="as-ac-thumb<?php echo $thumb ? '' : ' is-empty'; ?>"><?php echo $thumb; // phpcs:ignore -- 内部已转义 ?></span>
        <span class="as-ac-info">
            <span class="as-ac-title"><?php echo esc_html( $title ); ?></span>
            <span class="as-ac-desc"><?php echo esc_html( $desc ); ?></span>
            <span class="as-ac-meta">
                <span class="as-ac-meta-text"><?php echo esc_html( $meta ); ?></span>
                <span class="as-ac-more">阅读全文 →</span>
            </span>
        </span>
    </a>
    <?php
    return ob_get_clean();
}
add_shortcode( 'article_card', 'as_article_card_shortcode' );

/**
 * 正文内嵌销量榜：[sales_rank] 或 [sales_rank count="5"]
 *
 * 与首页 / 小程序同一套口径：as_top_sales_ids() 排序、as_web_price_info() 价格券、
 * as_vip_notice_card() 会员价、as_product_sales() 销量、as_product_cover_id() 封面。
 * 不输出券名和倒计时（2026-11-10 用户确认的 v3 设计）；券够不到门槛 / 控价商品
 * 对非会员看不到券时，券标签自然不显示，不另输出「券不可用」占位。
 */
function as_sales_rank_shortcode( $atts ) {
    $atts = shortcode_atts( array( 'count' => 3 ), $atts, 'sales_rank' );
    $count = max( 1, min( 10, absint( $atts['count'] ) ) );

    if ( ! function_exists( 'as_top_sales_ids' ) ) {
        return '';
    }
    $ids = as_top_sales_ids( $count );
    if ( empty( $ids ) ) {
        return '';
    }

    $items = array();
    $rank  = 0;

    foreach ( $ids as $id ) {
        $p = get_post( $id );
        if ( ! $p || 'plm_product' !== $p->post_type || 'publish' !== $p->post_status ) {
            continue;
        }
        $rank++;

        $title = get_the_title( $id );
        $url   = get_permalink( $id );
        $price = get_post_meta( $id, 'dg_price', true );
        $old   = get_post_meta( $id, 'dg_old_price', true );

        // 封面：全站统一口径（特色图 → 图集第一张）
        $cid = function_exists( 'as_product_cover_id' ) ? as_product_cover_id( $id ) : 0;
        if ( ! $cid ) {
            $cid = get_post_thumbnail_id( $id );
        }
        $img = '';
        if ( $cid ) {
            $src = wp_get_attachment_image_src( $cid, 'full' );
            if ( ! empty( $src[0] ) ) {
                // 榜单只有 3-5 张小图，不用懒加载，避免首屏最后一行出现白块
                $img = '<img src="' . esc_url( $src[0] ) . '" alt="' . esc_attr( $title ) . '">';
            }
        }

        // 价格 / 会员价统一走 as_web_price_info()
        $info = function_exists( 'as_web_price_info' ) ? as_web_price_info( $id ) : array();

        $vip = '';
        if ( ! empty( $info['vip'] ) && isset( $info['final'] ) && null !== $info['final']
            && function_exists( 'as_vip_money' ) ) {
            $tag = ! empty( $info['tag'] ) ? (string) $info['tag'] : '会员价';
            $vip = $tag . ' ¥' . as_vip_money( $info['final'] );
        }

        $sales = function_exists( 'as_product_sales' ) ? (int) as_product_sales( $id ) : 0;

        ob_start();
        ?>
        <li>
            <a class="as-rank-item" href="<?php echo esc_url( $url ); ?>">
                <span class="as-rank-thumb"><?php echo $img; // phpcs:ignore -- 内部已转义 ?><span class="as-rank-no r<?php echo (int) $rank; ?>"><?php echo (int) $rank; ?></span></span>
                <span class="as-rank-main">
                    <span class="as-rank-name"><?php echo esc_html( $title ); ?></span>
                    <span class="as-rank-prices"><b>¥<?php echo esc_html( $price ); ?></b><?php if ( $old ) : ?><s>¥<?php echo esc_html( $old ); ?></s><?php endif; ?></span>
                </span>
                <span class="as-rank-data">
                    <?php if ( $sales > 0 ) : ?><span class="as-rank-sales">已售 <?php echo (int) $sales; ?> 件</span><?php endif; ?>
                    <?php if ( $vip ) : ?><span class="as-rank-vip"><?php echo esc_html( $vip ); ?></span><?php endif; ?>
                </span>
                <span class="as-rank-arrow">→</span>
            </a>
        </li>
        <?php
        $items[] = ob_get_clean();
    }

    if ( ! $items ) {
        return '';
    }

    return '<section class="as-rank">'
        . '<div class="as-rank-head"><span class="as-rank-title">销量排行</span>'
        . '<span class="as-rank-tag">当前 TOP ' . count( $items ) . '</span></div>'
        . '<ol class="as-rank-list">' . implode( '', $items ) . '</ol>'
        . '</section>';
}
add_shortcode( 'sales_rank', 'as_sales_rank_shortcode' );

/** 防止榜单被 wpautop 套上 <p>（与产品卡同一优先级 12） */
function as_fix_rank_autop( $content ) {
    if ( false === strpos( $content, 'as-rank' ) ) {
        return $content;
    }
    $fixed = preg_replace( '/<p>\s*(<section class="as-rank")/i', '$1', $content );
    if ( null !== $fixed ) {
        $content = $fixed;
    }
    $fixed = preg_replace( '#(</section>)\s*</p>#i', '$1', $content );
    if ( null !== $fixed ) {
        $content = $fixed;
    }
    return $content;
}
add_filter( 'the_content', 'as_fix_rank_autop', 12 );


/**
 * 防止产品卡片被 wpautop 套上 <p>
 * wpautop 在优先级 10 执行、do_shortcode 在 11，所以必须挂在 11 之后才处理得到渲染结果。
 */
function as_fix_product_card_autop( $content ) {
    // 没有卡片就直接返回，避免无谓的正则开销
    if ( false === strpos( $content, 'as-pc' ) && false === strpos( $content, 'as-ac' ) ) {
        return $content;
    }

    // <p><div class="as-pc"> -> <div class="as-pc">
    $fixed = preg_replace( '/<p>\s*(<div class="as-pc")/i', '$1', $content );
    if ( null !== $fixed ) {
        $content = $fixed;
    }

    // 卡片后面多出来的孤立 </p>（要求卡片与它之间没有出现新的 <p，避免误删正常段落）
    $fixed = preg_replace( '/<div class="as-pc"((?:(?!<p[\s>]).)*?)<\/p>/is', '<div class="as-pc"$1', $content );
    if ( null !== $fixed ) {
        $content = $fixed;
    }

    // 文章卡：[article_card] 渲染成块级 <a>，同样别被 <p> 包住
    $fixed = preg_replace( '/<p>\s*(<a class="as-ac")/i', '$1', $content );
    if ( null !== $fixed ) {
        $content = $fixed;
    }
    $fixed = preg_replace( '/<a class="as-ac"((?:(?!<p[\s>]).)*?)<\/p>/is', '<a class="as-ac"$1', $content );
    if ( null !== $fixed ) {
        $content = $fixed;
    }

    return $content;
}
add_filter( 'the_content', 'as_fix_product_card_autop', 12 );

/**
 * 后台：在"添加媒体"旁边加"插入产品"按钮
 */
function as_add_product_media_button() {
    $screen = get_current_screen();
    if ( ! $screen || ! in_array( $screen->base, array( 'post', 'page' ), true ) ) return;

    wp_enqueue_media();
    wp_enqueue_script(
        'as-product-insert',
        get_template_directory_uri() . '/assets/js/product-insert.js',
        array( 'jquery', 'jquery-ui-dialog' ),
        as_asset_version( '/assets/js/product-insert.js' ),
        true
    );

    $products = get_posts( array(
        'post_type'      => 'plm_product',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ) );

    $product_list = array();
    foreach ( $products as $p ) {
        $price = get_post_meta( $p->ID, 'dg_price', true );
        $product_list[] = array(
            'id'    => $p->ID,
            'title' => $p->post_title,
            'price' => $price ? '¥' . $price : '',
        );
    }
    wp_localize_script( 'as-product-insert', 'asProducts', $product_list );

    // 「插入文章」按钮：默认列最近 10 篇，搜索覆盖全部
    wp_enqueue_script(
        'as-article-insert',
        get_template_directory_uri() . '/assets/js/article-insert.js',
        array( 'jquery', 'jquery-ui-dialog' ),
        as_asset_version( '/assets/js/article-insert.js' ),
        true
    );

    $article_posts = get_posts( array(
        'post_type'        => 'post',
        'posts_per_page'   => 300,
        'post_status'      => 'publish',
        'orderby'          => 'date',
        'order'            => 'DESC',
        'suppress_filters' => true,
    ) );

    $article_list = array();
    foreach ( $article_posts as $a ) {
        $a_cats = get_the_category( $a->ID );
        $article_list[] = array(
            'id'    => $a->ID,
            'title' => $a->post_title,
            'date'  => get_the_date( 'Y-m-d', $a ),
            'cat'   => ! empty( $a_cats ) ? $a_cats[0]->name : '',
        );
    }
    wp_localize_script( 'as-article-insert', 'asArticles', $article_list );

    // 「插入排行榜」按钮：榜单是动态短代码，不用弹窗，点一下直接插
    wp_enqueue_script(
        'as-rank-insert',
        get_template_directory_uri() . '/assets/js/rank-insert.js',
        array( 'jquery' ),
        as_asset_version( '/assets/js/rank-insert.js' ),
        true
    );
}
add_action( 'admin_enqueue_scripts', 'as_add_product_media_button' );

/**
 * 后台：输出"插入产品"按钮 HTML
 */
function as_render_product_media_button() {
    $screen = get_current_screen();
    if ( ! $screen || ! in_array( $screen->base, array( 'post', 'page' ), true ) ) return;
    ?>
    <style>
        #as-insert-product-btn { color: #c2185b; border-color: #c2185b; }
        #as-insert-product-btn:hover { background: #c2185b; color: #fff; border-color: #c2185b; }
        #as-insert-product-btn .dashicons { vertical-align: middle; margin-right: 4px; margin-top: -2px; }
        #as-insert-article-btn { color: #2f6fed; border-color: #2f6fed; margin-left: 4px; }
        #as-insert-article-btn:hover { background: #2f6fed; color: #fff; border-color: #2f6fed; }
        #as-insert-article-btn .dashicons { vertical-align: middle; margin-right: 4px; margin-top: -2px; }
        .as-article-picker input#as-article-search {
            box-sizing: border-box; width: 100%; padding: 10px 12px; margin: 0 0 12px;
            border: 1px solid #d7dce5; border-radius: 10px; font-size: 16px; line-height: 1.5;
        }
        .as-article-picker input#as-article-search:focus { border-color: #2f6fed; outline: none; box-shadow: 0 0 0 2px rgba(47,111,237,.15); }
        .as-article-picker .as-article-list { max-height: 380px; overflow-y: auto; -webkit-overflow-scrolling: touch; }
        .as-article-picker .as-article-item {
            box-sizing: border-box; display: flex; flex-direction: column; justify-content: center;
            gap: 3px; min-height: 48px; padding: 10px 12px; margin-bottom: 6px;
            border: 1px solid #e4e7ee; border-radius: 10px; cursor: pointer;
        }
        .as-article-picker .as-article-item:hover,
        .as-article-picker .as-article-item:focus { border-color: #2f6fed; background: #eef3fe; outline: none; }
        .as-article-picker .as-article-item strong { font-size: 14px; font-weight: 600; color: #14171f; line-height: 1.45; }
        .as-article-picker .as-article-meta { font-size: 12px; color: #697386; }
        .as-article-picker .as-article-empty { padding: 24px; text-align: center; color: #697386; font-size: 13px; }
        .as-article-picker .as-article-hint { margin: 10px 0 0; font-size: 12px; color: #697386; line-height: 1.6; }
        @media screen and (max-width: 600px) {
            .as-article-dialog.ui-dialog { width: calc(100vw - 24px) !important; left: 12px !important; top: 12px !important; }
            .as-article-picker .as-article-list { max-height: 52vh; }
            .as-article-picker .as-article-item { min-height: 54px; padding: 12px; }
            #as-insert-product-btn, #as-insert-article-btn { padding: 6px 10px; font-size: 13px; }
        }
    </style>
    <button type="button" id="as-insert-product-btn" class="button insert-product">
        <span class="dashicons dashicons-cart"></span>插入产品
    </button>
    <button type="button" id="as-insert-article-btn" class="button insert-article">
        <span class="dashicons dashicons-admin-page"></span>插入文章
    </button>
    <style>
        #as-insert-rank-btn { color: #a8791b; border-color: #a8791b; margin-left: 4px; }
        #as-insert-rank-btn:hover { background: #a8791b; color: #fff; border-color: #a8791b; }
        #as-insert-rank-btn .dashicons { vertical-align: middle; margin-right: 4px; margin-top: -2px; }
        @media screen and (max-width: 600px) {
            #as-insert-rank-btn { padding: 6px 10px; font-size: 13px; }
        }        .as-rank-picker-label { font-size: 13px; font-weight: 600; color: #14171f; margin-bottom: 10px; }
        .as-rank-chips { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
        .as-rank-chip {
            min-height: 44px; padding: 8px 14px; font-size: 14px; font-family: inherit;
            border: 1px solid #e4e7ee; border-radius: 10px; background: #fff;
            color: #3c4350; cursor: pointer;
        }
        .as-rank-chip:hover { border-color: #a8791b; }
        .as-rank-chip.on { background: #a8791b; color: #fff; border-color: #a8791b; font-weight: 600; }
        .as-rank-custom { display: flex; align-items: center; gap: 10px; }
        .as-rank-custom label { font-size: 12px; color: #697386; }
        .as-rank-custom input {
            width: 80px; padding: 8px; font-size: 16px;
            border: 1px solid #d7dce5; border-radius: 8px;
        }
        .as-rank-custom input:focus { border-color: #a8791b; outline: none; box-shadow: 0 0 0 2px rgba(168,121,27,.15); }
        .as-rank-hint { margin: 12px 0 0; font-size: 12px; line-height: 1.6; color: #697386; }
        .as-rank-overlay {
            position: fixed; inset: 0; z-index: 100000;
            background: rgba(10,12,18,.55);
            display: flex; align-items: center; justify-content: center; padding: 12px;
        }
        .as-rank-modal {
            width: 100%; max-width: 420px; max-height: calc(100vh - 24px);
            overflow-y: auto; -webkit-overflow-scrolling: touch;
            background: #fff; border-radius: 14px;
            box-shadow: 0 18px 50px rgba(0,0,0,.28);
            display: flex; flex-direction: column;
        }
        .as-rank-modal-hd { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 16px 18px 4px; }
        .as-rank-modal-hd h3 { margin: 0; font-size: 15px; font-weight: 600; color: #14171f; }
        .as-rank-x { border: 0; background: transparent; color: #8a93a3; font-size: 22px; line-height: 1; padding: 4px 8px; cursor: pointer; }
        .as-rank-modal-bd { padding: 10px 18px 16px; }
        .as-rank-modal-ft {
            position: sticky; bottom: 0; margin-top: auto;
            display: flex; gap: 8px; justify-content: flex-end;
            padding: 12px 18px; border-top: 1px solid #eef0f4; background: #fff;
        }
        .as-rank-cancel, .as-rank-confirm {
            min-height: 44px; padding: 8px 18px; border-radius: 10px;
            font-size: 14px; font-family: inherit; cursor: pointer;
        }
        .as-rank-cancel { border: 1px solid #dfe3ea; background: #fff; color: #5f6775; }
        .as-rank-confirm { border: 1px solid #a8791b; background: #a8791b; color: #fff; font-weight: 600; }
        @media screen and (max-width: 600px) {
            .as-rank-overlay { align-items: flex-start; padding: 10px; }
            .as-rank-modal { max-height: calc(100vh - 20px); border-radius: 12px; }
            .as-rank-modal-ft { padding-bottom: max(12px, env(safe-area-inset-bottom)); }
            .as-rank-cancel, .as-rank-confirm { flex: 1; }
            .as-rank-chip { flex: 1; min-width: 84px; padding: 8px 6px; }
        }


    </style>
    <button type="button" id="as-insert-rank-btn" class="button insert-rank">
        <span class="dashicons dashicons-awards"></span>插入排行榜
    </button>

    <?php
}
add_action( 'media_buttons', 'as_render_product_media_button', 20 );

/**
 * 后台：加载 jQuery UI Dialog 样式（用 WP 内置资源，不再走 CDN）
 */
function as_admin_dialog_styles() {
    $screen = get_current_screen();
    if ( ! $screen || ! in_array( $screen->base, array( 'post', 'page' ), true ) ) return;
    wp_enqueue_style( 'wp-jquery-ui-dialog' );
}
add_action( 'admin_enqueue_scripts', 'as_admin_dialog_styles' );

/**
 * 后台：移动端适配样式（全后台加载）
 *
 * 让 WP 后台在手机上也能正常改商品、发订单、填配置，不必再开电脑。
 * 三个最要紧的点：
 *   · 输入框字号 ≥16px —— iOS Safari 聚焦小于 16px 的输入框会放大整页，放大后
 *     常常找不回原来的位置，是手机端后台体验最差的一个坑。
 *   · 宽表格横向滚动 —— WP 的 widefat 表格列一多就把整页撑到几千像素宽，
 *     右侧的列完全点不到。
 *   · 属性面板改上下堆叠 —— 桌面端「标签 132px + 控件」两列在窄屏会把控件压扁。
 *
 * 登录页（login.php）不加载：它自带样式，且我们的规则对登录框没意义。
 */
function as_admin_mobile_styles() {
    if ( ! function_exists( 'get_current_screen' ) ) {
        return;
    }
    $screen = get_current_screen();
    if ( $screen && 'login' === $screen->base ) {
        return;
    }

    wp_enqueue_style(
        'as-admin-mobile',
        get_template_directory_uri() . '/assets/css/admin-mobile.css',
        array(),
        as_asset_version( '/assets/css/admin-mobile.css' )
    );
}
add_action( 'admin_enqueue_scripts', 'as_admin_mobile_styles' );

/**
 * 后台：经典编辑器（文章 / 页面 / 产品）移动端 App 风格样式
 *
 * 两道锁把影响面钉死：
 *   1. 只在 post.php / post-new.php 加载（$screen->base === 'post'），
 *      其它后台页一个字节都不加载；
 *   2. 样式文件内部**所有**规则都包在 @media (max-width: 782px) 里
 *      —— 桌面端零影响。
 *
 * 优先级 20 > as_admin_mobile_styles 的 10：保证注册在 admin-mobile.css
 * 之后，万一有同特异性冲突也能压过它（文件内还额外抬了特异性，双保险）。
 *
 * 核心改动：把「发布框 #submitdiv」固定到底部 —— 原生布局里它排在整篇正文
 * 之后，手机上要滚很久才够得着发布/更新按钮。
 */
function as_admin_editor_app_styles() {
    if ( ! function_exists( 'get_current_screen' ) ) {
        return;
    }
    $screen = get_current_screen();
    if ( ! $screen || 'post' !== $screen->base ) {
        return;
    }

    wp_enqueue_style(
        'as-admin-editor-app',
        get_template_directory_uri() . '/assets/css/admin-editor-app.css',
        array(),
        as_asset_version( '/assets/css/admin-editor-app.css' )
    );
}
add_action( 'admin_enqueue_scripts', 'as_admin_editor_app_styles', 20 );
