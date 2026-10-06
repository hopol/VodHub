// 前端交互脚本

(function () {
    'use strict';

    // ---------------------------------------------------------------- 顶栏搜索
    // 回车即提交（表单本身已支持，此处仅做聚焦体验）
    var searchInput = document.querySelector('.search-box input');
    if (searchInput) {
        searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.currentTarget.form.submit();
            }
        });
    }

    // ---------------------------------------------------------------- 后台提示
    // 3 秒后淡出
    var msg = document.getElementById('adminMsg');
    if (msg) {
        setTimeout(function () {
            msg.style.transition = 'opacity .5s';
            msg.style.opacity = '0';
            setTimeout(function () { msg.remove(); }, 500);
        }, 3000);
    }

    // ---------------------------------------------------------------- 破图兜底
    // 分两步走：上游抖一下不该直接表现为「整页一排相同的占位图」。
    //   第 1 次失败 → 记下原地址，2 秒后带一个新 query 重试（绕开浏览器自己的失败缓存）
    //   第 2 次失败 → 才换成占位图；占位图本身由 .htaccess 缓存 30 天，不会被反复请求
    // 换占位图之前原始 URL 一直保留在 data-orig-src 上，方便排查是哪个源在裂。
    document.addEventListener('error', function (e) {
        var target = e.target;
        if (!target || target.tagName !== 'IMG') return;

        var src = target.getAttribute('src') || '';
        if (!src || src.indexOf('no-cover.svg') !== -1) return;

        if (!target.getAttribute('data-retried')) {
            var original = target.getAttribute('data-orig-src') || src;
            target.setAttribute('data-orig-src', original);
            target.setAttribute('data-retried', '1');
            setTimeout(function () {
                target.setAttribute(
                    'src',
                    original + (original.indexOf('?') >= 0 ? '&' : '?') + 'r=' + Date.now()
                );
            }, 2000);
            return;
        }
        target.setAttribute('src', 'static/img/no-cover.svg');
    }, true);

    // ---------------------------------------------------------------- 列表页本地筛选
    // 上游的 wd 参数只匹配 vod_name（实测 wd=<拼音>、wd=<别名> 都返回 0 条），
    // 所以别名、拼音、首字母、演员、导演、编剧、标签这些字段在站内检索里是死的。
    // 这里按服务端预拼的 data-s 串在「当前这一页」内做子串匹配，把它们用起来。
    (function () {
        var input = document.getElementById('listFilter');
        var grid = document.getElementById('vodGrid');
        if (!input || !grid) return;

        var cards = [].slice.call(grid.querySelectorAll('.vod-card'));
        var count = document.getElementById('filterCount');
        var empty = document.getElementById('filterEmpty');
        if (!cards.length) return;

        function apply() {
            var q = (input.value || '').trim().toLowerCase();
            var shown = 0;
            for (var i = 0; i < cards.length; i++) {
                var hay = cards[i].getAttribute('data-s') || '';
                var hit = q === '' || hay.indexOf(q) !== -1;
                cards[i].hidden = !hit;
                if (hit) shown++;
            }
            if (count) {
                count.textContent = q === '' ? '' : shown + ' / ' + cards.length;
            }
            if (empty) {
                empty.classList.toggle('show', shown === 0);
            }
        }

        input.addEventListener('input', apply);
        // 浏览器的「恢复表单」可能带回上次的词，补跑一次
        if (input.value) apply();
    })();
})();
