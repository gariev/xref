<?php
/**
* @author Igor Gariev <gariev@hotmail.com>
* @copyright Copyright (c) 2013 Igor Gariev
* @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
*/

class XRef_Lint_UninitializedVars extends XRef_APlugin implements XRef_ILintPlugin {
    protected $reportId             = "lint-uninitialized-vars";
    protected $reportName           = "Lint (use of uninitialized vars)";
    protected $supportedFileType    = XRef::FILETYPE_PHP;

    /** known superglobals: array('$_SEREVR' => true, ...); */
    protected static $knownSuperglobals = array();

    /** known globals: array('$argv' => true, ...) */
    protected static $knownGlobals = array();

    /**
     * functions that return values in passed-by-reference-arguments
     * functionName --> list of argument positions, where values are returned
     * argument positions starts from 1 :)
     */
    protected static $returnByArgumentsFunction = array(
        "preg_match"        => array(3),
        "preg_match_all"    => array(3),
        "pcntl_wait"        => array(1),
        "str_replace"       => array(4),
        "system"            => array(2),
        "exec"              => array(2, 3),
        "sscanf"            => array(3, 4, 5, 6, 7, 8, 9, 10), // TODO: better way for unlimited number of params
        "parse_str"         => array(2),
        "openssl_sign"      => array(2),
    );

    protected $checkGlobalScope = false;

    public function __construct() {
        // super global variables
        $superGlobals = array(
            '$GLOBALS', '$_REQUEST', '$_GET', '$_POST',
            '$_FILES', '$_ENV', '$_SERVER', '$_COOKIE', '$_SESSION',
            // let's pretend here that $this is always defined,
            // the other lint plugin checks context of $this usage
            '$this',
        );
        self::$knownSuperglobals = array_fill_keys($superGlobals, true);

        // global variables
        $globals = array_merge( array('$argv', '$argc'), XRef::getConfigValue("lint.globals-vars", array()) );
        self::$knownGlobals  = array_fill_keys($globals, true);

        // functions that can assign value to variables passed by reference
        // format of each config entry (arguments positions starts with 1):
        // init-by-reference[]  =   <function-name>,<position-of-param1>,<position-of-param2...>
        foreach (XRef::getConfigValue("lint.init-by-reference", array()) as $str) {
            $params = split(",", $str);
            $function_name = array_shift($params);
            self::$returnByArgumentsFunction[$function_name] = $params;
        }

        // to check or not to check variables in the global scope
        $this->checkGlobalScope = XRef::getConfigValue("lint.check-global-scope", true);
    }


    public function getName() {
        return $this->reportName;
    }
    public function getId() {
        return $this->reportId;
    }

    const VAR_ASSIGNED = 1;
    const VAR_USED = 2;
    const VAR_UNKNOWN = 0;

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

    // for each scope, there is an array with list of declared variables
    // $foo = 1;                        // global scope
    // function bar() { $baz = 1; }     // function scope
    //                                  // nested functions ...
    protected $stackOfScopes = array(); // array of stdObjects

    protected function addScope($prevScope, $isInstanceMethod=false) {
        $this->stackOfScopes[] = (object) array(
            "vars"              => array(),
            "prevScope"         => $prevScope,
            "isInstanceMethod"  => $isInstanceMethod,
        );
    }
    protected function getCurrentScope() {
        if (count($this->stackOfScopes)>0) {
            return $this->stackOfScopes[ count($this->stackOfScopes)-1 ];
        } else {
            return null;
        }
    }
    protected function removeScope() {
        return array_pop($this->stackOfScopes);
    }


    protected function getOrCreateVar($token) {
        $varName = $token->text;
        $currentScope = $this->stackOfScopes[ count($this->stackOfScopes)-1 ];
        if (!isset($currentScope->vars[$varName])) {
            $currentScope->vars[$varName] = (object) array(
                "status"     => self::VAR_UNKNOWN,
                "token"      => $token,
                "isRefParam" => false, // paramenter of fuction passed by reference: function foo(&$bar)
                "isCatchVar" => false, // variable of try {} catch (E $foo) block
                "isGlobal"   => false,
            );
        } else {
            $currentScope->vars[$varName]->token = $token;
        }
        return $currentScope->vars[$varName];
    }

    protected function checkVar($token) {
        $varName = $token->text;
        if (isset(self::$knownSuperglobals[$varName])) {
            return true;
        } else if (count($this->stackOfScopes)==1 && isset(self::$knownGlobals[$varName])) {
            return true;
        } else {
            $currentScope = $this->getCurrentScope();
            return isset($currentScope->vars[$varName]);
        }
    }

    public function getReport(XRef_IParsedFile $pf) {
        if ($pf->getFileType() != $this->supportedFileType) {
            return;
        }

        // initialization/clean-up after previous parsed file, if any
        $this->stackOfScopes = array();
        $this->report = array();
        $dropScopeAt = -1;  // index of the token where current scope ends
        $this->addScope(-1);

        //
        // Part 1.
        //
        // variables that are being assigned a value:
        //  1. direct assignement: $foo = expr
        //  2. loop var:    foreach (array() as $foo)
        //  3. parameter of a function:  function bar($foo)
        //  4. catch(Exception $err)
        //  5. Array autovivification: $foo['index']
        //  6. Scalar autovivification: $count++
        //  7. superglobals
        //  8. list($foo) = array();
        //  9. globals: global $foo;
        // 10. functions that modify arguments:
        //      int preg_match ( string $pattern , string $subject [, array &$matches ...])
        $tokens = $pf->getTokens();

        // hate PHP 5.2: no loop labels allowed
        // TOKEN:
        for ($i=0; $i<count($tokens); ++$i) {
            $t = $tokens[$i];

            // $foo =
            // $foo[...] =
            // $foo[...][...] =
            // $foo++
            // exclude class variables: public $foo = 1;
            // special case: allow declarations of variables with undefined value: $foo;
            if ($t->kind==T_VARIABLE) {

                // skip variables declaration in classes
                //      public $foo;
                //      private static $bar;
                //      var $baz;
                if ($pf->getClassAt($t->index)!=null && $pf->getMethodAt($t->index)==null) {
                    continue; //TOKEN
                }

                $n = $t->nextNS(); // next non-space token
                $p = $t->prevNS(); // prev non-space token

                // skip static class variables:
                // Foo::$bar, self::$foo
                if ($p->kind==T_DOUBLE_COLON) {
                    continue;
                }

                $isArray = false;
                while ($n->text == '[') {
                    // quick forward to closing ']'
                    $n = $pf->getTokenAt( $pf->getIndexOfPairedBracket($n->index) );
                    $n = $n->nextNS();
                    $isArray = true;
                }

                if ($n->text == '=') {
                    if (!$this->checkVar($t) && $isArray) {
                        // array autovivification;
                        $this->addDefect($t, XRef::WARNING, "Array autovivification");
                    }
                    $var = $this->getOrCreateVar($t);
                    $var->status = self::VAR_ASSIGNED;
                    continue;
                }

                if ($n->kind==T_INC || $n->kind==T_DEC || $p->kind==T_INC || $p->kind==T_DEC) {
                    if (!$this->checkVar($t)) {
                        if ($isArray) {
                            // $foo["bar"]++
                            // array autovivification;
                            $this->addDefect($t, XRef::WARNING, "Array autovivification");
                        } else {
                            // $foo++
                            $this->addDefect($t, XRef::WARNING, "Scalar autovivification");
                        }

                        $var = $this->getOrCreateVar($t);
                        $var->status = self::VAR_ASSIGNED;
                        continue;
                    }
                }

                if ($n->text == ';' && !$isArray) {
                    if ($p && ($p->text==';' || $p->text=='{')) {
                        $this->addDefect($t, XRef::NOTICE, "Empty declaration-like statement");
                        $var = $this->getOrCreateVar($t);
                        $var->status = self::VAR_ASSIGNED;
                        continue;
                    }
                }
            }

            // foreach (expr as $foo)
            // foreach (expr as $foo => & $var)
            if ($t->kind==T_FOREACH) {
                $n = $t->nextNS();
                while ($n->kind != T_AS) {
                    $n = $n->nextNS();
                }
                $nn = $n->nextNS();
                if ($nn->text == '&') {
                    $nn = $nn->nextNS();
                }
                $var = $this->getOrCreateVar($nn);
                $var->status = self::VAR_ASSIGNED;

                $n = $nn->nextNS();
                // what is the name of "=>" token?
                if ($n->text == "=>") {
                    $nn = $n->nextNS();
                    if ($nn->text == '&') {
                        $nn = $nn->nextNS();
                    }
                    $var = $this->getOrCreateVar($nn);
                    $var->status = self::VAR_ASSIGNED;
                    $n = $nn->nextNS();
                }

                if ($n->text == ")") {
                    // ok
                } else {
                    // PHP code generated by smarty:
                    // foreach ($_from as $this->_tpl_vars['event']):
                }

                // TODO: can't skip to ")" of foreach(expr as ...), because expr will be unparsed
                // TODO: loop vars will be scanned again and counted as used even if they are not
            }

            // function &asdf($foo, $bar = array())
            // function asdf(&$foo)
            // here a new scope frame is created
            if ($t->kind==T_FUNCTION) {
                $isInsideInsanseMethod = ($pf->getClassAt($t->index)!=null && $t->prevNS()->kind != T_STATIC);
                $this->addScope($dropScopeAt, $isInsideInsanseMethod);
                $n = $t->nextNS();
                while ($n->text != "(") {
                    $n = $n->nextNS();
                }

                $closingBraketIndex = $pf->getIndexOfPairedBracket($n->index);
                while ($n->index != $closingBraketIndex) {
                    $n = $n->nextNS();
                    if ($n->kind == T_VARIABLE) {
                        $var = $this->getOrCreateVar($n);
                        $var->status = self::VAR_ASSIGNED;
                        $var->isRefParam = ($n->prevNS()->text == '&'); // parameter passed by reference
                    }
                }

                $n = $n->nextNS();
                if ($n->text == ';') {
                    // declaration only or absctract function: function foo();
                    $this->removeScope(); // empty scope with parameters names at most
                } elseif ($n->text == '{') {
                    $dropScopeAt = $pf->getIndexOfPairedBracket( $n->index );
                } else {
                    throw new Exception("$n found instead of { or ;");
                }
                $i = $n->index;
                continue;
            }
            if ($i==$dropScopeAt) {
                $currentScope = $this->removeScope();
                foreach ($currentScope->vars as $varName => $var) {
                    if ($var->status != self::VAR_USED && !$var->isRefParam && !$var->isCatchVar && !in_array($varName, self::$knownSuperglobals) && !$var->isGlobal) {
                        $this->addDefect($var->token, XRef::NOTICE, "Value of variable is not used");
                    }
                }
                $dropScopeAt = $currentScope->prevScope;
            }

            // catch (Exception $foo)
            if ($t->kind == T_CATCH) {
                $n = $t->nextNS();
                if ($n->text != '(') {
                    throw new Exception("$n found instead of '('");
                }
                $n = $n->nextNS(); // class name?
                $n = $n->nextNS(); //
                if ($n->kind == T_VARIABLE) {
                    $var = $this->getOrCreateVar($n);
                    $var->status = self::VAR_ASSIGNED;
                    $var->isCatchVar = true;
                } else {
                    throw new Exception("$n found instead of variable");
                }
                $n = $n->nextNS(); //
                if ($n->text != ')') {
                    throw new Exception("$n found instead of ')'");
                }

                $i = $n->index;
                continue;
            }

            // list($a, $b) = ...
            // TODO: check that the list is used in the left side of the assinment operator
            // TODO: example from PHP documentation: list($a, list($b, $c)) = array(1, array(2, 3));
            if ($t->kind==T_LIST) {
                $n = $t->nextNS();
                if (!$n->text=="(") {
                    throw new Exception("Invalid list declaration found: $t");
                }

                $closingBraketIndex = $pf->getIndexOfPairedBracket($n->index);
                while ($n->index != $closingBraketIndex) {
                    if ($n->kind==T_VARIABLE) {
                        $var = $this->getOrCreateVar($n);
                        $var->status = self::VAR_ASSIGNED;
                    }
                    $n = $n->nextNS();
                }
                $i = $n->index;
                continue;
            }

            // globals
            // TODO: check that the variable does exist at global level
            // TODO: link this var to the var at global level
            if ($t->kind == T_GLOBAL) {
                $n = $t->nextNS();
                while (true) {
                    if (!$n->kind==T_VARIABLE) {
                        throw new Exception("Invalid 'global' decalaraion found: $n");
                    }
                    $var = $this->getOrCreateVar($n);
                    $var->isGlobal = true;
                    $var->status = self::VAR_ASSIGNED;
                    $n = $n->nextNS();
                    if ($n->text==',') {
                        $n = $n->nextNS();
                        continue; // next variable in list
                    } elseif ($n->text==';') {
                        break; // end of list
                    } else {
                        throw new Exception("Invalid 'global' declaration found: $n");
                    }
                }
                $i = $n->index;
                continue;
            }

            // fucntions that return values into passed-by-reference-arguments
            //      preg_match, preg_match_all etc
            if ($t->kind == T_STRING && array_key_exists($t->text, self::$returnByArgumentsFunction)) {
                $n = $t->nextNS();
                if ($n->text == '(') {
                    $arguments = $pf->extractList($n->nextNS());
                    foreach (self::$returnByArgumentsFunction[$t->text] as $argPos) {
                        if (count($arguments) >= $argPos) {
                            $n = $arguments[$argPos-1];
                            if ($n->text == '&') {
                                $n = $n->nextNS();
                            }
                            if ($n->kind != T_VARIABLE) {
                                throw new Exception("Unexpected return variable found: $n");
                            }
                            $var = $this->getOrCreateVar($n);
                            $var->status = self::VAR_ASSIGNED;
                        }
                    }
                }
            }

            // Part 2.
            // Check if a variable is defined
            //
            if ($t->kind==T_VARIABLE) {
                $skipVariable = false;

                // skip class static variables:
                // Foo::$foo
                // TODO: check that this class variable is really declared
                $p = $t->prevNS();
                if ($p->kind == T_DOUBLE_COLON) {
                    $skipVariable = true;
                }

                // skip varibales in the global scope, because it's often polluted by vars
                // included from inlcuded/required files
                if ($this->checkGlobalScope==false && count($this->stackOfScopes)==1) {
                    $skipVariable = true;
                }

                if (!$skipVariable && !$this->checkVar($t)) {
                    $this->addDefect($t, XRef::ERROR, "Use of non-defined variable");
                    $var = $this->getOrCreateVar($t);
                    $var->status = self::VAR_USED; // mark it as used to report every var only once
                }
            }
        } // end of "for each token" loop

        if (count($this->stackOfScopes)!=1) {
            throw new Exception("internal error: size of stack = " . count($this->stackOfScopes) . ", " . $pf->getFileName());
        }

        $currentScope = $this->removeScope();
        foreach ($currentScope->vars as $varName => $var) {
            if ($var->status != self::VAR_USED && !in_array($varName, self::$knownSuperglobals)) {
                $this->addDefect($var->token, XRef::NOTICE, "Value of variable is not used");
            }
        }

        return $this->report;
    }
}

// vim: tabstop=4 expandtab
