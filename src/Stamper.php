<?php

declare(strict_types=1);

namespace Verstamp;

/**
 * 版本戳注入器：扫描 $root 下（跳过以 . 开头的条目以及 $root 顶层的戳文件自身）
 * 每个普通文件的内容字节，计算其 SHA-256，连同版本号写成一份逐字节确定的清单。
 *
 * 全部输出只依赖「文件内容 + 相对路径 + version」，不含时间 / 随机数 / 环境变量 /
 * 遍历顺序等不确定因素；条目按 path 字节序（strcmp）排序，行尾恒为 \n。
 *
 * 写入采用「临时文件 + rename 原子替换」：所有校验与哈希计算都在写入前完成，
 * 任何失败都抛出 StampException，且不会改动已有的戳文件、不会残留临时文件。
 */
final class Stamper
{
    /** 戳文件的固定文件名（位于 $root 顶层）。 */
    public const STAMP_NAME = 'VERSION.stamp';

    /** 写入时使用的临时文件名（固定名，写完即 rename 掉，失败即清理）。 */
    private const TMP_NAME = '.VERSION.stamp.tmp';

    /**
     * 计算版本戳；`$dryRun` 为 false 时把它原子写入 `$root` 下的 `VERSION.stamp`。
     *
     * @return array{entries: list<array{path: string, sha256: string}>, stamp: string}
     *
     * @throws StampException root 非法或扫描、读取、写入的任一环节失败
     */
    public function stamp(string $root, string $version, bool $dryRun = false): array
    {
        $root = $this->resolveRoot($root);

        // 先完成全部遍历、读取、哈希与排序、渲染，之后才允许触碰磁盘上的戳文件。
        $entries = $this->collect($root);

        // 第 5 条：按 path 字节序（strcmp）升序；不用 strcoll / 不受 setlocale 影响。
        usort($entries, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        // 第 1/4/6 条：戳文本逐字节固定，只含 version 与内容哈希。
        $stamp = $this->render($version, $entries);

        // 第 2 条：非 dry-run 时用原子替换完全覆盖旧戳；dry-run 不写任何文件。
        if (!$dryRun) {
            $this->writeAtomically($root, $stamp);
        }

        return ['entries' => $entries, 'stamp' => $stamp];
    }

    /**
     * 校验并规范化 root：必须存在且是目录，否则 fail-closed 抛 StampException。
     *
     * @throws StampException
     */
    private function resolveRoot(string $root): string
    {
        if ($root === '') {
            throw new StampException('root 不能为空');
        }

        clearstatcache(true, $root);

        if (!is_dir($root)) {
            throw new StampException('root 不存在或不是目录: ' . $root);
        }

        // 去掉结尾分隔符，保证相对路径切片位置固定（跨调用逐字节一致）。
        return rtrim($root, '/' . DIRECTORY_SEPARATOR);
    }

    /**
     * 递归收集所有需纳入戳的条目（跳过点开头条目与顶层戳文件）。
     *
     * @return list<array{path: string, sha256: string}>
     *
     * @throws StampException
     */
    private function collect(string $root): array
    {
        $entries = [];
        $this->walk($root . '/', $root, $entries);

        return $entries;
    }

    /**
     * @param list<array{path: string, sha256: string}> $entries
     *
     * @throws StampException
     */
    private function walk(string $absDir, string $root, array &$entries): void
    {
        $handle = @opendir($absDir);
        if ($handle === false) {
            throw new StampException('无法打开目录: ' . $absDir);
        }

        try {
            while (($name = readdir($handle)) !== false) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                // 第 7 条：以 . 开头的文件与目录（.git / .env / .hidden …）一律跳过。
                if ($name[0] === '.') {
                    continue;
                }

                $abs = $absDir . $name;
                $relative = $this->relativePath($root, $abs);

                // 第 2 条：$root 顶层的戳文件自身不得算进条目（子目录里的同名文件是普通条目）。
                if ($relative === self::STAMP_NAME) {
                    continue;
                }

                if (is_dir($abs)) {
                    $this->walk($abs . '/', $root, $entries);
                    continue;
                }

                if (!is_file($abs)) {
                    throw new StampException('不支持的条目类型（非普通文件/目录）: ' . $relative);
                }

                // 第 3 条：内容字节的 SHA-256（小写十六进制），不含任何元数据。
                // 流式哈希避免把大文件整体读进内存；读取失败 fail-closed。
                $hash = @hash_file('sha256', $abs);
                if ($hash === false) {
                    throw new StampException('无法读取文件: ' . $relative);
                }

                $entries[] = [
                    'path' => $relative,
                    'sha256' => $hash,
                ];
            }
        } finally {
            closedir($handle);
        }
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
     * 原子写入戳文件：先写到同目录固定临时文件，flush（best-effort fsync）后 rename
     * 覆盖目标。任何一步失败都清理临时文件并抛出，旧戳保持原样、不留半成品。
     *
     * @throws StampException
     */
    private function writeAtomically(string $root, string $stamp): void
    {
        $target = $root . '/' . self::STAMP_NAME;
        $tmp = $root . '/' . self::TMP_NAME;

        $stream = @fopen($tmp, 'wb');
        if ($stream === false) {
            throw new StampException('无法创建临时戳文件: ' . self::TMP_NAME);
        }

        $ok = false;

        try {
            $length = strlen($stamp);
            $written = 0;

            while ($written < $length) {
                $chunk = @fwrite($stream, substr($stamp, $written));
                if ($chunk === false || $chunk === 0) {
                    throw new StampException('写入临时戳文件失败: ' . self::TMP_NAME);
                }
                $written += $chunk;
            }

            if (!@fflush($stream)) {
                throw new StampException('刷新临时戳文件失败: ' . self::TMP_NAME);
            }

            if (function_exists('fsync')) {
                @fsync($stream);
            }

            if (!@fclose($stream)) {
                throw new StampException('关闭临时戳文件失败: ' . self::TMP_NAME);
            }

            $stream = null;

            // rename 在 POSIX 上是原子替换；Windows 上 PHP 会先处理已存在的目标。
            if (!@rename($tmp, $target)) {
                throw new StampException('无法替换戳文件: ' . self::STAMP_NAME);
            }

            $ok = true;
        } finally {
            if (is_resource($stream)) {
                @fclose($stream);
            }

            if (!$ok && is_file($tmp)) {
                @unlink($tmp);
            }
        }
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
