/**
 * 产品编辑页 · 逐行编辑器
 *
 * 处理两种字段：
 *   data-type="rows"   每行一个输入框（优点 / 缺点）
 *   data-type="pairs"  每行两个输入框（自定义属性的「名称 + 值」）
 *
 * 依赖 markup 由 PHP 输出，这里只负责增删行。
 */
(function () {
    'use strict';

    function makeInput(type, name, placeholder, value) {
        var el;
        if (type === 'textarea') {
            el = document.createElement('textarea');
            el.rows = 2;
        } else {
            el = document.createElement('input');
            el.type = 'text';
        }
        el.name = name;
        if (placeholder) {
            el.placeholder = placeholder;
        }
        el.value = value || '';
        return el;
    }

    function buildRow(box, values) {
        values = values || {};
        var key = box.getAttribute('data-key');
        var type = box.getAttribute('data-type');
        var placeholder = box.getAttribute('data-placeholder') || '';
        var item = box.getAttribute('data-item') || 'text';

        var row = document.createElement('div');
        row.className = 'as-rp-row';

        if (type === 'pairs') {
            row.appendChild(makeInput('text', key + '_name[]', '名称', values.name));
            row.appendChild(makeInput('text', key + '_value[]', '值', values.value));
        } else {
            row.appendChild(makeInput(item, key + '[]', placeholder, values.text));
        }

        var del = document.createElement('button');
        del.type = 'button';
        del.className = 'as-rp-del';
        del.setAttribute('aria-label', '删除这一行');
        del.innerHTML = '&times;';
        row.appendChild(del);

        return row;
    }

    function focusFirst(row) {
        var el = row.querySelector('input, textarea');
        if (el) {
            el.focus();
        }
    }

    function initBox(box) {
        var list = box.querySelector('.as-rp-list');
        var addBtn = box.querySelector('.as-rp-add');
        if (!list || !addBtn) {
            return;
        }

        function keepOneRow() {
            if (!list.querySelector('.as-rp-row')) {
                list.appendChild(buildRow(box, {}));
            }
        }

        // 添加一行
        addBtn.addEventListener('click', function () {
            var row = buildRow(box, {});
            list.appendChild(row);
            focusFirst(row);
            row.scrollIntoView({ block: 'nearest' });
        });

        // 删除行（事件委托，后加的行也生效）
        list.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('.as-rp-del') : null;
            if (!btn || !list.contains(btn)) {
                return;
            }
            var row = btn.parentNode;
            row.parentNode.removeChild(row);
            keepOneRow();
        });

        // 输入框里按回车 = 新增一行
        // （不拦的话会把整个文章表单提交掉）
        list.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' || e.shiftKey) {
                return;
            }
            var el = e.target;
            if (!el.matches || !el.matches('input[type="text"]')) {
                return;
            }
            e.preventDefault();
            var row = el.parentNode;
            var next = row.nextElementSibling;

            // 中间插入：回车就在下方插入新行
            if (!next) {
                next = buildRow(box, {});
                list.appendChild(next);
            }
            focusFirst(next);
        });

        // 初始至少保留一行空行，方便直接输入
        keepOneRow();

        // 统计非空行数（给 PHP 侧做「留空则隐藏」的提示用，可选）
        var form = box.closest('form');
        if (form) {
            form.addEventListener('submit', function () {
                Array.prototype.forEach.call(list.querySelectorAll('.as-rp-row'), function (row) {
                    if (!row.querySelector('input, textarea').value.trim()) {
                        row.style.opacity = '.5';
                    }
                });
            });
        }
    }

    function init() {
        Array.prototype.forEach.call(document.querySelectorAll('.as-rp'), initBox);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
