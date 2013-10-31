<?php
/**
 * lib/bin-scripts/xref-ci.php
 *
 * "Continuous Integration Server"
 *
 * This script is to be run from command line (cron).
 * It checks for modified files in repository and reports about new errors.
 *
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */


$includeDir = ("@php_dir@" == "@"."php_dir@") ? dirname(__FILE__) . "/.." : "@php_dir@/XRef";
require_once "$includeDir/XRef.class.php";

try {
    list ($options, $arguments) = XRef::getCmdOptions();
} catch (Exception $e) {
    error_log($e->getMessage());
    error_log("See 'xref-ci --help'");
    exit(1);
}

if (XRef::needHelp() || count($arguments)) {
    XRef::showHelpScreen("xref-ci - continuous integration server");
    exit(1);
}

$incremental= XRef::getConfigValue("ci.incremental", false);
$xref       = new XRef();
$xref->loadPluginGroup("lint");
$scm        = $xref->getSourceCodeManager();
$storage    = $xref->getStorageManager();



//
// Normal run:
// Find modified files from the last run and find new errors in these files
//
if (!$storage->getLock("ci")) {
    error_log("Can't obtain lock - already running?");
    exit(0);
}
$db = $storage->restoreData("ci", "database");
if (!$db) {
    // initialize the database
    $db = array();
    $db["branches"] = $scm->getListOfBranches();
    $db["numberOfSentLetters"] = 0;
    $storage->saveData("ci", "database", $db);
}

//
//  Update the repository
//  Find all (remote) branches and their current revisions
//  for each branch
//      find current revision
//      find list of modified files since the last revision
//      for each file
//          find old and new version of code, find list of errors and compare them
//      if errors were found, find author of the commit and notify them
//
if (XRef::getConfigValue("ci.update-repository", false)) {
    if (XRef::verbose()) {
        error_log("Updating repository");
    }
    $scm->updateRepository();
}

$branches = $scm->getListOfBranches();
foreach ($branches as $branch_name => $current_rev) {

    // new branch: don't check files, just add to list of known branches
    if (!isset($db["branches"][$branch_name])) {
        $db["branches"][$branch_name] = $current_rev;
        if (XRef::verbose()) {
            error_log("new branch $branch_name");
        }
        $storage->saveData("ci", "database", $db);
        continue;
    }

    $old_rev = $db["branches"][$branch_name];

    // nothing changed for this branch
    if ($old_rev == $current_rev) {
        if (XRef::verbose()) {
            error_log("Branch $branch_name was not modified");
        }
        continue;
    }

    if (XRef::verbose()) {
        error_log("Processing branch $branch_name");
    }

    // $errors: array(file name => XRef_CodeDefect[])
    $errors = array();

    $file_provider_old = $scm->getFileProvider($old_rev);
    $file_provider_new = $scm->getFileProvider($current_rev);
    $modified_files = $scm->getListOfModifiedFiles($old_rev, $current_rev);
    $lint_engine = XRef::getConfigValue("xref.project-check", true)
            ? new XRef_LintEngine_ProjectCheck($xref)
            : new XRef_LintEngine_Simple($xref);
    if ($incremental) {
        // incremental mode - find only the errors that are new from the old version of the same file
        $errors = $lint_engine->getIncrementalReport($file_provider_old, $file_provider_new, $modified_files);
    } else {
        // normal mode - report about every error in file
        $errors = $lint_engine->getReport($file_provider_new);
    }

    if (count($errors)) {
        if (XRef::verbose()) {
            error_log(count($errors) . " file(s) with errors found");
        }
        XRef_notifyAuthor($xref, $errors, $branch_name, $old_rev, $current_rev);
        $db["numberOfSentLetters"]++;
    }

    // save the database on each iteration/branch:
    // if we die for whatever reason (memory?), don't spam about already processed branches
    $db["branches"][$branch_name] = $current_rev;
    $storage->saveData("ci", "database", $db);
}
$storage->releaseLock("ci");

function XRef_notifyAuthor(XRef $xref, $fileErrors, $branch_name, $old_rev, $current_rev) {
    $reply_to       = XRef::getConfigValue('mail.reply-to');
    $from           = XRef::getConfigValue('mail.from');
    $recepients     = XRef::getConfigValue('mail.to');
    $project_name   = XRef::getConfigValue('project.name', '');

    // this works for git, will it work for other scms?
    $old_rev_short      = (strlen($old_rev)>7)     ? substr($old_rev, 0, 7)     : $old_rev;
    $current_rev_short  = (strlen($current_rev)>7) ? substr($current_rev, 0, 7) : $current_rev;

    // $commitInfo: array('an'=>'igariev', 'ae'=>'igariev@9e1ac877-.', ...)
    $commitInfo = $xref->getSourceCodeManager()->getRevisionInfo($current_rev);

    $subject    = "XRef CI $project_name: $branch_name/$current_rev_short";
    $headers    =   "MIME-Version: 1.0\n".
            "Content-type: text/html\n".
            "Reply-to: $reply_to\n".
            "From: $from\n";

    $body = $xref->fillTemplate("ci-email.tmpl", array(
        'branchName'        => $branch_name,
        'oldRev'            => $old_rev,
        'oldRevShort'       => $old_rev_short,
        'currentRev'        => $current_rev,
        'currentRevShort'   => $current_rev_short,
        'fileErrors'        => $fileErrors,
        'commitInfo'        => $commitInfo,
    ));

    foreach ($recepients as $to) {
        $to = preg_replace('#\{%(\w+)\}#e', '$commitInfo["$1"]', $to);
        mail($to, $subject, $body, $headers);
    }
}
// vim: tabstop=4 expandtab

