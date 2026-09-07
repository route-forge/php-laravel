<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Exceptions;

use RuntimeException;

/**
 * RF_BE_007：ForgeRouteRegistrar 持有未消费属性即被销毁。
 *
 * 典型成因：`Route::group(...)->tier('x')` —— group() 返回时组已注册完毕、
 * 组属性已出栈，尾部链式属性挂在新 Registrar 上无任何消费方。
 *
 * ⚠ 自 v1.4.x 起不再由 __destruct 自动抛出（PHP 析构中抛异常在栈展开场景会
 * fatal 且无法被 catch），改为日志告警：strict_mode=true 记 error、默认记 warning。
 * 本异常类与错误码 RF_BE_007 保留，供外部代码显式 catch/使用做兼容。
 *
 * @see .docs/SPEC.md §6.1
 */
class DiscardedRegistrarAttributesException extends RuntimeException implements ForgeExceptionContract
{
    public function code(): string
    {
        return 'RF_BE_007';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
