# TYPE ME V2 · Vercel Preview

## 目标

Vercel 仅作为当前 V2 的预览/联调入口，不替代正式生产验收。

当前 Preview 目标链路：

```text
Landing
→ 12 题
→ 主人格 + 隐藏人格
→ 概念 T 恤 Mockup
→ 1080×1920 人格身份证 PNG
→ 分享链接 / 二维码
→ B 用户通过分享链接进入并再次完成测试
```

## 1. Git / 分支

当前 Vercel 适配位于：

```text
v2-development
```

Vercel 项目与 GitHub 仓库连接后，`v2-development` 的后续提交应自动触发 Preview Deployment；`main` 仍保持为正式基线分支。

PR #2 保持 Draft。正式验收前不要把 Preview 当生产站。

## 2. Vercel 运行方式

仓库根目录：

- `Dockerfile.vercel`：FrankenPHP + PHP 8.3
- `Caddyfile`：监听 Vercel 提供的 `$PORT`，同时阻止敏感内部路径对外访问
- `.dockerignore`：阻止 `.env`、商户私钥、运行数据等进入镜像
- `/api/health.php`：检查 PHP 扩展、FreeType 和中文字体

容器内安装：

- `pdo_mysql`
- `gd`
- FreeType（由 GD 提供 `imagettftext`）
- `mbstring`
- `curl`
- `openssl`
- Noto CJK 字体

## 3. 无数据库 Preview 模式

Vercel 自动提供：

```text
VERCEL=1
VERCEL_ENV=preview
```

当同时满足：

```text
VERCEL_ENV=preview
DB_DSN 为空
```

TYPE ME 自动进入 `stateless_preview`。

该模式只用于产品体验：

- 12 题可完整计算正式 `campus-zscore-v1` 结果
- 不要求 `attempt_id` 在另一台容器实例的临时文件中仍然存在
- 人格身份证可直接根据本次 12 个答案重新校验并渲染
- PNG 以内联 data URL 返回，不依赖 Vercel 临时磁盘长期保存
- 分享链接可生成，B 用户可打开继续体验

该模式 **不保证 Dashboard、分享归因计数、订单数据等跨实例持久保存**。

生产绝不能依赖 Vercel `/tmp` 作为数据库。

## 4. 导入 Vercel

在 Vercel 创建项目并导入：

```text
foxigaoqian/type-me-v2
```

测试阶段使用 `v2-development`。

部署后先访问：

```text
https://YOUR_PREVIEW_URL/api/health.php
```

期望：

```json
{
  "ok": true,
  "runtime": "vercel-container",
  "environment": "preview",
  "preview_mode": true,
  "checks": {
    "curl": true,
    "openssl": true,
    "pdo_mysql": true,
    "gd": true,
    "freetype": true,
    "mbstring": true,
    "font": true
  }
}
```

## 5. Preview 阶段暂时不要放的密钥

第一次只测产品体验时，不需要配置：

- `MCHID`
- `APPID`
- `APP_SECRET`
- `API_V3_KEY`
- `SERIAL_NO`
- 商户私钥
- `WECHAT_PLATFORM_CERT_PATH`

尤其不要把这些值写进 Git。

## 6. 自定义测试域名

绑定测试域名后建议设置：

```dotenv
APP_BASE_URL=https://v2.example.com
```

未设置时，代码会根据当前 Host 与 `X-Forwarded-Proto` 自动生成分享 URL，因此 Vercel 的 `*.vercel.app` Preview 也可以正确生成 HTTPS 二维码。

## 7. 接数据库后的变化

一旦 Preview / Staging 配置：

```dotenv
DB_DSN=mysql:host=...;port=3306;dbname=type_me;charset=utf8mb4
DB_USER=...
DB_PASS=...
```

自动关闭无数据库的 `stateless_preview`，恢复严格的 attempt ownership 与持久化流程。

数据库必须先执行：

```bash
mysql -u YOUR_DB_USER -p type_me < migrations/001_init.sql
mysql -u YOUR_DB_USER -p type_me < migrations/002_seed_personality_products.sql
```

注意：96 个 SKU 的 `stock_on_hand` 默认全部为 0。

## 8. 身份证存储

Vercel 容器中默认：

```text
CARD_INLINE_RESPONSE=1
```

因此生成的身份证 PNG 直接返回浏览器，可显示和保存，不依赖容器本地文件长期存在。

正式上线若需要长期保存用户生成卡片，应改接对象存储（Vercel Blob / S3 / R2 等），而不是把容器磁盘当永久盘。

## 9. 正式支付之前

必须完成外部环境：

1. 持久 MySQL/MariaDB
2. 真实 SKU 库存
3. 微信支付商户配置
4. 商户私钥安全注入
5. 微信支付平台证书
6. OAuth / JSAPI 授权域名
7. 公网 HTTPS 支付与退款回调
8. 过期订单对账任务
9. 真实商品图片与商品参数

Preview 测通不等于支付生产验收通过。
