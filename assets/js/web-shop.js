/**
 * 网站商城前端交互（v2.87.25）
 *
 * 两件事：
 *   1. 支付状态轮询 —— 结算页显示二维码时，每 3 秒问一次订单状态，
 *      付完自动刷新成「支付成功」。
 *   2. 支付宝收银台自动提交 —— 支付宝返回的是一段 <form>，页面要自动 POST 过去。
 *
 * 无依赖（不引 jQuery）。
 */
( function () {
	'use strict';

	/* ---------- 1. 支付状态轮询 ---------- */
	var wait = document.querySelector( '[data-as-order]' );
	if ( wait ) {
		var orderNo = wait.getAttribute( 'data-as-order' );
		var statusUrl = wait.getAttribute( 'data-as-status' ) || ( '/order/?no=' + encodeURIComponent( orderNo ) + '&json=1' );
		var ticking = false;

		function poll() {
			if ( ticking ) { return; }
			ticking = true;
			fetch( statusUrl, { credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( d ) {
					ticking = false;
					if ( d && d.paid ) {
						// 已付款 → 刷新到成功态
						window.location.reload();
						return;
					}
					window.setTimeout( poll, 3000 );
				} )
				.catch( function () {
					ticking = false;
					window.setTimeout( poll, 6000 );
				} );
		}
		window.setTimeout( poll, 2000 );
	}

	/* ---------- 2. 支付宝收银台自动提交 ---------- */
	var payForm = document.querySelector( '.as-pay-form form' );
	if ( payForm ) {
		// 支付宝返回的 form 里没有 submit 按钮，这里补一个再自动提交
		if ( ! payForm.querySelector( 'input[type="submit"], button[type="submit"]' ) ) {
			var btn = document.createElement( 'button' );
			btn.type = 'submit';
			btn.textContent = '前往支付宝付款';
			btn.className = 'as-btn-shop-primary as-btn-shop-lg';
			payForm.appendChild( btn );
		}
		window.setTimeout( function () {
			payForm.submit();
		}, 400 );
	}

	/* ---------- 3. 购物车数量：失焦即存（表单提交前先同步一下） ---------- */
	var qtyInputs = document.querySelectorAll( '.as-cart-qty input' );
	for ( var i = 0; i < qtyInputs.length; i++ ) {
		( function ( input ) {
			input.addEventListener( 'change', function () {
				var v = parseInt( input.value, 10 );
				if ( ! v || v < 1 ) { input.value = 1; }
				if ( v > 99 ) { input.value = 99; }
			} );
		} )( qtyInputs[ i ] );
	}

	/* ---------- 4. 地址簿：点一下填入并折叠（v2.87.58） ----------
	 *
	 * 🔴 修 bug：字段 id 一直是**下划线**（as_name / as_phone / as_region / as_detail），
	 *   而这里原来写的是**连字符**（as-name …）→ getElementById 全部返回 null
	 *   → 被 `if (!el) continue` 静默跳过 → 点地址 chip 一个字段都没填进去，
	 *   只有 chip 高亮变蓝，看着像选中了（用户 2026-11-07 报「选了收货人但没填」）。
	 *
	 * 现在的行为（用户选的方案 B）：
	 *   点地址 → 填字段 → 「地址簿 + 表单」整块折叠，换成一行
	 *   「✓ 张三 138****8888 · 广东省 深圳市 南山区 xx路 [更换]」
	 *   → 页面立刻短一截，提交按钮离得更近；点「更换」展开回可编辑状态。
	 *
	 * 降级：无 JS / JS 报错 → 汇总条永远 hidden，用户看到完整表单，功能不丢。
	 * ------------------------------------------------------------------- */
	var addrBox    = document.querySelector( '[data-as-addr-box]' );
	var addrPicked = document.querySelector( '[data-as-addr-picked]' );
	var addrChange = document.querySelector( '[data-as-addr-change]' );

	/** 手机号打码：13812345678 → 138****5678（结算页可能被旁人看到） */
	function maskPhone( v ) {
		v = String( v || '' ).replace( /\s+/g, '' );
		return /^\d{11}$/.test( v ) ? v.slice( 0, 3 ) + '****' + v.slice( 7 ) : v;
	}

	/** 折叠：隐藏地址簿+表单，显示汇总条 */
	function addrCollapse( chip ) {
		if ( ! addrBox || ! addrPicked ) { return; }
		var nameEl = document.querySelector( '[data-as-picked-name]' );
		var phoneEl = document.querySelector( '[data-as-picked-phone]' );
		var regionEl = document.querySelector( '[data-as-picked-region]' );
		if ( nameEl ) { nameEl.textContent = chip.getAttribute( 'data-as-name' ) || ''; }
		if ( phoneEl ) { phoneEl.textContent = maskPhone( chip.getAttribute( 'data-as-phone' ) ); }
		if ( regionEl ) {
			regionEl.textContent = ( chip.getAttribute( 'data-as-region' ) || '' ) +
				' ' + ( chip.getAttribute( 'data-as-detail' ) || '' );
		}
		addrBox.hidden = true;
		addrPicked.hidden = false;
	}

	/** 展开：回到可编辑状态（地址簿和已选高亮都还在） */
	function addrExpand() {
		if ( ! addrBox || ! addrPicked ) { return; }
		addrBox.hidden = false;
		addrPicked.hidden = true;
	}

	if ( addrChange ) {
		addrChange.addEventListener( 'click', addrExpand );
	}

	var chips = document.querySelectorAll( '.as-addr-chip' );
	for ( var c = 0; c < chips.length; c++ ) {
		( function ( chip ) {
			chip.addEventListener( 'click', function () {
				/*
				 * 键名两套，别混：
				 *   id      = as-name（连字符，和 PHP 的 id="as-name" 对上）
				 *   data-*  = data-as-name（也是连字符，所以 data- + key 正好拼对）
				 */
				var fields = [ 'as-name', 'as-phone', 'as-region', 'as-detail' ];
				for ( var f = 0; f < fields.length; f++ ) {
					var key = fields[ f ];
					var el = document.getElementById( key );
					if ( ! el ) { continue; }
					var v = chip.getAttribute( 'data-' + key ) || '';
					el.value = v;
					// 让输入框的「必填」校验状态跟着更新
					el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				}
				for ( var k = 0; k < chips.length; k++ ) {
					chips[ k ].classList.remove( 'is-on' );
				}
				chip.classList.add( 'is-on' );

				/*
				 * 只在**三个必填项（收货人 / 手机号 / 详细地址）都有值**时才折叠。
				 * 否则折叠后那些空字段被藏起来，浏览器提交时会报
				 * 「An invalid form control … is not focusable」，用户还找不到该改哪里。
				 * （省市区是选填，不算在内）
				 */
				var needOk = true;
				var needs = [ 'as-name', 'as-phone', 'as-detail' ];
				for ( var n = 0; n < needs.length; n++ ) {
					var nel = document.getElementById( needs[ n ] );
					if ( ! nel || ! String( nel.value || '' ).replace( /\s+/g, '' ) ) { needOk = false; break; }
				}
				if ( needOk ) { addrCollapse( chip ); }
			} );
		} )( chips[ c ] );
	}

	/* ---------- 5. 加购 AJAX + 轻提示（v2.87.50） ----------
	 *
	 * 为什么：商品页手机端新增了吸底购买栏，「加入购物车」如果还走整页 POST，
	 * 点一下页面就刷没了 —— 吸底栏体验直接崩，滚动位置和已填信息全丢。
	 *
	 * 降级：JS 没加载 / fetch 失败 → 落回原来的整页表单提交，功能不丢。
	 * ------------------------------------------------------------------- */
	var CFG = window.asShopCfg || {};
	var AJAX_URL = CFG.ajaxUrl || '/wp-admin/admin-ajax.php';

	/* --- 轻提示 toast --- */
	var toastEl = null, toastTimer = null;
	function toast( msg, isErr ) {
		if ( ! toastEl ) {
			toastEl = document.createElement( 'div' );
			toastEl.className = 'as-toast';
			toastEl.setAttribute( 'role', 'status' );
			toastEl.setAttribute( 'aria-live', 'polite' );
			toastEl.innerHTML = '<span class="as-toast-ico" aria-hidden="true"></span><span class="as-toast-tx"></span>';
			document.body.appendChild( toastEl );
		}
		var tx = toastEl.querySelector( '.as-toast-tx' );
		if ( tx ) { tx.textContent = msg; }
		toastEl.classList.toggle( 'is-err', !! isErr );
		toastEl.classList.add( 'is-on' );
		window.clearTimeout( toastTimer );
		toastTimer = window.setTimeout( function () {
			toastEl.classList.remove( 'is-on' );
		}, 2200 );
	}

	/* --- 头部购物车角标实时更新 --- */
	function updateCartBadge( n ) {
		var entry = document.querySelector( '.as-cart-entry' );
		if ( ! entry ) { return; }
		n = parseInt( n, 10 ) || 0;
		var badge = entry.querySelector( '.as-cart-entry-n' );
		var ico = entry.querySelector( '.as-cart-entry-ico' );
		if ( n > 0 ) {
			if ( ! badge && ico ) {
				badge = document.createElement( 'span' );
				badge.className = 'as-cart-entry-n';
				ico.appendChild( badge );
			}
			if ( badge ) { badge.textContent = n; }
			entry.classList.add( 'is-has' );
		} else {
			if ( badge && badge.parentNode ) { badge.parentNode.removeChild( badge ); }
			entry.classList.remove( 'is-has' );
		}
		entry.setAttribute( 'aria-label', n > 0 ? ( '购物车，' + n + ' 件商品' ) : '购物车' );
	}

	/* --- 拦截加购表单 --- */
	var addForms = document.querySelectorAll( 'form[data-as-add-form]' );
	for ( var a = 0; a < addForms.length; a++ ) {
		( function ( form ) {
			form.addEventListener( 'submit', function ( e ) {
				// 已被标记为失败过一次 → 不再拦截，直接走整页提交
				if ( form.getAttribute( 'data-as-add-failed' ) ) { return; }
				e.preventDefault();

				var btn = form.querySelector( 'button[type="submit"]' );
				var oldText = btn ? btn.textContent : '';
				if ( btn ) { btn.disabled = true; btn.textContent = '加入中…'; }

				var body = new FormData( form );
				body.append( 'action', 'as_web_cart_add' );

				fetch( AJAX_URL, {
					method: 'POST',
					credentials: 'same-origin',
					body: body
				} )
					.then( function ( r ) { return r.json(); } )
					.then(
						function ( d ) {
							if ( btn ) { btn.disabled = false; btn.textContent = oldText; }
							if ( d && d.success ) {
								toast( d.data && d.data.message ? d.data.message : '已加入购物车' );
								updateCartBadge( d.data ? d.data.count : 0 );
							} else {
								var msg = ( d && d.data && d.data.message ) ? d.data.message : '加入购物车失败';
								toast( msg, true );
								// 失败不跳页（用户可能正在填地址），只在提示里说明
							}
						},
						function () {
							// 网络异常 → 标记失败并让浏览器照常提交，功能不丢
							if ( btn ) { btn.disabled = false; btn.textContent = oldText; }
							form.setAttribute( 'data-as-add-failed', '1' );
							form.submit();
						}
					);
			} );
		} )( addForms[ a ] );
	}
} )();

/* ==========================================
   「注册 / 登录」弹窗（v2.87.43）
   ------------------------------------------------------------------
   点头部人头像（未登录）→ 弹窗；已登录那个链接没有 data-as-member-open，
   正常跳 /member/ 个人中心。
   ========================================== */
( function () {
	var modal = document.getElementById( 'as-mem-modal' );
	if ( ! modal ) { return; }

	function show( tab ) {
		modal.hidden = false;
		modal.classList.add( 'is-on' );
		document.documentElement.style.overflow = 'hidden';
		switchTab( tab || 'register' );
	}
	function hide() {
		modal.classList.remove( 'is-on' );
		modal.hidden = true;
		document.documentElement.style.overflow = '';
	}
	function switchTab( name ) {
		var tabs = modal.querySelectorAll( '[data-as-mem-tab]' );
		var forms = modal.querySelectorAll( '[data-as-mem-form]' );
		var i;
		for ( i = 0; i < tabs.length; i++ ) {
			var on = tabs[ i ].getAttribute( 'data-as-mem-tab' ) === name;
			tabs[ i ].classList.toggle( 'is-on', on );
			tabs[ i ].setAttribute( 'aria-selected', on ? 'true' : 'false' );
		}
		for ( i = 0; i < forms.length; i++ ) {
			forms[ i ].hidden = forms[ i ].getAttribute( 'data-as-mem-form' ) !== name;
		}
		var sw = modal.querySelectorAll( '[data-as-mem-switch]' );
		for ( i = 0; i < sw.length; i++ ) {
			sw[ i ].hidden = sw[ i ].getAttribute( 'data-as-mem-switch' ) !== name;
		}
		var first = modal.querySelector( '[data-as-mem-form="' + name + '"] input[name="as_phone"]' );
		if ( first ) { try { first.focus( { preventScroll: true } ); } catch ( e ) { } }
	}

	// 打开入口
	var openers = document.querySelectorAll( '[data-as-member-open]' );
	for ( var o = 0; o < openers.length; o++ ) {
		openers[ o ].addEventListener( 'click', function ( e ) {
			e.preventDefault();
			show( 'register' );
		} );
	}

	// 页签切换
	var tabs = modal.querySelectorAll( '[data-as-mem-tab]' );
	for ( var t = 0; t < tabs.length; t++ ) {
		tabs[ t ].addEventListener( 'click', function ( e ) {
			e.preventDefault();
			switchTab( this.getAttribute( 'data-as-mem-tab' ) );
		} );
	}

	// 关闭：遮罩 / × / ESC
	var closers = modal.querySelectorAll( '[data-as-mem-close]' );
	for ( var c = 0; c < closers.length; c++ ) {
		closers[ c ].addEventListener( 'click', hide );
	}
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key || 27 === e.keyCode ) { hide(); }
	} );

	// 提交中禁用按钮，避免重复提交（注册会连发两次）
	var forms = modal.querySelectorAll( '[data-as-mem-form]' );
	for ( var f = 0; f < forms.length; f++ ) {
		forms[ f ].addEventListener( 'submit', function () {
			var btn = this.querySelector( '.as-mem-submit' );
			if ( btn ) { btn.disabled = true; btn.textContent = '处理中…'; }
		} );
	}

	// 带错误信息回来时自动弹窗（注册/登录失败）
	if ( modal.getAttribute( 'data-as-mem-error' ) ) {
		show( 'register' );
	}
} )();
