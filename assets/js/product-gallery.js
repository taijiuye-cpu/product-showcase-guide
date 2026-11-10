/**
 * 商品图集 —— 后台产品编辑页交互
 *
 * 职责：
 *   - 「添加图片」打开 wp.media 多选，选中的挂到当前产品
 *   - 移除（只解绑，不删媒体库原图）
 *   - 前移 / 后移调整展示顺序
 *   - 设为主图（写入特色图像）
 *   - 清空图集
 *
 * 存储约定（与 functions.php 里的 AJAX 对齐）：
 *   图集本身 = 附件的 post_parent 指向产品（WordPress 原生机制）
 *   顺序     = post meta as_gallery_order 存附件 ID 数组
 * 前端只传附件 ID 数组，不上传文件。
 *
 * 🔴 两个踩过的坑，别再犯：
 *   1. 取选中项**不能写** `.map(fn).get()` —— 集合的 map() 返回普通数组，
 *      数组没有 .get()，会在 select 回调里抛 TypeError，表现为
 *      「图能选中、弹窗关闭、图集没变化、也不报任何错」（AJAX 压根没发出）。
 *      正确写法见 collectIds()。
 *   2. 操作完**不要 `window.location.reload()`** —— 会丢掉未保存的标题/正文，
 *      还可能被 beforeunload 拦下。改为把服务端返回的新 HTML 局部替换。
 *   3. 改完主图**必须同步回 WP 表单里的 `_thumbnail_id`**（见 syncFeatured）——
 *      WP 那个隐藏字段是页面渲染时的快照，不更新它，用户点「更新」提交表单时
 *      wp_insert_post() 会拿旧值把刚设好的主图覆盖掉（-1 = 删除）。
 *      2026-10-09 用户报的「设完主图、点更新刷新后主图没被标记」就是这个。
 */
( function ( $ ) {
	'use strict';

	var cfg = window.asGallery || {};

	/**
	 * 从媒体的 selection 集合里取附件 ID。
	 *
	 * 兼容多种返回形态（Backbone Collection / 数组 / 单个 model），
	 * 逐项读取，绝不假设集合上还有链式方法。
	 *
	 * @param {Object} selection wp.media 的 selection。
	 * @return {Array<number>} 附件 ID 数组。
	 */
	function collectIds( selection ) {
		var ids = [];
		if ( ! selection ) {
			return ids;
		}

		try {
			// 标准形态：Backbone Collection，有 each()
			if ( typeof selection.each === 'function' ) {
				selection.each( function ( model ) {
					var id = model && model.id ? parseInt( model.id, 10 ) : 0;
					if ( id > 0 ) {
						ids.push( id );
					}
				} );
				return ids;
			}

			// 退路：普通数组
			if ( selection.length !== undefined ) {
				for ( var i = 0; i < selection.length; i++ ) {
					var item = selection[ i ];
					var id2 = item && item.id ? parseInt( item.id, 10 ) : parseInt( item, 10 );
					if ( id2 > 0 ) {
						ids.push( id2 );
					}
				}
			}
		} catch ( e ) {
			if ( window.console && console.warn ) {
				console.warn( '[图集] 解析选中项失败', e );
			}
		}

		return ids;
	}

	/**
	 * 识别当前操作的图集容器。
	 */
	function findWrap( $el ) {
		return $el.closest( '.as-gallery' );
	}

	/**
	 * 取产品 ID：以 DOM 上的 data-post 为准。
	 *
	 * 不用 cfg.postId —— wp_localize_script 在 admin_enqueue_scripts 阶段执行，
	 * 那时主循环还没建立，get_the_ID() 可能为 0。
	 */
	function postId( $wrap ) {
		var pid = 0;
		if ( $wrap && $wrap.length ) {
			pid = parseInt( $wrap.attr( 'data-post' ), 10 ) || 0;
		}
		if ( ! pid && cfg.postId ) {
			pid = parseInt( cfg.postId, 10 ) || 0;
		}
		return pid;
	}

	/**
	 * 发一个图集 AJAX 请求。
	 *
	 * dataType 显式指定 json：服务端若返回非 JSON（如 nonce 失败时的 `-1`），
	 * jQuery 会走 fail 分支，把原始响应暴露出来，而不是被当成成功静默刷新。
	 */
	/**
	 * 把「当前主图」同步回 WordPress 原生的特色图像控件。
	 *
	 * 🔴 为什么必须有这个函数（v2.61.1 修的 bug）：
	 *   WP 的编辑表单里有个隐藏字段 `_thumbnail_id`，值是**页面渲染那一刻**的快照
	 *   （没有特色图像时是 `-1`）。图集这边改主图走的是 AJAX，改完数据库对了，
	 *   但那个隐藏字段还是旧值 —— 用户一点「更新」提交表单，wp_insert_post()
	 *   就会拿旧值把刚设好的主图覆盖回去（`-1` 等于删除）。
	 *   表现就是：设完主图看着没问题，一刷新（点更新）主图就没了。
	 *
	 * @param {number} id 特色图像附件 ID；0 / 负数 = 没有主图。
	 */
	function syncFeatured( id ) {
		id = parseInt( id, 10 );
		if ( isNaN( id ) ) {
			return;
		}
		if ( id <= 0 ) {
			id = -1; // WP 约定：-1 = 没有特色图像
		}

		// ① 先把隐藏字段写对 —— 这一步才真正决定「点更新后主图还在不在」
		var $input = $( '#_thumbnail_id, input[name="_thumbnail_id"]' );
		if ( $input.length ) {
			$input.val( String( id ) );
		}

		// ② 再用 WP 自己的 API 刷一遍特色图像面板（它会连隐藏字段一起换新）
		//    媒体设置里没有 post 上下文时不调用，免得把面板换成空内容
		try {
			var settings = window.wp && wp.media && wp.media.view && wp.media.view.settings;
			if ( settings && settings.post && settings.post.id &&
				wp.media.featuredImage && typeof wp.media.featuredImage.set === 'function' ) {
				wp.media.featuredImage.set( id );
			}
		} catch ( e ) {
			// WP 改版导致接口变化也无所谓：① 已经保证数据不会被覆盖
			if ( window.console && console.warn ) {
				console.warn( '[图集] 同步特色图像面板失败（数据已安全）', e );
			}
		}
	}

	function req( $wrap, action, data, onDone ) {
		var pid = postId( $wrap );
		if ( ! pid ) {
			window.alert( '取不到产品 ID，请刷新页面后重试。' );
			return;
		}

		var $block = $wrap.closest( '.as-gallery-block' ).length ? $wrap.closest( '.as-gallery-block' ) : $wrap;
		$wrap.addClass( 'as-gallery-busy' );

		$.ajax( {
			url: cfg.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: $.extend( {
				action: action,
				nonce: cfg.nonce,
				post_id: pid
			}, data || {} )
		} )
			.done( function ( res ) {
				$wrap.removeClass( 'as-gallery-busy' );

				if ( ! res || res.success !== true ) {
					window.alert( ( res && res.data && ( res.data.msg || res.data ) ) || '操作失败' );
					return;
				}

				// 服务端返回最新的图集 HTML，局部替换，不重载页面
				if ( res.data && res.data.html ) {
					$block.replaceWith( res.data.html );
				}

				// 主图可能被这次操作改了（设为主图 / 移除的正好是主图）→ 同步回表单字段
				if ( res.data && res.data.thumb !== undefined ) {
					syncFeatured( res.data.thumb );
				}
				if ( typeof onDone === 'function' ) {
					onDone( res );
				}
			} )
			.fail( function ( xhr, status ) {
				$wrap.removeClass( 'as-gallery-busy' );

				var detail;
				if ( xhr.status === 403 ) {
					detail = '权限不足：当前账号不能编辑这个产品。';
				} else if ( xhr.status === 400 ) {
					detail = '请求被拒绝（多为产品 ID 缺失）。';
				} else if ( status === 'parsererror' ) {
					// 非 JSON 响应：把原文摆出来，别让它变成"什么都没发生"
					detail = '服务端返回了非 JSON 内容：\n' + String( xhr.responseText || '' ).slice( 0, 200 );
				} else {
					detail = 'HTTP ' + xhr.status + ' ' + ( xhr.responseText || '' ).slice( 0, 160 );
				}
				window.alert( '图集操作失败：\n' + detail );
			} );
	}

	/** 打开媒体库选图 */
	function openPicker( $wrap ) {
		if ( ! window.wp || ! wp.media ) {
			window.alert( '媒体库组件未加载。请按 Ctrl+F5 强制刷新页面后重试。' );
			return;
		}

		// 每次点击都新建 frame：避免多次打开后 selection 累积旧选择，
		// 也避免复用 frame 时闭包捕获到上一次的容器。
		var frame = wp.media( {
			title: '选择图集图片',
			button: { text: '加入图集' },
			library: { type: 'image' },
			multiple: 'add'
		} );

		frame.on( 'select', function () {
			var ids = collectIds( frame.state().get( 'selection' ) );

			if ( ! ids.length ) {
				window.alert( '没有读取到选中的图片，请重新选择一次。' );
				return;
			}

			req( $wrap, 'as_gallery_attach', { ids: ids } );
		} );

		frame.open();
	}

	$( function () {

		// 添加图片
		$( document ).on( 'click', '.as-gallery-add', function ( e ) {
			e.preventDefault();
			openPicker( findWrap( $( this ) ) );
		} );

		// 从图集移除（只解绑，不动封面）
		$( document ).on( 'click', '.as-gallery-del', function ( e ) {
			e.preventDefault();
			var $item = $( this ).closest( '.as-gallery-item' );
			var $wrap = findWrap( $( this ) );

			// 封面（特色图像）与图集是两个概念：移除图集不改封面。
			var msg = $item.hasClass( 'is-main' )
				? '这是当前主图（封面）。移除后它不再出现在图集里，但仍是封面（小程序列表图）。要换封面，请点其他图上的「主图」。确定移除？'
				: ( cfg.confirmDel || '把这张图从图集移除？' );
			if ( ! window.confirm( msg ) ) {
				return;
			}

			var id = parseInt( $item.data( 'id' ), 10 );
			req( $wrap, 'as_gallery_detach', { attachment_id: id } );
		} );

		// 设为主图
		$( document ).on( 'click', '.as-gallery-main', function ( e ) {
			e.preventDefault();
			var $wrap = findWrap( $( this ) );
			var id = parseInt( $( this ).closest( '.as-gallery-item' ).data( 'id' ), 10 );
			req( $wrap, 'as_gallery_set_main', { attachment_id: id } );
		} );

		// 前移
		$( document ).on( 'click', '.as-gallery-prev', function ( e ) {
			e.preventDefault();
			var $item = $( this ).closest( '.as-gallery-item' );
			var $prev = $item.prev( '.as-gallery-item' );
			if ( ! $prev.length ) {
				return;
			}
			var $wrap = findWrap( $( this ) );
			$item.insertBefore( $prev );
			req( $wrap, 'as_gallery_save_order', { order: order( $wrap ) } );
		} );

		// 后移
		$( document ).on( 'click', '.as-gallery-next', function ( e ) {
			e.preventDefault();
			var $item = $( this ).closest( '.as-gallery-item' );
			var $next = $item.next( '.as-gallery-item' );
			if ( ! $next.length ) {
				return;
			}
			var $wrap = findWrap( $( this ) );
			$item.insertAfter( $next );
			req( $wrap, 'as_gallery_save_order', { order: order( $wrap ) } );
		} );

		// 清空
		$( document ).on( 'click', '.as-gallery-clear', function ( e ) {
			e.preventDefault();
			if ( ! window.confirm( cfg.confirmClear || '清空图集？主图不受影响，媒体库原图不会被删除。' ) ) {
				return;
			}
			req( findWrap( $( this ) ), 'as_gallery_clear', {} );
		} );

		/** 收集当前 DOM 里的顺序 */
		function order( $wrap ) {
			var list = [];
			$wrap.find( '.as-gallery-item' ).each( function () {
				var id = parseInt( $( this ).data( 'id' ), 10 );
				if ( id > 0 ) {
					list.push( id );
				}
			} );
			return list;
		}
	} );

} )( jQuery );
