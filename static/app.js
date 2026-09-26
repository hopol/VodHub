// 前端交互脚本

(function () {
    'use strict';

    // 顶栏搜索：回车即提交（表单本身已支持，此处仅做聚焦体验）
    var searchInput = document.querySelector('.search-box input');
    if (searchInput) {
        searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.currentTarget.form.submit();
            }
        });
    }

    // 后台提示信息：3 秒后淡出
    var msg = document.getElementById('adminMsg');
    if (msg) {
        setTimeout(function () {
            msg.style.transition = 'opacity .5s';
            msg.style.opacity = '0';
            setTimeout(function () { msg.remove(); }, 500);
        }, 3000);
    }

    // 图片加载失败时显示占位图
    document.addEventListener('error', function (e) {
        var target = e.target;
        if (target && target.tagName === 'IMG') {
            var src = target.getAttribute('src') || '';
            if (src.indexOf('no-cover.svg') === -1) {
                target.setAttribute('src', 'static/img/no-cover.svg');
            }
        }
    }, true);
})();
