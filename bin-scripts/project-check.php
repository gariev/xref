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

XRef::setConfigValue("xref.storage-manager", "XRef_Storage_File");
XRef::setConfigValue("xref.data-dir", ".xref");
XRef::setConfigValue("git.repository-dir", ".");
XRef::setConfigValue("ci.source-code-manager", "XRef_SourceCodeManager_Git");

$xref = new XRef();
$xref->addParser( new XRef_Parser_PHP() );
$project_lint = new ProjectLintPrototype();
$project_lint->setXRef($xref);
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
    $source_code_manager = $xref->getSourceCodeManager();
    $file_provider = $source_code_manager->getFileProvider($revision);
    if (isset($options["exclude"])) {
        $file_provider->excludePath($options["exclude"]);
    }
    $project_database = new XRef_ProjectDatabase_Persistent($revision, $xref, $file_provider);
    $project_database->save($revision);
    $errors = $project_lint->getErrors( $project_database );
    $project_database->clear();

    if (isset($options["other"])) {
        // compare 2 repository versions
        $other_revision = $options["other"];
        $file_provider = $source_code_manager->getFileProvider($other_revision);
        if (isset($options["exclude"])) {
            $file_provider->excludePath($options["exclude"]);
        }
        $project_database = new XRef_ProjectDatabase_Persistent($other_revision, $xref, $file_provider);
        $project_database->save($other_revision);
        $other_errors = $project_lint->getErrors( $project_database );
        $report = XRef_getNewProjectErrors($errors, $other_errors);
    } else {
        $report = $errors;
    }

} else {
    // otherwise, just iterate over all files in dir
    $paths = ($arguments) ? $arguments : '.';
    $file_provider = new XRef_FileProvider_FileSystem( $paths );
    if (isset($options["exclude"])) {
        $file_provider->excludePath($options["exclude"]);
    }
    $project_database = new XRef_ProjectDatabase_Persistent(null, $xref, $file_provider);
    //$project_database->addLibraryFiles($library_files);
    $report = $project_lint->getErrors( $project_database );
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




















