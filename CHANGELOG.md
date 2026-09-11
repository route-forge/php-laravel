# Changelog

本项目所有重要变更都会记录在此文件中。

格式基于 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
版本号遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

## [2.0.1] - 2026-09-12

### Changed

- 依赖下限收紧到 `route-forge/common:^1.1.2`：本包 SPEC §3.1.2 / §3.1.5 已承诺
  `match.prefix` / `match.middleware` 与 level 级 `endpoint_middleware` 接受单值写法，
  但 1.1.1 的管理器保存路径（`ConfigFileGenerator`）会在 `exportInlineArray()` 的 `array`
  类型声明上抛 `TypeError`——文档承诺的写法一保存就崩。1.1.2 修好了读取侧与保存侧的口径相反。

### Fixed

- **`manager_allowed_ips` 写成单个字符串不再让 IP 白名单静默失效**：此前 `ManagerAllowedIps`
  用 `is_array()` 守卫，非数组值会让整段校验被跳过——配置写了、白名单没挂，管理器页面
  （全部路由元信息 + 运行时配置）对任意来源开放，属失保护方向。现统一在读取处 `(array)` 归一，
  单值写法与只含它的数组逐来源等价（SPEC §3.3、§5）。
  - ⚠ **行为变化一**：升级前 `'manager_allowed_ips' => '192.168.1.10'` 等价于「不限制」，
    升级后会真的只放行该 IP（含原本机回环也会被 403）。
  - ⚠ **行为变化二**：该键完全缺失时（如沿用旧版本发布的 `config/forge.php`）改按 SPEC §5
    默认值 `['127.0.0.1', '::1']` 仅放行本机，不再当作「不限制」；显式 `null` / `[]`
    仍表示放开限制，语义不变。
  - 顺带消除一处两侧不对称：`ConfigFileGenerator` 保存路径本就按 `(array)` 归一，
    修复前会出现「运行期不限制、在管理器页面点一次保存后突然开始限制」的行为翻转。

## [2.0.0] - 2026-09-10

### Breaking

- **框架无关核心下沉到 [`route-forge/common`](https://github.com/route-forge/php-common)**：层级解析、别名解析、
  路由仓库、缓存、TS 类型生成、摘要渲染与异常全部迁入 `RouteForge\Common\`，本包只保留 Laravel 适配层
  （`src/Adapter/` 桥接 `RouteNormalizerInterface` / `CacheInterface`）。异常与契约接口的 FQCN 随之变化
  （`RouteForge\Laravel\Exceptions\*` → `RouteForge\Common\Exception\*`，契约 →
  `RouteForge\Common\Contract\ForgeExceptionContract`），**错误码、`httpStatus()` 与消息文本一字未改**（SPEC §6）。
  `catch` 具体子类的宿主代码需改用新命名空间；按契约 catch 后读 `code()` 的无需改动。
- 新增 Composer 依赖 `route-forge/common:^1.1.1`（别名跨层级可见性与 `@forgeSummary` 非 ASCII 两项修复自 1.1.0 起，`match` 规则的类型归一化自 1.1.1 起）。

### Fixed

- **别名在目标路由名命中多个层级时不再从视图里消失**：同一个路由名以不同层级重复注册时（改名过渡期的典型手误），
  别名此前只跟随末次注册层级，`route:forge:list --level=<另一层级>` 与 `route:forge:types` 的 `d.ts` 都缺该条目，
  而层级端点里两个层级都有这个键——前端按旧名取用会被判 `UnknownRouteName`。现在端点 / list / types / 管理器四处
  一律跟随目标解析到的每一个层级；`route_count` 与 `tier_counts` 口径不变（一个别名只计一次，记在 `url()`
  实际解析到的末次注册层级）（SPEC §3.1.7、§3.2）
- **`@forgeSummary` 内嵌载荷不再把非 ASCII 转成 `\uXXXX`**：内嵌 JSON 编码器补齐 `JSON_UNESCAPED_UNICODE`，
  与 `Illuminate\Support\Js::from` 逐位对齐。JS 解析结果本就等价，但中文 `levels.*.description` 会让首屏
  `<head>` 里的载荷体积按字符数成倍膨胀
- 单层级缓存失效统一经 `RouteCache::forgetLevel()`，「失效一个层级必然同步失效摘要」由不变量保证，
  不再依赖每个调用方记得额外清 `'summary'` 键
- **`match.prefix` / `match.middleware` 写成单个字符串不再让元信息端点 500**：此前 `'prefix' => 'admin'`
  会在层级解析里抛 `count(): Argument #1 ($value) must be of type Countable|array, string given`（同文件对
  `foreach` 用了 `(array)` 归一、对空值判断却按裸值 `count()`，自相矛盾）。归一化在 `common` 1.1.1 落地，
  单值写法与等价的单元素数组**逐键输出一致**（实证：同一组路由下摘要与各层级端点、`list` / `types` 全部产物相同）；
  `middleware_match` 传非 `string`/`array` 类型时回落 `'any'` 并记一条 PSR-3 warning。本包补
  `tests/Feature/MatchRuleConfigTest.php` 守宿主可见面，SPEC §3.1.2 把「接受单值」写成显式承诺
- **`endpoint_middleware` 写成单个字符串的两处失效已修**（与上一条同族，落在本包 `ForgeServiceProvider`）：
  层级侧 `levels.*.endpoint_middleware` 走裸值 `count()`，在 **Provider boot 阶段**抛 `TypeError` →
  宿主全站每个请求 500（不只 forge 端点）；顶层 `endpoint_middleware` 的 `is_array()` 守卫更阴险——
  非数组值被静默丢弃，配置写了、中间件没挂，摘要端点（全部层级 + 运行时配置）看起来受保护实则完全开放。
  两处现统一按 `(array)` 归一并以 `!== []` 判空，单值写法与 Laravel 自身 `->middleware('auth')` 同形。
  ⚠ **行为变化**：升级前把顶层 `endpoint_middleware` 写成字符串等价于「不限制」，升级后会真的挂上该中间件；
  若你依赖旧的静默忽略行为（即本就没打算保护），请显式改为 `[]` 或 `null`（SPEC §3.1.5、§5）

### Changed

- `route:forge:types` 的 stderr 警告集合与 `route:forge:list` 的 `warnings` 对齐：除原有「有 tier 无 name」外，
  新增别名撞车 / 一名多声明 / 宏与 config 目标冲突 / 路由名跨层级重复注册。产物内容与退出码不变；
  **重定向产物请勿用 `2>&1` 合并两条流**，否则警告会混进 `d.ts` / `--json` 文件头（SPEC §3.2）
- 命令一次遍历内的 fail-fast 顺序明确为「先逐条解析路由层级，再解析别名映射」：两类配置错误并存时先报路由侧
  （`RF_BE_001/002/004/005/006` 优先于 `RF_BE_008`），两者均以退出码 1 结束（SPEC §3.2）
- `route:forge:list` 对「路由名跨层级重复注册」输出 warning，点名歧义而非静默择一（SPEC §3.1.7）
- 框架内部路由排除前缀（Laravel 的 `storage.*`）收敛到 `ForgeServiceProvider` 单点声明，
  命令层不再自行组装过滤器与别名解析器

## [1.5.0] - 2026-09-08

### Added

- `route:forge:list`：新增层级统计汇总行（`Tier counts:`，含 0 路由层级与 unassigned，别名计入目标层级）；
  unassigned 非零时主动提示检查 match 规则或补显式 tier（SPEC §3.2）
- `route:forge:list` / `route:forge:types`：「有 tier 无 name」的路由直接在控制台暴露
  （list 走 warning 行 / JSON warnings；types 走 stderr，不污染 stdout 产物）（SPEC §3.2）
- `route:forge:types`：`ForgeLevel` 联合类型覆盖所有已配置层级（含 0 路由空层级），
  `--json` 空层级输出 `{}` 而非 `[]`（SPEC §3.2）
- 管理器配置页只读展示 `aliases` 映射，config API 同步下发，便于审计（SPEC §3.3）

### Changed

- `route:forge:list` 表格：`Name` 列更名为 `Name/Alias`，**别名整行以黄色显示**、**被别名依赖的
  真实路由名以绿色显示**（改名需同步映射的审计信号），其余行默认颜色（仅 table 模式；
  `--json` 输出保持纯文本）；**被忽略的撞车声明以红色行展示在表格末尾**（仅 table，不进入
  JSON routes）；`Alias Of` 列名不变，语义已在 SPEC §3.2 明确
- 别名定位从「过渡手段」扩展为**双用法**：①改名迁移过渡（用完清理）；②长期稳定对外名
  （易变路由固定对外调用名，改名/跨层级迁移只更新映射目标，前端永不感知）（SPEC §3.1.7）

### Fixed

- 管理器保存配置不再静默丢失 `aliases` 映射：配置生成器原样透传（SPEC §3.3）
- `ForgeRouteRegistrar::__destruct` 不再抛异常：PHP 析构期间抛异常在栈展开场景会致命错误且无法 catch，
  改为日志告警（`strict_mode=true` 记 `error`，默认记 `warning`）；
  `DiscardedRegistrarAttributesException` / `RF_BE_007` 保留供兼容
- 摘要端点 `config.endpoint_prefix` 下发规范化后的值（与端点注册路径一致，前端可直接拼接）；
  `config.cache_ttl` 统一转为 `int|null`（负值归一化为 `null`，与实际缓存行为一致），不再出现 env 字符串
- 非严格模式下「有 tier 无 name」的路由记录 `warning`，不再静默消失
- `route:forge:list` / `route:forge:types` 捕获路由解析异常，输出 `[错误码] 消息` 而非裸堆栈；
  list 过滤后 0 行早退时不再吞掉警告输出
- 严格模式异常消息补充修复指引（`->name` / `->tier` / match 规则 / 关闭 strict_mode）
- 未命名路由上的 `->forgeAlias()` 声明不再静默丢失：别名解析器收集 warning 提示补 `->name(...)`（SPEC §3.1.7）
- 生成 d.ts 文件头「端点」注释取实际 `endpoint_prefix`（规范化后），自定义前缀不再失真
- 宏通道别名撞车检查缺失：宏声明的别名与真实路由名撞车时不再静默覆盖端点元信息中
  真实路由的条目，改为真实路由优先 + 警告（与 config 通道同规则）；同一别名在多条路由上
  重复宏声明时先声明者优先并警告（SPEC §3.1.7）

### Docs

- SPEC §3.1.2：明确 `prefix` 与 `middleware` 为 OR 关系（任一命中即归入）；
  声明 `unassigned` 为保留层级名，不可用作自定义层级
- SPEC §3.2：`route:forge:clear --level` 会同步失效摘要缓存（修正原"摘要缓存不受影响"的描述）
- SPEC §3.1.3 / §6：更新尾部链式属性被丢弃的行为说明与 RF_BE_007 触发场景
- SPEC §3.1.6 / §3.1.7 / §3.2 / §3.3：同步本轮易用性变更（摘要 cache_ttl 负值归一化、
  未命名路由别名警告、list/types 命令新行为、管理器 aliases 只读展示）
- SPEC §3.2：补充 `route:forge:list` / `route:forge:types` 行为说明（层级统计汇总、
  warnings 输出时机、空层级类型、stderr 警告通道、`--json` 新增 `tier_counts` 字段）

## [1.4.0] - 2026-09-03

### Added

- 首页内嵌摘要（Blade 指令 `@forgeSummary`，SPEC §3.1.8 / DESIGN §6.3）：面向「Laravel 服务端渲染 HTML、JS 只在浏览器执行」的首页，可选地把摘要内嵌进 `<head>`，让 `@route-forge/core` 跳过首屏的一次摘要 HTTP 往返
  - 复用摘要端点**同一 producer**（`RouteRepository::getSummary()`）：字段契约、缓存（`cache_driver`/`cache_ttl`）、dev 旁路、包自身路由排除等既有语义全部继承，与 `GET {endpoint_prefix}` 响应逐字段一致
  - 以**一次性、消费即自删、不可枚举**的 `window.__ROUTE_FORGE__` 访问器输出（`defineProperty` + 读后 `delete`）
  - **只嵌摘要，绝不内嵌层级路由表**：各层级明细仍按 `GET {endpoint_prefix}/{level}` 走 HTTP 懒加载，受保护路由不预置进公开 HTML
  - **XSS 安全编码**：经 `Illuminate\Support\Js`（`JSON_HEX_TAG` 等）转义，防 `</script>` 逃逸
  - **不新增 HTTP 端点、不递增 `schemeVersion`**；纯 SPA / Vite dev 不书写指令即回落网络摘要，行为不变（向后兼容的纯增量）
  - 新增 `src/Blade/ForgeSummaryRenderer.php`；`ForgeServiceProvider` 注册 `@forgeSummary` 指令
  - 同步 SPEC §3.1.8、DESIGN §6.3（把"取消 Blade 注入"澄清为"取消把 Blade 当唯一通道"，本特性是端点之上的可选加速）、README（中英）、`llms.txt`、`.github/copilot-instructions.md`、`AGENTS.md`
  - 新增测试 `tests/Feature/ForgeSummaryInjectionTest`：访问器结构、注入与摘要端点逐字段等值、`</script>` 转义、未用指令页不注入且端点无回归

## [1.3.0] - 2026-09-01

### Added

- 路由别名机制（SPEC §3.1.7）：路由改名迭代时，前端调用方无需修改路由名即可继续工作
  - 双通道声明：路由宏 `->forgeAlias('旧名', ...)`（显式，优先级高，改名处就近声明）
    与 config `aliases` 集中映射表（批量迁移），并用时宏优先
  - 别名作为额外键注入目标路由所在层级的元信息端点（含 `unassigned` 特殊层级），
    元信息与目标路由完全一致（纯复制）——前端校验与类型推断自然通过，前端零改动
  - 摘要端点 `route_count` 计入别名，与层级端点 `routes` 键数量保持一致
  - `route:forge:types` 为别名生成与目标一致的类型条目（TS 侧旧名仍合法）
  - `route:forge:list` 显示别名条目（`Alias Of` 列 / JSON `alias_of` 字段）、
    新增 `--aliases` 过滤、撞车等非致命问题以 `warnings` 输出
  - 管理器页面为别名条目打「别名」标并显示指向
  - 悬空别名（指向不存在的路由名）抛新异常 `AliasTargetException`（RF_BE_008，500），fail-fast
  - 别名是元信息层概念：不参与层级解析、不受 `strict_mode` 影响、旧 URI 本身不可访问；
    `schemeVersion` 不递增（向后兼容增量）

### Fixed

- `composer.json` 的 `homepage` / `support` 此前误指向前端 monorepo（`route-forge/route-forge`），
  现修正为本仓库 `route-forge/route-forge-laravel`，并补充 `support.docs`，避免 AI 抓取与 Packagist
  读到错误的包来源

### Changed

- README 重构为英文优先 + 中文保留：补充价值主张、痛点场景、能力自述、快速上手与常见坑，
  不做法竞品对比
- 新增面向 AI 的可发现性资产：`llms.txt`（llmstxt.org 标准入口）、`AGENTS.md`（编码 Agent 集成指南）、
  `.github/copilot-instructions.md`（Copilot 仓库级指令）
- `composer.json` 描述改为英文、`keywords` 扩充；GitHub 仓库补全 description / topics

## [1.2.2] - 2026-08-30

### Added

- 新增配置项 `manager_allowed_ips`：管理器页面 IP 白名单。默认仅本机回环
  （`127.0.0.1` / `::1`）可访问；支持精确 IP 列表、`'*'` 放行任意来源、
  `null`/空数组表示显式不限制。管理器路由仅 `APP_DEBUG=true` 时注册，
  线上本配置天然不生效
- 管理器保存配置时，`endpoint_middleware` 与 `manager_allowed_ips` 等
  不在表单中编辑的配置项原样透传，不再丢失

### Fixed

- **严格模式不可用**：包自身端点路由（`forge.routes.*` / `forge.manager.*`）
  此前参与元信息端点扫描——它们永远不带 tier，`strict_mode=true` 时必然抛
  `RF_BE_001` 导致摘要/层级端点全面 500；非严格模式下还会泄露进 `unassigned`
  明细被前端当作用户路由消费。现所有扫描统一排除包自身与框架内部路由
  （含 Laravel 12+ 的 `storage.*`），与 `route:forge:list` /
  `route:forge:types` / 管理器页面的过滤口径一致
- 层级下无路由时 `routes` 序列化为 `[]` 而非 `{}`，与「按路由名索引的对象」
  契约不一致的问题
- 配置了 `classifier` 回调时，管理器保存配置会静默抹掉该闭包；
  现改为拒绝保存并返回 422 明确提示

### Changed

- 仓库地址迁移至 GitHub 组织 `route-forge`：`composer.json`
  homepage/support 与 README 链接更新为 `route-forge/route-forge`
- 路线图移除 v1.3 Vite 插件（属前端工具链，不在后端包范围）
- 文档同步兼容矩阵描述：CI 覆盖 PHP 8.2–8.5 × Laravel 11/12/13
  （排除 PHP 8.2 × Laravel 13 组合）

## [1.2.1] - 2026-08-29

### Fixed

- 修复 CI 矩阵全红的三类问题：矩阵排除 PHP 8.2 × Laravel 13 组合、
  修正 Laravel 13 分支的 testbench 版本、放行 Laravel 11 依赖安装
- 兼容 Laravel 12 的框架自带 `storage.*` 路由与 Route 门面缓存
- `ForgeRouter::__call` 兼容 PHP 8.2/8.3 的 new 链式调用语法

## [1.2.0] - 2026-08-28

### Changed

- `classifier` 支持任意 callable（函数名字符串、`[Class, 'method']` 数组、
  可调用对象），此前非 Closure callable 会被静默丢弃
- 清理遗留问题，修正 SPEC 悬空/错误章节交叉引用
- 测试补齐：15 个边界与端到端用例、管理器页面零覆盖用例

## [1.1.1] - 2026-08-25

### Changed

- 更新文档说明

## [1.1.0] - 2026-08-25

### Added

- 开发环境可视化路由管理器页面（`GET /_forge/manager`）：Blade + 原生
  CSS/JS 零前端构建依赖，含总览（层级卡片）、路由（搜索/过滤/详情弹窗）、
  配置（全局设置表单 + levels JSON 编辑器）三个标签页，仅 `APP_DEBUG=true`
  时注册，配置保存直接写入 `config/forge.php` 并自动清除配置编译缓存

## [1.0.2] - 2026-08-24

### Fixed

- 修复 `RouteCache` 三处缺陷（缓存键索引维护相关）

## [1.0.1] - 2026-08-24

### Changed

- 摘要端点响应结构迭代为 `schemeVersion: 1`

## [1.0.0] - 2026-08-23

### Added

- 首个发布：路由分级懒加载后端（层级分配五级优先级、元信息端点、
  摘要端点、统一缓存、`route:forge:list` / `route:forge:types` /
  `route:forge:clear` Artisan 命令）
