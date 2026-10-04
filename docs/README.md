# 📚 VodHub 文档

按你想做的事选入口，不用从头读。

## 我想把它跑起来

**→ [部署指南](deployment.md)**

环境要求、虚拟主机 / Nginx / Docker 三种方式、权限、HTTPS、升级、部署后必做清单。

## 我想用起来

| 我想… | 看这篇 |
|-------|--------|
| 搞懂后台每个设置项是什么意思 | [配置说明](configuration.md) |
| 添加一个数据源 | [数据源接入](api-sources.md) |
| 封面图挂了 / 有防盗链 | [数据源接入 · 图片代理](api-sources.md#图片代理与防盗链) |
| 给某个源换一套显示风格 | [数据源接入 · 选模板](api-sources.md#选模板看封面比例) |
| 部署在 1 GB / 5 GB 免费空间上 | [极致低功耗模式](lowpower.md) |
| 想让配额（EP/磁盘）基本无感 | [极致低功耗模式](lowpower.md) |
| 播放页那些「地区 / 语言 / 类型」标签是怎么来的 | [配置说明 · 字段智能归一化](configuration.md) |
| 关掉 AI 标签功能会少什么 | [配置说明 · 字段智能归一化](configuration.md) |
| 遇到问题了 | [故障排查](troubleshooting.md) |

## 我想改代码 / 做模板

| 我想… | 看这篇 |
|-------|--------|
| 新写一套模板 | [模板开发](../docs/templates.md) |
| 搞懂后台改样式为什么不生效 | [模板开发 · CSS 变量编辑器](templates.md#css-变量编辑器) |
| 提 PR / 报 Bug | [贡献指南](../CONTRIBUTING.md) |
| 报安全漏洞 | [安全策略](../SECURITY.md) |

## 快速索引

| 文件 | 覆盖的问题 |
|------|-----------|
| [deployment.md](deployment.md) | 装不上、权限、Nginx/Apache 配置、HTTPS、反向代理、升级 |
| [configuration.md](configuration.md) | 设置项含义、缓存策略、列数优先级、数据存在哪 |
| [api-sources.md](api-sources.md) | 接口格式、地址怎么填、用哪些字段、封面比例、图片代理 |
| [templates.md](templates.md) | 模板结构、可用变量、CSS 变量编辑器、从零做模板、常见坑 |
| [lowpower.md](lowpower.md) | 极致低功耗模式：四层隔离、容量档位、水位红线、验证方法、已知边界 |
| [troubleshooting.md](troubleshooting.md) | **按症状查**：白屏、图裂、模板不生效、后台报错、性能 |

> **关于播放页的 AI 标签**（地区 / 语言 / 类型 / 更新状态）：
> 它由 TypeSafe System One 归一化，是**锦上添花**——
> 关掉（后台「站点设置」取消勾选）后站点功能完整，只少那几个标签。
> 它的原理、启停方式与已知限制写在
> [配置说明 · 字段智能归一化](configuration.md)。

---

> 文档有问题或没写清楚？[提个 Issue](https://github.com/hopol/VodHub/issues) 或直接提 PR 改 —— 每一次修正都在帮下一个人少踩一个坑。
