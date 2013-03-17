<?php
/**
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

class XRef_Lint_LowerCaseLiterals extends XRef_APlugin implements XRef_ILintPlugin {
    protected $reportId             = "lint-const-literals";
    protected $reportName           = "Lint (use of lower- or mixed-case string literals)";
    protected $supportedFileType    = XRef::FILETYPE_PHP;

    public function getName() {
        return $this->reportName;
    }
    public function getId() {
        return $this->reportId;
    }

    protected $reportLevel = XRef::WARNING;
    public function setReportLevel($reportLevel) {
        $this->reportLevel = $reportLevel;
    }

    // array of XRef_CodeDefect objects
    protected $report = array();

    protected function addDefect($token, $defectLevel, $message) {
        if ($defectLevel >= $this->reportLevel) {
            $this->report[] = new XRef_CodeDefect($token, $defectLevel, $message);
        }
    }

    public function getReport(XRef_IParsedFile $pf) {
        if ($pf->getFileType() != $this->supportedFileType) {
            return;
        }

        $this->report = array();

        // lower/mixed-case string literals:
        // for (foo=0; $foo<10; )
        // $bar[x]  - is it $bar['x'] or $bar[$x] ?
        $seenStrings = array(); // report every literal once per file, don't be noisy

        $tokens = $pf->getTokens();
        for ($i=0; $i<count($tokens); ++$i) {
            $t = $tokens[$i];

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

            if ($t->kind == T_STRING) {
                if ($t->text == strtoupper($t->text)) {
                    // ok, all-uppercase, SOME_CONSTANT, I hope
                    continue;
                }

                // skip all fully-quilified names (names with namespaces), if any
                // Foo\Bar\Baz
                $n = $t->nextNS();
                while ($n->kind == T_STRING || $n->kind==T_NS_SEPARATOR) {
                    $n = $n->nextNS();
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

                if ($p->kind == T_CONST) {
                    // ok, explicit constant declaration:
                    // class Enum { const Varain1 = 1; }
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
                if (array_key_exists($t->text, $seenStrings)) {
                    continue;
                }
                $seenStrings[ $t->text ] = 1;

                $this->addDefect($t, XRef::WARNING, "Mixed/Lower-case unquoted string literal");
            }
        }

        return $this->report;
    }
}

// vim: tabstop=4 expandtab
