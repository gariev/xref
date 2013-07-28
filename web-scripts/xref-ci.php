<?php
/**
 * lib/web-scripts/xref-ci.php
 *
 * This script compares 2 arbitrary git commits
 * and finds/reports new errors in changed files.
 *
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */


$includeDir = ("@php_dir@" == "@"."php_dir@") ? dirname(__FILE__) . "/.." : "@php_dir@/XRef";
require_once "$includeDir/XRef.class.php";
require_once "$includeDir/lib/ci-tools.php";

$xref = new XRef();
$xref->loadPluginGroup("lint");
$scm = $xref->getSourceCodeManager();

$rev1 = isset($_REQUEST["rev1"]) ? preg_replace('#[^\w\-/}{ @]#', '', $_REQUEST["rev1"]) : '';
$rev2 = isset($_REQUEST["rev2"]) ? preg_replace('#[^\w\-/}{ @]#', '', $_REQUEST["rev2"]) : '';

if ($rev1 && $rev2) {
    $fileErrors = array();
    $modified_files = $scm->getListOfModifiedFiles($rev1, $rev2);
    $file_provider1 = $scm->getFileProvider($rev1);
    $file_provider2 = $scm->getFileProvider($rev2);
    foreach ($modified_files as $filename) {
        $old_errors = $xref->getCachedFileReport($file_provider1, $filename);
        $new_errors = $xref->getCachedFileReport($file_provider2, $filename);
        $errors = XRef_getNewErrors($old_errors, $new_errors);
        if (count($errors)) {
            $fileErrors[$filename] = $errors;
        }
    }
}

echo $xref->fillTemplate("ci-web.tmpl", array(
    'rev1'          => $rev1,
    'rev2'          => $rev2,
    'fileErrors'    => $fileErrors,
));

