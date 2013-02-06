<?php

/**
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

class XRef_Doc_SourceFileDisplay extends XRef_APlugin implements XRef_IDocumentationPlugin {

    const LINE_NUMBER_FORMAT = "<a id='%d'>%5d   ";
    const REPORT_ID = "files";

    // set of all file names
    protected $fileList = array();

    public function getName() {
        return "List of all files";
    }

    public function getId() {
        return self::REPORT_ID;
    }

    public function generateTotalReport() {
        sort($this->fileList);
        $count = count($this->fileList);

        // index page
        list($fh, $root) = $this->xref->getOutputFileHandle(self::REPORT_ID, null);
        fwrite($fh,
            $this->xref->fillTemplate(
                'doc-total-report.tmpl',
                array(
                    'reportName' => $this->getName(),
                    'reportId'   => $this->getId(),
                    'root'       => $root,
                    'names'      => $this->fileList,
                )
            )
        );
        fclose($fh);

        // xref.css
        list($fh) = $this->xref->getOutputFileHandle("xref", null, "css");
        fwrite($fh, $this->getDefaultCSS());
        fclose($fh);
    }

    public function generateFileReport(XRef_IParsedFile $pf) {
        $filename = $pf->getFileName();
        $this->fileList[] = $filename;

        list($fh, $root) = $this->xref->getOutputFileHandle(self::REPORT_ID, $filename);
        fwrite($fh,
            $this->xref->fillTemplate(
                'doc-file-report.tmpl',
                array(
                    'reportName' => $this->getName(),
                    'reportId'   => $this->getId(),
                    'root'       => $root,
                    'sourceText' => $this->getFormattedText($pf, $root),
                )
            )
        );
        fclose($fh);
    }

    public function getDefaultCSS() {
        return <<<CSS
                .T_COMMENT, .T_DOC_COMMENT
                    { color: green; }
                .T_VARIABLE
                    { font-weight: bold; }
                .T_ABSTRACT, .T_AS, .T_BREAK, .T_CASE, .T_CATCH, .T_CLASS, .T_CLASS_C, .T_CONST,
                .T_CONTINUE, .T_DECLARE, .T_DEFAULT, .T_DO, .T_ELSE, .T_ELSE, .T_EXTENDS,
                .T_FINAL, .T_FOR, .T_FOREACH, .T_FUNCTION, .T_GLOBAL, .T_IF, .T_IMPLEMENTS,
                .T_INTERFACE, .T_NAMESPACE, .T_NEW, .T_NULL, .T_PRIVATE, .T_PUBLIC, .T_PROTECTED,
                .T_REQUIRE_ONCE, .T_RETURN, .T_STATIC, .T_SWITCH, .T_THROW, .T_TRY, .T_WHILE,
                .T_PACKAGE,
                .T_VAR, .T_IMPORT, .T_OVERRIDE, .T_IN, .T_EACH, .T_GET, .T_SET,
                .T_TRUE, .T_FALSE
                    { color: #808; }
                .T_CONSTANT_ENCAPSED_STRING
                    {color: darkblue; font-weight: bold;}

                .error      {color: red; }
                .warning    {color: orange; }
                .notice     {color: black; }
CSS
    ; }


    public function getFormattedText(XRef_IParsedFile $pf, $root) {
        $filename = $pf->getFileName();
        $links = $this->xref->getSourceFileLinks($filename);
        $tokens = $pf->getTokens();
        $lineNumber = 1;

        $ret = '';
        $ret .= sprintf(self::LINE_NUMBER_FORMAT, $lineNumber, $lineNumber);
        for ($i = 0; $i<count($tokens); ++$i) {
            $token = $tokens[$i];
            if ($links && array_key_exists($i, $links)) {
                // TODO: ugly, redo
                if (is_array($links[$i])) {
                    list($reportName, $objectId) = $links[$i];
                    $link = $this->xref->getHtmlLinkFor($reportName, $objectId, $root);
                    $ret .= "<a href='$link'>";
                } else {
                    $ret .= "</a>";
                }
            }

            $text = htmlspecialchars($token->text);
            if ($token->kind == Xref::T_ONE_CHAR) {
                $ret .= $text;
            } else {
                $span_class = array_key_exists($token->kind, XRef::$tokenNames)
                    ? XRef::$tokenNames[$token->kind]
                    : token_name($token->kind);
                if ($span_class=='UNKNOWN') {
                    $span_class = $token->kind;
                }
                if (!strstr($text, "\n")) {
                    // no new lines, simple
                    $ret .= "<span class='$span_class'>$text</span>";
                } else {
                    // ugly code that does the following:
                    // input:
                    //      some \n multiline text \n startig at line 10
                    // output:
                    //      <span class="text">some</span>
                    //      11 <span class="text">multiline text </span>
                    //      12  <span class="text"> starting at line 10 </span>
                    $parts = explode("\n", $text);
                    for ($j=0; $j<count($parts); $j++) {
                        if ($j!=0) {
                            $lineNumber++;
                            $ret .= sprintf(self::LINE_NUMBER_FORMAT, $lineNumber, $lineNumber);
                        }
                        $ret .= "<span class='$span_class'>$parts[$j]</span>";
                        if ($j != count($parts)-1) {
                            $ret .= "\n";
                        }
                    }
                }
            }
        }
        return $ret;
    }

}

// vim: tabstop=4 expandtab
