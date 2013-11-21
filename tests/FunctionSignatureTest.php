<?php

require_once dirname(__FILE__) . "/BaseLintClass.php";

class FunctionSignatureTest extends BaseLintClass {

    public function t_estBuildInFunction() {
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


}
