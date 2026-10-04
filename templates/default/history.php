<?php
/**
 * default 模板 - 播放历史与收藏
 * 变量：$pageTitle, $siteTitle
 */
require tplInclude('header.php', $tplName);
?>

<div class="list-head">
    <h1 class="list-title">我的记录</h1>
    <div class="type-filter">
        <button class="chip active" id="tabHistory">▶ 播放历史</button>
        <button class="chip" id="tabFavorite">⭐ 收藏</button>
        <button class="chip" id="btnClear">🗑 清空</button>
    </div>
</div>

<div id="recordList" class="vod-grid"></div>
<div class="empty" id="recordEmpty" hidden>
    <div class="empty-icon">🗒️</div>
    <h2>暂无记录</h2>
    <p>观看过的影片会自动记录在这里（仅保存在本机浏览器）。</p>
    <a class="btn btn-primary" href="index.php">去浏览影片</a>
</div>

<script>
(function () {
    const grid = document.getElementById('recordList');
    const empty = document.getElementById('recordEmpty');
    const tabHistory = document.getElementById('tabHistory');
    const tabFavorite = document.getElementById('tabFavorite');
    const btnClear = document.getElementById('btnClear');
    let mode = 'history';

    function read(key) {
        try { return JSON.parse(localStorage.getItem(key) || '[]'); }
        catch (e) { return []; }
    }

    function render() {
        const arr = read(mode === 'history' ? 'vod_history' : 'vod_favorites');
        tabHistory.classList.toggle('active', mode === 'history');
        tabFavorite.classList.toggle('active', mode === 'favorite');
        grid.innerHTML = '';
        if (!arr.length) {
            empty.hidden = false;
            return;
        }
        empty.hidden = true;
        for (const r of arr) {
            const a = document.createElement('a');
            a.className = 'vod-card';
            a.href = 'play.php?source=' + r.source + '&id=' + r.id;
            a.innerHTML = ''
                + '<div class="cover">'
                + '<img src="' + (r.pic || 'static/img/no-cover.svg') + '" alt="" loading="lazy">'
                + (mode === 'history' && r.time
                    ? '<span class="badge-time">' + new Date(r.time).toLocaleDateString('zh-CN') + '</span>'
                    : '')
                + '</div>'
                + '<div class="vod-info"><h3></h3><p class="vod-sub"></p></div>';
            a.querySelector('h3').textContent = r.name;
            a.querySelector('.vod-sub').textContent = mode === 'history' ? '点击继续观看' : '我的收藏';
            grid.appendChild(a);
        }
    }

    tabHistory.addEventListener('click', () => { mode = 'history'; render(); });
    tabFavorite.addEventListener('click', () => { mode = 'favorite'; render(); });
    btnClear.addEventListener('click', () => {
        if (!confirm('确定清空' + (mode === 'history' ? '播放历史' : '收藏') + '吗？')) return;
        localStorage.removeItem(mode === 'history' ? 'vod_history' : 'vod_favorites');
        render();
    });
    render();
})();
</script>

<?php require tplInclude('footer.php', $tplName); ?>
