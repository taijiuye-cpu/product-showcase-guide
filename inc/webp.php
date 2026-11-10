<?php
/**
 * 上传时自动生成 WebP 副本（v2.87.128 全站 WebP）
 * ---------------------------------------------------------------------------
 * 背景：站点的图片由 Apache 做**服务端内容协商**（浏览器发 Accept: image/webp
 *      就给 WebP，不发就给原 JPEG）—— 前端零改动，全站自动受益。
 *      但协商只对**已经存在 .webp 副本**的图生效，所以新上传的图必须补上副本，
 *      否则新图一直走 JPEG。这个文件负责这件事。
 *
 * 🔴 只生成副本，**不碰原图、不碰数据库**：
 *   · 删掉所有 .webp 文件 → 协商条件 `-f` 不成立 → 自动全部退回 JPEG
 *   · 关闭开关（wp-config 加 define( 'AS_WEBP_OFF', true )）→ 本文件整个不生效
 *
 * 质量档：
 *   · 详情长图（带 _as_jd_detail 标记）→ q85（文字海报，q80 起小字发虚）
 *   · 其它 → q80（800×800 主图 48KB → 10KB）
 *   · PNG → 不转（logo / 透明图，体积本就小，WebP 透明边缘画质更差）
 */

if ( defined( 'AS_WEBP_OFF' ) && AS_WEBP_OFF ) {
	return;
}

/**
 * 单张图转 WebP。
 *
 * @param string $path  绝对路径
 * @param int    $q     质量
 * @return bool
 */
function as_webp_convert( $path, $q = 80 ) {
	if ( ! function_exists( 'imagewebp' ) ) {
		return false;
	}
	$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	if ( 'jpg' !== $ext && 'jpeg' !== $ext ) {
		return false;
	}
	// 🔴 副本命名 = **原文件名 + .webp**（xxx.jpg → xxx.jpg.webp）：
	//    这样 Apache 协商只要 "%{REQUEST_FILENAME}.webp -f" 一条条件即可，
	//    不用在 RewriteCond 里拼路径（REQUEST_URI 是百分号编码、
	//    REQUEST_FILENAME 在 per-server 阶段又还没解析成文件系统路径，
	//    两种写法都踩过坑）。中文文件名也因此能正常工作。
	$webp = $path . '.webp';
	if ( file_exists( $webp ) ) {
		return true;
	}

	$im = @imagecreatefromjpeg( $path );
	if ( ! $im ) {
		return false;
	}
	$ok = @imagewebp( $im, $webp, $q );
	imagedestroy( $im );

	if ( ! $ok || ! file_exists( $webp ) ) {
		@unlink( $webp );
		return false;
	}
	return true;
}

/**
 * WP 生成完缩略图后 → 给原图与各尺寸都补一份 .webp 副本。
 *
 * @param array $metadata      附件元数据
 * @param int   $attachment_id 附件 ID
 * @return array
 */
function as_webp_generate_attachment( $metadata, $attachment_id ) {
	if ( ! function_exists( 'imagewebp' ) ) {
		return $metadata;
	}

	$file = get_attached_file( $attachment_id );
	if ( ! $file || ! file_exists( $file ) ) {
		return $metadata;
	}

	// 详情长图 → q85，其余 q80
	$is_detail = (bool) get_post_meta( $attachment_id, '_as_jd_detail', true );
	$q         = $is_detail ? 85 : 80;

	// 原图
	as_webp_convert( $file, $q );

	// 各尺寸缩略图
	if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
		foreach ( $metadata['sizes'] as $size => $data ) {
			if ( empty( $data['file'] ) ) {
				continue;
			}
			$path = dirname( $file ) . '/' . $data['file'];
			if ( file_exists( $path ) ) {
				as_webp_convert( $path, $q );
			}
		}
	}

	return $metadata;
}
add_filter( 'wp_generate_attachment_metadata', 'as_webp_generate_attachment', 20, 2 );

/**
 * 上传时同步生成「原尺寸 + 各注册尺寸」的 WebP。
 *
 * WP 默认只会生成缩略图里注册过的尺寸；商品图集用的是原图，
 * 所以这里把原图也补上（上面的 metadata 钩子已经覆盖，这里只做兜底）。
 */
function as_webp_on_upload( $id ) {
	if ( defined( 'AS_WEBP_OFF' ) && AS_WEBP_OFF ) {
		return;
	}
	$mime = get_post_mime_type( $id );
	if ( 'image/jpeg' !== $mime && 'image/jpg' !== $mime ) {
		return;
	}
	$file = get_attached_file( $id );
	if ( $file && file_exists( $file ) ) {
		$is_detail = (bool) get_post_meta( $id, '_as_jd_detail', true );
		as_webp_convert( $file, $is_detail ? 85 : 80 );
	}
}
add_action( 'add_attachment', 'as_webp_on_upload' );

/* ══════════════════════════════════════════════════════════════════════════
 * v2.87.129 —— 通用化
 * --------------------------------------------------------------------------
 * 目标：让 WebP 能力**随主题/目录迁移**，不依赖服务器 vhost 手工配置。
 *
 *  ① A：协商规则放进 wp-content/uploads/.htaccess
 *     · 实测 per-dir rewrite 在 uploads 目录里独立生效（vhost 段注释掉后仍返回 webp）
 *     · 前台 84 张图全在 uploads 内，主题目录无图片 → 覆盖完整
 *     · 换服务器 / 迁移站点时，uploads 目录跟着走 → 规则自动跟随，不用改 vhost
 *  ② B：主题自动维护该文件（幂等，只动自己那段标记块）
 *  ③ C：后台「WebP 图片」页 → 环境自检 + 副本覆盖率 + 一键补齐
 *
 * 🔴 写入方式：先写同目录临时文件再 rename —— 只需要**目录**写权限，
 *    不需要对 .htaccess 文件本身有写权限（服务器上现有 .htaccess 是 root 创建的，
 *    而 PHP-FPM 以 www 用户运行，直接 file_put_contents 会失败）。
 * ═══════════════════════════════════════════════════════════════════════ */

/**
 * uploads/.htaccess 里的协商规则块（含标记，便于幂等替换）。
 *
 * @return string
 */
function as_webp_htaccess_block() {
	return <<<'RULE'
# BEGIN AS-WEBP
# 服务端内容协商：浏览器发 Accept: image/webp → 返回 .webp 副本；不发 → 返回原图。
# 副本命名 = 原文件名 + .webp（xxx.jpg → xxx.jpg.webp），所以只测 -f 就行。
# 🔴 本段由主题自动维护（主题设置 → WebP 图片），手动改动会被覆盖。
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{HTTP_ACCEPT} image/webp
    RewriteCond expr "-f '%{REQUEST_FILENAME}.webp' || -f '%{DOCUMENT_ROOT}%{REQUEST_URI}.webp'"
    RewriteRule ^(.+)\.(jpe?g|png)$ $1.$2.webp [T=image/webp,L]
</IfModule>
<IfModule mod_headers.c>
    <FilesMatch "\.(jpe?g|png|webp)$">
        Header append Vary Accept
    </FilesMatch>
</IfModule>
AddType image/webp .webp
# END AS-WEBP
RULE;
}

/**
 * uploads/.htaccess 的绝对路径。
 *
 * @return string
 */
function as_webp_htaccess_path() {
	$up = wp_get_upload_dir();
	if ( empty( $up['basedir'] ) ) {
		return '';
	}
	return rtrim( $up['basedir'], '/\\' ) . '/.htaccess';
}

/**
 * 协商规则状态。
 *
 * @return array{path:string,exists:bool,has_rule:bool,writable:bool,dir_writable:bool}
 */
function as_webp_htaccess_status() {
	$path = as_webp_htaccess_path();
	$out  = array(
		'path'         => $path,
		'exists'       => false,
		'has_rule'     => false,
		'writable'     => false,
		'dir_writable' => false,
	);

	if ( '' === $path ) {
		return $out;
	}
	$out['exists']   = file_exists( $path );
	$out['writable'] = $out['exists'] ? is_writable( $path ) : is_writable( dirname( $path ) );
	$dir             = dirname( $path );
	$out['dir_writable'] = is_dir( $dir ) && is_writable( $dir );

	if ( $out['exists'] ) {
		$content = (string) file_get_contents( $path );
		$out['has_rule'] = ( false !== strpos( $content, '# BEGIN AS-WEBP' ) )
			&& ( false !== strpos( $content, '# END AS-WEBP' ) );
	}
	return $out;
}

/**
 * 幂等写入协商规则（只替换标记块内的内容，其它内容原样保留）。
 *
 * 🔴 两个必须注意的坑：
 *   1) **不能用 preg_replace 做替换** —— 规则块里有 `$1.$2`（rewrite 反向引用），
 *      preg_replace 会把它们当成自己的捕获组 → 写成 `..webp` → 图片全部 404。
 *      必须用 preg_replace_callback（回调返回的字符串不做反向引用替换）。
 *   2) 旧版本写过的规则块（没有 BEGIN/END 标记）也要一并清掉，
 *      否则会出现两段规则并存、旧的那段抢先命中。
 *
 * @return array{ok:bool,message:string}
 */
function as_webp_write_htaccess() {
	$path = as_webp_htaccess_path();
	if ( '' === $path ) {
		return array( 'ok' => false, 'message' => '取不到上传目录路径' );
	}
	$dir = dirname( $path );
	if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
		return array( 'ok' => false, 'message' => '上传目录不可写：' . $dir );
	}

	$old   = file_exists( $path ) ? (string) file_get_contents( $path ) : '';
	$block = as_webp_htaccess_block();

	// 清掉所有旧格式的协商段（含带标记的、以及 v2.87.128 手工写的那版）
	$patterns = array(
		'/# BEGIN AS-WEBP.*?# END AS-WEBP\s*/s',
		'/(?:#[^\n]*AS-WEBP[^\n]*\n)(?:[ \t]*<IfModule[^>]*>.*?<\/IfModule>[ \t]*\n)?(?:[ \t]*AddType image\/webp[^\n]*\n)?/s',
	);
	foreach ( $patterns as $pat ) {
		$old = preg_replace( $pat, '', $old );
	}
	$old = preg_replace( "/\n{3,}/", "\n\n", $old );

	$new = ( '' !== trim( $old ) ? rtrim( $old, "\n" ) . "\n\n" : '' ) . $block . "\n";

	// 🔴 先写临时文件再 rename：只需目录写权限
	$tmp = $path . '.tmp-' . wp_rand( 1000, 9999 );
	$put = file_put_contents( $tmp, $new );
	if ( false === $put || $put < 1 ) {
		@unlink( $tmp );
		return array( 'ok' => false, 'message' => '写入临时文件失败' );
	}
	@chmod( $tmp, 0644 );
	if ( ! @rename( $tmp, $path ) ) {
		@unlink( $tmp );
		return array( 'ok' => false, 'message' => '替换 .htaccess 失败（目录权限或文件被占用）' );
	}

	// 🔴 写完自检：规则必须是正确的 $1.$2 形式（历史上被 preg_replace 吃过）
	$check = (string) file_get_contents( $path );
	if ( false === strpos( $check, '$1.$2.webp' ) ) {
		return array( 'ok' => false, 'message' => '写入后自检失败：规则里的 $1.$2 不完整' );
	}

	return array( 'ok' => true, 'message' => '协商规则已写入：' . $path );
}

/**
 * 自动维护：进入后台时检查一次，规则缺失/过期就补上。
 *
 * 🔴 只在后台跑（前台每次请求都读文件不划算）；规则丢失会在下一次进后台时自愈。
 */
function as_webp_maybe_fix_htaccess() {
	if ( defined( 'AS_WEBP_OFF' ) && AS_WEBP_OFF ) {
		return;
	}
	$st = as_webp_htaccess_status();
	if ( $st['has_rule'] ) {
		return;
	}
	as_webp_write_htaccess();
}
add_action( 'admin_init', 'as_webp_maybe_fix_htaccess' );

/**
 * 扫描 uploads 目录，统计 WebP 覆盖率与节省体积。
 *
 * @param bool $fresh 是否忽略缓存重新扫描
 * @return array
 */
function as_webp_stats( $fresh = false ) {
	$key = 'as_webp_stats';
	if ( ! $fresh ) {
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$up = wp_get_upload_dir();
	$out = array(
		'total'      => 0,
		'converted'  => 0,
		'missing'    => 0,
		'src_bytes'  => 0,
		'webp_bytes' => 0,
		'saved_bytes'=> 0,
		'sample'     => '',
		'gd'         => function_exists( 'imagewebp' ),
		'rw'         => function_exists( 'apache_get_modules' )
			&& in_array( 'rewrite_module', apache_get_modules(), true ),
	);
	if ( empty( $up['basedir'] ) || ! is_dir( $up['basedir'] ) ) {
		return $out;
	}

	$base = rtrim( $up['basedir'], '/\\' );
	$it   = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $it as $f ) {
		if ( ! $f->isFile() ) {
			continue;
		}
		$ext = strtolower( $f->getExtension() );
		if ( 'jpg' !== $ext && 'jpeg' !== $ext ) {
			continue;
		}
		$out['total']++;
		$out['src_bytes'] += (int) $f->getSize();
		$wp = $f->getPathname() . '.webp';
		if ( file_exists( $wp ) ) {
			$out['converted']++;
			$out['webp_bytes'] += (int) filesize( $wp );
			if ( '' === $out['sample'] ) {
				$out['sample'] = substr( $f->getPathname(), strlen( $base ) + 1 );
			}
		} else {
			$out['missing']++;
		}
	}
	$out['saved_bytes'] = max( 0, $out['src_bytes'] - $out['webp_bytes'] );

	set_transient( $key, $out, 5 * MINUTE_IN_SECONDS );
	return $out;
}

/**
 * 找出还缺副本的 jpg（返回相对 uploads 的路径数组）。
 *
 * @return array
 */
function as_webp_missing_list() {
	$up = wp_get_upload_dir();
	if ( empty( $up['basedir'] ) || ! is_dir( $up['basedir'] ) ) {
		return array();
	}
	$base = rtrim( $up['basedir'], '/\\' );
	$it   = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS )
	);
	$list = array();
	foreach ( $it as $f ) {
		if ( ! $f->isFile() ) {
			continue;
		}
		$ext = strtolower( $f->getExtension() );
		if ( 'jpg' !== $ext && 'jpeg' !== $ext ) {
			continue;
		}
		if ( file_exists( $f->getPathname() . '.webp' ) ) {
			continue;
		}
		$list[] = substr( $f->getPathname(), strlen( $base ) + 1 );
	}
	sort( $list );
	return $list;
}

/**
 * 详情长图清单（带 _as_jd_detail 标记的附件）→ 这些用 q85。
 *
 * @return array 相对路径 => true
 */
function as_webp_detail_files() {
	global $wpdb;
	$out = array();
	$ids = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_as_jd_detail' LIMIT 3000" );
	if ( ! $ids ) {
		return $out;
	}
	$ph   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND post_id IN ($ph)",
			$ids
		),
		ARRAY_A
	);
	foreach ( $rows as $r ) {
		$out[ $r['meta_value'] ] = true;
	}
	return $out;
}

/**
 * 批量补齐一批副本（供 AJAX 分批调用）。
 *
 * @param int $limit 本批最多处理多少张
 * @return array
 */
function as_webp_convert_batch( $limit = 40 ) {
	$limit = max( 1, min( 200, (int) $limit ) );
	$up    = wp_get_upload_dir();
	$base  = rtrim( $up['basedir'], '/\\' );

	$key    = 'as_webp_missing';
	$miss   = get_transient( $key );
	if ( ! is_array( $miss ) ) {
		$miss = as_webp_missing_list();
		set_transient( $key, $miss, HOUR_IN_SECONDS );
	}
	$total = count( $miss );

	$detail = as_webp_detail_files();
	$done   = 0;
	$fail   = 0;
	$saved  = 0;

	while ( $done < $limit && ! empty( $miss ) ) {
		$rel = array_shift( $miss );
		$abs = $base . '/' . $rel;
		if ( ! file_exists( $abs ) ) {
			continue;
		}
		$q     = isset( $detail[ $rel ] ) ? 85 : 80;
		$before = (int) filesize( $abs );
		if ( as_webp_convert( $abs, $q ) ) {
			$after = file_exists( $abs . '.webp' ) ? (int) filesize( $abs . '.webp' ) : 0;
			$saved += max( 0, $before - $after );
			$done++;
		} else {
			$fail++;
		}
	}

	set_transient( $key, $miss, HOUR_IN_SECONDS );
	delete_transient( 'as_webp_stats' );

	return array(
		'done'      => $done,
		'failed'    => $fail,
		'saved'     => $saved,
		'remaining' => count( $miss ),
		'total'     => $total,
	);
}

/* ── 后台：主题设置 → WebP 图片 ─────────────────────────────────────── */

/*
 * 🔴 优先级 20：必须晚于 site-settings.php 的 admin_menu（默认 10）——
 *    父菜单 'as-site-seo' 先注册，本页才能挂成它的子菜单；
 *    否则父菜单还不存在 → add_submenu_page 会把它当顶级菜单。
 */
add_action( 'admin_menu', 'as_webp_admin_menu', 20 );
function as_webp_admin_menu() {
	add_submenu_page(
		'as-site-seo',
		'WebP 图片',
		'WebP 图片',
		'manage_options',
		'as-webp',
		'as_webp_page'
	);
}

add_action( 'wp_ajax_as_webp_convert', 'as_webp_ajax_convert' );
function as_webp_ajax_convert() {
	check_ajax_referer( 'as_webp', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => '权限不足' ) );
	}
	$limit = isset( $_POST['limit'] ) ? (int) $_POST['limit'] : 40;
	wp_send_json_success( as_webp_convert_batch( $limit ) );
}

add_action( 'wp_ajax_as_webp_write_htaccess', 'as_webp_ajax_write_htaccess' );
function as_webp_ajax_write_htaccess() {
	check_ajax_referer( 'as_webp', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => '权限不足' ) );
	}
	$res = as_webp_write_htaccess();
	if ( ! empty( $res['ok'] ) ) {
		delete_transient( 'as_webp_stats' );
	}
	wp_send_json_success( $res );
}

add_action( 'wp_ajax_as_webp_rescan', 'as_webp_ajax_rescan' );
function as_webp_ajax_rescan() {
	check_ajax_referer( 'as_webp', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => '权限不足' ) );
	}
	delete_transient( 'as_webp_missing' );
	delete_transient( 'as_webp_stats' );
	wp_send_json_success( as_webp_stats( true ) );
}

/**
 * 找一张已转换的图片（给后台页做浏览器实测探针用）。
 *
 * @return string 空串表示没找到
 */
function as_webp_sample_url() {
	$stats = as_webp_stats();
	if ( empty( $stats['sample'] ) ) {
		return '';
	}
	$up = wp_get_upload_dir();
	if ( empty( $up['baseurl'] ) ) {
		return '';
	}
	return $up['baseurl'] . '/' . str_replace( '\\', '/', $stats['sample'] );
}

/**
 * 后台页面。
 */
function as_webp_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$st    = as_webp_htaccess_status();
	$stats = as_webp_stats();
	$nonce = wp_create_nonce( 'as_webp' );
	$off   = defined( 'AS_WEBP_OFF' ) && AS_WEBP_OFF;
	$sample = as_webp_sample_url();

	$pct = $stats['total'] > 0 ? round( $stats['converted'] / $stats['total'] * 100, 1 ) : 0;
	?>
	<div class="wrap" id="as-webp-tools">
		<h1>WebP 图片</h1>
		<p class="description">
			服务端内容协商：浏览器支持 WebP 就给 WebP 副本，不支持就返回原图 —— 前台零改动，全站自动受益。
			本页可查看状态、补齐历史副本。
		</p>

		<?php if ( $off ) : ?>
			<div class="notice notice-warning"><p><strong>已停用</strong>：wp-config.php 里定义了 <code>AS_WEBP_OFF</code>，主题不生成副本、不写协商规则。</p></div>
		<?php endif; ?>

		<table class="widefat striped" style="max-width:900px;margin:16px 0;">
			<tbody>
				<tr>
					<td style="width:200px;"><strong>图片转换能力（GD）</strong></td>
					<td><?php echo $stats['gd'] ? '<span style="color:#1a7f37;">✔ 支持 imagewebp</span>' : '<span style="color:#b42318;">✘ 不支持（需装 GD 的 webp 支持）</span>'; ?>
						<div class="description">不支持时无法生成 WebP 副本，图片会照旧返回 JPEG（不影响站点可用）。</div>
					</td>
				</tr>
				<tr>
					<td><strong>Apache 协商规则</strong></td>
					<td>
						<?php if ( $st['has_rule'] ) : ?>
							<span style="color:#1a7f37;">✔ 已就位</span>
						<?php else : ?>
							<span style="color:#b42318;">✘ 未就位</span>
						<?php endif; ?>
						<div class="description">规则文件：<code><?php echo esc_html( $st['path'] ); ?></code><br>
						位置在 uploads 目录 → <strong>随目录迁移，换服务器不用改配置</strong>（需 Apache <code>AllowOverride All</code>）。</div>
					</td>
				</tr>
				<tr>
					<td><strong>副本覆盖率</strong></td>
					<td>
						<strong><?php echo (int) $stats['converted']; ?></strong> / <?php echo (int) $stats['total']; ?> 张（<?php echo $pct; ?>%）
						<?php if ( $stats['missing'] > 0 ) : ?>
							<span style="color:#b42318;">· 还缺 <?php echo (int) $stats['missing']; ?> 张</span>
						<?php else : ?>
							<span style="color:#1a7f37;">· 已全部补齐</span>
						<?php endif; ?>
						<div class="description">
							原图 <?php echo size_format( $stats['src_bytes'] ); ?> → WebP <?php echo size_format( $stats['webp_bytes'] ); ?>
							（省 <?php echo size_format( isset( $stats['saved_bytes'] ) ? $stats['saved_bytes'] : 0 ); ?>）
						</div>
					</td>
				</tr>
				<tr>
					<td><strong>浏览器实际收到的格式</strong></td>
					<td>
						<span id="as-webp-live">检测中…</span>
						<div class="description">前端实测：请求一张图看返回的 Content-Type（webp = 协商生效）。</div>
					</td>
				</tr>
			</tbody>
		</table>

		<p>
			<button type="button" class="button button-primary" id="as-webp-convert">一键补齐缺失副本</button>
			<button type="button" class="button" id="as-webp-write">修复协商规则</button>
			<button type="button" class="button" id="as-webp-rescan">重新扫描</button>
			<span id="as-webp-msg" style="margin-left:10px;color:#1a7f37;"></span>
		</p>
		<p class="description">
			补齐是分批后台执行的，页面可以关掉、稍后再点一次（已生成的会自动跳过）。<br>
			质量档：详情长图 q85（文字海报）· 其它 q80 · PNG 不转（logo/透明图）。
		</p>
	</div>
	<script>
	( function () {
		var $ = function ( s ) { return document.querySelector( s ); };
		var nonce = <?php echo wp_json_encode( $nonce ); ?>;
		var msg = $( '#as-webp-msg' );

		function post( action, data, cb ) {
			var fd = new FormData();
			fd.append( 'action', action );
			fd.append( 'nonce', nonce );
			Object.keys( data || {} ).forEach( function ( k ) { fd.append( k, data[ k ] ); } );
			fetch( window.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( j ) { cb( j ); } );
		}

		// 浏览器实际拿到的图片格式
		var sample = <?php echo wp_json_encode( $sample ); ?>;
		if ( sample ) {
			fetch( sample, { headers: { 'Accept': 'image/webp,image/*,*/*' }, cache: 'no-store' } )
				.then( function ( r ) { return r.blob(); } )
				.then( function ( b ) {
					$( '#as-webp-live' ).innerHTML = ( b.type === 'image/webp' )
						? '<span style="color:#1a7f37;">✔ ' + b.type + '（协商生效）</span>'
						: '<span style="color:#b42318;">' + b.type + '（协商未生效，图片仍是原格式）</span>';
				} )
				.catch( function () {
					$( '#as-webp-live' ).innerHTML = '<span style="color:#b42318;">检测失败</span>';
				} );
		} else {
			$( '#as-webp-live' ).innerHTML = '<span style="color:#b42318;">没找到已转换的图片，先点「一键补齐」</span>';
		}

		var btn = $( '#as-webp-convert' );
		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			msg.textContent = '开始补齐…';
			function step() {
				post( 'as_webp_convert', { limit: 40 }, function ( j ) {
					if ( ! j.success ) { msg.textContent = '失败：' + ( j.data && j.data.message ); btn.disabled = false; return; }
					var d = j.data;
					msg.textContent = '本次已转 ' + d.done + ' 张，还剩 ' + d.remaining + ' 张';
					if ( d.remaining > 0 ) { setTimeout( step, 200 ); }
					else { msg.textContent = '全部补齐完成 ✔'; btn.disabled = false; }
				} );
			}
			step();
		} );

		$( '#as-webp-write' ).addEventListener( 'click', function () {
			post( 'as_webp_write_htaccess', {}, function ( j ) {
				msg.textContent = j.success ? j.data.message : ( j.data && j.data.message );
			} );
		} );

		$( '#as-webp-rescan' ).addEventListener( 'click', function () {
			post( 'as_webp_rescan', {}, function ( j ) {
				msg.textContent = j.success ? '已重新扫描，请刷新页面查看最新数字' : '失败';
			} );
		} );
	} )();
	</script>
	<?php
}
