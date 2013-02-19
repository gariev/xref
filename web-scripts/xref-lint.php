<?php

/**
 * lib/bin-scripts/xref-lint.php
 *
 * This is a lint (a tool to find potential bugs in source code) for PHP sources.
 * This is a web version
 *
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

$includeDir = ("@php_dir@" == "@"."php_dir@") ? dirname(__FILE__) . "/.." : "@php_dir@/XRef";
require_once("$includeDir/XRef.class.php");

// web-server lint script

$xref = new XRef();
$xref->loadPluginGroup("lint");
$sourcePlugin = $xref->getPluginById("files");
$css = $sourcePlugin->getDefaultCSS();

$textareaContent = '// put source code here';
$report = null;
$exceptionMessage = null;
$formattedText = null;
$checkGlobalScope = XRef::getConfigValue("lint.check-global-scope", false);

if (isset($_REQUEST["source"])) {
    $source = (get_magic_quotes_gpc()) ? stripslashes($_REQUEST["source"]) : $_REQUEST["source"];
    $checkGlobalScope = isset($_REQUEST["check-global-scope"]) && $_REQUEST["check-global-scope"];
    XRef::setConfigValue("lint.check-global-scope", $checkGlobalScope);
    try {
        $parsedFile = $xref->getParsedFile("unknown.php", "php", $source);
        if (count($parsedFile->getTokens()) > 1) {
            $report = $xref->getLintReport($parsedFile);
            $formattedText = $sourcePlugin->getFormattedText($parsedFile, "");
        } else {
            $textareaContent = htmlspecialchars($source);
        }
    } catch (Exception $e) {
        $exceptionMessage = $e->getMessage();
        $textareaContent = htmlspecialchars($source);
    }
}

echo $xref->fillTemplate('lint-web.tmpl', array(
    'textareaContent'   => $textareaContent,
    'hasSource'         => isset($source),
    'formattedText'     => $formattedText,
    'exceptionMessage'  => $exceptionMessage,
    'report'            => $report,
    'css'               => $css,
    'checkGlobalScope'  => $checkGlobalScope,
));
// vim: tabstop=4 expandtab

