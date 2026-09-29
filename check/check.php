<?php

declare(strict_types=1);

/**
 * verstamp 固定验收程序（固定件）。**别改这个文件。**
 *
 * 用法：
 *   php check/check.php                   跑全部场景
 *   php check/check.php -list             列出全部场景
 *   php check/check.php --only stable     只跑某一组（stable/replace/hash/order/meta，可逗号分隔）
 *
 * 五组共 8 个场景：
 *   stable  2 —— 同一输入目录两次计算逐字节一致；输出与 LC_ALL / TZ 无关；
 *   replace 2 —— 重复运行不叠加（戳文件自身不算条目）；旧戳被完全替换；
 *   hash    2 —— 内容变则哈希变且等于内容 SHA-256；mtime 变化不影响戳；
 *   order   1 —— 条目按 path 的字节序（strcmp）升序；
 *   meta    1 —— 戳文本格式固定、点开头条目跳过、空目录只有版本行、非法 root 抛 StampException。
 *
 * 判据全程确定性：只用固定输入、固定版本号，比对字节与哈希，
 * 不依赖墙钟 / 机器速度 / 哈希遍历顺序 / 运行环境。
 */

const ROOT = __DIR__ . '/..';

require ROOT . '/autoload.php';

// ---------------------------------------------------------------------------
// 断言与工具
// ---------------------------------------------------------------------------

final class CheckFailure extends \Exception
{
    public function __construct(
        public readonly string $expected = '-',
        public readonly string $actual = '-',
        string $message = 'assertion failed'
    ) {
        parent::__construct($message);
    }
}

/** 新建一个干净的临时目录，返回其绝对路径。 */
function scratch(string $name): string
{
    static $base = null;

    if ($base === null) {
        $base = sys_get_temp_dir() . '/gsb158-check-' . getmypid();
        dropTree($base);
        if (!mkdir($base, 0777, true) && !is_dir($base)) {
            throw new \RuntimeException('无法创建临时目录 ' . $base);
        }
    }

    $root = $base . '/' . $name;
    dropTree($root);
    if (!mkdir($root, 0777, true) && !is_dir($root)) {
        throw new \RuntimeException('无法创建临时目录 ' . $root);
    }

    return $root;
}

function put(string $root, string $relative, string $content): void
{
    $path = $root . '/' . $relative;
    $parent = dirname($path);
    if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
        throw new \RuntimeException('无法创建目录 ' . $parent);
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

/** @return list<string> */
function paths(array $entries): array
{
    return array_map(static fn (array $entry): string => (string) ($entry['path'] ?? '?'), $entries);
}

function shaOf(array $entries, string $path): ?string
{
    foreach ($entries as $entry) {
        if (($entry['path'] ?? null) === $path) {
            return (string) ($entry['sha256'] ?? '');
        }
    }

    return null;
}

function stampOf(string $root, string $version, bool $dryRun = true): string
{
    return (new \Verstamp\Stamper())->stamp($root, $version, $dryRun)['stamp'];
}

function expectSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new CheckFailure(show($expected), show($actual));
    }
}

function show(mixed $value): string
{
    $text = is_string($value) ? $value : var_export($value, true);

    return str_replace(["\n", "\r", "\t"], ['\n', '\r', '\t'], $text);
}

// ---------------------------------------------------------------------------
// 场景
// ---------------------------------------------------------------------------

$scenarios = [];

// ---------------- stable 2 ----------------

$scenarios[] = [
    'group' => 'stable',
    'name' => 'stable/byte-identical',
    'expect' => '同一输入目录 + 同一 version，两次计算（不同实例）得到逐字节一致的戳与条目',
    'fn' => static function (): void {
        $root = scratch('stable-identical');
        put($root, 'a.txt', "alpha\n");
        put($root, 'B.php', "beta\n");
        put($root, 'sub/deep.txt', "deep\n");

        $first = (new \Verstamp\Stamper())->stamp($root, '1.2.3', true);
        $second = (new \Verstamp\Stamper())->stamp($root, '1.2.3', true);

        expectSame($first['stamp'], $second['stamp']);
        expectSame($first['entries'], $second['entries']);
    },
];

$scenarios[] = [
    'group' => 'stable',
    'name' => 'stable/locale-tz',
    'expect' => '戳与 LC_ALL / TZ / 默认时区无关（三种环境下逐字节一致）',
    'fn' => static function (): void {
        $root = scratch('stable-env');
        put($root, 'a.txt', "alpha\n");
        put($root, 'B.txt', "beta\n");
        put($root, 'a/b.txt', "nested\n");

        $oldTz = date_default_timezone_get();
        $oldLocale = setlocale(LC_ALL, '0');

        try {
            $baseline = stampOf($root, '1.0.0');

            putenv('LC_ALL=de_DE.UTF-8');
            putenv('TZ=Asia/Tokyo');
            date_default_timezone_set('Asia/Tokyo');
            setlocale(LC_ALL, 'de_DE.UTF-8', 'de_DE', 'German_Germany.1252');
            $de = stampOf($root, '1.0.0');

            putenv('LC_ALL=C');
            putenv('TZ=UTC');
            date_default_timezone_set('UTC');
            setlocale(LC_ALL, 'C');
            $c = stampOf($root, '1.0.0');
        } finally {
            putenv('LC_ALL');
            putenv('TZ');
            date_default_timezone_set($oldTz);
            if (is_string($oldLocale) && $oldLocale !== '') {
                setlocale(LC_ALL, $oldLocale);
            }
        }

        expectSame($baseline, $de);
        expectSame($baseline, $c);
    },
];

// ---------------- replace 2 ----------------

$scenarios[] = [
    'group' => 'replace',
    'name' => 'replace/idempotent',
    'expect' => '重复运行戳文件内容不变、只有一行 version=、且戳文件自身不计入条目',
    'fn' => static function (): void {
        $root = scratch('replace-idempotent');
        put($root, 'a.txt', "alpha\n");
        put($root, 'b.txt', "beta\n");

        $stamper = new \Verstamp\Stamper();

        $r1 = $stamper->stamp($root, '1.0.0');
        $c1 = (string) file_get_contents($root . '/VERSION.stamp');

        $r2 = $stamper->stamp($root, '1.0.0');
        $c2 = (string) file_get_contents($root . '/VERSION.stamp');

        expectSame($r1['stamp'], $c1);
        expectSame($c1, $c2);
        expectSame($r1['stamp'], $r2['stamp']);

        if (substr_count($c2, 'version=') !== 1) {
            throw new CheckFailure('戳文件里恰好一行 version=', substr_count($c2, 'version=') . ' 处');
        }

        expectSame(['a.txt', 'b.txt'], paths($r2['entries']));
    },
];

$scenarios[] = [
    'group' => 'replace',
    'name' => 'replace/stale',
    'expect' => 'root 下预置的旧戳被完全替换（旧内容消失，只剩新戳）',
    'fn' => static function (): void {
        $root = scratch('replace-stale');
        put($root, 'a.txt', "alpha\n");
        file_put_contents($root . '/VERSION.stamp', "OLD GARBAGE LINE\nversion=0.0.0\n");

        $result = (new \Verstamp\Stamper())->stamp($root, '2.0.0');
        $content = (string) file_get_contents($root . '/VERSION.stamp');

        expectSame($result['stamp'], $content);

        if (str_contains($content, 'GARBAGE')) {
            throw new CheckFailure('旧戳内容消失', '仍含 GARBAGE');
        }
        if (substr_count($content, 'version=') !== 1) {
            throw new CheckFailure('戳文件里恰好一行 version=', substr_count($content, 'version=') . ' 处');
        }
        expectSame('version=2.0.0', explode("\n", $content)[0] ?? '');
    },
];

// ---------------- hash 2 ----------------

$scenarios[] = [
    'group' => 'hash',
    'name' => 'hash/content-change',
    'expect' => '条目哈希等于文件内容字节的 SHA-256；内容变化则该条目哈希随之变化',
    'fn' => static function (): void {
        $root = scratch('hash-change');
        put($root, 'a.txt', "one\n");
        put($root, 'b.txt', "two\n");

        $before = (new \Verstamp\Stamper())->stamp($root, '1.0.0', true)['entries'];
        put($root, 'a.txt', "ONE LONGER\n");
        $after = (new \Verstamp\Stamper())->stamp($root, '1.0.0', true)['entries'];

        $a1 = shaOf($before, 'a.txt');
        $a2 = shaOf($after, 'a.txt');

        expectSame(hash('sha256', "one\n"), $a1);
        expectSame(hash('sha256', "ONE LONGER\n"), $a2);

        if ($a1 === $a2) {
            throw new CheckFailure('a.txt 的哈希随内容变化', '未变化');
        }

        expectSame(hash('sha256', "two\n"), shaOf($before, 'b.txt'));
        expectSame(hash('sha256', "two\n"), shaOf($after, 'b.txt'));
    },
];

$scenarios[] = [
    'group' => 'hash',
    'name' => 'hash/mtime-ignored',
    'expect' => '只改 mtime（内容不变）时戳逐字节不变，且哈希仍是内容哈希',
    'fn' => static function (): void {
        $root = scratch('hash-mtime');
        put($root, 'a.txt', "alpha\n");
        put($root, 'b.txt', "beta\n");

        $before = (new \Verstamp\Stamper())->stamp($root, '1.0.0', true);
        $beforeEntries = $before['entries'];

        touch($root . '/a.txt', 1000000000);
        touch($root . '/b.txt', 1600000000);
        clearstatcache();
        $afterOne = (new \Verstamp\Stamper())->stamp($root, '1.0.0', true)['stamp'];

        touch($root . '/a.txt', 1111111111);
        touch($root . '/b.txt', 1222222222);
        clearstatcache();
        $afterTwo = (new \Verstamp\Stamper())->stamp($root, '1.0.0', true)['stamp'];

        expectSame($before['stamp'], $afterOne);
        expectSame($before['stamp'], $afterTwo);

        expectSame(hash('sha256', "alpha\n"), shaOf($beforeEntries, 'a.txt'));
        expectSame(hash('sha256', "beta\n"), shaOf($beforeEntries, 'b.txt'));
    },
];

// ---------------- order 1 ----------------

$scenarios[] = [
    'group' => 'order',
    'name' => 'order/byte-order',
    'expect' => '条目按 path 的字节序（strcmp）升序，标点与数字都按字节比较（不受本地化排序影响）',
    'fn' => static function (): void {
        $root = scratch('order');
        // 互不区分大小写冲突，但字节序与本地化排序（忽略标点）不同，能区分 strcmp 与 strcoll。
        $expected = ['10.php', '2.php', 'a-b.php', 'a.b.php', 'a_b.php', 'ab.php', 'sub/b.php', 'sub2/b.php'];
        foreach ($expected as $relative) {
            put($root, $relative, "x\n");
        }

        $entries = (new \Verstamp\Stamper())->stamp($root, '1.0.0', true)['entries'];

        usort($expected, 'strcmp');

        expectSame($expected, paths($entries));
    },
];

// ---------------- meta 1 ----------------

$scenarios[] = [
    'group' => 'meta',
    'name' => 'meta/format-and-dots',
    'expect' => '戳格式固定（version 行 + 两个空格分隔的条目行、行尾 \\n）、点开头条目跳过、空目录只有版本行、非法 root 抛 StampException',
    'fn' => static function (): void {
        $root = scratch('meta-dots');
        put($root, '.env', "SECRET=1\n");
        put($root, '.git/config', "[core]\n");
        put($root, 'sub/.hidden', "hidden\n");
        put($root, 'keep.txt', "keep\n");
        put($root, 'sub/ok.txt', "ok\n");

        $result = (new \Verstamp\Stamper())->stamp($root, '1.2.3', true);
        expectSame(['keep.txt', 'sub/ok.txt'], paths($result['entries']));

        $stamp = $result['stamp'];
        if (str_contains($stamp, "\r")) {
            throw new CheckFailure('行尾只用 \\n', '出现 \\r');
        }
        expectSame('version=1.2.3', explode("\n", $stamp)[0] ?? '');

        $expectedStamp = "version=1.2.3\n"
            . hash('sha256', "keep\n") . "  keep.txt\n"
            . hash('sha256', "ok\n") . "  sub/ok.txt\n";
        expectSame($expectedStamp, $stamp);

        $empty = scratch('meta-empty');
        expectSame("version=9.9.9\n", stampOf($empty, '9.9.9'));

        $missing = sys_get_temp_dir() . '/gsb158-check-' . getmypid() . '/no-such-root';
        try {
            (new \Verstamp\Stamper())->stamp($missing, '1.0.0', true);
        } catch (\Verstamp\StampException $exc) {
            return;
        } catch (\Throwable $exc) {
            throw new CheckFailure('不存在的 root 抛 StampException', get_class($exc) . ': ' . $exc->getMessage());
        }

        throw new CheckFailure('不存在的 root 抛 StampException', '未抛异常');
    },
];

// ---------------------------------------------------------------------------
// runner
// ---------------------------------------------------------------------------

$argv = $argv ?? [];
$doList = false;
$only = null;

for ($i = 1; $i < count($argv); $i++) {
    $arg = (string) $argv[$i];

    if ($arg === '-list' || $arg === '--list') {
        $doList = true;
    } elseif ($arg === '--only' || $arg === '--group') {
        $i++;
        if ($i >= count($argv)) {
            fwrite(STDERR, "--only 需要一个组名（stable/replace/hash/order/meta）\n");
            exit(2);
        }
        $only = [];
        foreach (explode(',', (string) $argv[$i]) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $only[$part] = true;
            }
        }
    } elseif ($arg === '-h' || $arg === '--help') {
        echo "用法: php check/check.php [-list] [--only <组名>]\n";
        exit(0);
    } else {
        fwrite(STDERR, "未知参数: {$arg}\n");
        exit(2);
    }
}

if ($doList) {
    foreach ($scenarios as $scenario) {
        printf("[%-8s] %s\n", $scenario['group'], $scenario['name']);
    }
    exit(0);
}

$groups = [];
foreach ($scenarios as $scenario) {
    $groups[$scenario['group']] = true;
}
if ($only !== null) {
    foreach (array_keys($only) as $name) {
        if (!isset($groups[$name])) {
            fwrite(STDERR, "未知分组 {$name}（可选：" . implode(' / ', array_keys($groups)) . "）\n");
            exit(2);
        }
    }
}

$passed = 0;
$failed = 0;
$selected = 0;

foreach ($scenarios as $scenario) {
    if ($only !== null && !isset($only[$scenario['group']])) {
        continue;
    }

    $selected++;

    try {
        $scenario['fn']();
    } catch (CheckFailure $exc) {
        $failed++;
        printf(
            "FAIL %s  期望=%s 实际=%s（%s）\n",
            $scenario['name'],
            $exc->expected,
            $exc->actual,
            $exc->getMessage()
        );
        continue;
    } catch (\Throwable $exc) {
        $failed++;
        printf(
            "FAIL %s  期望=%s 实际=%s: %s\n",
            $scenario['name'],
            $scenario['expect'],
            get_class($exc),
            $exc->getMessage()
        );
        continue;
    }

    $passed++;
    printf("PASS %s\n", $scenario['name']);
}

dropTree(sys_get_temp_dir() . '/gsb158-check-' . getmypid());

if ($selected === 0) {
    fwrite(STDERR, "没有匹配的场景\n");
    exit(2);
}

printf("结果：通过 %d/%d\n", $passed, $selected);
exit($failed === 0 ? 0 : 1);