<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Console;

use Symfony\Component\Console\Output\ConsoleOutputInterface;

/**
 * 命令层的「辅助信息写 stderr」回退逻辑，route:forge:list / route:forge:types 共用。
 *
 * 为什么需要它：不带产物的辅助信息（别名警告、严格模式违规清单）在 `> x.d.ts` 这类
 * 重定向场景下绝不能混进 stdout——stdout 就是产物本身。但测试与 Kernel::call 传入的是
 * BufferedOutput，它未实现 ConsoleOutputInterface、拿不到 error 流，此时必须回退到同一
 * 输出，否则辅助信息会凭空消失、测试也无从断言。
 *
 * 这段回退此前只写在 types 命令里；list 命令的严格模式清单需要同样的口径，
 * 而两处各写一份正是「同一事实两处实现、改一处漏一处」的漂移温床，故抽为共享 trait。
 */
trait WritesToErrorOutput
{
    /**
     * 把若干行辅助信息写到 stderr（真实控制台）或回退到命令自身输出（BufferedOutput）。
     *
     * @param list<string> $lines 不含标记的纯文本行
     * @param string       $style Symfony 颜色标记（comment=黄，red=红）
     */
    protected function writeLinesToErrorOutput(array $lines, string $style = 'comment'): void
    {
        if ($lines === []) {
            return;
        }

        // 真实控制台下底层 output 是 ConsoleOutputInterface → 写 stderr；
        // BufferedOutput（测试/Kernel::call）未实现该接口 → 回退同一输出，信息仍可捕获
        $output = $this->output->getOutput();
        $target = $output instanceof ConsoleOutputInterface
            ? $output->getErrorOutput()
            : $output;

        foreach ($lines as $line) {
            $target->writeln("<{$style}>{$line}</{$style}>");
        }
    }
}
