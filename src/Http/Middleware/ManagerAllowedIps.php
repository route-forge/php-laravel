<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 管理器页面 IP 白名单守卫。
 *
 * 仅挂载在管理器路由（/_forge/manager*）上，按配置项
 * `forge.manager_allowed_ips` 限制可访问的来源 IP：
 *
 *   - IP 列表（精确匹配）：仅列表中的来源可访问；
 *     列表元素 '*' 表示放行任意来源；
 *     单个字符串（如 '192.168.1.10'）等价于只含它的数组；
 *   - null 或空数组：不做 IP 限制（开发者显式放开，
 *     局域网环境需注意暴露风险）；
 *   - 键缺失：按 SPEC §5 默认值 ['127.0.0.1', '::1'] 处理（仅本机）；
 *   - 匹配失败 → 403。
 *
 * 默认配置 ['127.0.0.1', '::1'] 仅允许本机回环访问
 * （浏览器访问 localhost 时可能解析为 IPv6 的 ::1，故一并放行）。
 *
 * 生产环境无需依赖本守卫：APP_DEBUG=false 时管理器路由
 * 根本不注册（见 ForgeServiceProvider::registerManagerRoutes）。
 */
class ManagerAllowedIps
{
    public function handle(Request $request, Closure $next): Response
    {
        // 读取处统一 (array) 归一（AGENTS.md 归一化红线）：单值字符串写法与只含它的数组同形，
        // 与 Laravel 自身 ->middleware('auth') 一致。此前的 is_array() 守卫会把单值写法整段静默
        // 丢掉——配置写了、白名单没挂，管理器页面对任意来源开放，属「失保护」的危险方向；
        // 而 common 的 ConfigFileGenerator 保存路径本就按 (array) 归一，两侧口径相反会让
        //「点一次保存后行为当场翻转」。默认值取自 SPEC §5，使同一键的两个读取点
        //（此处与 ForgeManagerController::globalConfig）对「键缺失」不再给出相反答案。
        $allowed = (array) config('forge.manager_allowed_ips', ['127.0.0.1', '::1']);

        // null 或空数组 = 不做 IP 限制（开发者显式放开）
        if ($allowed !== []) {
            $ip  = (string) $request->ip();
            $hit = false;
            foreach ($allowed as $entry) {
                $entry = trim((string) $entry);
                if ($entry === '*' || $entry === $ip) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                abort(403, 'Route Forge manager is not accessible from this IP address.');
            }
        }

        return $next($request);
    }
}
