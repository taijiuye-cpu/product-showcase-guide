<?php
/**
 * 站点健康自查 & 安全清理
 * --------------------------------------------------------------------------
 * 目的：让「WordPress 后台打不开 / 变慢」这类问题在崩溃之前就能被看到。
 *
 * 全部是只读检测 + 两类安全清理：
 *   1) 过期 transient（WP 自己就本该删的东西，删了只有好处）
 *   2) 冗余的文章修订版本（每个编辑动作都会存一份，是 postmeta 膨胀大头）
 *
 * 刻意不做的事：不碰订单表、不碰任何业务数据 —— 清理工具只清「垃圾」，
 * 不碰「内容」。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ==========================================================================
 * 指标采集
 * ======================================================================== */

/**
 * 采集一组健康指标。全部只读，不做任何写操作。
 *
 * @return array
 */
function as_mini_health_metrics() {
	global $wpdb;

	$options   = $wpdb->prefix . 'options';
	$posts     = $wpdb->prefix . 'posts';
	$autoload  = "autoload IN ('yes','on','auto-on')";

	$m = array();

	/* ---------- 环境 ---------- */
	$m['wp_version']    = get_bloginfo( 'version' );
	$m['php_version']   = PHP_VERSION;
	$m['mysql_version'] = method_exists( $wpdb, 'db_version' ) ? $wpdb->db_version() : '';
	$m['wp_limit']      = defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '(未设置)';
	$m['wp_max_limit']  = defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : '(未设置)';
	$m['php_limit']     = ini_get( 'memory_limit' );
	$m['file_edit']     = ! ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT );
	$m['wp_cron_off']   = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	$m['object_cache']  = wp_using_ext_object_cache() ? '已启用（外部对象缓存）' : '未启用（用数据库）';

	/* ---------- autoload：拖慢每个请求的头号嫌疑 ---------- */
	$row = $wpdb->get_row( "SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(option_value)),0) AS bytes
		FROM {$options} WHERE {$autoload}", ARRAY_A );
	$m['autoload_n']   = (int) $row['n'];
	$m['autoload_kb']  = round( ( (int) $row['bytes'] ) / 1024, 1 );

	/* ---------- transient：不清理就会一直堆 ---------- */
	$m['transient_n'] = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$options} WHERE option_name LIKE '\\_transient\\_%'"
	);
	$m['transient_expired'] = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$options}
			 WHERE option_name LIKE '\\_transient\\_timeout\\_%' AND option_value < %d",
			time()
		)
	);

	/* ---------- 文章修订 ---------- */
	$m['revision_n'] = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$posts} WHERE post_type=%s", 'revision' )
	);

	/* ---------- 内容规模 ---------- */
	$m['product_n'] = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$posts} WHERE post_type=%s AND post_status=%s", 'plm_product', 'publish' )
	);
	$m['post_n'] = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$posts} WHERE post_type=%s AND post_status=%s", 'post', 'publish' )
	);
	$m['order_n'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}as_orders" );

	$users        = count_users();
	$m['user_n']  = isset( $users['total_users'] ) ? (int) $users['total_users'] : 0;

	$m['plugin_n'] = count( (array) get_option( 'active_plugins', array() ) );

	$cm = wp_count_comments();
	$m['comment_pending'] = isset( $cm->moderated ) ? (int) $cm->moderated : 0;
	$m['comment_spam']    = isset( $cm->spam ) ? (int) $cm->spam : 0;

	/* ---------- 磁盘 / 数据库体积 ---------- */
	$m['disk_free'] = null;
	$m['disk_total'] = null;
	if ( function_exists( 'disk_free_space' ) && function_exists( 'disk_total_space' ) ) {
		$free  = @disk_free_space( ABSPATH );
		$total = @disk_total_space( ABSPATH );
		if ( false !== $free && false !== $total && $total > 0 ) {
			$m['disk_free']  = round( $free / 1048576, 1 );   // MB
			$m['disk_total'] = round( $total / 1048576, 1 );  // MB
			$m['disk_free_pct'] = round( $free / $total * 100, 1 );
		}
	}

	$tables = $wpdb->get_results(
		"SELECT table_name AS t, ROUND((data_length + index_length)/1024/1024, 1) AS mb, table_rows AS n
		 FROM information_schema.TABLES
		 WHERE table_schema = DATABASE()
		 ORDER BY (data_length + index_length) DESC LIMIT 6",
		ARRAY_A
	);
	$m['db_tables'] = is_array( $tables ) ? $tables : array();
	$db_total = 0;
	foreach ( $m['db_tables'] as $t ) {
		$db_total += (float) $t['mb'];
	}
	$m['db_top6_mb'] = round( $db_total, 1 );

	/* ---------- 错误日志（排查白屏最有用） ---------- */
	$log = ini_get( 'error_log' );
	if ( $log && file_exists( $log ) ) {
		$m['error_log']      = $log;
		$m['error_log_size'] = round( filesize( $log ) / 1024, 1 ); // KB
	} else {
		$m['error_log']      = $log ? $log . '（文件还不存在）' : '(PHP 未设置 error_log)';
		$m['error_log_size'] = null;
	}

	/* ---------- wp-cron 挂起的任务数 ---------- */
	$cron_n = 0;
	if ( function_exists( '_get_cron_array' ) ) {
		foreach ( (array) _get_cron_array() as $hooks ) {
			$cron_n += count( $hooks );
		}
	}
	$m['cron_n'] = $cron_n;

	return $m;
}

/**
 * 把指标翻译成「一条条结论」：level = ok / warn / bad
 *
 * @param array $m as_mini_health_metrics() 的返回值
 * @return array [{level, title, detail, fix}]
 */
function as_mini_health_findings( $m ) {
	$out = array();

	/* autoload：官方建议控制在 800KB 以内 */
	if ( $m['autoload_kb'] > 1500 ) {
		$out[] = array(
			'level'  => 'bad',
			'title'  => 'wp_options 自动加载体积过大（' . $m['autoload_kb'] . ' KB / ' . $m['autoload_n'] . ' 条）',
			'detail' => '自动加载项会在每一次页面请求时全部读进内存，是后台变慢、甚至 500 的头号原因。健康值应低于 800 KB。',
			'fix'    => '常见元凶：插件把大量数据写进 autoload（如全站缓存、SEO 插件、_transient_ 开头的缓存行堆积）。可先点下面的「清理过期缓存」，仍偏高就要逐个停用插件排查。',
		);
	} elseif ( $m['autoload_kb'] > 800 ) {
		$out[] = array(
			'level'  => 'warn',
			'title'  => 'wp_options 自动加载体积偏高（' . $m['autoload_kb'] . ' KB）',
			'detail' => '还没到危险值（800 KB 以内算健康），但值得盯一下。',
			'fix'    => '先点「清理过期缓存」，再观察是否回落。',
		);
	} else {
		$out[] = array(
			'level'  => 'ok',
			'title'  => 'wp_options 自动加载体积正常（' . $m['autoload_kb'] . ' KB）',
			'detail' => '',
			'fix'    => '',
		);
	}

	/* 过期 transient 堆积 */
	if ( $m['transient_expired'] > 200 ) {
		$out[] = array(
			'level'  => 'warn',
			'title'  => '有过期缓存没被清掉（' . $m['transient_expired'] . ' 条）',
			'detail' => '过期不等于被删除。网站的定时任务如果被流量中断过，这些就会一直躺在库里。',
			'fix'    => '点下面的「清理过期缓存」即可，纯收益、无风险。',
		);
	}

	/* 文章修订 */
	if ( $m['revision_n'] > 300 ) {
		$out[] = array(
			'level'  => 'warn',
			'title'  => '文章修订版本较多（' . $m['revision_n'] . ' 条）',
			'detail' => '每次点「更新」文章都会存一份历史版本，是数据库膨胀和后台变卡的主要原因之一。',
			'fix'    => '点下面的「清理冗余修订」：每篇文章保留最近 5 个版本，够用了。',
		);
	}

	/* PHP 版本 */
	if ( version_compare( $m['php_version'], '8.0', '<' ) ) {
		$out[] = array(
			'level'  => 'bad',
			'title'  => 'PHP 版本偏低（' . $m['php_version'] . '）',
			'detail' => 'PHP 7.x 已经停止安全维护，且比 8.x 慢很多。',
			'fix'    => '宝塔：软件商店 → PHP → 装 8.1/8.2，装完在「网站 → 设置」里切换，然后刷新后台确认正常。',
		);
	}

	/* 内存 */
	if ( $m['wp_max_limit'] === '(未设置)' ) {
		$out[] = array(
			'level'  => 'warn',
			'title'  => '没有为后台单独设置内存上限',
			'detail' => '后台操作（批量编辑、媒体库、导入）比前台吃内存得多。',
			'fix'    => '在 wp-config.php 加：define( \'WP_MAX_MEMORY_LIMIT\', \'512M\');',
		);
	}

	/* 磁盘 */
	if ( isset( $m['disk_free_pct'] ) && $m['disk_free_pct'] < 15 ) {
		$out[] = array(
			'level'  => 'bad',
			'title'  => '磁盘剩余空间不足（剩 ' . $m['disk_free'] . ' MB / ' . $m['disk_free_pct'] . '%）',
			'detail' => '磁盘写满会让 WordPress 无法上传、无法写日志，后台直接报错。',
			'fix'    => '清理服务器上的旧备份 / 日志，或扩容磁盘。',
		);
	}

	/* 后台能改代码 —— 这是最容易「自己把后台改崩」的入口 */
	if ( $m['file_edit'] ) {
		$out[] = array(
			'level'  => 'warn',
			'title'  => '后台「外观 → 主题文件编辑器」还开着',
			'detail' => '在这个编辑器里保存一次写错的代码，网站就会立刻白屏、后台也进不去，只能连 FTP 修。',
			'fix'    => '强烈建议关掉：wp-config.php 加 define( \'DISALLOW_FILE_EDIT\', true);',
		);
	} else {
		$out[] = array(
			'level'  => 'ok',
			'title'  => '已关闭后台的代码文件编辑器',
			'detail' => '',
			'fix'    => '',
		);
	}

	/* wp-cron 挂在请求里 */
	if ( ! $m['wp_cron_off'] ) {
		$out[] = array(
			'level'  => 'warn',
			'title'  => 'wp-cron 跟随页面请求触发（当前挂起 ' . $m['cron_n'] . ' 个任务）',
			'detail' => '默认行为是「有人访问就顺便跑定时任务」。访问一多，每个请求都要等它，后台会明显变卡。',
			'fix'    => 'wp-config.php 加 define( \'DISABLE_WP_CRON\', true); 然后在宝塔「计划任务」里加一条每分钟访问 ' . home_url( '/wp-cron.php' ) . ' 的 URL 任务。',
		);
	}

	/* 垃圾评论 */
	if ( $m['comment_spam'] > 50 ) {
		$out[] = array(
			'level'  => 'warn',
			'title'  => '回收站里的垃圾评论较多（' . $m['comment_spam'] . ' 条）',
			'detail' => '',
			'fix'    => '后台 → 评论 → 垃圾 → 清空。',
		);
	}

	return $out;
}

/* ==========================================================================
 * 安全清理
 * ======================================================================== */

/**
 * 清理过期的 transient。
 * 直接调用 WP 核心的 delete_expired_transients()：它只删已过期的，
 * 不会碰到还在用的缓存，纯收益。
 *
 * @return int 删掉的条数（估算）
 */
function as_mini_health_clean_transients() {
	global $wpdb;
	$options = $wpdb->prefix . 'options';

	$before = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$options} WHERE option_name LIKE '\\_transient\\_%'"
	);

	if ( function_exists( 'delete_expired_transients' ) ) {
		delete_expired_transients( true ); // true = 忽略外部对象缓存，直接清库
	}

	$after = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$options} WHERE option_name LIKE '\\_transient\\_%'"
	);

	return max( 0, $before - $after );
}

/**
 * 清理冗余的文章修订版本（每个 post 保留最近 $keep 个）
 *
 * @param int $keep 每篇保留多少条修订
 * @return int 删掉的条数
 */
function as_mini_health_clean_revisions( $keep = 5 ) {
	global $wpdb;
	$posts = $wpdb->prefix . 'posts';
	$keep  = max( 0, (int) $keep );

	$parents = $wpdb->get_col(
		"SELECT DISTINCT post_parent FROM {$posts} WHERE post_type='revision' AND post_parent > 0"
	);
	if ( ! $parents ) {
		return 0;
	}

	$deleted = 0;
	foreach ( $parents as $pid ) {
		$revs = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$posts} WHERE post_type='revision' AND post_parent=%d ORDER BY post_date DESC",
				$pid
			)
		);
		foreach ( array_slice( $revs, $keep ) as $rid ) {
			if ( wp_delete_post_revision( (int) $rid ) ) {
				$deleted++;
			}
		}
	}
	return $deleted;
}
