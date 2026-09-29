<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use Verstamp\Stamper;

/**
 * 既有用例：覆盖戳文本格式、排序、写入与跳过点条目等常规路径。
 */
final class CaseFailure extends \Exception
{
    public function __construct(
        public readonly string $expected,
        public readonly string $actual
    ) {
        parent::__construct('assertion failed');
    }
}

function show(mixed $value): string
{
    $text = is_string($value) ? $value : var_export($value, true);

    return str_replace(["\n", "\r", "\t"], ['\n', '\r', '\t'], $text);
}

function expectSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new CaseFailure(show($expected), show($actual));
    }
}

function expectTrue(bool $ok, string $expected, string $actual): void
{
    if (!$ok) {
        throw new CaseFailure($expected, $actual);
    }
}

function scratchRoot(string $name): string
{
    $root = sys_get_temp_dir() . '/gsb158-tests-' . getmypid() . '/' . $name;
    dropTree($root);
    mkdir($root, 0777, true);

    return $root;
}

function putFile(string $root, string $relative, string $content): void
{
    $path = $root . '/' . $relative;
    $parent = dirname($path);
    if (!is_dir($parent)) {
        mkdir($parent, 0777, true);
    }
    file_put_contents($path, $content);
}

function dropTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }

    rmdir($path);
}

function paths(array $entries): array
{
    return array_map(static fn (array $entry): string => (string) ($entry['path'] ?? '?'), $entries);
}

function expectedStamp(string $version, array $entries): string
{
    $text = 'version=' . $version . "\n";
    foreach ($entries as $entry) {
        $text .= $entry['sha256'] . '  ' . $entry['path'] . "\n";
    }

    return $text;
}

$cases = [];

$cases[] = [
    '戳首行是 version= 加版本号',
    static function (): void {
        $root = scratchRoot('version-line');
        putFile($root, 'a.txt', "alpha\n");

        $stamp = (new Stamper())->stamp($root, '1.2.3', true)['stamp'];
        $lines = explode("\n", $stamp);

        expectSame('version=1.2.3', $lines[0] ?? '');
    },
];

$cases[] = [
    '每条目行是 <sha256> 两个空格 <path>',
    static function (): void {
        $root = scratchRoot('entry-line');
        putFile($root, 'a.txt', "alpha\n");

        $stamp = (new Stamper())->stamp($root, '1.2.3', true)['stamp'];
        $lines = explode("\n", $stamp);

        expectSame(hash('sha256', "alpha\n") . '  a.txt', $lines[1] ?? '');
    },
];

$cases[] = [
    '条目按 path 字节序升序',
    static function (): void {
        $root = scratchRoot('order');
        putFile($root, 'b.txt', "b\n");
        putFile($root, 'a.txt', "a\n");
        putFile($root, 'Z.txt', "z\n");

        $entries = (new Stamper())->stamp($root, '1.0.0', true)['entries'];

        expectSame(['Z.txt', 'a.txt', 'b.txt'], paths($entries));
    },
];

$cases[] = [
    '子目录里的文件用相对 POSIX 路径',
    static function (): void {
        $root = scratchRoot('nested');
        putFile($root, 'sub/deep.txt', "deep\n");

        $entries = (new Stamper())->stamp($root, '1.0.0', true)['entries'];

        expectSame(['sub/deep.txt'], paths($entries));
    },
];

$cases[] = [
    'dry-run 不写文件',
    static function (): void {
        $root = scratchRoot('dry-run');
        putFile($root, 'a.txt', "alpha\n");

        (new Stamper())->stamp($root, '1.0.0', true);

        expectTrue(!is_file($root . '/VERSION.stamp'), '不生成 VERSION.stamp', '文件已生成');
    },
];

$cases[] = [
    '非 dry-run 把戳写进 VERSION.stamp',
    static function (): void {
        $root = scratchRoot('write');
        putFile($root, 'a.txt', "alpha\n");

        $result = (new Stamper())->stamp($root, '1.0.0');

        expectSame($result['stamp'], (string) file_get_contents($root . '/VERSION.stamp'));
    },
];

$cases[] = [
    '重复运行戳文件内容不变（不叠加）',
    static function (): void {
        $root = scratchRoot('repeat');
        putFile($root, 'a.txt', "alpha\n");

        $stamper = new Stamper();
        $stamper->stamp($root, '1.0.0');
        $first = (string) file_get_contents($root . '/VERSION.stamp');
        $stamper->stamp($root, '1.0.0');
        $second = (string) file_get_contents($root . '/VERSION.stamp');

        expectSame($first, $second);
    },
];

$cases[] = [
    '旧戳内容被完全替换',
    static function (): void {
        $root = scratchRoot('stale');
        putFile($root, 'a.txt', "alpha\n");
        file_put_contents($root . '/VERSION.stamp', "OLD GARBAGE\nversion=0\n");

        $result = (new Stamper())->stamp($root, '2.0.0');

        $content = (string) file_get_contents($root . '/VERSION.stamp');
        expectSame($result['stamp'], $content);
        expectTrue(!str_contains($content, 'GARBAGE'), '旧内容已消失', '仍含 GARBAGE');
    },
];

$cases[] = [
    '内容变化导致哈希变化',
    static function (): void {
        $root = scratchRoot('content-change');
        putFile($root, 'a.txt', "one\n");

        $before = (new Stamper())->stamp($root, '1.0.0', true)['stamp'];
        putFile($root, 'a.txt', "two\n");
        $after = (new Stamper())->stamp($root, '1.0.0', true)['stamp'];

        expectTrue($before !== $after, '戳发生变化', '戳未变化');
    },
];

$cases[] = [
    '只看内容：mtime 变化不影响戳',
    static function (): void {
        $root = scratchRoot('mtime');
        putFile($root, 'a.txt', "alpha\n");

        $before = (new Stamper())->stamp($root, '1.0.0', true)['stamp'];
        touch($root . '/a.txt', 1234567890);
        $after = (new Stamper())->stamp($root, '1.0.0', true)['stamp'];

        expectSame($before, $after);
    },
];

$cases[] = [
    '点开头的文件与目录被跳过、空目录只有版本行',
    static function (): void {
        $root = scratchRoot('dots');
        putFile($root, '.env', "SECRET=1\n");
        putFile($root, '.git/config', "[core]\n");
        putFile($root, 'keep.txt', "keep\n");

        $entries = (new Stamper())->stamp($root, '1.0.0', true)['entries'];
        expectSame(['keep.txt'], paths($entries));

        $empty = scratchRoot('empty');
        expectSame("version=9.9.9\n", (new Stamper())->stamp($empty, '9.9.9', true)['stamp']);
    },
];

$passed = 0;
$total = count($cases);

foreach ($cases as $index => $case) {
    $number = str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
    $name = $case[0];

    try {
        $case[1]();
        $passed++;
        echo "PASS  $number $name\n";
    } catch (\Throwable $error) {
        $expected = $error instanceof CaseFailure ? $error->expected : '(无异常)';
        $actual = $error instanceof CaseFailure ? $error->actual : $error->getMessage();
        echo "FAIL  $number $name  期望=$expected 实际=$actual\n";
    }
}

dropTree(sys_get_temp_dir() . '/gsb158-tests-' . getmypid());

echo "通过 $passed/$total\n";

exit($passed === $total ? 0 : 1);