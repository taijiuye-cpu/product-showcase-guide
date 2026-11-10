<?php
/**
 * 朱雀 AI 检测助手 · 后台界面
 *
 * 1. 文章编辑页右下角「AI 检测」面板（发布前一键检测）
 * 2. 设置 → 朱雀 AI 检测（填 API Key、查看用量提示）
 * 3. AJAX 接口 as_zhuque_check（服务端调朱雀，密钥不外泄）
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* 朱雀接口与函数定义在 inc/zhuque.php，必须已加载 */
if ( ! function_exists( 'as_zhuque_detect' ) ) {
    return;
}

/* ================= 编辑器面板 ================= */

add_action( 'add_meta_boxes', 'as_zhuque_add_meta_box' );
function as_zhuque_add_meta_box() {
    add_meta_box(
        'as-zhuque-box',
        'AI 检测（朱雀）',
        'as_zhuque_render_meta_box',
        'post',
        'side',
        'high'
    );
}

function as_zhuque_render_meta_box( $post ) {
    wp_nonce_field( 'as_zhuque_check', 'as_zhuque_nonce' );
    $enabled = as_zhuque_enabled();
    ?>
    <div id="as-zhuque-wrap">
        <?php if ( ! $enabled ) : ?>
            <p style="margin:0 0 8px;color:#8a9099;font-size:12px;line-height:1.6">
                还没配置 API Key。<br>
                到「设置 → 朱雀 AI 检测」填入腾讯云 EdgeOne Makers 的 API Key 后即可使用。
            </p>
            <a class="button button-secondary" href="<?php echo esc_url( admin_url( 'options-general.php?page=as-zhuque' ) ); ?>">去配置</a>
        <?php else : ?>
            <p style="margin:0 0 10px;color:#646970;font-size:12px;line-height:1.6">
                发布前检测正文的 AI 生成痕迹。检测的是<strong>当前编辑区里的内容</strong>，请先保存草稿。
            </p>
            <button type="button" class="button button-primary" id="as-zhuque-run" style="width:100%">
                检测 AI 痕迹
            </button>
            <div id="as-zhuque-result" style="margin-top:10px"></div>
        <?php endif; ?>
    </div>

    <style>
        #as-zhuque-result .zq-bar{display:flex;height:10px;border-radius:5px;overflow:hidden;background:#f0f0f1;margin:8px 0 6px}
        #as-zhuque-result .zq-bar i{display:block;height:100%}
        #as-zhuque-result .zq-legend{font-size:11px;color:#646970;line-height:1.8}
        #as-zhuque-result .zq-legend span{display:inline-block;width:8px;height:8px;border-radius:2px;margin-right:4px}
        #as-zhuque-result .zq-verdict{font-size:12px;font-weight:600;margin:8px 0 0;padding:7px 9px;border-radius:4px;line-height:1.5}
        #as-zhuque-result .zq-seg{font-size:12px;line-height:1.6;border-left:3px solid #dcdcde;padding:4px 0 4px 8px;margin:6px 0;max-height:150px;overflow:auto;color:#50575e}
        #as-zhuque-result .zq-seg.s1{border-left-color:#d63638;background:#fcf0f1}
        #as-zhuque-result .zq-seg.s2{border-left-color:#dba617;background:#fcf9e8}
        #as-zhuque-result .zq-seg.s0{border-left-color:#00a32a}
        #as-zhuque-result .zq-note{font-size:11px;color:#8a9099;margin-top:8px;line-height:1.6}
        #as-zhuque-result .zq-err{color:#d63638;font-size:12px;line-height:1.6}
    </style>

    <script>
    (function () {
        var btn = document.getElementById('as-zhuque-run');
        if (!btn) { return; }

        function esc(s) {
            return String(s || '').replace(/[&<>"]/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
            });
        }

        function pct(n) { return Math.round(n * 100) + '%'; }

        function render(data) {
            var box = document.getElementById('as-zhuque-result');
            var colors = { 0: '#00a32a', 1: '#d63638', 2: '#dba617' };
            var h = '';

            h += '<div class="zq-bar">';
            if (data.human > 0) { h += '<i style="width:' + (data.human * 100) + '%;background:' + colors[0] + '"></i>'; }
            if (data.ai    > 0) { h += '<i style="width:' + (data.ai * 100) + '%;background:' + colors[1] + '"></i>'; }
            if (data.maybe > 0) { h += '<i style="width:' + (data.maybe * 100) + '%;background:' + colors[2] + '"></i>'; }
            h += '</div>';

            h += '<div class="zq-legend">'
               + '<span style="background:' + colors[0] + '"></span>人工 ' + pct(data.human) + '<br>'
               + '<span style="background:' + colors[1] + '"></span>AI 生成 ' + pct(data.ai) + '<br>'
               + '<span style="background:' + colors[2] + '"></span>疑似 AI ' + pct(data.maybe)
               + '</div>';

            var bg = { high: '#fcf0f1', mid: '#fcf9e8', low: '#f0f6fc', none: '#edfaef' };
            var fg = { high: '#d63638', mid: '#996800', low: '#0969a5', none: '#007017' };
            h += '<p class="zq-verdict" style="background:' + bg[data.level] + ';color:' + fg[data.level] + '">'
               + esc(data.level_text) + '</p>';

            if (data.segments && data.segments.length) {
                var flagged = data.segments.filter(function (s) { return s.label !== 0; });
                if (flagged.length) {
                    h += '<p style="font-size:11px;color:#646970;margin:10px 0 4px">需要复核的段落（' + flagged.length + '）：</p>';
                    flagged.forEach(function (s) {
                        h += '<div class="zq-seg s' + s.label + '"><strong>' + esc(s.name) + ' · ' + pct(s.conf) + '</strong><br>' + esc(s.text) + '</div>';
                    });
                } else {
                    h += '<p style="font-size:11px;color:#007017;margin:10px 0 0">没有需要特别复核的段落。</p>';
                }
            }

            if (data.truncated) {
                h += '<p class="zq-note">内容过长，已截取前部分送检。</p>';
            }
            h += '<p class="zq-note">检测结果仅供参考。AI 检测是概率判断，规范严谨的原创内容也可能被标为疑似 AI。</p>';

            box.innerHTML = h;
        }

        btn.addEventListener('click', function () {
            var box = document.getElementById('as-zhuque-result');

            var content = '';
            if (window.tinymce && tinymce.get('content') && !tinymce.get('content').isHidden()) {
                content = tinymce.get('content').getContent();
            } else {
                var ta = document.getElementById('content');
                content = ta ? ta.value : '';
            }
            content = content.replace(/<[^>]*>/g, ' ').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim();

            if (content.length < 20) {
                box.innerHTML = '<p class="zq-err">内容太短（少于 20 字），先写点东西再检测。</p>';
                return;
            }

            btn.disabled = true;
            btn.textContent = '检测中…（约 10 秒）';
            box.innerHTML = '';

            var body = new FormData();
            body.append('action', 'as_zhuque_check');
            body.append('nonce', document.getElementById('as_zhuque_nonce').value);
            body.append('post_id', document.getElementById('post_ID').value);
            body.append('text', content);

            fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res && res.success && res.data) {
                        render(res.data);
                    } else {
                        box.innerHTML = '<p class="zq-err">' + esc((res && res.data && res.data.message) || '检测失败，请稍后重试。') + '</p>';
                    }
                })
                .catch(function () {
                    box.innerHTML = '<p class="zq-err">请求失败，请检查网络或服务器能否访问外网。</p>';
                })
                .finally(function () {
                    btn.disabled = false;
                    btn.textContent = '重新检测';
                });
        });
    })();
    </script>
    <?php
}

/* ================= AJAX 接口 ================= */

add_action( 'wp_ajax_as_zhuque_check', 'as_zhuque_ajax_check' );
function as_zhuque_ajax_check() {
    check_ajax_referer( 'as_zhuque_check', 'nonce' );

    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( array( 'message' => '没有权限。' ), 403 );
    }

    $text = isset( $_POST['text'] ) ? wp_unslash( (string) $_POST['text'] ) : '';
    $text = trim( preg_replace( '/\s+/u', ' ', $text ) );

    if ( mb_strlen( $text, 'UTF-8' ) < 20 ) {
        wp_send_json_error( array( 'message' => '内容太短（少于 20 字）。' ) );
    }

    $raw = as_zhuque_detect( $text, true );
    if ( is_wp_error( $raw ) ) {
        wp_send_json_error( array( 'message' => $raw->get_error_message() ) );
    }

    $data = as_zhuque_summarize( $raw );

    // 记录最近一次检测结果，供文章列表显示徽标
    $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
    if ( $post_id && current_user_can( 'edit_post', $post_id ) ) {
        update_post_meta(
            $post_id,
            '_as_zhuque_last',
            array(
                'time'    => current_time( 'mysql' ),
                'ai_like' => round( $data['ai_like'], 4 ),
                'level'   => $data['level'],
            )
        );
    }

    wp_send_json_success( $data );
}

/* ================= 设置页 ================= */

add_action( 'admin_menu', 'as_zhuque_admin_menu' );
function as_zhuque_admin_menu() {
    add_options_page(
        '朱雀 AI 检测',
        '朱雀 AI 检测',
        'manage_options',
        'as-zhuque',
        'as_zhuque_settings_page'
    );
}

add_action( 'admin_init', 'as_zhuque_register_settings' );
function as_zhuque_register_settings() {
    register_setting( 'as_zhuque_group', 'as_zhuque_key', array(
        'type'              => 'string',
        'sanitize_callback' => 'as_zhuque_sanitize_key',
        'default'           => '',
    ) );
}

function as_zhuque_sanitize_key( $v ) {
    return trim( sanitize_text_field( (string) $v ) );
}

/**
 * 读取最近一次连通性测试记录
 * 结构：array( 'time' => 'Y-m-d H:i:s', 'ok' => bool, 'msg' => string )
 */
function as_zhuque_last_test() {
    $t = get_option( 'as_zhuque_last_test', array() );
    return is_array( $t ) ? $t : array();
}

/**
 * 跑一次连通性测试并落库。返回同结构数组。
 * 用一段中性的普通中文文本，避免污染统计。
 */
function as_zhuque_run_test() {
    $raw = as_zhuque_detect(
        '这是一段用于测试朱雀接口连通性的普通中文文本，说完就删，不用于任何正式内容。',
        true
    );

    if ( is_wp_error( $raw ) ) {
        $rec = array(
            'time' => current_time( 'mysql' ),
            'ok'   => false,
            'msg'  => $raw->get_error_message(),
        );
    } else {
        $sum = as_zhuque_summarize( $raw );
        $rec = array(
            'time' => current_time( 'mysql' ),
            'ok'   => true,
            'msg'  => sprintf(
                '接口连通。示例返回：人工 %d%% / AI %d%% / 疑似 %d%%',
                round( $sum['human'] * 100 ),
                round( $sum['ai'] * 100 ),
                round( $sum['maybe'] * 100 )
            ),
        );
    }

    update_option( 'as_zhuque_last_test', $rec, false );
    return $rec;
}

/**
 * 「保存并测试连接」处理器
 * 先按普通设置保存（options.php 负责），这里只在同一请求里补一次连通性测试。
 */
add_action( 'admin_init', 'as_zhuque_maybe_run_test' );
function as_zhuque_maybe_run_test() {
    if ( ! isset( $_POST['as_zhuque_test'] ) || '1' !== $_POST['as_zhuque_test'] ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    check_admin_referer( 'as_zhuque_group-options' );

    as_zhuque_run_test();

    // 回到设置页（带个标记，方便显示「刚刚测试过」）
    wp_safe_redirect( admin_url( 'options-general.php?page=as-zhuque&as_tested=1' ) );
    exit;
}

function as_zhuque_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $last    = as_zhuque_last_test();
    $has_key = as_zhuque_enabled() || '' !== as_zhuque_key();
    // 手动点「仅测试」时（?as_test=1），不保存设置，只测连通性
    if ( isset( $_GET['as_test'], $_GET['_wpnonce'] ) && '1' === $_GET['as_test']
        && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'as_zhuque_test_now' ) ) {
        $last = as_zhuque_run_test();
    }
    ?>
    <div class="wrap">
        <h1>朱雀 AI 检测</h1>
        <p style="max-width:760px;color:#50575e;line-height:1.7">
            发文章前自检 AI 生成痕迹。检测由<strong>腾讯朱雀（朱雀实验室 / 混元安全团队）</strong>提供，
            通过腾讯云 EdgeOne Makers 的内置模型 <code>@makers/zhuque-text</code> 调用。
        </p>

        <!-- 连接状态条 -->
        <div id="as-zhuque-status" style="max-width:760px;margin:14px 0 18px;padding:11px 14px;border-radius:4px;border-left:4px solid #c3c4c7;background:#f6f7f7;line-height:1.7">
            <?php if ( ! $has_key ) : ?>
                <strong style="color:#50575e">尚未配置 API Key</strong>
                <div style="font-size:12px;color:#646970;margin-top:2px">填入 Key 并保存后，即可点右下「测试连接」验证是否可用。</div>
            <?php elseif ( empty( $last ) ) : ?>
                <strong style="color:#996800">已填 Key，但还没测试过连接</strong>
                <div style="font-size:12px;color:#646970;margin-top:2px">点下面的「测试连接」按钮跑一次，确认服务器能访问朱雀接口。</div>
            <?php elseif ( ! empty( $last['ok'] ) ) : ?>
                <strong style="color:#007017">✔ 连接正常</strong>
                <span style="font-size:12px;color:#646970">（上次测试：<?php echo esc_html( $last['time'] ); ?>）</span>
                <div style="font-size:12px;color:#50575e;margin-top:2px"><?php echo esc_html( $last['msg'] ); ?></div>
            <?php else : ?>
                <strong style="color:#d63638">✘ 连接失败</strong>
                <span style="font-size:12px;color:#646970">（上次测试：<?php echo esc_html( $last['time'] ); ?>）</span>
                <div style="font-size:12px;color:#b32d2e;margin-top:2px"><?php echo esc_html( $last['msg'] ); ?></div>
            <?php endif; ?>
        </div>

        <form method="post" action="options.php">
            <?php settings_fields( 'as_zhuque_group' ); ?>
            <input type="hidden" name="as_zhuque_test" id="as_zhuque_test_flag" value="0">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="as_zhuque_key">API Key</label></th>
                    <td>
                        <?php if ( defined( 'AS_ZHUQUE_API_KEY' ) && AS_ZHUQUE_API_KEY ) : ?>
                            <p style="margin:0">
                                <em>已在 <code>wp-config.php</code> 里用常量 <code>AS_ZHUQUE_API_KEY</code> 指定，此处的输入框不生效（常量优先）。</em>
                            </p>
                            <p style="margin:8px 0 0">
                                <a class="button button-secondary"
                                   href="<?php echo esc_url( wp_nonce_url( admin_url( 'options-general.php?page=as-zhuque&as_test=1' ), 'as_zhuque_test_now' ) ); ?>">
                                    测试连接
                                </a>
                                <span style="font-size:12px;color:#646970;margin-left:6px">立即测一次服务器到朱雀接口的连通性</span>
                            </p>
                        <?php else : ?>
                            <input type="password" name="as_zhuque_key" id="as_zhuque_key"
                                   value="<?php echo esc_attr( get_option( 'as_zhuque_key', '' ) ); ?>"
                                   class="regular-text" autocomplete="off" placeholder="粘贴 EdgeOne API Key">
                            <p class="description">
                                到 <a href="https://console.cloud.tencent.com/edgeone/makers?tab=models&subTab=apikey" target="_blank" rel="noopener">腾讯云 EdgeOne 控制台 → Makers → 模型 → API Key</a> 创建一个。
                                每月 <strong>50 万 tokens 免费</strong>。
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <p class="submit" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <button type="submit" class="button button-primary" onclick="document.getElementById('as_zhuque_test_flag').value='0'">保存</button>

                <?php if ( ! defined( 'AS_ZHUQUE_API_KEY' ) || ! AS_ZHUQUE_API_KEY ) : ?>
                    <button type="submit" class="button button-secondary"
                            onclick="document.getElementById('as_zhuque_test_flag').value='1'">保存并测试连接</button>
                    <span id="as-zhuque-hint" style="font-size:12px;color:#646970"></span>
                <?php endif; ?>
            </p>
        </form>

        <?php if ( isset( $_GET['as_tested'] ) ) : ?>
            <div class="notice notice-info inline" style="margin:12px 0">
                <p>已保存设置并完成一次连通性测试，结果见上方状态条。</p>
            </div>
        <?php endif; ?>

        <hr style="margin:24px 0">

        <h2 style="font-size:14px">怎么用</h2>
        <ol style="max-width:760px;color:#50575e;line-height:1.9;font-size:13px">
            <li>在上面填入 API Key 并保存。</li>
            <li>打开任意文章编辑页，右侧栏会出现「AI 检测（朱雀）」面板。</li>
            <li><strong>先保存草稿</strong>（检测的是编辑器里的当前内容），再点「检测 AI 痕迹」。</li>
            <li>看结果：三类占比 + 需要复核的段落（标黄为「疑似 AI」、标红为「AI 生成」）。</li>
        </ol>

        <h2 style="font-size:14px;margin-top:22px">重要说明</h2>
        <ul style="max-width:760px;color:#50575e;line-height:1.9;font-size:13px;list-style:disc;padding-left:20px">
            <li><strong>结果仅供参考。</strong>朱雀是概率判断（官方称准确率约 90%，误判率约 10~15%），完全手写的原创内容也可能被标为疑似 AI。</li>
            <li>它检测的是<strong>写作特征</strong>（用词可预测性、句式均匀度、段落过渡等），不是查重，也不判断内容对错。</li>
            <li>密钥只存在服务器，不会返回给浏览器，也不会进小程序。</li>
            <li>检测会把正文发往腾讯云接口。<strong>未发布的内容也属于对外传输</strong>，介意的话先自行脱敏。</li>
        </ul>

        <p style="margin-top:24px;color:#8a9099;font-size:12px">
            <?php if ( defined( 'AS_ZHUQUE_DISABLE' ) && AS_ZHUQUE_DISABLE ) : ?>
                注意：当前 <code>wp-config.php</code> 里设置了 <code>AS_ZHUQUE_DISABLE</code>，功能处于关闭状态。
            <?php else : ?>
                想临时关掉：在 <code>wp-config.php</code> 加 <code>define('AS_ZHUQUE_DISABLE', true);</code>
            <?php endif; ?>
        </p>
    </div>
    <?php
}
