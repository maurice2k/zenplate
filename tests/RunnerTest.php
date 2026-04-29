<?php

declare(strict_types=1);

namespace Maurice\Zenplate\Tests;

use Maurice\Zenplate\Exception\ExecuteException;
use Maurice\Zenplate\Runner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RunnerTest extends TestCase
{
    public function testStaticTextPassesThroughUnchanged(): void
    {
        $runner = new Runner();
        self::assertSame('Hello world!', $runner->run('Hello world!'));
    }

    public function testEmptyTemplateProducesEmptyString(): void
    {
        $runner = new Runner();
        self::assertSame('', $runner->run(''));
    }

    public function testSimpleVariableSubstitution(): void
    {
        $runner = new Runner();
        $runner->assign('name', 'Alice');
        self::assertSame('Hi Alice!', $runner->run('Hi {%name}!'));
    }

    public function testMissingVariableRendersAsEmptyString(): void
    {
        $runner = new Runner();
        self::assertSame('Hi !', $runner->run('Hi {%name}!'));
    }

    public function testNestedDotAccess(): void
    {
        $runner = new Runner();
        $runner->assign('user', ['name' => 'Bob', 'role' => 'admin']);
        self::assertSame('Bob (admin)', $runner->run('{%user.name} ({%user.role})'));
    }

    public function testArrayBracketAccessByIndex(): void
    {
        $runner = new Runner();
        $runner->assign('items', ['first', 'second', 'third']);
        self::assertSame('second', $runner->run('{%items[1]}'));
    }

    public function testArrayBracketAccessByQuotedKey(): void
    {
        $runner = new Runner();
        $runner->assign('data', ['foo' => 'bar']);
        self::assertSame('bar', $runner->run('{%data["foo"]}'));
    }

    public function testAssignArrayMergesValues(): void
    {
        $runner = new Runner();
        $runner->assign(['a' => '1', 'b' => '2']);
        $runner->assign('c', '3');
        self::assertSame('1 2 3', $runner->run('{%a} {%b} {%c}'));
    }

    public function testIfTrueBranch(): void
    {
        $runner = new Runner();
        $runner->assign('show', true);
        self::assertSame('yes', $runner->run('{if %show}yes{else}no{/if}'));
    }

    public function testIfFalseBranch(): void
    {
        $runner = new Runner();
        $runner->assign('show', false);
        self::assertSame('no', $runner->run('{if %show}yes{else}no{/if}'));
    }

    public function testIfEqualityComparison(): void
    {
        $runner = new Runner();
        $runner->assign('status', 'active');
        self::assertSame('on', $runner->run('{if %status == "active"}on{else}off{/if}'));
    }

    public function testElseIfChain(): void
    {
        $runner = new Runner();
        $runner->assign('n', 2);
        $tpl = '{if %n == 1}one{elseif %n == 2}two{elseif %n == 3}three{else}other{/if}';
        self::assertSame('two', $runner->run($tpl));
    }

    public function testIfWithStrlenFunction(): void
    {
        $runner = new Runner();
        $runner->assign('s', 'hello');
        self::assertSame('long', $runner->run('{if strlen(%s) > 3}long{else}short{/if}'));
    }

    public function testIfWithUnaryNot(): void
    {
        $runner = new Runner();
        $runner->assign('flag', false);
        self::assertSame('inverted', $runner->run('{if !%flag}inverted{else}straight{/if}'));
    }

    public function testIfWithParentheses(): void
    {
        $runner = new Runner();
        $runner->assign('a', true);
        $runner->assign('b', false);
        self::assertSame('hit', $runner->run('{if (%a && !%b) || %b}hit{else}miss{/if}'));
    }

    public function testNestedIfBlocks(): void
    {
        $runner = new Runner();
        $runner->assign('outer', true);
        $runner->assign('inner', true);
        $tpl = '{if %outer}O{if %inner}I{/if}{/if}';
        self::assertSame('OI', $runner->run($tpl));
    }

    public function testCompileErrorRaisesExecuteException(): void
    {
        $runner = new Runner();
        $this->expectException(ExecuteException::class);
        $runner->run('{if %foo == }nope{/if}');
    }

    public function testMissingEndIfRaisesExecuteException(): void
    {
        $runner = new Runner();
        $this->expectException(ExecuteException::class);
        $runner->run('{if %foo}dangling');
    }

    public function testRunCompiledRoundTrip(): void
    {
        $runner = new Runner();
        $runner->assign('name', 'Carol');

        // First render via Runner::run which compiles + evaluates internally.
        self::assertSame('Hi Carol!', $runner->run('Hi {%name}!'));

        // Now compile separately and feed the compiled output into runCompiled.
        $compiler = new \Maurice\Zenplate\Compiler();
        $compiled = $compiler->compile('Hi {%name}!');
        self::assertNotFalse($compiled);
        self::assertSame('Hi Carol!', $runner->runCompiled($compiled));
    }

    public function testRunCompiledRejectsNonZenplateInput(): void
    {
        $runner = new Runner();
        $this->expectException(ExecuteException::class);
        $runner->runCompiled('<?php echo "pwn"; ?>');
    }

    /**
     * The compiler escapes literal `<?` so the eval'd output cannot accidentally
     * enter PHP mode. These cases lock that contract in.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function provideLiteralPhpTagCases(): iterable
    {
        yield 'xml prologue'   => ['<?xml version="1.0"?>', '<?xml version="1.0"?>'];
        yield 'inline php tag' => ['<?php echo "pwn"; ?>', '<?php echo "pwn"; ?>'];
        yield 'short echo tag' => ['<?= "pwn" ?>', '<?= "pwn" ?>'];
        yield 'lone open'      => ['before <? after', 'before <? after'];
        yield 'double open'    => ['<?<?', '<?<?'];
        yield 'php then open'  => ['<?php<?', '<?php<?'];
        yield 'open then xml'  => ["<?\n<?xml", "<?\n<?xml"];
    }

    #[DataProvider('provideLiteralPhpTagCases')]
    public function testLiteralPhpTagsArePreserved(string $template, string $expected): void
    {
        $runner = new Runner();
        self::assertSame($expected, $runner->run($template));
    }

    public function testSurroundingTextWithVariable(): void
    {
        $runner = new Runner();
        $runner->assign('who', 'world');
        self::assertSame("a\nhello world!\nb", $runner->run("a\nhello {%who}!\nb"));
    }

    public function testUnclosedDelimiterTreatedAsLiteral(): void
    {
        $runner = new Runner();
        // A bare `{` with nothing parseable after it should pass through.
        self::assertSame('before { after', $runner->run('before { after'));
    }

    public function testIfWithoutElseRendersBodyWhenTrue(): void
    {
        $runner = new Runner();
        $runner->assign('show', true);
        self::assertSame('hello', $runner->run('{if %show}hello{/if}'));
    }

    public function testIfWithoutElseRendersNothingWhenFalse(): void
    {
        $runner = new Runner();
        $runner->assign('show', false);
        self::assertSame('', $runner->run('{if %show}hello{/if}'));
    }

    public function testIfWithEmptyBody(): void
    {
        $runner = new Runner();
        $runner->assign('show', true);
        self::assertSame('', $runner->run('{if %show}{/if}'));
    }

    public function testIfWithEmptyElseBody(): void
    {
        $runner = new Runner();
        $runner->assign('show', false);
        self::assertSame('', $runner->run('{if %show}body{else}{/if}'));
    }

    /**
     * The compiler emits `(empty($var) ? '' : $var)` for every variable
     * substitution. PHP's empty() collapses these to '' even when the value
     * is arguably "present" (notably 0 and '0'). These cases lock that
     * surprising-but-historical behavior in.
     *
     * @return iterable<string, array{mixed, string}>
     */
    public static function provideFalsyValueCases(): iterable
    {
        yield 'null'         => [null, ''];
        yield 'empty string' => ['', ''];
        yield 'int zero'     => [0, ''];
        yield 'string zero'  => ['0', ''];
        yield 'false'        => [false, ''];
        yield 'empty array'  => [[], ''];
    }

    #[DataProvider('provideFalsyValueCases')]
    public function testFalsyValuesRenderAsEmptyString(mixed $value, string $expected): void
    {
        $runner = new Runner();
        $runner->assign('v', $value);
        self::assertSame($expected, $runner->run('{%v}'));
    }

    /**
     * Counterpart to provideFalsyValueCases: values that should pass through.
     *
     * @return iterable<string, array{mixed, string}>
     */
    public static function provideTruthyValueCases(): iterable
    {
        yield 'non-empty string' => ['hello', 'hello'];
        yield 'positive int'     => [1, '1'];
        yield 'string one'       => ['1', '1'];
        yield 'negative int'     => [-1, '-1'];
        yield 'true (bool)'      => [true, '1']; // PHP echo of true is "1"
        yield 'string with zero' => ['hello 0', 'hello 0'];
    }

    #[DataProvider('provideTruthyValueCases')]
    public function testTruthyValuesRenderAsExpected(mixed $value, string $expected): void
    {
        $runner = new Runner();
        $runner->assign('v', $value);
        self::assertSame($expected, $runner->run('{%v}'));
    }

    /**
     * Mirror of the variable cases, but inside an if-condition: every value
     * that renders as '' as a variable should also take the else branch.
     *
     * @return iterable<string, array{mixed, string}>
     */
    public static function provideIfTruthinessCases(): iterable
    {
        yield 'null'         => [null, 'F'];
        yield 'empty string' => ['', 'F'];
        yield 'int zero'     => [0, 'F'];
        yield 'string zero'  => ['0', 'F'];
        yield 'false'        => [false, 'F'];
        yield 'empty array'  => [[], 'F'];
        yield 'non-empty'    => ['x', 'T'];
        yield 'positive int' => [1, 'T'];
        yield 'true'         => [true, 'T'];
    }

    #[DataProvider('provideIfTruthinessCases')]
    public function testIfBranchPicksFalseForFalsyValues(mixed $value, string $expected): void
    {
        $runner = new Runner();
        $runner->assign('v', $value);
        self::assertSame($expected, $runner->run('{if %v}T{else}F{/if}'));
    }

    public function testNestedDotAccessOnMissingPath(): void
    {
        $runner = new Runner();
        $runner->assign('user', ['name' => 'Bob']);
        // %user.missing is undefined; should render as empty, not error out.
        self::assertSame('', $runner->run('{%user.missing}'));
    }

    public function testBracketAccessOnMissingIndex(): void
    {
        $runner = new Runner();
        $runner->assign('items', ['a']);
        self::assertSame('', $runner->run('{%items[5]}'));
    }

    public function testDeeplyNestedDotAccess(): void
    {
        $runner = new Runner();
        $runner->assign('a', ['b' => ['c' => ['d' => ['e' => 'found']]]]);
        self::assertSame('found', $runner->run('{%a.b.c.d.e}'));
    }

    public function testDeeplyNestedMissingMidPath(): void
    {
        $runner = new Runner();
        $runner->assign('a', ['b' => ['c' => 'shallow']]);
        // `a.b.c` is a string; descending further must not error or warn.
        self::assertSame('', $runner->run('{%a.b.c.d.e}'));
    }

    public function testDeeplyNestedMissingTopLevel(): void
    {
        $runner = new Runner();
        // No `nope` assigned at all; descending into it must not error.
        self::assertSame('', $runner->run('{%nope.foo.bar.baz}'));
    }

    public function testMixedDotAndBracketAccess(): void
    {
        $runner = new Runner();
        $runner->assign('users', [
            'admins' => [
                ['name' => 'Alice'],
                ['name' => 'Bob'],
            ],
        ]);
        self::assertSame('Bob', $runner->run('{%users.admins[1].name}'));
    }

    public function testDeeplyNestedInIfCondition(): void
    {
        $runner = new Runner();
        $runner->assign('cfg', ['feature' => ['enabled' => true]]);
        self::assertSame('on', $runner->run('{if %cfg.feature.enabled}on{else}off{/if}'));
    }
}
