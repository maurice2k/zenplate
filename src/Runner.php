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

use Maurice\Zenplate\Exception\ExecuteException;

class Runner
{
    /** @var array<string,mixed> */
    protected array $vars = [];

    private ?Compiler $compiler = null;

    /**
     * Assign one or many template variables.
     *
     * @param string|array<string,mixed> $key   A single variable name, or an associative array of name => value.
     * @param mixed                      $value Ignored when $key is an array.
     */
    public function assign(string|array $key, mixed $value = null): void
    {
        if (is_array($key)) {
            $this->vars = array_merge($this->vars, $key);
            return;
        }

        $this->vars[$key] = $value;
    }

    /**
     * Compile and run a template.
     *
     * @throws ExecuteException When compilation fails or the generated PHP throws.
     */
    public function run(string $template): string
    {
        $compiler = $this->compiler ??= new Compiler();
        $output = $compiler->compile($template);

        if ($output === false) {
            $errorMessages = implode(', ', array_map(static fn(array $item): string => $item['message'], $compiler->getErrors()));
            throw new ExecuteException('Error compiling template; error messages: ' . $errorMessages);
        }

        return $this->evaluate($output);
    }

    /**
     * Run a previously compiled template (output of Compiler::compile()).
     *
     * @throws ExecuteException When the input lacks a Zenplate header or eval throws.
     */
    public function runCompiled(string $compiledTemplate): string
    {
        if (!str_starts_with($compiledTemplate, '<?php /* zenplate')) {
            throw new ExecuteException('Not a valid zenplate compiled template!');
        }

        return $this->evaluate($compiledTemplate);
    }

    /**
     * @throws ExecuteException
     */
    private function evaluate(string $compiled): string
    {
        ob_start();
        try {
            eval('?>' . $compiled);
        } catch (\ParseError $e) {
            ob_end_clean();
            throw new ExecuteException('Error running template; PHP parse error: ' . $e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw new ExecuteException('Error running template: ' . $e->getMessage(), 0, $e);
        }

        return (string)ob_get_clean();
    }
}
