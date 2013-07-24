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
require_once "$includeDir/lib/ci-tools.php";
require_once "$includeDir/lib/experimental.php";

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
foreach ($branches as $branchName => $currentRev) {

    // new branch: don't check files, just add to list of known branches
    if (!isset($db["branches"][$branchName])) {
        $db["branches"][$branchName] = $currentRev;
        if (XRef::verbose()) {
            error_log("new branch $branchName");
        }
        $storage->saveData("ci", "database", $db);
        continue;
    }

    $oldRev = $db["branches"][$branchName];

    // nothing changed for this branch
    if ($oldRev==$currentRev) {
        if (XRef::verbose()) {
            error_log("Branch $branchName was not modified");
        }
        continue;
    }

    if (XRef::verbose()) {
        error_log("Processing branch $branchName");
    }

    // $fileErrors: array(file name => scalar or array with errors)
    $fileErrors = array();
    $modified_files = $scm->getListOfModifiedFiles($oldRev, $currentRev);
    $file_provider_old = $scm->getFileProvider($oldRev);
    $file_provider_new = $scm->getFileProvider($currentRev);
    foreach ($modified_files as $filename) {
        if (XRef::verbose()) {
            error_log(" ... file $filename");
        }

        if ($incremental) {
            // incremental mode - find only the errors that are new from the old version of the same file
            $old_errors = XRef_getErrorsList($xref, $file_provider_old, $filename);
            $new_errors = XRef_getErrorsList($xref, $file_provider_new, $filename);
            $errors = XRef_getNewErrors($old_errors, $new_errors);
        } else {
            // normal mode - report about every error in file
            $errors = XRef_getErrorsList($xref, $file_provider_new, $filename);
        }

        if ($errors) {
            $fileErrors[$filename] = $errors;
        }
    }

    /* ------------------------------------------------------------
     * EXPERIMENTAL PART
     * ------------------------------------------------------------*/
    $projectErrors = array();

    if ($incremental) {
        $project_database = new XRef_ProjectDatabase_Persistent($oldRev, $xref, $file_provider_old);
        $project_database->save($oldRev);
        $old_errors = $xref->getProjectReport($project_database);

        $project_database->update($file_provider_new, $modified_files);
        $project_database->save($currentRev);
        $current_errors = $xref->getProjectReport($project_database);

        $projectErrors = XRef_getNewProjectErrors($old_errors, $current_errors);
    } else {
        $project_database = new XRef_ProjectDatabase_Persistent($currentRev, $xref, $file_provider_new);
        $project_database->save($currentRev);
        $projectErrors = $xref->getProjectReport($project_database);
    }
    $fileErrors = array_merge_recursive($fileErrors, $projectErrors);
    /* ------------------------------------------------------------
     * END OF EXPERIMENTAL PART
     * ------------------------------------------------------------*/

    $fileErrors = $xref->sortAndFilterReport($fileErrors);
    if (count($fileErrors)) {
        if (XRef::verbose()) {
            error_log(count($fileErrors) . " files with errors found");
        }
        XRef_notifyAuthor($xref, $fileErrors, $branchName, $oldRev, $currentRev);
        $db["numberOfSentLetters"]++;
    }

    // save the database on each iteration/branch:
    // if we die for whatever reason (memory?), don't spam about already processed branches
    $db["branches"][$branchName] = $currentRev;
    $storage->saveData("ci", "database", $db);
}
$storage->releaseLock("ci");

function XRef_notifyAuthor(XRef $xref, $fileErrors, $branchName, $oldRev, $currentRev) {
    $replyTo    = XRef::getConfigValue('mail.reply-to');
    $from       = XRef::getConfigValue('mail.from');
    $recepients = XRef::getConfigValue('mail.to');
    $projectName= XRef::getConfigValue('xref.project-name', '');

    // this works for git, will it work for other scms?
    $oldRevShort    = (strlen($oldRev)>7)     ? substr($oldRev, 0, 7)     : $oldRev;
    $currentRevShort= (strlen($currentRev)>7) ? substr($currentRev, 0, 7) : $currentRev;

    // $commitInfo: array('an'=>'igariev', 'ae'=>'igariev@9e1ac877-.', ...)
    $commitInfo = $xref->getSourceCodeManager()->getRevisionInfo($currentRev);

    $subject    = "XRef CI $projectName: $branchName/$currentRevShort";
    $headers    =   "MIME-Version: 1.0\n".
            "Content-type: text/html\n".
            "Reply-to: $replyTo\n".
            "From: $from\n";

    $body = $xref->fillTemplate("ci-email.tmpl", array(
        'branchName'        => $branchName,
        'oldRev'            => $oldRev,
        'oldRevShort'       => $oldRevShort,
        'currentRev'        => $currentRev,
        'currentRevShort'   => $currentRevShort,
        'fileErrors'        => $fileErrors,
        'commitInfo'        => $commitInfo,
    ));

    foreach ($recepients as $to) {
        $to = preg_replace('#\{%(\w+)\}#e', '$commitInfo["$1"]', $to);
        mail($to, $subject, $body, $headers);
    }
}
// vim: tabstop=4 expandtab

