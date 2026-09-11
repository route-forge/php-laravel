<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Tests\Feature;

use Orchestra\Testbench\TestCase;
use RouteForge\Laravel\ForgeServiceProvider;

/**
 * 管理器页面 IP 白名单测试（manager_allowed_ips，对应 .docs/SPEC.md §3.3）。
 *
 * 访问控制两层：
 *   1. APP_DEBUG=false 不注册管理器路由（见 ForgeManagerControllerTest 相关约定）；
 *   2. 开发环境内 ManagerAllowedIps 中间件按 IP 白名单放行/拒绝。
 *
 * 测试请求的来源 IP 经 server 参数 REMOTE_ADDR 指定
 * （Symfony Request::create 默认为 127.0.0.1）。
 */
class ManagerAllowedIpsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ForgeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // 管理器路由仅开发环境注册
        $app['config']->set('app.debug', true);
    }

    public function test_default_config_allows_loopback_only(): void
    {
        // 默认 ['127.0.0.1', '::1']：本机放行（含 IPv6 回环）
        $this->get('/_forge/manager', ['REMOTE_ADDR' => '127.0.0.1'])->assertStatus(200);
        $this->get('/_forge/manager', ['REMOTE_ADDR' => '::1'])->assertStatus(200);

        // 外部来源 403（页面与 API 一并守卫）
        $this->get('/_forge/manager', ['REMOTE_ADDR' => '203.0.113.7'])->assertStatus(403);
        $this->get('/_forge/manager/api/routes', ['REMOTE_ADDR' => '203.0.113.7'])->assertStatus(403);
        $this->get('/_forge/manager/api/config', ['REMOTE_ADDR' => '203.0.113.7'])->assertStatus(403);
    }

    public function test_configured_lan_ip_is_allowed(): void
    {
        // 局域网调试：追加开发机局域网 IP 后可从该来源访问
        config()->set('forge.manager_allowed_ips', ['127.0.0.1', '::1', '192.168.1.10']);

        $this->get('/_forge/manager', ['REMOTE_ADDR' => '192.168.1.10'])->assertStatus(200);
        // 列表外的其它局域网 IP 仍被拒绝（精确匹配，非网段）
        $this->get('/_forge/manager', ['REMOTE_ADDR' => '192.168.1.99'])->assertStatus(403);
    }

    public function test_wildcard_allows_any_ip(): void
    {
        config()->set('forge.manager_allowed_ips', ['*']);

        $this->get('/_forge/manager', ['REMOTE_ADDR' => '203.0.113.7'])->assertStatus(200);
    }

    public function test_single_string_value_is_normalized_and_enforced(): void
    {
        // 单值写法必须生效（与 Laravel ->middleware('auth') 同形的合法书写）。
        // 修复前的 is_array() 守卫会让这一整段校验被跳过：配置写了、白名单没挂，
        // 任意来源都能读管理器（全部路由元信息 + 运行时配置），属失保护方向。
        config()->set('forge.manager_allowed_ips', '192.168.1.10');

        $this->get('/_forge/manager', ['REMOTE_ADDR' => '192.168.1.10'])->assertStatus(200);
        $this->get('/_forge/manager/api/routes', ['REMOTE_ADDR' => '192.168.1.10'])->assertStatus(200);

        // 列表外的来源一律 403——含默认本机组：单值只放行了那一个 IP
        $this->get('/_forge/manager', ['REMOTE_ADDR' => '127.0.0.1'])->assertStatus(403);
        $this->get('/_forge/manager', ['REMOTE_ADDR' => '::1'])->assertStatus(403);
        $this->get('/_forge/manager', ['REMOTE_ADDR' => '203.0.113.7'])->assertStatus(403);
    }

    public function test_single_string_wildcard_allows_any_ip(): void
    {
        config()->set('forge.manager_allowed_ips', '*');

        $this->get('/_forge/manager', ['REMOTE_ADDR' => '203.0.113.7'])->assertStatus(200);
    }

    public function test_string_spelling_is_equivalent_to_single_element_array(): void
    {
        // 两种写法逐来源一致：归一化不得改变语义（同一键在 common 保存路径与本包运行期
        // 必须同口径，否则「在管理器点一次保存」会让行为当场翻转）。
        $sources = ['192.168.1.10', '192.168.1.99', '127.0.0.1', '::1', '203.0.113.7'];

        $withArray = [];
        $withString = [];
        foreach ($sources as $ip) {
            config()->set('forge.manager_allowed_ips', ['192.168.1.10']);
            $withArray[$ip] = $this->get('/_forge/manager', ['REMOTE_ADDR' => $ip])->getStatusCode();

            config()->set('forge.manager_allowed_ips', '192.168.1.10');
            $withString[$ip] = $this->get('/_forge/manager', ['REMOTE_ADDR' => $ip])->getStatusCode();
        }

        self::assertSame($withArray, $withString, '单值字符串写法与单元素数组写法的放行结果必须逐来源相同');
    }

    public function test_missing_key_falls_back_to_loopback_default(): void
    {
        // 键被删除（如沿用旧版本发布的 config/forge.php）时，按 SPEC §5 的默认值处理：
        // 仅本机回环可用。此前该情形等价于「不限制」，方向危险。
        $forge = config()->get('forge', []);
        unset($forge['manager_allowed_ips']);
        config()->set('forge', $forge);

        $this->get('/_forge/manager', ['REMOTE_ADDR' => '127.0.0.1'])->assertStatus(200);
        $this->get('/_forge/manager', ['REMOTE_ADDR' => '::1'])->assertStatus(200);
        $this->get('/_forge/manager', ['REMOTE_ADDR' => '203.0.113.7'])->assertStatus(403);
    }

    public function test_empty_or_null_list_disables_ip_check(): void
    {
        // 空数组 / null = 开发者显式放开 IP 限制（文档已警示暴露风险）。
        // 注意与「键缺失」区分：显式 null 仍是放开，缺键走默认值。
        config()->set('forge.manager_allowed_ips', []);
        $this->get('/_forge/manager', ['REMOTE_ADDR' => '203.0.113.7'])->assertStatus(200);

        config()->set('forge.manager_allowed_ips', null);
        $this->get('/_forge/manager', ['REMOTE_ADDR' => '203.0.113.7'])->assertStatus(200);
    }

    public function test_default_request_without_remote_addr_header_still_passes(): void
    {
        // 不带 REMOTE_ADDR 的测试请求默认来源即 127.0.0.1，
        // 默认配置下应当放行（同时保证既有管理器用例不受影响）
        $this->get('/_forge/manager')->assertStatus(200);
    }
}
