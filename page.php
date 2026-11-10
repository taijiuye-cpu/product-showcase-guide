<?php get_header(); ?>

<?php while ( have_posts() ) : the_post(); ?>

<div class="as-container as-single-post">
    <?php as_breadcrumbs(); ?>

    <article class="as-post">
        <header class="as-post-header"><h1 class="as-post-heading"><?php the_title(); ?></h1></header>
        <div class="as-post-content"><?php the_content(); ?></div>
    </article>

    <?php if ( comments_open() || get_comments_number() ) : ?>
        <div class="as-comments"><?php comments_template(); ?></div>
    <?php endif; ?>
</div>

<?php endwhile; ?>

<?php get_footer(); ?>
