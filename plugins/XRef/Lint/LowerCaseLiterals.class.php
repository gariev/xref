<?php
/**
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

class XRef_Lint_LowerCaseLiterals extends XRef_ALintPlugin {

    const E_LOWER_CASE_STRING_LITERAL = "XL01";
    const E_UNPREFIXED_CLASS_CONSTANT = "XL02";

    public function getErrorMap() {
        return array(
            self::E_LOWER_CASE_STRING_LITERAL => array(
                "severity" => XRef::WARNING,
                "message" => "Mixed/Lower-case unquoted string literal",
            ),
            self::E_UNPREFIXED_CLASS_CONSTANT => array(
                "severity" => XRef::WARNING,
                "message" => "Possible use of class constant without class prefix",
            ),
        );
    }

    // set of all PHP system constants (SORT_DESC etc)
    // array (constant_name => true)
    protected $system_constants = array();
    // similar array with config-defined (lint.add-constant) constants
    protected $config_constants = array();

    public function __construct() {
        parent::__construct("lint-const-literals", "Lint (use of lower- or mixed-case string literals)");
        foreach (get_defined_constants() as $const_name => $value) {
            $this->system_constants[ $const_name ] = true;
        }
    }

    protected $supportedFileType    = XRef::FILETYPE_PHP;

    public function getReport(XRef_IParsedFile $pf) {
        if ($pf->getFileType() != $this->supportedFileType) {
            return;
        }

        $this->report = array();

        // config_constants are initialized here and not in constructor only for
        // unittest. TODO: make unittest reload plugins after config changes
        $this->config_constants = array();
        foreach (XRef::getConfigValue("lint.add-constant", array()) as $const_name) {
            $this->config_constants[ $const_name ] = true;
        }

        // lower/mixed-case string literals:
        // for (foo=0; $foo<10; )
        // $bar[x]  - is it $bar['x'] or $bar[$x] ?
        $seen_strings = array(); // report every literal once per file, don't be noisy

        $global_constants = array();    // list of all constants defined in global space in this file
        $class_constants = array();     // list of all class constants names defined in this file

        // list of token indexes that contains allowed string literals
        $ignore_tokens = array();

        $tokens = $pf->getTokens();
        $tokens_count = count($tokens);
        for ($i=0; $i<$tokens_count; ++$i) {
            $t = $tokens[$i];

            if (isset($ignore_tokens[$i])) {
                continue;
            }

            // skip namespaces declarations and imports:
            // namespace foo\bar;
            // use foo\bar as foobar;
            if ($t->kind == T_NAMESPACE || $t->kind == T_USE) {
                do {
                    $t = $t->nextNS();
                } while ($t->text != ';' && $t->text != '{' );
                $i = $t->index;
                continue;
            }

            // skip class names completely
            // class Foo extends Bar implements Baz, Qux
            if ($t->kind==T_CLASS || $t->kind==T_INTERFACE || $t->kind==T_TRAIT) {
                do {
                    $t = $t->nextNS();
                } while ($t->text != ';' && $t->text != '{');
                $i = $t->index;
                continue;
            }

            if ($t->kind == T_INSTANCEOF || $t->kind == T_NEW) {
                // ok, class name:
                // $foo instanceof Foo
                // $foo = new Foo;
                do {
                    $t = $t->nextNS();
                } while ($t->kind==T_STRING || $t->kind==T_NS_SEPARATOR);
                $i = $t->index;
                continue;
            }

            if ($t->kind == T_CONST) {
                // const foo = 1, bar = 2;
                $list = $pf->extractList($t->nextNS(), ',', ';');
                foreach ($list as $token) {
                    if ($token->kind != T_STRING) {
                        throw new Exception($token);
                    }
                    // ignore foo and bar in this declaration
                    $ignore_tokens[ $token->index ] = 1;

                    // add their names to list of known constants or class constants
                    if ($pf->getClassAt($token->index)) {
                        $class_constants[ $token->text ] = 1;
                    } else {
                        $global_constants[ $token->text ] = 1;
                    }
                }
                continue;
            }


            if ($t->kind == T_STRING) {

                if (isset($seen_strings[ $t->text ])) {
                    // report each string only once
                    continue;
                }

                // skip know constants defined in this file (const foo=1),
                // system constants (SORT_DESC) and config-defined constants
                if (isset($global_constants[ $t->text ])
                    || isset($this->system_constants[ $t->text ])
                    || isset($this->config_constants[ $t->text ])
                )
                {
                    continue;
                }

                // PHP predefined case-insensitive constants?
                $str_upper = strtoupper($t->text);
                if ($str_upper == "TRUE" || $str_upper == "FALSE" || $str_upper == "NULL") {
                    continue;
                }

                // skip all fully-qualified names (names with namespaces), if any
                // Foo\Bar\Baz
                $n = $t->nextNS();
                while ($n->kind == T_STRING || $n->kind==T_NS_SEPARATOR) {
                    $n = $n->nextNS();
                }

                // add constants defined in this file to list of known strings and don't report them
                //  define('foo', <expression>)
                if ($t->text == 'define') {
                    if ($n->text == '(') {
                        $nn = $n->nextNS();
                        if ($nn->kind == T_CONSTANT_ENCAPSED_STRING) {
                            $string = $nn->text;
                            $nn = $nn->nextNS();
                            if ($nn->text == ',' && strlen($string) > 2) {
                                // remove the first and the last quotes
                                $string = substr($string, 1, strlen($string)-2);
                                $global_constants[ $string ] = 1;
                                continue;
                            }
                        }
                    }
                }

                if ($n->text == '(') {
                    // ok, function call: Foo(...)
                    $i = $n->index;
                    continue;
                }
                if ($n->kind == T_DOUBLE_COLON) {
                    // ok, class name: foo::$something
                    $i = $n->index;
                    continue;
                }
                if ($n->text == ':') {
                    // ok, label (e.g. goto foo; foo: ...);
                    $i = $n->index;
                    continue;
                }

                // some kind of variable declared with class?
                // catch (Foo $x);
                // function bar(Foo\Bar &$x)
                if ($n->text=='&') {
                    $n = $n->nextNS();
                }
                if ($n->kind==T_VARIABLE) {
                    $i = $n->index;
                    continue;
                }

                $p = $t->prevNS();
                if ($p->kind == T_DOUBLE_COLON || $p->kind==T_OBJECT_OPERATOR) {
                    // ok, static or instance member: $bar->foo, Bar::foo
                    continue;
                }
                if ($p->kind == T_GOTO) {
                    // ok, label for goto
                    continue;
                }

                // declare(ticks=1) ?
                if ($t->text=='ticks' || $t->text=='encoding') {
                    if ($p->text == '(') {
                        $pp = $p->prevNS();
                        if (strtolower($pp->text)=='declare') {
                            // ok, skip this
                            continue;
                        }
                    }

                }

                // is it some known class constant used without prefix?
                //  class Foo { const BAR = 1; }
                //  echo BAR; // should be Foo::BAR (or self::BAR inside the class)
                if (isset($class_constants[ $t->text ])) {
                    $this->addDefect($t, self::E_UNPREFIXED_CLASS_CONSTANT);
                    $seen_strings[ $t->text ] = 1;
                    continue;
                }

                if ($t->text == $str_upper) {
                    // ok, all-uppercase, SOME_CONSTANT, I hope
                    continue;
                }


                $this->addDefect($t, self::E_LOWER_CASE_STRING_LITERAL);
                $seen_strings[ $t->text ] = 1;
            }
        }

        return $this->report;
    }
}

// vim: tabstop=4 expandtab
