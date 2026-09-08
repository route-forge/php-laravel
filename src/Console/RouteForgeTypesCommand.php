<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\File;
use RouteForge\Common\Alias\AliasResolver;
use RouteForge\Common\Analyzer\RouteAnalyzer;
use RouteForge\Common\Contract\ForgeExceptionContract;
use RouteForge\Common\Filter\RouteNameFilter;
use RouteForge\Common\Tier\TierResolver;
use RouteForge\Common\Type\TypeGenerator;
use RouteForge\Laravel\Adapter\LaravelRouteNormalizer;

/**
 * 从路由表生成 TS 类型声明
 *
 * @see .docs/SPEC.md §3.2
 */
class RouteForgeTypesCommand extends Command
{
    protected $signature = 'route:forge:types
        {--level= : 仅生成指定层级下的路由类型}
        {--json : 输出 JSON 对象格式（键为路由名）}
        {--out= : 写入指定文件路径；不传则输出到 stdout}';

    protected $description = '生成 TS 路由类型声明（route:forge:types --level=admin --json --out=src/types/forge-routes.d.ts）';

    private const BODY_METHODS = ['POST', 'PUT', 'PATCH'];

    public function handle(Router $router, TierResolver $resolver): int
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

        // 数据收集（框架无关业务在 common RouteAnalyzer 中完成；本命令只负责 I/O 与渲染）
        $filter = RouteNameFilter::withExtraPrefixes(['storage.']);
        $normalizer = new LaravelRouteNormalizer();
        $infos = [];
        foreach ($router->getRoutes() as $route) {
            $infos[] = $normalizer->normalize($route);
        }

        $analyzer = new RouteAnalyzer($resolver, new AliasResolver((array) config('forge.aliases', []), $filter), $filter);
        try {
            $analysis = $analyzer->analyze($infos);
        } catch (ForgeExceptionContract $e) {
            $this->error("[{$e->code()}] {$e->getMessage()}");

            return 1;
        }

        // 组装「层级 → 路由名 → 类型约束」的二维映射
        // 预置全部目标层级（含 0 路由的空层级）：保证 ForgeLevel 联合类型覆盖
        // 所有已配置层级，前端引用「空层级」的路由名不再因类型缺失而 TS 报错
        $targets = $filterLevel !== null && $filterLevel !== '' ? [$filterLevel] : $levels;
        $routesByLevel = array_fill_keys($targets, []);

        $typeGenerator = new TypeGenerator();
        foreach ($analysis['rows'] as $r) {
            // unassigned 路由不生成类型（SPEC §3.2：无层级归属，不进入 ForgeRoutes 映射）；
            // 别名行已由 analyzer 预生成（跟随目标层级），此处统一过滤即可
            // 非目标层级（--level 过滤）也跳过：未 isset 的键不能隐式创建，
            // 否则过滤后 d.ts 仍会出现 client 等层级块
            if ($r['tier'] === null || !isset($routesByLevel[$r['level']])) {
                continue;
            }

            $methods = array_values(array_filter(
                $r['methods'],
                fn ($m) => strtoupper($m) !== 'HEAD',
            ));
            $method  = !empty($methods) ? strtoupper($methods[0]) : 'GET';
            $hasBody = in_array($method, self::BODY_METHODS, true);

            // 从 URI 模板提取 URL 可选参数（{param?} 语法）
            $optionalParams = $typeGenerator->extractOptionalParams($r['uri']);

            $routesByLevel[$r['level']][$r['name']] = [
                'method'  => $method,
                'params'  => $r['parameters'],
                'optionalParams' => $optionalParams,
                'defaults'       => $r['parameter_defaults'],
                'hasBody' => $hasBody,
                // response 始终为 unknown（v1.0 不支持自定义响应类型）
                'response' => 'unknown',
            ];
        }

        // 输出
        if ($this->option('json')) {
            $output = $typeGenerator->generateJson($routesByLevel);
        } else {
            // 端点注释取实际配置（规范化同端点注册），自定义 prefix 后不再失真
            $endpointPrefix = '/' . ltrim(rtrim((string) config('forge.endpoint_prefix', '/_forge/routes'), '/'), '/');
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
            $this->printWarnings($analysis['warnings']);

            return 0;
        }

        $this->printWarnings($analysis['warnings']);
        $this->line($output);

        return 0;
    }

    /**
     * 警告输出到 stderr：不带 --out 时 stdout 即产物本身
     * （artisan route:forge:types > x.d.ts 重定向场景），警告不得混入产物；
     * BufferedOutput（测试/Kernel::call）未实现 ConsoleOutputInterface，
     * getErrorOutput 回退为同一输出，警告仍可捕获。
     *
     * @param string[] $warnings
     */
    private function printWarnings(array $warnings): void
    {
        if ($warnings === []) {
            return;
        }

        // 真实控制台下底层 output 是 ConsoleOutputInterface → 写 stderr；
        // BufferedOutput（测试/Kernel::call）未实现该接口 → 回退同一输出，警告仍可捕获
        $output = $this->output->getOutput();
        $target = $output instanceof \Symfony\Component\Console\Output\ConsoleOutputInterface
            ? $output->getErrorOutput()
            : $output;

        foreach ($warnings as $warning) {
            $target->writeln("<comment>{$warning}</comment>");
        }
    }
}
