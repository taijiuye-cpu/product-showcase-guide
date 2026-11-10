<form role="search" method="get" class="as-searchform" action="<?php echo esc_url( home_url( '/' ) ); ?>">
    <input type="search" name="s" placeholder="搜索产品或文章…" value="<?php echo get_search_query(); ?>">
    <button type="submit">搜索</button>
</form>