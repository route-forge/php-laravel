<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use RouteForge\Common\Analyzer\RouteAnalyzer;
use RouteForge\Common\Contract\ForgeExceptionContract;
use RouteForge\Laravel\Adapter\LaravelRouteNormalizer;

/**
 * 列出所有命名路由的层级分配（含别名条目，SPEC §3.1.7）
 * @see .docs/SPEC.md §3.2
 */
class RouteForgeListCommand extends Command
{
    protected $signature = 'route:forge:list
        {--level= : 仅列出指定层级下的路由}
        {--json : 输出 JSON 数组格式}
        {--unassigned : 仅列出未分配层级的路由}
        {--aliases : 仅列出别名条目（旧名 → 真实路由名）}';

    protected $description = '列出所有命名路由的层级分配（route:forge:list --level=admin --json --unassigned）';

    public function handle(Router $router, RouteAnalyzer $analyzer, LaravelRouteNormalizer $normalizer): int
    {
        $levels = array_keys(config('forge.levels', []));

        $filterLevel = $this->option('level');
        $onlyUnassigned = (bool) $this->option('unassigned');
        $onlyAliases = (bool) $this->option('aliases');
        $asJson = (bool) $this->option('json');

        // level 过滤校验（unassigned 特殊层级合法）
        if ($filterLevel !== null && $filterLevel !== '' && !in_array($filterLevel, array_merge($levels, ['unassigned']), true)) {
            $this->error("Unknown level: {$filterLevel}");
            $this->line('Available levels: ' . (empty($levels) ? '(none)' : implode(', ',
                    $levels)));

            return 1;
        }

        // 分析器（filter / alias / resolver 接线）由 Provider 单例提供，
        // storage.* 等框架内部路由排除规则单点维护，命令层不做重复组装
        try {
            $analysis = $analyzer->analyzeRoutes($router->getRoutes(), $normalizer);
        } catch (ForgeExceptionContract $e) {
            // 悬空别名 / resolve 抛出的 Forge 系异常（RF_BE_001/002/004/005/006）：
            // 输出 [错误码] 消息而非裸堆栈
            $this->error("[{$e->code()}] {$e->getMessage()}");

            return 1;
        }

        $warnings   = $analysis['warnings'];
        $aliases    = $analysis['aliases'];
        $collisions = $analysis['collisions'];
        // 过滤前全量行索引：撞车红行需按目标路由取层级/方法/URI，不受当前过滤影响
        $rowByName = array_column($analysis['rows'], null, 'name');

        // 过滤与 JSON 契约组装（框架无关，SPEC §3.2）在 common RouteAnalyzer
        $rows    = $analyzer->filterRows($analysis['rows'], $filterLevel, $onlyUnassigned, $onlyAliases);
        $payload = $analyzer->listPayload($levels, $rows, $analysis['tier_counts'], $warnings, $filterLevel, $onlyUnassigned, $onlyAliases);

        // JSON 输出（结构化对象，便于脚本消费；warnings 供 CI/脚本检测别名配置问题）
        if ($asJson) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $orderedCounts = $payload['tier_counts'];

        // 层级统计汇总（unassigned 非零是「match 规则漏配」最常见的信号，主动提示）
        $this->line('Tier counts: ' . implode(' | ', array_map(
            fn (string $l, int $c): string => "{$l}: {$c}",
            array_keys($orderedCounts),
            $orderedCounts,
        )));
        if ($orderedCounts['unassigned'] > 0) {
            $this->warn($orderedCounts['unassigned'] . " route(s) are unassigned and only available via the 'unassigned' tier. "
                . 'Check match rules in config/forge.php or add explicit ->tier(...) markers.');
        }

        // 警告在任何过滤结果下都输出：配置问题（别名撞车 / tier 无 name）与
        // 当次过滤无关，0 行早退时也必须可见
        foreach ($warnings as $warning) {
            $this->warn($warning);
        }

        if (empty($rows)) {
            $this->info('No routes found matching the filter.');

            return 0;
        }

        // 被别名依赖的真实路由名（Name 列绿色标识：改名时需同步更新别名映射）
        $aliasedTargets = array_values(array_unique(array_values($aliases)));

        $tableRows = array_map(function (array $r) use ($aliasedTargets) {
            $methods = static fn (array $row): string => implode('|', RouteAnalyzer::withoutHead($row['methods']));

            // 别名整行黄色标识，真实路由行保持默认颜色（仅 table 模式；JSON 输出保持纯文本契约不变）
            if ($r['alias_of'] !== null) {
                $yellow = static fn (string $cell): string => "<fg=yellow>{$cell}</>";

                return [
                    $yellow($r['name']),
                    $yellow($r['level']),
                    $yellow($methods($r)),
                    $yellow($r['uri']),
                    $yellow((string) $r['alias_of']),
                ];
            }
            // 真实路由名被别名指向 → Name 列绿色（长期稳定对外名的审计信号）
            $hasAlias = in_array($r['name'], $aliasedTargets, true);

            return [
                $hasAlias ? "<fg=green>{$r['name']}</>" : $r['name'],
                $r['level'],
                $methods($r),
                $r['uri'],
                '—',
            ];
        }, $rows);

        // 撞车声明红行（仅 table 展示）：被忽略的别名声明，配置问题需肉眼可见；
        // 不进入 --json 的 routes 与端点元信息（真实路由优先，routes 只含可用名字）
        foreach ($collisions as $alias => $target) {
            $info = $rowByName[$target] ?? null;
            $red = static fn (string $cell): string => "<fg=red>{$cell}</>";
            $tableRows[] = [
                $red($alias),
                $red($info['level'] ?? '—'),
                $red(isset($info['methods']) ? implode('|', RouteAnalyzer::withoutHead($info['methods'])) : '—'),
                $red($info['uri'] ?? '—'),
                $red($target),
            ];
        }

        $this->table(['Name/Alias', 'Level', 'Methods', 'URI', 'Alias Of'], $tableRows);

        return 0;
    }
}
