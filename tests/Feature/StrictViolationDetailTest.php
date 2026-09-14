<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Route as RouteFacade;
use Orchestra\Testbench\TestCase;
use RouteForge\Laravel\ForgeServiceProvider;

/**
 * 开发模式（APP_DEBUG=true）下 RF_BE_009 错误体的结构化清单（对应 SPEC §6.1）。
 *
 * 单独成类的原因：app.debug 是 Testbench 类级 defineEnvironment 决定的（方法内再改已晚于
 * Provider boot 与 RouteRepository 装配），与 EndpointTest 的 debug=false 形态不能共存一类。
 *
 * 口径：只有开发模式才把 violations() 随错误体下发，供管理器页面/控制台逐行定位；
 * 生产形态（见 EndpointTest）只给 code + message——清单本身是宿主越界路由的名字与 URI 目录。
 */
class StrictViolationDetailTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ForgeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.debug', true);
    }

    public function test_debug_error_body_carries_structured_violations(): void
    {
        config()->set('forge.strict_mode', true);

        // 两类问题各一条：命名但未归级 / 有层级归属但没有名字
        RouteFacade::get('/orphan', static function () {})->name('orphan');
        RouteFacade::get('/admin/nameless', static function () {})->tier('admin');

        $response = $this->get('/_forge/routes/admin');

        $response->assertStatus(500);
        $this->assertSame('RF_BE_009', $response->json('error.code'));

        $violations = $response->json('error.violations');
        $this->assertIsArray($violations);
        // 三组恒定存在（即使为空），页面按组渲染时不必再判键是否存在
        foreach (['missing_name', 'unassigned', 'unresolved'] as $group) {
            $this->assertArrayHasKey($group, $violations);
        }

        $this->assertSame('orphan', $violations['unassigned'][0]['name']);
        $this->assertSame('orphan', $violations['unassigned'][0]['uri']);
        $this->assertSame('admin/nameless', $violations['missing_name'][0]['uri']);
        // 命中来源随条目下发，页面才能直接说出「是显式 tier 还是 match 规则把它归来的」
        $this->assertSame('explicit', $violations['missing_name'][0]['source']);

        // 包自身的端点（未命名 + 无名层级端点）不得混进清单
        $this->assertCount(1, $violations['missing_name']);
        $this->assertCount(1, $violations['unassigned']);
    }

    public function test_no_violations_key_when_strict_mode_is_off(): void
    {
        // 宽松模式下未归级是合法状态，端点正常返回，也不该出现违规字段
        RouteFacade::get('/orphan', static function () {})->name('orphan');

        $response = $this->get('/_forge/routes/admin');

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('violations', (array) $response->json());
    }
}
