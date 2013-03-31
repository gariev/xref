<?php
/**
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

class XRef_Lint_LowerCaseLiterals extends XRef_ALintPlugin {

    public function __construct() {
        parent::__construct("lint-const-literals", "Lint (use of lower- or mixed-case string literals)");
    }

    protected $supportedFileType    = XRef::FILETYPE_PHP;

    public function getReport(XRef_IParsedFile $pf) {
        if ($pf->getFileType() != $this->supportedFileType) {
            return;
        }

        $this->report = array();

        // lower/mixed-case string literals:
        // for (foo=0; $foo<10; )
        // $bar[x]  - is it $bar['x'] or $bar[$x] ?
        $seen_strings = array(); // report every literal once per file, don't be noisy

        // list of token indexes that contains allowed string literals
        $ignore_tokens = array();

        $tokens = $pf->getTokens();
        for ($i=0; $i<count($tokens); ++$i) {
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

                    // and add their names to list of known constants,
                    // unless they are class constants and
                    // will be prefixed by class name when used
                    if (! $pf->getClassAt($token->index)) {
                        $seen_strings[ $token->text ] = 1;
                    }
                }
                continue;
            }


            if ($t->kind == T_STRING) {
                $str_upper = strtoupper($t->text);
                if ($t->text == $str_upper) {
                    // ok, all-uppercase, SOME_CONSTANT, I hope
                    continue;
                }

                // PHP predefined constants?
                if ($str_upper == "TRUE" || $str_upper == "FALSE" || $str_upper == "NULL") {
                    continue;
                }

                // skip all fully-quilified names (names with namespaces), if any
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
                                $seen_strings[ $string ] = 1;
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

                if (array_key_exists($t->text, $seen_strings)) {
                    continue;
                }
                $seen_strings[ $t->text ] = 1;

                $this->addDefect($t, XRef::WARNING, "Mixed/Lower-case unquoted string literal");
            }
        }

        return $this->report;
    }
}

// vim: tabstop=4 expandtab
