<?php

$includeDir = ("@php_dir@" == "@"."php_dir@") ? dirname(__FILE__) . "/.." : "@php_dir@/XRef";
require_once("$includeDir/XRef.class.php");

class TestParsers extends PHPUnit_Framework_TestCase {



    public function testBasicUsage() {
        $testPhpCode = '
<?php
class Foo {
    public function bar() {
        return 42;
    }
}
';
        $xref = new XRef();
        $xref->addParser( new XRef_Parser_PHP() );
        $pf = $xref->getParsedFile("filename.php", "php", $testPhpCode);
        $classes = $pf->getClasses();
        $methods = $pf->getMethods();
        $this->assertTrue(count($classes)==1);
        $this->assertTrue($classes[0]->name=='Foo');
        $this->assertTrue(count($methods)==1);
        $this->assertTrue($methods[0]->name=='bar');

    }
}
