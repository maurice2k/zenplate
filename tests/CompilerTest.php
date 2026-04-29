<?php

declare(strict_types=1);

namespace Maurice\Zenplate\Tests;

use Maurice\Zenplate\Compiler;
use PHPUnit\Framework\TestCase;

final class CompilerTest extends TestCase
{
    public function testCompiledOutputCarriesZenplateHeader(): void
    {
        $compiler = new Compiler();
        $compiled = $compiler->compile('hello');

        self::assertIsString($compiled);
        self::assertStringStartsWith('<?php /* zenplate version ' . Compiler::VERSION, $compiled);
    }

    public function testCompileNullTemplateReturnsBareHeader(): void
    {
        $compiler = new Compiler();
        $compiled = $compiler->compile(null);

        self::assertIsString($compiled);
        self::assertStringStartsWith('<?php /* zenplate version', $compiled);
    }

    public function testGetUsedVariablesTracksAllReferencedVars(): void
    {
        $compiler = new Compiler();
        $compiler->compile('{%a} and {%b.c} and {%d[0]} and {%a}');

        $used = $compiler->getUsedVariables();
        sort($used);
        self::assertSame(['a', 'b', 'd'], $used);
    }

    public function testGetUsedVariablesIsResetAcrossCompiles(): void
    {
        $compiler = new Compiler();
        $compiler->compile('{%foo}');
        $compiler->compile('{%bar}');

        self::assertSame(['bar'], $compiler->getUsedVariables());
    }

    public function testNoErrorsOnValidTemplate(): void
    {
        $compiler = new Compiler();
        $compiler->compile('{if %x == 1}a{else}b{/if}');

        self::assertFalse($compiler->errorsExist());
        self::assertSame(0, $compiler->getErrorCount());
        self::assertSame([], $compiler->getErrors());
    }

    public function testMissingEndIfReportsError(): void
    {
        $compiler = new Compiler();
        $result = $compiler->compile('{if %x}body');

        self::assertFalse($result);
        self::assertTrue($compiler->errorsExist());
        $errors = $compiler->getErrors();
        $types = array_column($errors, 'type');
        self::assertContains(Compiler::ERROR_IF_NO_ENDIF, $types);
    }

    public function testEndifWithoutIfReportsError(): void
    {
        $compiler = new Compiler();
        $result = $compiler->compile('{/if}');

        self::assertFalse($result);
        $types = array_column($compiler->getErrors(), 'type');
        self::assertContains(Compiler::ERROR_ENDIF_NO_IF, $types);
    }

    public function testElseWithoutIfReportsError(): void
    {
        $compiler = new Compiler();
        $result = $compiler->compile('{else}body{/if}');

        self::assertFalse($result);
        $types = array_column($compiler->getErrors(), 'type');
        self::assertContains(Compiler::ERROR_ELSE_NO_IF, $types);
    }

    public function testElseIfWithoutIfReportsError(): void
    {
        $compiler = new Compiler();
        $result = $compiler->compile('{elseif %x}a{/if}');

        self::assertFalse($result);
        $types = array_column($compiler->getErrors(), 'type');
        self::assertContains(Compiler::ERROR_ELSEIF_NO_IF, $types);
    }

    public function testSingleEqualsIsReportedAsSpecificError(): void
    {
        $compiler = new Compiler();
        $result = $compiler->compile('{if %x = 1}a{/if}');

        // Any recorded error makes compile() return false, but the single-'='
        // recovery means we get exactly the dedicated error type rather than
        // a generic "unknown expression" error.
        self::assertFalse($result);
        $types = array_column($compiler->getErrors(), 'type');
        self::assertContains(Compiler::ERROR_IF_SINGLE_EQUAL_SIGN, $types);
        self::assertNotContains(Compiler::ERROR_IF_UNKNOWN_EX, $types);
    }

    public function testUnsupportedFunctionInIfReportsError(): void
    {
        $compiler = new Compiler();
        $result = $compiler->compile('{if exec("rm -rf /")}go{/if}');

        self::assertFalse($result);
        $types = array_column($compiler->getErrors(), 'type');
        self::assertContains(Compiler::ERROR_IF_FUNC_NOT_SUPPORTED, $types);
    }

    public function testRebuildingRegexesAfterDelimiterChange(): void
    {
        $compiler = new Compiler();
        $compiler->leftDelimiter = '<<';
        $compiler->rightDelimiter = '>>';

        $compiled = $compiler->compile('hello <<%name>>!');
        self::assertIsString($compiled);

        // Round-trip via the runner with custom delimiters.
        $runner = new \Maurice\Zenplate\Runner();
        $runner->assign('name', 'world');
        self::assertSame('hello world!', $runner->runCompiled($compiled));
    }
}
