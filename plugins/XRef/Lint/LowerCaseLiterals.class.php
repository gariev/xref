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

            // skip class names completely
            // class Foo extends Bar implements Baz, Qux
            if ($t->kind==T_CLASS || $t->kind==T_INTERFACE) {
                do {
                    $t = $t->nextNS();
                } while ($t->text != ';' && $t->text != '{');
                $i = $t->index;
                continue;
            }

            if ($t->kind == T_STRING) {
                if ($t->text == strtoupper($t->text)) {
                    // ok, all-uppercase, SOME_CONSTANT, I hope
                    continue;
                }

                $n = $t->nextNS();
                if ($n->text == '(') {
                    // ok, function call: Foo(...)
                    continue;
                }
                if ($n->kind == T_DOUBLE_COLON) {
                    // ok, class name: foo::$something
                    continue;
                }

                if ($n->text=='&') {
                    $n = $n->nextNS();
                }
                if ($n->kind==T_VARIABLE) {
                    // ok, some kind of variable declared with class:
                    // catch (Foo $x);
                    // function bar(Foo &$x)
                    continue;
                }

                $p = $t->prevNS();
                if ($p->kind == T_DOUBLE_COLON || $p->kind==T_OBJECT_OPERATOR) {
                    // ok, static or instance member: $bar->foo, Bar::foo
                    continue;
                }
                if ($p->kind == T_INSTANCEOF || $p->kind == T_NEW) {
                    // ok, class name:
                    // $foo instanceof Foo
                    // $foo = new Foo;
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
