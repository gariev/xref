<?php
/**
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

class XRef_Lint_AssignmentInCondition extends XRef_ALintPlugin {

    const E_ASSIGMENT_IN_CONDITION = "XA01";

    public function getErrorMap() {
        return array(
            self::E_ASSIGMENT_IN_CONDITION => array(
                "severity"  => XRef::WARNING,
                "message"   => "Assignment in conditional expression",
            ),
        );
    }

    public function __construct() {
        parent::__construct("lint-assignemnet-in-condition", "Lint (assignement in condition)");
    }

    protected $supportedFileType    = XRef::FILETYPE_PHP;

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

                    // skip function/method calls inside 'if' statements
                    //      if (someFunc($paramName = value)) ...
                    if ($n->text == '(') {
                        $p = $n->prevNS();
                        if ($p->kind == T_STRING) {
                            $n = $pf->getTokenAt( $pf->getIndexOfPairedBracket( $n->index ) );
                            continue;
                        }
                    }

                    if ($n->text == '=') {
                        $n = $n->nextNS();
                        $nn = $n->nextNS();
                        if ($nn->text == ')' || $nn->kind == T_BOOLEAN_AND || $nn->kind == T_BOOLEAN_OR) {
                            $this->addDefect($n, self::E_ASSIGMENT_IN_CONDITION);
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
