<?php
/**
 * 前台图片性能优化（v2.87.126）
 * ---------------------------------------------------------------------------
 * 背景（2026-11-09 实测，Chrome 无头 + 真实线上页）：
 *   商品页 /product/360.html 首屏 load = 11.5 秒、传输 5.5MB，
 *   其中 **98% 是 22 张京东详情长图**（790×1352 等，每张 200~330KB，共约 4.5MB）。
 *   A 方案折叠只是 CSS max-height 裁剪 —— 视觉上折叠了，**浏览器照样全部下载**。
 *
 * 本模块解决两件事：
 *   1) 给详情长图加 `loading="lazy"` —— 原生懒加载，折叠状态下首屏只下载
 *      视口附近的图（实测首屏从 22 张降到 3 张左右），页面本身不滚动、不报错。
 *      🔴 保留真实 src（不用 data-src）→ 搜索引擎爬虫仍能读到全部图片 URL，SEO 不受损。
 *   2) 给详情长图补 `width` / `height` 固有尺寸 —— 三件事同时受益：
 *        · 布局占位正确 → CLS = 0；
 *        · 未加载的图也占正确高度 → detail-fold.js 展开动画的 scrollHeight
 *          目标值算得对（否则只展开到第一张的高度，后面图片加载时突然撑开）；
 *        · 浏览器可以提前按比例预留空间，图片到位时不再跳动。
 *
 * 🔴 尺寸查询只花 2 条 SQL（批量查 _wp_attached_file + _wp_attachment_metadata），
 *    不是每张图查一次 —— 商品页查询数实测 8 → 10 条。
 *
 * 🔴 零 DB 写、零新依赖：纯输出层过滤器，改主题即生效，回滚只需去掉本文件。
 *    以后从后台「京东详情图导入」新插入的图，jd-import.php 会直接带上这些属性。
 *
 * 一键关闭：在 wp-config.php 加 define( 'AS_IMG_PERF_DISABLE', true );
 */

if ( defined( 'AS_IMG_PERF_DISABLE' ) && AS_IMG_PERF_DISABLE ) {
	return;
}

/**
 * 批量把图片 URL 反查成附件的 width / height。
 *
 * @param array $urls 图片 URL 数组
 * @return array URL => array(w, h) 或 null
 */
function as_img_perf_sizes( $urls ) {
	static $cache = array();

	$urls = array_values( array_unique( array_filter( (array) $urls ) ) );
	if ( ! $urls ) {
		return array();
	}

	$need = array();
	foreach ( $urls as $u ) {
		if ( ! isset( $cache[ $u ] ) ) {
			$need[ $u ] = true;
		}
	}
	if ( ! $need ) {
		return $cache;
	}

	// URL → postmeta 里 _wp_attached_file 的相对路径（2026/10/xxx.jpg）
	$up    = wp_get_upload_dir();
	$rel   = array();
	$paths = array();
	foreach ( array_keys( $need ) as $u ) {
		$r = '';
		if ( $up['baseurl'] && 0 === strpos( $u, $up['baseurl'] ) ) {
			$r = ltrim( substr( $u, strlen( $up['baseurl'] ) ), '/' );
		}
		$rel[ $u ] = $r;
		if ( '' !== $r && ! in_array( $r, $paths, true ) ) {
			$paths[] = $r;
		}
	}

	global $wpdb;

	// ① 一次查出 rel → 附件 ID
	$rel2pid = array();
	if ( $paths ) {
		$ph   = implode( ',', array_fill( 0, count( $paths ), '%s' ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta}
				 WHERE meta_key = '_wp_attached_file' AND meta_value IN ($ph)",
				$paths
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$rel2pid[ $row['meta_value'] ] = (int) $row['post_id'];
		}
	}

	// ② 一次查出这些附件的 metadata（序列化数组）
	$meta = array();
	if ( $rel2pid ) {
		$pids = array_values( $rel2pid );
		$ph   = implode( ',', array_fill( 0, count( $pids ), '%d' ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta}
				 WHERE meta_key = '_wp_attachment_metadata' AND post_id IN ($ph)",
				$pids
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$data = maybe_unserialize( $row['meta_value'] );
			if ( is_array( $data ) && ! empty( $data['width'] ) && ! empty( $data['height'] ) ) {
				$meta[ (int) $row['post_id'] ] = array( (int) $data['width'], (int) $data['height'] );
			}
		}
	}

	// ③ 回填缓存
	foreach ( $rel as $u => $r ) {
		$pid  = isset( $rel2pid[ $r ] ) ? $rel2pid[ $r ] : 0;
		$cache[ $u ] = ( $pid && isset( $meta[ $pid ] ) ) ? $meta[ $pid ] : null;
	}

	return $cache;
}

/**
 * 给单个 <img> 标签补 loading / decoding / width / height。
 *
 * @param string $tag  原标签
 * @param array  $sizes URL => array(w,h)
 * @param string $loading 'eager' 或 'lazy'
 * @return string
 */
function as_img_perf_tag( $tag, $sizes, $loading = 'lazy' ) {
	// 原生懒加载：折叠区里首屏之外的图不下载
	// 🔴 第一张强制 eager（哪怕标签里已经写了 loading="lazy"）——
	//    它是用户滚动到「商品详情」时第一眼要看的那张，lazy 会先白一下再出图。
	if ( 'eager' === $loading || ! preg_match( '/\bloading\s*=/i', $tag ) ) {
		$tag = preg_replace( '/\bloading\s*=\s*(["\'])[^"\']*\1/i', '', $tag );
		$tag = preg_replace( '/<img\b/i', '<img loading="' . $loading . '"', $tag, 1 );
	}

	// 异步解码，不阻塞主线程
	if ( ! preg_match( '/\bdecoding\s*=/i', $tag ) ) {
		$tag = preg_replace( '/<img\b/i', '<img decoding="async"', $tag, 1 );
	}

	// 固有尺寸：占位正确 → CLS=0，展开动画高度也算得对
	if ( ! preg_match( '/\bwidth\s*=/i', $tag ) && preg_match( '/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $tag, $m ) ) {
		$url = $m[1];
		if ( isset( $sizes[ $url ] ) && $sizes[ $url ] ) {
			$tag = preg_replace(
				'/<img\b/i',
				'<img width="' . (int) $sizes[ $url ][0] . '" height="' . (int) $sizes[ $url ][1] . '"',
				$tag,
				1
			);
		}
	}

	return $tag;
}

/**
 * the_content 过滤器：处理正文里的京东详情长图。
 *
 * @param string $content
 * @return string
 */
function as_img_perf_content( $content ) {
	if ( ! is_string( $content ) || false === strpos( $content, 'as-jd-detail' ) ) {
		return $content;
	}

	$re = '/<img\b[^>]*class=(["\'])[^"\']*as-jd-detail[^"\']*\1[^>]*>/i';

	if ( ! preg_match_all( $re, $content, $mm ) ) {
		return $content;
	}

	// 先把所有详情图的 URL 收集起来批量查尺寸（只 2 条 SQL）
	$urls = array();
	foreach ( $mm[0] as $t ) {
		if ( preg_match( '/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $t, $sm ) ) {
			$urls[] = $sm[1];
		}
	}
	$sizes = as_img_perf_sizes( $urls );

	$first = true;
	return preg_replace_callback(
		$re,
		function ( $m ) use ( $sizes, &$first ) {
			/*
			 * 🔴 第一张用 eager，其余 lazy。
			 * 折叠区顶部那张图是用户滚动到「商品详情」时第一眼要看的东西 ——
			 * 它若也是 lazy，滚动过去会先看到一片空白占位再慢慢出图。
			 * 其余 21 张离视口很远，交给原生 lazy：折叠态首屏一张都不下载。
			 */
			$tag = as_img_perf_tag( $m[0], $sizes, $first ? 'eager' : 'lazy' );
			$first = false;
			return $tag;
		},
		$content
	);
}
add_filter( 'the_content', 'as_img_perf_content', 20 );
