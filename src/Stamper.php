<?php

declare(strict_types=1);

namespace Verstamp;

/**
 * 版本戳注入器（朴素起点实现）：扫描 $root 下（跳过以 . 开头的条目以及戳文件自身）
 * 每个文件的内容字节，计算其 SHA-256，连同版本号写成一份逐字节确定的清单。
 *
 * 本起点实现了 README 对外契约的 9 条（确定性 / 完全替换 / 内容哈希 / 不含不确定因素 /
 * 遍历顺序 / 与环境无关 / 跳过点开头条目 / 空目录可处理 / dry-run），让既有用例全绿。
 * 固定验收里「非法 root 抛 StampException」这一道工程化门禁尚未补，因此固定件为部分通过，
 * 待做题时补上。
 */
final class Stamper
{
    /** 戳文件的固定文件名。 */
    public const STAMP_NAME = 'VERSION.stamp';

    /**
     * 计算版本戳；`$dryRun` 为 false 时把它写入 `$root` 下的 `VERSION.stamp`。
     *
     * @return array{entries: list<array{path: string, sha256: string}>, stamp: string}
     */
    public function stamp(string $root, string $version, bool $dryRun = false): array
    {
        // 朴素起点：尚未实现「非法 root 抛 StampException」这一工程化门禁。
        // 这里对非法 root 静默返回空戳（不抛异常），故固定件 meta 场景判为部分失败。
        if (!is_dir($root)) {
            return ['entries' => [], 'stamp' => 'version=' . $version . "\n"];
        }

        $entries = $this->collect($root);

        // 第 5 条：按 path 字节序（strcmp）升序；不用 strcoll / 不受 setlocale 影响。
        usort($entries, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        // 第 1/4/6 条：戳文本逐字节固定，只含 version 与内容哈希。
        $stamp = $this->render($version, $entries);

        // 第 2 条：非 dry-run 时把戳文件完全替换（file_put_contents 为覆盖写）。
        if (!$dryRun) {
            file_put_contents($root . '/' . self::STAMP_NAME, $stamp);
        }

        return ['entries' => $entries, 'stamp' => $stamp];
    }

    /**
     * 递归收集所有需纳入戳的条目（跳过点开头条目与戳文件自身）。
     *
     * @return list<array{path: string, sha256: string}>
     */
    private function collect(string $root): array
    {
        $entries = [];
        $this->walk($root, $root, $entries);

        return $entries;
    }

    /**
     * @param list<array{path: string, sha256: string}> $entries
     */
    private function walk(string $absDir, string $root, array &$entries): void
    {
        $handle = opendir($absDir);
        if ($handle === false) {
            return;
        }

        while (($name = readdir($handle)) !== false) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            // 第 7 条：以 . 开头的文件与目录（.git / .env / .hidden …）一律跳过。
            if ($name[0] === '.') {
                continue;
            }

            // 第 2 条：戳文件自身不得算进条目。
            if ($name === self::STAMP_NAME) {
                continue;
            }

            $abs = $absDir . '/' . $name;
            $relative = $this->relativePath($root, $abs);

            if (is_dir($abs)) {
                $this->walk($abs, $root, $entries);
                continue;
            }

            // 第 3 条：内容字节的 SHA-256（小写十六进制），不含任何元数据。
            $content = file_get_contents($abs);
            if ($content === false) {
                throw new StampException('无法读取文件: ' . $relative);
            }

            $entries[] = [
                'path' => $relative,
                'sha256' => hash('sha256', $content),
            ];
        }

        closedir($handle);
    }

    /**
     * 由绝对路径推导相对 $root 的 POSIX 路径（分隔符统一为 /）。
     */
    private function relativePath(string $root, string $abs): string
    {
        $relative = substr($abs, strlen($root) + 1);

        return str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }

    /**
     * 渲染逐字节固定的戳文本（行尾一律 \n，不随平台变 \r\n）。
     *
     * @param list<array{path: string, sha256: string}> $entries
     */
    private function render(string $version, array $entries): string
    {
        // 第 8 条：空目录（或只有被跳过的条目）时，戳文本只有 version 一行。
        $text = 'version=' . $version . "\n";

        foreach ($entries as $entry) {
            // 条目行：<sha256> 两个空格 <path>，行尾 \n。
            $text .= $entry['sha256'] . '  ' . $entry['path'] . "\n";
        }

        return $text;
    }
}
