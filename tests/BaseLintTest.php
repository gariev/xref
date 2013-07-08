<?php

$includeDir = ("@php_dir@" == "@"."php_dir@") ? dirname(__FILE__) . "/.." : "@php_dir@/XRef";
require_once "$includeDir/XRef.class.php";

class BaseLintTest extends PHPUnit_Framework_TestCase {

    private $xref;

    public function __construct() {
        $this->xref = new XRef();
        //TODO: skip reading config file, we need default values
        $this->xref->loadPluginGroup('lint');
        XRef::setConfigValue("lint.check-global-scope", true);
    }

    protected function checkFoundDefect($foundDefect, $tokenText, $lineNumber, $severity) {
        $descr = print_r($foundDefect, true);   // TODO
        $this->assertTrue($foundDefect->tokenText==$tokenText,      "Invalid token:\n$descr");
        $this->assertTrue($foundDefect->lineNumber==$lineNumber,    "Invalid line number:\n$descr");
        $this->assertTrue($foundDefect->severity==$severity,        "Invalid severity:\n$descr");
    }

    protected function checkPhpCode($phpCode, $expectedDefectsList) {
        $pf = $this->xref->getParsedFile("filename.php", $phpCode);
        $report = $this->xref->getLintReport($pf);
        $pf->release();

        $countFound = count($report);
        $countExpected = count($expectedDefectsList);
        if ($countFound != $countExpected) {
            print_r($report);
            $this->fail( "Wrong number of errors: found=$countFound, expected=$countExpected" );
        } else {
            $this->assertTrue($countFound == $countExpected, "Excpected number of defects");
        }

        for ($i=0; $i<count($report); ++$i) {
            $foundDefect = $report[$i];
            list($tokenText, $lineNumber, $severity) = $expectedDefectsList[$i];
            $this->checkFoundDefect($foundDefect, $tokenText, $lineNumber, $severity);
        }
    }

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
}
