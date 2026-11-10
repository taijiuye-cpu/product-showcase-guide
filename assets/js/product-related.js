/**
 * 产品编辑页「关联文章」选择器（v2.62.0）
 *
 * 为什么要有它：商品详情页的「相关文章」现在是「手选优先 + 分类兜底」，
 * 手选要能搜索、能排序、能移除 —— 文章上百篇以后，一个 checkbox 列表没法用。
 *
 * 数据流：
 *   搜索  → 走 WordPress 自带 REST（/wp-json/wp/v2/posts?search=…），不新增接口
 *   已选  → DOM 里的 <li> 顺序就是展示顺序，每项带一个 hidden input（name=dg_related_posts[]）
 *   保存  → 交给 WP 原生的表单提交（没有额外 AJAX），失败不了、也不会和「更新」按钮抢
 *
 * 只选**已发布**的文章：草稿选了前台也不显示，干脆不给选。
 */
( function ( $ ) {
	'use strict';

	var cfg = window.asRelated || {};
	if ( ! cfg.restUrl ) {
		return;
	}

	var MAX = parseInt( cfg.max, 10 ) || 3;
	var i18n = cfg.i18n || {};

	/** HTML 实体（REST 的 title.rendered 带 &#8211; 这类）→ 纯文本 */
	function decode( html ) {
		var t = document.createElement( 'textarea' );
		t.innerHTML = html == null ? '' : String( html );
		return t.value;
	}

	/** 清空、上移、下移按钮的可用状态 + 计数 */
	function refresh( $box ) {
		var $items = $box.find( '.as-rel-items > li' );
		var n = $items.length;

		$box.find( '.as-rel-count' ).text( n );
		$box.toggleClass( 'is-full', n >= MAX );
		$box.find( '.as-rel-input' ).prop( 'disabled', n >= MAX );
		$box.find( '.as-rel-input' ).attr( 'placeholder', n >= MAX ? ( i18n.full || '已达上限' ) : ( i18n.ph || '输入标题关键词搜索文章…' ) );

		$items.each( function ( i ) {
			$( this ).find( '.as-rel-up' ).prop( 'disabled', i === 0 );
			$( this ).find( '.as-rel-down' ).prop( 'disabled', i === n - 1 );
		} );
	}

	function has( $box, id ) {
		return $box.find( '.as-rel-items > li[data-id="' + id + '"]' ).length > 0;
	}

	function add( $box, id, title ) {
		if ( has( $box, id ) || $box.find( '.as-rel-items > li' ).length >= MAX ) {
			return;
		}

		var $li = $( '<li class="as-rel-item"></li>' ).attr( 'data-id', id );

		$( '<span class="as-rel-ops"></span>' )
			.append( '<button type="button" class="as-rel-up" aria-label="上移">↑</button>' )
			.append( '<button type="button" class="as-rel-down" aria-label="下移">↓</button>' )
			.appendTo( $li );

		$( '<span class="as-rel-title"></span>' ).text( title ).appendTo( $li );
		$( '<button type="button" class="as-rel-del" aria-label="移除">×</button>' ).appendTo( $li );
		$( '<input type="hidden">' ).attr( 'name', 'dg_related_posts[]' ).val( id ).appendTo( $li );

		$box.find( '.as-rel-items' ).append( $li );
		refresh( $box );
	}

	function renderResults( $box, posts, kw ) {
		var $res = $box.find( '.as-rel-results' ).empty().prop( 'hidden', false );

		if ( ! posts.length ) {
			$( '<div class="as-rel-empty"></div>' ).text( i18n.empty || '没有找到已发布的文章' ).appendTo( $res );
			return;
		}

		posts.forEach( function ( p ) {
			var taken  = has( $box, p.id );
			var full   = $box.find( '.as-rel-items > li' ).length >= MAX;
			var $btn   = $( '<button type="button"></button>' )
				.attr( 'data-id', p.id )
				.attr( 'data-title', decode( p.title && p.title.rendered ) )
				.prop( 'disabled', taken || full )
				.text( decode( p.title && p.title.rendered ) + ( taken ? '（已选）' : '' ) );

			$res.append( $btn );
		} );
	}

	function search( $box ) {
		var $input = $box.find( '.as-rel-input' );
		var kw     = $.trim( $input.val() );
		var $res   = $box.find( '.as-rel-results' );

		$res.empty().prop( 'hidden', false );
		$( '<div class="as-rel-empty"></div>' ).text( i18n.searching || '搜索中…' ).appendTo( $res );

		var url = cfg.restUrl + '?per_page=20&orderby=date&order=desc&_fields=id,title';
		if ( kw ) {
			url += '&search=' + encodeURIComponent( kw );
		}

		$.getJSON( url )
			.done( function ( posts ) {
				renderResults( $box, posts || [], kw );
			} )
			.fail( function () {
				$res.empty();
				$( '<div class="as-rel-empty"></div>' ).text( '搜索失败，请稍后重试' ).appendTo( $res );
			} );
	}

	$( function () {
		document.addEventListener( 'click', function ( e ) {
			if ( ! $( e.target ).closest( '.as-rel-search' ).length ) {
				$( '.as-rel-results' ).prop( 'hidden', true );
			}
		} );

		var timer = null;

		$( document )
			.on( 'focus', '.as-rel-input', function () {
				search( $( this ).closest( '.as-rel-box' ) );
			} )
			.on( 'input', '.as-rel-input', function () {
				var $box = $( this ).closest( '.as-rel-box' );
				window.clearTimeout( timer );
				timer = window.setTimeout( function () {
					search( $box );
				}, 250 );
			} )
			.on( 'keydown', '.as-rel-input', function ( e ) {
				if ( e.key !== 'Enter' && e.keyCode !== 13 ) {
					return;
				}
				e.preventDefault();
				$( this ).closest( '.as-rel-box' ).find( '.as-rel-results button:not(:disabled)').first().trigger( 'click' );
			} )
			// 选中一篇
			.on( 'click', '.as-rel-results button', function () {
				var $box = $( this ).closest( '.as-rel-box' );
				add( $box, parseInt( $( this ).attr( 'data-id' ), 10 ), $( this ).attr( 'data-title' ) );
				$box.find( '.as-rel-input' ).val( '' );
				search( $box );
			} )
			// 移除
			.on( 'click', '.as-rel-del', function () {
				var $box = $( this ).closest( '.as-rel-box' );
				$( this ).closest( '.as-rel-item' ).remove();
				refresh( $box );
			} )
			// 上移 / 下移：直接挪 DOM，hidden input 跟着走，顺序即展示顺序
			.on( 'click', '.as-rel-up', function () {
				var $item = $( this ).closest( '.as-rel-item' );
				$item.prev( '.as-rel-item' ).before( $item );
				refresh( $( this ).closest( '.as-rel-box' ) );
			} )
			.on( 'click', '.as-rel-down', function () {
				var $item = $( this ).closest( '.as-rel-item' );
				$item.next( '.as-rel-item' ).after( $item );
				refresh( $( this ).closest( '.as-rel-box' ) );
			} );

		$( '.as-rel-box' ).each( function () {
			refresh( $( this ) );
		} );
	} );
} )( jQuery );
