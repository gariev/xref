<?php
/**
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

class XRef_Lint_StaticThis extends XRef_APlugin implements XRef_ILintPlugin {
    protected $reportId             = "lint-static-this";
    protected $reportName           = "Lint (use of \$this outside of instance methods)";
    protected $supportedFileType    = XRef::FILETYPE_PHP;

    protected static $knownSuperglobals = array();
    protected static $knownGlobals = array();

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

        // special case: if file has no declarations of function/classes, it can be included into
        // body of some instance method, then use of $this is legit.
        // see also: joomla code.
        $allow_this_in_global_scope = false;
        if (count($pf->getClasses())==0 && count($pf->getMethods())==0) {
            $allow_this_in_global_scope = true;
        }

        // TOKEN:
        $tokens = $pf->getTokens();
        for ($i=0; $i<count($tokens); ++$i) {
            $t = $tokens[$i];

            // ignore bodies of non-static functions declared inside classes
            //      TODO: should we allow $this inside function arguments, e.g.
            //      class Foo { public function bar($this, $baz = $this) {} } ??
            if ($t->kind==T_FUNCTION && $t->prevNS()->kind!=T_STATIC && $pf->getClassAt($t->index)!=null) {
                $n = $t->nextNS();
                while ($n->text != "(") {
                    $n = $n->nextNS();
                }
                // fast-forward to closing ")" of the function arguments
                $n = $pf->getTokenAt( $pf->getIndexOfPairedBracket($n->index) );

                $n = $n->nextNS();
                if ($n->kind == T_USE) {
                    $n = $n->nextNS();
                    if ($n->text != '(') {
                        throw new Exception();
                    }
                    $n = $pf->getTokenAt( $pf->getIndexOfPairedBracket($n->index) );
                    $n = $n->nextNS();
                }

                if ($n->text == ';') {
                    // declaration only or absctract function: function foo();
                    // do nothing, skip main loop to the next token
                    $i = $n->index;
                } elseif ($n->text == '{') {
                    // fast-forward main loop to the end of the function body
                    $i = $pf->getIndexOfPairedBracket( $n->index );
                } else {
                    throw new Exception("$n found instead of { or ;");
                }
                continue;
            }

            // if we found $this anywhere else, this is an error
            if ($t->text == '$this') {
                if ($allow_this_in_global_scope) {
                    $this->addDefect($t, XRef::WARNING, "Possible use of \$this is global scope");
                } else {
                    $this->addDefect($t, XRef::ERROR, "\$this is used outside of instance method");
                }
            }

        } // end of TOKEN loop

        return $this->report;
    }
}

// vim: tabstop=4 expandtab
