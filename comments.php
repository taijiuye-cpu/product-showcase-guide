<?php
/**
 * 评论模板
 * 主题没有 comments.php 时 WordPress 会回落到已弃用的 theme-compat 版本
 */
if ( post_password_required() ) {
    return;
}
?>
<div id="comments" class="as-comment-area">

    <?php if ( have_comments() ) : ?>
        <h2 class="as-comments-title">
            <?php
            $as_count = get_comments_number();
            printf( '评论（%s）', esc_html( number_format_i18n( $as_count ) ) );
            ?>
        </h2>

        <ol class="as-comment-list">
            <?php
            wp_list_comments( array(
                'style'       => 'ol',
                'short_ping'  => true,
                'avatar_size' => 44,
            ) );
            ?>
        </ol>

        <?php
        the_comments_pagination( array(
            'prev_text' => '‹ 上一页',
            'next_text' => '下一页 ›',
        ) );
        ?>
    <?php endif; ?>

    <?php if ( ! comments_open() && get_comments_number() ) : ?>
        <p class="as-comments-closed">评论已关闭。</p>
    <?php endif; ?>

    <?php
    comment_form( array(
        'title_reply'          => '发表评论',
        'label_submit'         => '提交评论',
        'comment_notes_before' => '<p class="as-comment-notes">邮箱地址不会被公开。</p>',
        'comment_field'        => '<p class="comment-form-comment"><label for="comment">评论内容</label><textarea id="comment" name="comment" cols="45" rows="6" required></textarea></p>',
    ) );
    ?>

</div>
