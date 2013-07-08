<?php

/**
 * this is an ad-hoc script that can:
 *      1. show content of given file@revision from repository
 *      2. mark line numbers
 *      3. highlight syntax
 *
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

$includeDir = ("@php_dir@" == "@"."php_dir@") ? dirname(__FILE__) . "/.." : "@php_dir@/XRef";
require_once "$includeDir/XRef.class.php";

$filename = $_REQUEST["filename"];
$revision = $_REQUEST["revision"];
if (preg_match('#[^\w\.\/\-]#', $filename) || preg_match('#\.\.#', $filename) || preg_match('#[^\w\-/}{@ ]#', $revision)) {
    echo "Invalid filename or revision";
    exit(1);
}


$xref = new XRef();
$xref->loadPluginGroup("lint");
$sourcePlugin = $xref->getPluginById("files");
if ($sourcePlugin) {
    $css = $sourcePlugin->getDefaultCSS();
    $scm = $xref->getSourceCodeManager();
    $source = $scm->getFileContent($revision, $filename);
    $parsedFile = $xref->getParsedFile("unknown.php", $source);
    $formattedText = $sourcePlugin->getFormattedText($parsedFile, "");

    echo "<html><head><style>$css</style></head><body>";
    echo "<pre>$formattedText</pre>";
    echo "</body></html>";
} else {
    echo "Can't display formatted text: 'files' plugin not found";
}
// vim: tabstop=4 expandtab

