<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\File;
use RouteForge\Common\Analyzer\RouteAnalyzer;
use RouteForge\Common\Contract\ForgeExceptionContract;
use RouteForge\Common\Repository\RouteRepository;
use RouteForge\Common\Support\StrictViolationScanner;
use RouteForge\Common\Type\TypeGenerator;
use RouteForge\Laravel\Adapter\LaravelRouteNormalizer;

/**
 * 从路由表生成 TS 类型声明
 *
 * @see .docs/SPEC.md §3.2
 */
class RouteForgeTypesCommand extends Command
{
    use WritesToErrorOutput;

    protected $signature = 'route:forge:types
        {--level= : 仅生成指定层级下的路由类型}
        {--json : 输出 JSON 对象格式（键为路由名）}
        {--out= : 写入指定文件路径；不传则输出到 stdout}';

    protected $description = '生成 TS 路由类型声明（route:forge:types --level=admin --json --out=src/types/forge-routes.d.ts）';

    public function handle(Router $router, RouteAnalyzer $analyzer, LaravelRouteNormalizer $normalizer): int
    {
        $levels = array_keys(config('forge.levels', []));
        $filterLevel = $this->option('level');

        // level 过滤校验
        if ($filterLevel !== null && $filterLevel !== '' && !in_array($filterLevel, $levels,
                true)) {
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
            // 层级名拼错 / classifier 抛错 / 悬空别名（RF_BE_002/004/006/008）仍是
            // 一条一码的精确错误；严格模式的「未归级 / 有层级无名字」已改为
            // 全量收进 analyze()['violations']，由下方红色清单一次报完。
            $this->error("[{$e->code()}] {$e->getMessage()}");

            return 1;
        }

        // 严格模式有违规 → 拦下，且不产出类型声明：
        // d.ts 一旦被前端 commit 进仓库，缺的名字与漏归级的路由就固化成了「看起来是对的」的契约，
        // 比一条报错难查得多。strict_mode 的语义即「配置不对不放行」，退出码 1 交给 CI 拦。
        // 清单写 stderr：`route:forge:types > x.d.ts` 场景下 stdout 是产物，不得混入诊断信息。
        if (StrictViolationScanner::count($analysis['violations']) > 0) {
            $this->writeLinesToErrorOutput($analysis['warnings']);
            $this->writeLinesToErrorOutput(StrictViolationScanner::format($analysis['violations']), 'red');

            return 1;
        }

        // 目标层级：全部已配置层级（--level 时仅该层级），空层级由
        // TypeGenerator::collectTargets() 预置，保证 ForgeLevel 联合类型完整
        $typeGenerator = new TypeGenerator();
        $targets = $filterLevel !== null && $filterLevel !== '' ? [$filterLevel] : $levels;
        $routesByLevel = $typeGenerator->collectTargets($analysis['rows'], $targets);

        // 输出
        if ($this->option('json')) {
            $output = $typeGenerator->generateJson($routesByLevel);
        } else {
            // 端点注释取实际配置，规范化与端点注册/摘要下发共用同一实现
            $endpointPrefix = RouteRepository::normalizeEndpointPrefix((string) config('forge.endpoint_prefix', '/_forge/routes'));
            $output = $typeGenerator->generateDts($routesByLevel, $endpointPrefix);
        }

        $outFile = $this->option('out');
        if ($outFile !== null && $outFile !== '') {
            $dir = dirname($outFile);
            if (!is_dir($dir)) {
                File::makeDirectory($dir, 0755, true);
            }
            File::put($outFile, $output);
            $this->info("Written to: {$outFile}");
            // 警告只走 stderr：产物文件与 stdout 都必须保持干净
            $this->writeLinesToErrorOutput($analysis['warnings']);

            return 0;
        }

        // 不带 --out 时 stdout 就是 d.ts 产物本身（`> x.d.ts` 重定向场景），警告不得混入
        $this->writeLinesToErrorOutput($analysis['warnings']);
        $this->line($output);

        return 0;
    }
}
