<?php

require_once dirname(__FILE__) . "/BaseLintClass.php";

class FunctionSignatureTest extends BaseLintClass {

    public function testBuildInFunction() {
        $codeA =
        '<?php
            $foo = sprintf("a", "b");   // ok
            $foo = sprintf();           // warning (wrong number of args)
            $foo = unknown_func();      // warning (unknown function)

            preg_match();               // warning
            preg_match("##");           // warning
            preg_match("##", "");                   // ok
            preg_match("##", "", $matches);         // ok
            preg_match("##", "", $matches, 0);      // ok
            preg_match("##", "", $matches, 0, 1);   // ok
            preg_match("##", "", $matches, 0, 1, 2);// warning
        ';
         $this->checkProject(
            array( 'fileA.php' => $codeA ),
            array(
                array('fileA.php', 'sprintf',       XRef::WARNING, 3),
                array('fileA.php', 'unknown_func',  XRef::WARNING, 4),
                array('fileA.php', 'preg_match',    XRef::WARNING, 6),
                array('fileA.php', 'preg_match',    XRef::WARNING, 7),
                array('fileA.php', 'preg_match',    XRef::WARNING, 12),
            )
        );
    }

    public function testUserDefinedFunction() {
        $codeA =
        '<?php
            function foo()                  {   }
            function bar($a1)               {   }
            function baz($a1, $a2 = false)  {   }
        ';
        $codeB =
        '<?php
            foo();          // ok
            foo(1)          // warning

            bar();          // warning
            bar(1);         // ok
            bar(1, 2);      // warning

            baz();          // warning
            baz(1);         // ok
            baz(1, 2);      // ok
            baz(1, 2, 3);   // warning
        ';
         $this->checkProject(
            array( 'fileA.php' => $codeA, 'fileB.php' => $codeB ),
            array(
                array('fileB.php', 'foo', XRef::WARNING, 3),
                array('fileB.php', 'bar', XRef::WARNING, 5),
                array('fileB.php', 'bar', XRef::WARNING, 7),
                array('fileB.php', 'baz', XRef::WARNING, 9),
                array('fileB.php', 'baz', XRef::WARNING, 12),
            )
        );
    }

    public function testUnqualifiedMethodCall() {
        $codeA =
        '<?php
            function foo($a) {}
            class Foo {
                public function foo() { }
                public function bar() { }
                public static function baz() {}

                public function test() {
                    foo(1);         // ok, global function foo()
                    $this->foo();   // ok, method foo();
                    bar();          // warning - it should be $this->bar();
                    baz();          // warning - it should be $this->bar();
                }
            }
        ';
        $this->checkProject(
            array( 'fileA.php' => $codeA ),
            array(
                array('fileA.php', 'bar', XRef::WARNING, 11),
                array('fileA.php', 'baz', XRef::WARNING, 12),
            )
        );
    }

    public function testStaticMethodCall() {
        $codeA =
        '<?php
            function foo($a) {}
            class Foo {
                public static function foo() { }
                public static function bar() { }
                public static function baz() {}

                public static function test() {
                    foo(1);         // ok, global function foo()
                    self::foo();    // ok, method foo();
                    Foo::bar();     // ok, method bar()
                    static::baz();  // ok, method baz();
                    bar();          // warning - it should be self::bar();
                }
            }
        ';
        $this->checkProject(
            array( 'fileA.php' => $codeA ),
            array(
                array('fileA.php', 'bar', XRef::WARNING, 13),
            )
        );
    }


    public function testFunctionsWithBrokenReflections() {
        $codeA =
        '<?php
            define("foo");                      // warning
            define("foo", 1);                   // ok
            define("foo", 2, true);             // ok
            define("foo", 2, true, "extra");    // warning

            $something = array();
            implode();                          // warning
            implode($something);                // ok
            implode(",", $something);           // ok
            implode(",", $something, "more");   // warning

            spl_autoload_register();                            // ok
            spl_autoload_register("my_func");                   // ok
            spl_autoload_register("my_func", true);             // ok
            spl_autoload_register("my_func", true, true);       // ok
            spl_autoload_register("my_func", true, true, true); // warning
        ';
        $this->checkProject(
            array( 'fileA.php' => $codeA ),
            array(
                array('fileA.php', 'define',    XRef::WARNING, 2),
                array('fileA.php', 'define',    XRef::WARNING, 5),
                array('fileA.php', 'implode',   XRef::WARNING, 8),
                array('fileA.php', 'implode',   XRef::WARNING, 11),
                array('fileA.php', 'spl_autoload_register', XRef::WARNING, 17),
            )
        );
    }

    public function testMethodsThis() {
        $codeA =
        '<?php
            function foo($a) {}
            class A {
                public function foo($a, &$b) {}
            }

            class B extends A {
                public function bar(A $z = false) {}
                public function test() {
                    foo();              // global foo, warning
                    foo(1);             // ok
                    foo(1, 2);          // warning

                    $this->foo();       // warning
                    $this->foo(1);      // warning
                    $this->foo(1, 2);   // ok
                    $this->foo(1, 2, 3);// warning

                    $this->bar();       // ok
                    $this->bar(1);      // ok
                    $this->bar(1, 2);   // warning
                }
            }
        ';
        $this->checkProject(
            array( 'fileA.php' => $codeA ),
            array(
                array('fileA.php', 'foo',   XRef::WARNING, 10),
                array('fileA.php', 'foo',   XRef::WARNING, 12),
                array('fileA.php', 'foo',   XRef::WARNING, 14),
                array('fileA.php', 'foo',   XRef::WARNING, 15),
                array('fileA.php', 'foo',   XRef::WARNING, 17),
                array('fileA.php', 'bar',   XRef::WARNING, 21),
            )
        );
    }

    /**
     * @requires PHP 5.3
     */
    public function testNamespacedFunctions() {
        $codeA =
        '<?php
            namespace foo {
                function foo($a) {}
                function fopen() {}
            }
            namespace foo\\bar {
                function foo($a, $b) {}
            }
        ';
         $codeB =
        '<?php
            namespace foo;
            use foo\\bar as B;

            foo(1);                     // ok, \foo\foo;
            \\foo\\foo(1);              // ok, \foo\foo;
            foo();                      // warning, number of args

            \\foo\\bar\\foo(1, 2);      // ok \foo\bar\foo()
            B\\foo(1, 2);               // ok, same
            bar\\foo(1, 2);             // ok, same
            \\foo\\bar\\foo();          // warning, number of args, \foo\bar\foo()
            B\\foo(1);                  // warning, same
            bar\\foo(1,2,3);            // warning, same

            fopen();                    // ok, this is \foo\fopen()
            fopen("f", "r");            // warning, number of args
            \\fopen("f", "r");          // ok, global fopen
            \\fopen();                  // warning, global fopen, number of args

            fclose(1);                  // ok, global fclose
            \\fclose(1);                // ok, global fclose
            fclose();                   // warning
            \\fclose(1,2,3);            // warning

        ';
        $this->checkProject(
            array( 'fileA.php' => $codeA, 'fileB.php' => $codeB ),
            array(
                array('fileB.php', 'foo\\foo',      XRef::WARNING, 7),

                array('fileB.php', 'foo\\bar\\foo', XRef::WARNING, 12),
                array('fileB.php', 'foo\\bar\\foo', XRef::WARNING, 13),
                array('fileB.php', 'foo\\bar\\foo', XRef::WARNING, 14),

                array('fileB.php', 'foo\\fopen',    XRef::WARNING, 17),
                array('fileB.php', 'fopen',         XRef::WARNING, 19),

                array('fileB.php', 'fclose',        XRef::WARNING, 23),
                array('fileB.php', 'fclose',        XRef::WARNING, 24),
            )
        );
    }

    public function testConstructorsSimple() {
        $codeA =
        '<?php
            class A { }

            class B extends A {
                public function __construct($a) {}
            }

            class C {
                public function __construct($a, $b) {}
            }

            class D extends C {
                public function __construct($a, $b, $c) {
                    parent::__construct($a, $b);        // ok
                }
            }

            class E extends C {
                public function __construct($a, $b, $c) {
                    parent::__construct($a);            // warning
                }
            }
        ';

        $codeB =
        '<?php
            $a1 = new A();      // ok
            $a2 = new A(1);     // warning

            $b1 = new B();      // warning
            $b2 = new B(1);     // ok
        ';
        $this->checkProject(
            array( 'fileA.php' => $codeA, 'fileB.php' => $codeB ),
            array(
                array('fileA.php', 'C', XRef::WARNING, 20),
                array('fileB.php', 'A', XRef::WARNING, 3),
                array('fileB.php', 'B', XRef::WARNING, 5),
            )
        );
    }

    /**
     * @requires PHP 5.3
     */
    public function testNamespacedConstructors() {
        $codeA =
        '<?php
            namespace foo {
                class A {
                    public function __construct() {}
                }

                class B extends A {
                    public function __construct($a) {
                        parent::__construct();
                    }
                }
            }

            namespace foo\\bar {
                class A {
                    public function __construct($a, $b){}
                }
                class B extends \\foo\\A {
                    public function __construct($a, $b, $c) {
                        parent::__construct($a, $b);        // warning
                    }
                }
                class C extends A {
                    public function __construct($a, $b) {
                        parent::__construct($a, $b);        // ok
                    }
                }
            }
        ';

        $codeB =
        '<?php
            namespace foo;
            use foo\\bar as Bar;

            $a1 = new A();              // ok
            $a2 = new \\foo\\A();       // ok
            $a3 = new A(1);             // warning
            $a4 = new \\foo\\A(1, 2);   // warning

            $b1 = new B();              // warning
            $b2 = new \\foo\\B(1, 2);   // warning
            $b3 = new B(1);             // ok
            $b4 = new \\foo\\B(1);      // ok

            $a5 = new \\foo\\bar\\A(1,2);   // ok
            $a6 = new Bar\\A(1,2);          // ok
            $a7 = new bar\\A(1,2);          // ok
            $a8 = new \\foo\\bar\\A();      // warning
            $a9 = new Bar\\A(1);            // warning
            $aa = new bar\\A(1,2,3);        // warning
        ';
        $this->checkProject(
            array( 'fileA.php' => $codeA, 'fileB.php' => $codeB ),
            array(
                array('fileA.php', 'foo\\A',        XRef::WARNING, 20),

                array('fileB.php', 'foo\\A',        XRef::WARNING, 7),
                array('fileB.php', 'foo\\A',        XRef::WARNING, 8),

                array('fileB.php', 'foo\\B',        XRef::WARNING, 10),
                array('fileB.php', 'foo\\B',        XRef::WARNING, 11),

                array('fileB.php', 'foo\\bar\\A',   XRef::WARNING, 18),
                array('fileB.php', 'foo\\bar\\A',   XRef::WARNING, 19),
                array('fileB.php', 'foo\\bar\\A',   XRef::WARNING, 20),
            )
        );
    }

    public function testFunctionExists() {
        $codeA =
        '<?php
            unknown_function();                 // warning
            if (function_exists(\'unknown_function1\')) {
                unknown_function1();            // ok
                unknown_function1(1,2,3);       // ok
            }
            $foo = \\function_exists("unknown_function2") ?
                unknown_function2() : null;     // ok

            if ($foo->function_exists("unknown_function3")) {
                unknown_function3();            // warning, have no idea what $foo->function_exists() does
            }
        ';

        $codeB =
        '<?php
            unknown_function1();                // warning
            unknown_function2();                // warning
            if (function_exists("unknown_function")) {
                unknown_function();             // ok
            }
        ';
        $this->checkProject(
            array( 'fileA.php' => $codeA, 'fileB.php' => $codeB ),
            array(
                array('fileA.php', 'unknown_function',  XRef::WARNING, 2),
                array('fileA.php', 'unknown_function3', XRef::WARNING, 11),

                array('fileB.php', 'unknown_function1', XRef::WARNING, 2),
                array('fileB.php', 'unknown_function2', XRef::WARNING, 3),
            )
        );
    }


}
