<?php

$includeDir = ("@php_dir@" == "@"."php_dir@") ? dirname(__FILE__) . "/.." : "@php_dir@/XRef";
require_once("$includeDir/XRef.class.php");
$xref = new XRef();


$parser = new XRef_Parser_PHP();
$file_name = ($argc > 0) ? $argv[0] : __FILE__;
$file_content = file_get_contents($file_name);
$parsed_file = $parser->parse($file_content, $file_name);

echo "Classes: ",   print_r( $parsed_file->getClasses(), true ),    "\n\n";
echo "Methods: ",   print_r( $parsed_file->getMethods(), true ),    "\n\n";
echo "Constants: ", print_r( $parsed_file->getConstants(), true ),  "\n\n";

class ExampleClass extends Exception {
    public function __construct($message) {
        parent::__construct($message);
    }
}

