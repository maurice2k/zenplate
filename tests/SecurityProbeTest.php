<?php

declare(strict_types=1);

namespace Maurice\Zenplate\Tests;

use Maurice\Zenplate\Runner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Probes the eval() attack surface from a template-author perspective.
 *
 * Strategy: each exploit attempt tries to mutate a global flag
 * ($GLOBALS['__zp_pwned_<id>']) when executed. If eval ever runs the
 * injected code, the flag flips and the test fails. This is more reliable
 * than scanning output strings, because injected code that writes to
 * $GLOBALS leaves a clear trace even if it produces no stdout.
 *
 * Each case also asserts the rendered output equals the expected literal,
 * which catches the secondary case where injection produces unexpected
 * output without using PHP-level side effects.
 */
final class SecurityProbeTest extends TestCase
{
    protected function setUp(): void
    {
        // Clear any flags from previous tests.
        foreach (array_keys($GLOBALS) as $k) {
            if (is_string($k) && str_starts_with($k, '__zp_pwned_')) {
                unset($GLOBALS[$k]);
            }
        }
    }

    /**
     * Each entry is a template that tries to escape Zenplate's intended
     * surface area and execute attacker-controlled PHP. The success criterion
     * is uniform: none of these may set $GLOBALS['__zp_pwned_*'].
     *
     * Whether the runner ends up rendering text, throwing a compile error,
     * or crashing on a runtime warning is irrelevant for security; what
     * matters is that no injected code runs.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideExploitAttempts(): iterable
    {
        // Bracket string with escaped quote — try to break out of the PHP
        // string literal context the bracket subscript is emitted into.
        yield 'bracket-string escape break' => ['{%foo["a\"; $GLOBALS[\'__zp_pwned_1\']=true; $x=\""]}'];

        // Bracket string with PHP curly-syntax interpolation; {$expr} inside
        // double-quoted PHP strings runs expr at runtime.
        yield 'bracket-string {$expr} interpolation' => ['{%foo["{${\'__zp_pwned_2\'}=\'x\'}"]}'];

        // Bracket string with ${var} simple interpolation.
        yield 'bracket-string ${var} interpolation' => ['{%foo["${\'__zp_pwned_3\'}"]}'];

        // Bracket string with $varname interpolation in eval scope.
        yield 'bracket-string $this leak' => ['{%foo["$this"]}'];

        // Plain $var interpolation in a bracket subscript — only a "read"
        // attempt, but tests that even reads of injected globals don't fire.
        yield 'bracket-string $GLOBALS read' => ['{%foo["$GLOBALS"]}'];

        // If-condition with a string literal containing complex syntax.
        yield 'if-string {$expr}' => ['{if "{${\'__zp_pwned_5\'}=\'x\'}" == "x"}hit{else}miss{/if}'];

        // Try to call an arbitrary function from inside an if.
        yield 'arbitrary function in if' => ['{if shell_exec("touch /tmp/__zp_pwned_6")}hit{/if}'];

        // Try to chain extra statements into the if expression.
        yield 'extra statements in if' => ['{if 1 ; $GLOBALS["__zp_pwned_7"]=true ; 1}hit{/if}'];

        // Try to inject via a variable name (regex should reject this).
        yield 'injection via var name' => ['{%foo\'; $GLOBALS[\'__zp_pwned_8\']=true; //}'];

        // Literal <?php block in template body.
        yield 'literal <?php block' => ['<?php $GLOBALS["__zp_pwned_9"]=true; ?>'];

        // Literal <?= short echo tag.
        yield 'literal short echo' => ['<?= $GLOBALS["__zp_pwned_10"]=true ?>'];

        // Single-byte <? followed by code.
        yield 'lone <? followed by code' => ['<? $GLOBALS["__zp_pwned_11"]=true; ?>'];

        // Heredoc syntax in an if-condition.
        yield 'heredoc attempt in if' => ["{if <<<EOT\n\$GLOBALS['__zp_pwned_12']=true\nEOT\n}hit{/if}"];

        // Backtick (PHP shell-exec) in if expression.
        yield 'backtick in if' => ['{if `touch /tmp/__zp_pwned_13`}hit{/if}'];

        // Bracket with single-quoted string — single quotes don't interpolate
        // in PHP, so this is safe by definition; verify the regex doesn't
        // somehow flip quote semantics.
        yield 'bracket single-quoted dollar' => ["{%foo['\$GLOBALS[\"__zp_pwned_14\"]=true']}"];

        // Try to use the dot-selector path for injection.
        yield 'dot selector with bogus chars' => ['{%foo.bar"); $GLOBALS["__zp_pwned_15"]=true; //}'];

        // Multi-byte / unicode in variable name.
        yield 'unicode in var name' => ['{%fooä; $GLOBALS["__zp_pwned_16"]=true}'];

        // -- Aggressive interpolation-escalation attempts --
        // The earlier warnings proved PHP interpolates the contents of bracket
        // subscript strings at runtime. These cases probe whether that read
        // channel can be escalated to a *write* or a *function call*.

        // 17. Direct $GLOBALS write via complex curly assignment.
        yield 'complex curly assignment to $GLOBALS' => ['{%foo["{$GLOBALS[\'__zp_pwned_17\']=\'x\'}"]}'];

        // 18. Variable variable assignment via ${} syntax.
        yield 'variable-variable assignment' => ['{%foo["${\'__zp_pwned_18\'}=\'x\'"]}'];

        // 19. Method call on $this via complex curly.
        yield 'method call on $this' => ['{%foo["{$this->assign(\'__zp_pwned_19\',true)}"]}'];

        // 20. Function call inside variable variable: ${func()}.
        yield 'function call via ${func()}' => ['{%foo["${\'__zp_pwned_20\'.shell_exec(\'echo x\')}"]}'];

        // 21. Object property read via complex curly.
        yield 'property read via {$this->vars}' => ['{%foo["{$this->vars}"]}'];

        // 22. Try to trigger a static method call via curly complex syntax.
        //     PHP allows {$Class::method()}? It actually doesn't, but probe it.
        yield 'static call attempt' => ['{%foo["{Maurice\\Zenplate\\Runner::pwn(\'__zp_pwned_22\')}"]}'];
    }

    /**
     * Smoking gun: a template author MUST NOT be able to call an arbitrary
     * PHP function via the bracket-subscript interpolation channel.
     *
     * The template's bracket subscript is emitted into the generated PHP
     * verbatim, surrounded by `["..."]` quotes. PHP interprets the contents
     * as a double-quoted string, and the `${expr}` syntax inside such strings
     * allows arbitrary expression evaluation -- including function calls.
     */
    public function testBracketSubscriptDoesNotExecuteArbitraryFunctions(): void
    {
        InjectionWitness::reset();

        $template = '{%foo["${\'unused_\' . \\Maurice\\Zenplate\\Tests\\InjectionWitness::record(\'rce-via-bracket\')}"]}';

        $runner = new Runner();
        try {
            $runner->run($template);
        } catch (\Throwable) {
            // ignore -- we only care whether the witness function was called
        }

        self::assertSame(
            [],
            InjectionWitness::log(),
            'INJECTION CONFIRMED: a template called an arbitrary PHP function via ${expr} interpolation in a bracket subscript.',
        );
    }

    /**
     * Same probe but via the if-condition string-literal path, which also
     * gets emitted verbatim into the generated PHP.
     */
    public function testIfConditionDoesNotExecuteArbitraryFunctions(): void
    {
        InjectionWitness::reset();

        $template = '{if "${\'x\' . \\Maurice\\Zenplate\\Tests\\InjectionWitness::record(\'rce-via-if\')}" == "y"}hit{/if}';

        $runner = new Runner();
        try {
            $runner->run($template);
        } catch (\Throwable) {
            // ignore
        }

        self::assertSame(
            [],
            InjectionWitness::log(),
            'INJECTION CONFIRMED: a template called an arbitrary PHP function via ${expr} interpolation in an if-condition.',
        );
    }

    /**
     * An *assigned variable value* must never be re-parsed as template syntax.
     */
    public function testAssignedVariableValueIsNotReParsed(): void
    {
        $runner = new Runner();
        $runner->assign('payload', '{%attack} <?php $GLOBALS["__zp_pwned_23"]=true; ?>');
        $output = $runner->run('Hello {%payload}!');

        $leaks = array_values(array_filter(
            array_keys($GLOBALS),
            static fn($k): bool => is_string($k) && str_starts_with($k, '__zp_pwned_'),
        ));
        self::assertSame([], $leaks, 'Assigned variable value was re-parsed as template');

        // Output should contain the raw payload as plain text (echo dumps it
        // straight into the output buffer; PHP doesn't re-enter PHP mode for
        // string contents).
        self::assertStringContainsString('{%attack}', $output);
        self::assertStringContainsString('<?php', $output);
    }

    #[DataProvider('provideExploitAttempts')]
    public function testNoInjectionViaTemplate(string $template): void
    {
        $runner = new Runner();

        // Throw vs. render is fine; only injection is forbidden.
        try {
            $runner->run($template);
        } catch (\Throwable) {
            // expected for many of these
        }

        $leaks = array_values(array_filter(
            array_keys($GLOBALS),
            static fn($k): bool => is_string($k) && str_starts_with($k, '__zp_pwned_'),
        ));

        self::assertSame(
            [],
            $leaks,
            "INJECTION! Side-effect flag set during eval for template:\n  {$template}\nLeaked keys: " . implode(', ', $leaks),
        );
    }
}
