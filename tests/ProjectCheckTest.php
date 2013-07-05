<?php

$includeDir = ("@php_dir@" == "@"."php_dir@") ? dirname(__FILE__) . "/.." : "@php_dir@/XRef";
require_once "$includeDir/XRef.class.php";
require_once "$includeDir/lib/experimental.php";

class ProjectCheckTest extends PHPUnit_Framework_TestCase {

    private $xref;
    private $project_check;

    public function __construct() {
        $xref = new XRef();
        $xref->addParser( new XRef_Parser_PHP() );
        $project_check = new ProjectLintPrototype();
        $project_check->setXRef($xref);
        $this->xref = $xref;
        $this->project_check = $project_check;
    }

    protected function checkFoundDefect($foundDefect, $file_name, $expected_file_name, $tokenText, $lineNumber, $severity) {
        $descr = print_r($foundDefect, true);   // TODO
        $this->assertTrue($file_name                == $expected_file_name, "Wrong filename: $file_name / $expected_file_name");
        $this->assertTrue($foundDefect->tokenText   == $tokenText,          "Invalid token:\n$descr");
        $this->assertTrue($foundDefect->lineNumber  == $lineNumber,         "Invalid line number ($lineNumber/$foundDefect->lineNumber):\n$descr");
        $this->assertTrue($foundDefect->severity    == $severity,           "Invalid severity:\n$descr");
    }

    protected function checkProject($files, $expectedDefectsList) {
        $this->project_check->clearProject();
        foreach ($files as $file_name => $file_content) {
            $pf = $this->xref->getParsedFile($file_name, "php", $file_content);
            $this->project_check->addFile($pf);
            $pf->release();
        }
        // report: array (filename => array(list of errors))
        $report = $this->project_check->getErrors();

        // 1. count errors
        $countFound = 0;
        foreach ($report as $file_name => $list) {
            $countFound += count($list);
        }
        $countExpected = count($expectedDefectsList);
        if ($countFound != $countExpected) {
            print_r($report);
            $this->fail( "Wrong number of errors: found=$countFound, expected=$countExpected" );
        } else {
            $this->assertTrue($countFound == $countExpected, "Excpected number of defects");
        }

        $i = 0;
        foreach ($report as $file_name => $list) {
            foreach ($list as $e) {
                list($expected_file_name, $tokenText, $lineNumber, $severity) = $expectedDefectsList[$i];
                $this->checkFoundDefect($e, $file_name, $expected_file_name, $tokenText, $severity, $lineNumber);
                ++$i;
            }
        }
    }

    public function testProject() {
        $code = '
        <?php
            class A {
                function foo () {
                    echo $this->bar;    // warning
                }
            }
        ';
        $this->checkProject(
            array( 'fileA.php' => $code ),
            array(
                array('fileA.php', 'bar', XRef::WARNING, 5),
            )
        );
    }

    public function testMultifileProject() {
        $codeA = '
        <?php
            class A {
                public $foo;
            }
        ';
        $codeB = '
        <?php
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
                array('fileB.php', 'bar', XRef::WARNING, 6),
            )
        );
    }

    public function testMissingBaseClass() {
        $codeA = '
        <?php
            class B extends A {
                function foo() {
                    self::bar();        // warning, since there is no definition of class A
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
                array('fileA.php', 'bar', XRef::WARNING, 5),
                array('fileA.php', 'bar', XRef::ERROR, 10),
            )
        );
    }

    public function testMisuseOfConstants() {
        $codeA = '
        <?php
            class A {
                const BAR = 10;
            }
            class B extends A {
                const FOO = 20;
            }
            class C extends UnknownClass {}
        ';
        $codeB = '
        <?php
            echo A::BAR;        // ok
            echo B::BAR;        // ok
            echo A::FOO;        // warning
            echo B::FOO;        // ok
            echo C::FOO;        // warning, because class C extends unknown class
            echo D::FOO;        // warning, because no class D is found
        ';
        $this->checkProject(
            array( 'fileA.php' => $codeA, 'fileB.php' => $codeB, ),
            array(
                array('fileB.php', 'FOO', XRef::ERROR, 5),
                array('fileB.php', 'FOO', XRef::WARNING, 7),
                array('fileB.php', 'FOO', XRef::WARNING, 8),
            )
        );
    }

    public function testMisusedConstants() {
        $codeA = '
        <?php
            class A {
                const BAR = 10;
            }
            class B extends A {
                const FOO = 20;
            }
            class C extends UnknownClass {}
        ';
        $codeB = '
        <?php
            echo A::BAR;        // ok
            echo B::BAR;        // ok
            echo A::FOO;        // warning
            echo B::FOO;        // ok
            echo C::FOO;        // warning, because class C extends unknown class
            echo D::FOO;        // warning, because no class D is found
        ';
        $this->checkProject(
            array( 'fileA.php' => $codeA, 'fileB.php' => $codeB, ),
            array(
                array('fileB.php', 'FOO', XRef::ERROR, 5),
                array('fileB.php', 'FOO', XRef::WARNING, 7),
                array('fileB.php', 'FOO', XRef::WARNING, 8),
            )
        );
    }

    public function testInstanceVisibilityModifiers() {
        $codeA = '
        <?php
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
                array('fileA.php', 'pbaz', XRef::ERROR, 23),
                array('fileA.php', 'fbaz', XRef::ERROR, 26),
            )
        );
    }

    public function testStaticVisibilityModifiers() {
        $codeA = '
        <?php
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
                array('fileA.php', 'pbaz', XRef::ERROR, 31),
                array('fileA.php', 'fbaz', XRef::ERROR, 34),
                array('fileA.php', 'pbaz', XRef::ERROR, 38),
                array('fileA.php', 'fbaz', XRef::ERROR, 41),

                // class C
                array('fileA.php', 'pbar', XRef::ERROR, 48),
                array('fileA.php', 'pbaz', XRef::ERROR, 49),
                array('fileA.php', 'fbar', XRef::ERROR, 51),
                array('fileA.php', 'fbaz', XRef::ERROR, 52),

                // global test()
                array('fileA.php', 'pbar', XRef::ERROR, 58),
                array('fileA.php', 'pbaz', XRef::ERROR, 59),
                array('fileA.php', 'fbar', XRef::ERROR, 61),
                array('fileA.php', 'fbaz', XRef::ERROR, 62),
            )
        );
    }

    public function testMagicAccessors() {
        $codeA = '
        <?php
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
                array('fileA.php', 'foo', XRef::WARNING, 17),
                array('fileA.php', 'bar', XRef::WARNING, 18),
            )
        );
    }

    public function testStaticVsInstanceAccess() {
        $codeA = '
        <?php
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
                array('fileA.php', 'pfoo', XRef::ERROR, 13),
                array('fileA.php', 'pbar', XRef::ERROR, 17),
                array('fileA.php', 'fbar', XRef::ERROR, 20),
                // class B
                array('fileA.php', 'pfoo', XRef::ERROR, 27),
                array('fileA.php', 'pbar', XRef::ERROR, 31),
                array('fileA.php', 'fbar', XRef::ERROR, 34),
                // global test()
                array('fileA.php', 'pbar', XRef::ERROR, 41),
                array('fileA.php', 'fbar', XRef::ERROR, 42),
            )
        );
    }

    public function testInheritedFromInterfaces() {
        $codeA = '<?php

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
                array('fileA.php', 'D_CONST', XRef::ERROR, 15),
            )
        );

        // allow abstract classes to call methods inherited from interfaces
        $codeA = '<?php

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
                array('fileA.php', 'foo', XRef::ERROR, 13),
            )
        );
    }

    public function testInheritedFromTraits() {
        $codeA = '<?php

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
                array('fileA.php', 'bar', XRef::ERROR, 12),
            )
        );
    }
}
