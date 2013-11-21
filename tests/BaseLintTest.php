<?php

require_once dirname(__FILE__) . "/BaseLintClass.php";

class BaseLintTest extends BaseLintClass {

    // very basic usage of lint plugins
    // see also test class for each plugin
    public function testBasicLintUsage() {
        // Can't use nowdocs before php 5.3.0, and heredocs strings are interpolated :(
        $testPhpCode = '<?php


        // ---------------
        // Variables usages
        // ---------------
        // superglobals
        $foo = $_GET["bar"];                // ok

        // top-level vars:
        $foo = $argv;                       // ok
        $foo = $argc;                       // ok
        function foo_1 () { $foo = $argv; } // error (0)
        function foo_2 () { $foo = $argc; } // error (1)

        // initialiazed-vars:
        $foo1 = 10;
        $foo = $foo1;                       // ok
        $foo = $foo2;                       // warning (2)


        // ---------------
        // $this in static methods
        // ---------------
        class Foo {
            public function bar() {
                $this->textField++;         // ok
            }
            public static function baz() {
                $this->textField++;         // error (3)
            }
        }

        function qux() {
            $this->textField++;             // error (4)
        }


        // ---------------
        // non-upper case literals
        // ---------------
        echo MyClass.method();              // warning (5)
        '
        ;

        $exceptedDefects = array(
            array('$argv', 13, XRef::ERROR),
            array('$argc', 14, XRef::ERROR),
            array('$foo2', 19, XRef::WARNING),
            array('$this', 30, XRef::ERROR),
            array('$this', 35, XRef::ERROR),
            array('MyClass', 42, XRef::WARNING),
        );
        $this->checkPhpCode($testPhpCode, $exceptedDefects);
    }

    /**
     * @expectedException Exception
     */
    public function testException() {
        $code = '<?php
            // some invalid php code
            function foo(...) {}
        ';
        $this->checkPhpCode($code, array());
    }

}
