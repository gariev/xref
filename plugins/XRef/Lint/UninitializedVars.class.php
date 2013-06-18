<?php
/**
* @author Igor Gariev <gariev@hotmail.com>
* @copyright Copyright (c) 2013 Igor Gariev
* @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
*/


//
// Strict vs. relaxed mode:
//  <?php
//      include "foo.php";          // relaxed mode - no one knows what's inside foo.php
//      if (isset($x)) ...          // ok, we know now that there may be variable named $x
//      function bar($a, $b) {      // strict mode - new scope
//          if (isset($y)) ...;     // error - there's no way $y can be here
//          extract($a);            // relaxed mode from here till end of the scope
//          $$b = 1;                // also turn relaxed mode
//          if (empty($z))          // ok, we are in relaxed mode
//      }
//
//  In short:
//      - Global scope starts in relaxed mode, functions scope starts with strict.
//      - Use of extract() or $$var or include/require triggers relaxed mode.
//      - Use of isset($unknown_var) or empty($unknown_var) is error in strict mode
//        and "declaration" of $unknown_var in relaxed mode.
//
//      - TODO: make any conditional expression with a variable "declare" this
//        variable in relaxed mode (?), like
//          if (trim($unknownVar) != '') // now $unknownVar is known in this scope
//

// function that can assign values to variables passed by reference:
// list of known functions both that can and cannot set variable's value
//
//  strict mode:
//      known_function_that_assign_variable($unknown_var);          // ok
//      known_function_that_doesnt_assign_variable($unknown_var);   // error
//      unknown_function($unknown_var);                             // warning
//      unknown_function($unknown_var_in_expression*2);             // error
// relaxed mode:
//      known_function_that_assign_variable($unknown_var);          // ok
//      known_function_that_doesnt_assign_variable($unknown_var);   // warning
//      unknown_function($unknown_var);                             // warning
//      unknown_function($unknown_var_in_expression*2);             // warning
//
// list-of-known-function =
//      explicit list of functions +
//      config file defined funcions +
//      result of get_defined_functions() +     // don't overwrite functions from above
//      parsing of current file                 // overwrite or not?
//

class XRef_Lint_UninitializedVars extends XRef_ALintPlugin {

    const E_UNKNOWN_VAR             = "XV01";
    const E_UNKNOWN_VAR_RELAXED     = "XV02";
    const E_UNKNOWN_VAR_ARGUMENT    = "XV03";
    const E_ARRAY_AUTOVIVIFICATION  = "XV04";
    const E_SCALAR_AUTOVIVIFICATION = "XV05";
    const E_NON_VAR_PASSED_BY_REF   = "XV06";
    const E_EMPTY_STATEMENT         = "XV07";
    const E_LOSS_OF_STRICT_MODE     = "XV08";

    public function getErrorMap() {
        return array(
            self::E_UNKNOWN_VAR  => array(
                "message"   => "Use of unknown variable",
                "severity"  => XRef::ERROR,
            ),
            self::E_UNKNOWN_VAR_RELAXED  => array(
                "message"   => "Possible use of unknown variable",
                "severity"  => XRef::WARNING,
            ),
            self::E_UNKNOWN_VAR_ARGUMENT  => array(
                "message"   => "Possible use of unknown variable as function argument",
                "severity"  => XRef::WARNING,
            ),
            self::E_ARRAY_AUTOVIVIFICATION  => array(
                "message"   => "Array autovivification",
                "severity"  => XRef::WARNING,
            ),
            self::E_SCALAR_AUTOVIVIFICATION  => array(
                "message"   => "Scalar autovivification",
                "severity"  => XRef::WARNING,
            ),
            self::E_NON_VAR_PASSED_BY_REF => array(
                "message"   => "Possible attempt to pass non-variable by reference",
                "severity"  => XRef::ERROR,
            ),
            self::E_EMPTY_STATEMENT  => array(
                "message"   => "Empty declaration-like statement",
                "severity"  => XRef::NOTICE,
            ),
            self::E_LOSS_OF_STRICT_MODE => array(
                "message"   => "Can't reliable detect var usage from here",
                "severity"  => XRef::NOTICE,
            ),
        );
    }

    protected $supportedFileType    = XRef::FILETYPE_PHP;

    /** known superglobals: array('$_SEREVR' => true, ...); */
    public static $knownSuperglobals = array();

    /** known globals: array('$argv' => true, ...) */
    protected static $knownGlobals = array();

    /**
     * known internal php functions:
     * array( "function_name" => null|array with list of init-by-ref argument positions),
     * e.g. array( 'preg_match' => array(2), 'printf' => null, ... )
     */
    protected static $internalFunctions = array();

    /**
     * array similar to $internalFunctions above but with user functions, file dependent
     */
    private $userFunctionsFromCurrentFile = array();

    /**
     * array similar to $internalFunctions above but with user functions, file dependent
     */
    private $userFunctionsFromConfig = array();

    /**
     * array( "function_name" => true ) with list of functions that do takes
     * arguments by reference but don't initialize them, i.e. it must exist already,
     * e.g. sort( &$array )
     */
    protected static $internalFunctionsThatDoesntInitializePassedByReferenceParams = array();

    public function __construct() {
        parent::__construct("lint-uninitialized-vars", "Lint (use of uninitialized vars)");

        // super global variables
        $superGlobals = array(
            '$GLOBALS', '$_REQUEST', '$_GET', '$_POST',
            '$_FILES', '$_ENV', '$_SERVER', '$_COOKIE', '$_SESSION',
            '$HTTP_RAW_POST_DATA',
            '$http_response_header', '$php_errormsg',
            // let's pretend here that $this is always defined,
            // the other lint plugin checks context of $this usage
            '$this',
        );
        self::$knownSuperglobals = array_fill_keys($superGlobals, true);

        // global variables
        self::$knownGlobals = array_merge(
            array('$argv', '$argc'),
            XRef::getConfigValue("lint.add-global-var", array())
        );

        if (!self::$internalFunctions) {
            self::initialize_internal_php_function_list();
        }

    }

    const VAR_ASSIGNED = 1;
    const VAR_USED = 2;
    const VAR_UNKNOWN = 0;

    const MODE_STRICT = 1;
    const MODE_RELAXED = 2;

    /**
     * for each scope, there is an array with list of declared variables
     *  $foo = 1;                       // global scope
     *  function bar() { $baz = 1; }    // function scope
     *
     * @var XRef_Lint_UninitializedVars_Scope[]
     */
    protected $listOfScopes = array();  // array of stdObjects

    /** @var XRef_Lint_UninitializedVars_Scope */
    protected $currentScope = null;

    protected function checkVarAndAddDefectIfMissing(XRef_Token $token, $error_code=null) {
        $scope = $this->currentScope;

        if (!$scope->checkVar($token)) {
            if (!$error_code) {
                $error_code = ($scope->mode == self::MODE_RELAXED) ?
                    self::E_UNKNOWN_VAR_RELAXED : self::E_UNKNOWN_VAR;
            }

            if ($scope->isLoopMode) {
                // special "inside-loop" mode: don't report the missing variable
                // till the end of the loop, since
                // assign/use pattern can be out of lexical order inside of loops
                $variable_name = $token->text;
                if (!isset( $scope->loopVars[$variable_name] )) {
                    $scope->loopVars[$variable_name] = array($token, $error_code);
                }
            } else {
                $this->addDefect($token, $error_code);
                // create this var to report the error only once
                $var = $scope->getOrCreateVar($token);
                $var->status = self::VAR_ASSIGNED;
            }
        }
    }

    const CMD_CHANGE_CURRENT_SCOPE  = 1;    // change $this->currentScope
    const CMD_SKIP_TO_TOKEN         = 2;    // advance token index (skip to token)
    const CMD_CHECK_USED_VARIABLES  = 3;    // check that variables of "use" clause are defined in current scope
    const CMD_START_LOOP            = 4;    // switch to "inside the loop" mode and defer variable checks
    const CMD_END_LOOP              = 5;    // exit "inside the loop" mode and to the checks
    const CMD_SWITCH_TO_RELAXED_MODE= 6;    // switch the current scope to relaxed mode


    // ordered map (token index -> command array()
    protected $listOfCommands = array();

    protected function addCommand(array $cmd) {
        $token_index = $cmd[0];
        $i = 0;
        while ($i < count($this->listOfCommands) && $this->listOfCommands[$i][0] <= $token_index) {
            $i++;
        }
        array_splice($this->listOfCommands, $i, 0, array($cmd));
    }

    public function getReport(XRef_IParsedFile $pf) {
        if ($pf->getFileType() != $this->supportedFileType) {
            return;
        }
        $tokens = $pf->getTokens();
        $tokens_count = count($tokens);

        // initialization/clean-up after previous parsed file, if any
        $this->listOfScopes = array();
        $this->listOfCommands = array();
        $this->report = array();

        // create a global scope ...
        $global_scope = new XRef_Lint_UninitializedVars_Scope(0, $tokens_count);
        $global_scope->mode = self::MODE_RELAXED;
        $global_scope->isGlobal = true;
        $this->listOfScopes[] = $global_scope;
        $this->currentScope = $global_scope;
        foreach (self::$knownGlobals as $var_name) {
            $var = $global_scope->getOrCreateVarByName($var_name);
            $var->state = self::VAR_ASSIGNED;
        }
        // and create a separate scope for each function/method/closure
        foreach ($pf->getMethods() as $method) {
            // if the method/function has a body, create a scope
            if ($method->bodyStarts) {
                $scope = new XRef_Lint_UninitializedVars_Scope($method->bodyStarts, $method->bodyEnds);
                $this->listOfScopes[] = $scope;
                // copy function parameters to list of known variables of this scope
                foreach ($method->parameters as $param) {
                    $var = $scope->getOrCreateVarByName($param->name);
                    $var->status = self::VAR_ASSIGNED;
                }
                foreach ($method->usedVariables as $param) {
                    $var = $scope->getOrCreateVarByName($param->name);
                    $var->status = self::VAR_ASSIGNED;
                }
                // commands to switch the scope
                $this->addCommand(array($method->bodyStarts+1, self::CMD_CHANGE_CURRENT_SCOPE));
                $this->addCommand(array($method->bodyEnds+1, self::CMD_CHANGE_CURRENT_SCOPE));
                // special command to check used variables of a closure
                // right before T_FUNCTION token of the closure starts
                if ($method->usedVariables) {
                    $this->addCommand(array($method->index-1, self::CMD_CHECK_USED_VARIABLES, $method));
                }
            }

            // for all method declaration/definitions, skip the part
            // "function" <name> (argument list...) from checks
            $skip_to_index = ($method->bodyStarts) ? $method->bodyStarts : $method->bodyEnds;
            $this->addCommand(array($method->index, self::CMD_SKIP_TO_TOKEN, $skip_to_index));
        }

        // skip the content of class body from checks; check method bodies only
        // add commands:
        //      skip from class start to start of body of first method
        //      skip from end of body of first method to start of body of second method
        //      ...
        //      skip from end of the body of the last method till the end of the class
        foreach ($pf->getClasses() as $class) {
            // hm, there are no class declaration without a definition in PHP, right?
            if ($class->bodyStarts) {
                $start_skip_from = $class->index;
                foreach ($class->methods as $method) {
                    if ($method->bodyStarts) {
                        $this->addCommand(array($start_skip_from, self::CMD_SKIP_TO_TOKEN, $method->bodyStarts));
                        $start_skip_from = $method->bodyEnds;
                    }
                }
                $this->addCommand(array($start_skip_from, self::CMD_SKIP_TO_TOKEN, $class->bodyEnds));
            }
        }

        // TODO: move the line below into constructor. It's here for unit tests only.
        // Config doesn't change during normal work, and neither the var below.
        $this->userFunctionsFromConfig = &self::getUserFunctionsFromConfig();
        $this->userFunctionsFromCurrentFile = &self::getUserFunctionsFromCurrentFile($pf);

        // to check or not to check variables in the global scope
        // variables in local scope (inside functions) will always be checked
        $checkGlobalScope = XRef::getConfigValue("lint.check-global-scope", true);

        $function_knowledge_sources = array(
            $this->userFunctionsFromConfig,
            $this->userFunctionsFromCurrentFile,
            self::$internalFunctions,
        );

        for  ($i = 0; $i < $tokens_count; ++$i) {
            $t = $tokens[$i];

            // skip whitespaces but not doc comments (we'll process them later)
            // TODO: move doc comments parse code into XRef_ParsedFile (?)
            if ($t->kind == T_WHITESPACE || $t->kind == T_COMMENT) {
                continue;
            }

            // process the command(s)
            if ($this->listOfCommands && $this->listOfCommands[0][0] <= $i) {
                $cmd = array_shift($this->listOfCommands);
                switch ($cmd[1]) {

                    case self::CMD_CHANGE_CURRENT_SCOPE:
                        // there can be nested scopes, find the deepest one
                        foreach ($this->listOfScopes as $scope) {
                            if ($scope->start > $i) {
                                break;
                            }
                            if ($scope->end > $i) {
                                $this->currentScope = $scope;
                            }
                        }
                        break;

                    case self::CMD_SKIP_TO_TOKEN:
                        $i = $cmd[2];
                        break;

                    case self::CMD_CHECK_USED_VARIABLES:
                        $closure = $cmd[2];
                        foreach ($closure->usedVariables as $arg) {
                            // check that this variable exists at outer scope
                            // however, if it's passed by reference, it can be created by the closure:
                            //      $foo = function () using (&$x) { $x = 1; };
                            //      $foo(); // now we have $x here
                            $token = $pf->getTokenAt($arg->index);
                            if ($arg->isPassedByReference) {
                                $var = $this->currentScope->getOrCreateVar($token);
                                $var->status = self::VAR_ASSIGNED;  // not quite true -
                            } else {
                                $this->checkVarAndAddDefectIfMissing($token);
                            }
                        }
                        break;

                    case self::CMD_START_LOOP:
                        $this->currentScope->isLoopMode = true;
                        break;

                    case self::CMD_END_LOOP:
                        $this->currentScope->isLoopMode = false;
                        foreach ($this->currentScope->loopVars as $variable_name => $v) {
                            list($token, $error_code) = $v;
                            $this->checkVarAndAddDefectIfMissing($token, $error_code);
                        }
                        $this->currentScope->loopVars = array();
                        break;

                    case self::CMD_SWITCH_TO_RELAXED_MODE:
                        $this->currentScope->mode = self::MODE_RELAXED;
                        break;

                    default:
                        // shouldn't be here
                        throw new XRef_ParseException($t);
                }

                // executing a command can change the current pointer, redo the loop
                // and maybe execute another command
                // however, don't increment $i
                --$i;
                continue;
            }

            //
            // Switch from strict mode to relaxed?
            //
            // use of extract() or $$foo notation
            // trick is: the mode should be switched AFTER the statement, e.g.
            //  function foo() { extract($foo); echo $bar; }
            // $foo must be declared (still in strict scope); $bar - not (in relaxed mode)

            if ($this->currentScope->mode == self::MODE_STRICT) {
                // use of extract()
                if ($t->kind == T_STRING && $t->text == 'extract') {
                    $n = $t->nextNS(); // next non-space token
                    if ($n->text == '(') {
                        $this->addCommand(array($pf->getIndexOfPairedBracket( $n->index ), self::CMD_SWITCH_TO_RELAXED_MODE, $t));
                        continue;
                    }
                }
                // use of eval();
                if ($t->kind == T_EVAL) {
                    $n = self::skipTillText($t, ';');
                    $this->addCommand(array($n->index, self::CMD_SWITCH_TO_RELAXED_MODE, $t));
                    continue;
                }
                // $$var notation in assignment.
                // Non-assignment (read) operations doesn't cause mode switch
                //      $$foo =
                //      $$bar["baz"] =
                // TODO: other forms of assignment? $$foo++; ?
                if ($t->text == '$') {
                    $n = $t->nextNS(); // next non-space token
                    if ($n->kind==T_VARIABLE) {
                        $nn = $n->nextNS();
                        while ($nn->text == '[') {
                            // quick forward to closing ']'
                            $nn = $pf->getTokenAt( $pf->getIndexOfPairedBracket($nn->index) );
                            $nn = $nn->nextNS();
                        }
                        if ($nn->text == '=') {
                            $s = self::skipTillText($n, ';');           // find the end of the statement
                            $this->addCommand(array($s->index, self::CMD_SWITCH_TO_RELAXED_MODE, $n));
                        }
                    }
                }
                // include/require statements
                // if you use them inside functions, well, it's impossible to make any assertions about your code.
                if ($t->kind==T_INCLUDE || $t->kind==T_REQUIRE || $t->kind==T_INCLUDE_ONCE || $t->kind==T_REQUIRE_ONCE) {
                    $s = self::skipTillText($t, ';');               // find the end of the statement
                    if ($s) {
                        $this->addCommand(array($s->index, self::CMD_SWITCH_TO_RELAXED_MODE, $t));
                    }
                }
            }

            // loops:
            // in loop, a variable can be tested first, and then assigned and this in not an error:
            //      foreach                             // on parsing this line, $this->loop_starts_at will be set
            //          ($items as $item)
            //      {                                   // here $this->loop_ends_at will be set as marker
            //                                          // that we are inside of the loop
            //          if (isset($total)) {
            //              $total += $item->cost;
            //          } else {
            //              $total = $item->cost;
            //          }
            //      }                                   // here the loop scope will be dropped and all
            //                                          // missing vars will be added to report
            //
            if (!$this->currentScope->isLoopMode) {
                // start a new loop scope?
                // for, foreach, while, do ... while
                if ($t->kind == T_FOR || $t->kind == T_FOREACH || $t->kind == T_DO || $t->kind == T_WHILE) {
                    $n = $t->nextNS();
                    // skip condition, may be missing if token is T_DO
                    if ($n->text == '(') {
                        $n = $pf->getTokenAt( $pf->getIndexOfPairedBracket( $n->index ) );
                        $n = $n->nextNS();
                    }
                    // is there a block or a single statement as loop's body?
                    if ($n->text == '{') {
                        $this->addCommand(array($n->index, self::CMD_START_LOOP));
                        $this->addCommand(array($pf->getIndexOfPairedBracket($n->index), self::CMD_END_LOOP));
                    }
                }
            }

            //
            // Part 1.
            //
            // Find "known" (declared or assigned) variables.
            // Variable is "known" in following cases:
            //  1. value is assigned to the variable: $foo = expr
            //  2. loop var:    foreach (array() as $foo)
            //  3. parameter of a function:  function bar($foo)
            //  4. catch(Exception $err)
            //  5. Array autovivification: $foo['index']
            //  6. Scalar autovivification: $count++, $text .=
            //  7. superglobals
            //  8. list($foo) = array();
            //  9. globals: global $foo;
            // 10. functions that modify arguments:
            //      int preg_match ( string $pattern , string $subject [, array &$matches ...])
            // 11. test for existence of var in "relaxed" mode: isset($foo), empty($bar)
            // 12. variables declared via annotations: @var ClassName $varName (relaxed mode only)

            // $foo =
            // $foo[...] =
            // $foo[...][...] =
            // $foo++
            // $foo .=
            // exclude class variables: public $foo = 1;
            // special case: allow declarations of variables with undefined value: $foo;
            if ($t->kind==T_VARIABLE) {
                // skip variables declaration in classes
                //      public $foo;
                //      private static $bar;
                //      var $baz;
                if ($pf->getClassAt($t->index)!=null && $pf->getMethodAt($t->index)==null) {
                    // shouldn't be here
                    // remove this once it passes the tests
                    throw new XRef_ParseException($t);
                }

                $n = $t->nextNS(); // next non-space token
                $p = $t->prevNS(); // prev non-space token

                // skip static class variables:
                // Foo::$bar, self::$foo
                if ($p->kind==T_DOUBLE_COLON) {
                    continue;
                }

                $is_array = false;
                while ($n->text == '[') {
                    // quick forward to closing ']'
                    $n = $pf->getTokenAt( $pf->getIndexOfPairedBracket($n->index) );
                    $n = $n->nextNS();
                    $is_array = true;
                }

                if ($n->text == '=') {
                    if ($is_array && !$this->currentScope->checkVar($t)) {
                        // array autovivification?
                        $this->checkVarAndAddDefectIfMissing($t, self::E_ARRAY_AUTOVIVIFICATION);
                    } else {
                        $var = $this->currentScope->getOrCreateVar($t);
                        $var->status = self::VAR_ASSIGNED;
                    }
                    continue;
                }

                if ($n->kind==T_INC || $n->kind==T_DEC || $p->kind==T_INC || $p->kind==T_DEC || $n->kind==T_CONCAT_EQUAL || $n->kind==T_PLUS_EQUAL) {
                    $error_code = ($is_array) ? self::E_ARRAY_AUTOVIVIFICATION : self::E_SCALAR_AUTOVIVIFICATION;
                    $this->checkVarAndAddDefectIfMissing($t, $error_code);
                    continue;
                }

                if ($n->text == ';' && !$is_array) {
                    if ($p && ($p->text==';' || $p->text=='{')) {
                        $this->addDefect($t, self::E_EMPTY_STATEMENT);
                        $var = $this->currentScope->getOrCreateVar($t);
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
                $var = $this->currentScope->getOrCreateVar($nn);
                $var->status = self::VAR_ASSIGNED;

                $n = $nn->nextNS();
                if ($n->kind == T_DOUBLE_ARROW) {
                    $nn = $n->nextNS();
                    if ($nn->text == '&') {
                        $nn = $nn->nextNS();
                    }
                    $var = $this->currentScope->getOrCreateVar($nn);
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
                continue;
            }

            // function &asdf($foo, $bar = array())
            // function asdf(&$foo);
            // function asdf(Foo $foo);
            // function ($x) use ($y) ...
            // here a new scope frame is created
            if ($t->kind==T_FUNCTION) {
                // shouldn't be here
                // function declaration parst should be skipped by CMD_SKIP_TO
                throw new XRef_ParseException($t);
            }


            // catch (Exception $foo)
            if ($t->kind == T_CATCH) {
                $n = $t->nextNS();
                if ($n->text != '(') {
                    throw new Exception("$n found instead of '('");
                }
                $n = $n->nextNS();
                list($type, $n) = self::parseType($n);
                if (!$type) {
                    throw new Exception("No exception type found ($n)");
                }
                if ($n->kind == T_VARIABLE) {
                    $var = $this->currentScope->getOrCreateVar($n);
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
                        $this->currentScope->getOrCreateVar($n);
                    }
                    $n = $n->nextNS();
                }
                $i = $n->index;
                continue;
            }

            // globals:
            //      global $foo;     // makes the variable $foo known
            //      global $$bar;    // uh-oh, the var $bar must be known and relaxed mode afterwards
            //
            // TODO: check that the variable does exist at global level
            // TODO: link this var to the var at global level
            if ($t->kind == T_GLOBAL) {
                $n = $t->nextNS();
                while (true) {
                    if ($n->kind==T_VARIABLE) {
                        $var = $this->currentScope->getOrCreateVar($n);
                        $var->isGlobal = true;
                        $var->status = self::VAR_ASSIGNED;
                        $n = $n->nextNS();
                    } elseif ($n->text=='$') {
                        $n = $n->nextNS();
                        if ($n->kind==T_VARIABLE) {
                            // check that this var is declared
                            $this->checkVarAndAddDefectIfMissing($n);
                            // turn the relaxed mode on beginning of the next statement
                            $s = self::skipTillText($n, ';');
                            $token_caused_mode_switch = $n;
                            $switch_to_relaxed_scope_at = $s->index;
                        } else {
                            throw new Exception("Invalid 'global' decalaraion found: $nn");
                        }
                        $n = $n->nextNS();
                    } else {
                        throw new Exception("Invalid 'global' decalaraion found: $n");
                    }

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

            // static function variables
            //  function foo() {
            //      static $foo;                // <-- well, strictly speaking this variable is not intilialized,
            //      static $bar = 10, $baz;     //  but it's declared so let's hope that author knows what's going on
            //  }
            // other usage of "static" keyword:
            //  $foo = new static();
            //  $foo = new static;
            //  $foo instanceof staic
            //  $foo = static::methodName();
            if ($t->kind == T_STATIC && $pf->getMethodAt($t->index)!=null) {
                $n = $t->nextNS();
                $p = $t->prevNS();
                if ($n->kind != T_DOUBLE_COLON && $p->kind != T_NEW && $p->kind != T_INSTANCEOF) {
                    $list = $pf->extractList($n, ',', ';');
                    foreach ($list as $n) {
                        if ($n->kind == T_VARIABLE) {
                            $var = $this->currentScope->getOrCreateVar($n);
                            $var->status = self::VAR_ASSIGNED;
                            $i = $n->index;
                        } else {
                            // oops?
                            throw new Exception("Invalid 'static' decalaraion found: $n");
                        }
                    }
                    continue;
                }
            }

            //
            // Functions that can return values into passed-by-reference arguments,
            //  e.g. preg_match, preg_match_all etc.
            //
            //  foo($x);
            //  Foo::foo($x);
            //  $foo->foo($x);
            //
            // Checks for known functions:
            //  1. if function doesn't accept parameters by reference ( not function foo(&$x) )
            //      and can't therefore initialize a passed variable, check that the variable exists,
            //      otherwise, report an error
            //  2. if function does accept &$vars, check that variable, not an expression is
            //       actually passed
            //  3. if function does accept params-by-reference, but does not intialize them
            //      (e.g. sort()), check that variable exists
            //
            // Unknown (user-defined) functions can accept vars by reference too,
            // but we don't know about them, so just produce a warning
            //
            // Summary:
            //      known_function_that_assign_variable($unknown_var);          // ok               (processed here)
            //      known_function_that_doesnt_assign_variable($unknown_var);   // error/warning    (processed later)
            //      unknown_function($unknown_var);                             // warning          (here)
            //      unknown_function($unknown_var_in_expression*2);             // error/warning    (later)
            //
            if ($t->kind == T_STRING) {
                $n = $t->nextNS();
                if ($n->text == '(') {
                    $function_name = $this->getFullyQualifiedFunctionName($pf, $t);
                    $arguments = $pf->extractList($n->nextNS());
                    $is_known_function = false;

                    foreach ($function_knowledge_sources as $s) {
                        if (array_key_exists($function_name, $s)) {
                            $args = $s[$function_name];
                            $is_known_function = true;
                            break;
                        }
                    }

                    if ($is_known_function) {
                        // For known functions:
                        //  - mark variables that are used as passed-by-reference return arguments as known
                        //  - do nothing with variables that are not returned by function - they will be checked later
                        if ($args) {
                            foreach ($args as $argPos) {
                                if (count($arguments) > $argPos) {
                                    $n = $arguments[$argPos];
                                    if ($n->text == '&') {
                                        $n = $n->nextNS();
                                    }

                                    if ($n->kind == T_VARIABLE) {
                                        if (isset(self::$internalFunctionsThatDoesntInitializePassedByReferenceParams[$function_name])) {
                                            // if the function takes parameters by reference, but they must be defined prior to that
                                            // (e.g. sort), than check that this var exists
                                            $this->checkVarAndAddDefectIfMissing($n);
                                        } else {
                                            // otherwise, just note that this var will be initialized by this method call
                                            $var = $this->currentScope->getOrCreateVar($n);
                                            $var->status = self::VAR_ASSIGNED;
                                        }
                                    } else {
                                        // warn about non-variable being passed by reference
                                        // allow static class variable: Foo::$bar, \Foo\Bar::$baz
                                        $is_class_variable = false;
                                        if ($n->kind == T_STRING || $n->kind == T_NS_SEPARATOR) {
                                            list($type, $t) = self::parseType($n);
                                            if ($t->kind == T_DOUBLE_COLON && $t->nextNS()->kind == T_VARIABLE) {
                                                $is_class_variable = true;
                                            }
                                        }

                                        if (!$is_class_variable) {
                                            $this->addDefect($n, self::E_NON_VAR_PASSED_BY_REF);
                                        }
                                    }
                                }
                            }
                        }
                    } else {
                        // For unknown functions:
                        // If argument look like a single variable (not a part of a complex expression),
                        // it too can be passed/returned/initialized by function.
                        // Issue a warning if this variable is not known
                        foreach ($arguments as $n) {
                            if ($n->text == '&') {
                                $n = $n->nextNS();
                            }
                            if ($n->kind == T_VARIABLE) {
                                $nn = $n->nextNS();
                                if ($nn->text==',' || $nn->text==')') {
                                    // single variable - check that it exists but if not issue a warning only,
                                    // cause it can be initialized by this unknown functiton
                                    $this->checkVarAndAddDefectIfMissing($n, self::E_UNKNOWN_VAR_ARGUMENT);
                                }
                            }
                        }
                    }
                }
                continue;
            }

            // test for variable in relaxed mode only:
            //      if (isset($variable)) ...   // this makes $variable "known" in relaxed mode
            //      if (!empty($variable)) ...
            // No expressions as function argument:
            //      isset( $foo["bar"] ); // doesn't make $foo "declared", it must exist or this is an error
            if ($t->kind==T_ISSET || $t->kind==T_EMPTY) {
                $n = $t->nextNS();
                if ($n && $n->text=='(') {
                    $nn = $n->nextNS();
                    if ($nn && $nn->kind==T_VARIABLE) {
                        $nnn = $nn->nextNS();
                        if ($nnn && $nnn->text==')') {
                            // ok, this is a simple expression with a variable inside function call
                            if ($this->currentScope->mode==self::MODE_RELAXED) {
                                // mark this variable as "known" in relaxed mode
                                $var = $this->currentScope->getOrCreateVar($nn);
                                $var->status = self::VAR_ASSIGNED;
                            } else {
                                // skip till the end of statement in strict mode
                                $i = $nnn->index;
                                continue;
                            }
                        }
                    }
                }
                continue;
            }

            // doc comment (/** */) annotations
            // 1. Type info about variables (/** @var Foo $bar */)
            // 2. Variable declaration in relaxed mode (/** @var $foo */)
            if ($t->kind == T_DOC_COMMENT) {
                $variables_list = self::parseDocComment($t->text);
                $is_relaxed_mode = $this->currentScope->mode == self::MODE_RELAXED;
                foreach ($variables_list as $var_name => $var_type) {
                    if ($var_type) {
                        $this->currentScope->setVarType($var_name, $var_type);
                    }
                    if ($is_relaxed_mode) {
                        $this->currentScope->getOrCreateVarByName($var_name);
                    }
                }
                continue;
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

                // skip variables in the global scope, because it's often polluted by vars
                // included from included/required files
                if ($checkGlobalScope==false && $this->currentScope->isGlobal) {
                    $skipVariable = true;
                }

                if (!$skipVariable) {
                    $this->checkVarAndAddDefectIfMissing($t);
                }
            }
        } // end of "for each token" loop

        return $this->report;
    }

    // input: start token, text value
    // output: first token that follows the start token and equals to the given text
    // Helpful to find end-of-the statement (terminated by ';') of the start token
    private static function skipTillText($token, $text) {
        while ($token) {
            if ($token->text == $text) {
                return $token;
            }
            $token = $token->nextNS();
        }
        return null;
    }

    // initialize:
    //  1. self::$internalFunctions
    //      - the list of internal php function with their arguments that can be passed by reference
    //  2. self::$internalFunctionsThatDoesntInitializePassedByReferenceParams
    //
    private static function initialize_internal_php_function_list() {
        //
        // use PHP introspection to get all known functions
        //
        $defined_functions = get_defined_functions();
        $internal_functions = $defined_functions["internal"];
        foreach ($internal_functions as $function_name) {
            $r = new ReflectionFunction($function_name);
            $params = $r->getParameters();
            $ref_params_list = array();
            foreach ($params as $p) {
                if ($p->isPassedByReference()) {
                    $pos = $p->getPosition();
                    if ($p->getName() == "...") {
                        // functions like sscanf takes unlimited number of args
                        // TODO: create a better way to work with unlimited lists
                        for ($i = $pos; $i<10; ++$i) {
                            $ref_params_list[] = $i;
                        }
                    } else {
                        $ref_params_list[] = $pos;
                    }
                }
            }
            self::$internalFunctions[$function_name] = (count($ref_params_list)) ? $ref_params_list : null;
        }

        // add functions that are defined in extensions that the given PHP runtime may miss
        // e.g. my dev box misses apc extension
        // array_multisort is another exception - it may pass several args by reference, but
        // only the first one is guaranteed
        $override_list = array(
            "apc_fetch"               => array(1),
            'apc_dec'                 => array(2),
            'apc_inc'                 => array(2),
            'grapheme_extract'        => array(4),
            'ncurses_color_content'   => array(1, 2, 3),
            'ncurses_getmaxyx'        => array(1, 2),
            'ncurses_getmouse'        => array(0),
            'ncurses_getyx'           => array(1, 2),
            'ncurses_instr'           => array(0),
            'ncurses_mouse_trafo'     => array(0, 1),
            'ncurses_mousemask'       => array(1),
            'ncurses_pair_content'    => array(1, 2),
            'ncurses_wmouse_trafo'    => array(1, 2),
            'numfmt_parse'            => array(3),
            'numfmt_parse_currency'   => array(2, 3),
            'pcntl_waitpid'           => array(1),
            "pcntl_wait"              => array(0),
            "array_multisort"         => array(0),
        );

        foreach ($override_list as $function_name => $args) {
            self::$internalFunctions[$function_name] = $args;
        }

        //  some functions take pass-by-reference params but they don't initialize them,
        //  the params must already exist, e.g. bool sort ( array &$array [, int $sort_flags] )
        $exceptionList = array(
            'array_multisort', 'array_pop', 'array_push', 'array_shift', 'array_splice', 'array_unshift',
            'array_walk', 'array_walk_recursive', 'arsort', 'asort', 'call_user_method',
            'call_user_method_array', 'current', 'each', 'end', 'extract', 'key', 'krsort', 'ksort',
            'mb_convert_variables', 'natcasesort', 'natsort', 'next', 'openssl_csr_new', 'pos', 'prev',
            'reset', 'rsort', 'settype', 'shuffle', 'sort', 'uasort', 'uksort', 'usort', 'xml_set_object',
        );
        self::$internalFunctionsThatDoesntInitializePassedByReferenceParams = array_fill_keys($exceptionList, true);
    }

    private static function &getUserFunctionsFromCurrentFile(XRef_IParsedFile $pf) {
        $functions = array();

        // add functions/methods defined in this file
        $pf_methods = $pf->getMethods();
        foreach ($pf_methods as $m) {
            $function_name = $m->name;
            if (!$function_name) {
                // anonymous function
                continue;
            }
            if ($function_name=='__construct') {
                // constructors are too different from regular functions/methods
                // in decl/usage syntax
                continue;
            }

            $ref_params_list = array();
            for ($i=0; $i<count($m->parameters); ++$i) {
                if ($m->parameters[$i]->isPassedByReference) {
                    $ref_params_list[] = $i;
                }
            }
            if (!count($ref_params_list)) {
                $ref_params_list = null;
            }

            if ($m->className) {
                $functions[ $m->className . '::' . $function_name ] = $ref_params_list;
            } else {
                $functions[$function_name] = $ref_params_list;
            }
        }

        return $functions;
    }

    private static function &getUserFunctionsFromConfig() {
        $functions = array();

        // add user-defined functions/methods from config file
        // add-function-signature = "my_function($a, $b, &$c)"
        // add-function-signature = "MyClass::myMethod($a, $b, &$c)"
        // add-function-signature = "?::myMethod($a, $b, &$c)"
        foreach (XRef::getConfigValue("lint.add-function-signature", array()) as $str) {
            // TODO: tokenize all $str and get rig of regular expressions
            if (!preg_match('#^\s*(?:(\w+|\?)::)?(\w+)\s*\((.+)\)\s*$#', $str, $matches)) {
                throw new Exception("Can't parse function specification from config file: $str");
            }

            if ($matches[1]) {
                $class_name = $matches[1];
                $function_name = $matches[2];
            } else {
                $class_name = null;
                $function_name = $matches[2];
            }

            $ref_params_list = array();
            $arg_list = explode(',', $matches[3]);
            for ($i = 0; $i < count($arg_list); ++$i) {
                $t = $arg_list[$i];
                if (preg_match('#^\s*&#', $t)) {
                    $ref_params_list[] = $i;
                }
            }

            if (!count($ref_params_list)) {
                $ref_params_list = null;
            }

            if ($class_name) {
                $functions[ $class_name . '::' . $function_name ] = $ref_params_list;
            } else {
                $functions[$function_name] = $ref_params_list;
            }
        }
        return $functions;
    }

    // input: token where starts the (optionally typed) declaration of variable
    // output: tuple(fully qualified type name, next token)
    // e.g.
    //      $foo         --> (null, $foo)
    //      string &$foo --> ('string', &)
    //      \Foo\Bar $z  --> ('\Foo\Bar', $z)
    private static function parseType($token) {
        // types:               array | ClassName | string | int | bool
        // namespaced types:    \string | foo\bar | \foo\bar
        // note: when parsing namespaces in PHP 5.2, "\" will be lost
        $typeParts = array();
        if ($token->kind == T_ARRAY) {
            $typeParts[] = 'array';
            $token = $token->next();
        } else {
            while ($token->kind == T_STRING || $token->kind == T_NS_SEPARATOR) {
                $typeParts[] = $token->text;
                $token = $token->next(); // not nextNS()!
            }
        }
        if ($token->isSpace()) {
            $token = $token->nextNS();
        }
        $type = ($typeParts) ? implode("", $typeParts) : null;
        return array($type, $token);
    }

    // Foo::bar()               --> "Foo::bar"
    // $this->bar()             --> "Foo::bar" or "?::bar" (outside of known class def)
    // self::bar()              --> "Foo::bar" or "?::bar" (outside of known class def)
    // $unknownTypeVar->bar()   --> "?::bar"
    // $knownTypeVar->bar()     --> "VarType::bar"
    // bar()                    --> "bar"
    private function getFullyQualifiedFunctionName($pf, $token) {
        $function_name = $token->text;
        $p = $token->prevNS();
        $pp = $p->prevNS();
        $current_class = $pf->getClassAt( $token->index );

        if ($p->kind == T_OBJECT_OPERATOR) {
            if ($pp->text == '$this') {
                if ($current_class) {
                    return $current_class->name . "::" . $function_name;
                }
            } elseif ($pp->kind == T_VARIABLE) {
                $type = $this->currentScope->getVarType($pp->text);
                if ($type) {
                    // TODO: check that the type is a class name and not e.g. an array or int.
                    // Otherwise, add defect "Can't call method on value of <...> type"
                    return $type . "::" . $function_name;
                }
            }
            return "?::" . $function_name;
        } elseif ($p->kind == T_DOUBLE_COLON) {
            if ($pp->text == 'self') {
                if ($current_class) {
                    return $current_class->name . "::" . $function_name;
                }
                return "?::" . $function_name;
            } else {
                // TODO: scan left for complex type names: \Foo\Bar::baz()
                return $pp->text . "::" . $function_name;
            }
        }
        return $function_name;
    }

    // input: doc comment text
    // output: array( '$variable_name' => 'variable type', '$name' => null, ...)
    // TODO: this function is made public static for unit tests only
    // TODO: move it to Parser class and change interface
    public static function parseDocComment($doc_comment_text) {
        // looking for annotations like
        // @var [optionalTypeName] $variable_name1, $variable_name2 , e.g.
        //      @var Foo $bar
        //      @var \Foo\Bar $baz
        //      @var $foo, $bar
        $result = array();
        if (preg_match_all('#@var\s+(?:([\w\\\\]+)\s+)?(\$\w+(?:\s*,\s*\$\w+)*)#', $doc_comment_text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $type = ($m[1]) ? $m[1] : null;
                $var_list = explode(',', $m[2]);
                foreach ($var_list as $v) {
                    $result[ trim($v) ] = $type;
                }
            }
        }
        return $result;
    }
}

class XRef_Lint_UninitializedVars_Scope {
    public $start;              // index of first token of the scope
    public $end;                // index of the last token
    public $vars     = array(); // variable name --> stdObject
    public $varTypes = array(); // variable name --> string typeName
        // variable type is not a part of var stdObject because
        // type annotation can precede the variable
    public $mode = XRef_Lint_UninitializedVars::MODE_STRICT;
    public $isGlobal = false;
    public $isLoopMode = false;
    public $loopVars = array();

    public function __construct($start, $end) {
        $this->start = $start;
        $this->end = $end;
    }

    public function getOrCreateVarByName($var_name) {
        if (!isset($this->vars[$var_name])) {
            $this->vars[$var_name] = (object) array(
                "token"      => null,
                "isRefParam" => false,  // parameter of function passed by reference: function foo(&$bar)
                "isCatchVar" => false,  // variable of try {} catch (E $foo) block
                "isGlobal"   => false,
            );
        }
        return $this->vars[$var_name];
    }

    public function getOrCreateVar($token) {
        $var = $this->getOrCreateVarByName($token->text);
        $var->token = $token;
        return $var;
    }

    public function setVarType($var_name, $var_type) {
        $this->varTypes[$var_name] = $var_type;
    }

    public function getVarType($varName) {
        return (isset($this->varTypes[$varName])) ? $this->varTypes[$varName] : null;
    }

    public function checkVar($token) {
        $varName = $token->text;
        if (isset(XRef_Lint_UninitializedVars::$knownSuperglobals[$varName])) {
            return true;
        } else {
            return isset($this->vars[$varName]);
        }
    }

}

// vim: tabstop=4 expandtab
