<?php

require_once dirname(__FILE__) . "/BaseLintTest.php";

class UndefinedVarsLintTest extends BaseLintTest {

    public function testSuperGlobals() {
        $testPhpCode = '
        <?php
            // globals cant triggers at all, neither in global scope or in funcitons
            echo $GLOBALS["i"];                                         // ok
            $var = $_REQUEST["foo"] + $_GET["bar"] + $_POST["baz"]      // ok + ok + ok
            if ($_FILES["filename"]) {                                  // ok
                $var++;                                                 //
            }
            echo $_ENV["PATH"];                                         // ok
            call_unknown_function($_SERVER, $_COOKIE, $_SESSION);       // ok, ok, ok
            // less-commonly used vars:
            echo $HTTP_RAW_POST_DATA;                                   // ok
            echo $http_response_header;                                 // ok
            echo $php_errormsg;                                         // ok

            echo $explicit_defect1;                                     // warning

            // the same checks in local scope
            function foo() {
                echo $GLOBALS["i"];                                     // ok
                $var = $_REQUEST["foo"] + $_GET["bar"] + $_POST["baz"]; // ok
                if ($_FILES["filename"]) {                              // ok
                    $var++;                                             //
                }
                echo $_ENV["PATH"];                                     // ok
                call_unknown_function($_SERVER, $_COOKIE, $_SESSION);   // ok, ok, ok
                // less-commonly used vars:
                echo $HTTP_RAW_POST_DATA;                               // ok
                echo $http_response_header;                             // ok
                echo $php_errormsg;                                     // ok

                echo $explicit_defect2;                                 // error
            }
        ';
        $expectedDefects = array(
            array('$explicit_defect1', 16, XRef::WARNING),
            array('$explicit_defect2', 32, XRef::ERROR),
        );
        $this->checkPhpCode($testPhpCode, $expectedDefects);
    }

    public function testGlobals() {
        $testPhpCode = '
        <?php
            // globals can be used in global scope only and triggers error in local scope (unless explicitly imported)
            echo $argc;     // ok
            echo $argv;     // ok

            function foo () {
                echo $argc;     // error
                echo $argv;     // error
            }

            function bar ($bar) {
                $$bar = 1;      // switch to relaxed mode
                echo $argc;     // warning
                echo $argv;     // warning
            }

            function baz () {
                global $argc, $argv;
                echo $argc;     // ok
                echo $argv;     // ok
            }
        ';

        $exceptedDefects = array(
            array('$argc', 8, XRef::ERROR),
            array('$argv', 9, XRef::ERROR),
            array('$argc', 14, XRef::WARNING),
            array('$argv', 15, XRef::WARNING),
        );
        $this->checkPhpCode($testPhpCode, $exceptedDefects);
    }

    public function testAutovivification() {
        $testPhpCode = '
        <?php
            function foo () {
                echo $i;                // error
                $j = 10;
                echo $j;                // ok

                $line++;                // warning  - autovivification of scalar
                echo $line;             // ok
                $x += 10;               // warning
                $y .= " ";              // warning

                $a["foo"] = 10;         // warning - autovivification of array
                print_r($a);            // ok
                $b[$x][$y] .= "<br>";   // warning
                $b[$z]++;               // error on $z
            }
        '
        ;
        $exceptedDefects = array(
            array('$i', 4, XRef::ERROR),
            array('$line', 8, XRef::WARNING),
            array('$x', 10, XRef::WARNING),
            array('$y', 11, XRef::WARNING),
            array('$a', 13, XRef::WARNING),
            array('$b', 15, XRef::WARNING),
            array('$z', 16, XRef::ERROR),
        );
        $this->checkPhpCode($testPhpCode, $exceptedDefects);


    }

    public function testVariablesAssignedByFunctions () {

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

        $testPhpCode = '
        <?php
            function foo () {
                // internal functions
                preg_match("#pattern#", "string-to-be-mateched", $matches);     // ok
                preg_match("#pattern#", "string-to-be-mateched", null);         // error (non-var pass by ref)
                preg_grep("pattern", $input);                                   // error
                sort($array);                                                   // error
                sort( array(1,2,3) );                                           // error (non-var pass by ref)

                // locally-defined functions
                local_function_with_pass_by_reference_argument2(1, $var2);      // ok
                local_function_with_pass_by_reference_argument2($var3, $var4);  // error in $var3 only

                // unknown functions
                unknown_function($unknown_var);                                 // warning
                unknown_function($unknown_var_in_expression*2);                 // error
            }

            function bar ($args) {
                extract($args);                                                 // relaxed mode from here

                // internal functions
                preg_match("#pattern#", "string-to-be-mateched", $matches);     // ok
                preg_match("#pattern#", "string-to-be-mateched", null);         // error (non-var pass by ref)
                preg_grep("pattern", $input);                                   // warning
                sort($array);                                                   // warning
                sort( array(1,2,3) );                                           // error (non-var pass by ref)

                // locally-defined functions
                local_function_with_pass_by_reference_argument2(1, $var2);      // ok
                local_function_with_pass_by_reference_argument2($var3, $var4);  // warning in $var3 only

                // unknown functions
                unknown_function($unknown_var);                                 // warning
                unknown_function($unknown_var_in_expression*2);                 // warning
            }

            function local_function_with_pass_by_reference_argument2($arg1, &$arg2) {
                $arg2 = $arg1;
            }

            sort( array(1,2,3) );                                               // error (non-var pass by ref)
            sort( Foo::$bar );                                                  // ok

        ';

        $expectedDefects = array(
            array('null',                   6,  XRef::ERROR),
            array('$input',                 7,  XRef::ERROR),
            array('$array',                 8,  XRef::ERROR),
            array('array',                  9,  XRef::ERROR),
            array('$var3',                  13, XRef::ERROR),
            array('$unknown_var',           16, XRef::WARNING),
            array('$unknown_var_in_expression', 17, XRef::ERROR),

            array('null',                   25,  XRef::ERROR),
            array('$input',                 26,  XRef::WARNING),
            array('$array',                 27,  XRef::WARNING),
            array('array',                  28,  XRef::ERROR),
            array('$var3',                  32, XRef::WARNING),
            array('$unknown_var',           35, XRef::WARNING),
            array('$unknown_var_in_expression', 36, XRef::WARNING),

            array('array',                  43,  XRef::ERROR),
        );
        $this->checkPhpCode($testPhpCode, $expectedDefects);

        $testPhpCode = '
        <?php
            class Foo {
                public function preg_match() {}
                public function sort(&$x)    {}

                public function bar() {
                    $this->preg_match("", "", $x);      // error: method preg_match dont initialize vars
                    Foo::preg_match("", "", $y);        // error
                    self::preg_match("", "", $z);       // error
                    preg_match("", "", $ok);            // ok, this is internal preg_match

                    $this->sort($a);                    // ok
                    Foo::sort($b);                      // ok
                    self::sort($c);                     // ok
                    sort($d);                           // error - internal sort doesnt intialize vars
                }
            }

            function test () {
                Foo::preg_match("", "", $i);                // error
                preg_match("", "", $j);                     // ok
                $foo = new SomeClass();
                $foo->preg_match("", "", $k);               // warning: this is unknown preg_match

                Foo::sort($l);                              // ok
                sort($m);                                   // error, internal sort
                $foo->sort($n);                             // warning, unknown sort
            }

            Foo::preg_match("", "", $i);                // warning (global relaxed scope, otherwise - error)
            preg_match("", "", $j);                     // ok
            $foo = new SomeClass();
            $foo->preg_match("", "", $k);               // warning: this is unknown preg_match

            Foo::sort($l);                              // ok
            sort($m);                                   // warning, internal sort
            $foo->sort($n);                             // warning, unknown sort
        '
        ;

        $expectedDefects = array(
            array('$x', 8,   XRef::ERROR),
            array('$y', 9,   XRef::ERROR),
            array('$z', 10,  XRef::ERROR),
            array('$d', 16,  XRef::ERROR),

            array('$i', 21,  XRef::ERROR),
            array('$k', 24,  XRef::WARNING),
            array('$m', 27,  XRef::ERROR),
            array('$n', 28,  XRef::WARNING),

            array('$i', 31,  XRef::WARNING),
            array('$k', 34,  XRef::WARNING),
            array('$m', 37,  XRef::WARNING),
            array('$n', 38,  XRef::WARNING),
        );
        $this->checkPhpCode($testPhpCode, $expectedDefects);

        //
        XRef::setConfigValue(
            'lint.init-by-reference',
            array(
                "foo,0",
                "Foo::bar,1",
                "?::baz,0,1"
            )
        );
        $testPhpCode = '
        <?php
            function t() {
                foo($x);                    // ok
                foo($y, $z);                // error on $z

                Foo::bar(1, $a);            // ok
                Foo::bar($b, $c);           // error on $b

                $x->baz($i, $j);            // ok
                $x->foo->baz($k, $l, $m);   // error on $m

            }
        '
        ;

        $expectedDefects = array(
            array('$z', 5,   XRef::ERROR),
            array('$b', 8,   XRef::ERROR),
            array('$m', 11,   XRef::ERROR),
        );
        $this->checkPhpCode($testPhpCode, $expectedDefects);

    }

    // test to check constructs like
    //  function foo () {
    //      static $foo, $bar = 1, $baz;
    //  }
    // as well as other static constructs
    public function testStaticDecl () {
        $testPhpCode = '
            <?php

            function foo () {
                static $foo;                    // ok
                static $bar, $baz;              // ok
                static $qux = 10, $qaz, $qix=1; // ok

                echo $foo, $bar + $baz;         // ok
                echo $qux, $qaz + $qix;         // ok
                echo $i;                        // error
            }

            class Bar {
                public function bar () {
                    if (is_subclass_of(static::$instance, "Foo")) { // ok
                        return static::baz();                       // ok
                    } else {
                        return new static(25);                      // ok
                    }
                    return new static;
                }
            }
            echo $foo;                          // error
        '
        ;
        $expectedDefects = array(
            array('$i',     11,  XRef::ERROR),
            array('$foo',   24,  XRef::WARNING),
        );
        $this->checkPhpCode($testPhpCode, $expectedDefects);
     }

    public function testNestedFunctions() {
        $testPhpCode = '
        <?php
            function foo($x) {
                $y = 10;                                // ok
                $f = function ($z) use ($x, & $y) {     // ok
                    return $z * ($x + $y);              // ok
                    echo $i;                            // error
                };
                echo $z;                                // error
                echo $i;                                // error
                echo $x, $y;                            // ok

                $g = function ($p) use (&$q) {          // error - no $q in outer scope
                    return $p * $q;                     // ok
                };
                echo $q;                                // ok, dont report $q twice
            }
        ';
        $expectedDefects = array(
            array('$i', 7,  XRef::ERROR),
            array('$z', 9,  XRef::ERROR),
            array('$i', 10,  XRef::ERROR),
            array('$q', 13,  XRef::ERROR),
        );
        $this->checkPhpCode($testPhpCode, $expectedDefects);

        $testPhpCode = '
        <?php
            class Foo {
                public static function bar($arg) {
                    $closure = function($v) use ($arg) {
                        return $v * $arg;
                    };
                    return $closure(1);
                }
            }
        '
        ;
        $expectedDefects = array();
        $this->checkPhpCode($testPhpCode, $expectedDefects);

    }

    public function testRelaxedMode() {
        $testPhpCode = '
        <?php
            function foo($x) {
                echo $y;            // error
                $$x = "foo";        // relaxed mode starts here
                echo $z;            // warning
            }
            function bar() {
                global $i;
                echo $i;            // ok
                echo $j;            // error
                $$i["key"] = "foo"; // relaxed mode starts here
                echo $z;            // warning
            }
         ';
        $expectedDefects = array(
            array('$y', 4,  XRef::ERROR),
            array('$z', 6,  XRef::WARNING),
            array('$j', 11,  XRef::ERROR),
            array('$z', 13,  XRef::WARNING),
        );
        $this->checkPhpCode($testPhpCode, $expectedDefects);

    }

}

