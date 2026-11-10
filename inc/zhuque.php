<?php
/**
 * 朱雀 AI 检测助手 · 后台集成
 *
 * 在文章编辑页提供「AI 检测」按钮，发布前把正文送去腾讯朱雀检测，
 * 返回「人工 / AI / 疑似 AI」三类占比 + 逐段标注。
 *
 * 接口：腾讯云 EdgeOne Makers 内置模型 @makers/zhuque-text
 *   POST https://ai-gateway.edgeone.link/v1/providers/zhuque-text/classify
 *   Header: Authorization: Bearer <API_KEY>
 *   Body:   { "text": "...", "is_merge": true }
 *
 * 密钥只在服务端（wp_options 或 wp-config.php 常量），不返回给浏览器。
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'AS_ZHUQUE_API', 'https://ai-gateway.edgeone.link/v1/providers/zhuque-text/classify' );
define( 'AS_ZHUQUE_MAX_CHARS', 100000 );

/**
 * 取 API Key：常量优先，其次后台设置
 */
function as_zhuque_key() {
    if ( defined( 'AS_ZHUQUE_API_KEY' ) && AS_ZHUQUE_API_KEY ) {
        return trim( (string) AS_ZHUQUE_API_KEY );
    }
    $opt = get_option( 'as_zhuque_key', '' );
    return is_string( $opt ) ? trim( $opt ) : '';
}

/**
 * 检测开关是否打开
 */
function as_zhuque_enabled() {
    if ( defined( 'AS_ZHUQUE_DISABLE' ) && AS_ZHUQUE_DISABLE ) {
        return false;
    }
    return '' !== as_zhuque_key();
}

/**
 * 调朱雀接口。返回 array 或 WP_Error
 *
 * @param string $text     待检测文本（纯文本）
 * @param bool   $is_merge 是否整篇合并评估
 */
function as_zhuque_detect( $text, $is_merge = true ) {
    $key = as_zhuque_key();
    if ( ! $key ) {
        return new WP_Error( 'as_zhuque_no_key', '还没有配置朱雀 API Key，请到「设置 → 朱雀 AI 检测」里填写。' );
    }

    $text = trim( (string) $text );
    if ( '' === $text ) {
        return new WP_Error( 'as_zhuque_empty', '内容为空，没有可检测的文字。' );
    }

    // 超长截断，避免单次请求过大（官方网页版限制 3000 字，API 按 token 计费）
    $truncated = false;
    if ( mb_strlen( $text, 'UTF-8' ) > AS_ZHUQUE_MAX_CHARS ) {
        $text      = mb_substr( $text, 0, AS_ZHUQUE_MAX_CHARS, 'UTF-8' );
        $truncated = true;
    }

    $resp = wp_remote_post(
        AS_ZHUQUE_API,
        array(
            'timeout' => 45,
            'headers' => array(
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode(
                array(
                    'text'     => $text,
                    'is_merge' => (bool) $is_merge,
                ),
                JSON_UNESCAPED_UNICODE
            ),
        )
    );

    if ( is_wp_error( $resp ) ) {
        return $resp;
    }

    $code = (int) wp_remote_retrieve_response_code( $resp );
    $body = (string) wp_remote_retrieve_body( $resp );
    $json = json_decode( $body, true );

    if ( 200 !== $code ) {
        // 错误响应可能是 {error:{message:...}} 或 {msg:...}
        $msg = '';
        if ( is_array( $json ) ) {
            if ( ! empty( $json['msg'] ) ) {
                $msg = (string) $json['msg'];
            } elseif ( ! empty( $json['error']['message'] ) ) {
                $msg = (string) $json['error']['message'];
            }
        }
        if ( '' === $msg ) {
            $msg = mb_substr( $body, 0, 200, 'UTF-8' );
        }

        if ( 401 === $code || 403 === $code ) {
            $msg = 'API Key 无效或没有权限（HTTP ' . $code . '）。请到「设置 → 朱雀 AI 检测」检查密钥是否填写正确。' . ( $msg ? ' 接口原文：' . $msg : '' );
        } else {
            $msg = '朱雀接口返回 HTTP ' . $code . '：' . $msg;
        }

        return new WP_Error( 'as_zhuque_http', $msg );
    }

    if ( ! is_array( $json ) || ! isset( $json['status'] ) || 'success' !== $json['status'] ) {
        $msg = is_array( $json ) && ! empty( $json['msg'] ) ? $json['msg'] : '返回格式异常';
        return new WP_Error( 'as_zhuque_bad', '检测失败：' . $msg );
    }

    $json['_truncated'] = $truncated;
    return $json;
}

/**
 * 把接口原始返回整理成给前端用的结构
 */
function as_zhuque_summarize( $raw ) {
    $ratio = isset( $raw['labels_ratio'] ) && is_array( $raw['labels_ratio'] ) ? $raw['labels_ratio'] : array();
    $human = isset( $ratio['0'] ) ? (float) $ratio['0'] : 0.0;
    $ai    = isset( $ratio['1'] ) ? (float) $ratio['1'] : 0.0;
    $maybe = isset( $ratio['2'] ) ? (float) $ratio['2'] : 0.0;

    $segments = array();
    if ( ! empty( $raw['segment_labels'] ) && is_array( $raw['segment_labels'] ) ) {
        foreach ( $raw['segment_labels'] as $s ) {
            $label = isset( $s['label'] ) ? (int) $s['label'] : 0;
            $segments[] = array(
                'text'  => isset( $s['text'] ) ? (string) $s['text'] : '',
                'label' => $label,
                'name'  => as_zhuque_label_name( $label ),
                'conf'  => isset( $s['conf'] ) ? (float) $s['conf'] : 0.0,
            );
        }
    }

    // 判定等级：以「AI + 疑似AI」合计为准
    $ai_like = $ai + $maybe;
    if ( $ai_like >= 0.8 ) {
        $level = 'high';
        $level_text = 'AI 痕迹较重，建议按标注段落人工改写';
    } elseif ( $ai_like >= 0.4 ) {
        $level = 'mid';
        $level_text = '存在一定 AI 痕迹，建议复核标黄段落';
    } elseif ( $ai_like >= 0.1 ) {
        $level = 'low';
        $level_text = 'AI 痕迹较轻，基本可放心发布';
    } else {
        $level = 'none';
        $level_text = '几乎无 AI 痕迹，可以放心发布';
    }

    return array(
        'human'      => $human,
        'ai'         => $ai,
        'maybe'      => $maybe,
        'ai_like'    => $ai_like,
        'softmax'    => isset( $raw['softmax_confidence'] ) ? (float) $raw['softmax_confidence'] : 0.0,
        'ratio_conf' => isset( $raw['ratio_confidence'] ) ? (float) $raw['ratio_confidence'] : 0.0,
        'segments'   => $segments,
        'level'      => $level,
        'level_text' => $level_text,
        'truncated'  => ! empty( $raw['_truncated'] ),
    );
}

function as_zhuque_label_name( $label ) {
    switch ( (int) $label ) {
        case 0:
            return '人工撰写';
        case 1:
            return 'AI 生成';
        case 2:
            return '疑似 AI';
        default:
            return '未知';
    }
}
