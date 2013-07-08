<?php
/**
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */


class XRef_Plugin_Constants_Constant {
    public $name;                       // Fully-qualified name, e.g. Foo\Bar::Baz
    public $id;                         // e.g. foo\bar::Baz
    public $declaredAt      = array();  // public $bar;
    public $usedAt          = array();  // XRef_FilePosition -- $foo->bar
}

class XRef_Doc_ConstantsPHP extends XRef_APlugin implements XRef_IDocumentationPlugin {
    protected $supportedFileType    = XRef::FILETYPE_PHP;

    public function __construct() {
        parent::__construct("php-constants", "List of PHP class constants");
    }

    // map: const id ("class::ConstName") --> XRef_Plugin_Constants_Constant object
    protected $constants = array();

    protected function getOrCreate($constName) {

        $parts = preg_split('#::#', $constName, 2);
        $id = (count($parts)>1) ? strtolower($parts[0]) . '::' . $parts[1] : $constName;

        if (!array_key_exists($id, $this->constants)) {
            $c = new XRef_Plugin_Constants_Constant();
            $c->name = $constName;
            $c->id = $id;
            $this->constants[$id] = $c;
        }
        return $this->constants[$id];
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
                $class = $pf->getClassAt($n->index);
                if ($class) {
                    $name = $class->name . "::" . $n->text;
                } else {
                    $name = $n->text;
                }
                $c = $this->getOrCreate($name);

                $filePos = new XRef_FilePosition($pf, $n->index);
                $c->declaredAt[] = $filePos;

                // link from source file HTML page to report page "reportId/objectId"
                $this->xref->addSourceFileLink($filePos, $this->reportId, $c->id);
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
                        $class = $pf->getClassAt($p->index);
                        if (!$class) {
                            error_log("Reference to self:: class not inside a class method at " . $pf->getFileName() . ":$p->lineNumber");
                            continue;
                        }
                        $className = $class->name;
                    }

                    $name = $className . "::" . $n->text;
                    $c = $this->getOrCreate($name);
                    $filePos = new XRef_FilePosition($pf, $n->index);
                    $c->usedAt[] = $filePos;

                    // link from source file HTML page to report page "reportId/objectId"
                    $this->xref->addSourceFileLink($filePos, $this->reportId, $c->id);

                }
            }

        } // foreach token
    }

    public function generateTotalReport() {

        $ids = array_keys($this->constants);
        sort($ids);
        $count = count($ids);

        // index page
        list($fh, $root) = $this->xref->getOutputFileHandle($this->reportId, null);
        fwrite($fh,
            $this->xref->fillTemplate(
                'doc-total-report.tmpl',
                array(
                    'reportName' => $this->getName(),
                    'reportId'   => $this->getId(),
                    'root'       => $root,
                    'names'      => $ids,
                    'objects'    => $this->constants,
                )
            )
        );
        fclose($fh);

        // page for each constant
        foreach ($ids as $id) {
            $c = $this->constants[$id];

            list($fh, $root) = $this->xref->getOutputFileHandle($this->reportId, $id);
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
