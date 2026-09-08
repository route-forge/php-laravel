<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\App;
use RouteForge\Common\Config\ConfigFileGenerator;
use RouteForge\Common\Repository\RouteRepository;

/**
 * 管理器页面控制器：仅在开发环境（APP_DEBUG=true）下可用。
 *
 * 提供可视化路由管理面板，包括：
 *   - 路由列表与层级总览
 *   - 路由搜索与过滤
 *   - 层级详情查看
 *   - 配置编辑（levels + 全局设置）
 *
 * 配置文件的 PHP 源码生成由 common 层 ConfigFileGenerator 完成（框架无关），
 * 本控制器只负责请求校验与文件写入。
 */
class ForgeManagerController extends Controller
{
    public function __construct(private readonly RouteRepository $repository) {}

    /**
     * 管理器页面（HTML）。
     */
    public function index(): \Illuminate\Contracts\View\View
    {
        $levelsConfig = config('forge.levels', []);
        $tiers        = [];
        foreach ($levelsConfig as $name => $cfg) {
            $tiers[] = [
                'name'        => $name,
                'description' => $cfg['description'] ?? '',
                'load'        => $cfg['load'] ?? 'lazy',
            ];
        }

        return view('forge::manager', [
            'tiers'        => $tiers,
            'levelsConfig' => $levelsConfig,
            'globalConfig' => $this->globalConfig(),
        ]);
    }

    /**
     * API：获取所有路由及层级分配（JSON）。
     */
    public function routes(): JsonResponse
    {
        $data = $this->repository->getAllRoutesWithTiers();

        return new JsonResponse([
            'routes' => $data['routes'],
            'tiers'  => $data['tiers'],
        ]);
    }

    /**
     * API：获取当前配置（JSON）。
     */
    public function config(): JsonResponse
    {
        return new JsonResponse([
            'levels' => config('forge.levels', []),
            'global' => $this->globalConfig(),
        ]);
    }

    /**
     * 管理器页面与 config API 共用的全局配置展示结构。
     *
     * 注意与 updateConfig() 的 preserved 键集合保持一致：
     * aliases 等只读展示项保存时须原样透传，避免静默丢失。
     */
    private function globalConfig(): array
    {
        return [
            'endpoint_prefix'     => (string)config('forge.endpoint_prefix', '/_forge/routes'),
            'url_prefix'          => config('forge.url_prefix'),
            'cache_ttl'           => config('forge.cache_ttl'),
            'cache_driver'        => config('forge.cache_driver'),
            'strict_mode'         => (bool)config('forge.strict_mode', false),
            'scheme_version'      => (int)config('forge.scheme_version', 1),
            'manager_allowed_ips' => (array)config('forge.manager_allowed_ips', ['127.0.0.1', '::1']),
            // 只读展示：别名不在表单中编辑（保存时原样透传），可见便于审计
            'aliases'             => (array)config('forge.aliases', []),
        ];
    }

    /**
     * API：更新配置文件。
     *
     * 接收 levels（JSON 对象）与 global（全局设置对象），
     * 重新生成 config/forge.php 文件（源码生成由 common ConfigFileGenerator 完成）。
     */
    public function updateConfig(Request $request): JsonResponse
    {
        $levels = $request->input('levels');
        $global = $request->input('global');

        if (!is_array($levels)) {
            return new JsonResponse(['error' => 'Invalid levels data'], 422);
        }
        if (!is_array($global)) {
            return new JsonResponse(['error' => 'Invalid global config'], 422);
        }

        // classifier 是闭包等运行时 callable，无法序列化回 PHP 配置文件。
        // 若已配置则拒绝保存（fail-fast），避免重新生成文件时静默抹掉分类回调。
        if (config('forge.classifier') !== null) {
            return new JsonResponse([
                'error' => 'A classifier callback is configured in config/forge.php. '
                    . 'The manager cannot serialize closures into the config file; '
                    . 'please edit config/forge.php manually.',
            ], 422);
        }

        $configPath = App::configPath('forge.php');
        $content    = (new ConfigFileGenerator())->generate($levels, $global, [
            // 不在表单中编辑、保存时原样透传的配置项（避免保存时静默丢失）
            'endpoint_middleware' => config('forge.endpoint_middleware', []),
            'manager_allowed_ips' => config('forge.manager_allowed_ips', ['127.0.0.1', '::1']),
            'aliases'             => config('forge.aliases', []),
        ]);

        if (@file_put_contents($configPath, $content) === false) {
            return new JsonResponse(['error' => 'Failed to write config file'], 500);
        }

        // 若存在编译缓存的配置（php artisan config:cache），删除之，
        // 使下一个请求的 LoadConfiguration 重新从 config/*.php 文件读取，
        // 变更方能真正生效。开发环境（未缓存配置）下此文件不存在，属正常空操作。
        $compiled = App::bootstrapPath('cache/config.php');
        if (is_file($compiled)) {
            @unlink($compiled);
        }

        return new JsonResponse(['success' => true, 'message' => 'Config updated successfully']);
    }
}
