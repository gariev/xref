<?php
/**
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

class XRef_Lint_AssignementInCondition extends XRef_APlugin implements XRef_ILintPlugin {
    protected $reportId             = "lint-assignemnet-in-condition";
    protected $reportName           = "Lint (assignement in condition)";
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

        $tokens = $pf->getTokens();
        $count = count($tokens);
        for ($i=0; $i<$count; ++$i) {
            $t = $tokens[$i];

            // warnings:
            //      if ($foo = $bar) ;
            //      if ($bar = null) ;
            //      if ($baz = 25 && $qux == 30) ;
            // ok:
            //      if ($fh = fopen($file, "w")) ;
            //      if ($a = $foo->next() ) ;
            if ($t->kind == T_IF || $t->kind == T_ELSEIF) {
                $n = $t->nextNS();
                if ($n->text != '(') {
                    throw new Exception();
                }
                $last_index = $pf->getIndexOfPairedBracket( $n->index );
                while ($n->index < $last_index) {
                    $n = $n->nextNS();
                    if ($n->text == '=') {
                        $n = $n->nextNS();
                        $nn = $n->nextNS();
                        if ($nn->text == ')' || $nn->kind == T_BOOLEAN_AND || $nn->kind == T_BOOLEAN_OR) {
                            $this->addDefect($n, XRef::WARNING, "Assignement in condition");
                        }
                    }
                }
                $i = $last_index;
                continue;
            }
        }

        return $this->report;
    }
}

// vim: tabstop=4 expandtab
