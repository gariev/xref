<?php

$includeDir = ("@php_dir@" == "@"."php_dir@") ? dirname(__FILE__) . "/.." : "@php_dir@/XRef";
require_once "$includeDir/XRef.class.php";
require_once "$includeDir/lib/experimental.php";

class ProjectCheckTest extends PHPUnit_Framework_TestCase {

    private $xref;

    public function __construct() {
        $xref = new XRef();
        $xref->loadPluginGroup('lint');
        $this->xref = $xref;
    }

    protected function checkFoundDefect($found_defect, $file_name, $expected_file_name, $token_text, $line_number, $severity) {
        $descr = print_r($found_defect, true);   // TODO
        $this->assertTrue($file_name                == $expected_file_name, "Wrong filename: $file_name / $expected_file_name");
        $this->assertTrue($found_defect->tokenText  == $token_text,         "Invalid token ($found_defect->tokenText, $token_text):\n$descr");
        $this->assertTrue($found_defect->lineNumber == $line_number,        "Invalid line number ($line_number/$found_defect->lineNumber):\n$descr");
        $this->assertTrue($found_defect->severity   == $severity,           "Invalid severity:\n$descr");
    }

    protected function checkProject($files, $expected_defects) {
        $lint_engine = new XRef_LintEngine_ProjectCheck($this->xref, false);
        $file_provider = new XRef_FileProvider_InMemory($files);
        $report = $lint_engine->getReport($file_provider);

        // 1. count errors
        $count_found = 0;
        foreach ($report as $file_name => $list) {
            $count_found += count($list);
        }
        $count_expected = count($expected_defects);
        if ($count_found != $count_expected) {
            print_r($report);
            $this->fail( "Wrong number of errors: found=$count_found, expected=$count_expected" );
        } else {
            $this->assertTrue($count_found == $count_expected, "Expected number of defects");
        }

        $i = 0;
        foreach ($report as $file_name => $list) {
            foreach ($list as $e) {
                list($expected_file_name, $token_text, $line_number, $severity) = $expected_defects[$i];
                $this->checkFoundDefect($e, $file_name, $expected_file_name, $token_text, $severity, $line_number);
                ++$i;
            }
        }
    }

    public function testProject() {
        $code =
        '<?php
            class A {
                function foo () {
                    echo $this->bar;    // warning
                }
            }
        ';
        $this->checkProject(
            array( 'fileA.php' => $code ),
            array(
                array('fileA.php', 'bar', XRef::WARNING, 4),
            )
        );
    }

    public function testMultifileProject() {
        $codeA =
        '<?php
            class A {
                public $foo;
            }
        ';
        $codeB =
        '<?php
            class B extends A{
                function foo () {
                    echo $this->foo;        // ok
                    echo $this->bar;        // warning
                }
            }
        ';
        $this->checkProject(
            array( 'fileA.php' => $codeA, 'fileB.php' => $codeB ),
            array(
                array('fileB.php', 'bar', XRef::WARNING, 5),
            )
        );
    }

    public function testMissingBaseClass() {
        $codeA =
        '<?php
            class B extends A {
                function foo() {
                    self::bar();        // notice, since there is no definition of class A
                }
            }
            class C {
                function foo() {
                    self::bar();        // error
                }
            }
        ';
        $this->checkProject(
            array( 'fileA.php' => $codeA ),
            array(
                array('fileA.php', 'bar', XRef::ERROR, 9),
            )
        );
    }

    public function testMisuseOfConstants() {
        $codeA =
        '<?php
            class A {
                const BAR = 10;
            }
            class B extends A {
                const FOO = 20;
            }
            class C extends UnknownClass {}
        ';
        $codeB =
        '<?php
            echo A::BAR;        // ok
            echo B::BAR;        // ok
            echo A::FOO;        // warning
            echo B::FOO;        // ok
            echo C::FOO;        // notice, because class C extends unknown class
            echo D::FOO;        // notice, because no class D is found
        ';
        $this->checkProject(
            array( 'fileA.php' => $codeA, 'fileB.php' => $codeB, ),
            array(
                array('fileB.php', 'FOO', XRef::ERROR, 4),
                // array('fileB.php', 'FOO', XRef::NOTICE, 7),
                // array('fileB.php', 'FOO', XRef::NOTICE, 8),
            )
        );
    }

    public function testMisusedConstants() {
        $codeA =
        '<?php
            class A {
                const BAR = 10;
            }
            class B extends A {
                const FOO = 20;
            }
            class C extends UnknownClass {}
        ';
        $codeB =
        '<?php
            echo A::BAR;        // ok
            echo B::BAR;        // ok
            echo A::FOO;        // warning
            echo B::FOO;        // ok
            echo C::FOO;        // notice, because class C extends unknown class
            echo D::FOO;        // notice, because no class D is found
        ';
        $this->checkProject(
            array( 'fileA.php' => $codeA, 'fileB.php' => $codeB, ),
            array(
                array('fileB.php', 'FOO', XRef::ERROR, 4),
                // array('fileB.php', 'FOO', XRef::NOTICE, 7),
                // array('fileB.php', 'FOO', XRef::NOTICE, 8),
            )
        );
    }

    public function testInstanceVisibilityModifiers() {
        $codeA =
        '<?php
            class A {
                public      $pfoo;
                protected   $pbar;
                private     $pbaz;
                public      function ffoo()  {}
                protected   function fbar()  {}
                private     function fbaz()  {}
                public function test() {
                    echo $this->pfoo;       // ok
                    echo $this->pbar;       // ok
                    echo $this->pbaz;       // ok
                    echo $this->ffoo();     // ok
                    echo $this->fbar();     // ok
                    echo $this->fbaz();     // ok
                }
            }
            class B extends A {
                public function test() {
                    echo $this->pfoo;       // ok
                    echo $this->pbar;       // ok
                    echo $this->pbaz;       // error
                    echo $this->ffoo();     // ok
                    echo $this->fbar();     // ok
                    echo $this->fbaz();     // error
                }
            }
            // TODO: use variable types
            /** @var $varA A */
            $varA->ffoo();                  // ok
        ';
        $this->checkProject(
            array( 'fileA.php' => $codeA, ),
            array(
                array('fileA.php', 'pbaz', XRef::ERROR, 22),
                array('fileA.php', 'fbaz', XRef::ERROR, 25),
            )
        );
    }

    public function testStaticVisibilityModifiers() {
        $codeA =
        '<?php
            class A {
                public      static $pfoo;
                protected   static $pbar;
                private     static $pbaz;
                public      static function ffoo()  {}
                protected   static function fbar()  {}
                private     static function fbaz()  {}
                public function test() {
                    echo self::$pfoo;       // ok
                    echo self::$pbar;       // ok
                    echo self::$pbaz;       // ok
                    echo self::ffoo();      // ok
                    echo self::fbar();      // ok
                    echo self::fbaz();      // ok

                    echo A::$pfoo;          // ok
                    echo A::$pbar;          // ok
                    echo A::$pbaz;          // ok
                    echo A::ffoo();         // ok
                    echo A::fbar();         // ok
                    echo A::fbaz();         // ok
                 }
            }

            class B extends A {
                public function test() {
                    echo self::$pfoo;       // ok
                    echo self::$pbar;       // ok
                    echo self::$pbaz;       // error
                    echo self::ffoo();      // ok
                    echo self::fbar();      // ok
                    echo self::fbaz();      // error

                    echo A::$pfoo;          // ok
                    echo A::$pbar;          // ok
                    echo A::$pbaz;          // error
                    echo A::ffoo();         // ok
                    echo A::fbar();         // ok
                    echo A::fbaz();         // error
                }
            }

            class C {
                public function test() {
                    echo A::$pfoo;          // ok
                    echo A::$pbar;          // error
                    echo A::$pbaz;          // error
                    echo A::ffoo();         // ok
                    echo A::fbar();         // error
                    echo A::fbaz();         // error
                }
            }

            public function test() {
                echo A::$pfoo;          // ok
                echo A::$pbar;          // error
                echo A::$pbaz;          // error
                echo A::ffoo();         // ok
                echo A::fbar();         // error
                echo A::fbaz();         // error
            }
        ';
        $this->checkProject(
            array( 'fileA.php' => $codeA, ),
            array(
                // class B
                array('fileA.php', 'pbaz', XRef::ERROR, 30),
                array('fileA.php', 'fbaz', XRef::ERROR, 33),
                array('fileA.php', 'pbaz', XRef::ERROR, 37),
                array('fileA.php', 'fbaz', XRef::ERROR, 40),

                // class C
                array('fileA.php', 'pbar', XRef::ERROR, 47),
                array('fileA.php', 'pbaz', XRef::ERROR, 48),
                array('fileA.php', 'fbar', XRef::ERROR, 50),
                array('fileA.php', 'fbaz', XRef::ERROR, 51),

                // global test()
                array('fileA.php', 'pbar', XRef::ERROR, 57),
                array('fileA.php', 'pbaz', XRef::ERROR, 58),
                array('fileA.php', 'fbar', XRef::ERROR, 60),
                array('fileA.php', 'fbaz', XRef::ERROR, 61),
            )
        );
    }

    public function testMagicAccessors() {
        $codeA =
        '<?php
            class A {
                public function __get($key) {}
                public function test() {
                    echo $this->foo;        // ok
                }
            }
            class B extends A {
                public function test() {
                    echo $this->foo;        // ok
                    echo $this->bar;        // ok
                }
            }
            class C {
                public function test() {
                    echo $this->foo;        // warning
                    echo $this->bar;        // warning
                }
            }
        ';
        $this->checkProject(
            array( 'fileA.php' => $codeA, ),
            array(
                array('fileA.php', 'foo', XRef::WARNING, 16),
                array('fileA.php', 'bar', XRef::WARNING, 17),
            )
        );
    }

    public function testStaticVsInstanceAccess() {
        $codeA =
        '<?php
            class A {
                // static
                public static $pfoo;
                public static function ffoo() {}
                // instance
                public $pbar;
                public function fbar() {}

                public function test() {
                    echo self::$pfoo;       // ok
                    echo $this->pfoo;       // error
                    echo $this->ffoo();     // ok, actually
                    echo self::ffoo();      // ok

                    echo self::$pbar;       // error
                    echo $this->pbar;       // ok
                    echo $this->fbar();     // ok
                    echo self::fbar();      // error
                }
            }

            class B extends A {
                public function test() {
                    echo self::$pfoo;       // ok
                    echo $this->pfoo;       // error
                    echo $this->ffoo();     // ok, actually
                    echo self::ffoo();      // ok

                    echo self::$pbar;       // error
                    echo $this->pbar;       // ok
                    echo $this->fbar();     // ok
                    echo self::fbar();      // error
                }
            }

            public function test() {
                echo A::$pfoo;              // ok
                echo A::ffoo();             // ok
                echo A::$pbar;              // error
                echo A::fbar();             // error
            }
        ';

        $this->checkProject(
            array( 'fileA.php' => $codeA, ),
            array(
                // class A
                array('fileA.php', 'pfoo', XRef::ERROR, 12),
                array('fileA.php', 'pbar', XRef::ERROR, 16),
                array('fileA.php', 'fbar', XRef::ERROR, 19),
                // class B
                array('fileA.php', 'pfoo', XRef::ERROR, 26),
                array('fileA.php', 'pbar', XRef::ERROR, 30),
                array('fileA.php', 'fbar', XRef::ERROR, 33),
                // global test()
                array('fileA.php', 'pbar', XRef::ERROR, 40),
                array('fileA.php', 'fbar', XRef::ERROR, 41),
            )
        );
    }

    public function testInheritedFromInterfaces() {
        $codeA =
        '<?php
            class A {
                const A_CONST = 1;
            }
            interface B {
                const B_CONST = 2;
            }
            class C extends A implements B {
                const C_CONST = 3;
                public function test() {
                    echo self::A_CONST;     // ok
                    echo self::B_CONST;     // ok (!)
                    echo self::C_CONST;     // ok
                    echo self::D_CONST;     // warning
                }
            }
        ';
        $this->checkProject(
            array( 'fileA.php' => $codeA, ),
            array(
                array('fileA.php', 'D_CONST', XRef::ERROR, 14),
            )
        );

        // allow abstract classes to call methods inherited from interfaces
        $codeA =
        '<?php
            interface A {
                public function foo();
            }
            abstract class B implements A {
                public function test() {
                    echo $this->foo();      // ok
                }
            }
            class C implements A {
                public function test() {
                    echo $this->foo();      // actually, 2 errors should be here,
                                            // since C doesnt implement the promised foo()
                }
            }
        ';
        $this->checkProject(
            array( 'fileA.php' => $codeA, ),
            array(
                array('fileA.php', 'foo', XRef::ERROR, 12),
            )
        );
    }

    public function testInheritedFromTraits() {
        $codeA =
        '<?php
            trait A {
                const A_CONST = 1;
                public function foo() {}
                private function bar() {}
            }
            class B {
                use A;
                public function test() {
                    echo $this->foo();      // ok
                    echo $this->bar();      // error
                    echo self::A_CONST;     // ok
                }
            }
        ';
        $this->checkProject(
            array( 'fileA.php' => $codeA, ),
            array(
                array('fileA.php', 'bar', XRef::ERROR, 11),
            )
        );
    }

    public function testMissedConstructor() {
        $code =
        '<?php
            class A { }             // ok
            class B extends A {}    // ok
            class C extends A {     // ok
                public function __construct() {
                }
            }
            class D extends C {}    // ok, php will create a default constructor that will call parent\'s constructor
            class E extends C {     // warning
                public function __construct() {
                }
            }
            class F extends D {     // warning. class D doesn\'t have a constructor, but its base class C has
                public function __construct() {
                }
            }
        ';
        $this->checkProject(
            array( 'fileA.php' => $code ),
            array(
                array('fileA.php', 'E', XRef::WARNING, 9),
                array('fileA.php', 'F', XRef::WARNING, 13),
            )
        );
    }


}
