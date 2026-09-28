# 部署指南

VodHub 零依赖（无 Composer、无 Node、无数据库安装），部署就是「上传 + 给权限」两步。

- [环境要求](#环境要求)
- [虚拟主机（Apache）](#虚拟主机apache)
- [Nginx + PHP-FPM](#nginx--php-fpm)
- [Docker（可选）](#docker可选)
- [权限与目录](#权限与目录)
- [HTTPS](#https)
- [反向代理](#反向代理)
- [升级](#升级)
- [部署后必做的 4 件事](#部署后必做的-4-件事)

---

## 环境要求

| 项目 | 要求 |
|------|------|
| PHP | **≥ 8.0** |
| PHP 扩展 | `curl`、`pdo_sqlite`（必需），`json`、`mbstring`（PHP 8 默认自带） |
| Web 服务器 | Apache 2.4 / Nginx / Caddy 均可 |
| 数据库 | **无需安装**，自动用 SQLite 文件 |
| Composer / Node | **不需要** |

检查你的环境：

```bash
php -v                 # 需要 8.0 或更高
php -m | grep -E 'curl|pdo_sqlite'
```

如果版本太低，程序不会白屏，而是会显示一个明确的「需要 PHP 8.0 及以上」提示页，告诉你当前版本和切换方法。

---

## 虚拟主机（Apache）

最省事的方式，适合大多数免费/付费虚拟主机。

1. **上传**：把项目文件上传到站点根目录（通常是 `htdocs/` 或 `www/`）
   - 注意保持目录结构，`includes/`、`templates/`、`static/` 必须和 `index.php` 同级

2. **权限**：让程序能创建运行期目录

   ```bash
   chmod -R 755 runtime     # 如果目录还不存在，先建：mkdir -p runtime && chmod 755 runtime
   ```

   大多数面板（cPanel / 宝塔 / 主机面板）里也可以直接在文件管理器改权限。

   > 找不到 `runtime/`？**没关系**，程序会自己创建，只要站点根目录可写就行。

3. **访问**：打开 `https://你的域名/admin.php`，用默认密码 `admin123` 登录

### 你需要确认的三件事

`.htaccess` 已经写好了访问控制和静态缓存，但需要：

| 模块 | 作用 | 缺了会怎样 |
|------|------|-----------|
| `mod_rewrite` | 拦截 `runtime/`、`templates/*.php` 直接访问 | 模板可被 URL 直接读取（**建议确认已开**） |
| `mod_expires` | 静态资源缓存 | 无缓存头，仍能正常访问 |
| `mod_headers` | `Cache-Control` | 同上 |

所有规则都包在 `<IfModule>` 里，**模块被禁用时会静默跳过，不会导致 500**。

验证方法：

```bash
# 理想情况返回 403
curl -I https://你的域名/runtime/data.db

# 理想情况返回 200 + Cache-Control 头
curl -I https://你的域名/static/style.css
```

> 如果你的虚拟主机**禁用了 `.htaccess`**（部分主机要求在面板里勾选「AllowOverride」），请在面板里开启，或改用下面的 Nginx 方案。

---

## Nginx + PHP-FPM

```nginx
server {
    listen 443 ssl http2;
    server_name example.com;

    root /var/www/VodHub;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;

    # ---- 静态资源缓存 ----
    location ~* \.(css|js)$ {
        expires 7d;
        add_header Cache-Control "public";
        try_files $uri =404;
    }

    location ~* \.(jpg|jpeg|png|gif|svg|webp|ico|woff2|woff)$ {
        expires 30d;
        add_header Cache-Control "public";
        try_files $uri =404;
    }

    # ---- 安全：禁止直接访问运行期数据 ----
    location ^~ /runtime/ {
        deny all;
        return 404;
    }

    # 模板只应被程序 include，禁止 URL 直接读取
    location ~* ^/templates/.+\.(php|json)$ {
        deny all;
        return 404;
    }

    # ---- 路由 ----
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;   # 按你的版本调整
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_read_timeout 60;      # 上游接口慢时避免 504
    }

    # 动态页面不缓存
    location ~ ^/(index|list|play|search|history|login|logout|admin|img)\.php$ {
        add_header Cache-Control "no-store, no-cache, must-revalidate";
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
}
```

改完重载：`sudo nginx -t && sudo systemctl reload nginx`

---

## Docker（可选）

项目本身极简，如果你偏好容器化，这是个最小示例：

```dockerfile
FROM php:8.3-apache
RUN docker-php-ext-install pdo_mysql opcache \
 && a2enmod rewrite headers expires \
 && sed -i 's|/var/www/html|/var/www/html|g' /etc/apache2/sites-available/000-default.conf

COPY . /var/www/html/
RUN mkdir -p /var/www/html/runtime && chmod -R 777 /var/www/html/runtime
```

```bash
docker build -t vodhub .
docker run -d -p 8080:80 -v vodhub-runtime:/var/www/html/runtime vodhub
```

> 挂载 `runtime/` 卷，容器重建后数据库和缓存不会丢。

---

## 权限与目录

```
VodHub/
├── *.php              # 644
├── includes/          # 755 目录 / 644 文件
├── templates/         # 755 / 644
├── static/            # 755 / 644
├── c/                 # 775（页面静态缓存，**必须可写**，1.3.0 新增，可随时删）
├── static/imgcache/   # 775（图片代理本地副本，**必须可写**，1.3.0 新增，可随时删）
└── runtime/           # 775（必须可写，且不对 Web 公开）
    ├── data.db        # SQLite 数据库（含密码哈希、你配置的数据源）
    └── cache/         # 接口响应缓存
```

**最小权限原则**：只需要 `runtime/`、`c/`、`static/imgcache/` 可写，其他都应该是只读（`644`）。

> **1.3.0 起新增两个可写目录**：
> - `c/` —— 页面静态缓存（Apache 直出，命中时 0 个 PHP 进程）
> - `static/imgcache/` —— 图片代理本地副本（命中时 0 EP、0 出站）
>
> 两者**都是可随时删除的纯缓存**，已加入 `.gitignore`。
> 目录不存在时程序会自动创建（只要站点根目录可写）；
> 写不进去也不会报错，只是退化为动态渲染 / 走 `img.php` 转发。

**`.user.ini`**：项目根目录带了一份生产 PHP 配置（关闭错误显示、打开错误日志、
OPcache 重新校验、会话 GC）。CGI / FPM / LSAPI 主机自动生效；
**mod_php 主机**由 `.htaccess` 里的 `php_value` 接手；两者都失效时
`includes/guard.php` 还有一层运行时兜底。需要排障时设环境变量 `VODHUB_DEBUG=1`
重新打开错误显示。

常见权限问题速查 → [故障排查](troubleshooting.md#权限问题)

---

## HTTPS

强烈建议开启，尤其开了访问密码时（否则密码明文传输）。

- **Let's Encrypt 免费证书**：`certbot --nginx -d example.com`
- **虚拟主机**：面板里一般有「一键 SSL」或「免费证书」按钮
- 开启后建议在后台改一次管理密码（旧会话 cookie 不带 Secure 标记）

---

## 反向代理

如果你把 VodHub 放在另一个域名/子路径后面（如 `https://example.com/vod/`）：

```nginx
location /vod/ {
    proxy_pass http://127.0.0.1:8080/;
    proxy_set_header Host              $host;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

程序使用相对链接，**通常无需额外配置**。如果发现跳转路径不对，请开 Issue 反馈。

---

## 升级

```bash
# 1. 备份（最重要！数据库里有你的全部配置）
cp -r runtime/ /backup/VodHub-runtime-$(date +%F)/

# 2. 用新版本覆盖程序文件（不含 runtime/）
#    绝大多数情况只需要覆盖，不会丢数据
unzip VodHub-1.x.x.zip -d /var/www/   # GitHub 自动归档名 = 仓库名

# 3. 恢复权限
chmod -R 755 runtime

# 4. 访问后台，确认正常
```

**表结构会自动迁移**（`db.php` 里的 `schema_version` 机制），升级时**不需要手工改数据库**，老数据不受影响。

> ⚠️ **备份 `runtime/` 永远是第一步。** 它包含你的管理员密码、全部数据源配置和分组。

---

## 部署后必做的 6 件事

| # | 事项 | 位置 | 为什么 |
|---|------|------|-------|
| 1 | **改管理密码** | 后台 → 访问设置 → 修改管理密码 | 默认 `admin123` 是公开的 |
| 2 | **给 `runtime/` 加访问拒绝** | 上面的 Apache/Nginx 配置 | 里面有密码哈希和你的接口地址 |
| 3 | **开 HTTPS** | Certbot / 面板 | 访问密码和管理密码走明文不安全 |
| 4 | **添加数据源并点「测试」** | 后台 → 数据源管理 | 确认上游通了再启用 |
| 5 | **选对容量档位** | 后台 → 站点与维护 → 容量档位 | **磁盘 ≤1 GB 选「紧凑」**（上限 132 MB）。不少主机的自动探测拿到的是整机磁盘而不是你的配额 |
| 6 | **看一眼极致低功耗状态** | 后台 → 站点与维护 | 确认磁盘 > 8%、页面静态化「已启用」、各项体积在上限内 |

### 1.3.0 升级注意

```bash
# 1. 先备份（老规矩，永远是第一步）
cp -r runtime/ /backup/VodHub-runtime-$(date +%F)/

# 2. 用新版本覆盖程序文件（不含 runtime/）
# 3. 确认两个新目录可写
mkdir -p c static/imgcache && chmod 775 c static/imgcache
```

- **SQLite 开启了 WAL**：会多出 `data.db-wal` / `data.db-shm`，
  **面板备份要连它们一起拷**，否则会丢最近写入。
- **新增 `robots.txt` 与 `.user.ini`**：若站点根目录已有同名文件请手动合并。
- **不需要手工改数据库**，`schema_version` 仍为 5，老库直接可用。
- 回滚很简单：删掉 `c/` 与 `static/imgcache/`、还原程序文件即可
  （这两个目录本来就是可随时删除的纯缓存）。

---

## 下一步

- [配置说明](configuration.md) —— 每个后台设置项什么意思
- [数据源接入](api-sources.md) —— 接口怎么填、图片代理怎么用
- [故障排查](troubleshooting.md) —— 出问题了先看这里
