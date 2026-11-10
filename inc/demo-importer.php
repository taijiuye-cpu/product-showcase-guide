<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 后台菜单：演示数据导入（v2.53.0 起并入「主题设置」子菜单）
 *
 * 原来这里用 add_menu_page 注册独立一级菜单，现已由
 * inc/site-settings.php 的 as_site_settings_menu() 作为子菜单注册，
 * 所以本文件不再注册一级菜单（否则 WP 会同时挂两处）。
 */
function as_demo_admin_menu() {
	// 已并入主题设置，此处不再单独注册菜单。
	// 挂载点见 inc/site-settings.php → as_site_settings_menu()。
}
add_action( 'admin_menu', 'as_demo_admin_menu' );

/**
 * 导入页面 UI + 处理
 */
function as_demo_import_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    /*
     * 现在这个页面挂在「主题设置 → 演示数据导入」下（作为子菜单回调），
     * 而 site-settings.php 也可能把别的子页指向自己的渲染函数。
     * 这里按 $_GET['page'] 确认确实是本页再继续，
     * 否则同一个回调被误用于别的页时会渲染错内容。
     */
    $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
    if ( 'as-demo-import' !== $page ) {
        return;
    }

    $notice = '';

    // 处理导入请求
    if ( isset( $_POST['as_demo_import'] ) && check_admin_referer( 'as_demo_import_action', 'as_demo_nonce' ) ) {
        $update_existing = ! empty( $_POST['as_demo_update'] );
        $result = as_run_demo_import( $update_existing );

        if ( is_wp_error( $result ) ) {
            $notice = '<div class="notice notice-error"><p>导入失败：' . esc_html( $result->get_error_message() ) . '</p></div>';
        } else {
            $notice = '<div class="notice notice-success"><p>导入完成！' .
                '品类 ' . intval( $result['categories'] ) . ' 个，' .
                '品牌 ' . intval( $result['brands'] ) . ' 个，' .
                '新增产品 ' . intval( $result['products'] ) . ' 个，' .
                ( $result['updated'] ? '更新已有产品 ' . intval( $result['updated'] ) . ' 个，' : '' ) .
                '新增文章 ' . intval( $result['posts'] ) . ' 篇' .
                ( $result['updated_posts'] ? '，更新已有文章 ' . intval( $result['updated_posts'] ) . ' 篇' : '' ) .
                ( ( $result['thumbs_product'] || $result['thumbs_post'] )
                    ? '。配图：产品 ' . intval( $result['thumbs_product'] ) . ' 张、文章 ' . intval( $result['thumbs_post'] ) . ' 张'
                    : '' ) .
                '。</p></div>';
        }
    }

    // 处理清空请求
    if ( isset( $_POST['as_demo_clear'] ) && check_admin_referer( 'as_demo_clear_action', 'as_demo_nonce' ) ) {
        $result = as_clear_demo_data();
        $notice = '<div class="notice notice-warning"><p>已清空：产品 ' . intval( $result['products'] ) .
                  ' 个，文章 ' . intval( $result['posts'] ) . ' 篇（分类和品牌保留）。</p></div>';
    }

    // 处理「从媒体库补配图」
    if ( isset( $_POST['as_demo_fill_thumbs'] ) && check_admin_referer( 'as_demo_fill_action', 'as_demo_nonce' ) ) {
        $fill   = as_demo_fill_thumbnails_from_library();
        $notice = '<div class="notice notice-success"><p>已从媒体库补配图：产品 ' .
            intval( $fill['plm_product']['filled'] ) . ' 张，文章 ' . intval( $fill['post']['filled'] ) . ' 张。' .
            ( $fill['plm_product']['missing'] ? '仍有 ' . intval( $fill['plm_product']['missing'] ) . ' 个产品没有特色图像。' : '' ) .
            '</p></div>';
    }

    $data_file = get_template_directory() . '/demo-data.txt';
    $file_exists = file_exists( $data_file );

    $as_lib_square = count( as_demo_library_image_ids( 'square' ) );
    $as_lib_wide   = count( as_demo_library_image_ids( 'wide' ) );
    ?>
    <div class="wrap">
        <h1>演示数据导入</h1>
        <?php echo wp_kses_post( $notice ); ?>

        <div class="card" style="max-width:720px;padding:20px;margin-top:20px;">
            <h2>数据源文件</h2>
            <p>
                文件路径：<code><?php echo esc_html( $data_file ); ?></code><br>
                状态：
                <?php if ( $file_exists ) : ?>
                    <span style="color:green;">已找到</span>
                <?php else : ?>
                    <span style="color:red;">未找到，请确认主题目录下存在 demo-data.txt</span>
                <?php endif; ?>
            </p>
            <p class="description">
                导入会把文件里的品类、品牌、产品、文章写入数据库，按标题去重。
                <strong>勾选下面的选项</strong>后，已存在的产品与文章也会被更新——升级主题后想补齐新增的属性（优缺点、成分、自定义属性等）、或者刷新文章正文，都靠它。
            </p>

            <?php
            $as_img_product = as_demo_image_files( 'product' );
            $as_img_post    = as_demo_image_files( 'post' );
            ?>
            <p class="description">
                演示配图：
                产品图 <strong><?php echo count( $as_img_product ); ?></strong> 张
                （<code>assets/imgs/product/</code>）、
                文章图 <strong><?php echo count( $as_img_post ); ?></strong> 张
                （<code>assets/imgs/post/</code>）。
                <?php if ( ! $as_img_product && ! $as_img_post ) : ?>
                    <br><em>两个目录都是空的。导入时会自动改用媒体库里已有的图片补配图，不会留成占位图。</em>
                <?php else : ?>
                    <br>导入时会随机分配给还没有特色图像的内容；已经手动设过图的不会被覆盖。
                <?php endif; ?>
                <br>⚠️ 这两个目录在<strong>主题目录内部</strong>，而后台上传主题时会先删掉整个主题目录再解压，
                所以放在这里的图<strong>每次更新主题都会丢</strong>。要长期使用请改用下面的「从媒体库补配图」。
            </p>

            <form method="post" style="margin-top:16px;">
                <?php wp_nonce_field( 'as_demo_import_action', 'as_demo_nonce' ); ?>
                <p>
                    <label>
                        <input type="checkbox" name="as_demo_update" value="1" checked>
                        同时更新已存在的产品与文章（补齐新增属性、刷新正文）
                    </label>
                </p>
                <button type="submit" name="as_demo_import" class="button button-primary button-hero" <?php disabled( ! $file_exists ); ?>>
                    导入初始数据
                </button>
            </form>
        </div>

        <?php echo as_demo_health_report(); ?>

        <div class="card" style="max-width:720px;padding:20px;margin-top:20px;">
            <h2>从媒体库补配图</h2>
            <p class="description">
                给<strong>还没有特色图像</strong>的产品和文章，从媒体库里已有的图片随机分配。
                已经手动设过图的内容不会被覆盖，也不会往媒体库导入新文件。
            </p>
            <p class="description" style="background:#fff8e5;border-left:4px solid #dba617;padding:10px 12px;">
                <strong>图片突然全变成灰色占位块时，点这里。</strong><br>
                原因：WordPress 上传主题时会<strong>先删掉整个主题目录</strong>再解压，
                所以放在 <code>assets/imgs/product/</code> 和 <code>assets/imgs/post/</code> 里的原图会被一起清掉。
                但之前导入到<strong>媒体库</strong>里的图还在 —— 这个按钮就是把它们重新认回来。
            </p>
            <p class="description">
                媒体库当前可用：方图 <strong><?php echo intval( $as_lib_square ); ?></strong> 张（分给产品）、
                横图 <strong><?php echo intval( $as_lib_wide ); ?></strong> 张（分给文章）。
                <?php if ( ! $as_lib_square && ! $as_lib_wide ) : ?>
                    <br><em>媒体库里还没有可用的图片，先去「媒体 → 添加」上传几张。</em>
                <?php endif; ?>
            </p>
            <form method="post" style="margin-top:12px;">
                <?php wp_nonce_field( 'as_demo_fill_action', 'as_demo_nonce' ); ?>
                <button type="submit" name="as_demo_fill_thumbs" class="button button-primary">从媒体库补配图</button>
            </form>
        </div>

        <div class="card" style="max-width:720px;padding:20px;margin-top:20px;">
            <h2>清空演示产品与文章</h2>
            <p class="description">
                只删除「产品」和「文章」，分类和品牌保留。用于反复测试导入效果。
                <br>由演示导入产生的图片附件会留在媒体库中（不删除），以免误删你后来手动复用过的图。
            </p>
            <form method="post" onsubmit="return confirm('确定要清空所有产品和文章吗？此操作不可恢复。');">
                <?php wp_nonce_field( 'as_demo_clear_action', 'as_demo_nonce' ); ?>
                <button type="submit" name="as_demo_clear" class="button button-secondary">
                    清空产品与文章
                </button>
            </form>
        </div>
    </div>
    <?php
}

/**
 * 把演示数据里的字面 \n 还原成真实换行
 *
 * 演示数据是一行一条、字段用 | 分隔，正文里没法直接写换行，
 * 所以约定用两个字符的反斜杠 + n 表示换行，写成多段。
 * 导入后交给 wpautop 渲染成多个 <p>。
 * 原来不含 \n 的单段内容不受影响。
 */
function as_demo_unescape( $text ) {
    return str_replace( array( '\\n', '\\r' ), array( "\n", '' ), (string) $text );
}

/**
 * 解析 demo-data.txt
 */
function as_parse_demo_file() {
    $file = get_template_directory() . '/demo-data.txt';
    if ( ! file_exists( $file ) ) {
        return new WP_Error( 'no_file', '演示数据文件不存在。' );
    }

    $lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
    if ( ! $lines ) {
        return new WP_Error( 'empty_file', '演示数据文件为空。' );
    }

    $data = array(
        'category' => array(),
        'brand'    => array(),
        'product'  => array(),
        'post'     => array(),
    );

    $section = '';

    foreach ( $lines as $line ) {
        $line = trim( $line );
        if ( $line === '' || strpos( $line, '#' ) === 0 ) continue;

        // 检测分节标记
        if ( preg_match( '/^\[(category|brand|product|post)\]$/i', $line, $m ) ) {
            $section = strtolower( $m[1] );
            continue;
        }

        if ( ! $section ) continue;

        // 按 | 拆分
        $parts = array_map( 'trim', explode( '|', $line ) );

        switch ( $section ) {
            case 'category':
                if ( count( $parts ) >= 1 && $parts[0] !== '' ) {
                    $data['category'][] = array(
                        'name' => $parts[0],
                        'desc' => $parts[1] ?? '',
                    );
                }
                break;

            case 'brand':
                if ( $parts[0] !== '' ) {
                    $data['brand'][] = $parts[0];
                }
                break;

            case 'product':
                // 必填 7 项：标题|品类|品牌|现价|原价|购买链接|商品详情
                // 后面的选填项可以整体省略
                // ⚠️ 第 7 列（下标 6）旧格式是「推荐指数」，v2.87.14 起该属性整体移除，
                //    这里仍按下标 6 解析以保证旧演示文件内容列不错位，但不再写入 meta。
                if ( count( $parts ) >= 8 ) {
                    $parts = array_pad( $parts, 20, '' );

                    $data['product'][] = array(
                        'title'        => $parts[0],
                        'category'     => $parts[1],
                        'brand'        => $parts[2],
                        'price'        => $parts[3],
                        'old_price'    => $parts[4],
                        'buy_url'      => $parts[5],
                        'rating'       => $parts[6],
                        'content'      => as_demo_unescape( $parts[7] ),
                        'spec'         => $parts[8],
                        'audience'     => $parts[9],
                        'ingredients'  => $parts[10],
                        'coupon'       => $parts[11],
                        'reason'       => $parts[12],
                        'pros'         => $parts[13],
                        'cons'         => $parts[14],
                        'custom'       => $parts[16],
                        'discreet'     => $parts[17],
                        'related_cats' => $parts[18],
                        'hot'          => $parts[19],
                    );
                }
                break;

            case 'post':
                // 标题 | 分类 | 内容 [| 关联品类]
                // 第 4 列可选：填一个品类名，文章底部就会推荐该品类下的产品
                if ( count( $parts ) >= 3 ) {
                    $parts = array_pad( $parts, 4, '' );

                    $data['post'][] = array(
                        'title'       => $parts[0],
                        'category'    => $parts[1],
                        'content'     => as_demo_unescape( $parts[2] ),
                        'product_cat' => $parts[3],
                    );
                }
                break;
        }
    }

    return $data;
}

/**
 * 按标题查重（get_page_by_title() 自 WP 6.2 起已弃用）
 */
function as_demo_find_by_title( $title, $post_type ) {
    $found = get_posts( array(
        'post_type'              => $post_type,
        'post_status'            => 'any',
        'title'                  => $title,
        'posts_per_page'         => 1,
        'fields'                 => 'ids',
        'no_found_rows'          => true,
        'update_post_term_cache' => false,
        'update_post_meta_cache' => false,
    ) );
    return $found ? $found[0] : 0;
}

/* ------------------------------------------------------------------
 * 演示图片：assets/imgs/product/ 与 assets/imgs/post/
 * 导入时随机分配给产品和文章，作为特色图像
 * ------------------------------------------------------------------ */

/**
 * 扫描主题内的演示图片目录
 *
 * @param string $type product | post
 * @return array 图片的绝对路径列表（已排序，便于打乱后复现）
 */
function as_demo_image_files( $type ) {
    $dir = trailingslashit( get_template_directory() ) . 'assets/imgs/' . $type;

    if ( ! is_dir( $dir ) ) {
        return array();
    }

    $allowed = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' );
    $files   = array();

    foreach ( (array) glob( $dir . '/*' ) as $file ) {
        if ( ! is_file( $file ) ) {
            continue;
        }

        $ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
        if ( in_array( $ext, $allowed, true ) ) {
            $files[] = $file;
        }
    }

    sort( $files );

    return $files;
}

/**
 * 把主题目录里的一张图片导入媒体库
 *
 * 同一个源文件只导入一次：已导入过的按 _as_demo_source 元标记复用，
 * 所以反复执行演示导入不会在媒体库里堆出重复附件。
 *
 * @return int 附件 ID，失败返回 0
 */
function as_demo_import_image( $source, $post_id = 0, $desc = '' ) {
    if ( ! $source || ! file_exists( $source ) ) {
        return 0;
    }

    $origin = basename( $source );

    // 已导入过就直接复用
    $found = get_posts( array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_query'     => array(
            array(
                'key'   => '_as_demo_source',
                'value' => $origin,
            ),
        ),
    ) );

    if ( $found ) {
        return intval( $found[0] );
    }

    // 文件名跟随主题的「上传图片自动重命名为时间戳」设置
    $ext  = pathinfo( $origin, PATHINFO_EXTENSION );
    $name = $origin;

    if ( get_theme_mod( 'as_rename_uploads', true ) ) {
        static $seq = 0;
        $seq++;
        $name = ( time() + $seq ) . ( $ext ? '.' . $ext : '' );
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $tmp = wp_tempnam( $name );
    if ( ! $tmp || ! @copy( $source, $tmp ) ) {
        return 0;
    }

    // media_handle_sideload 期待 $_FILES 形式；成功时它会把临时文件移走
    $attachment_id = media_handle_sideload(
        array(
            'name'     => $name,
            'tmp_name' => $tmp,
        ),
        $post_id,
        $desc
    );

    if ( is_wp_error( $attachment_id ) ) {
        @unlink( $tmp );
        return 0;
    }

    update_post_meta( $attachment_id, '_as_demo_source', $origin );

    return intval( $attachment_id );
}

/**
 * 给一篇内容分配特色图像
 *
 * $index 按引用传入，让同一批导入顺序取图、尽量不重复。
 * 已经有特色图像的内容不会被覆盖 —— 手动设过图的永远优先。
 */
function as_demo_assign_thumbnail( $post_id, $images, &$index, $title = '' ) {
    if ( empty( $images ) || has_post_thumbnail( $post_id ) ) {
        return false;
    }

    $total = count( $images );
    $file  = $images[ $index % $total ];
    $index++;

    $thumb_id = as_demo_import_image( $file, $post_id, $title );

    if ( ! $thumb_id ) {
        return false;
    }

    set_post_thumbnail( $post_id, $thumb_id );

    return true;
}

/**
 * 列出媒体库里已有的图片附件
 *
 * ⚠️ 为什么需要这个：WordPress 通过后台上传主题时，会**先删掉整个主题目录**
 * 再解压，所以放在 `assets/imgs/` 里的演示图会被一起清掉。
 * 但之前导入进媒体库的附件还在 —— 这个函数就是用来读回它们的后备方案。
 *
 * @param string $shape 'square' 只要接近正方形的（方图，适合产品）；'wide' 只要横图；'any' 不限
 * @return int[] 附件 ID 数组
 */
function as_demo_library_image_ids( $shape = 'any' ) {
    // 同一次请求里结果不会变，缓存一下，避免管理页多次调用重复遍历
    static $cache = array();

    if ( isset( $cache[ $shape ] ) ) {
        return $cache[ $shape ];
    }

    $ids = get_posts( array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif' ),
        'posts_per_page' => 600,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ) );

    $ids = array_map( 'intval', (array) $ids );

    if ( 'any' === $shape ) {
        $cache[ $shape ] = $ids;
        return $ids;
    }

    $out = array();

    foreach ( $ids as $id ) {
        $file = (string) get_attached_file( $id );

        // 排除 WordPress 自动裁的站点图标（cropped-*）、以及各种小尺寸变体
        if ( '' === $file || 0 === strpos( basename( $file ), 'cropped-' ) ) {
            continue;
        }

        $meta = wp_get_attachment_metadata( $id );
        if ( empty( $meta['width'] ) || empty( $meta['height'] ) ) {
            continue;
        }

        // 太小的不要（站点图标常见 32/180/192/270）
        if ( $meta['width'] < 300 || $meta['height'] < 300 ) {
            continue;
        }

        $ratio = $meta['width'] / max( 1, $meta['height'] );

        if ( 'square' === $shape && $ratio >= 0.9 && $ratio <= 1.1 ) {
            $out[] = $id;
        } elseif ( 'wide' === $shape && $ratio > 1.2 ) {
            $out[] = $id;
        }
    }

    $cache[ $shape ] = $out;

    return $out;
}

/**
 * 从媒体库给「还没有特色图像」的内容补图
 *
 * 已经设过图的一律不动。同一个附件会被多篇内容复用（图不够时循环使用），
 * 所以不会往媒体库里再塞重复文件。
 *
 * @return array 各类型的补图数量
 */
function as_demo_fill_thumbnails_from_library() {
    $plan = array(
        'plm_product' => 'square',
        'post'        => 'wide',
    );

    $result = array();

    foreach ( $plan as $post_type => $shape ) {
        $ids = as_demo_library_image_ids( $shape );

        // 某一档没有合适的图就放宽条件，别让内容一直空着
        if ( ! $ids ) {
            $ids = as_demo_library_image_ids( 'any' );
        }

        $items = get_posts( array(
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => 500,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ) );

        $filled = 0;
        $total  = count( $ids );
        $index  = 0;

        if ( $total ) {
            foreach ( $items as $item_id ) {
                if ( has_post_thumbnail( $item_id ) ) {
                    continue;
                }
                set_post_thumbnail( $item_id, $ids[ $index % $total ] );
                $index++;
                $filled++;
            }
        }

        $result[ $post_type ] = array(
            'filled'    => $filled,
            'available' => $total,
            'missing'   => count( $items ) - $filled,
        );
    }

    return $result;
}

/**
 * 执行导入
 *
 * @param bool $update_existing 是否更新已存在的产品（补齐新增属性）。
 *                              以前这里是无条件跳过，导致主题升级后重新导入
 *                              也补不上新增字段，只能先清空再导。
 */
function as_run_demo_import( $update_existing = false ) {
    $data = as_parse_demo_file();
    if ( is_wp_error( $data ) ) return $data;

    // 导入图片要生成各尺寸缩略图，数量多时比较耗时
    @set_time_limit( 0 );

    $count = array(
        'categories' => 0, 'brands' => 0, 'products' => 0, 'posts' => 0,
        'updated'    => 0, 'updated_posts' => 0,
        'coupons_created' => 0, 'coupons_skipped' => 0,
        'thumbs_product' => 0, 'thumbs_post' => 0,
    );

    // 演示图片：打乱后按顺序分配，图片数量够多时不会重复
    $product_images = as_demo_image_files( 'product' );
    $post_images    = as_demo_image_files( 'post' );
    shuffle( $product_images );
    shuffle( $post_images );

    $product_img_index = 0;
    $post_img_index    = 0;

    // 1. 导入品类
    $cat_map = array();
    foreach ( $data['category'] as $cat ) {
        $term = term_exists( $cat['name'], 'plm_category' );
        if ( ! $term ) {
            $term = wp_insert_term( $cat['name'], 'plm_category', array( 'description' => $cat['desc'] ) );
        }
        if ( ! is_wp_error( $term ) ) {
            $cat_map[ $cat['name'] ] = is_array( $term ) ? $term['term_id'] : $term;
            $count['categories']++;
        }
    }

    // 2. 导入品牌
    $brand_map = array();
    foreach ( $data['brand'] as $brand ) {
        $term = term_exists( $brand, 'plm_brand' );
        if ( ! $term ) {
            $term = wp_insert_term( $brand, 'plm_brand' );
        }
        if ( ! is_wp_error( $term ) ) {
            $brand_map[ $brand ] = is_array( $term ) ? $term['term_id'] : $term;
            $count['brands']++;
        }
    }

    // 3. 导入产品
    foreach ( $data['product'] as $p ) {
        $existing = as_demo_find_by_title( $p['title'], 'plm_product' );

        if ( $existing ) {
            // 已存在：只有勾了"同时更新"才继续，且只补属性、不动正文和标题
            if ( ! $update_existing ) {
                continue;
            }
            $post_id = $existing;
        } else {
            $post_id = wp_insert_post( array(
                'post_type'    => 'plm_product',
                'post_title'   => $p['title'],
                'post_content' => $p['content'],
                'post_status'  => 'publish',
            ) );

            if ( is_wp_error( $post_id ) || ! $post_id ) {
                continue;
            }
        }

        // 分类
        if ( isset( $cat_map[ $p['category'] ] ) ) {
            wp_set_object_terms( $post_id, intval( $cat_map[ $p['category'] ] ), 'plm_category' );
        }
        // 品牌
        if ( isset( $brand_map[ $p['brand'] ] ) ) {
            wp_set_object_terms( $post_id, intval( $brand_map[ $p['brand'] ] ), 'plm_brand' );
        }

        // 自定义字段
        // （推荐指数 dg_rating 属性 v2.87.14 已整体移除，演示数据里的那一列不再写入）

        // 服务说明已彻底全局化（v2.42.0）：不再按商品写 dg_discreet，
        // 统一由「小程序商城 → 设置 → 服务说明」配置，所有商品共用。

        // 优点 / 缺点 / 评论 / 自定义属性：演示数据里用分号分隔，写入时统一转成"一行一个"
        $meta = array(
            'dg_buy_url'     => esc_url_raw( $p['buy_url'] ),
            'dg_price'       => sanitize_text_field( $p['price'] ),
            'dg_old_price'   => sanitize_text_field( $p['old_price'] ),
            // 注意：演示数据里的「优惠信息」列（$p['coupon']）不再导入。
            // 优惠券是独立的一套（后台「小程序商城 → 优惠券」），
            // 如果按演示数据自动生成，会一口气冒出 20 多张券，反而干扰站长。
            'dg_spec'        => sanitize_text_field( $p['spec'] ),
            'dg_audience'    => sanitize_text_field( $p['audience'] ),
            'dg_ingredients' => sanitize_textarea_field( $p['ingredients'] ),
            'dg_reason'      => sanitize_textarea_field( $p['reason'] ),
            'dg_pros'        => sanitize_textarea_field( implode( "\n", as_text_lines( $p['pros'] ) ) ),
            'dg_cons'        => sanitize_textarea_field( implode( "\n", as_text_lines( $p['cons'] ) ) ),
            'dg_custom'      => sanitize_textarea_field( implode( "\n", as_text_lines( $p['custom'] ) ) ),
            // 虚拟销量：演示数据里没有这一列，按商品标题哈希生成一个稳定的
            // 伪随机数（80 ~ 3000），保证多次重导结果一致、看起来也自然。
            'dg_sales_virtual' => (string) ( 80 + ( abs( crc32( (string) $p['title'] ) ) % 2920 ) ),
        );

        foreach ( $meta as $meta_key => $meta_value ) {
            if ( '' === $meta_value ) {
                continue;
            }
            update_post_meta( $post_id, $meta_key, $meta_value );
        }

        // 关联文章分类：按名称查 term_id；这些分类可能还没建，先建再查
        $related_ids = array();
        foreach ( as_text_lines( $p['related_cats'] ) as $cat_name ) {
            $term = term_exists( $cat_name, 'category' );
            if ( ! $term ) {
                $term = wp_insert_term( $cat_name, 'category' );
            }
            if ( ! is_wp_error( $term ) && $term ) {
                $related_ids[] = is_array( $term ) ? intval( $term['term_id'] ) : intval( $term );
            }
        }
        if ( $related_ids ) {
            update_post_meta( $post_id, 'dg_related_cat', array_values( array_unique( $related_ids ) ) );
        }

        // 首页热门推荐
        if ( in_array( trim( (string) $p['hot'] ), array( '是', '1', 'yes', 'on' ), true ) ) {
            update_post_meta( $post_id, 'dg_hot', '1' );
        }

        // 随机分配产品特色图（已经设过图的产品不会被覆盖）
        if ( as_demo_assign_thumbnail( $post_id, $product_images, $product_img_index, $p['title'] ) ) {
            $count['thumbs_product']++;
        }

        if ( $existing ) {
            $count['updated']++;
        } else {
            $count['products']++;
        }
    }

    // 4. 导入文章
    foreach ( $data['post'] as $post ) {
        $existing = as_demo_find_by_title( $post['title'], 'post' );

        // 已存在：只有勾了"同时更新"才刷新正文与分类，不动标题
        if ( $existing && ! $update_existing ) {
            continue;
        }

        $cat_id = 0;
        $term = term_exists( $post['category'], 'category' );
        if ( ! $term ) {
            $term = wp_insert_term( $post['category'], 'category' );
        }
        if ( ! is_wp_error( $term ) ) {
            $cat_id = is_array( $term ) ? intval( $term['term_id'] ) : intval( $term );
        }

        $post_args = array(
            'post_type'     => 'post',
            'post_title'    => $post['title'],
            'post_content'  => $post['content'],
            'post_status'   => 'publish',
            'post_category' => $cat_id ? array( $cat_id ) : array(),
        );

        if ( $existing ) {
            $post_args['ID'] = $existing;
            $post_id   = wp_update_post( $post_args );
            $is_update = true;
        } else {
            $post_id   = wp_insert_post( $post_args );
            $is_update = false;
        }

        if ( is_wp_error( $post_id ) || ! $post_id ) {
            continue;
        }

        // 关联品类：文章底部据此推荐该品类下的产品
        if ( '' !== $post['product_cat'] ) {
            $pterm = term_exists( $post['product_cat'], 'plm_category' );
            if ( ! $pterm ) {
                $pterm = wp_insert_term( $post['product_cat'], 'plm_category' );
            }
            if ( ! is_wp_error( $pterm ) && $pterm ) {
                update_post_meta(
                    $post_id,
                    'dg_product_cat',
                    is_array( $pterm ) ? intval( $pterm['term_id'] ) : intval( $pterm )
                );
            }
        }

        // 随机分配文章特色图（已经设过图的文章不会被覆盖）
        if ( as_demo_assign_thumbnail( $post_id, $post_images, $post_img_index, $post['title'] ) ) {
            $count['thumbs_post']++;
        }

        if ( $is_update ) {
            $count['updated_posts']++;
        } else {
            $count['posts']++;
        }
    }

    // 兜底：主题目录里没有图（例如刚更新过主题，assets/imgs 被清空），
    // 或者图不够分，就从媒体库里已有的图片补上，避免导入完全是占位图。
    $fill = as_demo_fill_thumbnails_from_library();
    $count['thumbs_product'] += $fill['plm_product']['filled'];
    $count['thumbs_post']    += $fill['post']['filled'];

    // 优惠券与演示数据完全解耦：导入演示数据【不会】创建任何优惠券，
    // 也不会读取演示数据里的「优惠信息」列。优惠券只在
    // WordPress 后台「小程序商城 → 优惠券」里手动创建。

    return $count;
}

/**
 * 数据体检：统计每个必填 / 选填属性在多少个产品上有值
 * 用来一眼看出哪些属性还缺数据（比如所有产品都还没配图）
 */
function as_demo_health_report() {
    $ids = get_posts( array(
        'post_type'      => 'plm_product',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ) );

    $total = count( $ids );
    if ( ! $total ) {
        return '';
    }

    $meta_checks = array(
        '购买链接'     => 'dg_buy_url',
        '现价'         => 'dg_price',
        '原价'         => 'dg_old_price',
        '规格'         => 'dg_spec',
        '适合人群'     => 'dg_audience',
        '成分'         => 'dg_ingredients',
        '推荐理由'     => 'dg_reason',
        '优点'         => 'dg_pros',
        '缺点'         => 'dg_cons',
        '自定义属性'   => 'dg_custom',
        '关联文章分类' => 'dg_related_cat',
    );

    $counts  = array_fill_keys( array_keys( $meta_checks ), 0 );
    $thumbs  = 0;
    $branded = 0;
    $hot     = 0;

    foreach ( $ids as $id ) {
        if ( has_post_thumbnail( $id ) ) {
            $thumbs++;
        }

        $terms = get_the_terms( $id, 'plm_brand' );
        if ( $terms && ! is_wp_error( $terms ) ) {
            $branded++;
        }

        if ( as_product_is_hot( $id ) ) {
            $hot++;
        }

        foreach ( $meta_checks as $label => $key ) {
            $value = get_post_meta( $id, $key, true );
            $has   = is_array( $value ) ? ! empty( $value ) : ( '' !== trim( (string) $value ) );

            if ( $has ) {
                $counts[ $label ]++;
            }
        }
    }

    $rows = array( '品牌' => $branded, '主图' => $thumbs ) + $counts + array( '首页热门' => $hot );

    // 内容规模概览
    $post_counts = wp_count_posts( 'post' );
    $post_total  = isset( $post_counts->publish ) ? intval( $post_counts->publish ) : 0;

    $cat_terms   = get_terms( array( 'taxonomy' => 'plm_category', 'hide_empty' => false, 'fields' => 'ids' ) );
    $cat_total   = is_wp_error( $cat_terms ) ? 0 : count( $cat_terms );

    $brand_terms = get_terms( array( 'taxonomy' => 'plm_brand', 'hide_empty' => false, 'fields' => 'ids' ) );
    $brand_total = is_wp_error( $brand_terms ) ? 0 : count( $brand_terms );

    ob_start();
    ?>
    <div class="card" style="max-width:720px;padding:20px;margin-top:20px;">
        <h2>数据体检</h2>
        <p class="description" style="margin-bottom:6px;">
            已发布产品 <strong><?php echo intval( $total ); ?></strong> 个 ·
            文章 <strong><?php echo intval( $post_total ); ?></strong> 篇 ·
            品类 <strong><?php echo intval( $cat_total ); ?></strong> 个 ·
            品牌 <strong><?php echo intval( $brand_total ); ?></strong> 个
        </p>
        <p class="description">红色表示该属性在所有产品上都还是空的。</p>
        <table class="widefat striped" style="margin-top:12px;">
            <thead>
                <tr><th>属性</th><th style="width:110px;">已填写</th><th style="width:120px;">覆盖率</th></tr>
            </thead>
            <tbody>
            <?php foreach ( $rows as $label => $n ) :
                $pct = $total ? round( $n / $total * 100 ) : 0;
                $color = 100 === (int) $pct ? '#1e7e34' : ( $n > 0 ? '#b26a00' : '#c0392b' );
            ?>
                <tr>
                    <td><?php echo esc_html( $label ); ?></td>
                    <td><?php echo intval( $n ); ?> / <?php echo intval( $total ); ?></td>
                    <td style="color:<?php echo esc_attr( $color ); ?>;font-weight:600;"><?php echo intval( $pct ); ?>%</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description" style="margin-top:12px;">
            「主图」需要在「商城产品」里逐个设置特色图像，本主题的占位图只是兜底显示。<br>
            「首页热门」「关联文章分类」属于运营开关，覆盖率为 0 是正常的。
        </p>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * 清空演示产品与文章
 */
function as_clear_demo_data() {
    $count = array( 'products' => 0, 'posts' => 0 );

    // 删除产品
    $products = get_posts( array(
        'post_type'      => 'plm_product',
        'posts_per_page' => -1,
        'post_status'    => 'any',
        'fields'         => 'ids',
    ) );
    foreach ( $products as $id ) {
        wp_delete_post( $id, true );
        $count['products']++;
    }

    // 删除文章
    $posts = get_posts( array(
        'post_type'      => 'post',
        'posts_per_page' => -1,
        'post_status'    => 'any',
        'fields'         => 'ids',
    ) );
    foreach ( $posts as $id ) {
        wp_delete_post( $id, true );
        $count['posts']++;
    }

    return $count;
}
