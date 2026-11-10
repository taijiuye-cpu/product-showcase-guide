<?php
/**
 * 整页缓存（v2.87.131）
 * ---------------------------------------------------------------------------
 * 背景（生产环境实测数据：商品页一天）：
 *   · 日请求 39.7 万 / 日流量 6.4GB，其中 **HTML 占真实流量的 84%**（4.3GB）
 *   · 首页 TTFB 468ms、商品页 409ms（静态资源 287ms、PHP 净渲染约 200ms）
 *   · 没有页面缓存 → 每个访客都在跑完整 WordPress（bootstrap + 查询 + 模板渲染）
 *
 * 做法：未登录访客的「静态型」GET 页面，把**渲染完成的 HTML** 存成文件；
 *       下次同一路径直接读文件输出并 exit —— 省掉查询与模板渲染。
 *
 * 🔴 为什么只缓存「未登录、无 query」的页面：
 *   1) 已登录访客看到的页面可能带个性化内容 → 必须实时渲染；
 *   2) 带 query 的 URL 参数多、不好归一 → 一律不缓存；
 *   3) 表单类页面（含 nonce）→ 一律不缓存。
 *
 * 注：基础版不含会员价与购物车，因此不需要按身份 / 购物车 cookie 分流；
 *     完整版（含交易模块）需要额外排除已登录用户与带购物车 cookie 的请求。
 *
 * 🔴 读取时机选 `init`（不是 template_redirect）：init 在 WP_Query 之前触发，
 *    命中时能连查询和模板渲染一起省掉（实测省 100ms 以上）。
 *    写入时机是 template_redirect 末尾 + shutdown（要拿到渲染后的完整 HTML）。
 *
 * 失效：
 *   · TTL 10 分钟（即使钩子漏了也会自动刷新）
 *   · 内容变更钩子（save_post / deleted_post / edit_terms / 主题设置保存…）→ 版本号 +1，
 *     旧键整体作废（与 inc/api-cache.php 的 as_api_cache_ver 同一思路，但用独立 option）
 *
 * 关闭：wp-config.php 加 define( 'AS_PAGE_CACHE_OFF', true );
 */

if ( defined( 'AS_PAGE_CACHE_OFF' ) && AS_PAGE_CACHE_OFF ) {
	return;
}

/**
 * 内容版本号：内容一变 +1，旧缓存整体作废。
 */
function as_page_cache_ver() {
	return (int) get_option( 'as_page_cache_ver', 1 );
}

/**
 * 让全部页面缓存立即失效。
 */
function as_page_cache_flush() {
	update_option( 'as_page_cache_ver', as_page_cache_ver() + 1, false );
}

/**
 * 缓存目录（放在 wp-content/cache 下，不在 uploads 里 —— uploads 会被媒体库清理逻辑扫）。
 */
function as_page_cache_dir() {
	return WP_CONTENT_DIR . '/cache/as-page';
}

/**
 * 当前请求的路径（去掉 query 与结尾斜杠）。
 */
function as_page_cache_request_path() {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	if ( '' === $uri ) {
		return '';
	}
	$path = wp_parse_url( $uri, PHP_URL_PATH );
	if ( ! $path ) {
		return '';
	}
	$path = '/' . ltrim( $path, '/' );
	if ( '/' !== $path ) {
		$path = rtrim( $path, '/' );
	}
	return $path;
}

/**
 * 这个请求能不能用/写页面缓存。
 *
 * @param bool $for_write 写缓存时还要额外判断状态码（在调用处做）
 * @return string 缓存键；空串表示不缓存
 */
function as_page_cache_key() {
	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
		return '';
	}

	/*
	 * 已登录 → 绝不缓存（登录态页面可能带个性化内容）。
	 * 🔴 WordPress 的登录 cookie 名是**带站点 hash**的
	 *    wordpress_logged_in_<md5(siteurl)> / wordpress_sec_<hash>，
	 *    按固定名匹配根本匹配不到 → 已登录用户会命中游客缓存。
	 *    这里用**前缀匹配** + is_user_logged_in() 双保险。
	 */
	foreach ( array_keys( $_COOKIE ) as $as_pc_ck ) {
		if ( 0 === strpos( $as_pc_ck, 'wordpress_logged_in' ) || 0 === strpos( $as_pc_ck, 'wordpress_sec' ) ) {
			return '';
		}
	}
	if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
		return '';
	}
	// 购物车 / 订单 / 立即购买（cookie 存在 → 角标与页面内容会变）
	foreach ( array( 'as_web_cart', 'as_web_orders', 'as_web_buynow' ) as $ck ) {
		if ( ! empty( $_COOKIE[ $ck ] ) ) {
			return '';
		}
	}
	// 评论 cookie（表单默认值会变）
	if ( ! empty( $_COOKIE['comment_author'] ) || ! empty( $_COOKIE['comment_author_email'] ) ) {
		return '';
	}

	// 带 query 的 URL 不缓存
	$qs = isset( $_SERVER['QUERY_STRING'] ) ? trim( (string) wp_unslash( $_SERVER['QUERY_STRING'] ) ) : '';
	if ( '' !== $qs ) {
		return '';
	}

	$path = as_page_cache_request_path();
	if ( '' === $path ) {
		return '';
	}

	// 系统 / 交易类 / 特殊端点一律排除
	if ( preg_match( '#^/(wp-admin|wp-login|wp-json|wp-content|wp-includes|xmlrpc\.php|cart|checkout|order|orders|member|account|signup|feed|comments)(/|$)#i', $path ) ) {
		return '';
	}
	if ( preg_match( '#\.(xml|txt|ico|json|php|css|js|jpg|jpeg|png|gif|webp|svg|woff2?)$#i', $path ) ) {
		return '';
	}
	if ( false !== strpos( $path, '?' ) || false !== strpos( $path, '#' ) ) {
		return '';
	}

	// 首页风格会改变 HTML → 纳入键
	$style = (string) get_theme_mod( 'as_home_style', '' );

	return sha1( $path . '|' . as_page_cache_ver() . '|' . $style );
}

/**
 * 缓存文件路径。
 */
function as_page_cache_file( $key ) {
	if ( '' === $key ) {
		return '';
	}
	return as_page_cache_dir() . '/' . $key . '.html';
}

/**
 * 确保缓存目录存在且可写。
 */
function as_page_cache_prepare_dir() {
	$dir = as_page_cache_dir();
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
		// 目录禁止直接访问（缓存文件不是公开资源）
		$ht = "Deny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n";
		@file_put_contents( $dir . '/.htaccess', $ht );
	}
	return is_dir( $dir ) && is_writable( $dir );
}

/* ── 读：init 阶段（早于 WP_Query）────────────────────────────────────── */

function as_page_cache_maybe_serve() {
	$key = as_page_cache_key();
	if ( '' === $key ) {
		return;
	}
	$file = as_page_cache_file( $key );
	if ( ! $file || ! file_exists( $file ) ) {
		return;
	}
	// 过期即视为没有
	$age = time() - (int) filemtime( $file );
	if ( $age > 600 ) {
		return;
	}
	$html = file_get_contents( $file );
	if ( false === $html || '' === trim( $html ) ) {
		return;
	}

	if ( ! headers_sent() ) {
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'X-AS-Page-Cache: HIT' );
	}
	echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- 缓存的是已渲染页面
	exit;
}
add_action( 'init', 'as_page_cache_maybe_serve', 1 );

/* ── 写：模板渲染结束后把完整 HTML 存下来 ────────────────────────────── */

function as_page_cache_start_buffer() {
	$key = as_page_cache_key();
	if ( '' === $key ) {
		return;
	}
	if ( ! as_page_cache_prepare_dir() ) {
		return;
	}
	$GLOBALS['as_page_cache_key']  = $key;
	$GLOBALS['as_page_cache_body'] = '';
	// 记住自己的缓冲层号（shutdown 时只收自己这层及之上被别人开的层）
	$GLOBALS['as_page_cache_ob_level'] = ob_get_level();
	ob_start();
}
add_action( 'template_redirect', 'as_page_cache_start_buffer', 99 );

function as_page_cache_save() {
	if ( empty( $GLOBALS['as_page_cache_key'] ) ) {
		return;
	}
	$key = $GLOBALS['as_page_cache_key'];
	$GLOBALS['as_page_cache_key'] = '';

	/*
	 * 🔴 收集内容的方式：WordPress / 其它插件在 template_redirect 之后**还会再开
	 *    输出缓冲层**（实测 level ≠ 自己那层）→ 严格判断层级会直接放弃缓存。
	 *    这里把收到的各层按「内层在前」拼起来，再 **原样 echo 回去**：
	 *      · 拿到完整 HTML（与客户端收到的完全一致）
	 *      · 客户端不会收到空 body（v2.87.131 首版就是少了这一步 → 首次访问空白页）
	 */
	$body = '';
	while ( ob_get_level() > 0 ) {
		$chunk = ob_get_clean();
		if ( false === $chunk ) {
			break;
		}
		$body = $chunk . $body;
	}
	if ( '' !== $body ) {
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- 原样输出已渲染页面
	}

	// 只存 200 的正常页面（404 / 500 不存）
	$code = http_response_code();
	if ( 200 !== (int) $code ) {
		return;
	}
	// 明显不是完整页面（被重定向、空内容）不存
	if ( strlen( $body ) < 500 || strlen( $body ) > 4 * 1024 * 1024 ) {
		return;
	}
	// 🔴 内容必须是完整页面（含 </html>）才存 —— 防止把「空 body」写进缓存
	if ( false === strpos( $body, '</html>' ) ) {
		return;
	}

	$file = as_page_cache_file( $key );
	$tmp  = $file . '.tmp' . wp_rand( 1000, 9999 );
	if ( false === @file_put_contents( $tmp, $body ) ) {
		return;
	}
	@chmod( $tmp, 0644 );
	if ( ! @rename( $tmp, $file ) ) {
		@unlink( $tmp );
		return;
	}

	// 🔴 顺手清理过期文件：内容变更 → 版本号 +1 → 旧键全部变成垃圾文件，
	//    而旧文件不会自己消失。不清理的话磁盘会被垃圾慢慢吃满。
	//    用 10% 概率做，避免每次写缓存都扫目录。
	if ( 0 === mt_rand( 0, 9 ) ) {
		as_page_cache_gc();
	}
}

/**
 * 删除 1 小时前的缓存文件（1 小时前写的必然已过 TTL）。
 */
function as_page_cache_gc() {
	$dir = as_page_cache_dir();
	if ( ! is_dir( $dir ) ) {
		return 0;
	}
	$files = glob( $dir . '/*.html' );
	if ( ! is_array( $files ) ) {
		return 0;
	}
	$now = time();
	$n   = 0;
	foreach ( $files as $f ) {
		if ( ( $now - (int) filemtime( $f ) ) > 3600 ) {
			if ( @unlink( $f ) ) {
				$n++;
			}
		}
	}
	return $n;
}
add_action( 'shutdown', 'as_page_cache_save', 0 );

/* ── 失效钩子 ───────────────────────────────────────────────────────── */

foreach ( array( 'save_post', 'deleted_post', 'trashed_post', 'untrashed_post', 'edited_terms', 'customize_save_after' ) as $as_pc_hook ) {
	add_action(
		$as_pc_hook,
		function () {
			as_page_cache_flush();
		},
		20
	);
}
// 主题设置 / 优惠券等选项变化
add_action(
	'update_option_theme_mods_product-showcase-guide',
	function () {
		as_page_cache_flush();
	},
	20
);

function as_page_cache_clear_all() {
	$dir = as_page_cache_dir();
	if ( ! is_dir( $dir ) ) {
		return 0;
	}
	$n    = 0;
	$files = glob( $dir . '/*.html' );
	if ( is_array( $files ) ) {
		foreach ( $files as $f ) {
			if ( @unlink( $f ) ) {
				$n++;
			}
		}
	}
	as_page_cache_flush();
	return $n;
}

/* ── 后台：主题设置 → 页面缓存 ──────────────────────────────────────── */

add_action( 'admin_menu', 'as_page_cache_admin_menu', 20 );
function as_page_cache_admin_menu() {
	add_submenu_page(
		'as-site-seo',
		'页面缓存',
		'页面缓存',
		'manage_options',
		'as-page-cache',
		'as_page_cache_page'
	);
}

add_action( 'wp_ajax_as_page_cache_clear', 'as_page_cache_ajax_clear' );
function as_page_cache_ajax_clear() {
	check_ajax_referer( 'as_page_cache', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => '权限不足' ) );
	}
	$n = as_page_cache_clear_all();
	wp_send_json_success( array( 'cleared' => $n ) );
}

function as_page_cache_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$dir    = as_page_cache_dir();
	$files  = is_dir( $dir ) ? glob( $dir . '/*.html' ) : array();
	$count  = is_array( $files ) ? count( $files ) : 0;
	$size   = 0;
	if ( is_array( $files ) ) {
		foreach ( $files as $f ) {
			$size += (int) filesize( $f );
		}
	}
	$off    = defined( 'AS_PAGE_CACHE_OFF' ) && AS_PAGE_CACHE_OFF;
	$nonce  = wp_create_nonce( 'as_page_cache' );
	?>
	<div class="wrap" id="as-page-cache">
		<h1>页面缓存</h1>
		<p class="description">
			未登录访客的静态型页面（首页、商品页、文章页、归档页）会整页缓存，
			命中时直接输出文件、不再跑 WordPress —— 实测 TTFB 可省 100ms 以上。
			会员、已加购、带参数的 URL、交易页一律走实时渲染。
		</p>
		<?php if ( $off ) : ?>
			<div class="notice notice-warning"><p><strong>已停用</strong>：wp-config.php 里定义了 <code>AS_PAGE_CACHE_OFF</code>。</p></div>
		<?php endif; ?>
		<table class="widefat striped" style="max-width:900px;margin:16px 0;">
			<tbody>
				<tr><td style="width:200px;"><strong>缓存状态</strong></td>
					<td><?php echo $off ? '<span style="color:#b42318;">已停用</span>' : '<span style="color:#1a7f37;">启用中</span>'; ?>
						<div class="description">缓存有效期 10 分钟；内容变更时立即失效。</div></td></tr>
				<tr><td><strong>已缓存页面</strong></td>
					<td><strong><?php echo (int) $count; ?></strong> 个 · 共 <?php echo size_format( $size ); ?>
						<div class="description">目录：<code><?php echo esc_html( $dir ); ?></code>（已禁止直接访问）</div></td></tr>
				<tr><td><strong>内容版本号</strong></td>
					<td><?php echo (int) as_page_cache_ver(); ?>
						<div class="description">内容变更时 +1，旧缓存整体作废。</div></td></tr>
			</tbody>
		</table>
		<p>
			<button type="button" class="button button-primary" id="as-pc-clear">清空全部页面缓存</button>
			<span id="as-pc-msg" style="margin-left:10px;color:#1a7f37;"></span>
		</p>
	</div>
	<script>
	( function () {
		var msg = document.querySelector( '#as-pc-msg' );
		document.querySelector( '#as-pc-clear' ).addEventListener( 'click', function () {
			var fd = new FormData();
			fd.append( 'action', 'as_page_cache_clear' );
			fd.append( 'nonce', <?php echo wp_json_encode( $nonce ); ?> );
			fetch( window.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( j ) {
					msg.textContent = j.success ? ('已清空 ' + j.data.cleared + ' 个缓存页面') : '失败';
				} );
		} );
	} )();
	</script>
	<?php
}
