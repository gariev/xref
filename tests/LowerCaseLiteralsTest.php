<?php

require_once dirname(__FILE__) . "/BaseLintTest.php";

class LowerCaseLiteralsTest extends BaseLintTest {

   public function NONtestLiteral() {
        $testPhpCode = '
        <?php
            echo time;                                  // warning
            echo Foo::bar();                            // ok
            function foo(Exception $a, ClassName &$b);  // ok
            $foo = array();
            echo $foo[x];                               // warning
            try {} catch (Exception $e) {};             // ok
            try {} catch (\Exception $e) {};            // ok
        ';
        $exceptedDefects = array(
            array('time', 3, XRef::WARNING),
            array('x', 7, XRef::WARNING),
        );
        $this->checkPhpCode($testPhpCode, $exceptedDefects);
    }

    public function testNamespacedNames() {
        $testPhpCode = '
        <?php
            namespace foo\bar;                  // ok
            use bar\foo as anotherFoo;          // ok
            function foo( \bar\foo\baz $x ) {}  // ok
            \Foo\Bar::baz();                    // ok
            $foo = new \Foo\Bar\Baz();          // ok
            $foo->bar();                        // ok
            echo Foo::$bar;                     // ok
            echo \Foo\Bar::$bar;                // ok
            echo ExpectedWarning;               // warning
        ';

        $exceptedDefects = array(
            array('ExpectedWarning', 11, XRef::WARNING),
        );
        $this->checkPhpCode($testPhpCode, $exceptedDefects);
    }
}

