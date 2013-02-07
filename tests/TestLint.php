<?php

$includeDir = ("@php_dir@" == "@"."php_dir@") ? dirname(__FILE__) . "/.." : "@php_dir@/XRef";
require_once "$includeDir/XRef.class.php";

// Can't use nowdocs before php 5.3.0, and heredocs strings are interpolated :(
//$testPhpCodeVars = <<<'END'
$testPhpCodeVars = '
<?php

// superglobals: ok
$foo = $_GET["bar"];

// top-level vars:
$foo = $argv;   //ok
$foo = $argc;   //ok
function foo_1 () { $foo = $argv; } // not ok
function foo_2 () { $foo = $argc; } // not ok

// initialiazed-vars:
$foo1 = 10;
$foo = $foo1; //ok
$foo = $foo2; // not ok
'
;

$testPhpCodeThis = '
<?php


'
;


$testPhpCodeLiterals = '
<?php
END
';



class TestParsers extends PHPUnit_Framework_TestCase {

    public function testBasicUsage() {
        global $testPhpCodeVars, $testPhpCodeVars, $testPhpCodeLiterals;

        $xref = new XRef();
        XRef::setConfigValue("lint.check-global-scope", true);
        $xref->loadPluginGroup('lint');
        $pf = $xref->getParsedFile("filename.php", "php", $testPhpCodeVars);

        $report = $xref->getLintReport($pf);
        $this->assertTrue(count($report)==3);

        $this->assertTrue($report[0]->tokenText=='$argv',   print_r($report[0], true));
        $this->assertTrue($report[0]->lineNumber==10,       print_r($report[0], true));

        $this->assertTrue($report[1]->tokenText=='$argc',   print_r($report[1], true));
        $this->assertTrue($report[1]->lineNumber==11,       print_r($report[1], true));

        $this->assertTrue($report[2]->tokenText=='$foo2',   print_r($report[2], true));
        $this->assertTrue($report[2]->lineNumber==16,       print_r($report[2], true));

    }
}
