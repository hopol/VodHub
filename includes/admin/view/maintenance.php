<?php
/**
 * 站点与维护（低功耗状态 / 系统缓存 / 配置导入导出）（admin.php 视图片段）
 *
 * 1.5.3 起从 admin.php 拆出 —— 原文件 982 行，逻辑与 HTML 混在一起。
 *
 * ⚠ 由 admin.php **顶层**按固定顺序 require，与 admin.php 共用同一份作用域；
 *   **顺序不能调**：这份片段里有 $tName / $curCols / $curTier / $curWb /
 *   $probeOk 这类中间变量，改变顺序会让后面的片段读到前一份的残留值。
 *   拆分只改变文件归属，不改变执行顺序 —— 与原来逐字节等价。
 *
 * ⚠ 这类会 echo 的视图**不能被 HTTP 直接取到**：`.htaccess` 已加
 *   `RewriteRule ^includes/ - [F,L]`。同 templates/ 被保护的道理 ——
 *   直接取会吐出未初始化变量的页面骨架与路径信息。
 */
?>
    <!-- ============================= 站点与维护 ============================= -->
    <section class="admin-card">
        <h2 class="admin-title">⚙️ 站点与维护</h2>
        <form class="admin-form" method="post" action="admin.php">
            <input type="hidden" name="action" value="site_settings">
            <label class="field field-wide">
                <span>站点名称</span>
                <input type="text" name="site_title" required maxlength="50" value="<?= h($siteTitle) ?>">
            </label>
            <label class="field">
                <span>列表列数（PC 端）</span>
                <select name="list_columns">
                    <?php $curCols = intval(setting('list_columns', '0')); ?>
                    <option value="0" <?= $curCols === 0 ? 'selected' : '' ?>>跟随模板默认</option>
                    <?php for ($c = 2; $c <= 8; $c++): ?>
                        <option value="<?= $c ?>" <?= $curCols === $c ? 'selected' : '' ?>>
                            <?= $c ?> 列<?= $c === 5 ? '（推荐）' : '' ?>
                        </option>
                    <?php endfor; ?>
                </select>
                <small class="muted">
                    控制列表页每行卡片数。选「跟随模板默认」时按当前模板的列数显示；
                    手机端始终自适应，不受此设置影响。
                </small>
            </label>
            <label class="field">
                <span>容量档位（极致低功耗）</span>
                <select name="lowpower_tier">
                    <?php $curTier = (string) setting('lowpower_tier', 'auto'); ?>
                    <option value="auto" <?= $curTier === 'auto' ? 'selected' : '' ?>>自动探测</option>
                    <option value="compact" <?= $curTier === 'compact' ? 'selected' : '' ?>>紧凑（1 GB 空间）</option>
                    <option value="standard" <?= $curTier === 'standard' ? 'selected' : '' ?>>标准（5 GB 及以上空间）</option>
                </select>
                <small class="muted">
                    决定页面缓存、图片缓存、接口缓存的硬上限：紧凑档合计约 105 MB，标准档约 340 MB。
                    <b>磁盘 ≤1 GB 请选「紧凑」</b> —— 不少主机的自动探测拿到的是整机磁盘而不是你的配额。
                </small>
            </label>
            <label class="field">
                <span>首页补拉预算（秒）</span>
                <select name="warm_budget">
                    <?php $curWb = max(0, intval(setting('warm_budget', '6'))); ?>
                    <option value="0" <?= $curWb === 0 ? 'selected' : '' ?>>0（不自动补拉）</option>
                    <?php foreach ([1, 2, 3, 4, 5, 6, 8, 10] as $w): ?>
                        <option value="<?= $w ?>" <?= $curWb === $w ? 'selected' : '' ?>><?= $w ?> 秒<?= $w === 6 ? '（推荐）' : '' ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="muted">
                    清空接口缓存后，首页要为**每个数据源**各请求一次上游取分类。
                    这里限制的是**单次请求最多花多少秒**，用满就不再拉 ——
                    拉不完的下次访问继续，已成功的都已落盘、进度不丢。
                    <b>先确认你的网关超时</b>：3 秒超时的主机请选 ≤2 秒，否则照样 502；
                    选 0 = 不自动补拉，首页只显示本地已缓存的分类（没有就提示「无法获取分类」）。
                </small>
            </label>
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <button class="btn btn-primary" type="submit">保存</button>
        </form>

        <?php
        $cacheStat = cacheStats(); $opInfo = opcacheInfo();

        /**
         * 把异常翻译成「用户该做什么」。
         *
         * ⚠ 不要一律归因为「升级包没传全」—— 2026-09-28 线上事故：
         * InfinityFree 把 disk_free_space / disk_total_space 放进了 disable_functions，
         * 抛的是 `Call to undefined function disk_total_space()`，**与升级包完全无关**，
         * 而旧文案写死了 config.php 归因，把人往错方向带。
         * 归因必须跟着错误类型走。
         */
        $lpDiagnose = static function (string $msg): array {
            $m = strtolower($msg);
            if (str_contains($m, 'disk_total_space') || str_contains($m, 'disk_free_space')) {
                return ['主机禁用了 disk_free_space / disk_total_space（disable_functions）',
                        '这与升级包无关。水位探测已自动降级 —— 请在上面「容量档位」手动选紧凑或标准档；硬上限仍然生效。'];
            }
            if (str_contains($m, 'undefined function')) {
                return ['主机禁用了某个函数：' . $msg,
                        '跑 vp.php 的 H 节看禁了哪些；前台会自动降级，不影响访问。'];
            }
            if (str_contains($m, 'constant') || str_contains($m, 'page_cache_dir') || str_contains($m, 'app_version')) {
                return [$msg, 'config.php 是旧版 —— 补传升级包里的 config.php。'];
            }
            if (str_contains($m, 'no such file') || str_contains($m, 'failed opening required')) {
                return [$msg, '升级包缺文件 —— 按 vp.php 的 B 节标「缺!」的补传。'];
            }
            return [$msg, '把上面这行原样贴出来提 Issue。'];
        };

        // ---------------------------------------------------------------- 故障隔离
        // 这一段依赖 1.3.0 新增的函数与常量，而它下面**紧跟着**「清理系统缓存 · OPcache」——
        // 那是「传了文件没变化」时唯一的救场按钮。
        //
        // 所以：坏掉一个新功能可以接受，**把它一起带下水不行**。
        // 只要文件没传齐（典型是漏了改过的 config.php），这里必须跳过而不是 fatal，
        // 否则会出现「页面看着正常、但 OPcache 按钮整段消失」这种最难诊断的故障。
        $lp      = null;
        $lpWhy   = '';
        try {
            foreach (['guardDisk', 'guardCaps', 'pcCount', 'imgCacheBytes', 'pcEnabled', 'bytesHuman'] as $fn) {
                if (!function_exists($fn)) {
                    $lpWhy = '函数 ' . $fn . '() 不存在';
                    break;
                }
            }
            // 用 APP_VERSION 判「config.php 是不是新版」——它**没有**兜底默认值，
            // 只有 1.3.0 的 config.php 才 define 它。其余常量已在 guard/client 里
            // 自带默认值（漏传 config.php 不再让前台 500），所以不再拿它们当判据。
            if ($lpWhy === '' && !defined('APP_VERSION')) {
                $lpWhy = 'config.php 是旧版（缺 APP_VERSION 等 1.3.0 新常量）';
            }
            if ($lpWhy === '') {
                $lp = [
                    'disk'    => guardDisk(),
                    'caps'    => guardCaps(),
                    'pages'   => pcCount(),
                    'imgs'    => imgCacheBytes(),
                    'enabled' => pcEnabled(),
                    'access'  => setting('access_enabled') === '1',
                ];
            }
        } catch (Throwable $e) {
            $lpWhy = $e->getMessage();
        }
        ?>
        <?php if ($lp !== null): ?>
        <h3 class="admin-subtitle">🔋 极致低功耗状态</h3>
        <?php if (($lp['disk']['probe'] ?? 'ok') !== 'ok'): ?>
        <p class="muted" style="color:#8a6d3b;background:#fcf8e3;padding:6px 10px;border-radius:4px">
            ℹ️ 本机<b>磁盘探测不可用</b>（<code>disk_free_space</code> / <code>disk_total_space</code>
            被主机 <code>disable_functions</code> 禁用，免费主机常见）——
            <b>与升级包无关</b>，水位控制已自动停用，档位按上面「容量档位」的设置运行，
            <b>各目录硬上限仍然生效</b>。1 GB 空间请手动选「紧凑」。
        </p>
        <?php endif; ?>
        <p class="muted">
            <?php $probeOk = (($lp['disk']['probe'] ?? 'ok') === 'ok'); ?>
            磁盘可用 <?php if ($probeOk): ?>
                <b><?= round($lp['disk']['pct'], 1) ?>%</b>
                （<?= h(bytesHuman((int) $lp['disk']['free'])) ?> / <?= h(bytesHuman((int) $lp['disk']['total'])) ?>）
            <?php else: ?>
                <b>探测不可用</b>（见上方说明）
            <?php endif; ?>；
            容量档位 <b><?= (($lp['caps']['base'] ?? '') === 'compact') ? '紧凑（约 105 MB）' : '标准（约 340 MB）' ?></b>
            <?php if (($lp['caps']['probe'] ?? 'ok') !== 'ok'): ?>
                · 水位<b>未收紧</b>（探测不可用）
            <?php else: ?>
                · 水位 <b><?= h((string) $lp['caps']['tier']) ?></b><?= ((string) ($lp['caps']['tier'] ?? 'normal')) !== 'normal' ? '（上限已按水位缩放）' : '' ?>
            <?php endif; ?>
            <?php if ((int) ($lp['caps']['pct'] ?? 100) < GUARD_REDLINE): ?>
                <span class="alert alert-error" style="padding:2px 6px">低于 <?= GUARD_REDLINE ?>% 红线，已停止写入页面与图片缓存</span>
            <?php endif; ?>
            ；本档上限：页面缓存 <b><?= intval($lp['caps']['page_files']) ?> 个 / <?= round($lp['caps']['page_bytes'] / 1048576) ?> MB</b>、
            图片缓存 <b><?= round($lp['caps']['img_bytes'] / 1048576) ?> MB</b>、
            接口缓存 <b><?= intval($lp['caps']['cache_files']) ?> 个 / <?= round($lp['caps']['cache_bytes'] / 1048576) ?> MB</b>。
        </p>
        <p class="muted">
            页面静态缓存 <b><?= intval($lp['pages']) ?> 个 HTML</b>；
            图片本地缓存 <b><?= h(bytesHuman((int) $lp['imgs'])) ?></b>；
            页面静态化 <b><?= $lp['enabled'] ? '已启用' : '未启用' ?></b>
            <?= $lp['access'] ? '（已开启访问密码 → 按安全要求自动停用）' : '' ?>
            —— 每小时自动换桶过期，配置变更时全量作废，超过 2 小时的桶自动回收。
        </p>
        <?php else: ?>
        <?php [$why, $hint] = $lpDiagnose((string) $lpWhy); ?>
        <h3 class="admin-subtitle">🔋 极致低功耗状态</h3>
        <p class="muted" style="color:#a94442">
            ⚠️ 无法显示 —— <b><?= h($why) ?></b><br>
            <b>处理：</b><?= $hint ?><br>
            <b>影响面：</b>仅本状态栏；下方「系统缓存」照常可用，
            <b>前台也能正常打开</b>（新文件已自带默认值兜底）。
        </p>
        <?php endif; ?>

        <h3 class="admin-subtitle">🧹 系统缓存</h3>
        <p class="muted">
            接口缓存 <b><?= $cacheStat['files'] ?> 个文件 / <?= h($cacheStat['size']) ?></b>；
            列表/详情 <?= intval(CACHE_TTL / 60) ?> 分钟、分类 <?= intval(CACHE_TTL_TYPE / 3600) ?> 小时，
            上游故障时自动降级用旧缓存。
        </p>
        <p class="muted">
            <b>OPcache：</b><?= h($opInfo['msg']) ?><?= $opInfo['available'] ? '；已缓存 ' . intval($opInfo['scripts']) . ' 个脚本 / ' . intval($opInfo['keys']) . ' 个键（上限 ' . intval($opInfo['max_keys']) . '）；' . h($opInfo['memory']) : '' ?>。
        </p>

        <form class="admin-form" method="post" action="admin.php"
              onsubmit="return confirm('确定清理勾选的缓存吗？\n'
                  + '· 接口缓存：清掉后下次访问要重新请求上游，\n'
                  + '  首页可能变慢几秒（每个源各撞一次超时），上游不通时会显示「无法获取分类」\n'
                  + '· 页面缓存 / 图片缓存：清掉后下次访问重新生成，只是慢一点\n'
                  + '· OPcache：上传文件后没变化时才需要勾，否则别勾')">
            <input type="hidden" name="action" value="clear_cache">
            <label class="field-check">
                <input type="checkbox" name="clear_api" value="1">
                <span>接口响应缓存（<code>runtime/cache/*.json</code>）</span>
            </label>
            <label class="field-check">
                <input type="checkbox" name="clear_opcache" value="1" checked>
                <span><b>OPcache（PHP 脚本缓存）</b> —— 上传文件后前台没变化，就是它在跑旧代码</span>
            </label>
            <label class="field-check">
                <input type="checkbox" name="clear_page" value="1">
                <span>页面静态缓存（<code>c/&lt;时间桶&gt;/*.html</code>）—— 改完样式/模板没变化时勾上</span>
            </label>
            <label class="field-check">
                <input type="checkbox" name="clear_img" value="1">
                <span>图片本地缓存（<code>static/imgcache/</code>）—— 封面需要重新从上游取时勾上</span>
            </label>
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <button class="btn btn-danger" type="submit">清理系统缓存</button>
        </form>

        <h3 class="admin-subtitle">📦 配置导入导出</h3>
        <p class="muted">
            导出的是<b>配置</b>（站点设置、模板样式、分组、数据源），
            <b>不含</b>接口响应缓存 —— 那是可重建的缓存。
            密码哈希与 API 密钥<b>默认不导出</b>，需要迁移密码时再勾选。
        </p>

        <form class="admin-form" method="get" action="admin.php">
            <label class="field-check">
                <input type="checkbox" name="secrets" value="1">
                <span>同时导出密码哈希与 API 密钥（⚠️ 文件含敏感信息，勿外传）</span>
            </label>
            <button class="btn btn-primary" type="submit" name="export" value="1">⬇ 导出 JSON</button>
        </form>

        <form class="admin-form" method="post" action="admin.php" enctype="multipart/form-data"
              onsubmit="return confirm('确定导入配置吗？选「覆盖」会清空现有分组与数据源，不可撤销。')">
            <input type="hidden" name="action" value="import_config">
            <label class="field field-wide">
                <span>配置文件（.json）</span>
                <input type="file" name="config_file" accept=".json,application/json,text/plain">
                <small class="muted">也可以不选文件，直接把 JSON 粘贴到下面的框里（文件优先）。</small>
            </label>
            <label class="field field-wide">
                <span>或粘贴 JSON</span>
                <textarea name="config_text" rows="5" spellcheck="false"
                          placeholder='{"format":"vodhub-config","version":1,...}'></textarea>
            </label>
            <label class="field">
                <span>导入方式</span>
                <select name="mode">
                    <option value="merge" selected>合并 —— 按接口地址匹配，只新增/更新，不删除</option>
                    <option value="replace">覆盖 —— 清空现有分组与数据源，完全按文件重建</option>
                </select>
                <small class="muted">
                    两种方式都会写入设置项；文件里没带密钥时，后台密码与访问密码保持不变。
                </small>
            </label>
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <button class="btn btn-primary" type="submit">⬆ 导入配置</button>
        </form>
    </section>