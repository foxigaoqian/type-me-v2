# TYPE ME V2 · P0 状态

> 本文件按 H5-V2 需求的 P0 顺序维护。P0 未全部验收前，不自动进入 P1。

## 已完成 / 已进入代码

- [x] P0-1：主流程隐藏像素工坊、首页商城和复杂推荐；新 Landing 只推动开始测试。
- [x] P0-2：移动端优先 Landing Page 重做。
- [x] P0-3：3 题升级为 12 题；一题一屏、进度、自动下一题、可返回上一题。
- [x] P0-4：8 人格配置化；服务端计算主人格 + 隐藏人格；同分使用最后 4 题、可选明确倾向、稳定种子。
- [x] P0-5：结果页升级为人格身份、解析、指标、隐藏人格、技能、弱点、真实样本占比、分享、T 恤承接。
- [x] P0-6：人格身份证代码已完成。服务端生成 1080×1920 PNG，二维码自动携带 `source=share&referrer_id=...&share_id=...`；前端可查看/保存 PNG。服务器仍需安装 GD + FreeType + mbstring 并配置中文字体后做真实运行验收。
- [x] P0-7：分享归因。生成 `share_id`，链接携带 `source=share&referrer_id=...&share_id=...`；记录 share open / viral start / viral complete；分享记录支持数据库双写。
- [x] P0-8：人格 T 恤承接、颜色/尺码选择、¥129 人格认证价、旧微信支付/退款能力兼容；数据库启用后先锁 SKU 并事务化预留库存，支付成功回调/查单幂等转为已售库存，失败释放预留。
- [x] P0-9：核心埋点和字段已经进入代码；事件、测试、结果、分享支持数据库双写；运行数据不提交 Git。
- [x] P0-10：Dashboard 第一版，包括流量、测试漏斗、逐题退出率、人格分布、裂变、商品漏斗、销售、人格商业价值、渠道、校园来源；管理接口需要 `ADMIN_TOKEN`。

## 数据库迁移

- `migrations/001_init.sql`：测试、事件、分享、商品、SKU、订单、支付事件、退款全量表结构。
- `migrations/002_seed_personality_products.sql`：8 个人格商品 + 黑/白/灰 × S/M/L/XL SKU。
- SKU 初始库存统一为 **0**，不会伪造库存；上线前必须填写真实库存。
- 设置 `DB_DSN / DB_USER / DB_PASS` 后启用数据库。
- 当前采用迁移期双写：DB 为新数据目标，JSON/NDJSON 暂保留兼容镜像，避免旧接口突然失效。
- JSON/NDJSON 兼容镜像默认写到站点目录之外，也可通过 `PRIVATE_STORAGE_PATH` 指向专门私有数据目录。
- `scripts/migrate-private-storage.php` 用于把旧 Web 根目录 `storage/` 中的运行数据迁到私有目录，默认不覆盖同名目标文件。

## 支付与库存超时

- `ORDER_RESERVATION_TTL_MINUTES` 默认 30 分钟，允许 5–120 分钟。
- 微信 JSAPI/NATIVE 下单同步发送 RFC3339 `time_expire`，支付结束时间与本地库存预留 TTL 对齐。
- `scripts/reconcile-expired-orders.php` 用于 Cron 对账过期 `PENDING_PAYMENT` 订单。
- 已支付：幂等 finalize；已关闭/撤销/失败：释放预留；未支付：先调用微信关单，成功后才释放；`USERPAYING`/未知状态绝不抢先释放。

## 需求未提供、不能伪造的数据

- 真人男/女正面、背面上身图。
- T 恤克重、成分、面料手感、版型、真实尺码参考、洗涤、缩水、透度。
- 各 SKU 的真实库存数量。
- 12 道题每个答案到 8 人格的精确 +3/+2/+1 映射。当前配置是实现阶段草案，并在配置和文档里明确标记，不能当成产品已验收算法。

## 上线前阻断项

1. **服务器身份证运行环境**：安装/启用 PHP `gd`、FreeType、`mbstring`，配置 `CARD_FONT_PATH`，实际生成 1080×1920 PNG，并扫码验证归因参数。
2. **微信支付平台证书**：服务器必须配置 `WECHAT_PLATFORM_CERT_PATH`；支付/退款回调会先验签再解密，未配置证书时会拒绝回调。
3. **数据库落地**：在目标 MySQL/MariaDB 执行 migration，填写真实 SKU 库存，再配置 `DB_DSN`；未配置 DB 时继续走兼容文件存储，不算正式生产形态。
4. **Cron 落地**：服务器需要每 5 分钟执行 `scripts/reconcile-expired-orders.php`，避免未支付订单永久占用预留库存。
5. **真实支付全链路测试**：微信 OAuth → JSAPI/NATIVE 下单 → SKU 预留 → 支付回调/查单 → 库存扣减 → Dashboard → 退款必须在真实商户环境逐项验证。
6. **真实商品数据**：缺失时页面保持 `NEED_REAL_PHOTO / NEED_REAL_PRODUCT_DATA`，禁止用假 UGC 或虚构参数替代。
7. **算法产品验收**：12 题逐选项人格权重必须由产品侧确认后冻结。

## 安全处理

- `.env`、商户私钥、证书、订单 JSON、统计 NDJSON、生成卡片 PNG 均不会提交 Git。
- 私有订单/分享/统计镜像不再依赖 `.htaccess`，默认写到站点 Web 根目录之外；`storage/.htaccess` 仅作为 Apache 第二层保护。
- 管理订单和 Dashboard 接口要求 `X-Admin-Token`。
- 测试答案/结果写入数据库时校验 `attempt_id` 是否属于当前用户。
- 退款和支付状态查询校验订单归属；管理操作可使用管理员 token。
- 微信 OAuth 回跳仅允许当前域名/站内路径，拒绝站外 open redirect。
- 微信支付/退款回调校验时间戳、平台证书序列号、RSA-SHA256 签名后才解密业务数据。
- 订单数据库使用 InnoDB 事务 + `SELECT ... FOR UPDATE` 锁 SKU，避免并发超卖；支付成功回调和主动查单使用同一幂等 finalize。
- CI 检查 PHP/JS/JSON 语法，并阻止典型密钥/运行文件进入仓库。

## 当前结论

P0 的主要代码结构和关键生产防护已经齐全，但仍是 **Draft / 待真实环境验收**。当前不能直接替换生产站，主要剩余工作是服务器环境、真实商品/库存数据、微信商户全链路和算法映射验收。详细部署步骤见 `docs/DEPLOYMENT.md`。
