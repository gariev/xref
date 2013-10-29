<?php

require_once dirname(__FILE__) . "/BaseLintTest.php";

class LowerCaseLiteralsTest extends BaseLintTest {

   public function testBasicLiteral() {
        $testPhpCode = '<?php

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
        $testPhpCode = '<?php

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

        $testPhpCode = '<?php

            namespace foo\bar {
                use bar\foo as anotherFoo;          // ok
                function foo( \bar\foo\baz $x ) {}  // ok
                \Foo\Bar::baz();                    // ok
                $foo = new \Foo\Bar\Baz();          // ok
                $foo->bar();                        // ok
                echo Foo::$bar;                     // ok
            }
            namespace \baz\qux {                    // ok
                echo \Foo\Bar::$bar;                // ok
                echo ExpectedWarning;               // warning
            }
        ';

        $exceptedDefects = array(
            array('ExpectedWarning', 13, XRef::WARNING),
        );
        $this->checkPhpCode($testPhpCode, $exceptedDefects);
     }

    public function testTraits() {
        $testPhpCode = '<?php

            trait Foo {
            }
        ';
        $exceptedDefects = array(
        );
        $this->checkPhpCode($testPhpCode, $exceptedDefects);
    }

    public function testClassConstants() {
        $testPhpCode = '<?php

            class Foo {
                const bar = 1;      // ok
                const Baz = 2;      // ok
            }
            echo Foo::bar;          // ok
            echo Foo::Baz;          // ok
            echo ExpectedWarning;   // warning
        ';
        $exceptedDefects = array(
            array('ExpectedWarning', 9, XRef::WARNING),
        );
        $this->checkPhpCode($testPhpCode, $exceptedDefects);
    }

    public function testLocallyDefinedConstants() {
        $testPhpCode = '<?php

            define("foo", 2);
            echo foo;               // ok
            define(\'bar\', 2);
            echo bar;               // ok
            $expr = "baz";
            define($expr, 2);
            echo baz;               // warning
            echo expr;              // warning
            define("asdf" . $expr, 2);
            echo asdf;              // warning
        ';
        $exceptedDefects = array(
            array('baz',  9, XRef::WARNING),
            array('expr', 10, XRef::WARNING),
            array('asdf', 12, XRef::WARNING),
        );
        $this->checkPhpCode($testPhpCode, $exceptedDefects);
    }

    public function testConstants() {
        $testPhpCode = '<?php

            const foo = 1;          // ok
            echo foo;               // ok

            const bar = 2, baz = 3; // ok
            echo bar;               // ok
            echo baz;               // ok

            const a = b;            // warning (b)
            echo a;                 // ok

            class Foo {
                const c = 1;        // ok
                const d = true, e = 2*2; //ok
            }
            echo Foo::c;            // ok
            echo c;                 // warning c
        ';
        $exceptedDefects = array(
            array('b', 10, XRef::WARNING),
            array('c', 18, XRef::WARNING),
        );
        $this->checkPhpCode($testPhpCode, $exceptedDefects);
    }

    public function testPredefinedConstants() {
        $testPhpCode = '<?php

            if (true) ;                 // ok
            if (false);                 // ok
            if (null);                  // ok
            if (True || fAlse || Null); // ok

            function true() {           // its ok in PHP :(
                return false;
            }

            class Foo {
                const NULL = "myNull";  // thats ok too :(
            }

            echo Foo::NULL;
        ';
        $exceptedDefects = array();
        $this->checkPhpCode($testPhpCode, $exceptedDefects);
    }

    public function testLabels() {
        $testPhpCode = '<?php

            goto my_label;
            $x = 1;
            my_label:
            echo $x;
        ';
        $exceptedDefects = array();
        $this->checkPhpCode($testPhpCode, $exceptedDefects);
    }

    public function testClassConstants2() {
        $testPhpCode = '<?php

            class Foo {
                const Bar = 1, BAZ = 10;
                const Bar2 = 1, BAZ2 = 10;  // each error reported only once,
                                            // so, need the second set of canstants
                const SORT_ASC = SORT_ASC;  // dumb but valid
                public function foo() {
                    echo Foo::Bar;          // ok
                    echo self::Bar;         // ok
                    echo Bar;               // warning

                    echo Foo::BAZ;          // ok
                    echo self::BAZ;         // ok
                    echo BAZ;               // warning

                    echo Foo::SORT_ASC;     // ok
                    echo self::SORT_ASC;    // ok
                    echo SORT_ASC;          // ok - system SORT_ASC
                }
            }

            echo Foo::Bar2;                 // ok
            echo Bar2;                      // warning
            echo Foo::BAZ2;                 // ok
            echo BAZ2;                      // warning
            echo Foo::SORT_ASC;             // ok
            echo SORT_ASC;                  // ok

        ';
        $exceptedDefects = array(
            array('Bar',    11, XRef::WARNING),
            array('BAZ',    15, XRef::WARNING),
            array('Bar2',   24, XRef::WARNING),
            array('BAZ2',   26, XRef::WARNING),
        );
        $this->checkPhpCode($testPhpCode, $exceptedDefects);
    }

    public function testConfigConstants() {
        XRef::setConfigValue(
            'lint.add-constant',
            array(
                'foo',
                'FooBar',
                'BAZ',
            )
        );

        $testPhpCode = '<?php

            class Foo {
                const BAZ = 10;
            }

            echo foo;       //ok
            echo FooBar;    // ok
            echo Foo::BAZ;  // ok
            echo BAZ;       // ok, global BAZ
            echo unknown;   // warning
        ';
        $exceptedDefects = array(
            array('unknown',   11, XRef::WARNING),
        );
        $this->checkPhpCode($testPhpCode, $exceptedDefects);
        XRef::setConfigValue( 'lint.add-constant', array() );
     }

    public function testIndexedArraysInInterpolatedStrings() {
        $testPhpCode = '<?php
            $foo = array();
            echo "$foo[key1]";          // ok
            echo "{$foo[key2]}";        // warning
            echo "{$foo[\'key3\']}";    // ok
        ';
        $exceptedDefects = array(
            array('key2',   4, XRef::WARNING),
        );
        $this->checkPhpCode($testPhpCode, $exceptedDefects);
     }
}

