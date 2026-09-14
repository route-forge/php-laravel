<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use RouteForge\Common\Contract\ForgeExceptionContract;
use RouteForge\Common\Exception\RouteStrictViolationException;
use RouteForge\Common\Repository\RouteRepository;

/**
 * 元信息查询端点控制器：GET /{endpoint_prefix}/{level} 与 GET /{endpoint_prefix}
 *
 * @see .docs/SPEC.md §3.1.5, §3.1.6, §6.1
 */
class RouteMetadataController extends Controller
{
    public function __construct(private readonly RouteRepository $repository)
    {
    }

    /**
     * 返回指定层级下所有命名路由的元信息。
     *
     * 异常映射（§6.1，均由 ForgeExceptionContract 自带 code/httpStatus 驱动）：
     *   - UnknownLevelException (RF_BE_002)            → 404
     *   - RouteStrictViolationException (RF_BE_009)    → 500（strict_mode 违规聚合，一次报全）
     *   - RouteTierNotAssignedException (RF_BE_001)    → 500（单条语义保留，聚合路径已由 RF_BE_009 承载）
     *   - CacheDriverException (RF_BE_003)             → 500
     *   - ClassifierException (RF_BE_004)              → 500
     *   - AliasTargetException (RF_BE_008)             → 500
     */
    public function show(string $level): JsonResponse
    {
        try {
            $payload = $this->repository->getRoutesByLevel($level);
        } catch (ForgeExceptionContract $e) {
            return $this->errorResponse($e, ['level' => $level]);
        }

        return new JsonResponse($payload);
    }

    /**
     * 摘要端点：GET /{endpoint_prefix}
     *
     * 返回所有层级概览、全局配置、未分配路由列表（SPEC §3.1.6）。
     *
     * 异常映射（§6.1）：
     *   - RouteStrictViolationException (RF_BE_009) → 500
     *   - CacheDriverException (RF_BE_003) → 500
     *   - ClassifierException (RF_BE_004) → 500
     *   - AliasTargetException (RF_BE_008) → 500
     */
    public function index(): JsonResponse
    {
        try {
            $payload = $this->repository->getSummary();
        } catch (ForgeExceptionContract $e) {
            return $this->errorResponse($e);
        }

        return new JsonResponse($payload);
    }

    /**
     * Forge 异常的统一错误体：code / message 取自异常自身，HTTP 状态码取自 httpStatus()。
     *
     * @param array<string,mixed> $context 端点侧补充上下文（如层级名）
     */
    private function errorResponse(ForgeExceptionContract $e, array $context = []): JsonResponse
    {
        $error = array_merge(['code' => $e->code(), 'message' => $e->getMessage()], $context);

        // RF_BE_009 的结构化违规清单仅在开发模式下随错误体下发，供管理器页面/控制台逐行定位。
        // 不默认下发是因为清单本身即「宿主全部越界路由的名字与 URI 目录」，属内部结构信息；
        // 生产环境仍只给 message（既有契约形态），要机器可读的定位数据请开 debug 或跑命令行。
        if ($e instanceof RouteStrictViolationException && (bool) config('app.debug', false)) {
            $error['violations'] = $e->violations();
        }

        return new JsonResponse(['error' => $error], $e->httpStatus());
    }
}
