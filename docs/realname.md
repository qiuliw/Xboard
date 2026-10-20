# 支付宝实名认证（后端）

默认 `enabled / require_purchase / require_subscription` 均为 false；不更改 uuid、token、banned、登录可用性或节点用户下发。实名独立于支付，不复用支付插件配置。

## 接口与兼容

登录后调用：
- `GET /api/v1/user/realname/status`
- `POST /api/v1/user/realname/start`，JSON `{name,id_number,consent:true}`，头 `Idempotency-Key`（16–128 位 `[A-Za-z0-9_-]`，推荐 UUID）。客户端超时后必须复用原 key，不应生成新 key 盲重试。
- `POST /api/v1/user/realname/refresh`，JSON `{attempt_id: UUID}`。

三者成功均是 `{status:"success",message:"...",data:{enabled,required_for_purchase,required_for_subscription,status,verified_at,attempt},error:null}`。

`status`：unverified/pending/verified/failed/expired；`verified_at`：ISO8601 或 null；`attempt`：null 或 `{id,status,auth_url,expires_at,retry_after_seconds}`，流水状态 initializing/pending/uncertain/succeeded/failed/expired。日期 ISO8601，链接只允许 HTTPS `openapi.alipay.com/gateway.do`。初始化尚不确定时顶层为 pending，链接可空，前端不要自动重复提交。

门禁 HTTP403 `{status:"fail",error_code:"REALNAME_REQUIRED",message:"请先完成实名认证",data:null}`，不是登录失效。此分支将旧 user 中间件未登录码由 403 改为 401（兼容注意）；其他既有错误不统一修改。无权访问他人流水返回 404。无效参数 422，幂等参数冲突 409，限额 429，服务故障 503。错误也包含 error_code/message。

## 部署顺序（此实现未部署）

1. 审阅并安装锁定 Composer 依赖。新增官方 `alipaysdk/easysdk:^2.2.3`，必须同步 composer.lock；本地开发可 `composer update alipaysdk/easysdk --no-scripts --no-plugins`，不要无关全量升级。生产由既有发布流程安装。
2. **先迁移后开放新 API**：`php artisan migrate --force` 创建 realname_verifications、realname_attempts、realname_initialize_limits。未迁移时实名 API 不可用；强制策略遇 DB 故障拒绝访问。
3. 服务端环境变量设置：
   - `REALNAME_ENABLED=false`
   - `REALNAME_REQUIRE_PURCHASE=false`
   - `REALNAME_REQUIRE_SUBSCRIPTION=false`
   - `REALNAME_ALIPAY_APP_ID`
   - `REALNAME_ALIPAY_PRIVATE_KEY`（RSA2 商户私钥，不能出现在客户端）
   - `REALNAME_ALIPAY_PUBLIC_KEY`（验签公钥；当前实现为公钥模式，不是证书模式）
   - `REALNAME_ALIPAY_BIZ_CODE`（必须取自实际签约产品，不根据示例猜 FACE 等能力）
   - `REALNAME_RETURN_URL`（固定 HTTPS 前端落地页，不允许 query/fragment/userinfo，不接受请求覆盖）
   - `REALNAME_HMAC_KEY`（独立高熵秘密，至少 32 字节；不要随意轮换，旧幂等/计费桶依赖它）
   - 可选 `REALNAME_DAILY_USER_LIMIT=5`、`REALNAME_DAILY_IP_LIMIT=20`（自然日 Asia/Shanghai，初始化前消耗；0 禁止初始化）。新流水默认冷却 60 秒。
4. 沿用发布流程刷新配置缓存并重启所有 Octane/队列实例。先保留强制开关关闭验收，再按产品决定开启。**require 开关独立于 enabled**：强制开关开启后即使 enabled=false/供应方故障/配置缺失，也不会放行未实名用户；已实名只信本地 verified_at。

`return_url` 仅回到前端；浏览器回跳不修改认证状态、不含本地 token/姓名/证件号/nonce。前端恢复会话调用 status/refresh，只有服务端对绑定 certifyId 的签名 query 明确 passed=T 才写 verified_at。无实名 callback 接口。

## 安全与故障语义

- 姓名、证件号只在请求和供应方调用内存中使用，不存表、不拼 URL、不写日志。请求在验证后移除这两个字段；校验失败不闪存输入。HMAC 保存幂等与负载指纹，certifyId/链接通过 APP_KEY 加密。不得开启请求/响应 body、SQL bindings、代理 URI query、APM/debugbar 的实名链路捕获；上游及数据库异常不记录原异常。对支付宝出站 query/认证链接也应禁用敏感日志。日志安全需要运维侧一起保证。
- 每用户 DB 行锁序列化流水预留，同用户不同 key 不能并行创建；唯一 user+幂等、outer_order_no、certifyId HMAC。用户/IP 每日额度用持久化 HMAC 桶，不能靠重启清零；配置可信代理后 IP 限额才可靠，多实例共享 DB。
- initialize 超时/异常/不明确响应且无 certifyId：保留 uncertain，不自动重发；无 certifyId 无法 query，需人工对账。禁止为了恢复按钮删除不确定流水，否则可能重复计费。当前未提供运营人工解除接口。
- query 非明确结果/网络失败/验签失败：uncertain，不写 verified、不视为最终失败/过期。SDK passed 字符串严格解析，只有 T 是成功；**F 在实际产品未确认终态前保守为 unknown**，避免未完成认证被当作失败反复初始化。签名业务错误 CERTIFY_ID_EXPIRED 才标记 expired。
- expires_at 是本地链接展示期限（默认 30 分钟），不是供应方有效期承诺；超时隐藏链接但仍可 query，也不会据此释放 uncertain 创建新的计费流水。原始失败详情、identityInfo/materialInfo/人脸不保存不返回。
- realname_initialize_limits 可由维护任务删除 7 天前的桶；当前不额外引入调度任务。认证记录/流水留存期、删除/注销策略由产品与合规确定；APP_KEY/HMAC_KEY 需安全备份。

## 门禁范围

购买：OrderService.createFromRequest（扣余额/建单前）、checkout（含免费/历史未付订单）、礼品卡授予套餐/有效期/流量/设备/重置权益（纯余额充值不拦）。订阅：getSubscribe、resetSecurity（轮换前，含 v2 复用入口）、ClientController.doSubscribe、user/server/fetch（含 ETag 304 前）、知识库包含三种订阅 URL 占位符的内容、Telegram 取订阅链接。

不把可停用 hook 当唯一授权。既有支付回调验签和 paid/open 幂等履约保持原样，不在已收款后拒绝履约。管理员代操作与节点用户同步不加全局门禁；未实名仍可登录、查看普通信息和不含订阅凭据的帮助文章。

## 验证与依赖

本机专用 Compose、HTTP 冒烟脚本和 E2E fake router 已按发布要求移除，不进入生产包。保留下述隔离单元/接口测试；测试中的 fake 供应方不在应用路由或生产服务中注册。

本次 Composer 定向更新新增 4 个支付宝 SDK 相关包，并移除原锁文件中已不在 composer.json 要求下的 13 个 Reverb 相关历史包；没有升级既有包版本。锁文件校验和安装 dry-run 已通过。依赖审计提示既有 48 条安全公告涉及 16 个包，未在此任务自动升级。

`/realname/return` 为无外部资源的 HTTPS 回跳落地页，只提示返回客户端查询，不消费任何认证成功参数。生产 `REALNAME_RETURN_URL` 应与实际可信域名对应。

## PHP 隔离测试

`tests/Feature/Realname/RealnameTest.php` 在任何 provider boot 前强制 SQLite :memory:、array cache/mail/session、fake供应方、fake bus；禁用插件初始化。建议用临时容器执行：`php vendor/bin/phpunit tests/Feature/Realname/RealnameTest.php`，运行时 `--network none`，仅挂载开发副本，不连接现有服务。不要运行真实实名认证或使用真实身份材料。

待产品/上线环境确认：实际签约 biz_code/准入及费用、F 最终性与失败码、供应方TTL/重查窗口、公钥或证书模式、return_url与前端落地、频率额度、隐私告知与同意文案、人工对账/解卡、数据留存与删除策略。此分支不声称已具备这些商户签约能力。
