<?php
/**
 * 京东详情图导入（v2.87.107）
 * ---------------------------------------------------------------------------
 * 目的：在经典编辑器「添加媒体」旁边点一个按钮 → 粘贴京东商品链接 →
 *      只挑详情长图 → 下载保存到本站媒体库 → 插入正文编辑器。
 *
 * 设计约束（用户 2026-11-09 确认）：
 *   · 只要「详情图」，不抓主图 / SKU 图 / 图集，不改商品主图。
 *   · 图片必须落到自己的服务器（media_handle_sideload → /wp-content/uploads/），
 *     正文里绝不留京东外链。
 *   · 插入到商品详情正文（the_content），追加到末尾，不覆盖已有描述。
 *   · 正文里就是普通的 <img class="as-jd-detail">，作者可继续自由编辑。
 *   · 🔴 v2.87.107：附件一律「未附加」（post_parent = 0），绝不挂到商品下。
 *      主题的「商品图集」= get_attached_media('image', $post_id)，封面也会回退
 *      图集第一张；一旦附加，详情长图就会全跑进后台图集 / 前台轮播，
 *      甚至变成小程序列表封面。详情图只属于正文，不属于图集。
 *
 * 接口：d.3.cn/desc/<SKU>?cdn=2 是京东 PC 商品页自己调用的公开只读接口，
 *      无需 AppKey/签名/开放平台授权。老接口 cd.jd.com/description/channel 已失效。
 *      🔴 新版详情图写在 CSS background-image:url() 里，不是 <img> 标签，
 *      解析器必须同时吃 img 属性与 CSS url()，并按出现顺序保序。
 *      🔴 v2.87.106：14 位新 SKU / POP 第三方商品把详情描述挂在「主商品 ID」
 *      （页面里的 productId / realSpuId，形如 10023057637333）上，拿 SKU
 *      直接请求 d.3.cn 会返回 HTTP 200 但 0 字节。故抓取链路增加回退：
 *      SKU 无数据 → 抓移动端商品页读出主商品 ID → 换 ID 重取详情描述。
 *
 * 安全：nonce + edit_post 权限 + 图片域名白名单（防 SSRF）+ 超时。
 *      服务器没装 mbstring，本文件一律用 strpos / substr / preg_*。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 京东图片域名白名单（下载前必查，防止把任意 URL 塞给 download_url）
 */
function as_jd_allowed_image_host( $host ) {
	$host = strtolower( trim( (string) $host ) );
	if ( '' === $host ) {
		return false;
	}
	$suffixes = array( '360buyimg.com', 'jd.com', 'jdcloud.com' );
	foreach ( $suffixes as $suffix ) {
		if ( $host === $suffix ) {
			return true;
		}
		$dot = '.' . $suffix;
		if ( strlen( $host ) > strlen( $dot ) && substr( $host, - strlen( $dot ) ) === $dot ) {
			return true;
		}
	}
	return false;
}

/**
 * URL 归一化：协议相对 → https；实体解码；去掉首尾引号/空格
 */
function as_jd_normalize_url( $url ) {
	$url = trim( (string) $url );
	$url = html_entity_decode( $url, ENT_QUOTES, 'UTF-8' );
	$url = trim( $url, " \t\n\r\0\x0B\\\"'" );
	if ( 0 === strpos( $url, '//' ) ) {
		$url = 'https:' . $url;
	} elseif ( 0 === strpos( $url, 'http://' ) ) {
		$url = 'https://' . substr( $url, 7 );
	}
	if ( 0 !== strpos( $url, 'https://' ) ) {
		return '';
	}
	return $url;
}

/**
 * 从各种京东链接 / 纯 SKU 里提取商品编号
 */
function as_jd_extract_sku( $input ) {
	$input = trim( (string) $input );
	if ( '' === $input ) {
		return '';
	}
	if ( preg_match( '#item\.jd\.com/(\d{5,20})\.html#i', $input, $m ) ) {
		return $m[1];
	}
	if ( preg_match( '#item\.m\.jd\.com/product/(\d{5,20})#i', $input, $m ) ) {
		return $m[1];
	}
	if ( preg_match( '#[?&](?:skuId|sku|wareId)=(\d{5,20})#i', $input, $m ) ) {
		return $m[1];
	}
	if ( preg_match( '/^\d{5,20}$/', $input ) ) {
		return $input;
	}
	if ( preg_match( '/(\d{6,20})/', $input, $m ) ) {
		return $m[1];
	}
	return '';
}

/**
 * 服务端取远程内容（8 秒超时 + 浏览器 UA/Referer）
 */
function as_jd_remote_get( $url, $sku ) {
	$args = array(
		'timeout'     => 8,
		'redirection' => 3,
		'headers'     => array(
			'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
			'Referer'    => 'https://item.jd.com/' . $sku . '.html',
			'Accept'     => '*/*',
		),
	);
	$response = wp_remote_get( $url, $args );
	if ( is_wp_error( $response ) ) {
		return '';
	}
	if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return '';
	}
	return (string) wp_remote_retrieve_body( $response );
}

/**
 * 从详情 HTML 里按顺序提取图片 URL。
 *
 * 同时兼容两种写法：
 *   1) <img src|data-src|data-lazyload|data-original|data-ks-lazyload="...">
 *   2) CSS background-image: url(...)   ← 京东新版详情图主要藏在这里
 *
 * 用 PREG_OFFSET_CAPTURE 拿出现位置，按位置排序 → 保序；URL 做 key → 去重。
 */
function as_jd_parse_images_from_html( $html ) {
	$found  = array();
	$pattern = '/(?:<img\b[^>]*?\b(?:data-lazyload|data-ks-lazyload|data-original|data-src|src)\s*=\s*["\']([^"\']+)["\']|url\(\s*["\']?([^)"\']+)["\']?\s*\))/i';
	if ( ! preg_match_all( $pattern, $html, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
		return array();
	}
	foreach ( $matches as $m ) {
		$raw    = ( isset( $m[1] ) && '' !== $m[1][0] ) ? $m[1][0] : ( isset( $m[2] ) ? $m[2][0] : '' );
		$offset = ( isset( $m[1] ) && '' !== $m[1][0] ) ? $m[1][1] : ( isset( $m[2] ) ? $m[2][1] : 0 );
		if ( '' === $raw ) {
			continue;
		}
		$url = as_jd_normalize_url( $raw );
		if ( '' === $url ) {
			continue;
		}
		if ( ! as_jd_allowed_image_host( parse_url( $url, PHP_URL_HOST ) ) ) {
			continue;
		}
		$path = (string) parse_url( $url, PHP_URL_PATH );
		if ( strlen( $path ) < 12 ) {
			continue;
		}
		// 排除图标 / 二维码 / 占位图等非详情内容，以及非位图格式
		if ( preg_match( '/(logo|icon|qrcode|erweima|loading|placeholder|blank|sprite)/i', $path ) ) {
			continue;
		}
		if ( preg_match( '/\.(gif|svg|ico|js|css)(\?|$)/i', $path ) ) {
			continue;
		}
		if ( ! array_key_exists( $url, $found ) ) {
			$found[ $url ] = $offset;
		}
	}
	asort( $found, SORT_NUMERIC );
	return array_keys( $found );
}

/**
 * 取某个 ID 的京东详情描述，解出 content HTML（拿不到返回空串）。
 *
 * 注意：这里的 $id 不一定是 SKU —— 新版 POP / 14 位 SKU 商品的详情描述
 * 挂在「主商品 ID」上，用 SKU 请求会返回 HTTP 200 但 0 字节。
 * $sku 只用于拼 Referer。
 */
function as_jd_desc_content( $id, $sku ) {
	$body = as_jd_remote_get( 'https://d.3.cn/desc/' . rawurlencode( $id ) . '?cdn=2', $sku );
	if ( '' === $body ) {
		return '';
	}
	// 返回形如 showdesc({"date":...,"content":"<style>..."});
	if ( preg_match( '/^\s*showdesc\s*\((.*)\)\s*;?\s*$/s', $body, $m ) ) {
		$data = json_decode( $m[1], true );
		if ( is_array( $data ) && isset( $data['content'] ) ) {
			return (string) $data['content'];
		}
	}
	$data = json_decode( $body, true );
	if ( is_array( $data ) && isset( $data['content'] ) ) {
		return (string) $data['content'];
	}
	return $body; // 直接返回 HTML 的情况
}

/**
 * 从移动端商品页（SSR，含 _itemOnly 大 JSON）里读出可用于详情描述的
 * 候选主商品 ID。按字段可信度排序，排除 SKU 自身，最多 3 个，控制请求数。
 */
function as_jd_desc_id_candidates( $html, $sku ) {
	$ids = array();
	foreach ( array( 'productId', 'realSpuId', 'mainSkuId', 'mainSpuId', 'spuId' ) as $key ) {
		if ( preg_match_all( '/"' . $key . '"\s*:\s*"?(\d{8,20})"?/', $html, $m ) ) {
			foreach ( $m[1] as $id ) {
				if ( $id !== (string) $sku && ! in_array( $id, $ids, true ) ) {
					$ids[] = $id;
				}
			}
		}
	}
	return array_slice( $ids, 0, 3 );
}

/**
 * 抓某个 SKU 的详情图。三级链路：
 *   ① SKU 直接取详情描述（老 SKU / 自营商品，绝大多数走这里）
 *   ② 无数据 → 抓移动端商品页读出主商品 ID，换 ID 重取（v2.87.106）
 *   ③ 仍无 → 直接从移动端页挑图兜底（可能含主图，UI 里让用户勾）
 */
function as_jd_fetch_images( $sku ) {
	$images = array();
	$source = '';
	$mobile = '';

	// ① 常规路径
	$content = as_jd_desc_content( $sku, $sku );
	if ( '' !== $content ) {
		$images = as_jd_parse_images_from_html( $content );
		if ( $images ) {
			$source = '京东详情描述接口';
		}
	}

	// ② 主商品 ID 回退（14 位新 SKU / POP 商品）
	if ( ! $images ) {
		$mobile = as_jd_remote_get( 'https://item.m.jd.com/product/' . rawurlencode( $sku ) . '.html', $sku );
		if ( '' !== $mobile ) {
			foreach ( as_jd_desc_id_candidates( $mobile, $sku ) as $id ) {
				$content = as_jd_desc_content( $id, $sku );
				if ( '' === $content ) {
					continue;
				}
				$try = as_jd_parse_images_from_html( $content );
				if ( $try ) {
					$images = $try;
					$source = '京东详情描述接口（主商品 ' . $id . '）';
					break;
				}
			}
		}
	}

	// ③ 兜底：移动端页里直接挑图
	if ( ! $images && '' !== $mobile ) {
		$images = as_jd_parse_images_from_html( $mobile );
		if ( $images ) {
			$source = '移动端商品页（兜底，可能含主图，请勾选）';
		}
	}

	if ( count( $images ) > 40 ) {
		$images = array_slice( $images, 0, 40 );
	}
	return array(
		'images' => $images,
		'source' => $source,
	);
}

/* ==========================================================================
 * AJAX ① 只读解析：返回图片清单，不写任何数据
 * ======================================================================== */
function as_jd_ajax_fetch() {
	if ( ! check_ajax_referer( 'as_jd_import', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => '安全校验失败，请刷新编辑页重试。' ), 403 );
	}
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => '当前账号没有编辑权限。' ), 403 );
	}
	$sku = as_jd_extract_sku( isset( $_POST['url'] ) ? wp_unslash( $_POST['url'] ) : '' );
	if ( '' === $sku ) {
		wp_send_json_error( array( 'message' => '没识别出商品编号，请粘贴 item.jd.com/xxxxx.html 这样的链接，或直接填 SKU。' ) );
	}
	$result = as_jd_fetch_images( $sku );
	if ( ! $result['images'] ) {
		wp_send_json_error( array( 'message' => '没有抓到详情图。京东可能临时加了风控，或该商品暂无图文详情，可先用「添加媒体」手动上传。' ) );
	}
	$list = array();
	foreach ( $result['images'] as $i => $url ) {
		$list[] = array(
			'url'   => esc_url_raw( $url ),
			'index' => $i + 1,
		);
	}
	wp_send_json_success( array(
		'sku'    => $sku,
		'source' => $result['source'],
		'images' => $list,
		'count'  => count( $list ),
	) );
}
add_action( 'wp_ajax_as_jd_fetch', 'as_jd_ajax_fetch' );

/* ==========================================================================
 * AJAX ② 入库：只下载用户勾选的图到本站媒体库（不挂到商品/图集），返回可插入的 HTML
 * ======================================================================== */
function as_jd_ajax_import() {
	if ( ! check_ajax_referer( 'as_jd_import', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => '安全校验失败，请刷新编辑页重试。' ), 403 );
	}
	$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
	if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( array( 'message' => '没有编辑这篇内容的权限。' ), 403 );
	}
	$raw_urls = ( isset( $_POST['urls'] ) && is_array( $_POST['urls'] ) ) ? $_POST['urls'] : array();
	if ( ! $raw_urls || count( $raw_urls ) > 40 ) {
		wp_send_json_error( array( 'message' => '请先勾选要导入的详情图（最多 40 张）。' ) );
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$html   = array();
	$ids    = array();
	$failed = 0;
	foreach ( $raw_urls as $i => $raw ) {
		$url  = as_jd_normalize_url( esc_url_raw( wp_unslash( $raw ) ) );
		$host = parse_url( $url, PHP_URL_HOST );
		if ( '' === $url || ! as_jd_allowed_image_host( $host ) ) {
			$failed++;
			continue;
		}
		$base = basename( (string) parse_url( $url, PHP_URL_PATH ) );
		if ( ! preg_match( '/\.(jpe?g|png|webp)$/i', $base ) ) {
			$base = 'jd-detail-' . ( $i + 1 ) . '.jpg';
		}
		$base = sanitize_file_name( $base );

		$tmp = download_url( $url, 20 );
		if ( is_wp_error( $tmp ) ) {
			$failed++;
			continue;
		}
		// 🔴 第二个参数必须是 0 —— 绝不把附件挂到商品下。
		// 主题「商品图集」= get_attached_media('image', $post_id)，封面也会回退
		// 图集第一张；一旦附加，这些详情长图就会变成后台图集 / 前台轮播图。
		// 用户要的是「只放在详情正文」，所以附件保持「未附加」状态。
		$attachment_id = media_handle_sideload(
			array(
				'name'     => $base,
				'tmp_name' => $tmp,
			),
			0
		);
		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $tmp ) ) {
				@unlink( $tmp );
			}
			$failed++;
			continue;
		}
		// 兜底：个别 WP 版本 / 编辑器插件会给「从编辑页上传」的附件自动补
		// post_parent，这里再强制解绑一次；同时打标记，便于日后识别与清理。
		wp_update_post(
			array(
				'ID'          => (int) $attachment_id,
				'post_parent' => 0,
			)
		);
		update_post_meta( (int) $attachment_id, '_as_jd_detail', 1 );
		$src = wp_get_attachment_url( $attachment_id );
		if ( ! $src ) {
			$failed++;
			continue;
		}
		$ids[]  = (int) $attachment_id;
		/*
		 * v2.87.126：新插入的详情图直接带上 loading="lazy" 与固有尺寸。
		 *   · loading="lazy" —— 折叠态首屏只下载视口附近的图（商品页原来 5.5MB）；
		 *   · width/height —— 布局占位正确（CLS=0），detail-fold.js 展开动画的
		 *     目标高度也算得对（否则只展开到第一张的高度，后面图片加载时突然撑开）。
		 * 老正文里的图由 inc/img-perf.php 的 the_content 过滤器统一补齐。
		 */
		$dim = '';
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $meta ) && ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
			$dim = ' width="' . (int) $meta['width'] . '" height="' . (int) $meta['height'] . '"';
		}
		$html[] = '<img class="as-jd-detail" src="' . esc_url( $src ) . '" alt="' . esc_attr( '商品详情图 ' . ( $i + 1 ) ) . '"' . $dim . ' loading="lazy" decoding="async" />';
	}
	if ( ! $html ) {
		wp_send_json_error( array( 'message' => '所有图片都下载失败，请稍后重试，或改用「添加媒体」手动上传。' ) );
	}
	wp_send_json_success( array(
		'html'   => implode( "\n\n", $html ),
		'count'  => count( $html ),
		'failed' => $failed,
		'ids'    => $ids,
	) );
}
add_action( 'wp_ajax_as_jd_import', 'as_jd_ajax_import' );

/* ==========================================================================
 * 后台：编辑器按钮 + 资源
 * ======================================================================== */
function as_jd_render_media_button() {
	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->base, array( 'post', 'page' ), true ) ) {
		return;
	}
	echo '<button type="button" id="as-jd-import-btn" class="button as-jd-btn">';
	echo '<span class="dashicons dashicons-format-image"></span> 京东详情图';
	echo '</button>';
}
add_action( 'media_buttons', 'as_jd_render_media_button', 21 );

function as_jd_admin_assets( $hook ) {
	if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}
	wp_enqueue_style(
		'as-jd-admin',
		get_template_directory_uri() . '/assets/css/jd-import-admin.css',
		array(),
		as_asset_version( '/assets/css/jd-import-admin.css' )
	);
	wp_enqueue_script(
		'as-jd-import',
		get_template_directory_uri() . '/assets/js/jd-import.js',
		array( 'jquery' ),
		as_asset_version( '/assets/js/jd-import.js' ),
		true
	);
	wp_localize_script(
		'as-jd-import',
		'asJdCfg',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'as_jd_import' ),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'as_jd_admin_assets' );
