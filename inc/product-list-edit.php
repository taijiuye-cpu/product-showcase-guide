<?php
/**
 * WP 后台「商城产品」列表 —— 行内直接编辑
 *
 * 让管理员在列表页就能改高频字段，不用点进编辑页：
 *   价格/销量：现价 dg_price、原价 dg_old_price、虚拟销量 dg_sales_virtual
 *   展示：     效果类型 dg_effect（产品属性，三选一，v2.60.0 起）
 *   运营勾选： 首页抢购 dg_flash、首页推荐 dg_hot（列表里直接勾选）
 *
 * ⚠️ 「排序 menu_order」列已在 v2.60.0 从列表移除：排序改在别处填。
 *    保存逻辑仍然保留（as_qe_fields 白名单里有），哪天要把列加回来直接用。
 * ⚠️ 「平台 dg_platform」属性已于 v2.87.49 **整体移除**（后台字段配置、行内编辑白名单、
 *    清洗分支、网页前台渲染、小程序接口与后台表单全部下线）——
 *    两站数据库里该 meta 均为 0 条记录，删掉不影响任何商品。
 * ⚠️ 「推荐指数 dg_rating」属性已于 v2.87.14 整体移除（后台表单、保存、
 *    接口、前台展示全部下线），白名单与清洗逻辑同步删除。
 *
 * 做法：给列表加列，列里直接放控件，改动后 AJAX 保存（wp_ajax_as_qe_product）。
 * 只在后台加载，且只在 plm_product 的列表页入队脚本。
 *
 * 与编辑页保持一致：
 *   dg_hot / dg_flash 存 '1' 或删除；空值一律 delete_post_meta（不留空 meta）。
 * 但这里多一层校验（列表里就是输入框，输错要拦住并回滚）。
 * ⚠️ menu_order 不是 meta，是 post 字段，保存走 wp_update_post()。
 */

defined( 'ABSPATH' ) || exit;

/**
 * 允许行内编辑的字段白名单（含 post 字段 menu_order）
 *
 * 说明：「排序 menu_order」列已从列表移除，但白名单保留 —— 字段本身仍然有效，
 * 保留在这里是为了哪天把列加回来时不用再动保存逻辑。
 */
function as_qe_fields() {
	return array(
		'dg_price',
		'dg_old_price',
		'dg_sales_virtual',
		'dg_hot',
		'dg_flash',
		'menu_order',
		'dg_effect',
	);
}

/** 金额（现价 / 原价）清洗：非负数，最多两位小数 */
function as_qe_clean_price( $raw, $label ) {
	if ( '' === $raw ) {
		return '';
	}
	if ( ! is_numeric( $raw ) ) {
		return new WP_Error( 'as_qe_num', $label . '要填数字' );
	}
	$f = (float) $raw;
	if ( $f < 0 ) {
		return new WP_Error( 'as_qe_num', $label . '不能是负数' );
	}
	$s = number_format( $f, 2, '.', '' );
	$s = rtrim( rtrim( $s, '0' ), '.' );
	return ( '' === $s ) ? '0' : $s;
}

/**
 * 清洗一个字段值。
 * 返回清洗后的字符串，或 WP_Error（校验不通过）。
 * 返回空串表示「删除这个 meta」（menu_order 例外，空串会归 0）。
 */
function as_qe_clean( $field, $raw ) {
	$raw = trim( (string) $raw );

	switch ( $field ) {
		case 'dg_price':
			return as_qe_clean_price( $raw, '现价' );

		case 'dg_old_price':
			return as_qe_clean_price( $raw, '原价' );

		case 'dg_sales_virtual':
			if ( '' === $raw ) {
				return '';
			}
			if ( ! is_numeric( $raw ) ) {
				return new WP_Error( 'as_qe_num', '虚拟销量要填数字' );
			}
			$n = (int) $raw;
			if ( $n < 0 ) {
				return new WP_Error( 'as_qe_num', '虚拟销量不能是负数' );
			}
			return (string) $n;

		case 'dg_hot':
		case 'dg_flash':
			return ( '' === $raw || '0' === $raw ) ? '' : '1';

		case 'menu_order':
			if ( '' === $raw ) {
				return '0';
			}
			if ( ! is_numeric( $raw ) ) {
				return new WP_Error( 'as_qe_num', '排序要填数字' );
			}
			return (string) max( 0, (int) $raw );

		// 效果类型：产品属性（meta），三选一，值与编辑页下拉一致
		case 'dg_effect':
			if ( '' === $raw ) {
				return '';
			}
			return in_array( $raw, as_effect_options(), true )
				? $raw
				: new WP_Error( 'as_qe_effect', '效果类型只能是温和 / 均衡 / 强效' );
	}

	return new WP_Error( 'as_qe_field', '不允许修改这个字段' );
}

/** 加列（插在标题后面） */
function as_qe_columns( $cols ) {
	$out  = array();
	$done = false;

	foreach ( $cols as $key => $label ) {
		$out[ $key ] = $label;
		if ( 'title' === $key ) {
			$out['as_price']    = '现价';
			$out['as_old']      = '原价';
			$out['as_sales']    = '虚拟销量';
			// 「效果类型」列（v2.60.0）：产品属性，三选一，列表里直接下拉改。
			$out['as_effect']   = '效果类型';
			// 首页运营勾选：决定商品是否进首页的「限时抢购」「热门推荐」区块。
			$out['as_flash']    = '首页抢购';
			$out['as_hot']      = '首页推荐';
			$done               = true;
		}
	}

	if ( ! $done ) {
		$out['as_price']  = '现价';
		$out['as_old']    = '原价';
		$out['as_sales']  = '虚拟销量';
		$out['as_effect'] = '效果类型';
		$out['as_flash']  = '首页抢购';
		$out['as_hot']    = '首页推荐';
	}

	return $out;
}
add_filter( 'manage_plm_product_posts_columns', 'as_qe_columns' );

/** 输出一个数字输入框 */
function as_qe_num_input( $pid, $field, $value, $extra = '' ) {
	printf(
		'<input type="number" step="0.01" min="0" class="as-qe-in as-qe-num" data-pid="%d" data-field="%s" data-prev="%s" value="%s" placeholder="—" %s>',
		(int) $pid,
		esc_attr( $field ),
		esc_attr( (string) $value ),
		esc_attr( (string) $value ),
		$extra // phpcs:ignore WordPress.Security.EscapeOutput
	);
}

/** 输出一个下拉选择框（$opts 是 [值 => 显示] 或 [值, 值, ...]） */
function as_qe_select( $pid, $field, $value, $opts, $label ) {
	printf( '<select class="as-qe-in" data-pid="%d" data-field="%s" data-prev="%s" aria-label="%s">', (int) $pid, esc_attr( $field ), esc_attr( (string) $value ), esc_attr( $label ) );
	printf( '<option value="">—</option>' );
	foreach ( $opts as $k => $v ) {
		if ( is_int( $k ) ) {
			$k = $v;
		}
		printf( '<option value="%s" %s>%s</option>', esc_attr( (string) $k ), selected( (string) $value, (string) $k, false ), esc_html( (string) $v ) );
	}
	echo '</select>';
}

/** 输出一个勾选框（存 '1' 或删除 meta） */
function as_qe_check( $pid, $field, $value, $label ) {
	printf(
		'<input type="checkbox" class="as-qe-in as-qe-check" data-pid="%d" data-field="%s" data-prev="%s" value="1"%s aria-label="%s">',
		(int) $pid,
		esc_attr( $field ),
		esc_attr( ( '' === $value || '0' === $value ) ? '' : '1' ),
		checked( '1', (string) $value, false ),
		esc_attr( $label )
	);
}

/** 渲染列内容（直接是输入控件） */
function as_qe_column( $col, $post_id ) {
	$meta = function ( $k ) use ( $post_id ) {
		return (string) get_post_meta( $post_id, $k, true );
	};

	switch ( $col ) {
		case 'as_price':
			as_qe_num_input( $post_id, 'dg_price', $meta( 'dg_price' ) );
			break;

		case 'as_old':
			as_qe_num_input( $post_id, 'dg_old_price', $meta( 'dg_old_price' ) );
			break;

		case 'as_sales':
			printf(
				'<input type="number" step="1" min="0" class="as-qe-in as-qe-num" data-pid="%d" data-field="dg_sales_virtual" data-prev="%s" value="%s" placeholder="0" aria-label="虚拟销量">',
				(int) $post_id,
				esc_attr( $meta( 'dg_sales_virtual' ) ),
				esc_attr( $meta( 'dg_sales_virtual' ) )
			);
			break;

		/*
		 * 「效果类型」列（v2.60.0）：产品属性 meta dg_effect，三选一。
		 * 这里可以放心用 as_qe_select()：候选值是纯字符串列表（温和/均衡/强效），
		 * 正好走它「简写列表数组」那条分支，value 与显示值都是同一个词。
		 * ⚠️ 反过来说：凡是「value 是 id、显示是名字」的下拉（比如以后按词条 id 存），
		 *    绝不能用它 —— PHP 会把「115」这种键转成整数，导致 value 被写成名字。
		 */
		case 'as_effect':
			as_qe_select( $post_id, 'dg_effect', $meta( 'dg_effect' ), as_effect_options(), '效果类型' );
			break;

		/*
		 * 首页运营勾选：首页「限时抢购」区块读 dg_flash、「热门推荐」区块读 dg_hot。
		 * 存 '1' 或删除 meta（不存在 = 不勾选）。
		 */
		case 'as_flash':
			as_qe_check( $post_id, 'dg_flash', $meta( 'dg_flash' ), '首页抢购' );
			break;

		case 'as_hot':
			as_qe_check( $post_id, 'dg_hot', $meta( 'dg_hot' ), '首页推荐' );
			break;
	}
}
add_action( 'manage_plm_product_posts_custom_column', 'as_qe_column', 10, 2 );

/** 列样式 + 反馈状态 */
function as_qe_styles() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'edit-plm_product' !== $screen->id ) {
		return;
	}
	?>
	<style>
	/* 表格总宽：保住标题列，超出部分横向滚动，别把标题挤成竖排。
	 * v2.84.4 去掉「首页抢购 / 首页推荐 / 完整版隐藏」三列后回到 1290。
	 * v2.87.67：只在桌面生效 —— 手机端 WP 只保留主列，1290px 会把标题列
	 * 撑到 1205px，整张表出现 900px 横向滚动（实测 docW 1308 / 390）。 */
	@media (min-width: 783px) {
		.wp-list-table { min-width: 1290px; }
	}

		/* 标题列：给足宽度，长标题正常换行，不再一个字一行 */
		.column-title { min-width: 250px; }
		.column-title img {
			float: left;
			width: 52px !important;
			height: 52px !important;
			object-fit: cover;
			border-radius: 6px;
			margin: 0 8px 4px 0;
		}

	/* 可编辑列：紧凑列宽 + 表头不换行 */
	.column-as_price, .column-as_old { width: 68px; }
	.column-as_sales { width: 84px; }
	.column-as_effect { width: 92px; }
	.column-as_vip_mode { width: 88px; }
	.column-as_vip_rate { width: 68px; }
	th.column-as_price, th.column-as_old, th.column-as_sales,
	th.column-as_effect,
	th.column-as_vip_mode, th.column-as_vip_rate { white-space: nowrap; }

		/* 控件 */
		.as-qe-in { width: 100%; box-sizing: border-box; margin: 0; }
		.as-qe-num { text-align: right; }
		.as-qe-check { width: auto; }
		.as-qe-in.as-qe-saving { opacity: .55; }
		.as-qe-in.as-qe-ok { border-color: #00a32a !important; box-shadow: 0 0 0 1px #00a32a !important; }
		.as-qe-in.as-qe-err { border-color: #d63638 !important; box-shadow: 0 0 0 1px #d63638 !important; }
		.as-qe-in[disabled] { background: #f0f0f1; color: #8c8f94; }
	</style>
	<?php
}
add_action( 'admin_head-edit.php', 'as_qe_styles' );

/** 只在商品列表页加载脚本 */
function as_qe_assets( $hook ) {
	if ( 'edit.php' !== $hook ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'edit-plm_product' !== $screen->id ) {
		return;
	}

	wp_enqueue_script(
		'as-product-list-edit',
		get_template_directory_uri() . '/assets/js/product-list-edit.js',
		array(),
		AS_THEME_VERSION,
		true
	);
	wp_localize_script(
		'as-product-list-edit',
		'AS_QE',
		array(
			'url'   => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'as_qe_product' ),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'as_qe_assets' );

/** AJAX：保存单个字段 */
function as_qe_save() {
	check_ajax_referer( 'as_qe_product' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => '没有权限' ), 403 );
	}

	$pid   = isset( $_POST['pid'] ) ? (int) $_POST['pid'] : 0;
	$field = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
	$raw   = isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

	if ( ! $pid || ! in_array( $field, as_qe_fields(), true ) ) {
		wp_send_json_error( array( 'message' => '参数不正确' ), 400 );
	}

	$post = get_post( $pid );
	if ( ! $post || 'plm_product' !== $post->post_type ) {
		wp_send_json_error( array( 'message' => '这个产品不存在' ), 404 );
	}
	if ( ! current_user_can( 'edit_post', $pid ) ) {
		wp_send_json_error( array( 'message' => '没有权限改这个产品' ), 403 );
	}

	$value = as_qe_clean( $field, $raw );
	if ( is_wp_error( $value ) ) {
		wp_send_json_error( array( 'message' => $value->get_error_message() ), 400 );
	}

	// menu_order 是 post 字段，不是 meta
	if ( 'menu_order' === $field ) {
		wp_update_post(
			array(
				'ID'         => $pid,
				'menu_order' => (int) $value,
			)
		);
		wp_send_json_success( array( 'value' => $value ) );
	}

	// 其余字段与编辑页一致：空值就删 meta，不留空记录
	if ( '' === $value ) {
		delete_post_meta( $pid, $field );
	} else {
		update_post_meta( $pid, $field, $value );
	}

	wp_send_json_success(
		array(
			'value'   => $value,
			'display' => ( in_array( $field, array( 'dg_price', 'dg_old_price' ), true ) && '' !== $value ) ? '¥' . $value : $value,
		)
	);
}
add_action( 'wp_ajax_as_qe_product', 'as_qe_save' );
