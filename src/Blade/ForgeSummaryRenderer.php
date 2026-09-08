<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Blade;

use RouteForge\Common\Repository\RouteRepository;
use RouteForge\Common\Summary\SummaryRenderer as CommonSummaryRenderer;

/**
 * 首页内嵌摘要渲染器（Laravel Blade 适配层）。
 *
 * 渲染逻辑已下沉 common 层 {@see CommonSummaryRenderer}（框架无关），
 * 本类只负责从容器注入的 common RouteRepository 取摘要后委托渲染，
 * 供 @forgeSummary Blade 指令调用。
 *
 * 契约、安全边界与红线见 common 层 SummaryRenderer 的文档注释，
 * 以及 .docs/SPEC.md §3.1.8 / .docs/DESIGN.md §6.3。
 */
final class ForgeSummaryRenderer
{
    /**
     * 前端约定消费的全局 key（勿改，与 @route-forge/core 消费实现对齐）。
     */
    public const GLOBAL_KEY = CommonSummaryRenderer::GLOBAL_KEY;

    public function __construct(
        private readonly RouteRepository $repository,
    ) {
    }

    /**
     * 渲染可直接放进 HTML &lt;head&gt;（早于前端 bundle）的一段 &lt;script&gt;。
     *
     * 返回的是已安全编码的原始 HTML 字符串（内含 JSON.parse('...') 表达式，
     * 其中的 &lt; / &gt; 已被 common JsSafeEncoder 转义），调用方应原样 echo，勿再经 Blade {{ }} 转义。
     */
    public function render(): string
    {
        // 复用摘要端点同一 producer：字段契约 / 缓存 / 包路由排除 / dev 旁路全部继承
        return CommonSummaryRenderer::render($this->repository->getSummary());
    }
}
