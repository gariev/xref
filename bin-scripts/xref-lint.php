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
$file_provider = new XRef_FileProvider_FileSystem( ($arguments) ? $arguments : array(".") );

$totalFiles         = 0;
$filesWithDefects   = 0;
$numberOfNotices    = 0;
$numberOfWarnings   = 0;
$numberOfErrors     = 0;

$jsonOutput = array();

// main loop over all files
foreach ($file_provider->getFiles() as $filename) {
    try {
        $pf = $xref->getParsedFile($filename);
        $report = $xref->getLintReport($pf);

        $totalFiles++;
        if (count($report)) {
            $filesWithDefects++;
            foreach ($report as $r) {
                if ($r->severity==XRef::NOTICE) {
                    $numberOfNotices++;
                } elseif ($r->severity==XRef::WARNING) {
                    $numberOfWarnings++;
                } elseif ($r->severity==XRef::ERROR) {
                    $numberOfErrors++;
                }
            }
        }

        if (count($report)) {
            if ($outputFormat=='text') {
                echo("File: $filename\n");
                foreach ($report as $r) {
                    $lineNumber     = $r->lineNumber;
                    $tokenText      = $r->tokenText;
                    $severityStr    = XRef::$severityNames[ $r->severity ];
                    $line = sprintf("    line %4d: %-8s (%s): %s (%s)", $lineNumber, $severityStr, $r->errorCode, $r->message, $tokenText);
                    if ($color) {
                        $line = $colorMap{$severityStr} . $line . $colorMap{"_off"};
                    }
                    echo($line . "\n");
                }
            } else {
                foreach ($report as $r) {
                    $lineNumber     = $r->lineNumber;
                    $tokenText      = $r->tokenText;
                    $severityStr    = XRef::$severityNames[ $r->severity ];
                    $jsonOutput[] = array(
                        'fileName'      => $filename,
                        'lineNumber'    => $r->lineNumber,
                        'tokenText'     => $r->tokenText,
                        'severityStr'   => $severityStr,
                        'errorCode'     => $r->errorCode,
                        'message'       => $r->message,
                    );
                }
            }
        }

        $pf->release();
    } catch (Exception $e) {
        if ($outputFormat=='text') {
            error_log("Can't parse file '$filename': " . $e->getMessage() . "\n");
            if (XRef::verbose()) {
                error_log("At " . $e->getFile() . ":" . $e->getLine());
                error_log($e->getTraceAsString());
            }
        } else {
            $jsonOutput[] = array(
                'fileName'      => $filename,
                'lineNumber'    => 1,
                'severityStr'   => XRef::FATAL,
                'message'       => $e->getMessage(),
            );
        }
    }
}

// print total report
if (XRef::verbose()) {
    echo("Total files:          $totalFiles\n");
    echo("Files with defects:   $filesWithDefects\n");
    echo("Errors:               $numberOfErrors\n");
    echo("Warnings:             $numberOfWarnings\n");
    echo("Notices:              $numberOfNotices\n");
}

if ($outputFormat=='json') {
    echo json_encode($jsonOutput); // JSON_PRETTY_PRINT is not available before php 5.4 :(
}

if ($numberOfErrors+$numberOfWarnings > 0) {
    exit(1);
} else {
    exit(0);
}


// vim: tabstop=4 expandtab

