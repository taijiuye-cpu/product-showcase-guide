=== Product Showcase Guide ===
Contributors: taijiuye
Tags: blog, e-commerce, custom-menu, custom-logo, featured-images, translation-ready
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

商品导购与评测主题：商品管理、品牌品类分类、限时特惠、SEO、整页缓存与图片优化。

== Description ==

Product Showcase Guide 把「商品展示」与「内容评测」做在一起。

适合做商品导览站、选购指南站、评测博客站 —— 站点自己管理商品与参数，
把购买动作引导到你自己填写的外部购买链接，不做站内交易。

主要功能：

* 独立商品文章类型，配套字段面板（现价、原价、购买链接、规格、成分、优缺点、用户评价等）
* 品牌与品类两套分类法，支持品牌 Logo 与分类图标
* 商品图集：多图上传、排序、设主图
* 限时特惠区块与 /sale/ 页面，倒计时结束时间后台可配
* 首页「货架」与「橱窗」两种风格，一键切换
* 完整博客系统：归档、搜索、评论、相关文章、阅读量
* SEO：meta、Open Graph、Twitter Card、JSON-LD 结构化数据
* 整页缓存（未登录访客）、WebP 批量转换、长图懒加载
* 演示数据一键导入、站点健康自查

= 隐私与数据 =

主题本身不收集任何数据，也不内置第三方服务的 SDK。
可选的 AI 文本检测功能需要你自己提供 API Key，Key 只保存在服务端，不下发浏览器。

= 免责声明 =

本主题是软件工具，不销售任何商品，也不提供商品推荐。
主题自带的演示数据全部为虚构示例内容，不对应任何真实商品、品牌或功效宣称。

== Installation ==

1. 后台 → 外观 → 主题 → 添加新主题 → 上传主题，选择 zip 文件安装并启用。
2. 后台 → 主题设置 → 演示数据导入，可一键填充示例内容。
3. 设置 → 固定链接，保存一次以刷新商品页的伪静态规则。

== Frequently Asked Questions ==

= 商品页 404 怎么办？ =

主题的商品详情页依赖伪静态规则。去「设置 → 固定链接」保存一次，然后访问任意页面触发规则刷新。

= 演示图片放在哪里？ =

放进 WordPress 媒体库，不要放在主题目录里 —— 后台上传主题时会先删除整个主题目录，
放在里面的图片会一起丢。详见主题内 assets/imgs/post/README.txt。

= 首页「限时特惠」区块是空的？ =

两步：① 在「产品」列表页给商品勾上「首页抢购」；② 在「主题设置 → 通用设置」里
填「限时活动结束时间」（不填则不显示倒计时，商品仍会出现）。

= 怎么改成自己的样式？ =

用子主题覆盖，不要直接改主题文件 —— 更新主题时会覆盖改动。

== Changelog ==

= 1.0.0 =
* 首个公开版本。

== Upgrade Notice ==

= 1.0.0 =
首个版本。
