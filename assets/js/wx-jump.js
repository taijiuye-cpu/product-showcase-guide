/**
 * 网页购买按钮 → 下单方式选择（配合 inc/wx-jump.php）
 *
 * v2.87.38 起，点击带 [data-as-mp] 的购买按钮先弹**选择层**：
 *   ① 小程序下单 —— 电脑端弹小程序码；手机端现场取一条 URL Link 直接跳
 *      （微信规定一人一链，所以不能预生成）
 *   ② 网站下单   —— 电脑端弹二维码；手机端直接打开本站商品详情页
 *      二维码优先用后台「网站与小程序共用设置 → 自定义二维码」上传的那张，
 *      没上传则由 /?as_site_qr=<pid> 动态生成该商品页的码。
 *
 * 任何一步失败都逐级降级，按钮不会点了没反应：
 *   小程序取链接失败 → 改弹小程序码 → 再失败退回原外链；
 *   网站下单没有二维码 → 电脑端直接新窗口打开商品页。
 */
(function () {
  var CFG = window.AS_WXJUMP || {};
  if (!CFG.url) return;

  function isMobile() {
    return /Android|iPhone|iPod|iPad|HarmonyOS|Windows Phone|BlackBerry|Mobile/i.test(
      navigator.userAgent || ''
    );
  }

  function request(type, pid) {
    return fetch(CFG.url + '/' + type + '?pid=' + encodeURIComponent(pid), {
      credentials: 'omit',
    }).then(function (r) {
      return r.json().then(function (d) {
        if (!r.ok || !d || !d.ok) {
          throw new Error((d && d.message) || 'request failed');
        }
        return d;
      });
    });
  }

  function closest(el, sel) {
    while (el && el.nodeType === 1) {
      if (el.matches ? el.matches(sel) : false) return el;
      el = el.parentNode;
    }
    return null;
  }

  /* ---------- 二维码弹层（首次用到才创建） ---------- */

  var mask = null;

  function ensureMask() {
    if (mask) return mask;

    mask = document.createElement('div');
    mask.className = 'as-mp-mask';
    mask.innerHTML =
      '<div class="as-mp-box" role="dialog" aria-label="扫码">' +
      '<button type="button" class="as-mp-close" aria-label="关闭">&times;</button>' +
      '<div class="as-mp-title">微信扫一扫</div>' +
      '<div class="as-mp-img"><img alt="二维码" src=""></div>' +
      '<div class="as-mp-tip">打开微信扫描二维码，直达商品页下单</div>' +
      '</div>';

    var close = function () {
      mask.classList.remove('on');
    };
    mask.addEventListener('click', function (e) {
      if (e.target === mask || (e.target.className || '').indexOf('as-mp-close') > -1) close();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' || e.keyCode === 27) close();
    });

    document.body.appendChild(mask);
    return mask;
  }

  function showCode(url, title, tip) {
    var m = ensureMask();
    m.querySelector('.as-mp-title').textContent = title;
    m.querySelector('.as-mp-img img').src = url;
    m.querySelector('.as-mp-tip').textContent = tip;
    m.classList.add('on');
  }

  /* ---------- 下单方式选择层 ---------- */

  var chooseMask = null;
  var activeBtn = null;

  function ensureChoose() {
    if (chooseMask) return chooseMask;

    chooseMask = document.createElement('div');
    chooseMask.className = 'as-mp-mask';
    chooseMask.innerHTML =
      '<div class="as-mp-box as-choose-box" role="dialog" aria-label="选择下单方式">' +
      '<button type="button" class="as-mp-close" aria-label="关闭">&times;</button>' +
      '<div class="as-mp-title">选择下单方式</div>' +
      '<button type="button" class="as-choose-item" data-as-pick="mp">' +
      '<span class="as-choose-ico">微</span>' +
      '<span class="as-choose-tx"><b>小程序下单</b><em>打开微信小程序购买</em></span>' +
      '</button>' +
      '<button type="button" class="as-choose-item" data-as-pick="site">' +
      '<span class="as-choose-ico as-choose-ico-site">站</span>' +
      '<span class="as-choose-tx"><b>网站下单</b><em>在本网站直接下单付款</em></span>' +
      '</button>' +
      '</div>';

    var close = function () {
      chooseMask.classList.remove('on');
    };

    chooseMask.addEventListener('click', function (e) {
      if (e.target === chooseMask || (e.target.className || '').indexOf('as-mp-close') > -1) {
        close();
        return;
      }
      var pick = closest(e.target, '[data-as-pick]');
      if (!pick || !activeBtn) return;
      var btn = activeBtn;
      close();
      if ('mp' === pick.getAttribute('data-as-pick')) {
        goMp(btn);
      } else {
        goSite(btn);
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' || e.keyCode === 27) close();
    });

    document.body.appendChild(chooseMask);
    return chooseMask;
  }

  function showChoose(btn) {
    activeBtn = btn;
    ensureChoose().classList.add('on');
  }

  /* ---------- 兜底：打开原商品外链 ---------- */

  function openFallback(btn) {
    var href = btn.getAttribute('href');
    if (href && href !== '#') {
      window.open(href, '_blank');
    }
  }

  /* ---------- ① 小程序下单 ---------- */

  function goMp(btn) {
    var pid = btn.getAttribute('data-as-mp');
    var mpUrl = btn.getAttribute('data-as-mp-url');   // 商品属性里填的购买链接
    var mpQr = btn.getAttribute('data-as-mp-qr');     // 后台上传的小程序码

    var title = '微信扫一扫';
    var tip = '打开微信扫描二维码，进小程序下单';

    if (isMobile()) {
      // 手机端：直接跳到商品属性里填的购买链接；没有就退回详情页/外链
      if (mpUrl) {
        window.location.href = mpUrl;
      } else {
        openFallback(btn);
      }
      return;
    }

    // 电脑端：优先弹后台上传的那张码；没上传才去调接口自动生成
    if (mpQr) {
      showCode(mpQr, title, tip);
      return;
    }
    if (!pid) return;
    request('code', pid)
      .then(function (d) {
        showCode(d.code_url, title, tip);
      })
      .catch(function () {
        openFallback(btn);
      });
  }

  /* ---------- ② 网站下单：直接打开本站商品详情页 ---------- */

  function goSite(btn) {
    var site = btn.getAttribute('data-as-site');
    if (site) {
      window.location.href = site;
    } else {
      openFallback(btn);
    }
  }

  /* ---------- 点击处理 ---------- */

  document.addEventListener('click', function (e) {
    var btn = closest(e.target, '[data-as-mp]');
    if (!btn) return;
    if (!btn.getAttribute('data-as-mp')) return;
    e.preventDefault();

    // 详情页的按钮（data-as-mp-only）：不弹选择层，直接走小程序那条路
    if (btn.getAttribute('data-as-mp-only')) {
      goMp(btn);
      return;
    }

    showChoose(btn);
  });
})();
