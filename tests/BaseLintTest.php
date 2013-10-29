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

    protected function checkFoundDefect($found_defect, $token_text, $line_number, $severity) {
        $descr = print_r($found_defect, true);   // TODO
        $this->assertTrue($found_defect->tokenText  == $token_text,     "Invalid token:\n$descr");
        $this->assertTrue($found_defect->lineNumber == $line_number,    "Invalid line number:\n$descr");
        $this->assertTrue($found_defect->severity   == $severity,       "Invalid severity:\n$descr");
    }

    protected function checkPhpCode($php_code, $expected_defects) {
        $pf = $this->xref->getParsedFile("filename.php", $php_code);
        $lint_engine = new XRef_LintEngine_Simple($this->xref, false);
        $lint_engine->addParsedFile($pf);
        $pf->release();
        $report = $lint_engine->collectReport();
        $errors = (isset($report["filename.php"])) ? $report["filename.php"] : array();

        $count_found = count($errors);
        $count_expected = count($expected_defects);
        if ($count_found != $count_expected) {
            print_r($report);
            $this->fail( "Wrong number of errors: found=$count_found, expected=$count_expected" );
        } else {
            $this->assertTrue($count_found == $count_expected, "Expected number of defects");
        }

        for ($i=0; $i<count($errors); ++$i) {
            $found_defect = $errors[$i];
            list($token_text, $line_number, $severity) = $expected_defects[$i];
            $this->checkFoundDefect($found_defect, $token_text, $line_number, $severity);
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
