<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Orchestra\Testbench\TestCase;
use RouteForge\Laravel\ForgeServiceProvider;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * route:forge:list 命令测试（对应 .docs/SPEC.md §3.2）。
 *
 * 默认配置：strict_mode=false、levels=[public, client, manage, admin]，
 * 未命中层级的路由归入 unassigned 特殊层级（fallback_level 已移除）。
 * ForgeServiceProvider::boot() 会注册 forge.routes.{index,show} 元信息端点，
 * 命令内部按 str_starts_with('forge.routes.') 跳过它们，故不会污染输出。
 *
 * 说明：使用 Kernel::call + BufferedOutput 捕获完整输出后用 assertStringContainsString
 * 断言，避免 expectsOutputToContain 在「单次 doWrite 输出多行」时仅能匹配首个子串的限制。
 */
class ListCommandTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ForgeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // 开发模式：管理器页面路由（forge.manager.*）也会注册，
        // 用于验证命令输出不会混入 forge 自身的任何路由
        $app['config']->set('app.debug', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // admin 层级：显式 tier
        RouteFacade::get('/admin/users', static function () {})
            ->name('admin.users.index')
            ->tier('admin');

        // client 层级：显式 tier
        RouteFacade::get('/client/dashboard', static function () {})
            ->name('client.dashboard')
            ->tier('client');

        // orphan：未声明 tier、不匹配任何 prefix/middleware → unassigned
        RouteFacade::get('/orphan', static function () {})
            ->name('orphan');
    }

    /**
     * @return array{0:int,1:string}
     */
    private function runList(array $params = []): array
    {
        $buffer = new BufferedOutput();
        $exit = $this->app->make(Kernel::class)->call('route:forge:list', $params, $buffer);

        return [$exit, $buffer->fetch()];
    }

    public function test_default_lists_all_routes_with_table_header(): void
    {
        [$exit, $out] = $this->runList();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Name', $out);
        $this->assertStringContainsString('Level', $out);
        $this->assertStringContainsString('Methods', $out);
        $this->assertStringContainsString('URI', $out);
        $this->assertStringContainsString('admin.users.index', $out);
        $this->assertStringContainsString('client.dashboard', $out);
        $this->assertStringContainsString('orphan', $out);
    }

    public function test_level_filter_only_lists_matching_routes(): void
    {
        [$exit, $out] = $this->runList(['--level' => 'admin']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('admin.users.index', $out);
        $this->assertStringContainsString('admin', $out);
        $this->assertStringNotContainsString('client.dashboard', $out);
        $this->assertStringNotContainsString('orphan', $out);
    }

    public function test_level_filter_unknown_level_shows_available(): void
    {
        [$exit, $out] = $this->runList(['--level' => 'nonexistent']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Unknown level', $out);
        $this->assertStringContainsString('Available levels', $out);
        // 可用层级应被列出
        $this->assertStringContainsString('public', $out);
        $this->assertStringContainsString('admin', $out);
    }

    public function test_json_output_format(): void
    {
        [$exit, $out] = $this->runList(['--json' => true]);

        $this->assertSame(0, $exit);

        $decoded = json_decode($out, true);
        $this->assertIsArray($decoded, 'JSON 输出应为数组');

        // 结构化输出：含 levels / count / routes 字段
        $this->assertArrayHasKey('levels', $decoded);
        $this->assertArrayHasKey('count', $decoded);
        $this->assertArrayHasKey('routes', $decoded);
        $this->assertNull($decoded['filter'], '无过滤条件时 filter 为 null');
        $this->assertSame(3, $decoded['count']);

        // levels 应包含已定义层级 + unassigned 特殊层级
        $this->assertContains('admin', $decoded['levels']);
        $this->assertContains('unassigned', $decoded['levels']);

        // routes 为路由条目数组
        $routes = $decoded['routes'];
        $names  = array_column($routes, 'name');
        $this->assertContains('admin.users.index', $names);
        $this->assertContains('client.dashboard', $names);
        $this->assertContains('orphan', $names);

        // 验证条目结构
        $adminKey = array_search('admin.users.index', $names, true);
        $admin  = $routes[$adminKey];
        $this->assertSame('admin', $admin['level']);
        $this->assertSame(['GET'], $admin['methods']);
        $this->assertSame('admin/users', $admin['uri']);

        // orphan 未分配 → level='unassigned'（不再为 null）
        $orphanKey = array_search('orphan', $names, true);
        $this->assertSame('unassigned', $routes[$orphanKey]['level']);
    }

    public function test_unassigned_filter_lists_only_unassigned(): void
    {
        // 未命中层级的命名路由归入 unassigned（唯一的兜底机制）
        [$exit, $out] = $this->runList(['--unassigned' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('orphan', $out);
        $this->assertStringNotContainsString('admin.users.index', $out);
        $this->assertStringNotContainsString('client.dashboard', $out);
    }

    public function test_level_filter_accepts_unassigned_special_level(): void
    {
        // --level=unassigned 与 --unassigned 语义一致（特殊层级可作为过滤值）
        [$exit, $out] = $this->runList(['--level' => 'unassigned']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('orphan', $out);
        $this->assertStringNotContainsString('admin.users.index', $out);
        $this->assertStringNotContainsString('client.dashboard', $out);
    }

    public function test_forge_internal_routes_excluded_from_output(): void
    {
        // APP_DEBUG=true 时 forge.manager.* 四条路由已注册（defineEnvironment），
        // 命令输出应同时跳过 forge.routes.* 与 forge.manager.* 自身路由
        [, $out] = $this->runList(['--json' => true]);

        $decoded = json_decode($out, true);
        $names   = array_column($decoded['routes'], 'name');

        foreach ($names as $name) {
            $this->assertStringStartsNotWith('forge.', $name, "forge 自身路由 [{$name}] 不应出现在输出中");
        }
        $this->assertSame(3, $decoded['count']);
    }

    public function test_strict_mode_prints_red_violation_list_and_exits_1(): void
    {
        // common ≥ 1.2.0：严格模式不再「第一条未归级就中断并打 [RF_BE_001]」，
        // 改为表格照常输出、末尾追加红色违规清单、退出码 1——排查场景下命令本身必须还能跑
        config(['forge.strict_mode' => true]);

        [$exit, $out] = $this->runList();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Route Forge strict_mode found 1 route configuration problem(s):', $out);
        $this->assertStringContainsString('named route(s) are not matched by any level rule', $out);
        $this->assertStringContainsString('orphan (GET orphan)', $out);
        // 违规不再是「一条一码」的 Forge 异常形态，也不得吐堆栈
        $this->assertStringNotContainsString('[RF_BE_', $out);
        $this->assertStringNotContainsString('Stack trace', $out);
        // 清单是追加而非替代：统计行与表格仍在，便于对照
        $this->assertStringContainsString('Tier counts:', $out);
        $this->assertStringContainsString('admin.users.index', $out);
    }

    public function test_strict_mode_reports_missing_name_and_unassigned_in_one_run(): void
    {
        // 两类问题一次报全（旧实现只会先撞上其中一条就退出，修一条刷一条）
        config(['forge.strict_mode' => true]);
        RouteFacade::get('/admin/unnamed', static function () {})->tier('admin');

        [$exit, $out] = $this->runList();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('found 2 route configuration problem(s)', $out);
        $this->assertStringContainsString('assigned to a level but have no', $out);
        $this->assertStringContainsString('not matched by any level rule', $out);
        $this->assertStringContainsString('admin/unnamed', $out);
        $this->assertStringContainsString('orphan', $out);
    }

    public function test_strict_mode_does_not_report_package_own_endpoint_as_violation(): void
    {
        // 层级端点 `_forge/routes/admin` 是未命名路由，且带 admin 层级自己的 endpoint_middleware，
        // 会被该层级的 match.middleware 规则命中。RouteNameFilter 的 URI 维度没接线时，
        // 包会把自己报成宿主的「有层级无名字」违规——这里钉住它必须不出现。
        config([
            'forge.strict_mode' => true,
            'forge.levels.admin.endpoint_middleware' => ['auth', 'admin'],
        ]);

        [$exit, $out] = $this->runList();

        $this->assertSame(1, $exit);
        $this->assertStringNotContainsString('_forge/routes', $out);
        $this->assertStringNotContainsString('_forge/manager', $out);
    }

    public function test_tier_without_name_prints_console_warning(): void
    {
        // 有 tier 无 name：非严格模式下该路由静默消失，命令应在控制台直接暴露
        RouteFacade::get('/admin/unnamed', static function () {})->tier('admin');

        [$exit, $out] = $this->runList();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('has tier [admin] but no route name assigned', $out);
        $this->assertStringContainsString('Add ->name(...)', $out);
    }

    public function test_tier_without_name_warning_in_json_output(): void
    {
        RouteFacade::get('/admin/unnamed', static function () {})->tier('admin');

        [$exit, $out] = $this->runList(['--json' => true]);

        $this->assertSame(0, $exit);
        $decoded = json_decode($out, true);
        $this->assertNotEmpty($decoded['warnings']);
        $found = array_filter($decoded['warnings'], fn (string $w) => str_contains($w, 'has tier [admin] but no route name assigned'));
        $this->assertNotEmpty($found, 'JSON warnings 应包含 tier 无 name 警告');
        // 无名路由不进入 routes 列表
        foreach ($decoded['routes'] as $route) {
            $this->assertNotSame('/admin/unnamed', $route['uri']);
        }
    }

    public function test_unnamed_view_lists_every_unnamed_route_without_table_or_warnings(): void
    {
        // --unnamed 的价值正是「事后也能找出谁没名字」：既列被层级规则命中的
        // （manage/no-name 靠 match.prefix 命中，历史上完全静默），也列压根没归级的（freeform）。
        RouteFacade::get('/manage/no-name', static function () {});
        RouteFacade::get('/freeform', static function () {});

        [$exit, $out] = $this->runList(['--unnamed' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('unnamed route(s) total', $out);
        $this->assertStringContainsString('manage/no-name', $out);
        $this->assertStringContainsString('freeform', $out);
        // 独立视图：不打印表格与统计，避免与 warnings 双写同一事实
        $this->assertStringNotContainsString('Tier counts:', $out);
        $this->assertStringNotContainsString('admin.users.index', $out);
        // 包自身的未命名层级端点不得混进来（按 URI 排除，否则视图被自家路由刷满）
        $this->assertStringNotContainsString('_forge/routes', $out);
    }

    public function test_unnamed_view_honours_level_filter(): void
    {
        RouteFacade::get('/manage/no-name', static function () {});
        RouteFacade::get('/freeform', static function () {});

        [$exit, $out] = $this->runList(['--unnamed' => true, '--level' => 'manage']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('manage/no-name', $out);
        $this->assertStringNotContainsString('freeform', $out);
    }

    public function test_unnamed_view_says_so_when_every_route_is_named(): void
    {
        // 空视图要给明确文案，而不是空屏让人以为命令没跑
        [$exit, $out] = $this->runList(['--unnamed' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No unnamed routes', $out);
    }

    public function test_unnamed_view_rejects_being_combined_with_output_modes(): void
    {
        // unnamed 是独立数据源（不在 rows 里）：与 --json 同屏要为此新造一份 JSON 形态，
        // 与表格同屏就是双写。判为参数冲突，而不是悄悄丢掉某一边。
        foreach (['--json', '--unassigned', '--aliases'] as $opt) {
            [$exit, $out] = $this->runList(['--unnamed' => true, $opt => true]);

            $this->assertSame(1, $exit, "组合 {$opt} 应判为参数冲突");
            $this->assertStringContainsString('standalone view', $out);
        }
    }

    public function test_unnamed_view_can_isolate_the_virtual_unresolved_group(): void
    {
        // classifier 返回未配置的层级名 → probe 标为 classifier-unknown-tier，条目落在虚拟分组
        // unresolved：它既不是真实层级、也不属于 unassigned，唯独 --unnamed 视图能按它过滤。
        // 这批「层级名写错的未命名路由」正是最难自己冒出来的，放通标签才有排查入口。
        config(['forge.classifier' => static function (Route $route): ?string {
            return $route->uri() === 'weird/one' ? 'not-a-configured-level' : null;
        }]);
        RouteFacade::get('/weird/one', static function () {})->middleware('auth');

        [$exit, $out] = $this->runList(['--unnamed' => true, '--level' => 'unresolved']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('unresolved', $out);
        $this->assertStringContainsString('weird/one', $out);
    }

    public function test_summary_counts_printed_with_unassigned_hint(): void
    {
        [$exit, $out] = $this->runList();

        $this->assertSame(0, $exit);
        // 各层级计数行（0 也列出，含 unassigned）
        $this->assertStringContainsString('Tier counts:', $out);
        $this->assertStringContainsString('public: 0', $out);
        $this->assertStringContainsString('admin: 1', $out);
        $this->assertStringContainsString('unassigned: 1', $out);
        // unassigned 非零提示
        $this->assertStringContainsString("1 route(s) are unassigned and only available via the 'unassigned' tier.", $out);
    }

    public function test_summary_no_unassigned_hint_when_all_assigned(): void
    {
        // 追加一个命中 orphan 前缀的层级，使 orphan 不再 unassigned
        config(['forge.levels' => array_merge(config('forge.levels'), [
            'catch' => ['description' => '', 'match' => ['prefix' => ['orphan']]],
        ])]);

        [$exit, $out] = $this->runList();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('catch: 1', $out);
        $this->assertStringContainsString('unassigned: 0', $out);
        $this->assertStringNotContainsString('route(s) are unassigned', $out);
    }

    public function test_tier_counts_in_json_output(): void
    {
        [$exit, $out] = $this->runList(['--json' => true]);

        $this->assertSame(0, $exit);
        $decoded = json_decode($out, true);
        $this->assertSame(0, $decoded['tier_counts']['public']);
        $this->assertSame(1, $decoded['tier_counts']['admin']);
        $this->assertSame(1, $decoded['tier_counts']['unassigned']);
    }

    public function test_table_marks_unassigned_rows_magenta(): void
    {
        $buffer = new BufferedOutput();
        $buffer->setDecorated(true);
        $exit = $this->app->make(Kernel::class)->call('route:forge:list', [], $buffer);
        $out  = $buffer->fetch();

        $this->assertSame(0, $exit);
        // 未分配层级整行品红（fg=magenta → \e[35m）：Name 列与 Level 列都着色
        $this->assertStringContainsString("\033[35morphan", $out);
        $this->assertStringContainsString("\033[35munassigned", $out);
        // 命中层级的真实路由行保持默认颜色（行首无转义码）
        $this->assertMatchesRegularExpression('/^\| admin\.users\.index /m', $out);
        // 汇总行的 warn 提示不受影响，两处信号并存
        $this->assertStringContainsString('route(s) are unassigned', $out);

        // 不支持着色时（未 setDecorated）必须只剩纯文本，不得残留 markup
        [$plainExit, $plain] = $this->runList();
        $this->assertSame(0, $plainExit);
        $this->assertStringNotContainsString('fg=magenta', $plain, '着色 markup 不得泄漏为字面文本');
        $this->assertStringNotContainsString("\033[", $plain, '非着色输出不得混入 ANSI 码');
        $this->assertMatchesRegularExpression('/^\| orphan /m', $plain);
    }

    public function test_magenta_unassigned_wins_over_alias_yellow(): void
    {
        // orphan（setUp 中未分配）的旧名别名：别名行跟随目标落在 unassigned 层级，
        // 整行品红优先于别名黄——「待配层级」是更需要被看到的问题，
        // 别名身份仍由 Alias Of 列的文字表达
        RouteFacade::get('/legacy/orphan', static function () {})
            ->name('legacy.orphan')
            ->forgeAlias('orphan.old');

        $buffer = new BufferedOutput();
        $buffer->setDecorated(true);
        $exit = $this->app->make(Kernel::class)->call('route:forge:list', [], $buffer);
        $out  = $buffer->fetch();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString("\033[35morphan.old", $out);
        $this->assertStringNotContainsString("\033[33morphan.old", $out, '未分配行的品红应优先于别名黄');
        $this->assertStringContainsString('orphan.old', $out);
        // 声明别名的目标路由自身也未分配 → 同为品红（黄只属于命中层级的别名行）
        $this->assertStringContainsString("\033[35mlegacy.orphan", $out);
    }

    public function test_table_marks_alias_rows_yellow_and_real_routes_plain(): void
    {
        // 别名用非撞车名（admin.users.index 是 setUp 中的真实路由，撞车会被忽略）
        RouteFacade::get('/admin/members', static function () {})
            ->name('admin.members.index')
            ->tier('admin')
            ->forgeAlias('admin.members.old');

        $buffer = new BufferedOutput();
        $buffer->setDecorated(true);
        $exit = $this->app->make(Kernel::class)->call('route:forge:list', [], $buffer);
        $out = $buffer->fetch();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Name/Alias', $out);
        // 别名整行黄色（fg=yellow → \e[33m）：名称列与 Alias Of 列都着色
        $this->assertStringContainsString("\033[33madmin.members.old", $out);
        $this->assertStringContainsString("\033[33madmin.members.index", $out);
        // 被别名依赖的真实路由名绿色（fg=green → \e[32m）
        $this->assertStringContainsString("\033[32madmin.members.index", $out);
        // 无别名依赖的真实路由行保持默认颜色：行首无转义码（表头默认绿色，不能全局断言无 32m）
        $this->assertMatchesRegularExpression('/^\| admin\.users\.index /m', $out);
    }

    public function test_json_output_stays_plain_text_without_ansi(): void
    {
        RouteFacade::get('/admin/members', static function () {})
            ->name('admin.members.index')
            ->tier('admin')
            ->forgeAlias('admin.members.old');

        [$exit, $out] = $this->runList(['--json' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringNotContainsString("\033[", $out, 'JSON 输出不得混入 ANSI 颜色码');
    }

    public function test_colliding_alias_declaration_rendered_as_red_row(): void
    {
        // 撞车声明（宏别名撞真实路由名）：真实路由优先，被忽略的声明以红色行
        // 展示在表格中（仅 table；JSON routes 不含该行）
        RouteFacade::get('/admin/members', static function () {})
            ->name('admin.members.index')
            ->tier('admin')
            ->forgeAlias('admin.users.index'); // admin.users.index 是 setUp 中的真实路由

        $buffer = new BufferedOutput();
        $buffer->setDecorated(true);
        $exit = $this->app->make(Kernel::class)->call('route:forge:list', [], $buffer);
        $out = $buffer->fetch();

        $this->assertSame(0, $exit);
        // 撞车声明整行红色（fg=red → \e[31m），Alias Of 列指向其声明目标
        $this->assertStringContainsString("\033[31madmin.users.index", $out);
        $this->assertStringContainsString("\033[31madmin.members.index", $out);
        // 配套 warning 已输出
        $this->assertStringContainsString('collides with a real route name', $out);
    }
}
