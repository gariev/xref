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

if (isset($_REQUEST["source"]) && $_REQUEST["source"]) {
    $source = (get_magic_quotes_gpc()) ? stripslashes($_REQUEST["source"]) : $_REQUEST["source"];
    try {
        $parsed_file = $xref->getParsedFile("unknown.php", $source);
        if (count($parsed_file->getTokens()) > 1) {
            $lint_engine = XRef::getConfigValue("xref.project-check", true)
                    ? new XRef_LintEngine_ProjectCheck($xref)
                    : new XRef_LintEngine_Simple($xref);
            $lint_engine->addParsedFile($parsed_file);
            $report = $lint_engine->collectReport();
            $formattedText = $sourcePlugin->getFormattedText($parsed_file, "");
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
));
// vim: tabstop=4 expandtab

