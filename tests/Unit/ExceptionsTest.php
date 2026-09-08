<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RouteForge\Common\Exception\AliasTargetException;
use RouteForge\Common\Exception\CacheDriverException;
use RouteForge\Common\Exception\ClassifierException;
use RouteForge\Common\Exception\DiscardedRegistrarAttributesException;
use RouteForge\Common\Exception\RouteMissingNameException;
use RouteForge\Common\Exception\RouteTierNotAssignedException;
use RouteForge\Common\Exception\UnknownClassifierTierException;
use RouteForge\Common\Exception\UnknownLevelException;

/**
 * 异常契约冒烟测试（仅校验 code/httpStatus，未跑完整 Laravel 栈）。
 * 覆盖 SPEC §6 错误码表全部 8 个异常。
 */
class ExceptionsTest extends TestCase
{
    public function test_exception_codes(): void
    {
        $this->assertSame('RF_BE_001', (new RouteTierNotAssignedException())->code());
        $this->assertSame('RF_BE_002', (new UnknownLevelException())->code());
        $this->assertSame('RF_BE_003', (new CacheDriverException())->code());
        $this->assertSame('RF_BE_004', (new ClassifierException())->code());
        $this->assertSame('RF_BE_005', (new RouteMissingNameException())->code());
        $this->assertSame('RF_BE_006', (new UnknownClassifierTierException())->code());
        $this->assertSame('RF_BE_007', (new DiscardedRegistrarAttributesException())->code());
        $this->assertSame('RF_BE_008', (new AliasTargetException())->code());
    }

    public function test_http_statuses(): void
    {
        $this->assertSame(500, (new RouteTierNotAssignedException())->httpStatus());
        $this->assertSame(404, (new UnknownLevelException())->httpStatus());
        $this->assertSame(500, (new CacheDriverException())->httpStatus());
        $this->assertSame(500, (new ClassifierException())->httpStatus());
        $this->assertSame(500, (new RouteMissingNameException())->httpStatus());
        $this->assertSame(500, (new UnknownClassifierTierException())->httpStatus());
        $this->assertSame(500, (new DiscardedRegistrarAttributesException())->httpStatus());
        $this->assertSame(500, (new AliasTargetException())->httpStatus());
    }
}
