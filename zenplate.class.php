<?php

/**
 * zenplate -- Simple and fast PHP based template engine
 *
 * Copyright 2008-2009 by Moritz Mertinkat
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


/**
 * zenplate
 *
 * Simple and fast PHP based template engine
 *
 * @author Moritz Mertinkat
 * @version 0.3
 * @license LGPL (http://www.gnu.org/licenses/lgpl.html)
 */
class zenplate {

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
     * zenplate version
     *
     * @var string
     */
    public $version = '0.3';

    /**
     * Left delimiter used for the template tags
     *
     * @var string
     */
    public $left_delimiter = '{';

    /**
     * Right delimiter used for the template tags
     *
     * @var string
     */
    public $right_delimiter = '}';

    /**
     * Names of PHP functions supported in if-statements
     *
     * @var string
     */
    public $supported_if_funcs = array('strlen', 'strtoupper', 'strtolower');


    protected $offset = 0;
    protected $output = '';

    protected $template = '';
    protected $template_length = 0;

    protected $block_stack = array();

    protected $error_list = array();

    protected $db_qstr_regexp = '';
    protected $si_qstr_regexp = '';
    protected $qstr_regexp = '';
    protected $num_const_regexp = '';
    protected $var_bracket_regexp = '';
    protected $var_selector_regexp = '';
    protected $dvar_regexp = '';
    protected $var_regexp = '';
    protected $func_regexp = '';
    protected $mod_regexp = '';

    protected $vars = array();


    /**
     * Constructor
     *
     * @return void
     */
    public function __construct() {

        // matches double quoted strings
        // "foobar"
        // "foo\"bar"
        // "foobar" . "foo\"bar"
        $this->db_qstr_regexp = '"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"';

        // matches single quoted strings
        // 'foobar'
        // 'foo\'bar'
        $this->si_qstr_regexp = '\'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\'';

        // matches single or double quoted strings
        $this->qstr_regexp = '(?:' . $this->db_qstr_regexp . '|' . $this->si_qstr_regexp . ')';

        // matches numerical constants
        // 30
        // -12
        // 13.22
        $this->num_const_regexp = '(?:\-?0[xX][0-9a-fA-F]+|\-?\d+(?:\.\d+)?|\.\d+)';

        // matches bracket portion of vars
        // [0]
        // [%bar] -- not supported yet
        // ["foobar"]
        // ['foobar']
        $this->var_bracket_regexp = '\[(?:\d+|\%?[a-zA-Z0-9_]+|' . $this->qstr_regexp . ')\]';
        $this->var_bracket_regexp = '\[(?:\d+|' . $this->qstr_regexp . ')\]';


        // matches selector portion of vars
        // .foo
        // .bar123
        $this->var_selector_regexp = '\.[a-zA-Z_]+[a-zA-Z0-9_]*';

        // matches direct % vars
        // %foo
        // %foo.bar
        // %foo.bar["foobar"]
        // %foo[0]
        // %foo[%bar] -- not supported yet
        // %foo[5]["foobar"]
        $this->dvar_regexp = '\%[a-zA-Z0-9_]+(?:' . $this->var_bracket_regexp . '|(' . $this->var_selector_regexp . '))*';

        // matches valid variable syntax:
        // %foo
        // 'text'
        // "text"
        // 30
        // -12
        // 12.22
        $this->var_regexp = '(?:' . $this->dvar_regexp . '|' . $this->qstr_regexp . '|' . $this->num_const_regexp . '|true|false)';

        // matches function or block name
        // foo
        // bar123
        // __foo
        $this->func_regexp = '[\w\_][\w\d\_]*';

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
     * Assigns variables
     *
     * @param  mixed $key Key (may also be an associative array in which case $value must not be given)
     * @param  string $value Value
     * @return void
     */
    public function assign($key, $value = NULL) {

        if ($value === NULL && is_array($key)) {
            $this->vars = array_merge($this->vars, $key);
        } else if (is_string($key)) {
            $this->vars[$key] = $value;
        }

    }

    /**
     * Compiles and runs a template
     *
     * @param  string $template Template
     * @return string The parsed template
     * @throws zenplate_compile_exception if there are errors during compilation
     */
    public function parse($template) {

        $output = $this->compile($template);

        if ($output === false) {
            throw new zenplate_compile_exception('Error compiling template; see "get_errors()" for further details');
        }

        ob_start();
        $res = @eval('?>' . $output);

        if ($res === false) {
            throw new zenplate_compile_exception('Error compiling template; this is a serious problem (PHP parse error)');
        }

        return ob_get_clean();

    }

    /**
     * Runs a compiled template
     *
     * @param string $compiled_template Compiled template
     * @return string The parsed template
     * @throws zenplate_execute_exception if the given compiled template has no zenplate header
     */
    public function run($compiled_template) {

        if (strpos($compiled_template, '<?php /* zenplate') === 0) {

            ob_start();
            $res = @eval('?>' . $compiled_template);

            if ($res === false) {
                throw new zenplate_execute_exception('PHP parse error in given template');
            }

            return ob_get_clean();

        } else {
            throw new zenplate_execute_exception('Not a valid zenplate compiled template');
        }

    }

    /**
     * Compiles template
     *
     * @param  string $template Template
     * @return mixed The compiled template if no errors occured, false otherwise (get errors with "get_errors()" method)
     */
    public function compile($template) {

        $this->template = $template;
        $this->template_length = strlen($this->template);
        $this->offset = 0;
        $this->output = '<?php /* zenplate version ' . $this->version . ', created on ' . @strftime("%Y-%m-%d %H:%M:%S %Z") . ' */' . "\n?>\n";
        $this->error_list = array();

        $last_offset = $this->offset;

        try {

            while (($pos = strpos($this->template, $this->left_delimiter, $this->offset)) !== false) {

                // skip block before match and replace php code
                $this->output .= preg_replace('/(<\?\S*)/s', '<?php echo \'\\1\' ?>' . "\n", substr($this->template, $this->offset, $pos - $this->offset));
                $this->offset = $pos;

                $test_offset = $pos + strlen($this->left_delimiter);

                if ($test_offset >= $this->template_length) {
                    break;
                }

                $next_char = $this->template{$test_offset};
                $parsed = false;

                if ($next_char == '%') {
                    $parsed = $this->parse_variable($test_offset);
                } else {
                    $parsed = $this->parse_block_or_function($test_offset);
                }

                if ($parsed !== false) {
                    $this->output .= $parsed;
                } else {
                    // skip block and replace php code
                    $this->output .= preg_replace('/(<\?\S*)/s', '<?php echo \'\\1\' ?>' . "\n", substr($this->template, $this->offset, $test_offset - $this->offset));
                    $this->offset = $test_offset;

                }

                if ($this->offset == $last_offset) {
                    throw new zenplate_compile_exception('Something strange happend...');
                }

                $last_offset = $this->offset;

            }

            // skip block and replace php code
            $this->output .= preg_replace('/(<\?\S*)/s', '<?php echo \'\\1\' ?>' . "\n", substr($this->template, $this->offset));

            foreach ($this->block_stack as $blockname => $stack) {

                if (is_array($stack) && count($stack) > 0) {

                    foreach ($stack as $item) {

                        if ($blockname == 'if') {
                            $this->add_error(self::ERROR_IF_NO_ENDIF, $this->template_length - 1, '', "No closing end-if structure found for if starting at {$item['offset']}");
                        } else {
                            $this->add_error(self::ERROR_BLOCK_NO_ENDIF, $this->template_length - 1, $blockname, "No closing end-{$blockname} structure found for {$blockname} starting at {$item['offset']}");
                        }

                    }

                }

            }

            if (!$this->errors_exist()) {
                return $this->output;
            } else {
                return false;
            }

        }

        catch (zenplate_compile_exception $e) {
            return false;
        }

    }

    /**
     * Parses a variable from template input
     *
     * @param  integer $test_offet Offset to test for correct variable
     * @return mixed Replacement in case everything's alright, false otherwise
     */
    protected function parse_variable($test_offset) {

        if (preg_match('/(' . $this->dvar_regexp . ')' . $this->right_delimiter . '/', $this->template, $matches, PREG_OFFSET_CAPTURE, $test_offset)) {

            if ($matches[0][1] == $test_offset) {

                $this->offset = $test_offset + strlen($matches[0][0]);
                return $this->replace_variable($matches[1][0], false);

            }

            $this->add_error(self::ERROR_VAR_SYNTAX, $test_offset, '', "Error parsing variable expression starting at position {$test_offset}", true);

        }

        return false;

    }

    /**
     * Replaces a variable
     *
     * @param  string $variable String that was matched by $this->dvar_regexp
     * @param  boolean $inline Controls whether or not the result is surrounded by <?php ... ?> tags
     * @return string Replacement
     */
    protected function replace_variable($variable, $inline) {

        if (preg_match_all('/(%[a-zA-Z0-9_]+|' . $this->var_bracket_regexp . '|' . $this->var_selector_regexp . ')/', $variable, $tmp)) {

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
     * @param  integer $test_offet Offset to test for correct variable
     * @return mixed Replacement in case everything's alright, false otherwise
     */
    protected function parse_block_or_function($test_offset) {

        if (preg_match('/(\/?' . $this->func_regexp . ')(\s+|' . $this->right_delimiter . '|$)/', $this->template, $matches, PREG_OFFSET_CAPTURE, $test_offset)) {

            if ($matches[1][1] == $test_offset) {

                $name = strtolower($matches[1][0]);

                if ($name == 'if') {
                    return $this->parse_if($test_offset + 2);
                } else if ($name == 'else') {
                    return $this->parse_else($test_offset + 4);
                } else if ($name == 'elseif') {
                    return $this->parse_if($test_offset + 6, true);
                } else if ($name == '/if') {
                    return $this->parse_endif($test_offset + 3);
                }

            }

        }

        return false;

    }

    /**
     * Parses if-statement
     *
     * @param  integer $test_offet Offset to test for correct variable
     * @param  boolean $elseif Is this an else-if-statement?
     * @return mixed Replacement in case everything's alright, false otherwise
     */
    protected function parse_if($test_offset, $elseif = false) {

        $offset = $test_offset;
        $offset_end = $offset;
        $argument_list = array();
        $rdelim_found = false;
        $parenthesis_open = 0;
        $parenthesis_close = 0;

        if ($elseif) {

            $count = $this->stack_count('if');

            if ($count == 0) {
                $this->add_error(self::ERROR_ELSEIF_NO_IF, $test_offset, '', "No if-statement found for elseif structure at position {$test_offset}");
                return false;
            }

            $else_count = (int)$this->stack_current_get_value('if', 'else');
            if ($else_count != 0) {
                $this->add_error(self::ERROR_ELSE_ONLY_ONE_ALLOWED, $test_offset, '', "Only one else per if-statement is allowed at position {$test_offset}");
            }

            $this->stack_pop('if');

        }

        while (true) {

            /* we don't yet support modifiers:
            if (preg_match('/(?>(' . $this->var_regexp . '(?:' . $this->mod_regexp . '*)?)|(\~|\!|\@)|(!==|===|==|!=|<>|<<|>>|<=|>=|\&\&|\|\||,|\^|\||\&|<|>|\%|\+|\-|\/|\*)|' . preg_quote($this->right_delimiter) . '|\(|\)|=|\b\w+\b|\S+)/', $this->template, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            */

            if (preg_match('/(?>(' . $this->var_regexp . ')|(!==|===|==|!=|<>|<<|>>|<=|>=|\&\&|\|\||,|\^|\||\&|<|>|\%|\+|\-|\/|\*)|(\~|\!|\@)|' . preg_quote($this->right_delimiter) . '|\(|\)|=|\b\w+\b|\S+)/', $this->template, $matches, PREG_OFFSET_CAPTURE, $offset)) {

                $argument = $matches[0][0];

                //echo $argument . "\n";

                if ($argument == $this->right_delimiter) {
                    $rdelim_found = true;
                }

                if ($rdelim_found || $offset > $matches[0][1]) {
                    $offset_end = $matches[0][1] + strlen($argument);
                    break;
                }

                $argument_list[] = array('type' => (isset($matches[4]) ? 'op_unary' : (isset($matches[3]) ? 'op_binary' : (isset($matches[1]) ? 'var' : ''))), 'str' => $argument, 'offset' => $matches[0][1]);

                if ($argument == '(') {
                    $parenthesis_open++;
                } else if ($argument == ')') {
                    $parenthesis_close++;
                }

                $offset = $matches[0][1] + strlen($argument);

            } else {
                break;
            }

        }

        //print_r($argument_list);

        $startup_error_count = $this->get_error_count();

        if (!$rdelim_found) {
            $this->add_error(self::ERROR_IF_NO_RDELIM, $test_offset, '', "No right delimiter found for if statement starting at position {$test_offset}", true);
        }

        $argument_count = count($argument_list);
        if ($argument_count == 0) {
            $this->add_error(self::ERROR_IF_NO_ARGS, $offset, '', "Error parsing if statement starting at position {$offset}", true);
        }

        if ($parenthesis_open != $parenthesis_close) {
            $this->add_error(self::ERROR_IF_UNBALANCED_PARAS, $offset, '', "Unbalanced parenthesis in if statement at position {$offset}", true);
        }

        $last_argument = false;
        $paras_opened = 0;
        $next_allowed = array('var', 'func', 'para_open', 'op_unary');
        $void_paras_allowed = false;

        $if_statement = '';

        for ($i = 0; $i < $argument_count; ++$i) {

            $last_argument = ($i == $argument_count - 1);
            $type = $argument_list[$i]['type'];
            $str = $argument_list[$i]['str'];

            if ($type == 'op_binary') {

                if (!in_array($type, $next_allowed)) {
                    $this->add_error(self::ERROR_IF_OP_NOT_ALLOWED, $argument_list[$i]['offset'], $str, "Operator \"{$str}\" not allowed at position {$argument_list[$i]['offset']}");
                } else if ($last_argument) {
                    $this->add_error(self::ERROR_IF_MISSING_EX_AFTER, $argument_list[$i]['offset'], $str, "Missing expression after \"{$str}\" at position {$argument_list[$i]['offset']}");
                }

                $next_allowed = array('var', 'func', 'para_open', 'op_unary');

            } else if ($argument_list[$i]['type'] == 'var') {

                if (!in_array($type, $next_allowed)) {
                    $this->add_error(self::ERROR_IF_VAR_NOT_ALLOWED, $argument_list[$i]['offset'], '', "Variable not allowed at position {$argument_list[$i]['offset']}");
                }

                if (preg_match('/^' . $this->dvar_regexp . '$/', $str)) {
                    $str = $this->replace_variable($str, true);
                }

                $next_allowed = array('op_binary', 'para_close');

            } else if (preg_match('/^' . $this->func_regexp . '$/', $argument_list[$i]['str'])) {

                $type = 'func';

                if (!in_array($type, $next_allowed)) {
                    $this->add_error(self::ERROR_IF_FUNC_NOT_ALLOWED, $argument_list[$i]['offset'], '', "Function not allowed at position {$argument_list[$i]['offset']}");
                } else if (!in_array($str, $this->supported_if_funcs)) {
                    $this->add_error(self::ERROR_IF_FUNC_NOT_SUPPORTED, $argument_list[$i]['offset'], '', "Unsupported function call at position {$argument_list[$i]['offset']}");
                }

                $void_paras_allowed = true;

                $next_allowed = array('para_open');

            } else if ($argument_list[$i]['type'] == 'op_unary') {

                if (!in_array($type, $next_allowed)) {
                    $this->add_error(self::ERROR_IF_OP_NOT_ALLOWED, $argument_list[$i]['offset'], $str, "Operator \"{$str}\" not allowed at position {$argument_list[$i]['offset']}");
                } else if ($last_argument) {
                    $this->add_error(self::ERROR_IF_MISSING_EX_AFTER, $argument_list[$i]['offset'], $str, "Missing expression after \"{$str}\" at position {$argument_list[$i]['offset']}");
                }

                // do nothing

                $next_allowed = array('var', 'func', 'para_open', 'op_unary');

            } else if ($argument_list[$i]['str'] == '(') {

                $type = 'para_open';

                if (!in_array($type, $next_allowed)) {
                    $this->add_error(self::ERROR_IF_OPEN_PARAS_NOT_ALLOWED, $argument_list[$i]['offset'], '', "Opening parenthesis not allowed at position {$argument_list[$i]['offset']}");
                } else {
                    $paras_opened++;
                }

                $next_allowed = array('var', 'func', 'para_open', 'op_unary');

                if ($void_paras_allowed) {
                    $next_allowed[] = 'para_close';
                    $void_paras_allowed = false;
                }

            } else if ($argument_list[$i]['str'] == ')') {

                $type = 'para_close';

                if (!in_array($type, $next_allowed)) {
                    $this->add_error(self::ERROR_IF_CLOSE_PARAS_NOT_ALLOWED, $argument_list[$i]['offset'], '', "Closing parenthesis not allowed at position {$argument_list[$i]['offset']}");
                } else {

                    if ($paras_opened > 0) {
                        $paras_opened--;
                    } else {
                        $this->add_error(self::ERROR_IF_CLOSE_PARAS_NOT_ALLOWED_NO_OPEN_PARAS_BEFORE, $argument_list[$i]['offset'], '', "No opening parenthesis found before position {$argument_list[$i]['offset']}");
                    }

                }

                $next_allowed = array('op_binary', 'para_close');

            } else {

                if ($argument_list[$i]['str'] == '=') {
                    $this->add_error(self::ERROR_IF_SINGLE_EQUAL_SIGN, $argument_list[$i]['offset'], '', "Should be == at position {$argument_list[$i]['offset']}");
                } else {
                    $this->add_error(self::ERROR_IF_UNKNOWN_EX, $argument_list[$i]['offset'], '', "Unknown expression at position {$argument_list[$i]['offset']}");
                }

            }

            $if_statement .= ($i > 0 ? ' ' : '') . $str;

        }

        if ($this->get_error_count() == $startup_error_count) {

            $this->stack_push('if', $test_offset);
            $this->offset = $offset_end;

            return '<?php ' . ($elseif ? 'else' : '') . 'if (' . $if_statement . '): ?>';

        } else {
            return false;
        }

    }

    /**
     * Parses else-block
     *
     * @param  integer $test_offet Offset to test for correct variable
     * @return mixed Replacement in case everything's alright, false otherwise
     */
    protected function parse_else($test_offset) {

        if (preg_match('/\s*' . $this->right_delimiter . '/', $this->template, $matches, PREG_OFFSET_CAPTURE, $test_offset)) {

            if ($matches[0][1] == $test_offset) {

                $startup_error_count = $this->get_error_count();

                $count = $this->stack_count('if');

                if ($count == 0) {
                    $this->add_error(self::ERROR_ELSE_NO_IF, $test_offset, '', "No if-statement found for else structure at position {$test_offset}");
                }

                $else_count = (int)$this->stack_current_get_value('if', 'else');
                if ($else_count != 0) {
                    $this->add_error(self::ERROR_ELSE_ONLY_ONE_ALLOWED, $test_offset, '', "Only one else per if-statement is allowed at position {$test_offset}");
                } else {
                    $this->stack_current_set_value('if', 'else', 1);
                }

                if ($this->get_error_count() == $startup_error_count) {
                    $this->offset = $matches[0][1] + strlen($matches[0][0]);
                    return '<?php else: ?>';
                } else {
                    return false;
                }

            }

        }

        $this->add_error(self::ERROR_ELSE_NO_RDELIM, $test_offset, '', "No right delimiter found for else structure at position {$test_offset}", true);

    }

    /**
     * Parses end-if block
     *
     * @param  integer $test_offet Offset to test for correct variable
     * @return mixed Replacement in case everything's alright, false otherwise
     */
    protected function parse_endif($test_offset) {

        if (preg_match('/\s*' . $this->right_delimiter . '/', $this->template, $matches, PREG_OFFSET_CAPTURE, $test_offset)) {

            if ($matches[0][1] == $test_offset) {

                $startup_error_count = $this->get_error_count();

                $res = $this->stack_pop('if');

                if ($res === NULL) {
                    $this->add_error(self::ERROR_ENDIF_NO_IF, $test_offset, '', "No if-statement found for end-if structure at position {$test_offset}");
                }

                if ($this->get_error_count() == $startup_error_count) {
                    $this->offset = $matches[0][1] + strlen($matches[0][0]);
                    return '<?php endif; ?>';
                } else {
                    return false;
                }

            }

        }

        $this->add_error(self::ERROR_ENDIF_NO_RDELIM, $test_offset, '', "No right delimiter found for end-if structure at position {$test_offset}", true);

    }

    /**
     * Pushes an element of type blockname on the stack (associated with an offset, if given)
     *
     * @param  string $blockname Type of element to push on the stack
     * @param  integer $offet Offset
     * @return void
     */
    protected function stack_push($blockname, $offset = 0) {

        $blockname = strtolower($blockname);

        if (!isset($this->block_stack[$blockname])) {
            $this->block_stack[$blockname] = array();
        }

        $this->block_stack[$blockname][] = array('offset' => $offset);

    }

    /**
     * Pops an element of by blockname off the stack
     *
     * @param  string $blockname Type of element to push off the stack
     * @return array Associative array with at least "offset" as key
     */
    protected function stack_pop($blockname) {

        $blockname = strtolower($blockname);

        if (!isset($this->block_stack[$blockname])) {
            return NULL;
        } else {
            return array_pop($this->block_stack[$blockname]);
        }

    }

    /**
     * Counts how many elements there are on the stack of type blockname
     *
     * @param  string $blockname Type of elements to count
     * @return integer Count
     */
    protected function stack_count($blockname) {
        return isset($this->block_stack[$blockname]) ? count($this->block_stack[$blockname]) : 0;
    }

    /**
     * Sets a value associated with the current element of type blockname
     *
     * @param  string $blockname Type of element
     * @param  string $key Key
     * @param  string $value Value
     * @return void
     */
    protected function stack_current_set_value($blockname, $key, $value) {

        if (isset($this->block_stack[$blockname]) && count($this->block_stack[$blockname]) > 0) {
            $this->block_stack[$blockname][count($this->block_stack[$blockname]) - 1][$key] = $value;
        }

    }

    /**
     * Gets a value associated with the current element of type blockname
     *
     * @param  string $blockname Type of element
     * @param  string $key Key
     * @return mixed Value of the corresponding key if there's one, NULL otherwise
     */
    protected function stack_current_get_value($blockname, $key) {
        return isset($this->block_stack[$blockname]) && isset($this->block_stack[$blockname][count($this->block_stack[$blockname]) - 1][$key]) ? $this->block_stack[$blockname][count($this->block_stack[$blockname]) - 1][$key] : NULL;
    }

    /**
     * Adds a compile error to the internal list of errors
     *
     * @param  integer $type Type of error (see ERROR_* consts)
     * @param  integer $offset Offset where the error occured, if applicable
     * @param  string $additional Additional string that can be used along with the message (i.e. the token that caused the error)
     * @param  string $message Default error message in english
     * @param  boolean $halt Halt the compiler? (true to halt)
     * @return void
     */
    protected function add_error($type, $offset, $addtional, $message, $halt = false) {

        $this->error_list[] = array('type' => $type, 'offset' => $offset, 'additional' => $addtional, 'message' => $message);

        if ($halt) {
            throw new zenplate_compile_exception($message);
        }

    }

    /**
     * Returns a list of errors
     *
     * @return array List of errors (List of associative arrays with keys "type", "offset", "additional" and "message")
     */
    public function get_errors() {
        return $this->error_list;
    }

    /**
     * Returns number of errors
     *
     * @return integer Number of errors
     */
    public function get_error_count() {
        return count($this->error_list);
    }

    /**
     * Checks for errors
     *
     * @return boolean True if any errors occured, false otherwise
     */
    public function errors_exist() {
        return ($this->get_error_count() > 0);
    }

}


/**
 * zenplate_compile_exception
 *
 * Exception class for zenplate derived classes
 *
 * @author Moritz Mertinkat
 * @version 0.3
 * @license LGPL (http://www.gnu.org/licenses/lgpl.html)
 */
class zenplate_compile_exception extends Exception {
}


/**
 * zenplate_execute_exception
 *
 * Exception class for zenplate derived classes
 *
 * @author Moritz Mertinkat
 * @version 0.3
 * @license LGPL (http://www.gnu.org/licenses/lgpl.html)
 */
class zenplate_execute_exception extends Exception {
}
