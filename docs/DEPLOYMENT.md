# TYPE ME V2 · Production Deployment Runbook

> 目标：在不把密钥、商户证书、私有订单/统计数据提交 Git 的前提下部署 V2。示例路径需要按真实服务器修改。

## 1. 服务器要求

- PHP 8.x（建议 8.1+）
- PHP 扩展：`curl`、`openssl`、`pdo_mysql`、`gd`、FreeType、`mbstring`
- MySQL 8 / MariaDB 10.5+
- HTTPS
- 可写的私有数据目录（必须在站点 Web 根目录之外）
- 一份可显示中文的字体文件，服务器只配置路径，不提交字体文件到仓库

检查示例：

```bash
php -v
php -m | grep -E 'curl|openssl|pdo_mysql|gd|mbstring'
php -r 'var_dump(function_exists("imagettftext"));'
```

## 2. 环境变量

复制 `.env.example` 为服务器本地 `.env`，但不要提交 `.env`。

必须确认：

```dotenv
MCHID=
APPID=
APP_SECRET=
API_V3_KEY=
SERIAL_NO=
NOTIFY_URL=https://YOUR_DOMAIN/api/wechat/notify.php
REFUND_NOTIFY_URL=https://YOUR_DOMAIN/api/wechat/refund-notify.php
ADMIN_TOKEN=
WECHAT_PLATFORM_CERT_PATH=/ABSOLUTE/PATH/wechatpay-platform-cert.pem
CARD_FONT_PATH=/ABSOLUTE/PATH/NotoSansCJK-Regular.ttc

DB_DSN=mysql:host=127.0.0.1;port=3306;dbname=type_me;charset=utf8mb4
DB_USER=
DB_PASS=

PRIVATE_STORAGE_PATH=/ABSOLUTE/NON_WEB/PATH/type-me-v2-data
ORDER_RESERVATION_TTL_MINUTES=30
```

`PRIVATE_STORAGE_PATH` 必须让 PHP 运行用户可读写，但不能位于域名 DocumentRoot 内。生成的人格身份证 PNG 仍写到 `storage/cards/`，因为该文件本来就需要用户访问/保存。

## 3. 迁移旧运行数据

如果旧站已经在项目根目录 `storage/` 中存在订单、分享、测试或埋点 JSON/NDJSON，先运行：

```bash
php scripts/migrate-private-storage.php
```

默认不会覆盖目标目录同名文件。只有确认目标旧文件可以被覆盖时才使用：

```bash
php scripts/migrate-private-storage.php --force
```

迁移完成后检查 `PRIVATE_STORAGE_PATH` 中的数据，再决定是否删除旧 Web 根目录中的运行文件。

## 4. 初始化数据库

先备份，再执行：

```bash
mysql -u YOUR_DB_USER -p type_me < migrations/001_init.sql
mysql -u YOUR_DB_USER -p type_me < migrations/002_seed_personality_products.sql
```

`002_seed_personality_products.sql` 会建立 8 人格 × 3 颜色 × 4 尺码的 96 个 SKU，但所有 `stock_on_hand` 初始值都是 **0**。

上线前必须按真实库存填写 `skus.stock_on_hand`。不要为了测试购买流程而在生产数据库伪造库存。

检查：

```sql
SELECT product_id,color,size,stock_on_hand,stock_reserved,active
FROM skus
ORDER BY product_id,color,size;
```

## 5. 微信支付

上线前逐项确认：

1. `APPID` 与 `MCHID` 的绑定关系正确。
2. JSAPI 支付授权目录覆盖生产域名。
3. `NOTIFY_URL`、`REFUND_NOTIFY_URL` 为公网 HTTPS 且不带 query string。
4. `WECHAT_PLATFORM_CERT_PATH` 指向当前微信支付平台证书。
5. 商户私钥只存在服务器 `certs/apiclient_key.pem`，不提交 Git。
6. `API_V3_KEY` 为正确的 32 字节密钥。

V2 下单会把微信支付 `time_expire` 与本地 `ORDER_RESERVATION_TTL_MINUTES` 对齐。到期后不能直接释放库存；必须先确认微信订单未支付并关单。

## 6. 过期订单 / 库存预留对账

建议每 5 分钟运行一次：

```cron
*/5 * * * * cd /ABSOLUTE/PATH/type-me-v2 && /usr/bin/php scripts/reconcile-expired-orders.php >> /ABSOLUTE/NON_WEB/PATH/type-me-v2-data/reconcile.log 2>&1
```

脚本行为：

- `SUCCESS`：执行同一个幂等支付 finalize，预留库存转为已售库存。
- `CLOSED / REVOKED / PAYERROR`：释放预留库存。
- `NOTPAY`：先调用微信关单；关单成功后才释放库存。
- `USERPAYING` 或未知状态：保留库存，不擅自释放。
- 微信查单/关单失败：保留库存并返回错误，下一轮再处理。

这样可以避免“用户仍能付款，但本地已经把最后一件库存卖给别人”的竞态。

## 7. 人格身份证

第一次部署后必须真实测试：

1. 完成 12 题。
2. 打开结果页。
3. 点击“生成我的人格身份证”。
4. 确认输出尺寸为 1080×1920。
5. 手机扫描卡片二维码。
6. 确认打开 URL 中存在 `source=share`、`referrer_id`、`share_id`。
7. B 用户完成测试后，Dashboard 的 viral 数据增加。

当前 QR 生成端点由 `QR_API_URL` 配置。正式环境若对第三方 QR 服务有隐私/可用性要求，应切换到自托管 QR 服务；二维码内容仅应包含分享 URL，不放姓名、手机号、地址等个人信息。

## 8. Nginx / Apache

私有运行数据已经支持放到 Web 根目录之外，所以生产安全不应依赖 `.htaccess`。

若仍保留历史 `storage/*.json` 或 `storage/*.ndjson`，Nginx 需要显式禁止访问，例如：

```nginx
location ~* ^/storage/.*\.(json|ndjson|log|lock)$ {
    deny all;
    return 404;
}
```

Apache 项目内已有 `storage/.htaccess` 作为第二层保护。

## 9. 发布前全链路验收

必须至少完成一次：

```text
A Landing
→ 12 题
→ 主人格 + 隐藏人格
→ 1080×1920 身份证
→ A 分享
→ B 通过 share_id 进入
→ B 完成测试
→ A 的裂变归因可查
→ A 查看对应人格 T 恤
→ 选择真实有库存的颜色 / 尺码
→ 下单，SKU 进入 reserved
→ 微信支付成功
→ 回调或查单 finalize
→ stock_reserved 减少、stock_on_hand 减少
→ Dashboard 出现支付数据
→ 发起退款
→ 退款回调状态正确
```

在这条链完整通过前，不建议把 Draft PR 当作生产发布包。
