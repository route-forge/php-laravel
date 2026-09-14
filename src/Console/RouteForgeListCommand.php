<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use RouteForge\Common\Analyzer\RouteAnalyzer;
use RouteForge\Common\Contract\ForgeExceptionContract;
use RouteForge\Common\Repository\RouteRepository;
use RouteForge\Common\Support\StrictViolationScanner;
use RouteForge\Laravel\Adapter\LaravelRouteNormalizer;

/**
 * 列出所有命名路由的层级分配（含别名条目，SPEC §3.1.7）
 * @see .docs/SPEC.md §3.2
 */
class RouteForgeListCommand extends Command
{
    use WritesToErrorOutput;

    protected $signature = 'route:forge:list
        {--level= : 仅列出指定层级下的路由}
        {--json : 输出 JSON 数组格式}
        {--unassigned : 仅列出未分配层级的路由}
        {--aliases : 仅列出别名条目（旧名 → 真实路由名）}
        {--unnamed : 独立视图：列出全量未命名路由（含未命中任何层级者）}';

    protected $description = '列出所有命名路由的层级分配（route:forge:list --level=admin --json --unassigned --unnamed）';

    /**
     * 未命名视图里「层级名拼错 / classifier 抛错」条目的虚拟分组名。
     * 它不是真实层级，只有 --unnamed 视图能按它过滤（见 common RouteAnalyzer::formatUnnamed）。
     */
    private const UNRESOLVED_GROUP = 'unresolved';

    public function handle(Router $router, RouteAnalyzer $analyzer, LaravelRouteNormalizer $normalizer): int
    {
        $levels = array_keys(config('forge.levels', []));

        $filterLevel = $this->option('level');
        $onlyUnassigned = (bool) $this->option('unassigned');
        $onlyAliases = (bool) $this->option('aliases');
        $onlyUnnamed = (bool) $this->option('unnamed');
        $asJson = (bool) $this->option('json');

        // --unnamed 是独立视图：数据来自 analyze() 的 unnamed 键而非 rows，
        // 与表格 / warnings 同屏就是同一事实双写，与 --json 同屏则要为此新造一份 JSON 形态。
        // 因此这里显式判为参数冲突，而不是悄悄丢掉某一边。
        if ($onlyUnnamed && ($asJson || $onlyUnassigned || $onlyAliases)) {
            $this->error('--unnamed is a standalone view and cannot be combined with --json, --unassigned or --aliases.');
            $this->line('Run it on its own: route:forge:list --unnamed [--level=<level>]');

            return 1;
        }

        // level 过滤校验（unassigned 特殊层级合法；--unnamed 视图额外接受虚拟分组 unresolved）
        $allowedLevels = array_merge($levels, [RouteRepository::UNASSIGNED_LEVEL]);
        if ($onlyUnnamed) {
            $allowedLevels[] = self::UNRESOLVED_GROUP;
        }
        if ($filterLevel !== null && $filterLevel !== '' && !in_array($filterLevel,
                $allowedLevels, true)) {
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
            // 悬空别名 / resolve 抛出的 Forge 系异常（RF_BE_002/004/006/008）：
            // 输出 [错误码] 消息而非裸堆栈。严格模式的「未归级 / 有层级无名字」
            // 已不在此列——common 把它们收进 analyze()['violations'] 全量报，见下方红色清单。
            $this->error("[{$e->code()}] {$e->getMessage()}");

            return 1;
        }

        // --unnamed 独立视图：全量未命名路由（含未命中任何层级者）按层级分组。
        // 这些路由没有名字 → 不可能被 url() 引用，也不进 rows / tier_counts / 端点元信息，
        // 因此只看 warnings 会漏（warnings 只收「被层级规则命中却没名字」那部分）。
        // 本视图不重复打印表格与 warnings——同一事实双写只会让两次输出互相打脸。
        if ($onlyUnnamed) {
            foreach (RouteAnalyzer::formatUnnamed($analysis['unnamed'], $levels, $filterLevel) as $line) {
                $this->line($line);
            }

            return 0;
        }

        // 严格模式违规清单（common 单点判定与措辞）：missing_name / unassigned 非空即配置有问题。
        // 命令不再逐条 fail-fast——排查场景下命令本身必须还能跑出全貌，因此照常出表，
        // 末尾追加红色清单并以退出码 1 收尾（CI 依据退出码拦下，人依据清单定位）。
        $violationLines = StrictViolationScanner::count($analysis['violations']) > 0
            ? StrictViolationScanner::format($analysis['violations'])
            : [];

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
            // stdout 是纯 JSON 产物：违规清单改走 stderr，退出码 1 负责拦下 CI。
            // （violations 不塞进 payload——那是 --json 对外契约的扩张，另议。）
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            if ($violationLines !== []) {
                $this->writeLinesToErrorOutput($violationLines, 'red');

                return 1;
            }

            return 0;
        }

        $orderedCounts   = $payload['tier_counts'];
        $unassignedCount = $orderedCounts[RouteRepository::UNASSIGNED_LEVEL];

        // 层级统计汇总（unassigned 非零是「match 规则漏配」最常见的信号，主动提示）
        $this->line('Tier counts: ' . implode(' | ', array_map(
            fn (string $l, int $c): string => "{$l}: {$c}",
            array_keys($orderedCounts),
            $orderedCounts,
        )));
        if ($unassignedCount > 0) {
            $this->warn($unassignedCount . " route(s) are unassigned and only available via the 'unassigned' tier. "
                . 'Check match rules in config/forge.php or add explicit ->tier(...) markers.');
        }

        // 警告在任何过滤结果下都输出：配置问题（别名撞车 / tier 无 name）与
        // 当次过滤无关，0 行早退时也必须可见
        foreach ($warnings as $warning) {
            $this->warn($warning);
        }

        if (empty($rows)) {
            $this->info('No routes found matching the filter.');

            // 0 行同样要报违规：未命名但有层级归属的路由压根不进 rows，
            // 「表格空着 + 一堆违规」正是最需要开口的组合
            return $this->finishWithViolations($violationLines);
        }

        // 被别名依赖的真实路由名（Name 列绿色标识：改名时需同步更新别名映射）
        $aliasedTargets = array_values(array_unique(array_values($aliases)));

        $tableRows = array_map(function (array $r) use ($aliasedTargets) {
            $methods = static fn (array $row): string => implode('|', RouteAnalyzer::withoutHead($row['methods']));

            // 整行着色优先级：unassigned 品红（待分配的警告态）> 别名黄 > 默认。
            // 品红覆盖别名黄时，别名身份仍由 Alias Of 列的文字表达，不依赖颜色。
            if ($r['level'] === RouteRepository::UNASSIGNED_LEVEL) {
                $magenta = static fn (string $cell): string => "<fg=magenta>{$cell}</>";

                return array_map($magenta, [
                    $r['name'],
                    $r['level'],
                    $methods($r),
                    $r['uri'],
                    (string) $r['alias_of'],
                ]);
            }

            // 别名整行黄色标识，真实路由行保持默认颜色（仅 table 模式；JSON 输出保持纯文本契约不变）
            if ($r['alias_of'] !== null) {
                $yellow = static fn (string $cell): string => "<fg=yellow>{$cell}</>";

                return array_map($yellow, [
                    $r['name'],
                    $r['level'],
                    $methods($r),
                    $r['uri'],
                    (string) $r['alias_of'],
                ]);
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

        return $this->finishWithViolations($violationLines);
    }

    /**
     * 表格出口的收尾：严格模式违规以红色清单追加在输出末尾，有违规即退出码 1。
     *
     * 措辞与 HTTP 错误体（RF_BE_009 message）同源于 common StrictViolationScanner::format()，
     * 命令层不再各写一份「哪里配错了」的文案。表格模式没有产物污染问题，清单紧邻表格
     * 最便于对照；只有 --json 模式才把清单改投 stderr（见 handle()）。
     *
     * @param list<string> $violationLines
     */
    private function finishWithViolations(array $violationLines): int
    {
        if ($violationLines === []) {
            return 0;
        }

        foreach ($violationLines as $line) {
            $this->line("<fg=red>{$line}</>");
        }

        return 1;
    }
}
