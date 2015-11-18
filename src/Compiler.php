<?php

/**
 * Zenplate -- Simple and fast PHP based template engine
 *
 * Copyright 2008-2015 by Moritz Fain
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

/**
 * Zenplate compiler
 *
 * Simple and fast PHP based template engine
 *
 * @author Moritz Fain
 * @version 0.4
 * @license LGPL (http://www.gnu.org/licenses/lgpl.html)
 */
class Compiler
{
    /**
     * Error constants
     */
    const ERROR_VAR_SYNTAX = 1;

    const ERROR_IF_NO_RDELIM = 2;
    const ERROR_IF_NO_ARGS = 3;
    const ERROR_IF_UNBALANCED_PARAS = 4;
    const ERROR_IF_OP_NOT_ALLOWED = 5;
    const ERROR_IF_MISSING_EX_AFTER = 6;
    const ERROR_IF_VAR_NOT_ALLOWED = 7;
    const ERROR_IF_FUNC_NOT_ALLOWED = 8;
    const ERROR_IF_FUNC_NOT_SUPPORTED = 9;
    const ERROR_IF_OPEN_PARAS_NOT_ALLOWED = 10;
    const ERROR_IF_CLOSE_PARAS_NOT_ALLOWED = 11;
    const ERROR_IF_CLOSE_PARAS_NOT_ALLOWED_NO_OPEN_PARAS_BEFORE = 12;
    const ERROR_IF_SINGLE_EQUAL_SIGN = 13;
    const ERROR_IF_UNKNOWN_EX = 14;
    const ERROR_IF_NO_ENDIF = 15;

    const ERROR_ELSE_NO_RDELIM = 16;
    const ERROR_ELSE_NO_IF = 17;
    const ERROR_ELSE_ONLY_ONE_ALLOWED = 18;

    const ERROR_ENDIF_NO_RDELIM = 19;
    const ERROR_ENDIF_NO_IF = 20;

    const ERROR_ELSEIF_NO_IF = 21;

    const ERROR_BLOCK_NO_ENDBLOCK = 40;


    /**
     * Zenplate version
     *
     * @var string
     */
    public $version = '0.4';

    /**
     * Left delimiter used for the template tags
     *
     * @var string
     */
    public $leftDelimiter = '{';

    /**
     * Right delimiter used for the template tags
     *
     * @var string
     */
    public $rightDelimiter = '}';

    /**
     * Names of PHP functions supported in if-statements
     *
     * @var string
     */
    public $supportedIfFuncs = ['strlen', 'strtoupper', 'strtolower'];

    protected $offset = 0;
    protected $output = '';

    protected $template = '';
    protected $templateLength = 0;

    protected $blockStack = [];

    protected $errorList = [];

    protected $dbQstrRegexp = '';
    protected $siQstrRegexp = '';
    protected $qstrRegexp = '';
    protected $numConstRegexp = '';
    protected $varBracketRegexp = '';
    protected $varSelectorRegexp = '';
    protected $dvarRegexp = '';
    protected $varRegexp = '';
    protected $funcRegexp = '';
    protected $modRegexp = '';

    /**
     * Constructor
     */
    public function __construct() {

        // matches double quoted strings
        // "foobar"
        // "foo\"bar"
        // "foobar" . "foo\"bar"
        $this->dbQstrRegexp = '"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"';

        // matches single quoted strings
        // 'foobar'
        // 'foo\'bar'
        $this->siQstrRegexp = '\'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\'';

        // matches single or double quoted strings
        $this->qstrRegexp = '(?:' . $this->dbQstrRegexp . '|' . $this->siQstrRegexp . ')';

        // matches numerical constants
        // 30
        // -12
        // 13.22
        $this->numConstRegexp = '(?:\-?0[xX][0-9a-fA-F]+|\-?\d+(?:\.\d+)?|\.\d+)';

        // matches bracket portion of vars
        // [0]
        // [%bar] -- not supported yet
        // ["foobar"]
        // ['foobar']
        $this->varBracketRegexp = '\[(?:\d+|\%?[a-zA-Z0-9_]+|' . $this->qstrRegexp . ')\]';
        $this->varBracketRegexp = '\[(?:\d+|' . $this->qstrRegexp . ')\]';


        // matches selector portion of vars
        // .foo
        // .bar123
        $this->varSelectorRegexp = '\.[a-zA-Z_]+[a-zA-Z0-9_]*';

        // matches direct % vars
        // %foo
        // %foo.bar
        // %foo.bar["foobar"]
        // %foo[0]
        // %foo[%bar] -- not supported yet
        // %foo[5]["foobar"]
        $this->dvarRegexp = '\%[a-zA-Z0-9_]+(?:' . $this->varBracketRegexp . '|(' . $this->varSelectorRegexp . '))*';

        // matches valid variable syntax:
        // %foo
        // 'text'
        // "text"
        // 30
        // -12
        // 12.22
        $this->varRegexp = '(?:' . $this->dvarRegexp . '|' . $this->qstrRegexp . '|' . $this->numConstRegexp . '|true|false)';

        // matches function or block name
        // foo
        // bar123
        // __foo
        $this->funcRegexp = '[\w\_][\w\d\_]*';

        /* not yet supported

        // matches valid modifier syntax:
        // |foo
        // |@foo
        // |foo:"bar"
        // |foo:%bar
        // |foo:"bar":%foobar
        // |foo|bar
        $this->mod_regexp = '(?:\|@?[0-9a-zA-Z_]+(?::(?>-?\w+|' . $this->dvar_regexp . '|' . $this->qstr_regexp .'))*)';

        */
    }

    /**
     * Compiles template
     *
     * @param  string $template Template
     * @throws \RuntimeException
     * @return mixed The compiled template if no errors occured, false otherwise (get errors with "get_errors()" method)
     */
    public function compile($template)
    {
        $this->template = $template;
        $this->templateLength = strlen($this->template);
        $this->offset = 0;
        $this->output = '<?php /* zenplate version ' . $this->version . ', created on ' . @strftime("%Y-%m-%d %H:%M:%S %Z") . ' */' . "\n?>\n";
        $this->errorList = [];

        $lastOffset = $this->offset;

        try {

            while (($pos = strpos($this->template, $this->leftDelimiter, $this->offset)) !== false) {

                // skip block before match and replace php code
                $this->output .= preg_replace('/(<\?\S*)/s', '<?php echo \'\\1\' ?>' . "\n", substr($this->template, $this->offset, $pos - $this->offset));
                $this->offset = $pos;

                $testOffset = $pos + strlen($this->leftDelimiter);

                if ($testOffset >= $this->templateLength) {
                    break;
                }

                $nextChar = $this->template[$testOffset];

                if ($nextChar == '%') {
                    $parsed = $this->parseVariable($testOffset);
                } else {
                    $parsed = $this->parseBlockOrFunction($testOffset);
                }

                if ($parsed !== false) {
                    $this->output .= $parsed;
                } else {
                    // skip block and replace php code
                    $this->output .= preg_replace('/(<\?\S*)/s', '<?php echo \'\\1\' ?>' . "\n", substr($this->template, $this->offset, $testOffset - $this->offset));
                    $this->offset = $testOffset;

                }

                if ($this->offset == $lastOffset) {
                    throw new \RuntimeException('Main parser loop is broken');
                }

                $lastOffset = $this->offset;

            }

            // skip block and replace php code
            $this->output .= preg_replace('/(<\?\S*)/s', '<?php echo \'\\1\' ?>' . "\n", substr($this->template, $this->offset));

            foreach ($this->blockStack as $blockname => $stack) {

                if (is_array($stack) && count($stack) > 0) {

                    foreach ($stack as $item) {

                        if ($blockname == 'if') {
                            $this->addError(self::ERROR_IF_NO_ENDIF, $this->templateLength - 1, '', "No closing end-if structure found for if starting at {$item['offset']}");
                        } else {
                            $this->addError(self::ERROR_BLOCK_NO_ENDBLOCK, $this->templateLength - 1, $blockname, "No closing end-{$blockname} structure found for {$blockname} starting at {$item['offset']}");
                        }

                    }

                }

            }

            if (!$this->errorsExist()) {
                return $this->output;
            } else {
                return false;
            }

        }

        catch (ParseException $e) {
            return false;
        }
    }

    /**
     * Parses a variable from template input
     *
     * @param  integer $testOffset Offset to test for correct variable
     * @return mixed Replacement in case everything's alright, false otherwise
     */
    protected function parseVariable($testOffset)
    {
        if (preg_match('/(' . $this->dvarRegexp . ')' . $this->rightDelimiter . '/', $this->template, $matches, PREG_OFFSET_CAPTURE, $testOffset)) {

            if ($matches[0][1] == $testOffset) {

                $this->offset = $testOffset + strlen($matches[0][0]);
                return $this->replaceVariable($matches[1][0], false);

            }

            $this->addError(self::ERROR_VAR_SYNTAX, $testOffset, '', "Error parsing variable expression starting at position {$testOffset}", true);

        }

        return false;
    }

    /**
     * Replaces a variable
     *
     * @param  string $variable String that was matched by $this->dvarRegexp
     * @param  boolean $inline Controls whether or not the result is surrounded by <?php ... ?> tags
     * @return string Replacement
     */
    protected function replaceVariable($variable, $inline)
    {
        if (preg_match_all('/(%[a-zA-Z0-9_]+|' . $this->varBracketRegexp . '|' . $this->varSelectorRegexp . ')/', $variable, $tmp)) {

            $output = '';

            for ($i = 0, $max = count($tmp[1]); $i < $max; ++$i) {

                if ($i == 0) {
                    $output .= '$this->vars[\'' . substr($tmp[1][$i], 1) . '\']';
                } else if ($tmp[1][$i] != '' && $tmp[1][$i]{0} == '.') {
                    $output .= '[\'' . substr($tmp[1][$i], 1) . '\']';
                } else if ($tmp[1][$i] != '' && $tmp[1][$i]{0} == '[') {
                    $output .= $tmp[1][$i];
                }

            }

            $output = '(empty(' . $output . ') ? \'\' : ' . $output . ')';

            if ($inline) {
                return $output;
            } else {
                return '<?php echo ' . $output . '; ?>' . "\n";
            }

        } else {
            return $variable;
        }
    }

    /**
     * Parses a block or a function call
     *
     * @param  integer $testOffset Offset to test for correct variable
     * @return mixed Replacement in case everything's alright, false otherwise
     */
    protected function parseBlockOrFunction($testOffset)
    {
        if (preg_match('/(\/?' . $this->funcRegexp . ')(\s+|' . $this->rightDelimiter . '|$)/', $this->template, $matches, PREG_OFFSET_CAPTURE, $testOffset)) {

            if ($matches[1][1] == $testOffset) {

                $name = strtolower($matches[1][0]);

                if ($name == 'if') {
                    return $this->parseIf($testOffset + 2);
                } else if ($name == 'else') {
                    return $this->parseElse($testOffset + 4);
                } else if ($name == 'elseif') {
                    return $this->parseIf($testOffset + 6, true);
                } else if ($name == '/if') {
                    return $this->parseEndif($testOffset + 3);
                }

            }

        }

        return false;
    }

    /**
     * Parses if-statement
     *
     * @param  integer $testOffset Offset to test for correct variable
     * @param  boolean $elseIf Is this an else-if-statement?
     * @return mixed Replacement in case everything's alright, false otherwise
     */
    protected function parseIf($testOffset, $elseIf = false)
    {
        $offset = $testOffset;
        $offsetEnd = $offset;
        $argumentList = [];
        $rdelimFound = false;
        $parenthesisOpen = 0;
        $parenthesisClose = 0;

        if ($elseIf) {

            $count = $this->stackCount('if');

            if ($count == 0) {
                $this->addError(self::ERROR_ELSEIF_NO_IF, $testOffset, '', "No if-statement found for elseif structure at position {$testOffset}");
                return false;
            }

            $elseCount = (int)$this->stackCurrentGetValue('if', 'else');
            if ($elseCount != 0) {
                $this->addError(self::ERROR_ELSE_ONLY_ONE_ALLOWED, $testOffset, '', "Only one else per if-statement is allowed at position {$testOffset}");
            }

            $this->stackPop('if');

        }

        while (true) {

            /* we don't yet support modifiers:
            $regexp = '/(?>(' . $this->varRegexp . '(?:' . $this->modRegexp . '*)?)|(\~|\!|\@)|(!==|===|==|!=|<>|<<|>>|<=|>=|\&\&|\|\||,|\^|\||\&|<|>|\%|\+|\-|\/|\*)|' . preg_quote($this->rightDelimiter) . '|\(|\)|=|\b\w+\b|\S+)/';
            */
            $regexp = '/(?>(' . $this->varRegexp . ')|(!==|===|==|!=|<>|<<|>>|<=|>=|\&\&|\|\||,|\^|\||\&|<|>|\%|\+|\-|\/|\*)|(\~|\!|\@)|' . preg_quote($this->rightDelimiter) . '|\(|\)|=|\b\w+\b|\S+)/';
            if (preg_match($regexp, $this->template, $matches, PREG_OFFSET_CAPTURE, $offset)) {

                $argument = $matches[0][0];

                if ($argument == $this->rightDelimiter) {
                    $rdelimFound = true;
                }

                if ($rdelimFound || $offset > $matches[0][1]) {
                    $offsetEnd = $matches[0][1] + strlen($argument);
                    break;
                }

                $argumentList[] = ['type' => (isset($matches[4]) ? 'op_unary' : (isset($matches[3]) ? 'op_binary' : (isset($matches[1]) ? 'var' : ''))), 'str' => $argument, 'offset' => $matches[0][1]];

                if ($argument == '(') {
                    $parenthesisOpen++;
                } else if ($argument == ')') {
                    $parenthesisClose++;
                }

                $offset = $matches[0][1] + strlen($argument);

            } else {
                break;
            }

        }

        $startupErrorCount = $this->getErrorCount();

        if (!$rdelimFound) {
            $this->addError(self::ERROR_IF_NO_RDELIM, $testOffset, '', "No right delimiter found for if statement starting at position {$testOffset}", true);
        }

        $argumentCount = count($argumentList);
        if ($argumentCount == 0) {
            $this->addError(self::ERROR_IF_NO_ARGS, $offset, '', "Error parsing if statement starting at position {$offset}", true);
        }

        if ($parenthesisOpen != $parenthesisClose) {
            $this->addError(self::ERROR_IF_UNBALANCED_PARAS, $offset, '', "Unbalanced parenthesis in if statement at position {$offset}", true);
        }

        $parasOpened = 0;
        $nextAllowed = ['var', 'func', 'para_open', 'op_unary'];
        $voidParasAllowed = false;

        $ifStatement = '';

        for ($i = 0; $i < $argumentCount; ++$i) {

            $lastArgument = ($i == $argumentCount - 1);
            $type = $argumentList[$i]['type'];
            $str = $argumentList[$i]['str'];

            if ($type == 'op_binary') {

                if (!in_array($type, $nextAllowed)) {
                    $this->addError(self::ERROR_IF_OP_NOT_ALLOWED, $argumentList[$i]['offset'], $str, "Operator \"{$str}\" not allowed at position {$argumentList[$i]['offset']}");
                } else if ($lastArgument) {
                    $this->addError(self::ERROR_IF_MISSING_EX_AFTER, $argumentList[$i]['offset'], $str, "Missing expression after \"{$str}\" at position {$argumentList[$i]['offset']}");
                }

                $nextAllowed = ['var', 'func', 'para_open', 'op_unary'];

            } else if ($argumentList[$i]['type'] == 'var') {

                if (!in_array($type, $nextAllowed)) {
                    $this->addError(self::ERROR_IF_VAR_NOT_ALLOWED, $argumentList[$i]['offset'], '', "Variable not allowed at position {$argumentList[$i]['offset']}");
                }

                if (preg_match('/^' . $this->dvarRegexp . '$/', $str)) {
                    $str = $this->replaceVariable($str, true);
                }

                $nextAllowed = ['op_binary', 'para_close'];

            } else if (preg_match('/^' . $this->funcRegexp . '$/', $argumentList[$i]['str'])) {

                $type = 'func';

                if (!in_array($type, $nextAllowed)) {
                    $this->addError(self::ERROR_IF_FUNC_NOT_ALLOWED, $argumentList[$i]['offset'], '', "Function not allowed at position {$argumentList[$i]['offset']}");
                } else if (!in_array($str, $this->supportedIfFuncs)) {
                    $this->addError(self::ERROR_IF_FUNC_NOT_SUPPORTED, $argumentList[$i]['offset'], '', "Unsupported function call at position {$argumentList[$i]['offset']}");
                }

                $voidParasAllowed = true;

                $nextAllowed = ['para_open'];

            } else if ($argumentList[$i]['type'] == 'op_unary') {

                if (!in_array($type, $nextAllowed)) {
                    $this->addError(self::ERROR_IF_OP_NOT_ALLOWED, $argumentList[$i]['offset'], $str, "Operator \"{$str}\" not allowed at position {$argumentList[$i]['offset']}");
                } else if ($lastArgument) {
                    $this->addError(self::ERROR_IF_MISSING_EX_AFTER, $argumentList[$i]['offset'], $str, "Missing expression after \"{$str}\" at position {$argumentList[$i]['offset']}");
                }

                // do nothing

                $nextAllowed = ['var', 'func', 'para_open', 'op_unary'];

            } else if ($argumentList[$i]['str'] == '(') {

                $type = 'para_open';

                if (!in_array($type, $nextAllowed)) {
                    $this->addError(self::ERROR_IF_OPEN_PARAS_NOT_ALLOWED, $argumentList[$i]['offset'], '', "Opening parenthesis not allowed at position {$argumentList[$i]['offset']}");
                } else {
                    $parasOpened++;
                }

                $nextAllowed = ['var', 'func', 'para_open', 'op_unary'];

                if ($voidParasAllowed) {
                    $nextAllowed[] = 'para_close';
                    $voidParasAllowed = false;
                }

            } else if ($argumentList[$i]['str'] == ')') {

                $type = 'para_close';

                if (!in_array($type, $nextAllowed)) {
                    $this->addError(self::ERROR_IF_CLOSE_PARAS_NOT_ALLOWED, $argumentList[$i]['offset'], '', "Closing parenthesis not allowed at position {$argumentList[$i]['offset']}");
                } else {

                    if ($parasOpened > 0) {
                        $parasOpened--;
                    } else {
                        $this->addError(self::ERROR_IF_CLOSE_PARAS_NOT_ALLOWED_NO_OPEN_PARAS_BEFORE, $argumentList[$i]['offset'], '', "No opening parenthesis found before position {$argumentList[$i]['offset']}");
                    }

                }

                $nextAllowed = ['op_binary', 'para_close'];

            } else {

                if (in_array('op_binary', $nextAllowed) && $argumentList[$i]['str'] == '=') {

                    // this is kind of a hack: in case of binary comparison with only one '=' we correct the issue, trace back but still report an error
                    $argumentList[$i]['type'] = 'op_binary';
                    $argumentList[$i]['str'] = '==';
                    $this->addError(self::ERROR_IF_SINGLE_EQUAL_SIGN, $argumentList[$i]['offset'], '', "Should be == at position {$argumentList[$i]['offset']}");
                    $startupErrorCount++;
                    $i--;

                    continue;

                } else {
                    $this->addError(self::ERROR_IF_UNKNOWN_EX, $argumentList[$i]['offset'], '', "Unknown expression at position {$argumentList[$i]['offset']}");
                }

            }

            $ifStatement .= ($i > 0 ? ' ' : '') . $str;

        }

        if ($this->getErrorCount() == $startupErrorCount) {

            $this->stackPush('if', $testOffset);
            $this->offset = $offsetEnd;

            return '<?php ' . ($elseIf ? 'else' : '') . 'if (' . $ifStatement . '): ?>';

        } else {
            return false;
        }
    }

    /**
     * Parses else-block
     *
     * @param  integer $testOffset Offset to test for correct variable
     * @return mixed Replacement in case everything's alright, false otherwise
     */
    protected function parseElse($testOffset)
    {
        if (preg_match('/\s*' . $this->rightDelimiter . '/', $this->template, $matches, PREG_OFFSET_CAPTURE, $testOffset)) {

            if ($matches[0][1] == $testOffset) {

                $startupErrorCount = $this->getErrorCount();

                $count = $this->stackCount('if');

                if ($count == 0) {
                    $this->addError(self::ERROR_ELSE_NO_IF, $testOffset, '', "No if-statement found for else structure at position {$testOffset}");
                }

                $elseCount = (int)$this->stackCurrentGetValue('if', 'else');
                if ($elseCount != 0) {
                    $this->addError(self::ERROR_ELSE_ONLY_ONE_ALLOWED, $testOffset, '', "Only one else per if-statement is allowed at position {$testOffset}");
                } else {
                    $this->stackCurrentSetValue('if', 'else', 1);
                }

                if ($this->getErrorCount() == $startupErrorCount) {
                    $this->offset = $matches[0][1] + strlen($matches[0][0]);
                    return '<?php else: ?>';
                }

            }

        }

        $this->addError(self::ERROR_ELSE_NO_RDELIM, $testOffset, '', "No right delimiter found for else structure at position {$testOffset}", true);

        return false;
    }

    /**
     * Parses end-if block
     *
     * @param  integer $testOffset Offset to test for correct variable
     * @return mixed Replacement in case everything's alright, false otherwise
     */
    protected function parseEndif($testOffset)
    {
        if (preg_match('/\s*' . $this->rightDelimiter . '/', $this->template, $matches, PREG_OFFSET_CAPTURE, $testOffset)) {

            if ($matches[0][1] == $testOffset) {

                $startupErrorCount = $this->getErrorCount();

                $res = $this->stackPop('if');

                if ($res === null) {
                    $this->addError(self::ERROR_ENDIF_NO_IF, $testOffset, '', "No if-statement found for end-if structure at position {$testOffset}");
                }

                if ($this->getErrorCount() == $startupErrorCount) {
                    $this->offset = $matches[0][1] + strlen($matches[0][0]);
                    return '<?php endif; ?>';
                }

            }

        }

        $this->addError(self::ERROR_ENDIF_NO_RDELIM, $testOffset, '', "No right delimiter found for end-if structure at position {$testOffset}", true);

        return false;
    }

    /**
     * Pushes an element of type <blockname> onto the stack (associated with an offset, if given)
     *
     * @param  string $blockname Type of element to push onto the stack
     * @param  integer $offset Offset
     * @return void
     */
    protected function stackPush($blockname, $offset = 0)
    {
        $blockname = strtolower($blockname);

        if (!isset($this->blockStack[$blockname])) {
            $this->blockStack[$blockname] = [];
        }

        $this->blockStack[$blockname][] = ['offset' => $offset];
    }

    /**
     * Pops an element of type <blockname> off the stack
     *
     * @param  string $blockname Type of element to push off the stack
     * @return array Associative array with at least "offset" as key
     */
    protected function stackPop($blockname)
    {
        $blockname = strtolower($blockname);

        if (!isset($this->blockStack[$blockname])) {
            return null;
        }

        return array_pop($this->blockStack[$blockname]);
    }

    /**
     * Counts how many elements there are on the stack of type <blockname>
     *
     * @param  string $blockname Type of elements to count
     * @return integer Count
     */
    protected function stackCount($blockname)
    {
        return isset($this->blockStack[$blockname]) ? count($this->blockStack[$blockname]) : 0;
    }

    /**
     * Sets a value associated with the current element of type <blockname>
     *
     * @param  string $blockname Type of element
     * @param  string $key Key
     * @param  string $value Value
     * @return void
     */
    protected function stackCurrentSetValue($blockname, $key, $value)
    {
        if (isset($this->blockStack[$blockname]) && count($this->blockStack[$blockname]) > 0) {
            $this->blockStack[$blockname][count($this->blockStack[$blockname]) - 1][$key] = $value;
        }
    }

    /**
     * Gets a value associated with the current element of type <blockname>
     *
     * @param  string $blockname Type of element
     * @param  string $key Key
     * @return mixed Value of the corresponding key if there's one, null otherwise
     */
    protected function stackCurrentGetValue($blockname, $key)
    {
        return isset($this->blockStack[$blockname]) && isset($this->blockStack[$blockname][count($this->blockStack[$blockname]) - 1][$key]) ? $this->blockStack[$blockname][count($this->blockStack[$blockname]) - 1][$key] : null;
    }

    /**
     * Adds a compile error to the internal list of errors
     *
     * @param  integer $type Type of error (see ERROR_* consts)
     * @param  integer $offset Offset where the error occured, if applicable
     * @param  string $additional Additional string that can be used along with the message (i.e. the token that caused the error)
     * @param  string $message Default error message in english
     * @param  boolean $halt Halt the compiler? (true to halt)
     * @throws ParseException
     * @return void
     */
    protected function addError($type, $offset, $additional, $message, $halt = false)
    {
        $this->errorList[] = ['type' => $type, 'offset' => $offset, 'additional' => $additional, 'message' => $message];

        if ($halt) {
            throw new ParseException($message);
        }
    }

    /**
     * Returns a list of errors
     *
     * @return array List of errors (List of associative arrays with keys "type", "offset", "additional" and "message")
     */
    public function getErrors()
    {
        return $this->errorList;
    }

    /**
     * Returns number of errors
     *
     * @return integer Number of errors
     */
    public function getErrorCount()
    {
        return count($this->errorList);
    }

    /**
     * Checks for errors
     *
     * @return boolean True if any errors occured, false otherwise
     */
    public function errorsExist()
    {
        return ($this->getErrorCount() > 0);
    }
}
