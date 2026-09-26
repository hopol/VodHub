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
    document.addEventListener('error', function (e) {
        var target = e.target;
        if (target && target.tagName === 'IMG') {
            var src = target.getAttribute('src') || '';
            if (src.indexOf('no-cover.svg') === -1) {
                target.setAttribute('src', 'static/img/no-cover.svg');
            }
        }
    }, true);

    // ---------------------------------------------------------------- 播放页字段归一化
    // 播放页先用「上游原始字段 + 本地解析」渲染出来，这里再异步补上需要常识判断的部分
    // （地区/语言归一、主类型、更新状态、内容分级）。命中缓存时几乎瞬时返回。
    // 失败一律静默 —— 页面上的原始字段本身就是完整可用的展示。
    (function () {
        var box = document.getElementById('detailMeta') || document.getElementById('playSide');
        if (!box) return;

        var qs = new URLSearchParams(window.location.search);
        var source = qs.get('source') || '';
        var vid = qs.get('id') || '';
        if (!source || !vid) return;

        fetch('enrich.php?source=' + encodeURIComponent(source) + '&id=' + encodeURIComponent(vid), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        }).then(function (d) {
            if (!d || !d.ok) return;
            replace('detailMeta', d.chips);
            replace('playSide', d.side);
        }).catch(function () { /* 保持原始字段即可 */ });

        function replace(id, html) {
            if (!html) return;
            var el = document.getElementById(id);
            if (!el) return;
            var tmp = document.createElement('div');
            tmp.innerHTML = html;
            var next = tmp.firstElementChild;
            if (next) el.parentNode.replaceChild(next, el);
        }
    })();

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
