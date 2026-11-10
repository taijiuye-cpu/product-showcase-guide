<?php
/**
 * 基础版兼容层 —— 关闭交易类功能的降级实现
 * ---------------------------------------------------------------------------
 * 目的：开源基础版不含支付 / 会员 / 订单 / 物流 / 小程序商城等交易功能，
 *       但前台模板里有大量调用点。为了让模板一处不改就能跑，这里提供
 *       **语义等价于「功能已关闭」**的降级实现。
 *
 * 规则（本文件所有函数都必须遵守）：
 *   1. 不得访问 as_orders / as_coupons 等交易表 —— 基础版没有这些表。
 *   2. 不得读任何支付密钥 —— 基础版不支持支付。
 *   3. 返回值必须与「原模块存在但开关关闭」时完全一致，
 *      否则模板会出现「会员价永远算不出来」「购物车角标一直有」这类错位。
 *
 * 本文件不需要配置，开箱即用。
 *
 * @package product-showcase-guide
 */

defined( 'ABSPATH' ) || exit;

/* ==========================================================================
 * 会员 / 优惠券
 * ======================================================================== */

/**
 * 站点是否有会员体系。
 *
 * 基础版没有会员功能 —— 前台的「成为会员」按钮、「会员折扣」统计格、
 * 会员中心链接都指向不存在的页面，所以模板要按这个开关隐藏它们。
 *
 * 完整版（含 inc/vip.php、inc/web-member.php）里这个函数不存在，
 * 模板用 function_exists() 保护 → 会员入口照常显示。
 *
 * @return bool 基础版恒为 false。
 */
function as_has_member_system() {
	return false;
}

/**
 * 站点是否有优惠券系统 —— 基础版恒为 false。
 *
 * @return bool
 */
function as_has_coupon_system() {
	return false;
}

/**
 * 当前访客是否是 VIP —— 基础版无会员体系，恒为 false。
 *
 * @param int $uid 用户 ID（保留参数只为与原函数签名一致）。
 * @return bool
 */
function as_vip_is_vip( $uid = 0 ) {
	return false;
}

/**
 * 会员价提示块 —— 基础版恒返回 null（表示「没有会员价这回事」）。
 *
 * @param int $pid 商品 ID。
 * @return array|null
 */
function as_vip_web_notice( $pid ) {
	return null;
}

/**
 * 会员到手价 —— 基础版恒返回 null。
 *
 * @param int $pid 商品 ID。
 * @return array|null
 */
function as_vip_special( $pid ) {
	return null;
}

/**
 * 会员价 —— 基础版恒返回 null。
 *
 * @param int $pid 商品 ID。
 * @return float|null
 */
function as_vip_price( $pid ) {
	return null;
}

/**
 * 全局会员折扣率 —— 基础版无折扣，恒返回空字符串。
 *
 * @return string
 */
function as_vip_global_rate() {
	return '';
}

/**
 * 折扣率的中文标签。
 *
 * @param string $rate 折扣率。
 * @return string
 */
function as_vip_rate_label( $rate ) {
	return $rate ? (string) $rate : '';
}

/**
 * 清理价格字符串 —— 基础版仍需要（后台保存价格时要做同样的清洗）。
 *
 * @param mixed $raw 原始值。
 * @return string
 */
function as_vip_clean_price( $raw ) {
	if ( is_array( $raw ) || is_object( $raw ) ) {
		return '';
	}
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return '';
	}
	$clean = preg_replace( '/[^0-9.]/', '', $raw );
	return ( null === $clean ) ? '' : $clean;
}

/**
 * 清理折扣率字符串。
 *
 * @param mixed $raw 原始值。
 * @return string
 */
function as_vip_clean_rate( $raw ) {
	if ( is_array( $raw ) || is_object( $raw ) ) {
		return '';
	}
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return '';
	}
	$clean = preg_replace( '/[^0-9.]/', '', $raw );
	return ( null === $clean ) ? '' : $clean;
}

/**
 * 「成为会员」入口配置 —— 基础版无会员入口，恒返回空数组。
 *
 * @return array
 */
function as_vip_join_entry() {
	return array();
}

/**
 * 商品是否对非会员隐藏 —— 基础版不控价，恒为 false。
 *
 * @param int $pid 商品 ID。
 * @return bool
 */
function as_vip_product_hidden( $pid ) {
	return false;
}

/**
 * 商品是否隐藏优惠券提示 —— 基础版无优惠券，恒为 false。
 *
 * @param int $pid 商品 ID。
 * @return bool
 */
function as_vip_product_hide_coupon( $pid ) {
	return false;
}

/**
 * 会员价文案是否对该商品展示 —— 基础版恒为 false。
 *
 * @param int $pid 商品 ID。
 * @return bool
 */
function as_vip_product_announced( $pid ) {
	return false;
}

/**
 * 会员提示文案 —— 基础版恒为空。
 *
 * @return string
 */
function as_vip_hint_text() {
	return '';
}

/**
 * 金额格式化 —— 保留这个工具函数（前台多处用它显示价格）。
 *
 * @param mixed $amount 金额。
 * @return string
 */
function as_vip_money( $amount ) {
	$n = round( (float) $amount, 2 );
	return ( $n === floor( $n ) ) ? (string) (int) $n : number_format( $n, 2, '.', '' );
}

/**
 * 券门槛基准价 —— 基础版无券，恒用现价。
 *
 * @param int $pid 商品 ID。
 * @return float
 */
function as_coupon_base_for_viewer( $pid ) {
	return (float) get_post_meta( (int) $pid, 'dg_price', true );
}

/**
 * 当前访客可用的券 —— 基础版恒为空数组。
 *
 * @param int $pid 商品 ID。
 * @return array
 */
function as_coupons_for_viewer( $pid ) {
	return array();
}

/**
 * 到手价标签 —— 基础版没有「券后价」这回事。
 *
 * @return string
 */
function as_price_final_tag() {
	return '';
}

/**
 * 价格信息聚合 —— 基础版口径：只有现价，没有会员价、没有券。
 *
 * 结构与完整版保持一致（模板按 key 取值），只是恒填充「未开启」的值。
 *
 * @param int $pid 商品 ID。
 * @return array
 */
function as_web_price_info( $pid ) {
	static $cache = array();

	$pid = (int) $pid;
	if ( isset( $cache[ $pid ] ) ) {
		return $cache[ $pid ];
	}

	$now = (float) get_post_meta( $pid, 'dg_price', true );

	$out = array(
		'now'     => $now,
		'vip'     => null,
		'special' => false,
		'coupons' => array(),
		'off'     => 0.0,
		'na'      => false,
		'final'   => null,
		'tag'     => '',
		'save'    => 0.0,
	);

	return $cache[ $pid ] = $out;
}

/* ==========================================================================
 * 优惠券（交易相关，基础版一律为空）
 * ======================================================================== */

/**
 * 生效中的优惠券 —— 基础版无券，恒为空数组。
 *
 * @param bool $force 忽略缓存。
 * @return array
 */
function as_mini_coupons_active( $force = false ) {
	return array();
}

/**
 * 某商品可用的券 —— 基础版恒为空数组。
 *
 * @param int   $product_id 商品 ID。
 * @param float $price      基准价。
 * @return array
 */
function as_mini_coupons_for_product( $product_id, $price = null ) {
	return array();
}

/**
 * 券是否适用于某商品 —— 基础版恒为 false。
 *
 * @param array $coupon  券。
 * @param array $product_ids 商品 ID 列表。
 * @return bool
 */
function as_mini_coupon_applies( $coupon, $product_ids ) {
	return false;
}

/**
 * 我的优惠券 —— 基础版恒为空数组。
 *
 * @return array
 */
function as_mini_my_coupons() {
	return array();
}

/* ==========================================================================
 * 网站商城（购物车 / 下单 / 支付）
 * ======================================================================== */

/**
 * 网站商城是否开启 —— 基础版恒为 false。
 *
 * 模板用它决定「加入购物车 / 立即购买」按钮出不出现，
 * 恒 false 正好是「纯导购站」该有的样子。
 *
 * @return bool
 */
function as_web_mall_enabled() {
	return false;
}

/**
 * 当前访问者用户 ID —— 基础版没有会员账号体系，但登录态仍可用。
 *
 * @return int
 */
function as_web_current_uid() {
	return is_user_logged_in() ? (int) get_current_user_id() : 0;
}

/**
 * 购物车件数 —— 基础版无购物车，恒为 0。
 *
 * @return int
 */
function as_web_cart_count() {
	return 0;
}

/**
 * 购物车商品明细 —— 基础版恒为空数组。
 *
 * @return array
 */
function as_web_cart_detail() {
	return array();
}

/**
 * 商品购买按钮组 —— 基础版只保留「去购买」外链，不做站内下单。
 *
 * 完整版的按钮组包含「加入购物车 / 立即购买 / 选择规格」等站内交易入口，
 * 基础版没有购物车和结算流程，所以只输出一个跳转外链的按钮。
 *
 * @param int    $post_id     商品 ID。
 * @param string $extra_class 附加 CSS 类。
 * @return string
 */
function as_product_buy_group( $post_id = null, $extra_class = '' ) {
	$post_id = $post_id ? (int) $post_id : (int) get_the_ID();
	if ( ! $post_id ) {
		return '';
	}
	/*
	 * 复用 functions.php 里的 as_buy_button() —— 它已经实现了「没有小程序、
	 * 没有站内下单」时只输出一个跳转外链的按钮，正是基础版要的行为。
	 */
	$btn = as_buy_button( $post_id, '去购买', false );
	if ( ! $btn ) {
		return '';
	}
	if ( $extra_class ) {
		$btn = str_replace( 'class="as-buy-btn"', 'class="as-buy-btn ' . esc_attr( $extra_class ) . '"', $btn );
	}
	return $btn;
}

/**
 * 站内会员弹窗 —— 基础版无会员体系，恒返回空串。
 *
 * @param string $err 错误信息。
 * @return string
 */
function as_web_member_modal( $err = '' ) {
	return '';
}

/**
 * 支付配置 —— 基础版不支持支付，恒返回全关闭的默认配置。
 *
 * @return array
 */
function as_wpay_config() {
	return array(
		'mall_enabled' => false,
		'wx_enabled'   => false,
		'wx_source'    => '',
		'wx_mch_id'    => '',
		'wx_key'       => '',
		'wx_appid'     => '',
		'wx_mode'      => 'native',
		'wx_notify'    => '',
		'ali_enabled'  => false,
		'ali_appid'    => '',
		'ali_private_key' => '',
		'ali_public_key'  => '',
		'ali_mode'     => 'web',
		'ali_mobile'   => false,
		'ali_notify'   => '',
	);
}

/**
 * 读支付配置项 —— 基础版无密钥，恒返回默认值。
 *
 * @param string $key     配置键。
 * @param string $default 默认值。
 * @return string
 */
function as_wpay_get( $key, $default = '' ) {
	return $default;
}

/**
 * 微信小程序跳转是否开启 —— 基础版不含小程序，恒为 false。
 *
 * @return bool
 */
function as_wx_jump_enabled() {
	return false;
}

/**
 * 自定义小程序码地址 —— 基础版恒为空。
 *
 * @return string
 */
function as_wx_jump_qr_custom() {
	return '';
}

/**
 * 小程序环境 —— 基础版恒为 release。
 *
 * @return string
 */
function as_wx_jump_env() {
	return 'release';
}

/* ==========================================================================
 * 小程序 / 首页广告 / 站点配置
 * ======================================================================== */

/**
 * 小程序配置 —— 基础版没有小程序，但 **option 存储（as_mini_config）沿用**，
 * 因为网站设置页里「服务说明标签」「首页优惠模块显示数量」「首页广告位」
 * 等设置仍存在这张表里（见 inc/site-settings.php 的 source=option 字段）。
 *
 * 基础版只读这几个键，其余小程序专属键（appid / 密钥 / 支付相关）一律忽略。
 *
 * @return array
 */
function as_mini_get_config() {
	static $cfg = null;
	if ( null === $cfg ) {
		$saved = get_option( 'as_mini_config', array() );
		$cfg   = is_array( $saved ) ? $saved : array();
	}

	$kept = array( 'service_tags', 'flash_count', 'home_ads', 'flash_end_at' );

	$out = array();
	foreach ( $kept as $key ) {
		if ( isset( $cfg[ $key ] ) ) {
			$out[ $key ] = $cfg[ $key ];
		}
	}
	return $out;
}

/**
 * 读小程序配置项 —— 基础版没有小程序，但 **option 存储（as_mini_config）沿用**，
 * 因为网站设置页里「服务说明标签」「首页抢购数量」等设置仍存在这张表里
 * （见 inc/site-settings.php 的 source=option 字段）。
 *
 * 基础版只需要读这两个键，其余小程序专属键（appid / 密钥 / 支付相关）一律忽略。
 *
 * @param string $key 配置键。
 * @return mixed
 */
function as_mini_opt( $key ) {
	static $cfg = null;
	if ( null === $cfg ) {
		$saved = get_option( 'as_mini_config', array() );
		$cfg   = is_array( $saved ) ? $saved : array();
	}

	// 基础版保留的键：网站侧仍然在用。
	$kept = array( 'service_tags', 'flash_count', 'home_ads', 'flash_end_at' );

	if ( ! in_array( $key, $kept, true ) ) {
		return '';
	}
	if ( ! isset( $cfg[ $key ] ) ) {
		return '';
	}
	return $cfg[ $key ];
}

/**
 * 首页广告位最大数量。
 *
 * @return int
 */
function as_home_ads_max() {
	return 6;
}

/**
 * 取首页广告列表（已清洗，可直接在模板里用）。
 *
 * 这是**网站侧功能**（前台首页 hero 位置的轮播广告位），不属于交易链路，
 * 因此基础版完整保留。存储位置沿用 as_mini_config 的 home_ads 键。
 *
 * 清洗：过滤掉没图 / 没目标 / 类型非法 / 目标已删除或不是发布的条目。
 *
 * @return array 每条：array( 'img' => string, 'type' => string, 'id' => int, 'title' => string )
 */
function as_home_ads() {
	$raw = as_mini_opt( 'home_ads' );
	if ( ! is_array( $raw ) ) {
		return array();
	}

	$out = array();
	foreach ( $raw as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}
		$img  = isset( $item['img'] ) ? trim( (string) $item['img'] ) : '';
		$type = isset( $item['type'] ) ? (string) $item['type'] : '';
		$id   = isset( $item['target_id'] ) ? (int) $item['target_id'] : 0;

		if ( '' === $img || 0 === $id ) {
			continue;
		}
		if ( ! in_array( $type, array( 'product', 'post' ), true ) ) {
			continue;
		}

		// 目标得真实存在且已发布（下架的商品 / 草稿文章不该出现在广告位）
		$post = get_post( $id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			continue;
		}
		// 商品必须是商品类型、文章必须是文章类型
		$expect = ( 'product' === $type ) ? 'plm_product' : 'post';
		if ( $post->post_type !== $expect ) {
			continue;
		}

		$out[] = array(
			'img'   => $img,
			'type'  => $type,
			'id'    => $id,
			'title' => get_the_title( $post ),
		);

		if ( count( $out ) >= as_home_ads_max() ) {
			break;
		}
	}

	return $out;
}

/**
 * 单条广告的网页端跳转链接。
 *
 * @param array $ad as_home_ads() 里的单条。
 * @return string 链接；拿不到就返回空串（前端应跳过）。
 */
function as_home_ad_url( array $ad ) {
	$link = get_permalink( $ad['id'] );
	return $link ? (string) $link : '';
}

/**
 * 物流查询是否就绪 —— 基础版不含物流，恒为 false。
 *
 * @return bool
 */
function as_logistics_ready() {
	return false;
}

/**
 * 识别快递公司 —— 基础版不含物流，恒返回空。
 *
 * @param string $text 单号或公司名。
 * @return string
 */
function as_logistics_detect_carrier( $text ) {
	return '';
}

/**
 * 快递公司展示名 —— 基础版不含物流，恒返回原值。
 *
 * @param string $code 公司代码。
 * @return string
 */
function as_logistics_carrier_label( $code ) {
	return (string) $code;
}
