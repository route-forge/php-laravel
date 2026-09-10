<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Tests\Feature;

use Psr\Log\AbstractLogger;

/**
 * 收集 PSR-3 日志的测试替身（不是测试类，phpunit 不会当作用例收集）。
 *
 * 用途：断言 route-forge/common 经注入 logger 发出的 warning。TierResolver 是容器单例、
 * 构造时捕获 logger，因此必须在层级解析器首次解析之前用 $app->instance() 完成替换。
 *
 * log() 的参数刻意不声明类型：psr/log 2.x 接口无参数类型、3.x 有，放宽参数 + 保留 void
 * 返回是唯一同时兼容两者的写法。
 */
final class RecordingTestLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $warnings = [];

    /** @var list<string> */
    public array $others = [];

    public function log($level, $message, array $context = []): void
    {
        $text = (string) $message;

        if ($level === 'warning') {
            $this->warnings[] = $text;

            return;
        }

        $this->others[] = (string) $level . ': ' . $text;
    }
}
