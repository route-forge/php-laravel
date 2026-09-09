<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Route as RouteFacade;
use Orchestra\Testbench\TestCase;
use RouteForge\Laravel\ForgeServiceProvider;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * 路由别名测试（对应 .docs/SPEC.md §3.1.7）。
 *
 * 覆盖：
 *   1. 宏 ->forgeAlias() 声明的别名出现在目标路由所在层级端点
 *   2. config aliases 声明的别名同样生效
 *   3. 优先级：宏（显式）> config
 *   4. 别名与真实路由名撞车 → 真实路由优先，别名被忽略
 *   5. 悬空别名 → RF_BE_008 / 500（fail-fast）
 *   6. 别名元信息与目标路由完全一致（uri/methods/parameters/parameter_defaults）
 *   7. 别名跟随目标路由的层级归属（含 unassigned 特殊层级）
 *   8. 摘要端点 route_count 计入别名
 *   9. 缓存模式下别名随扫描缓存
 *  10. route:forge:types 为别名生成类型条目
 *  11. route:forge:list 别名行 / --aliases 过滤 / warnings
 *  12. 宏基本校验（空参数抛 InvalidArgumentException）
 *  13. 目标路由名跨层级重复注册时：别名在每个解析层级都可见（端点 / list / d.ts），
 *     并给出重复注册警告；计数口径仍与摘要 route_count 一致
 */
class RouteAliasTest extends TestCase
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

    private function summaryEndpoint(): string
    {
        return rtrim($this->endpoint('admin'), '/admin');
    }

    public function test_macro_alias_appears_in_level_endpoint(): void
    {
        RouteFacade::get('/admin/members', static function () {})
            ->name('admin.members.index')
            ->tier('admin')
            ->forgeAlias('admin.users.index');

        $routes = $this->get($this->endpoint('admin'))->json('routes');

        // 新旧两个名字同时可用，元信息完全一致
        $this->assertArrayHasKey('admin.members.index', $routes);
        $this->assertArrayHasKey('admin.users.index', $routes);
        $this->assertSame($routes['admin.members.index'], $routes['admin.users.index']);
        $this->assertSame('admin/members', $routes['admin.users.index']['uri']);
    }

    public function test_config_alias_appears_in_level_endpoint(): void
    {
        config(['forge.aliases' => ['admin.users.index' => 'admin.members.index']]);

        RouteFacade::get('/admin/members', static function () {})
            ->name('admin.members.index')
            ->tier('admin');

        $routes = $this->get($this->endpoint('admin'))->json('routes');

        $this->assertArrayHasKey('admin.users.index', $routes);
        $this->assertSame($routes['admin.members.index'], $routes['admin.users.index']);
    }

    public function test_macro_alias_wins_over_config(): void
    {
        config(['forge.aliases' => ['old.name' => 'config.target']]);

        RouteFacade::get('/admin/macro', static function () {})
            ->name('macro.target')
            ->tier('admin')
            ->forgeAlias('old.name');
        RouteFacade::get('/admin/config', static function () {})
            ->name('config.target')
            ->tier('admin');

        $routes = $this->get($this->endpoint('admin'))->json('routes');

        // 同一别名双通道声明：显式宏优先
        $this->assertSame('admin/macro', $routes['old.name']['uri']);
    }

    public function test_alias_colliding_with_real_route_is_ignored(): void
    {
        config(['forge.aliases' => ['admin.real.index' => 'admin.members.index']]);

        RouteFacade::get('/admin/members', static function () {})
            ->name('admin.members.index')
            ->tier('admin');
        // 真实路由占用了别名想使用的名字
        RouteFacade::get('/admin/real', static function () {})
            ->name('admin.real.index')
            ->tier('admin');

        $routes = $this->get($this->endpoint('admin'))->json('routes');

        // 真实路由优先：该名字对应的仍是自己的元信息
        $this->assertSame('admin/real', $routes['admin.real.index']['uri']);
        $this->assertCount(2, (array) $routes);
    }

    public function test_dangling_config_alias_fails_fast_with_rf_be_008(): void
    {
        config(['forge.aliases' => ['admin.users.index' => 'admin.missing.index']]);

        RouteFacade::get('/admin/members', static function () {})
            ->name('admin.members.index')
            ->tier('admin');

        $response = $this->get($this->endpoint('admin'));

        $response->assertStatus(500);
        $this->assertSame('RF_BE_008', $response->json('error.code'));
    }

    public function test_alias_meta_is_pure_copy_without_marker_fields(): void
    {
        RouteFacade::get('/admin/members/{member}', static function () {})
            ->name('admin.members.show')
            ->tier('admin')
            ->defaults('member', 'default-member')
            ->forgeAlias('admin.members.detail');

        $routes = $this->get($this->endpoint('admin'))->json('routes');

        $target = $routes['admin.members.show'];
        $this->assertSame(['member'], $target['parameters']);
        $this->assertSame(['member' => 'default-member'], (array) $target['parameter_defaults']);
        // 纯复制：别名条目与目标逐字段一致，无 alias_of 等附加标记
        $this->assertSame($target, $routes['admin.members.detail']);
        $this->assertArrayNotHasKey('alias_of', $routes['admin.members.detail']);
    }

    public function test_macro_supports_multiple_aliases(): void
    {
        RouteFacade::get('/admin/members', static function () {})
            ->name('admin.members.index')
            ->tier('admin')
            ->forgeAlias('legacy.a', 'legacy.b');

        $routes = $this->get($this->endpoint('admin'))->json('routes');

        $this->assertArrayHasKey('legacy.a', $routes);
        $this->assertArrayHasKey('legacy.b', $routes);
        $this->assertSame($routes['admin.members.index'], $routes['legacy.a']);
        $this->assertSame($routes['admin.members.index'], $routes['legacy.b']);
    }

    public function test_alias_follows_target_into_unassigned_level(): void
    {
        config(['forge.aliases' => ['orphan.old' => 'orphan']]);

        // 未声明 tier、不匹配任何层级 → unassigned
        RouteFacade::get('/orphan-path', static function () {})
            ->name('orphan');

        $routes = $this->get($this->endpoint('unassigned'))->json('routes');

        $this->assertArrayHasKey('orphan', $routes);
        $this->assertArrayHasKey('orphan.old', $routes);
        $this->assertSame($routes['orphan'], $routes['orphan.old']);
    }

    public function test_summary_route_count_includes_aliases(): void
    {
        config(['forge.aliases' => ['admin.users.index' => 'admin.members.index']]);

        RouteFacade::get('/admin/members', static function () {})
            ->name('admin.members.index')
            ->tier('admin');

        $summary = $this->get($this->summaryEndpoint())->json();

        // 1 真实路由 + 1 别名 = 2（count 与层级端点 routes 键数量一致）
        $this->assertSame(2, $summary['levels']['admin']['route_count']);
    }

    public function test_alias_is_cached_with_level_payload(): void
    {
        config(['app.debug' => false]); // 关闭 debug 使缓存生效

        RouteFacade::get('/admin/members', static function () {})
            ->name('admin.members.index')
            ->tier('admin')
            ->forgeAlias('admin.users.index');

        $first = $this->get($this->endpoint('admin'))->json('routes');
        $this->assertArrayHasKey('admin.users.index', $first);

        // 第二次请求走缓存，别名条目仍在
        $second = $this->get($this->endpoint('admin'))->json('routes');
        $this->assertArrayHasKey('admin.users.index', $second);
        $this->assertSame($second['admin.members.index'], $second['admin.users.index']);
    }

    public function test_types_command_generates_alias_entries(): void
    {
        RouteFacade::get('/admin/members', static function () {})
            ->name('admin.members.index')
            ->tier('admin')
            ->forgeAlias('admin.users.index');

        $buffer = new BufferedOutput();
        $exit = $this->app->make(Kernel::class)->call('route:forge:types', ['--json' => true], $buffer);

        $this->assertSame(0, $exit);
        $types = json_decode($buffer->fetch(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('admin.members.index', $types['admin']);
        $this->assertArrayHasKey('admin.users.index', $types['admin']);
        $this->assertSame($types['admin']['admin.members.index'], $types['admin']['admin.users.index']);
    }

    public function test_list_command_shows_alias_rows_and_supports_filter(): void
    {
        RouteFacade::get('/admin/members', static function () {})
            ->name('admin.members.index')
            ->tier('admin')
            ->forgeAlias('admin.users.index');

        $buffer = new BufferedOutput();
        $exit = $this->app->make(Kernel::class)->call('route:forge:list', ['--json' => true], $buffer);
        $this->assertSame(0, $exit);
        $out = json_decode($buffer->fetch(), true, flags: JSON_THROW_ON_ERROR);

        $rowsByName = array_column($out['routes'], null, 'name');
        $this->assertNull($rowsByName['admin.members.index']['alias_of']);
        $this->assertSame('admin.members.index', $rowsByName['admin.users.index']['alias_of']);
        $this->assertSame('admin', $rowsByName['admin.users.index']['level']);

        // --aliases：仅显示别名条目
        $buffer = new BufferedOutput();
        $exit = $this->app->make(Kernel::class)->call('route:forge:list', ['--aliases' => true, '--json' => true], $buffer);
        $this->assertSame(0, $exit);
        $out = json_decode($buffer->fetch(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(1, $out['routes']);
        $this->assertSame('admin.users.index', $out['routes'][0]['name']);
        $this->assertSame('admin.members.index', $out['routes'][0]['alias_of']);
    }

    public function test_list_command_reports_collision_warnings(): void
    {
        config(['forge.aliases' => ['admin.real.index' => 'admin.members.index']]);

        RouteFacade::get('/admin/members', static function () {})
            ->name('admin.members.index')
            ->tier('admin');
        RouteFacade::get('/admin/real', static function () {})
            ->name('admin.real.index')
            ->tier('admin');

        $buffer = new BufferedOutput();
        $exit = $this->app->make(Kernel::class)->call('route:forge:list', ['--json' => true], $buffer);
        $this->assertSame(0, $exit);
        $out = json_decode($buffer->fetch(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertNotEmpty($out['warnings']);
        $this->assertStringContainsString('admin.real.index', $out['warnings'][0]);
    }

    public function test_macro_rejects_empty_alias_arguments(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RouteFacade::get('/admin/members', static function () {})
            ->name('admin.members.index')
            ->tier('admin')
            ->forgeAlias();
    }

    public function test_alias_on_unnamed_route_yields_warning_instead_of_silent_loss(): void
    {
        // 无名路由上的 forgeAlias() 声明无法挂载元信息（B7）：
        // 不注入别名条目，但应产生 warning 而非静默消失
        RouteFacade::get('/admin/anonymous', static function () {})
            ->tier('admin')
            ->forgeAlias('admin.users.index');

        $routes = $this->get($this->endpoint('admin'))->json('routes');
        $this->assertArrayNotHasKey('admin.users.index', $routes);

        $buffer = new BufferedOutput();
        $this->app->make(Kernel::class)->call('route:forge:list', ['--aliases' => true], $buffer);
        $raw = $buffer->fetch();

        // table 模式：即使过滤后 0 行（早退），警告也必须可见
        $this->assertStringContainsString('declared via ->forgeAlias() on an unnamed route', $raw);
        $this->assertStringContainsString('Add ->name(...) to the route', $raw);
    }

    public function test_macro_alias_colliding_with_real_route_is_ignored_and_not_clobbering(): void
    {
        // 宏别名与真实路由名撞车：真实路由优先（修复前宏别名会静默覆盖
        // 端点元信息中真实路由的条目，造成数据污染）
        RouteFacade::get('/admin/users', static function () {})
            ->name('admin.users.index')
            ->tier('admin');
        RouteFacade::get('/admin/members', static function () {})
            ->name('admin.members.index')
            ->tier('admin')
            ->forgeAlias('admin.users.index');

        $routes = $this->get($this->endpoint('admin'))->json('routes');

        // 真实路由条目保持自己的元信息，未被别名覆盖
        $this->assertSame('admin/users', $routes['admin.users.index']['uri']);
        // 只出现一次 admin.users.index（撞车别名不注入）
        $this->assertSame(['admin.users.index', 'admin.members.index'], array_keys($routes));

        // list --json：warning 提示撞车
        $buffer = new BufferedOutput();
        $this->app->make(Kernel::class)->call('route:forge:list', ['--json' => true], $buffer);
        $out = json_decode($buffer->fetch(), true, flags: JSON_THROW_ON_ERROR);
        $found = array_filter($out['warnings'], fn (string $w) => str_contains($w, "Alias [admin.users.index] collides with a real route name"));
        $this->assertNotEmpty($found);
    }

    public function test_macro_alias_declared_on_multiple_routes_first_wins_with_warning(): void
    {
        // 同一别名在多条路由上宏声明：先声明者优先，重复的记录警告
        RouteFacade::get('/admin/first', static function () {})
            ->name('admin.first')
            ->tier('admin')
            ->forgeAlias('shared.alias');
        RouteFacade::get('/admin/second', static function () {})
            ->name('admin.second')
            ->tier('admin')
            ->forgeAlias('shared.alias');

        $routes = $this->get($this->endpoint('admin'))->json('routes');

        $this->assertSame('admin/first', $routes['shared.alias']['uri']);

        $buffer = new BufferedOutput();
        $this->app->make(Kernel::class)->call('route:forge:list', ['--json' => true], $buffer);
        $out = json_decode($buffer->fetch(), true, flags: JSON_THROW_ON_ERROR);
        $found = array_filter($out['warnings'], fn (string $w) => str_contains($w, 'declared via ->forgeAlias() on multiple routes'));
        $this->assertNotEmpty($found);
    }

    /**
     * 跑 route:forge:list --json 并解码为数组。
     *
     * @param array<string, mixed> $options 额外选项（如 ['--level' => 'admin']）
     *
     * @return array<string, mixed>
     */
    private function listJson(array $options = []): array
    {
        $buffer = new BufferedOutput();
        $exit = $this->app->make(Kernel::class)->call('route:forge:list', $options + ['--json' => true], $buffer);
        $this->assertSame(0, $exit, 'route:forge:list 应成功退出');

        return json_decode($buffer->fetch(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * 解析 route:forge:types --json 的产物。
     *
     * 警告写 stderr，而 Kernel::call 注入的是单一 BufferedOutput（没有独立错误流），
     * 因此测试里警告会排在 JSON 前面——真实终端下 `> out.json` 两者天然分离，
     * 这里只剥离前导警告行。
     *
     * @return array<string, mixed>
     */
    private function decodeTypesJson(string $raw): array
    {
        $start = strpos($raw, '{');
        $this->assertNotFalse($start, 'types --json 应产出 JSON 对象');

        return json_decode(substr($raw, $start), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_alias_is_visible_in_every_tier_its_target_resolves_to(): void
    {
        // 同一个路由名以不同层级注册两次（改名过渡期的典型手误）：别名必须跟随
        // target 出现在它解析到的每个层级——否则端点里有该键、d.ts 与 --level 视图
        // 却没有，前端按旧名取用会被判 UnknownRouteName。
        config(['forge.aliases' => ['legacy.members' => 'admin.members.index']]);

        RouteFacade::get('/admin/members', static function () {})
            ->name('admin.members.index')
            ->tier('admin');
        RouteFacade::get('/client/members-export', static function () {})
            ->name('admin.members.index'); // 无显式 tier，靠 match.prefix=client 落到 client

        // 端点侧：两个层级都注入别名键（既有契约，锁死防回归）
        $this->assertArrayHasKey('legacy.members', $this->get($this->endpoint('admin'))->json('routes'));
        $this->assertArrayHasKey('legacy.members', $this->get($this->endpoint('client'))->json('routes'));

        // list --level：别名行在各自层级可见
        $this->assertContains(
            'legacy.members',
            array_column($this->listJson(['--level' => 'admin'])['routes'], 'name')
        );
        $this->assertContains(
            'legacy.members',
            array_column($this->listJson(['--level' => 'client'])['routes'], 'name')
        );

        // 重复注册本身是配置歧义，必须显式警告而非静默挑一个
        $warn = array_filter(
            $this->listJson()['warnings'],
            fn (string $w): bool => str_contains($w, 'Route name [admin.members.index] is registered 2 times')
        );
        $this->assertNotEmpty($warn);
    }

    public function test_alias_counting_and_types_stay_consistent_with_duplicate_target_name(): void
    {
        config(['forge.aliases' => ['legacy.members' => 'admin.members.index']]);

        RouteFacade::get('/admin/members', static function () {})
            ->name('admin.members.index')
            ->tier('admin');
        RouteFacade::get('/client/members-export', static function () {})
            ->name('admin.members.index');

        // 计数口径不变：一个别名只计一次，计在末次注册层级（与摘要 route_count 同源）
        $counts = $this->listJson()['tier_counts'];
        $this->assertSame(1, $counts['admin']);
        $this->assertSame(2, $counts['client']);

        $levels = $this->get($this->summaryEndpoint())->json('levels');
        $this->assertSame(1, $levels['admin']['route_count']);
        $this->assertSame(2, $levels['client']['route_count']);

        // d.ts 类型条目按层级铺开：两个层级都能用旧名取到类型
        $buffer = new BufferedOutput();
        $exit = $this->app->make(Kernel::class)->call('route:forge:types', ['--json' => true], $buffer);
        $this->assertSame(0, $exit);
        $types = $this->decodeTypesJson($buffer->fetch());

        $this->assertArrayHasKey('legacy.members', $types['admin']);
        $this->assertArrayHasKey('legacy.members', $types['client']);
        // 别名的类型约束与目标路由完全一致（SPEC §3.1.7 的「纯复制」）
        $this->assertSame($types['admin']['admin.members.index'], $types['admin']['legacy.members']);

        // --level 单层级收集时别名同样在产物里
        $buffer = new BufferedOutput();
        $this->app->make(Kernel::class)->call('route:forge:types', ['--level' => 'admin', '--json' => true], $buffer);
        $only = $this->decodeTypesJson($buffer->fetch());
        $this->assertArrayHasKey('legacy.members', $only['admin']);
        $this->assertArrayNotHasKey('client', $only);
    }
}
