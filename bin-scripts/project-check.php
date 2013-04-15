<?php
$includeDir = dirname(__FILE__) . "/..";
require_once("$includeDir/XRef.class.php");
require_once("$includeDir/lib/experimental.php");

/**
 * This is very experimental script to check cross-reference integrity of a project:
 * if we access method/property/constant of a class, check that this method/property/constant is
 * defined somewhere (it can be a parent class).
 */

$xref = new XRef();
$path = ($argc>1) ? $argv[1] : ".";
$xref->addPath($path);
$xref->addParser( new XRef_Parser_PHP() );
$project_lint = new ProjectLintPrototype();

foreach ($xref->getFiles() as $filename => $ext) {
    try {
        $pf = $xref->getParsedFile($filename, $ext);
        $project_lint->addFile($pf);
        $pf->release(); // help PHP garbage collector to free memory
    } catch(Exception $e) {
        error_log("Can't process file '$filename': " . $e->getMessage() . "\n" . $e->getLine() . "\n");
    }
}

$colorMap = array(
    "error"     => "\033[0;31m",
    "warning"   => "\033[0;33m",
    "notice"    => "\033[0;32m",
    "_off"      => "\033[0;0m",
);

$report = $project_lint->getErrors();
foreach ($report as $filename => $errors_list) {
    echo("File: $filename\n");
    foreach ($errors_list as $r) {
        $lineNumber     = $r->lineNumber;
        $tokenText      = $r->tokenText;
        $severityStr    = XRef::$severityNames[ $r->severity ];
        $line = sprintf("    line %4d: %-8s (%s): %s (%s)", $lineNumber, $severityStr, $r->errorCode, $r->message, $tokenText);
        $line = $colorMap{$severityStr} . $line . $colorMap{"_off"};
        echo($line . "\n");
    }
}




















