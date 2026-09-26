<?php
/**
 * default 模板 - 播放器脚本（播放页底部加载）
 * 变量：$playUrls, $vodId, $sourceId, $name, $pic
 *
 * hls.js 必须在内联脚本之前加载，否则非 Safari 浏览器拿不到 window.Hls
 */
if (!$playUrls) {
    return;
}
?>
<script src="static/js/hls.min.js"></script>
<script>
// 播放器逻辑：优先 hls.js，Safari 原生支持 m3u8
(function () {
    const video = document.getElementById('player');
    const btns = document.querySelectorAll('#playerSources .chip');
    const urls = <?= json_encode(array_map(static fn ($p) => $p['url'], $playUrls)) ?>;
    let hls = null;

    function loadHlsThen(url) {
        // CDN 未加载成功时动态注入一次，加载完再播放
        const s = document.createElement('script');
        s.src = 'static/js/hls.min.js';
        s.onload = () => play(url);
        s.onerror = () => alert('播放组件加载失败，请检查网络后重试');
        document.head.appendChild(s);
    }

    function play(url) {
        if (hls) { hls.destroy(); hls = null; }
        const canNative = video.canPlayType('application/vnd.apple.mpegurl');
        if (canNative) {
            video.src = url;
        } else if (window.Hls && window.Hls.isSupported()) {
            hls = new window.Hls({ enableWorker: true });
            hls.loadSource(url);
            hls.attachMedia(video);
        } else if (!window.Hls) {
            loadHlsThen(url);
            return;
        } else {
            alert('当前浏览器不支持播放 m3u8，请更换浏览器');
            return;
        }
        btns.forEach(b => b.classList.toggle('active', b.dataset.url === url));
    }

    btns.forEach(b => b.addEventListener('click', () => play(b.dataset.url)));
    play(urls[0]);

    // 记录播放历史（localStorage，仅本机）
    const rec = {
        id: <?= $vodId ?>,
        source: <?= $sourceId ?>,
        name: <?= json_encode($name) ?>,
        pic: <?= json_encode($pic) ?>,
        time: Date.now()
    };
    try {
        const key = 'vod_history';
        let arr = JSON.parse(localStorage.getItem(key) || '[]');
        arr = arr.filter(r => !(r.id === rec.id && r.source === rec.source));
        arr.unshift(rec);
        arr = arr.slice(0, 60);
        localStorage.setItem(key, JSON.stringify(arr));
    } catch (e) { /* 无痕模式等，忽略 */ }

    // 收藏按钮
    const fav = document.getElementById('btnFavorite');
    const fkey = 'vod_favorites';
    let favs = [];
    try { favs = JSON.parse(localStorage.getItem(fkey) || '[]'); } catch (e) {}
    const isFav = favs.some(r => r.id === rec.id && r.source === rec.source);
    if (isFav) fav.textContent = '★ 已收藏';
    fav.addEventListener('click', () => {
        try {
            let arr = JSON.parse(localStorage.getItem(fkey) || '[]');
            const i = arr.findIndex(r => r.id === rec.id && r.source === rec.source);
            if (i >= 0) {
                arr.splice(i, 1);
                fav.textContent = '⭐ 收藏';
            } else {
                arr.unshift(rec);
                fav.textContent = '★ 已收藏';
            }
            localStorage.setItem(fkey, JSON.stringify(arr));
        } catch (e) {}
    });
})();
</script>
