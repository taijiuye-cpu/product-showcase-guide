/**
 * WP 后台「商城产品」列表 —— 行内编辑（配合 inc/product-list-edit.php）
 *
 * 改动字段后自动保存，不用进编辑页。
 * 保存成功闪绿框，失败闪红框并回滚到原值 —— 绝不让界面显示的数字与库里的不一致。
 */
(function () {
  var CFG = window.AS_QE || {};
  if (!CFG.url || !CFG.nonce) return;

  // 行内可保存字段白名单（与 as_qe_fields() 一致）。
  var FIELDS = [
    'dg_price',
    'dg_old_price',
    'dg_sales_virtual',
    'dg_hot',
    'dg_flash',
    'menu_order',
    'dg_effect',
  ];

  function find(el, sel) {
    if (document.querySelector) {
      return document.querySelector(sel);
    }
    return null;
  }

  function post(pid, field, value) {
    var body = new URLSearchParams();
    body.append('action', 'as_qe_product');
    body.append('_wpnonce', CFG.nonce);
    body.append('pid', pid);
    body.append('field', field);
    body.append('value', value);

    return fetch(CFG.url, {
      method: 'POST',
      credentials: 'same-origin',
      body: body,
    }).then(function (r) {
      return r.json();
    });
  }

  function flash(el, cls) {
    el.classList.add(cls);
    setTimeout(function () {
      el.classList.remove(cls);
    }, 1400);
  }

  function rollback(el) {
    var prev = el.getAttribute('data-prev') || '';
    if (el.type === 'checkbox') {
      el.checked = prev === '1';
    } else {
      el.value = prev;
    }
  }

  function save(el) {
    var pid = el.getAttribute('data-pid');
    var field = el.getAttribute('data-field');
    if (!pid || FIELDS.indexOf(field) < 0) return;

    // 同一格正在保存时不重复发请求
    if ('1' === el.getAttribute('data-busy')) return;
    el.setAttribute('data-busy', '1');

    var value = el.type === 'checkbox' ? (el.checked ? '1' : '') : el.value;

    el.classList.remove('as-qe-ok', 'as-qe-err');
    el.classList.add('as-qe-saving');

    function done() {
      el.removeAttribute('data-busy');
      el.classList.remove('as-qe-saving');
    }

    post(pid, field, value)
      .then(function (res) {
        done();

        if (!res || !res.success) {
          throw new Error((res && res.data && res.data.message) || '保存失败');
        }

        // 服务端会规范化数值，回填它返回的值
        var v = String((res.data && res.data.value) || '');
        if (el.type === 'checkbox') {
          el.checked = v === '1';
        } else {
          el.value = v;
        }
        el.setAttribute('data-prev', v);

        flash(el, 'as-qe-ok');
      })
      .catch(function (err) {
        done();
        rollback(el);
        flash(el, 'as-qe-err');
        window.alert('保存失败：' + ((err && err.message) || '未知错误'));
      });
  }

  document.addEventListener('change', function (e) {
    var el = e.target;
    if (!el || !el.classList || !el.classList.contains('as-qe-in')) return;
    save(el);
  });

  // 输入框里按回车即保存（blur 会触发 change，所以这里只 blur，不重复提交）
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.keyCode !== 13) return;
    var el = e.target;
    if (!el || !el.classList || !el.classList.contains('as-qe-in')) return;
    e.preventDefault();
    el.blur();
  });
})();
