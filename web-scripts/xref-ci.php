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


$includeDir = ("@php_dir@" == "@"."php_dir@") ? dirname(__FILE__) . "/.." : "@php_dir@/Xref";
require_once "$includeDir/XRef.class.php";
require_once "$includeDir/lib/ci-tools.php";

$xref = new Xref();
$xref->loadPluginGroup("lint");
$scm = $xref->getSourceCodeManager();

$rev1 = isset($_REQUEST["rev1"]) ? preg_replace('#[^\w\-/}{ @]#', '', $_REQUEST["rev1"]) : '';
$rev2 = isset($_REQUEST["rev2"]) ? preg_replace('#[^\w\-/}{ @]#', '', $_REQUEST["rev2"]) : '';

if ($rev1 && $rev2) {
    $fileErrors = array();
    $listOfFiles = $scm->getListOfModifiedFiles($rev1, $rev2);
    foreach ($listOfFiles as $file) {
        if (!preg_match("#\\.php\$#", $file)) {
            continue;
        }
        try {
            $oldErrors = XRef_getErrorsList($xref, $file, $rev1);
        } catch (Exception $e) {
            // oops, syntax errors in previsous version of the file?
            // let's report all errors in the file then.
            $oldErrors = array();
        }
        try {
            $curErrors = XRef_getErrorsList($xref, $file, $rev2);
        } catch (Exception $e) {
            $fileErrors[$file] = "Can't parse file, syntax error? (" . $e->getMessage() . ")";
            continue;
        }
        $errors = XRef_getNewErrors($oldErrors, $curErrors);
        if (count($errors)) {
            $fileErrors[$file] = $errors;
        }
    }
}

echo $xref->fillTemplate("ci-web.tmpl", array(
    'rev1'          => $rev1,
    'rev2'          => $rev2,
    'fileErrors'    => $fileErrors,
));

