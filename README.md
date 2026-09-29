# Verstamp —— 版本戳注入

PHP 8 的版本戳注入工具：扫描一个源码目录，把「版本号 + 每个文件的内容哈希」写成一份
**逐字节确定**的清单文件，供可复现打包使用。
只依赖 PHP 标准库（php-cli），不用 composer、不用第三方依赖、不用 mbstring（按字节处理）。

## 用法

```php
require __DIR__ . '/autoload.php';

use Verstamp\Stamper;

$result = (new Stamper())->stamp('/path/to/src', '1.2.3');   // 写入 /path/to/src/VERSION.stamp
$result['stamp'];     // 戳文本
$result['entries'];   // [['path' => 'a.php', 'sha256' => '…'], …]

(new Stamper())->stamp('/path/to/src', '1.2.3', true);        // dry-run：只算不写
```

## 对外契约

以下 9 条是本题的验收依据。

1. **确定性**：同一输入目录 + 同一 `version`，任意时刻、任意机器上生成的戳**逐字节一致**；
   同一进程内多次调用结果也一致。
2. **完全替换**：`$root` 下若已存在旧戳文件，必须被**完全替换**（覆盖），不得在其后追加、
   不得与旧内容叠加；戳文件自身也不得算进条目里。
3. **内容哈希**：每个条目给出该文件**内容字节**的 SHA-256（小写十六进制），
   **不得**包含 `mtime` / `inode` / `uid` / `gid` / 权限等元数据。
4. **不含不确定因素**：戳文本里**禁止**出现 `time()`、`uniqid()`、随机数、进程号、主机名等
   随时间或环境变化的内容。
5. **遍历顺序**：条目按 `path` 的**字节序升序**（`strcmp`）排列；**不得**用 `strcoll` / `setlocale`
   影响顺序。
6. **与环境无关**：输出与 `LC_ALL` / `TZ` 环境变量无关。
7. **跳过点开头条目**：`$root` 下以 `.` 开头的文件与目录（如 `.git`、`.env`）必须跳过。
8. **空目录可处理**：`$root` 为空（或只有被跳过的条目）时，戳文本只有 `version` 一行。
9. **dry-run**：`$dryRun` 为 true 时**不写任何文件**，且两次 dry-run 的输出完全一致。

## 接口与戳格式

```php
public function stamp(string $root, string $version, bool $dryRun = false): array
```

返回 `['entries' => …, 'stamp' => …]`：

- `entries`：`list<array{path: string, sha256: string}>`，按第 5 条排序。
- `stamp`：戳文本，逐字节规则固定为

  ```
  version=<version>\n
  <sha256>  <path>\n
  <sha256>  <path>\n
  …
  ```

  第一行是 `version=` 加版本号；其后每个条目一行，`sha256` 与 `path` 之间是**两个空格**；
  每行以 `\n` 结尾（**不随平台变 `\r\n`**）。`path` 是相对 `$root` 的 POSIX 路径（分隔符 `/`）。
- `$dryRun` 为 false 时，把 `stamp` 写入 `$root/VERSION.stamp`；为 true 时不写。
- `$root` 不存在或不是目录时抛 `StampException`。

## 目录

- `src/Stamper.php` —— 本次要交付的实现（现在方法体是空实现）
- `src/StampException.php` —— 异常类型
- `autoload.php` —— `spl_autoload_register` 加载 `src/` 下的类
- `tests/run.php` —— 既有回归用例
- `check/check.php` —— 固定验收（**勿改**）
- `scripts/check.sh` —— 固定验收入口

## 自检

```bash
php tests/run.php
bash scripts/check.sh            # = php check/check.php
bash scripts/check.sh -list
bash scripts/check.sh --only stable
```

`check/check.php` 支持 `-list` 与 `--only <组名>`（组名：`stable` / `replace` / `hash` / `order` / `meta`），
逐场景打印 `PASS <组>/<名>` 或 `FAIL <组>/<名>  期望=… 实际=…`，结尾打印 `结果：通过 x/N`，
全过 `exit 0`，否则 `exit 1`，失败不早退。

## 环境

PHP 8（php-cli）。不需要 composer，不使用 mbstring。