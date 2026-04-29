<?php

declare(strict_types=1);

/**
 * Zenplate -- Simple and fast PHP based template engine
 *
 * Copyright 2008-2026 by Moritz Fain
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 */

namespace Maurice\Zenplate;

use Maurice\Zenplate\Exception\ParseException;

class Compiler
{
    public const VERSION = '0.6.0';

    public const ERROR_VAR_SYNTAX = 1;

    public const ERROR_IF_NO_RDELIM = 2;
    public const ERROR_IF_NO_ARGS = 3;
    public const ERROR_IF_UNBALANCED_PARAS = 4;
    public const ERROR_IF_OP_NOT_ALLOWED = 5;
    public const ERROR_IF_MISSING_EX_AFTER = 6;
    public const ERROR_IF_VAR_NOT_ALLOWED = 7;
    public const ERROR_IF_FUNC_NOT_ALLOWED = 8;
    public const ERROR_IF_FUNC_NOT_SUPPORTED = 9;
    public const ERROR_IF_OPEN_PARAS_NOT_ALLOWED = 10;
    public const ERROR_IF_CLOSE_PARAS_NOT_ALLOWED = 11;
    public const ERROR_IF_CLOSE_PARAS_NOT_ALLOWED_NO_OPEN_PARAS_BEFORE = 12;
    public const ERROR_IF_SINGLE_EQUAL_SIGN = 13;
    public const ERROR_IF_UNKNOWN_EX = 14;
    public const ERROR_IF_NO_ENDIF = 15;

    public const ERROR_ELSE_NO_RDELIM = 16;
    public const ERROR_ELSE_NO_IF = 17;
    public const ERROR_ELSE_ONLY_ONE_ALLOWED = 18;

    public const ERROR_ENDIF_NO_RDELIM = 19;
    public const ERROR_ENDIF_NO_IF = 20;

    public const ERROR_ELSEIF_NO_IF = 21;

    public const ERROR_BLOCK_NO_ENDBLOCK = 40;

    public string $leftDelimiter = '{';
    public string $rightDelimiter = '}';

    /** @var list<string> */
    public array $supportedIfFuncs = ['strlen', 'strtoupper', 'strtolower'];

    /** @var array<string,bool> */
    protected array $usedVariables = [];

    protected int $offset = 0;
    protected string $output = '';

    protected string $template = '';
    protected int $templateLength = 0;

    /** @var array<string, list<array<string,mixed>>> */
    protected array $blockStack = [];

    /** @var list<array{type:int,offset:int,additional:string,message:string}> */
    protected array $errorList = [];

    protected string $dvarRegexp;
    protected string $varRegexp;
    protected string $varBracketRegexp;
    protected string $varSelectorRegexp;
    protected string $funcRegexp;

    private string $rightDelimQuoted;
    private string $variableMatchRegexp;
    private string $variableReplaceRegexp;
    private string $blockOrFunctionRegexp;
    private string $ifTokenRegexp;
    private string $rdelimOnlyRegexp;
    private string $dvarFullMatchRegexp;

    public function __construct()
    {
        // matches double quoted strings: "foobar", "foo\"bar"
        $dbQstrRegexp = '"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"';

        // matches single quoted strings: 'foobar', 'foo\'bar'
        $siQstrRegexp = '\'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\'';

        $qstrRegexp = '(?:' . $dbQstrRegexp . '|' . $siQstrRegexp . ')';

        // matches numerical constants: 30, -12, 13.22
        $numConstRegexp = '(?:\-?0[xX][0-9a-fA-F]+|\-?\d+(?:\.\d+)?|\.\d+)';

        // matches bracket portion of vars: [0], ["foobar"], ['foobar']
        // %-vars (e.g. [%bar]) are intentionally not supported.
        $this->varBracketRegexp = '\[(?:\d+|' . $qstrRegexp . ')\]';

        // matches selector portion of vars: .foo, .bar123
        $this->varSelectorRegexp = '\.[a-zA-Z_]+[a-zA-Z0-9_]*';

        // matches direct % vars: %foo, %foo.bar, %foo.bar["foobar"], %foo[0], %foo[5]["foobar"]
        $this->dvarRegexp = '\%[a-zA-Z0-9_]+(?:' . $this->varBracketRegexp . '|(' . $this->varSelectorRegexp . '))*';

        // matches valid variable syntax: %foo, 'text', "text", 30, -12, 12.22, true, false
        $this->varRegexp = '(?:' . $this->dvarRegexp . '|' . $qstrRegexp . '|' . $numConstRegexp . '|true|false)';

        // matches function or block name: foo, bar123, __foo
        $this->funcRegexp = '[\w\_][\w\d\_]*';

        // TODO: variable modifiers (`{%foo|upper}`, `{%foo|default:"x"}`, chained `|a|b`)
        // are intentionally unimplemented. Touching this will need: a $modRegexp,
        // hooking into replaceVariable() and parseIf()'s var branch, plus a
        // registry of allowed modifier names mapping to safe PHP callables.

        $this->rebuildCompiledRegexps();
    }

    /**
     * Rebuild the regexes that depend on $rightDelimiter / class regex fragments.
     * Called once in the constructor; callers that mutate $rightDelimiter from
     * outside should be aware that the cached forms won't pick that up.
     */
    private function rebuildCompiledRegexps(): void
    {
        $this->rightDelimQuoted = preg_quote($this->rightDelimiter, '/');

        $this->variableMatchRegexp = '/(' . $this->dvarRegexp . ')' . $this->rightDelimiter . '/';
        $this->variableReplaceRegexp = '/(%[a-zA-Z0-9_]+|' . $this->varBracketRegexp . '|' . $this->varSelectorRegexp . ')/';
        $this->blockOrFunctionRegexp = '/(\/?' . $this->funcRegexp . ')(\s+|' . $this->rightDelimiter . '|$)/';
        $this->ifTokenRegexp = '/(?>(' . $this->varRegexp . ')|(!==|===|==|!=|<>|<<|>>|<=|>=|\&\&|\|\||,|\^|\||\&|<|>|\%|\+|\-|\/|\*)|(\~|\!|\@)|' . $this->rightDelimQuoted . '|\(|\)|=|\b\w+\b|\S+)/';
        $this->rdelimOnlyRegexp = '/\s*' . $this->rightDelimiter . '/';
        $this->dvarFullMatchRegexp = '/^' . $this->dvarRegexp . '$/';
    }

    /**
     * Compiles a template into PHP source.
     *
     * @return string|false Compiled PHP source on success, false if errors were collected (see getErrors()).
     */
    public function compile(?string $template): string|false
    {
        $this->template = $template ?? '';
        $this->templateLength = strlen($this->template);
        $this->offset = 0;
        $this->output = '<?php /* zenplate version ' . self::VERSION . ', created on ' . date('c') . ' */' . "\n?>\n";
        $this->errorList = [];
        $this->usedVariables = [];
        $this->blockStack = [];

        // Right-delimiter may have been mutated since construction; refresh cached regexes.
        $this->rebuildCompiledRegexps();

        $lastOffset = $this->offset;

        try {
            while (($pos = strpos($this->template, $this->leftDelimiter, $this->offset)) !== false) {

                $this->output .= $this->emitLiteral(substr($this->template, $this->offset, $pos - $this->offset));
                $this->offset = $pos;

                $testOffset = $pos + strlen($this->leftDelimiter);

                if ($testOffset >= $this->templateLength) {
                    break;
                }

                $nextChar = $this->template[$testOffset];

                $parsed = $nextChar === '%'
                    ? $this->parseVariable($testOffset)
                    : $this->parseBlockOrFunction($testOffset);

                if ($parsed !== false) {
                    $this->output .= $parsed;
                } else {
                    $this->output .= $this->emitLiteral(substr($this->template, $this->offset, $testOffset - $this->offset));
                    $this->offset = $testOffset;
                }

                if ($this->offset === $lastOffset) {
                    throw new \RuntimeException('Main parser loop is broken');
                }

                $lastOffset = $this->offset;
            }

            $this->output .= $this->emitLiteral(substr($this->template, $this->offset));

            foreach ($this->blockStack as $blockname => $stack) {
                if (count($stack) === 0) {
                    continue;
                }
                foreach ($stack as $item) {
                    if ($blockname === 'if') {
                        $this->addError(self::ERROR_IF_NO_ENDIF, $this->templateLength - 1, '', "No closing end-if structure found for if starting at {$item['offset']}");
                    } else {
                        $this->addError(self::ERROR_BLOCK_NO_ENDBLOCK, $this->templateLength - 1, $blockname, "No closing end-{$blockname} structure found for {$blockname} starting at {$item['offset']}");
                    }
                }
            }

            return $this->errorsExist() ? false : $this->output;
        } catch (ParseException) {
            return false;
        }
    }

    /**
     * Escapes literal `<?` sequences in a template chunk so the eval'd output
     * can't accidentally enter PHP mode. Equivalent to the historical
     * preg_replace('/(<\?\S*)/s', ...) form: PHP eats the newline after `?>`
     * so the surrounding text is preserved byte-for-byte.
     */
    private function emitLiteral(string $chunk): string
    {
        if ($chunk === '' || !str_contains($chunk, '<?')) {
            return $chunk;
        }
        return str_replace('<?', "<?php echo '<?' ?>\n", $chunk);
    }

    /**
     * Decode a quoted-string token (matched by qstrRegexp) to its raw value.
     * The compiler must NEVER emit a user-supplied string into the generated
     * PHP as a double-quoted literal -- PHP's `${expr}` syntax inside
     * double-quoted strings allows arbitrary expression evaluation. Use this
     * with safePhpString() to round-trip user strings safely.
     *
     * For "..." we apply standard C-style escape decoding (\n, \t, \", \\, ...).
     * For '...' only \\ and \' are special, matching PHP's own rules.
     */
    private function decodeQuotedString(string $literal): string
    {
        if ($literal === '' || strlen($literal) < 2) {
            return '';
        }
        $body = substr($literal, 1, -1);
        if ($literal[0] === '"') {
            return stripcslashes($body);
        }
        return strtr($body, ['\\\\' => '\\', "\\'" => "'"]);
    }

    /**
     * Emit a raw PHP string value as a single-quoted PHP literal. PHP single-
     * quoted strings do NOT interpolate variables and do NOT honour the
     * `${expr}` / `{$expr}` syntaxes, so this is the safe form to embed
     * attacker-controlled data into eval'd PHP.
     */
    private function safePhpString(string $raw): string
    {
        return "'" . strtr($raw, ['\\' => '\\\\', "'" => "\\'"]) . "'";
    }

    /**
     * @return list<string> Names of variables referenced by the last compiled template.
     */
    public function getUsedVariables(): array
    {
        return array_keys($this->usedVariables);
    }

    /**
     * @return string|false Replacement string on success, false on no match.
     */
    protected function parseVariable(int $testOffset): string|false
    {
        if (preg_match($this->variableMatchRegexp, $this->template, $matches, PREG_OFFSET_CAPTURE, $testOffset)) {
            if ($matches[0][1] === $testOffset) {
                $this->offset = $testOffset + strlen($matches[0][0]);
                return $this->replaceVariable($matches[1][0], false);
            }

            $this->addError(self::ERROR_VAR_SYNTAX, $testOffset, '', "Error parsing variable expression starting at position {$testOffset}", true);
        }

        return false;
    }

    /**
     * Generates the PHP expression that resolves a `%var` reference.
     *
     * @param string $variable Token previously matched by $this->dvarRegexp.
     * @param bool   $inline   If true, return a bare expression; if false, wrap in `<?php echo ... ?>`.
     */
    protected function replaceVariable(string $variable, bool $inline): string
    {
        if (!preg_match_all($this->variableReplaceRegexp, $variable, $matches)) {
            return $variable;
        }

        $output = '';
        $parts = $matches[1];
        $max = count($parts);

        for ($i = 0; $i < $max; ++$i) {
            $piece = $parts[$i];
            $name = substr($piece, 1);
            if ($i === 0) {
                $this->usedVariables[$name] = true;
                $output .= '$this->vars[\'' . $name . '\']';
                continue;
            }

            $first = $piece[0] ?? '';
            if ($first === '.') {
                $output .= '[\'' . $name . '\']';
            } elseif ($first === '[') {
                // $piece is `[<digits>]` or `["..."]` or `['...']` per
                // varBracketRegexp. Emit numeric forms verbatim; for string
                // forms decode to the raw key value and re-emit as a single-
                // quoted PHP literal so PHP cannot interpolate `${expr}`.
                $inner = substr($piece, 1, -1);
                if (ctype_digit($inner)) {
                    $output .= $piece;
                } else {
                    $output .= '[' . $this->safePhpString($this->decodeQuotedString($inner)) . ']';
                }
            }
        }

        $output = '(empty(' . $output . ') ? \'\' : ' . $output . ')';

        return $inline ? $output : '<?php echo ' . $output . '; ?>' . "\n";
    }

    /**
     * @return string|false Replacement string on success, false on no match.
     */
    protected function parseBlockOrFunction(int $testOffset): string|false
    {
        if (!preg_match($this->blockOrFunctionRegexp, $this->template, $matches, PREG_OFFSET_CAPTURE, $testOffset)) {
            return false;
        }
        if ($matches[1][1] !== $testOffset) {
            return false;
        }

        $name = strtolower($matches[1][0]);

        return match ($name) {
            'if'     => $this->parseIf($testOffset + 2),
            'else'   => $this->parseElse($testOffset + 4),
            'elseif' => $this->parseIf($testOffset + 6, true),
            '/if'    => $this->parseEndif($testOffset + 3),
            default  => false,
        };
    }

    /**
     * @return string|false Replacement string on success, false otherwise.
     */
    protected function parseIf(int $testOffset, bool $elseIf = false): string|false
    {
        $offset = $testOffset;
        $offsetEnd = $offset;
        /** @var list<array{type:string,str:string,offset:int}> $argumentList */
        $argumentList = [];
        $rdelimFound = false;
        $parenthesisOpen = 0;
        $parenthesisClose = 0;

        if ($elseIf) {
            $count = $this->stackCount('if');

            if ($count === 0) {
                $this->addError(self::ERROR_ELSEIF_NO_IF, $testOffset, '', "No if-statement found for elseif structure at position {$testOffset}");
                return false;
            }

            $elseCount = (int)$this->stackCurrentGetValue('if', 'else');
            if ($elseCount !== 0) {
                $this->addError(self::ERROR_ELSE_ONLY_ONE_ALLOWED, $testOffset, '', "Only one else per if-statement is allowed at position {$testOffset}");
            }

            $this->stackPop('if');
        }

        while (true) {
            if (!preg_match($this->ifTokenRegexp, $this->template, $matches, PREG_OFFSET_CAPTURE, $offset)) {
                break;
            }

            $argument = $matches[0][0];
            $matchOffset = $matches[0][1];

            if ($argument === $this->rightDelimiter) {
                $rdelimFound = true;
            }

            if ($rdelimFound || $offset > $matchOffset) {
                $offsetEnd = $matchOffset + strlen($argument);
                break;
            }

            $type = isset($matches[4]) ? 'op_unary'
                : (isset($matches[3]) ? 'op_binary'
                : (isset($matches[1]) ? 'var' : ''));

            $argumentList[] = ['type' => $type, 'str' => $argument, 'offset' => $matchOffset];

            if ($argument === '(') {
                $parenthesisOpen++;
            } elseif ($argument === ')') {
                $parenthesisClose++;
            }

            $offset = $matchOffset + strlen($argument);
        }

        $startupErrorCount = $this->getErrorCount();

        if (!$rdelimFound) {
            $this->addError(self::ERROR_IF_NO_RDELIM, $testOffset, '', "No right delimiter found for if statement starting at position {$testOffset}", true);
        }

        $argumentCount = count($argumentList);
        if ($argumentCount === 0) {
            $this->addError(self::ERROR_IF_NO_ARGS, $offset, '', "Error parsing if statement starting at position {$offset}", true);
        }

        if ($parenthesisOpen !== $parenthesisClose) {
            $this->addError(self::ERROR_IF_UNBALANCED_PARAS, $offset, '', "Unbalanced parenthesis in if statement at position {$offset}", true);
        }

        $parasOpened = 0;
        $nextAllowed = ['var', 'func', 'para_open', 'op_unary'];
        $voidParasAllowed = false;

        $ifStatement = '';

        for ($i = 0; $i < $argumentCount; ++$i) {
            $arg = &$argumentList[$i];
            $lastArgument = ($i === $argumentCount - 1);
            $type = $arg['type'];
            $str = $arg['str'];
            $argOffset = $arg['offset'];

            if ($type === 'op_binary') {

                if (!in_array($type, $nextAllowed, true)) {
                    $this->addError(self::ERROR_IF_OP_NOT_ALLOWED, $argOffset, $str, "Operator \"{$str}\" not allowed at position {$argOffset}");
                } elseif ($lastArgument) {
                    $this->addError(self::ERROR_IF_MISSING_EX_AFTER, $argOffset, $str, "Missing expression after \"{$str}\" at position {$argOffset}");
                }

                $nextAllowed = ['var', 'func', 'para_open', 'op_unary'];

            } elseif ($type === 'var') {

                if (!in_array($type, $nextAllowed, true)) {
                    $this->addError(self::ERROR_IF_VAR_NOT_ALLOWED, $argOffset, '', "Variable not allowed at position {$argOffset}");
                }

                if (preg_match($this->dvarFullMatchRegexp, $str)) {
                    $str = $this->replaceVariable($str, true);
                } elseif ($str !== '' && ($str[0] === '"' || $str[0] === "'")) {
                    // qstr token: re-emit as a single-quoted PHP literal so
                    // PHP's `${expr}` / `{$expr}` syntax cannot fire on
                    // attacker-supplied content.
                    $str = $this->safePhpString($this->decodeQuotedString($str));
                }

                $nextAllowed = ['op_binary', 'para_close'];

            } elseif (preg_match('/^' . $this->funcRegexp . '$/', $str)) {

                $type = 'func';

                if (!in_array($type, $nextAllowed, true)) {
                    $this->addError(self::ERROR_IF_FUNC_NOT_ALLOWED, $argOffset, '', "Function not allowed at position {$argOffset}");
                } elseif (!in_array($str, $this->supportedIfFuncs, true)) {
                    $this->addError(self::ERROR_IF_FUNC_NOT_SUPPORTED, $argOffset, '', "Unsupported function call at position {$argOffset}");
                }

                $voidParasAllowed = true;
                $nextAllowed = ['para_open'];

            } elseif ($type === 'op_unary') {

                if (!in_array($type, $nextAllowed, true)) {
                    $this->addError(self::ERROR_IF_OP_NOT_ALLOWED, $argOffset, $str, "Operator \"{$str}\" not allowed at position {$argOffset}");
                } elseif ($lastArgument) {
                    $this->addError(self::ERROR_IF_MISSING_EX_AFTER, $argOffset, $str, "Missing expression after \"{$str}\" at position {$argOffset}");
                }

                $nextAllowed = ['var', 'func', 'para_open', 'op_unary'];

            } elseif ($str === '(') {

                $type = 'para_open';

                if (!in_array($type, $nextAllowed, true)) {
                    $this->addError(self::ERROR_IF_OPEN_PARAS_NOT_ALLOWED, $argOffset, '', "Opening parenthesis not allowed at position {$argOffset}");
                } else {
                    $parasOpened++;
                }

                $nextAllowed = ['var', 'func', 'para_open', 'op_unary'];

                if ($voidParasAllowed) {
                    $nextAllowed[] = 'para_close';
                    $voidParasAllowed = false;
                }

            } elseif ($str === ')') {

                $type = 'para_close';

                if (!in_array($type, $nextAllowed, true)) {
                    $this->addError(self::ERROR_IF_CLOSE_PARAS_NOT_ALLOWED, $argOffset, '', "Closing parenthesis not allowed at position {$argOffset}");
                } elseif ($parasOpened > 0) {
                    $parasOpened--;
                } else {
                    $this->addError(self::ERROR_IF_CLOSE_PARAS_NOT_ALLOWED_NO_OPEN_PARAS_BEFORE, $argOffset, '', "No opening parenthesis found before position {$argOffset}");
                }

                $nextAllowed = ['op_binary', 'para_close'];

            } else {

                if (in_array('op_binary', $nextAllowed, true) && $str === '=') {
                    // single '=' in a comparison context: treat as '==' but report it
                    $arg['type'] = 'op_binary';
                    $arg['str'] = '==';
                    $this->addError(self::ERROR_IF_SINGLE_EQUAL_SIGN, $argOffset, '', "Should be == at position {$argOffset}");
                    $startupErrorCount++;
                    $i--;
                    continue;
                }

                $this->addError(self::ERROR_IF_UNKNOWN_EX, $argOffset, '', "Unknown expression at position {$argOffset}");
            }

            $ifStatement .= ($i > 0 ? ' ' : '') . $str;
        }
        unset($arg);

        if ($this->getErrorCount() !== $startupErrorCount) {
            return false;
        }

        $this->stackPush('if', $testOffset);
        $this->offset = $offsetEnd;

        return '<?php ' . ($elseIf ? 'else' : '') . 'if (' . $ifStatement . '): ?>';
    }

    /**
     * @return string|false Replacement string on success, false otherwise.
     */
    protected function parseElse(int $testOffset): string|false
    {
        if (preg_match($this->rdelimOnlyRegexp, $this->template, $matches, PREG_OFFSET_CAPTURE, $testOffset) && $matches[0][1] === $testOffset) {
            $startupErrorCount = $this->getErrorCount();

            if ($this->stackCount('if') === 0) {
                $this->addError(self::ERROR_ELSE_NO_IF, $testOffset, '', "No if-statement found for else structure at position {$testOffset}");
            }

            $elseCount = (int)$this->stackCurrentGetValue('if', 'else');
            if ($elseCount !== 0) {
                $this->addError(self::ERROR_ELSE_ONLY_ONE_ALLOWED, $testOffset, '', "Only one else per if-statement is allowed at position {$testOffset}");
            } else {
                $this->stackCurrentSetValue('if', 'else', 1);
            }

            if ($this->getErrorCount() === $startupErrorCount) {
                $this->offset = $matches[0][1] + strlen($matches[0][0]);
                return '<?php else: ?>';
            }

            return false;
        }

        $this->addError(self::ERROR_ELSE_NO_RDELIM, $testOffset, '', "No right delimiter found for else structure at position {$testOffset}", true);

        return false;
    }

    /**
     * @return string|false Replacement string on success, false otherwise.
     */
    protected function parseEndif(int $testOffset): string|false
    {
        if (preg_match($this->rdelimOnlyRegexp, $this->template, $matches, PREG_OFFSET_CAPTURE, $testOffset) && $matches[0][1] === $testOffset) {
            $startupErrorCount = $this->getErrorCount();

            $res = $this->stackPop('if');

            if ($res === null) {
                $this->addError(self::ERROR_ENDIF_NO_IF, $testOffset, '', "No if-statement found for end-if structure at position {$testOffset}");
            }

            if ($this->getErrorCount() === $startupErrorCount) {
                $this->offset = $matches[0][1] + strlen($matches[0][0]);
                return '<?php endif; ?>';
            }

            return false;
        }

        $this->addError(self::ERROR_ENDIF_NO_RDELIM, $testOffset, '', "No right delimiter found for end-if structure at position {$testOffset}", true);

        return false;
    }

    protected function stackPush(string $blockname, int $offset = 0): void
    {
        $blockname = strtolower($blockname);

        if (!isset($this->blockStack[$blockname])) {
            $this->blockStack[$blockname] = [];
        }

        $this->blockStack[$blockname][] = ['offset' => $offset];
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function stackPop(string $blockname): ?array
    {
        $blockname = strtolower($blockname);

        if (!isset($this->blockStack[$blockname])) {
            return null;
        }

        return array_pop($this->blockStack[$blockname]);
    }

    protected function stackCount(string $blockname): int
    {
        return isset($this->blockStack[$blockname]) ? count($this->blockStack[$blockname]) : 0;
    }

    protected function stackCurrentSetValue(string $blockname, string $key, mixed $value): void
    {
        if (isset($this->blockStack[$blockname]) && count($this->blockStack[$blockname]) > 0) {
            $this->blockStack[$blockname][count($this->blockStack[$blockname]) - 1][$key] = $value;
        }
    }

    protected function stackCurrentGetValue(string $blockname, string $key): mixed
    {
        if (!isset($this->blockStack[$blockname])) {
            return null;
        }
        $top = $this->blockStack[$blockname][count($this->blockStack[$blockname]) - 1] ?? null;
        return $top[$key] ?? null;
    }

    /**
     * @throws ParseException When $halt is true.
     */
    protected function addError(int $type, int $offset, string $additional, string $message, bool $halt = false): void
    {
        $this->errorList[] = ['type' => $type, 'offset' => $offset, 'additional' => $additional, 'message' => $message];

        if ($halt) {
            throw new ParseException($message);
        }
    }

    /**
     * @return list<array{type:int,offset:int,additional:string,message:string}>
     */
    public function getErrors(): array
    {
        return $this->errorList;
    }

    public function getErrorCount(): int
    {
        return count($this->errorList);
    }

    public function errorsExist(): bool
    {
        return $this->getErrorCount() > 0;
    }
}
