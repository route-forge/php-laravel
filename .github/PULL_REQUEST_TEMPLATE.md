## 这个 PR 改什么

<!-- 一句话讲清动机与影响面。是缺陷修复就指出「什么输入下会静默失效 / 崩在哪一层」。 -->

## 类型

- [ ] 缺陷修复
- [ ] 新功能
- [ ] 重构（行为不变）
- [ ] 文档
- [ ] 测试
- [ ] 构建 / CI / 杂项

## 落在哪一层

<!-- 框架无关逻辑（层级解析、别名、仓库、缓存、TS 类型生成、摘要渲染、异常）属于
     route-forge/common；只有 Laravel 绑定留在本包。选错层会被要求拆分。 -->

- [ ] 本包（`src/`）
- [ ] 需要 `route-forge/common` 同步改动（请附对端 PR 链接，并确认核心已发版、本包约束已提升）
- [ ] 纯文档 / 测试 / CI

## 自检清单

**行为与契约**

- [ ] 已阅读 `.docs/SPEC.md` 与 `.docs/DESIGN.md`，本 PR 不与既有契约冲突
- [ ] 行为若发生变化，已同步 `SPEC.md`（含 §5 配置表的类型列与语义描述）与 `DESIGN.md`
- [ ] 新增/变更的端点字段或响应形状已考虑 `schemeVersion` 是否需要递增
- [ ] 若涉及公共 API 增删，已评估是否属于 breaking（以及应并入哪个版本号）

**配置读取（本项目出过两次同类缺陷，务必逐条对）**

- [ ] 新增的「契约上是数组」的配置读取点，已在**读取处**用 `(array)` 归一后再 `count()` / `foreach`
- [ ] 没有用 `is_array()` 守卫代替归一——那会让非数组写法被静默丢弃，造成「配置写了但不生效」的危险方向失效
- [ ] 空值语义（`null` / `[]` = 不限制）与 SPEC §5 描述一致，且回落路径有告警而非静默

**不变量**

- [ ] 包自身路由（`forge.routes.*` / `forge.manager.*`）未泄漏进任何元信息扫描
- [ ] 缓存失效统一走 `RouteCache::forgetLevel($level)`，没有裸 `forget($level)` + `'summary'` 魔法串
- [ ] 没有重复声明 `storage.*` 前缀、没有在命令内自行 new `RouteNameFilter` / `AliasResolver`
- [ ] 新增了 `Route` / 资源路由宏的话，`_ide_helper.php` 已同步补桩

**测试**

- [ ] 已跑全量测试且全绿：`composer test`
- [ ] 新行为/缺陷修复附了对应测试，且测的是**宿主可见面**（端点响应 / 命令产物 / d.ts），不只是内部单元
- [ ] 若某行为在测试里才成立（例如 `restore_error_handler()`、`defineEnvironment` 是类级的），已确认不是假绿

**发布卫生**

- [ ] `CHANGELOG.md` 已按 Keep a Changelog 分节记录（Breaking / Added / Changed / Fixed …）
- [ ] `composer.json` 未引入 `repositories` 块（本地联调的 path repository 提交前必须 `--unset`）
- [ ] 提交信息遵循 `type(scope): 中文描述`
- [ ] 未 push、未打 tag（等维护者指示）

## 复现 / 验证方式

<!-- 修复前 vs 修复后的实际命令与输出。缓存类问题请注明是否 `route:forge:clear` 过。 -->

```bash
php artisan route:forge:list --json
php artisan route:forge:types --out /tmp/route-forge.d.ts
```

## 关联 Issue

<!-- Closes # -->
