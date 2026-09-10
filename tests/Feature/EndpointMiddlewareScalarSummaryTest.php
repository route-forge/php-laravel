<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Route as RouteFacade;
use Orchestra\Testbench\TestCase;
use RouteForge\Laravel\ForgeServiceProvider;

/**
 * 摘要端点的顶层 endpoint_middleware 写成「单个字符串」的形态测试（对应 SPEC §3.1.6）。
 *
 * 修复前这里比层级侧更阴险：is_array() 守卫让非数组值被静默丢掉，不报错也不挂中间件，
 * 开发者以为摘要端点受保护，实际任何人可读——而摘要里就写着全部层级与运行时配置。
 * 现在与层级侧同口径按 (array) 归一化，单值写法真实生效。
 *
 * 独立成类是为了不和 {@see EndpointMiddlewareScalarTest} 的层级侧配置互相遮蔽
 * （层级侧一旦抛 TypeError，就看不到摘要侧的静默忽略了）。
 */
class EndpointMiddlewareScalarSummaryTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ForgeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.debug', false);

        // 只配摘要侧：单值字符串
        $app['config']->set('forge.endpoint_middleware', ForgeTestBlockMiddleware::class);
    }

    public function test_summary_endpoint_accepts_single_string_middleware_and_blocks(): void
    {
        RouteFacade::get('/admin/users', static function () {})
            ->name('admin.users.index')
            ->tier('admin');

        $prefix = (string) config('forge.endpoint_prefix', '/_forge/routes');

        $this->get('/' . ltrim(rtrim($prefix, '/'), '/'))->assertStatus(403);
    }
}
