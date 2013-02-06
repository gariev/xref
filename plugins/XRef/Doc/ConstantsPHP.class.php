<?php
/**
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */


class XRef_Plugin_Constants_Constant {
    public $name;
    public $declaredAt      = array();  // public $bar;
    public $usedAt          = array();  // XRef_FilePosition -- $foo->bar
}

class XRef_Doc_ConstantsPHP extends XRef_APlugin implements XRef_IDocumentationPlugin {
    protected $reportId             = "php-constants";
    protected $reportName           = "List of PHP class constants";
    protected $supportedFileType    = XRef::FILETYPE_PHP;

    public function getName() {
        return $this->reportName;
    }
    public function getId() {
        return $this->reportId;
    }

    // map: const name ("class::const") --> XRef_Plugin_Constants_Constant object
    protected $constants = array();

    protected function getOrCreate($constName) {
        if (!array_key_exists($constName, $this->constants)) {
            $this->constants[$constName] = new XRef_Plugin_Constants_Constant();
            $this->constants[$constName]->name = $constName;
        }
        return $this->constants[$constName];
    }

    public function generateFileReport(XRef_IParsedFile $pf) {
        if ($pf->getFileType() != $this->supportedFileType) {
            return;
        }

        $tokens = $pf->getTokens();

        for ($i=0; $i<count($tokens); ++$i) {
            $t = $tokens[$i];

            // Const declared:
            // const FOO = 10;
            if ($t->kind==T_CONST) {
                $n = $t->nextNS();
                $name = $pf->getClassAt($n->index) . "::" . $n->text;
                $c = $this->getOrCreate($name);

                $filePos = new XRef_FilePosition($pf, $n);
                $c->declaredAt[] = $filePos;

                // link from source file HTML page to report page "reportId/objectId"
                $this->xref->addSourceFileLink($filePos, $this->reportId, $name);
            }

            // Const used:
            // foo::bar, but not foo::bar() or foo::$bar\
            if ($t->kind==T_DOUBLE_COLON) {
                $p = $t->prevNS();
                if ($p->kind==T_STRING) {
                    $n = $t->nextNS();

                    if ($n->kind==T_VARIABLE) {
                        // static field
                        continue;
                    } elseif ($n->kind==T_STRING) {
                        $nn = $n->nextNS();
                        if ($nn->kind==XRef::T_ONE_CHAR && $nn->text=='(') {
                            // method call
                            continue;
                        }
                    } else {
                        error_log("What's this: $n->text");
                        continue;
                    }

                    $className = $p->text;
                    if ($className=='self') {
                        $className = $pf->getClassAt($p->index);
                        if (!$className) {
                            error_log("Reference to self:: class not inside a class method at " . $pf->getFileName() . ":$p->lineNumber");
                            continue;
                        }
                    }

                    $name = $className . "::" . $n->text;
                    $c = $this->getOrCreate($name);
                    $filePos = new XRef_FilePosition($pf, $n);
                    $c->usedAt[] = $filePos;

                    // link from source file HTML page to report page "reportId/objectId"
                    $this->xref->addSourceFileLink($filePos, $this->reportId, $name);

                }
            }

        } // foreach token
    }

    public function generateTotalReport() {

        $names = array_keys($this->constants);
        sort($names);
        $count = count($names);

        // index page
        list($fh, $root) = $this->xref->getOutputFileHandle($this->reportId, null);
        fwrite($fh,
            $this->xref->fillTemplate(
                'doc-total-report.tmpl',
                array(
                    'reportName' => $this->getName(),
                    'reportId'   => $this->getId(),
                    'root'       => $root,
                    'names'      => $names,
                    'objects'    => $this->constants,
                )
            )
        );
        fclose($fh);

        // page for each constant
        foreach ($names as $name) {
            $c = $this->constants[$name];

            list($fh, $root) = $this->xref->getOutputFileHandle($this->reportId, $name);
            fwrite($fh,
                $this->xref->fillTemplate(
                    'doc-constant-report.tmpl',
                    array(
                        'reportName'    => $this->getName(),
                        'reportId'      => $this->getId(),
                        'root'          => $root,
                        'c'             => $c,
                        'title'         => "Constant"
                    )
                )
            );
            fclose($fh);
        }
    }
}

// vim: tabstop=4 expandtab
