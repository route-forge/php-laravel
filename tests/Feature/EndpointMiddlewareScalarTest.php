<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Route as RouteFacade;
use Orchestra\Testbench\TestCase;
use RouteForge\Laravel\ForgeServiceProvider;

/**
 * 层级端点的 endpoint_middleware 写成「单个字符串」的形态测试（对应 .docs/SPEC.md §3.1.5、§5）。
 *
 * 与 Laravel 自身 ->middleware('auth') 同形，因此单值写法是用户直觉写法，必须支持。
 * 修复前：count('auth') 抛 TypeError，且发生在 Provider boot 阶段 → 宿主全站每个请求 500。
 * 现在按 (array) 归一化（与 route-forge/common 1.1.1 的 match 规则同口径）。
 * 摘要侧的同源问题（非数组被 is_array 守卫静默丢掉 → 以为有保护实则没挂中间件）
 * 由 {@see EndpointMiddlewareScalarSummaryTest} 覆盖。
 */
class EndpointMiddlewareScalarTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ForgeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.debug', false);

        // 单值字符串，而非 [ForgeTestBlockMiddleware::class]
        $app['config']->set('forge.levels.admin.endpoint_middleware', ForgeTestBlockMiddleware::class);
    }

    private function endpoint(string $level = 'admin'): string
    {
        return $this->summaryEndpoint() . '/' . $level;
    }

    /** 摘要端点 = 规范化后的 endpoint_prefix 本身（见 SPEC §3.1.6）。 */
    private function summaryEndpoint(): string
    {
        $prefix = (string) config('forge.endpoint_prefix', '/_forge/routes');

        return '/' . ltrim(rtrim($prefix, '/'), '/');
    }

    public function test_level_endpoint_accepts_single_string_middleware_and_blocks(): void
    {
        // 修复前：boot 阶段 count(): Argument #1 ($value) must be of type Countable|array, string given
        RouteFacade::get('/admin/users', static function () {})
            ->name('admin.users.index')
            ->tier('admin');

        // 403 同时证明两件事：没抛 TypeError，且中间件确实挂上了（不是静默忽略）
        $this->get($this->endpoint('admin'))->assertStatus(403);
    }
}
