# V2Board 用户主题 UI/UX 设计规格文档（页面 × 功能 × 接口对照）

> 本文档面向新用户主题的 UI/UX 设计与前端开发，逐页列出功能模块、UI 组件与后端接口的完整对应关系。
> 依据代码版本：`app/Http/Routes/V1/*`、`app/Http/Controllers/V1/{Passport,Guest,User}/*` 实际实现整理。

## 全局约定

| 事项 | 规则 |
|---|---|
| 接口前缀 | 统一为 `/api/v1` |
| 响应格式 | 成功：`{"data": ...}`；错误：HTTP 4xx/5xx + `{"message": "..."}`，全局 toast 提示 |
| 认证方式 | `/user/*` 接口需请求头 `Authorization: {auth_data}`（登录返回的 JWT）；401/403 全局拦截跳登录页 |
| 金额单位 | 一律为**分**，显示需 ÷100 并拼接 `currency_symbol` |
| 流量单位 | 用户流量/节点数据为**字节**；套餐 `transfer_enable` 为 **GB** |
| 时间格式 | Unix 秒级时间戳；`expired_at = null` 显示「长期有效」 |
| 防重复提交 | 下单、结算、兑换、划转、发工单等按钮必须防抖/锁定 |
| 轮询点 | 收银台支付状态 3~5s；工单详情 5~10s |
| i18n | 主题自带多语言包（默认主题含 zh-CN / zh-TW / en-US / ja-JP / ko-KR / vi-VN / fa-IR），需语言切换控件 |
| 入口模板 | `public/theme/<name>/dashboard.blade.php`，主题配置声明于 `config.json` 的 `configs`（支持 input / select / textarea） |

## 页面总览

| # | 页面 | 路由 | 认证 |
|---|---|---|---|
| 1 | 登录页 | `/#/login` | 否 |
| 2 | 注册页 | `/#/register` | 否 |
| 3 | 忘记密码页 | `/#/forgetpassword` | 否 |
| 4 | 主布局（框架） | — | 是 |
| 5 | 仪表盘 | `/#/dashboard` | 是 |
| 6 | 节点状态页 | `/#/node` | 是 |
| 7 | 套餐商店页 | `/#/plan` | 是 |
| 8 | 下单配置页 | `/#/plan/:id` | 是 |
| 9 | 收银台页 | `/#/order/:trade_no` | 是 |
| 10 | 我的订单页 | `/#/order` | 是 |
| 11 | 邀请返利页 | `/#/invite` | 是 |
| 12 | 个人中心页 | `/#/profile` | 是 |
| 13 | 流量明细页 | `/#/traffic` | 是 |
| 14 | 工单列表页 | `/#/ticket` | 是 |
| 15 | 工单详情页 | `/#/ticket/:id` | 是 |
| 16 | 知识库页 | `/#/knowledge` | 是 |
| 17 | 公告详情（弹窗/页） | — | 是 |

---

## 1. 登录页 `/#/login`

**UI 组件**：居中卡片表单、Logo、背景图、语言切换下拉、reCAPTCHA 组件（按需渲染）。

| 功能 | 交互说明 | 接口 | 请求参数 | 响应/处理 |
|---|---|---|---|---|
| 页面初始化 | 拉取站点名/Logo/描述、是否显示 reCAPTCHA | `GET /guest/comm/config` | — | `logo`、`app_description`、`is_recaptcha`、`recaptcha_site_key`、`tos_url` |
| 账号登录 | 邮箱+密码表单，提交按钮防抖 | `POST /passport/auth/login` | `email`、`password`(≥8位)、`recaptcha_data`(开启时) | `data:{token, is_admin, auth_data}` → 存 `auth_data` 并设 `Authorization` 头；`is_admin=true` 可显示「进入后台」 |
| 密码错误锁定提示 | 同一邮箱错 5 次锁 60 分钟 | （同上，500 错误） | — | message：`There are too many password errors…` |
| 快捷登录（免密） | URL 含 `?verify=xxx&redirect=xxx` 时自动执行 | `GET /passport/auth/token2Login?verify={code}` | `verify`（一次性临时码，60s 有效） | 响应同登录；成功后跳 `redirect` 页；失效提示 `Token error` |
| 跳转注册/忘记密码 | 底部链接 | — | — | 前端路由跳转 |

## 2. 注册页 `/#/register`

**UI 组件**：注册表单卡片、邮箱验证码行（带倒计时按钮）、邀请码输入框、服务条款勾选、reCAPTCHA。

| 功能 | 交互说明 | 接口 | 请求参数 | 响应/处理 |
|---|---|---|---|---|
| 页面初始化 | 决定表单形态 | `GET /guest/comm/config` | — | `is_email_verify`(1→显示验证码行)、`is_invite_force`(1→邀请码必填并锁定)、`email_whitelist_suffix`(数组→邮箱变「前缀输入+后缀下拉」)、`is_recaptcha`、`tos_url` |
| 邀请码 PV 统计 | URL 带 `?code=xxx` 打开时自动上报并填充邀请码 | `POST /passport/comm/pv` | `invite_code` | `data:true`（静默调用） |
| 发送邮箱验证码 | 按钮 60s 倒计时 | `POST /passport/comm/sendEmailVerify` | `email`、`recaptcha_data`、`isforget=0` | `data:true`；错误：邮箱已注册 / 后缀不在白名单 / Gmail 别名不支持 / 60s 内重复发送 / 同 IP 限流(429)；验证码 6 位数字、300s 有效 |
| 提交注册 | 表单：邮箱、验证码(6位)、密码、确认密码、邀请码、TOS 勾选 | `POST /passport/auth/register` | `email`、`password`、`email_code`、`invite_code`、`recaptcha_data` | `data:{token, is_admin, auth_data}` → 注册即登录，直接跳仪表盘；错误：邮箱已存在 / 注册已关闭 / 必须邀请码 / 邀请码无效 / 注册频繁(IP 限制) |

## 3. 忘记密码页 `/#/forgetpassword`

**UI 组件**：三段式表单（邮箱 → 验证码 → 新密码）。

| 功能 | 接口 | 请求参数 | 响应/处理 |
|---|---|---|---|
| 发送验证码 | `POST /passport/comm/sendEmailVerify` | `email`、`isforget=1`、`recaptcha_data` | 错误：`This email is not registered in the system` |
| 重置密码 | `POST /passport/auth/forget` | `email`、`email_code`(6位数字)、`password`(新密码) | `data:true` → 提示成功回登录页；后端清除该用户全部会话；验证码错 3 次限流 5 分钟 |

---

## 4. 主布局（侧边栏 + 顶栏，登录后页面共用）

**UI 组件**：可折叠侧边导航（移动端抽屉/底部 TabBar）、顶栏（Logo、头像、余额、登出）、导航红点角标。

| 功能 | 交互说明 | 接口 | 响应/处理 |
|---|---|---|---|
| 路由守卫 | 每次进入受保护路由校验 | `GET /user/checkLogin` | `data:{is_login, is_admin?}`；401 → 清 token 跳登录 |
| 用户区全局配置 | 登录后调一次，全局缓存 | `GET /user/comm/config` | `is_telegram`、`telegram_discuss_link`、`stripe_pk`、`currency`、`currency_symbol`、`withdraw_methods`、`withdraw_close`、`commission_distribution_enable/l1/l2/l3` |
| 顶栏用户信息 | 头像、邮箱、余额挂件 | `GET /user/info` | `avatar_url`（cravatar，后端已拼好）、`email`、`balance`(分÷100) |
| 导航红点 | 订单/工单菜单角标 | `GET /user/getStat` | `data:[待付订单数, 未关工单数, 邀请人数]`（固定顺序数组） |
| TG 群链接按钮 | `telegram_discuss_link` 非空时显示 | —（复用配置） | 外链新窗口打开 |
| 登出 | 清本地 auth_data | —（纯前端） | 跳登录页 |

## 5. 仪表盘 `/#/dashboard`

**UI 组件**：公告走马灯/卡片、订阅概览卡（环形进度条）、待办提醒条、一键订阅图标网格、二维码弹窗。

| 功能模块 | UI 组件 | 接口 | 响应字段 → 用途 |
|---|---|---|---|
| 公告轮播/列表 | 走马灯或卡片，点击开详情弹窗 | `GET /user/notice/fetch?current=1&pageSize=5` | `data:[{id,title,content(Markdown),img_url,tags[]}], total`（pageSize 上限 100） |
| 订阅概览卡 | 套餐名+到期倒计时+流量环形进度+在线设备 | `GET /user/getSubscribe` | `plan.name`、`expired_at`(null=长期)、进度=`(u+d)/transfer_enable`、`alive_ip`/`device_limit`、`reset_day`(流量重置倒计时，null=不重置) |
| 待办提醒条 | 有未付订单/未关工单时显示 | `GET /user/getStat` | 数组[0]>0 → 「您有待支付订单」链到订单页 |
| 一键订阅按钮组 | 客户端图标网格（Clash / ClashMeta / Shadowrocket / Sing-box / Stash / Surge / Surfboard / QuantumultX / Hiddify / NekoBox 等） | —（复用 `subscribe_url`） | 前端拼 scheme 深链：`clash://install-config?url=…`、`sing-box://import-remote-profile?url=…`、`shadowrocket://add/sub://…` 等，接口不提供 |
| 复制订阅/二维码 | 只读输入框+复制按钮+二维码弹窗 | 同上 | `subscribe_url` |
| 更新周期按钮 | 仅 `allow_new_period=1` 且流量用尽时显示，带确认框 | `POST /user/newPeriod` | `data:true` → 刷新订阅卡；错误：「流量未用尽不能续期」「剩余时间不足」等 |
| 应用下载卡（可选） | Windows/macOS/Android 版本+下载链接 | —（站点配置/主题配置提供） | — |

## 6. 节点状态页 `/#/node`

**UI 组件**：节点表格/卡片列表、协议 Badge、在线状态点、协议/标签筛选器、空态引导。

| 功能 | 接口 | 说明 |
|---|---|---|
| 节点列表 | `GET /user/server/fetch` | 每项 `{id, type, name, host, port, rate(倍率), tags[], is_online, last_check_at, ...协议特有字段}`；`type` ∈ shadowsocks / vmess / vless / trojan / hysteria / tuic / anytls |
| 协商缓存 | 同上带 `If-None-Match` 头 | 支持 ETag，命中返回 304，用本地缓存渲染 |
| 空态 | 同上 | 无有效订阅时返回 `[]` → 显示「暂无可用节点」+ 引导去商店按钮 |
| 筛选 | —（纯前端） | 按协议/标签过滤 |

## 7. 套餐商店页 `/#/plan`

**UI 组件**：套餐卡片网格、Markdown 渲染的功能清单、售罄 Badge。

| 功能 | 接口 | 说明 |
|---|---|---|
| 套餐卡片网格 | `GET /user/plan/fetch` | `data:Plan[]`（仅 show=1，按 sort 排序）；字段：`name`、`content`(Markdown 功能清单)、`transfer_enable`(GB)、`device_limit`、`speed_limit`(Mbps，null 不限)、8 档价格（**分**，null 的周期不渲染）：`month_price / quarter_price / half_year_price / year_price / two_year_price / three_year_price / onetime_price / reset_price` |
| 售罄标记 | 同上 | `capacity_limit` 已是**剩余名额**（后端已减在用人数），≤0 显示售罄禁点；null 不限 |
| 选购跳转 | — | 跳 `/#/plan/:id` |

## 8. 下单配置页 `/#/plan/:id`

**UI 组件**：套餐详情区、周期单选分段控件、优惠券输入行、订单金额摘要、提交按钮。

| 功能 | 交互说明 | 接口 | 请求参数 | 响应/处理 |
|---|---|---|---|---|
| 套餐详情 | 标题+Markdown 内容 | `GET /user/plan/fetch?id={id}` | — | `data:Plan`；隐藏但可续费的套餐（老用户自己的）也可查到；无权限 500 → 回商店页 |
| 周期选择 | 单选分段控件（月/季/半年/年/两年/三年/一次性/流量重置包） | —（复用详情数据） | — | 只渲染非 null 价格档 |
| 优惠券验证 | 输入框+「验证」按钮，成功显示折后价 | `POST /user/coupon/check` | `code`(必填)、`plan_id` | `data:{name, type(1金额减/2比例%), value, limit_period[], limit_plan_ids, ...}`；据 `limit_period` 禁用不适用周期；失败 500 toast 原因（过期/次数用尽/不适用等） |
| 订单金额摘要 | 小计/优惠/应付合计，提示「余额自动抵扣」 | —（前端计算） | — | 余额抵扣由后端下单时自动完成 |
| 提交订单 | 按钮防抖锁定 | `POST /user/order/save` | `plan_id`、`period`(枚举：`month_price`…`onetime_price`/`reset_price`)、`coupon_code`(可选) | `data:"trade_no"` → 跳收银台；错误「You have an unpaid or pending order…」→ 弹窗引导跳已有订单；其余：售罄 / 该周期不可购 / 重置包需有效订阅 / 不可续费需换套餐 |

## 9. 收银台页 `/#/order/:trade_no`

**UI 组件**：订单信息卡、金额明细行、支付方式单选卡片、二维码弹窗、Stripe Elements 表单、支付状态轮询与成功动画。

| 功能 | 接口 | 请求参数 | 响应/处理 |
|---|---|---|---|
| 订单信息 | `GET /user/order/detail?trade_no=` | — | `total_amount`(应付)、`balance_amount`(余额抵扣)、`discount_amount`(优惠)、`surplus_amount`(旧订阅折抵)、`handling_amount`(手续费)、`surplus_orders[]`、`plan`(对象；充值单为 `{id:0, name:'deposit'}`)；充值单含 `bounus`(满赠)、`get_amount`(实得) |
| 支付方式选择 | `GET /user/order/getPaymentMethod` | — | `data:[{id, name, payment, icon, handling_fee_fixed, handling_fee_percent}]`；手续费=总额×percent/100+fixed 实时显示 |
| Stripe 卡支付 | `POST /user/comm/getStripePublicKey` | `id`(payment id) | `data:"pk_live_…"` 初始化 Stripe Elements 取 token |
| 结算 | `POST /user/order/checkout` | `trade_no`、`method`(支付方式 id)、`token`(仅 Stripe) | 按响应 `type` 分支：`1`→跳转 data 中 URL；`0`→data 为二维码内容，渲染二维码弹窗；`-1`→0 元单直接成功跳结果页 |
| 支付状态轮询 | `GET /user/order/check?trade_no=` | — | `data:status`（0待付/1开通中/2已取消/3已完成/4已折抵）；每 3~5s 轮询，status=1 或 3 即成功 |
| 取消订单 | `POST /user/order/cancel` | `trade_no` | `data:true`；仅 status=0 可取消，带确认框 |

## 10. 我的订单页 `/#/order`

**UI 组件**：订单表格/卡片列表、状态 Tag（5 态配色）、状态筛选 Tab。

| 功能 | 接口 | 说明 |
|---|---|---|
| 订单列表 | `GET /user/order/fetch[?status=n]` | `data:Order[]`（含内嵌 `plan` 对象，按创建时间倒序）；`status`：0待付/1开通中/2取消/3完成/4折抵；`type`：1新购/2续费/3升级/4流量重置 |
| 状态筛选 | 同上带 `status` 参数 | — |
| 行操作 | 复用第 9 节接口 | 待付→「去支付」(跳收银台)、「取消」；其余→「查看」 |

## 11. 邀请返利页 `/#/invite`

**UI 组件**：统计数字卡片组（5 张）、邀请码表格、佣金明细分页表格、划转/提现模态框。

| 功能 | 接口 | 请求参数 | 响应/处理 |
|---|---|---|---|
| 统计卡片组 | `GET /user/invite/fetch` | — | `stat:[已注册人数, 累计佣金(分), 确认中佣金(分), 佣金比例(%), 可用佣金(分)]` |
| 邀请码表格 | 同上 | — | `codes:[{id, code, pv, created_at}]`；「复制链接」= `{app_url}/#/register?code={code}` |
| 生成邀请码 | `GET /user/invite/save` | — | `data:true`；达 `invite_gen_limit` 上限报 `The maximum number of creations has been reached` |
| 佣金明细 | `GET /user/invite/details?current=&page_size=` | 分页参数（page_size≥10） | `{data:[{trade_no, order_amount, get_amount, created_at}], total}`（金额分） |
| 佣金划转 | `POST /user/transfer` | `transfer_amount`(分) | `data:true`（佣金→余额）；错误 `Insufficient commission balance` |
| 佣金提现 | `POST /user/ticket/withdraw` | `withdraw_method`(须在 `withdraw_methods` 白名单内，做下拉)、`withdraw_account` | `data:true`（系统自动创建高优先级工单）；`withdraw_close=1` 时隐藏整个入口；错误：低于最低提现额（limit 单位**元**）/ 方式不支持 |
| 多级分销说明 | —（复用 `/user/comm/config` 缓存） | — | `commission_distribution_enable=1` 时展示 L1/L2/L3 比例 |

## 12. 个人中心页 `/#/profile`

**UI 组件**：账户信息卡、余额卡、密码表单、Switch 开关组、Telegram 绑定弹窗、礼品卡兑换行、会话管理表格、危险操作区。

| 功能 | 接口 | 请求参数 | 响应/处理 |
|---|---|---|---|
| 账户信息卡 | `GET /user/info` | — | `avatar_url`、`email`、`created_at`、`last_login_at` |
| 余额卡+充值 | `POST /user/order/save` | `plan_id=0`、`deposit_amount`(分) | `data:"trade_no"` → 跳收银台；充值模态框展示 `deposit_bounus` 满赠阶梯（detail 返回 bounus/get_amount） |
| 修改密码 | `POST /user/changePassword` | `old_password`、`new_password`(≥8位) | `data:true` → 后端清全部会话，前端清 token 跳登录 |
| 通知/续费开关 | `POST /user/update` | `remind_expire` / `remind_traffic` / `auto_renewal`(0/1，可选传) | `data:true`；Switch 初值取自 `GET /user/info` |
| Telegram 绑定 | `GET /user/telegram/getBotInfo` | — | `data:{username}` → 弹窗展示 `t.me/{username}` + 说明「将订阅链接发给 bot 完成绑定」；仅 `is_telegram=1` 显示模块 |
| Telegram 解绑 | `GET /user/unbindTelegram` | — | `data:true`；`telegram_id` 非空时显示「解绑」按钮 |
| 礼品卡兑换 | `POST /user/redeemgiftcard` | `giftcard`(卡密) | `{data:true, type:1-5, value}` 按类型弹结果窗：1=余额+value分 / 2=时长+value天 / 3=流量+value GB / 4=流量重置 / 5=兑换套餐(value=天数，0 永久)；错误：不存在/未生效/已过期/次数用尽/本人已用/类型不适用 |
| 活跃会话管理 | `GET /user/getActiveSession`；`POST /user/removeActiveSession` | —；`session_id` | 返回对象字典 `{<session_id>:{ip, login_at, ua}}`，key 即 session_id；每行「下线」按钮 |
| 重置订阅信息 | `GET /user/resetSecurity` | — | `data:"新订阅链接"`；危险区红色按钮+二次确认框，提示旧订阅立即失效（UUID/token 全换） |

## 13. 流量明细页 `/#/traffic`

**UI 组件**：流量记录表格（字节格式化 GB/MB）。

| 功能 | 接口 | 说明 |
|---|---|---|
| 流量记录表 | `GET /user/stat/getTrafficLog` | `data:[{u, d, record_at, server_rate}]`；**仅返回本月起数据**，按日聚合、倒序；计费流量=(u+d)×server_rate |

## 14. 工单列表页 `/#/ticket`

**UI 组件**：工单表格、等级/状态 Tag、新建工单模态框。

| 功能 | 接口 | 请求参数 | 说明 |
|---|---|---|---|
| 工单列表 | `GET /user/ticket/fetch` | — | `data:[{id, subject, level, status, reply_status, created_at, updated_at}]`；`level`：0低/1中/2高；`status`：0开启/1关闭；`reply_status`：0=客服已回复(高亮提醒)、1=等待回复 |
| 新建工单 | `POST /user/ticket/save` | `subject`、`level`(0/1/2)、`message` 均必填 | `data:true`；错误：`There are other unresolved tickets`（引导跳已有工单）/「请先购买套餐」(工单权限=仅付费用户) /「当前套餐不允许发起工单」(完全关闭) |

## 15. 工单详情页 `/#/ticket/:id`

**UI 组件**：聊天气泡界面（`is_me` 区分左右）、底部输入框、关闭按钮。

| 功能 | 接口 | 请求参数 | 说明 |
|---|---|---|---|
| 消息记录 | `GET /user/ticket/fetch?id=` | `id` | `data:Ticket & {message:[{message, created_at, is_me}]}`；5~10s 轮询刷新 |
| 回复 | `POST /user/ticket/reply` | `id`、`message` | **上一条为自己发的则禁用发送按钮**（否则报 `Please wait for the technical enginneer to reply`）；已关闭工单禁用输入 |
| 关闭工单 | `POST /user/ticket/close` | `id` | `data:true` → 界面变只读，带确认框 |

## 16. 知识库页 `/#/knowledge`

**UI 组件**：分类折叠面板、搜索框、富文本文章渲染（弹窗或详情页）。

| 功能 | 接口 | 请求参数 | 说明 |
|---|---|---|---|
| 分类文章列表 | `GET /user/knowledge/fetch?language=zh-CN` | `language`(跟随 i18n 切换) | `data:{"分类名":[{id, title, updated_at}]}`（**按 category 分组的对象**结构） |
| 关键词搜索 | 同上加 `&keyword=` | `keyword` | 标题+正文模糊匹配 |
| 文章详情 | `GET /user/knowledge/fetch?id=&language=` | `id` | `data:{title, body, ...}`；`body` 为 HTML/Markdown，**必须以富文本/HTML 渲染** |

**文章 body 渲染注意**：

- 占位符已被后端替换为真实值：`{{siteName}}`、`{{subscribeUrl}}`、`{{urlEncodeSubscribeUrl}}`、`{{safeBase64SubscribeUrl}}`、`{{subscribeToken}}` —— 文章内会直接包含用户订阅链接与一键导入按钮 HTML。
- 无有效订阅用户：`<!--access start-->…<!--access end-->` 区域被替换为 `<div class="v2board-no-access">…</div>`，**主题必须为 `.v2board-no-access` 类提供样式**（建议做成引导购买卡片）。

## 17. 公告详情（弹窗或独立页）

| 功能 | 接口 | 说明 |
|---|---|---|
| 单条公告 | `GET /user/notice/fetch?id={id}` | `data:Notice`（title、content(Markdown)、img_url、tags[]）；不存在返回 **404**（其他接口错误多为 500，此处特殊） |

---

## 附录 A：接口清单速查（按前缀分组）

### Guest（无需认证）

| 方法 | 路径 | 用途 |
|---|---|---|
| GET | `/guest/comm/config` | 站点公开配置（未登录页初始化） |

### Passport（无需认证）

| 方法 | 路径 | 用途 |
|---|---|---|
| POST | `/passport/auth/login` | 登录 |
| POST | `/passport/auth/register` | 注册（注册即登录） |
| GET | `/passport/auth/token2Login` | 临时码免密登录 |
| POST | `/passport/auth/forget` | 重置密码 |
| POST | `/passport/auth/getQuickLoginUrl` | 由 auth_data 换免密链接 |
| POST | `/passport/comm/sendEmailVerify` | 发送邮箱验证码 |
| POST | `/passport/comm/pv` | 邀请码 PV 统计 |

### User（需 Authorization 头）

| 方法 | 路径 | 用途 | 使用页面 |
|---|---|---|---|
| GET | `/user/checkLogin` | 登录态检查 | 路由守卫 |
| GET | `/user/info` | 用户信息 | 布局/个人中心 |
| GET | `/user/getStat` | 待办统计 | 布局红点 |
| GET | `/user/getSubscribe` | 订阅详情 | 仪表盘 |
| GET | `/user/resetSecurity` | 重置订阅信息 | 个人中心 |
| POST | `/user/newPeriod` | 更新周期 | 仪表盘 |
| POST | `/user/redeemgiftcard` | 礼品卡兑换 | 个人中心 |
| POST | `/user/changePassword` | 修改密码 | 个人中心 |
| POST | `/user/update` | 通知/续费开关 | 个人中心 |
| POST | `/user/transfer` | 佣金划转 | 邀请页 |
| POST | `/user/getQuickLoginUrl` | 免密登录链接 | 跨端跳转 |
| GET | `/user/getActiveSession` | 会话列表 | 个人中心 |
| POST | `/user/removeActiveSession` | 下线会话 | 个人中心 |
| GET | `/user/unbindTelegram` | 解绑 Telegram | 个人中心 |
| GET | `/user/telegram/getBotInfo` | Bot 信息 | 个人中心 |
| GET | `/user/comm/config` | 用户区配置 | 布局初始化 |
| POST | `/user/comm/getStripePublicKey` | Stripe 公钥 | 收银台 |
| GET | `/user/plan/fetch` | 套餐列表/详情 | 商店/下单页 |
| POST | `/user/coupon/check` | 优惠券验证 | 下单页 |
| POST | `/user/order/save` | 创建订单/充值单 | 下单页/充值 |
| GET | `/user/order/detail` | 订单详情 | 收银台 |
| GET | `/user/order/getPaymentMethod` | 支付方式列表 | 收银台 |
| POST | `/user/order/checkout` | 结算 | 收银台 |
| GET | `/user/order/check` | 支付状态轮询 | 收银台 |
| GET | `/user/order/fetch` | 订单列表 | 我的订单 |
| POST | `/user/order/cancel` | 取消订单 | 收银台/订单列表 |
| GET | `/user/server/fetch` | 节点列表（ETag） | 节点页 |
| GET | `/user/stat/getTrafficLog` | 流量明细 | 流量页 |
| GET | `/user/invite/fetch` | 邀请概览 | 邀请页 |
| GET | `/user/invite/save` | 生成邀请码 | 邀请页 |
| GET | `/user/invite/details` | 佣金明细（分页） | 邀请页 |
| GET | `/user/notice/fetch` | 公告列表/详情 | 仪表盘 |
| GET | `/user/ticket/fetch` | 工单列表/详情 | 工单页 |
| POST | `/user/ticket/save` | 新建工单 | 工单页 |
| POST | `/user/ticket/reply` | 回复工单 | 工单详情 |
| POST | `/user/ticket/close` | 关闭工单 | 工单详情 |
| POST | `/user/ticket/withdraw` | 佣金提现工单 | 邀请页 |
| GET | `/user/knowledge/fetch` | 知识库列表/文章 | 知识库页 |

## 附录 B：关键枚举值

| 枚举 | 取值 |
|---|---|
| 订单状态 `status` | 0 待支付 / 1 开通中 / 2 已取消 / 3 已完成 / 4 已折抵 |
| 订单类型 `type` | 1 新购 / 2 续费 / 3 升级 / 4 流量重置 |
| 订购周期 `period` | `month_price` / `quarter_price` / `half_year_price` / `year_price` / `two_year_price` / `three_year_price` / `onetime_price` / `reset_price` / `deposit`(充值) |
| 工单等级 `level` | 0 低 / 1 中 / 2 高 |
| 工单状态 `status` | 0 开启 / 1 已关闭 |
| 工单回复状态 `reply_status` | 0 客服已回复 / 1 等待回复 |
| 优惠券类型 `type` | 1 按金额减(分) / 2 按比例(%) |
| 礼品卡类型 `type` | 1 余额(分) / 2 时长(天) / 3 流量(GB) / 4 流量重置 / 5 兑换套餐 |
| 节点协议 `type` | shadowsocks / vmess / vless / trojan / hysteria / tuic / anytls |
