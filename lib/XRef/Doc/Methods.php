<?php
/**
 * Base (internal) class for PHP and ActionScript documentation plugins for class and object methods, and non-class functions.
 * TODO: move methods/functions parsing into parser objects
 *
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

class XRef_Plugin_Methods_Method {
    public $name;                   // simple method or function name, without class or namespace prefix
    public $id;                     // lower-case name
    public $definedAt = array();    // XRef_FilePosition
    public $calledFrom = array();   // XRef_FilePosition
}

// this class is marked abstract to prevent instantiation; it implements all methods, however
abstract class XRef_Plugin_Methods extends XRef_APlugin implements XRef_IDocumentationPlugin {
    protected $supportedFileType = XRef::FILETYPE_PHP;

    public function __construct($reportId, $reportName, $isCaseSensitive, $supportedFileType) {
        parent::__construct($reportId, $reportName);
        $this->supportedFileType = $supportedFileType;
    }

    // map: method name --> XRef_Plugin_Methods_Method objects
    protected $methods = array();

    /** @return XRef_Plugin_Methods_Method */
    protected function getOrCreate($methodName) {
        $id = strtolower($methodName);
        // remove namespace, if any
        if (preg_match('#\\\\([^\\\\]+)$#', $id, $matches)) {
            $id = $matches[1];
        }
        if (!array_key_exists($id, $this->methods)) {
            $m = new XRef_Plugin_Methods_Method();
            $m->name = $methodName;
            $m->id = $id;
            $this->methods[$id] = $m;
        }
        return $this->methods[$id];
    }

    public function generateFileReport(XRef_IParsedFile $pf) {
        if ($pf->getFileType() != $this->supportedFileType) {
            return;
        }

        // collect all declared methods
        $pf_methods = $pf->getMethods();
        foreach ($pf_methods as /** @var $pfm XRef_Function */ $pfm) {
            if ($pfm->name) {
                $m = $this->getOrCreate($pfm->name);
                $definedAt = new XRef_FilePosition($pf, $pfm->nameIndex);
                $m->definedAt[] = $definedAt;

                // link from source file HTML page to report page "reportId/objectId"
                $this->xref->addSourceFileLink($definedAt, $this->reportId, $m->id, true);
            }
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
                        $m = $this->getOrCreate($t->text);
                        $calledFrom = new XRef_FilePosition($pf, $t->index);
                        $m->calledFrom[] = $calledFrom;

                        // link from source file HTML page to report page "reportId/objectId"
                        $this->xref->addSourceFileLink($calledFrom, $this->reportId, $m->id);
                    }
                }
            }
        }
    }

    public function generateTotalReport() {

        $ids = array_keys($this->methods);
        $count = count($ids);
        sort($ids);

        // index page
        list($fh, $root) = $this->xref->getOutputFileHandle($this->reportId, null);
        fwrite($fh,
            $this->xref->fillTemplate(
                'doc-total-report.tmpl',
                array(
                    'reportName' => $this->getName(),
                    'reportId'   => $this->getid(),
                    'root'       => $root,
                    'names'      => $ids,
                    'objects'    => $this->methods,
                )
            )
        );
        fclose($fh);

        // page for each method
        foreach ($ids as $id) {
            $m = $this->methods[$id];

            list($fh, $root) = $this->xref->getOutputFileHandle($this->reportId, $id);
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
