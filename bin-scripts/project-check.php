<?php
$includeDir = dirname(__FILE__) . "/..";
require_once("$includeDir/XRef.class.php");
require_once("$includeDir/lib/experimental.php");
require_once("$includeDir/lib/ci-tools.php");

/**
 * This is very experimental script to check cross-reference integrity of a project:
 * if we access method/property/constant of a class, check that this method/property/constant is
 * defined somewhere (it can be a parent class).
 */

XRef::registerCmdOption("x:", "revision=", "", "");
XRef::registerCmdOption("y:", "other=", "", "");
XRef::registerCmdOption("e:", "exclude=", "", "", true);
XRef::registerCmdOption("l:", "library=", "", "", true);

$xref = new XRef();
$xref->addParser( new XRef_Parser_PHP() );
$project_lint = new ProjectLintPrototype();
$project_lint->setXRef($xref);

XRef::setConfigValue("xref.storage-manager", "XRef_Storage_File");
XRef::setConfigValue("xref.data-dir", ".");
XRef::setConfigValue("git.repository-dir", ".");
XRef::setConfigValue("ci.source-code-manager", "XRef_SourceCodeManager_Git");
list($options, $arguments) = XRef::getCmdOptions();

$report = null;
$library_files = null;
if (isset($options['library'])) {
    $library_files = new XRef_FileProvider_FileSystem($options['library']);
}

if (isset($options["revision"])) {
    // if we got git revision, try to load the project state
    // of this revision and save it for future use
    $revision = $options["revision"];
    $project_lint->loadOrCreateProject($revision);
    $project_lint->saveProject($revision);
    $project_lint->addLibraryFiles($library_files);

    $errors = $project_lint->getErrors();
    if (isset($options["other"])) {
        // compare 2 repository versions
        $other_revision = $options["other"];
        $project_lint->loadOrCreateProject($other_revision);
        $project_lint->saveProject($other_revision);
        $project_lint->addLibraryFiles($library_files);
        $other_errors = $project_lint->getErrors();
        $report = XRef_getNewProjectErrors($errors, $other_errors);
    } else {
        $report = $errors;
    }
} else {
    // otherwise, just iterate over all files in dir
    $paths = ($arguments) ? $arguments : '.';
    $exclude_paths = (isset($options["exclude"])) ? $options["exclude"] : null;
    $file_provider = new XRef_FileProvider_FileSystem( $paths, $exclude_paths  );
    $project_lint->addFiles($file_provider);
    $project_lint->addLibraryFiles($library_files);
    $report = $project_lint->getErrors();
}

$colorMap = array(
    "error"     => "\033[0;31m",
    "warning"   => "\033[0;33m",
    "notice"    => "\033[0;32m",
    "_off"      => "\033[0;0m",
);

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




















