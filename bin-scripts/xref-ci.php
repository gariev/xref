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

list ($options, $arguments) = XRef::getCmdOptions();
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
    $listOfFiles = $scm->getListOfModifiedFiles($oldRev, $currentRev);
    foreach ($listOfFiles as $file) {
        if (!preg_match("#\\.php\$#", $file)) {
            continue;
        }
        if (XRef::verbose()) {
            error_log(" ... file $file");
        }

        if ($incremental) {
            // incremental mode - find only the errors that are new from the old version of the same file
            try {
                $oldErrors = XRef_getErrorsList($xref, $file, $oldRev);
            } catch (Exception $e) {
                // oops, syntax errors in previsous version of the file?
                // let's report all errors in the file then.
                $oldErrors = array();
            }

            try {
                $curErrors = XRef_getErrorsList($xref, $file, $currentRev);
            } catch (Exception $e) {
                $fileErrors[$file] = "Can't parse file, syntax error? (" . $e->getMessage() . ")";
                continue;
            }
            $errors = XRef_getNewErrors($oldErrors, $curErrors);
        } else {
            // normal mode - report about every error in file
            try {
                $errors = XRef_getErrorsList($xref, $file, $currentRev);
            } catch (Exception $e) {
                $fileErrors[$file] = "Can't parse file, syntax error? (" . $e->getMessage() . ")";
                continue;
            }
        }

        if (count($errors)) {
            $fileErrors[$file] = $errors;
        }
    }

    /* ------------------------------------------------------------
     * EXPERIMENTAL PART
     * ------------------------------------------------------------*/
    $projectErrors = array();
    $project_lint = new ProjectLintPrototype();
    $project_lint->setXRef($xref);
    $project_lint->loadOrCreateProject($branchName, $oldRev);
    $old_errors = $project_lint->getErrors();
    foreach ($listOfFiles as $file) {
        if (!preg_match("#\\.php\$#", $file)) {
            continue;
        }
        $project_lint->updateFile($currentRev, $file);
    }
    $current_errors = $project_lint->getErrors();
    $project_lint->saveProject($branchName);
    foreach ($current_errors as $file => $errors_list) {
        if (!isset($oldErrors[$file])) {
            $projectErrors[$file] = $errors_list;
        } else {
            $errors = XRef_getNewErrors($old_errors[$file], $errors_list);
            if (count($errors)) {
                $projectErrors[$file] = $errors;
            }
        }
    }
    /* ------------------------------------------------------------
     * END OF EXPERIMENTAL PART
     * ------------------------------------------------------------*/

    if (count($fileErrors) || count($projectErrors)) {
        if (XRef::verbose()) {
            error_log(count($fileErrors) . " files with errors found");
        }
        XRef_notifyAuthor($xref, $fileErrors, $projectErrors, $branchName, $oldRev, $currentRev);
        $db["numberOfSentLetters"]++;
    }

    // save the database on each iteration/branch:
    // if we die for whatever reason (memory?), don't spam about already processed branches
    $db["branches"][$branchName] = $currentRev;
    $storage->saveData("ci", "database", $db);
}
$storage->releaseLock("ci");

function XRef_notifyAuthor(XRef $xref, $fileErrors, $projectErrors, $branchName, $oldRev, $currentRev) {
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
        'projectErrors'     => $projectErrors,
        'commitInfo'        => $commitInfo,
    ));

    foreach ($recepients as $to) {
        $to = preg_replace('#\{%(\w+)\}#e', '$commitInfo["$1"]', $to);
        mail($to, $subject, $body, $headers);
    }
}
// vim: tabstop=4 expandtab

