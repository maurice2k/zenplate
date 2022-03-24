<?php

/**
 * Zenplate -- Simple and fast PHP based template engine
 *
 * Copyright 2008-2016 by Moritz Fain
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

use Maurice\Zenplate\Exception\ExecuteException;

/**
 * Zenplate runner
 *
 * Simple and fast PHP based template engine
 *
 * @author Moritz Fain
 * @version 0.4.1
 * @license LGPL (http://www.gnu.org/licenses/lgpl.html)
 */
class Runner
{
    /**
     * Assigned variables
     *
     * @var array
     */
    protected $vars = [];

    /**
     * Assigns variables
     *
     * @param  mixed $key Key (may also be an associative array in which case $value must not be given)
     * @param  string $value Value
     * @return void
     */
    public function assign($key, $value = null)
    {
        if ($value === null && is_array($key)) {
            $this->vars = array_merge($this->vars, $key);
        } else if (is_string($key)) {
            $this->vars[$key] = $value;
        }
    }

    /**
     * Compiles and runs a template
     *
     * @param  string $template Template
     * @return string The parsed template with variables replaced
     * @throws ExecuteException if there are errors during compilation
     */
    public function run($template)
    {
        $compiler = new Compiler();
        $output = $compiler->compile((string)$template);

        if ($output === false) {
            $errorMessages = implode(', ', array_map(function($item) {return $item['message'];}, $compiler->getErrors()));
            throw new ExecuteException('Error compiling template; error messages: ' . $errorMessages);
        }

        ob_start();
        $res = @eval('?>' . $output);

        if ($res === false) {
            throw new ExecuteException('Error running template; this is a serious problem (PHP parse error)');
        }

        return ob_get_clean();
    }

    /**
     * Runs a compiled template
     *
     * @param string $compiledTemplate Compiled template
     * @return string The parsed template with variables replaced
     * @throws ExecuteException if the given compiled template has no zenplate header
     */
    public function runCompiled($compiledTemplate)
    {
        if (strpos($compiledTemplate, '<?php /* zenplate') === 0) {

            ob_start();
            $res = @eval('?>' . $compiledTemplate);

            if ($res === false) {
                throw new ExecuteException('Error running template; this is a serious problem (PHP parse error)');
            }

            return ob_get_clean();

        } else {
            throw new ExecuteException('Not a valid zenplate compiled template!');
        }
    }
}
