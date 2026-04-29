# Zenplate

A small, fast PHP template engine — meant for things like emails, simple
config files, and one-shot text rendering. Templates compile to plain PHP,
which is then evaluated with the assigned variables in scope.

- Tiny surface area (two classes: `Compiler`, `Runner`)
- No dependencies beyond PHP 8.1+
- Variable substitution with deep dot/bracket access
- `if` / `elseif` / `else` / `/if` blocks
- A small whitelist of safe functions inside `if` (`strlen`, `strtoupper`, `strtolower`)
- Custom delimiters
- Compile-once / run-many for cached templates

## Install

```bash
composer require maurice2k/zenplate
```

Requires PHP `^8.1`.

## Quick start

```php
use Maurice\Zenplate\Runner;

$runner = new Runner();
$runner->assign('name', 'Alice');

echo $runner->run('Hello {%name}!');
// => Hello Alice!
```

Assign many variables at once with an associative array:

```php
$runner->assign([
    'subject' => 'Welcome',
    'user'    => ['name' => 'Alice', 'role' => 'admin'],
]);
```

## Variables

Variables start with `%`. They're wrapped in the configured delimiters
(default `{` / `}`).

### Simple

```php
$runner->assign('name', 'world');
$runner->run('Hello {%name}!');
// => Hello world!
```

### Dot access (nested arrays / objects)

```php
$runner->assign('user', ['name' => 'Bob', 'role' => 'admin']);
$runner->run('{%user.name} ({%user.role})');
// => Bob (admin)
```

Dot access nests arbitrarily deep — missing keys at any level render as
the empty string, no warnings:

```php
$runner->assign('cfg', ['feature' => ['enabled' => true]]);
$runner->run('{%cfg.feature.enabled}');         // => 1
$runner->run('{%cfg.feature.does.not.exist}');  // => (empty)
```

### Bracket access

```php
$runner->assign('items', ['first', 'second', 'third']);
$runner->run('{%items[1]}');               // => second

$runner->assign('data', ['foo' => 'bar']);
$runner->run('{%data["foo"]}');            // => bar
```

### Mixed dot + bracket

```php
$runner->assign('users', [
    'admins' => [
        ['name' => 'Alice'],
        ['name' => 'Bob'],
    ],
]);
$runner->run('{%users.admins[1].name}');   // => Bob
```

### Empty / falsy values

Variables are rendered through PHP's `empty()`. That means **all of these
render as the empty string**:

| Value         | Output |
|---------------|--------|
| `null`        | `''`   |
| `''`          | `''`   |
| `0` (int)     | `''`   |
| `'0'` (str)   | `''`   |
| `false`       | `''`   |
| `[]`          | `''`   |

This is intentional historical behavior — handy for templates that print
a default for "missing-ish" values, surprising if you actually wanted to
print `0`. If you need `0` to render, pass `'0 '` or convert upstream.

## Conditionals

```php
$runner->assign('status', 'active');

$runner->run('{if %status == "active"}on{else}off{/if}');
// => on
```

`elseif` chains and `else` clauses work as expected:

```php
$tpl = '{if %n == 1}one{elseif %n == 2}two{elseif %n == 3}three{else}other{/if}';
$runner->assign('n', 2);
$runner->run($tpl); // => two
```

`else` is optional:

```php
$runner->run('{if %show}visible{/if}');
```

### Operators

Allowed inside `{if ...}`:

- Comparison: `==`, `===`, `!=`, `!==`, `<>`, `<`, `>`, `<=`, `>=`
- Logical: `&&`, `||`, unary `!`
- Arithmetic / bitwise: `+`, `-`, `*`, `/`, `%`, `&`, `|`, `^`, `<<`, `>>`
- Grouping: `( ... )`

```php
$runner->assign('a', true);
$runner->assign('b', false);
$runner->run('{if (%a && !%b) || %b}hit{else}miss{/if}'); // => hit
```

A single `=` in a comparison context is treated as `==` but reported as
an error (so the template still compiles to something sensible while the
typo is surfaced via `Compiler::getErrors()`).

### Functions inside `if`

Only an explicit whitelist is callable from inside an `if` condition:

- `strlen`
- `strtoupper`
- `strtolower`

```php
$runner->assign('s', 'hello');
$runner->run('{if strlen(%s) > 3}long{else}short{/if}'); // => long
```

Extend the list per Compiler instance:

```php
use Maurice\Zenplate\Compiler;

$c = new Compiler();
$c->supportedIfFuncs[] = 'count';
```

Note: `Runner::run()` constructs its own internal `Compiler`, so to use
custom functions or delimiters with the high-level API you'll currently
need to compile separately and use `Runner::runCompiled()` (see below).

## Custom delimiters

```php
use Maurice\Zenplate\Compiler;

$c = new Compiler();
$c->leftDelimiter  = '<<';
$c->rightDelimiter = '>>';

$compiled = $c->compile('Hello <<%name>>!');
```

## Compile once, run many

Compilation is regex-driven and not free; if you render the same template
repeatedly, compile it once and feed the cached PHP into `runCompiled()`:

```php
use Maurice\Zenplate\Compiler;
use Maurice\Zenplate\Runner;

$compiled = (new Compiler())->compile('Hello {%name}!');
file_put_contents('/var/cache/greeting.php', $compiled);

// Later, possibly in another request:
$runner = new Runner();
$runner->assign('name', 'Alice');
echo $runner->runCompiled(file_get_contents('/var/cache/greeting.php'));
```

`runCompiled()` rejects input that doesn't carry the Zenplate header so a
random PHP file can't be smuggled through.

## Error handling

`Runner::run()` throws `ExecuteException` on either compile errors or
runtime PHP errors:

```php
use Maurice\Zenplate\Exception\ExecuteException;

try {
    $runner->run('{if %x == }nope{/if}');
} catch (ExecuteException $e) {
    // "Error compiling template; error messages: ..."
}
```

For finer-grained inspection, drive the compiler directly:

```php
$c = new Compiler();
if ($c->compile($tpl) === false) {
    foreach ($c->getErrors() as $err) {
        // $err = ['type' => int, 'offset' => int, 'additional' => string, 'message' => string]
    }
}
```

`Compiler::ERROR_*` constants identify each error kind (`ERROR_IF_NO_ENDIF`,
`ERROR_IF_SINGLE_EQUAL_SIGN`, etc.).

## Safety notes

The compiler emits PHP source that gets `eval()`ed by `Runner::run()`,
so injection prevention is critical. Current defenses:

- **Literal `<?` escaping** — `<?xml`, `<?php`, `<?=` and lone `<?`
  sequences are wrapped so they pass through as inert text.
- **String literals are normalized** — quoted strings inside bracket
  subscripts (`{%foo["key"]}`) and if-conditions (`{if "x" == "y"}`)
  are decoded and re-emitted as **single-quoted** PHP literals. This
  blocks PHP's `${expr}` and `{$expr}` interpolation syntax, which
  would otherwise allow arbitrary expression evaluation including
  function calls.
- **Variable / selector names** are constrained to `[a-zA-Z0-9_]+` —
  no path for injection via identifiers.
- **`if` function whitelist** — only names in `$supportedIfFuncs`
  (default `strlen`, `strtoupper`, `strtolower`) can appear in
  conditions; anything else is a compile error.
- **`runCompiled()` header check** — rejects PHP files that don't
  carry the Zenplate header so a random PHP blob can't be smuggled in.

The injection probes in `tests/SecurityProbeTest.php` exercise the
known attack surface.

> **Note on string literals:** double-quoted strings used as array keys
> or in if-conditions are decoded with C-style escape rules (`\n` →
> newline, `\\` → `\`, `\"` → `"`, etc.) at compile time, then re-emitted
> safely. Your template's `{%foo["a\"b"]}` still resolves to the array
> key `a"b`. Single-quoted strings retain PHP's stricter escape rules
> (only `\\` and `\'` are special).

Even with these defenses in place, treat compiled templates as code:
**don't compile attacker-controlled template source** unless you've
threat-modelled what they could read from the eval scope (`$this->vars`,
in particular).

## Limitations

- No modifier syntax yet (`{%foo|upper}`, `{%foo|default:"x"}`). The
  integration points are noted in `Compiler::__construct`. PRs welcome.
- No `foreach` / loop blocks.
- `Runner::run()` always builds its own `Compiler`, so custom delimiters
  or extended `supportedIfFuncs` need the compile-once path.

## Testing

Test infrastructure (Dockerfile, compose file, phpunit config, fixtures,
test cases) lives under `tests/`. Everything runs in Docker so there's
nothing to install on the host. A `Makefile` at the repo root wraps the
common commands:

```bash
make test                  # full suite on PHP 8.1
make test PHP=8.3          # full suite on a specific PHP version
make test-all              # PHP 8.1, 8.2, and 8.3 in turn
make test-security         # only the eval-injection probes
make test-dox              # readable testdox reporter
make shell                 # drop into a shell in the container
make clean                 # remove built images + phpunit cache
make help                  # list all targets
```

If you'd rather not use Make, the equivalent docker compose invocation is:

```bash
docker compose -f tests/docker-compose.yml run --rm test
```

CI runs the same image across PHP 8.1 / 8.2 / 8.3 / 8.4 / 8.5 on every
push and PR (see `.github/workflows/ci.yml`).

## License

LGPL-3.0. See `lgpl-3.0.txt`.
