<?php
/**
 * 首页 —— 只做**风格分派**
 * --------------------------------------------------------------------------
 * v2.87.43：后台「主题设置 → 外观风格」可切换两套首页编排：
 *   · inc/home/shelf.php    —— 货架（默认）：区块等权、商品密集、找货快
 *   · inc/home/showcase.php —— 橱窗：品牌大屏、大图留白、一屏一件事
 *
 * 两套共用同一批取数函数与业务逻辑（价格 / 券 / 会员价 / 购物车 / 支付），
 * 这里只决定「用哪套编排渲染」，不掺任何功能判断。
 *
 * @package product-showcase-guide
 */

defined( 'ABSPATH' ) || exit;

get_header();

$as_home_layout = ( function_exists( 'as_home_style' ) && as_home_style_is( 'showcase' ) )
	? 'showcase'
	: 'shelf';

require get_template_directory() . '/inc/home/' . $as_home_layout . '.php';

get_footer();
