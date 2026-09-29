<?php

declare(strict_types=1);

namespace Verstamp;

/**
 * 版本戳注入器：扫描 $root 下（跳过以 . 开头的条目以及戳文件自身）每个文件的内容字节，
 * 计算其 SHA-256，连同版本号写成一份逐字节确定的清单。
 *
 * 满足 README 的 9 条对外契约（确定性 / 完全替换 / 内容哈希 / 不含不确定因素 /
 * 遍历顺序 / 与环境无关 / 跳过点开头条目 / 空目录可处理 / dry-run），并做到 fail-closed：
 * 非法 root、不可读目录/文件一律抛 StampException；写入前完成全部收集与渲染，
 * 写入走「同目录临时文件 + rename」原子替换，任一步失败都清除临时文件，绝不留半成品。
 */
final class Stamper
{
    /** 戳文件的固定文件名。 */
    public const STAMP_NAME = 'VERSION.stamp';

    /**
     * 原子写入用的临时文件名：固定且以 . 开头（遍历时本就跳过，残留也不会污染条目）。
     */
    private const TMP_NAME = '.' . self::STAMP_NAME . '.tmp';

    /**
     * 计算版本戳；`$dryRun` 为 false 时把它原子写入 `$root` 下的 `VERSION.stamp`。
     *
     * @return array{entries: list<array{path: string, sha256: string}>, stamp: string}
     *
     * @throws StampException root 非法、不可读，或收集/写入任一步失败时抛出
     */
    public function stamp(string $root, string $version, bool $dryRun = false): array
    {
        if ($root === '' || !is_dir($root)) {
            throw new StampException('root 不存在或不是目录');
        }

        $root = $this->normalizeRoot($root);

        // 写入前完成全部校验与收集：任一条目不可读即 fail-closed，此时尚未触碰戳文件。
        $entries = $this->collect($root);

        // 第 5 条：按 path 字节序（strcmp）升序；不用 strcoll / 不受 setlocale 影响。
        usort($entries, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        // 第 1/4/6/8 条：戳文本逐字节固定，只含 version 与内容哈希，行尾恒为 \n。
        $stamp = $this->render($version, $entries);

        // 第 2/9 条：非 dry-run 时原子替换戳文件；dry-run 不写任何文件。
        if (!$dryRun) {
            $this->writeAtomically($root, $stamp);
        }

        return ['entries' => $entries, 'stamp' => $stamp];
    }

    /**
     * 去掉结尾的目录分隔符，保证相对路径推导与临时文件拼接逐调用一致。
     */
    private function normalizeRoot(string $root): string
    {
        $root = rtrim($root, '/' . DIRECTORY_SEPARATOR);

        return $root === '' ? DIRECTORY_SEPARATOR : $root;
    }

    /**
     * 递归收集所有需纳入戳的条目（跳过点开头条目与戳文件自身）。
     *
     * @return list<array{path: string, sha256: string}>
     *
     * @throws StampException 目录不可打开或文件不可读取时抛出
     */
    private function collect(string $root): array
    {
        $entries = [];
        $this->walk($root, $root, $entries);

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
            throw new StampException('无法读取目录: ' . $this->relativePath($root, $absDir));
        }

        try {
            while (($name = readdir($handle)) !== false) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                // 第 7 条：以 . 开头的文件与目录（.git / .env / .hidden …）一律跳过。
                // 临时戳文件也以 . 开头，即使上次进程被强杀残留，也不会进入条目。
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
     * 渲染逐字节固定的戳文本（行尾一律 \n，不随平台变 \r\n）。
     *
     * @param list<array{path: string, sha256: string}> $entries
     */
    private function render(string $version, array $entries): string
    {
        $text = 'version=' . $version . "\n";

        foreach ($entries as $entry) {
            $text .= $entry['sha256'] . '  ' . $entry['path'] . "\n";
        }

        return $text;
    }

    /**
     * 把戳文本写入同目录固定临时文件，再 rename 原子替换正式戳文件。
     *
     * 任何失败都删除临时文件并抛 StampException：不留临时文件、不产生半截戳、
     * 旧戳要么保持原样、要么被完整替换。
     *
     * @throws StampException
     */
    private function writeAtomically(string $root, string $stamp): void
    {
        $tmp = $root . '/' . self::TMP_NAME;
        $target = $root . '/' . self::STAMP_NAME;

        $handle = @fopen($tmp, 'wb');
        if ($handle === false) {
            throw new StampException('无法创建临时戳文件');
        }

        $length = strlen($stamp);
        $written = 0;
        $ok = true;

        while ($written < $length) {
            $chunk = @fwrite($handle, substr($stamp, $written));
            if ($chunk === false || $chunk === 0) {
                $ok = false;
                break;
            }
            $written += $chunk;
        }

        if ($ok) {
            $ok = @fflush($handle);
        }

        if (!@fclose($handle)) {
            $ok = false;
        }

        if (!$ok || $written !== $length) {
            @unlink($tmp);
            throw new StampException('写入临时戳文件失败');
        }

        // rename 同目录内为原子操作，且各平台均覆盖已存在的目标文件。
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            throw new StampException('替换戳文件失败');
        }
    }
}
