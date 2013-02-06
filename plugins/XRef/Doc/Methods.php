<?php
/**
 * Base (internal) class for PHP and ActionScript documentation plugins for class and object methods, and non-class functions.
 * TODO: move methods/functions parsing into parser objects
 *
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

// hate PHP - there are no nested classes, names are long and ugly
class XRef_Plugin_Methods_Method {
    public $name;
    public $definedAt = array();    // XRef_FilePosition
    public $calledFrom = array();   // XRef_FilePosition
}

// this class is marked abstract to prevent instantiation; it implements all methods, however
abstract class XRef_Plugin_Methods extends XRef_APlugin implements XRef_IDocumentationPlugin {
    protected $reportId     = "php-methods";
    protected $reportName   = "PHP methods and functions";
    protected $isCaseSensitive = false; // php methods are case-insensitive
    protected $supportedFileType = XRef::FILETYPE_PHP;

    protected function __construct($reportId, $reportName, $isCaseSensitive, $supportedFileType) {
        $this->reportId = $reportId;
        $this->reportName = $reportName;
        $this->isCaseSensitive = $isCaseSensitive;
        $this->supportedFileType = $supportedFileType;
    }

    public function getName() {
        return $this->reportName;
    }
    public function getId() {
        return $this->reportId;
    }

    // map: method name --> XRef_Plugin_Methods_Method objects
    protected $methods = array();

    public function getMethodByName($methodName) {
        if (!$this->isCaseSensitive) {
            $methodName = strtolower($methodName);
        }
        return (isset($this->methods[$methodName])) ? $this->methods[$methodName] : null;
    }

    public function generateFileReport(XRef_IParsedFile $pf) {
        if ($pf->getFileType() != $this->supportedFileType) {
            return;
        }

        // collect all declared methods
        $pf_methods = $pf->getMethods();
        foreach ($pf_methods as $pfm) {
            $name = ($this->isCaseSensitive) ? $pfm->name : strtolower($pfm->name);
            if (!array_key_exists($name, $this->methods)) {
                $this->methods[$name] = new XRef_Plugin_Methods_Method();
            }
            $m = $this->methods[$name];

            $m->name = $pfm->name; // preserve original case
            $definedAt = new XRef_FilePosition($pf, $pfm);
            $m->definedAt[] = $definedAt;

            // link from source file HTML page to report page "reportId/objectId"
            $this->xref->addSourceFileLink($definedAt, $this->reportId, $name);
        }

        // collect all method calls
        $tokens = $pf->getTokens();
        for ($i=0; $i<count($tokens); ++$i) {
            $t = $tokens[$i];

            // find something like:
            //      <name> "("
            // and not like
            //      "new" <name> "("
            //      "function" <name> "("
            //      "function" ("get"|"set") <name> "(" // AS3 declaration of getter/setter function
            if ($t->kind==T_STRING) {
                $n = $t->nextNS();
                if ($n!=null && $n->kind==XRef::T_ONE_CHAR && $n->text=="(") {
                    $p = $t->prevNS();

                    if ($p!=null && $p->kind==XRef::T_ONE_CHAR && $p->text=='&') {
                        // PHP - function &foo(
                        $p = $p->prevNS();
                    }

                    if (($p->kind!=T_NEW && $p->kind!=T_FUNCTION && $p->kind!=XRef::T_GET && $p->kind!=XRef::T_SET)
                            || $p==null)
                    {
                        $name = ($this->isCaseSensitive) ? $t->text : strtolower($t->text);
                        if (!array_key_exists($name, $this->methods)) {
                            $this->methods[$name] = new XRef_Plugin_Methods_Method();
                            $this->methods[$name]->name = $t->text; // original case
                        }
                        $m = $this->methods[$name];

                        $calledFrom = new XRef_FilePosition($pf, $t);
                        $m->calledFrom[] = $calledFrom;

                        // link from source file HTML page to report page "reportId/objectId"
                        $this->xref->addSourceFileLink($calledFrom, $this->reportId, $name);
                    }
                }
            }
        }
    }

    public function generateTotalReport() {

        $names = array_keys($this->methods);
        $count = count($names);
        sort($names);

        // index page
        list($fh, $root) = $this->xref->getOutputFileHandle($this->reportId, null);
        fwrite($fh,
            $this->xref->fillTemplate(
                'doc-total-report.tmpl',
                array(
                    'reportName' => $this->getName(),
                    'reportId'   => $this->getid(),
                    'root'       => $root,
                    'names'      => $names,
                    'objects'    => $this->methods,
                )
            )
        );
        fclose($fh);

        // page for each method
        foreach ($names as $name) {
            $m = $this->methods[$name];

            list($fh, $root) = $this->xref->getOutputFileHandle($this->reportId, $name);
            fwrite($fh,
                $this->xref->fillTemplate(
                    'doc-method-report.tmpl',
                    array(
                        'reportName'    => $this->getName(),
                        'reportId'      => $this->getid(),
                        'root'          => $root,
                        'm'             => $m,
                        'title'         => 'Method/Function'
                    )
                )
            );
            fclose($fh);
        }
    }
}

// vim: tabstop=4 expandtab
