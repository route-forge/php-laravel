<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Route as RouteFacade;
use Orchestra\Testbench\TestCase;
use Psr\Log\LoggerInterface;
use RouteForge\Laravel\ForgeServiceProvider;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * match 规则「配置形态」测试（对应 .docs/SPEC.md §3.1.2 的类型归一化）。
 *
 * 锁定 route-forge/common 1.1.1 的修复在 Laravel 宿主侧的可见结果：
 *   1. match.prefix 写成单个字符串 → 正常命中层级，端点不再 500
 *      （1.1.1 之前会在层级解析里抛 count(): Argument #1 must be of type Countable|array）
 *   2. match.middleware 同理
 *   3. 归一化不放宽语义：不命中的路由仍落 unassigned，不会全量命中
 *   4. 命令面同样一致：单值前缀写法下 route:forge:list 的层级归属与 tier_counts、
 *      route:forge:types 的层级块都与端点相同（此前别名那轮漂移正是「端点有、命令没有」，
 *      只测端点封不住这类问题）
 *   5. middleware_match 传非法类型 → 回落 'any' 并经 PSR-3 记 warning
 *
 * 逐条「单值 vs 数组等价」的细粒度断言在 common 侧 TierResolverTest 覆盖，
 * 本文件守宿主可见面：HTTP 端点、`route:forge:list` / `route:forge:types` 产物、注入 logger。
 */
class MatchRuleConfigTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ForgeServiceProvider::class];
    }

    private function endpoint(string $level): string
    {
        $prefix = (string) config('forge.endpoint_prefix', '/_forge/routes');

        return '/' . ltrim(rtrim($prefix, '/'), '/') . '/' . $level;
    }

    public function test_single_string_prefix_matches_tier_without_type_error(): void
    {
        config(['forge.levels' => [
            'report' => [
                'description' => '报表',
                'load'        => 'lazy',
                'match'       => ['prefix' => 'admin'],   // 单值字符串，非 ['admin']
            ],
        ]]);

        RouteFacade::get('/admin/report', static function () {})->name('admin.report');
        RouteFacade::get('/web/other', static function () {})->name('web.other');

        $response = $this->get($this->endpoint('report'));
        $response->assertStatus(200);
        $this->assertSame(['admin.report'], array_keys((array) $response->json('routes')));

        // 归一化不得放宽匹配：不命中的路由仍落 unassigned
        $unassigned = $this->get($this->endpoint('unassigned'));
        $this->assertArrayHasKey('web.other', (array) $unassigned->json('routes'));
    }

    public function test_single_string_prefix_does_not_match_unrelated_uri(): void
    {
        // 防呆：'admin' 若被当成空前缀，/administrator-x 也会被 str_starts_with 命中
        config(['forge.levels' => [
            'report' => ['description' => '报表', 'load' => 'lazy', 'match' => ['prefix' => 'admin']],
        ]]);

        RouteFacade::get('/administrator-x', static function () {})->name('admin.partial');

        $this->assertArrayNotHasKey('admin.partial', (array) $this->get($this->endpoint('report'))->json('routes'));
        $this->assertArrayHasKey('admin.partial', (array) $this->get($this->endpoint('unassigned'))->json('routes'));
    }

    public function test_single_string_middleware_matches_tier(): void
    {
        config(['forge.levels' => [
            'member' => [
                'description' => '会员',
                'load'        => 'lazy',
                'match'       => ['middleware' => 'auth'],   // 单值字符串，非 ['auth']
            ],
        ]]);

        RouteFacade::get('/me/card', static function () {})->name('me.card')->middleware(['auth']);
        RouteFacade::get('/open/doc', static function () {})->name('open.doc');

        $member = $this->get($this->endpoint('member'));
        $member->assertStatus(200);
        $this->assertSame(['me.card'], array_keys((array) $member->json('routes')));

        $unassigned = $this->get($this->endpoint('unassigned'));
        $this->assertArrayHasKey('open.doc', (array) $unassigned->json('routes'));
    }

    public function test_invalid_middleware_match_type_falls_back_to_any_with_warning(): void
    {
        config(['forge.levels' => [
            'dual' => [
                'description' => '双条件',
                'load'        => 'lazy',
                'match'       => [
                    'middleware'       => ['auth', 'role:admin'],
                    'middleware_match' => 1,   // 非法：既非 string 也非 DNF 数组
                ],
            ],
        ]]);

        // 回落 'any' 才会命中；若被当作 'all' 则不命中
        RouteFacade::get('/dual/one', static function () {})->name('dual.one')->middleware(['auth']);

        // TierResolver 是容器单例、构造时捕获 logger：必须在首次解析前替换
        $logger = new RecordingTestLogger();
        $this->app->instance(LoggerInterface::class, $logger);

        $dual = $this->get($this->endpoint('dual'));
        $dual->assertStatus(200);
        $this->assertSame(['dual.one'], array_keys((array) $dual->json('routes')));

        $matched = array_values(array_filter(
            $logger->warnings,
            static fn (string $m): bool => str_contains($m, 'middleware_match rule has invalid type [int]')
        ));
        $this->assertNotEmpty(
            $matched,
            '非法 middleware_match 类型应记一条 warning，实际 warnings=' . json_encode($logger->warnings)
        );
        // 文本须给出可自助的修复指引（get_debug_type()：int 输出 [int]，不是 [integer]）
        $this->assertStringContainsString('expected string ("any"/"all") or DNF array', $matched[0]);
        $this->assertStringContainsString('Falling back to "any".', $matched[0]);
    }

    /**
     * 命令面必须与端点一致——之前别名那轮漂移正是「端点里有、list/types 里没有」，
     * 配置形态的差异同样不能只测端点。
     */
    public function test_single_string_prefix_is_reflected_in_list_and_types_commands(): void
    {
        config(['forge.levels' => [
            'report' => ['description' => '报表', 'load' => 'lazy', 'match' => ['prefix' => 'report']],
        ]]);

        RouteFacade::get('/report/monthly', static function () {})->name('report.monthly');
        RouteFacade::post('/report/export', static function () {})->name('report.export');

        $list = $this->commandJson('route:forge:list', ['--json' => true]);
        $levelByName = array_column((array) $list['routes'], 'level', 'name');
        $this->assertSame('report', $levelByName['report.monthly'] ?? null);
        $this->assertSame('report', $levelByName['report.export'] ?? null);
        $this->assertSame(2, $list['tier_counts']['report'] ?? null);

        $types = $this->commandJson('route:forge:types', ['--json' => true]);
        $this->assertSame(['report.monthly', 'report.export'], array_keys((array) ($types['report'] ?? [])));
        // POST 仍带 body 类型：证明单值写法走的是同一套收集逻辑，不是降级路径
        $entry = (array) ($types['report']['report.export'] ?? []);
        $this->assertSame('POST', $entry['method'] ?? null);
        $this->assertArrayHasKey('body', $entry);
    }

    /**
     * 跑命令并解析其 JSON 产物。
     *
     * 命令的警告写 stderr，而 Kernel::call 注入的是单一 BufferedOutput（无独立错误流），
     * 因此测试里警告可能前缀在产物之前——真实终端下两条流天然分离。
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function commandJson(string $command, array $options = []): array
    {
        $buffer = new BufferedOutput();
        $exit = $this->app->make(Kernel::class)->call($command, $options, $buffer);
        $this->assertSame(0, $exit, $command . ' 应以 0 退出');

        $raw = $buffer->fetch();
        $start = strpos($raw, '{');
        $this->assertNotFalse($start, $command . ' 应产出 JSON 对象');

        return (array) json_decode(substr($raw, $start), true, flags: JSON_THROW_ON_ERROR);
    }
}
