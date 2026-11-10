# 贡献指南

感谢你考虑为本项目贡献代码。

## 提交 Issue 之前

先搜一下已有的 Issue —— 可能已经有人问过同样的问题。

**提 Bug 请务必附上：**

- WordPress 版本、PHP 版本、主题版本
- 复现步骤（怎么点、点什么、期望什么、实际什么）
- 复现时**确实发生**的那个页面 URL
- 浏览器控制台与 PHP 错误日志里的报错
- 如果能截图更好（把个人域名和敏感信息涂掉）

## 提 PR 之前

1. **先开 Issue 讨论** —— 尤其是改动比较大的时候，避免白做
2. **从 main 分支切新分支**：`git checkout -b feature/your-feature`
3. **一次 PR 只做一件事** —— 不要把重构和新功能混在一起
4. **确认能跑通**：装一个干净的 WordPress，启用主题，验证你改的部分
5. **在 PR 描述里写清楚**：改了什么、为什么改、怎么验证

## 代码规范

### PHP

遵循 [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/)：

- 缩进用 **Tab**，行内对齐用 **空格**
- 文件用 `<?php` 开头，第一行是文件说明注释
- 所有文件加 `defined( 'ABSPATH' ) || exit;` 防直访
- 前台输出必须转义：`esc_html()` / `esc_attr()` / `esc_url()` / `esc_js()`
- 数据库操作走 WordPress API，`$wpdb` 查询必须用 `prepare()`
- 不要用 `esc_sql()` 代替 `prepare()`
- 函数名用小写 + 下划线，前缀 `as_`（避免与其他主题冲突）
- 变量名不要用 `$post`、`$id`、`$query` 这类通用名（会覆盖全局变量）

### JavaScript

- ES5 语法 + `var`（不引入构建工具，不引 jQuery）
- 事件监听用 `addEventListener`，不要用 `onxxx` 属性
- 所有 DOM 查询都要判空 —— 主题可能在没有该元素的页面被使用

### CSS

- 用 BEM 风格命名：`as-block__element--modifier`
- 颜色、圆角等设计值集中成 CSS 变量，别散落各处
- 前台样式表放 `assets/css/`，后台专用样式表也放 `assets/css/` 但用 `admin-` 前缀

### 注释

**写「为什么」，不写「做了什么」**：

```php
// ❌ 把价格设为 0
$price = 0;

// ✅ 券门槛恒按现价判，不按会员价 —— 会员折扣会让券失效
$base_price = $current_price;
```

## 提交信息

用祈使句、说明做了什么：

```
fix: 修复商品列表页虚拟销量留 0 的问题
feat: 商品卡片支持按销量排序
docs: 补充短代码用法说明
refactor: 把 SEO 逻辑从 functions.php 拆到 inc/seo.php
```

## 不要做的事

- 不要提交密钥、API Key、数据库密码、个人域名
- 不要提交 `wp-config.php`、`node_modules/`、编辑器配置目录
- 不要改动与你的 PR 无关的文件（格式化、顺手重构都不行）
- 不要删除 `inc/lib/qrcode.class.php` 的版权头 —— 它是 LGPL 的第三方代码

## 许可

你提交的代码默认以本项目的 GPL v2 or later 授权发布。

## 行为准则

参与即表示同意 [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md)。
