<?php
/**
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

class XRef_Lint_StaticThis extends XRef_ALintPlugin {

    const E_THIS_OUTSIDE_OF_METHOD              = "xr031";
    const E_THIS_IN_GLOBAL_SCOPE                = "xr032";
    const E_CLASS_CONSTRUCT_OUTSIDE_OF_METHOD   = "xr033";
    const E_CLASS_CONSTRUCT_IN_GLOBAL_SCOPE     = "xr034";


    public function getErrorMap() {
        return array(
            self::E_THIS_OUTSIDE_OF_METHOD => array(
                "severity"  => XRef::ERROR,
                "message"   => "(\$this) is used outside of instance/class scope"
            ),
            self::E_THIS_IN_GLOBAL_SCOPE => array(
                "severity"  => XRef::WARNING,
                "message"   => "Possible use of (\$this) in global scope"
            ),
            self::E_CLASS_CONSTRUCT_OUTSIDE_OF_METHOD => array(
                "severity"  => XRef::ERROR,
                "message"   => "Class keyword (%s) is used outside of instance/class scope"
            ),
            self::E_CLASS_CONSTRUCT_IN_GLOBAL_SCOPE => array(
                "severity"  => XRef::WARNING,
                "message"   => "Possible use of class keyword (%s) in global scope"
            ),
         );
    }

    public function __construct() {
        parent::__construct("lint-static-this", "Lint (use of \$this outside of instance methods)");
    }

    protected $supportedFileType    = XRef::FILETYPE_PHP;

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
        $tokens_count = count($tokens);
        for ($i=0; $i<$tokens_count; ++$i) {
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
                        throw new XRef_ParseException($n);
                    }
                    $n = $pf->getTokenAt( $pf->getIndexOfPairedBracket($n->index) );
                    $n = $n->nextNS();
                }

                if ($n->text == ';') {
                    // declaration only or abstract function: function foo();
                    // do nothing, skip main loop to the next token
                    $i = $n->index;
                } elseif ($n->text == '{') {
                    // fast-forward main loop to the end of the function body
                    $i = $pf->getIndexOfPairedBracket( $n->index );
                } else {
                    throw new XRef_ParseException($n, "'{' or ';'");
                }
                continue;
            }

            // if we found $this anywhere else, this is an error
            if ($t->text == '$this') {
                if ($allow_this_in_global_scope) {
                    $this->addDefect($t, self::E_THIS_IN_GLOBAL_SCOPE);
                } else {
                    $this->addDefect($t, self::E_THIS_OUTSIDE_OF_METHOD);
                }
            }

            // similar error: self is used outside of class declaration
            // however, self:: is allowed in static methods
            // also, allow $object->parent
            if (($t->text == 'self' || $t->text == 'parent') && !$pf->getClassAt($t->index)) {
                $n = $t->nextNS();
                $p = $t->prevNS();
                if ($n->text == '::' || $p->kind == T_NEW || $p->kind == T_INSTANCEOF) {
                    if ($allow_this_in_global_scope) {
                        $this->addDefect($t, self::E_CLASS_CONSTRUCT_IN_GLOBAL_SCOPE);
                    } else {
                        $this->addDefect($t, self::E_CLASS_CONSTRUCT_OUTSIDE_OF_METHOD);
                    }
                }
            }

        } // end of TOKEN loop

        return $this->report;
    }
}

// vim: tabstop=4 expandtab
