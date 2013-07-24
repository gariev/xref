<?php

/**
 * lib/bin-scripts/xref-lint.php
 *
 * This is a lint (a tool to find potential bugs in source code) for PHP sources.
 * This is a command-line version
 *
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

$includeDir = ("@php_dir@" == "@"."php_dir@") ? dirname(__FILE__) . "/.." : "@php_dir@/XRef";
require_once("$includeDir/XRef.class.php");

// command-line arguments
XRef::registerCmdOption('o:', "output=",        '-o, --output=TYPE',    "either 'text' (default) or 'json'");
XRef::registerCmdOption('r:', "report-level=",  '-r, --report-level=',  "either 'error', 'warning' or 'notice'");
try {
    list ($options, $arguments) = XRef::getCmdOptions();
} catch (Exception $e) {
    error_log($e->getMessage());
    error_log("See 'xref-lint --help'");
    exit(1);
}

if (XRef::needHelp()) {
    XRef::showHelpScreen(
        "xref-lint - tool to find problems in PHP source code",
        "$argv[0] [options] [path to check]"
    );
    exit(1);
}

//
// report-level:  errors, warnings or notices
// Option -r <value> is a shortcut for option -d lint.report-level=<value>
if (isset($options['report-level'])) {
    XRef::setConfigValue("lint.report-level", $options['report-level']);
}

//
// output-format: text or json
//
$outputFormat = 'text';
if (isset($options['output'])) {
    $outputFormat = $options['output'];
}
if ($outputFormat != 'text' && $outputFormat != 'json') {
    die("unknown output format: $outputFormat");
}

//
// color: on/off/auto, for text output to console only
//
$color = XRef::getConfigValue("lint.color", '');
if ($color=="auto") {
    $color = function_exists('posix_isatty') && posix_isatty(STDOUT);
}
$colorMap = array(
    "error"     => "\033[0;31m",
    "warning"   => "\033[0;33m",
    "notice"    => "\033[0;32m",
    "_off"      => "\033[0;0m",
);

$xref = new XRef();
$xref->loadPluginGroup("lint");
$file_provider = new XRef_FileProvider_FileSystem( ($arguments) ? $arguments : '.' );

$totalFiles         = 0;
$filesWithDefects   = 0;
$numberOfNotices    = 0;
$numberOfWarnings   = 0;
$numberOfErrors     = 0;


// main loop over all files
$total_report = array();
foreach ($file_provider->getFiles() as $filename) {
    try {
        $totalFiles++;
        $file_content = $file_provider->getFileContent($filename);
        $pf = $xref->getParsedFile($filename, $file_content);
        $total_report[$filename] = $xref->getLintReport($pf);
    } catch (XRef_ParseException $e) {
        $total_report[$filename] = array( XRef_CodeDefect::fromParseException($e) );
    }
}

$total_report = $xref->sortAndFilterReport($total_report);

// calculate some stats
foreach ($total_report as $file_name => $report) {
    $filesWithDefects++;
    foreach ($report as $code_defect) {
        if ($code_defect->severity == XRef::NOTICE) {
            $numberOfNotices++;
        } elseif ($code_defect->severity == XRef::WARNING) {
            $numberOfWarnings++;
        } elseif ($code_defect->severity == XRef::ERROR) {
            $numberOfErrors++;
        }
    }
}

// output the report
if ($outputFormat=='text') {
    foreach ($total_report as $file_name => $report) {
        echo "File: $file_name\n";
        foreach ($report as $code_defect) {
            $lineNumber     = $code_defect->lineNumber;
            $tokenText      = $code_defect->tokenText;
            $severityStr    = XRef::$severityNames[ $code_defect->severity ];
            $line = sprintf("    line %4d: %-8s (%s): %s (%s)", $lineNumber, $severityStr, $code_defect->errorCode, $code_defect->message, $tokenText);
            if ($color) {
                $line = $colorMap{$severityStr} . $line . $colorMap{"_off"};
            }
            echo $line . "\n";
        }
    }
    // print stats
    if (XRef::verbose()) {
        echo("Total files:          $totalFiles\n");
        echo("Files with defects:   $filesWithDefects\n");
        echo("Errors:               $numberOfErrors\n");
        echo("Warnings:             $numberOfWarnings\n");
        echo("Notices:              $numberOfNotices\n");
    }
} else {
    $jsonOutput = array();
    foreach ($total_report as $file_name => $report) {
        foreach ($report as $code_defect) {
            $lineNumber     = $code_defect->lineNumber;
            $tokenText      = $code_defect->tokenText;
            $severityStr    = XRef::$severityNames[ $code_defect->severity ];
            $jsonOutput[] = array(
                'fileName'      => $filename,
                'lineNumber'    => $code_defect->lineNumber,
                'tokenText'     => $code_defect->tokenText,
                'severityStr'   => $severityStr,
                'errorCode'     => $code_defect->errorCode,
                'message'       => $code_defect->message,
            );
        }
    }
    echo json_encode($jsonOutput); // JSON_PRETTY_PRINT is not available before php 5.4 :(
}


if ($numberOfErrors+$numberOfWarnings > 0) {
    exit(1);
} else {
    exit(0);
}


// vim: tabstop=4 expandtab

