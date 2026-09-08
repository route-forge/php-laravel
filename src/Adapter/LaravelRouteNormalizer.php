<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Adapter;

use Illuminate\Routing\Route;
use InvalidArgumentException;
use RouteForge\Common\Contract\RouteNormalizerInterface;
use RouteForge\Common\Dto\RouteInfo;

/**
 * Laravel 路由 → 统一 RouteInfo 的适配器。
 *
 * 提取 common 层所有业务逻辑所需的字段（含 action 中的 tier / forge_aliases），
 * 并把原始 Laravel Route 存入 RouteInfo::source，供 classifier 回调
 * （按 Laravel 类型书写）经 Provider 包装后取回使用。
 */
final class LaravelRouteNormalizer implements RouteNormalizerInterface
{
    public function normalize(mixed $route): RouteInfo
    {
        if (!$route instanceof Route) {
            throw new InvalidArgumentException(
                'Expected Illuminate\Routing\Route, got ' . get_debug_type($route),
            );
        }

        $action = $route->getAction();

        // 显式 tier（->tier() 宏 / group tier 透传均写入 action['tier']）
        $tier = $action['tier'] ?? null;
        $tier = is_string($tier) && $tier !== '' ? $tier : null;

        // ->forgeAlias() 宏声明的别名列表
        $aliases = $action['forge_aliases'] ?? [];
        $aliases = is_array($aliases)
            ? array_values(array_filter($aliases, 'is_string'))
            : [];

        return new RouteInfo(
            name: $route->getName(),
            uri: $route->uri(),
            methods: $route->methods(),
            parameters: $route->parameterNames(),
            parameterDefaults: (array) $route->defaults,
            middleware: $route->gatherMiddleware(),
            tier: $tier,
            forgeAliases: $aliases,
            source: $route,
        );
    }
}
