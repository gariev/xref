<?php
/**
 * This is an (internal) base class for PHP and ActionScript documentation plugins for classes.
 * TODO: move classes functionality to parser (XRef_Parser_PHP)
 *
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

// PHP 5.2 - there are no nested classes or namepsaces, names are long and ugly :(
class XRef_Plugin_Classes_Class {
    public $name;
    public $extends         = array();  // list(?) of class names
    public $implements      = array();  // list of interfaces names
    public $inheritedBy     = array();
    public $isInterface     = false;
    public $isAbstract      = false;

    public $definedAt       = array();  // XRef_FilePosition
    public $usedAt          = array();  // XRef_FilePosition -- Class::$foo, extends Class, etc
    public $instantiatedAt  = array();  // XRef_FilePosition -- $c = new Class()
}

class XRef_Plugin_Classes extends XRef_APlugin implements XRef_IDocumentationPlugin {
    protected $supportedFileType = XRef::FILETYPE_PHP;

    public function __construct($reportId, $reportName, $supportedFileType) {
        parent::__construct($reportId, $reportName);
        $this->supportedFileType = $supportedFileType;
    }

    // map: class/interface name --> XRef_Plugin_Classes_Class object
    protected $classes = array();

    /** @return XRef_Plugin_Classes_Class */
    protected function getOrCreate($className) {
        $lc_name = strtolower($className);
        if (!array_key_exists($lc_name, $this->classes)) {
            $this->classes[$lc_name] = new XRef_Plugin_Classes_Class();
            $this->classes[$lc_name]->name = $className;
        }
        return $this->classes[$lc_name];
    }

    public function generateFileReport(XRef_IParsedFile $pf) {
        if ($pf->getFileType() != $this->supportedFileType) {
            return;
        }

        // collect all declared classes
        foreach ($pf->getClasses() as /** @var $pfc XRef_Class */ $pfc) {
            $c = $this->getOrCreate($pfc->name);
            $c->isInterface = $pfc->kind==T_INTERFACE;

            $definedAt = new XRef_FilePosition($pf, $pfc->nameIndex);
            $c->definedAt[] = $definedAt;

            // link from source file HTML page to report page "reportId/objectId"
            $this->xref->addSourceFileLink($definedAt, $this->reportId, $pfc->name, true);

            // extended classes/interfaces
            foreach ($pfc->extends as $ext_name) {
                $c->extends[] = $ext_name;
                $ext_class = $this->getOrCreate($ext_name);
                $ext_class->inheritedBy[] = $pfc->name;

                $extendsIndex = $pfc->extendsIndex[$ext_name];
                $filePos = new XRef_FilePosition($pf, $extendsIndex[0], $extendsIndex[1]);
                $ext_class->usedAt[] = $filePos;

                // link from source file HTML page to report page "reportId/objectId"
                $this->xref->addSourceFileLink($filePos, $this->reportId, $ext_name, true);
            }

            // extended classes/interfaces
            foreach ($pfc->implements as $imp_name) {
                $c->implements[] = $imp_name;
                $imp_class = $this->getOrCreate($imp_name);
                $imp_class->inheritedBy[] = $pfc->name;

                $extendsIndex = $pfc->implementsIndex[$imp_name];
                $filePos = new XRef_FilePosition($pf, $extendsIndex[0], $extendsIndex[1]);
                $imp_class->usedAt[] = $filePos;

                // link from source file HTML page to report page "reportId/objectId"
                $this->xref->addSourceFileLink($filePos, $this->reportId, $imp_name, true);
            }
        } // foreach declared class

        // collect all places where this class is instantiated or mentioned:
        $tokens = $pf->getTokens();
        $tokens_count = count($tokens);
        for ($i=0; $i<$tokens_count; ++$i) {
            $t = $tokens[$i];

            // "new" <name>
            // "new" $className
            // "new" "(" <callable-name> ")"  // AS3
            // "new" "<" <int> ">"            // AS3
            if ($t->kind==T_NEW) {
                $t = $t->nextNS();

                if ($t->kind != T_STRING) {
                    continue;
                }

                // TODO: scan for namespaced name, e.g. new \Foo\Bar()
                $name = $pf->qualifyName($t->text, $t->index);

                $new = $this->getOrCreate($name);

                $filePos = new XRef_FilePosition($pf, $t->index);
                $new->instantiatedAt[] = $filePos;

                // link from source file HTML page to report page "reportId/objectId"
                $this->xref->addSourceFileLink($filePos, $this->reportId, $name, true);
                continue;
            } // T_NEW

            //
            // Class :: $foo
            // Class :: foo()
            // but not Class :: CONST
            if ($t->kind==T_DOUBLE_COLON) {
                $p = $t->prevNS();
                if ($p->kind==T_STRING) {
                    $n = $t->nextNS();

                    if ($n->kind==T_VARIABLE) {
                        // ok, static field
                    } elseif ($n->kind==T_STRING) {
                        // method or constant
                        $nn = $n->nextNS();
                        if ($nn->text != '(') {
                            continue;
                        }
                    } else {
                        throw new XRef_ParseException($n);
                    }

                    $className = $pf->qualifyName($p->text, $p->index);
                    if ($pf->getFileType()==XRef::FILETYPE_PHP) {
                        if ($className=='self') {
                            $class = $pf->getClassAt($p->index);
                            if (!$class) {
                                error_log("Reference to self:: class not inside a class method at " . $pf->getFileName() . ":$t->lineNumber");
                                continue;
                            }
                            $className = $class->name;
                        }
                        // TODO: super:: class resolution needs 2-pass parser
                    }

                    $c = $this->getOrCreate($className);
                    $filePos = new XRef_FilePosition($pf, $p->index);
                    $c->usedAt[] = $filePos;
                    $this->xref->addSourceFileLink($filePos, $this->reportId, $p->text, true);
                } else {
                    error_log("Unexpected token $t->text at " . $pf->getFileName() . ":$t->lineNumber");
                }
            }

            // $foo isinstanceof Bar
            // $foo isinstanceof $bar
            if ($t->kind==T_INSTANCEOF) {
                $n = $t->nextNS();
                if ($n->kind==T_STRING) {
                    $className = $pf->qualifyName($n->text, $n->index);
                    $c = $this->getOrCreate($className);
                    $filePos = new XRef_FilePosition($pf, $n->index);
                    $c->usedAt[] = $filePos;
                    $this->xref->addSourceFileLink($filePos, $this->reportId, $className, true);
                }
            }

            // is_a($foo, "Bar")
            if ($t->kind==T_STRING && $t->text=="is_a") {
                $n = $t->nextNS();
                if ($n->text != '(') {
                    continue;
                }
                // TODO: add more checks below
                $n  = $n->nextNS(); // var name. Actually, should skip any expression, e.g.: is_a($user->world, "World")
                $n  = $n->nextNS(); // comma
                $n  = $n->nextNS(); // class name literal?
                $className = $pf->qualifyName(preg_replace("#[\'\"]#", '', $n->text), $n->index);

                $c = $this->getOrCreate($className);
                $filePos = new XRef_FilePosition($pf, $n->index);
                $c->usedAt[] = $filePos;
                $this->xref->addSourceFileLink($filePos, $this->reportId, $className, true);
            }

        } // foreach $token
    }

    public function generateTotalReport() {

        $names = array_keys($this->classes);
        sort($names);

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
                    'objects'    => $this->classes,
                )
            )
        );
        fclose($fh);

        // page for each class
        foreach ($names as $name) {
            $c = $this->classes[$name];

            sort($c->extends);
            sort($c->implements);
            sort($c->inheritedBy);

            list($fh, $root) = $this->xref->getOutputFileHandle($this->reportId, $name);
            fwrite($fh,
                $this->xref->fillTemplate(
                    'doc-class-report.tmpl',
                    array(
                        'reportName'    => $this->getName(),
                        'reportId'      => $this->getId(),
                        'root'          => $root,
                        'c'             => $c,
                        'title'         => ($c->isInterface) ? "Interface" : "Class"
                    )
                )
            );
            fclose($fh);
        }
        //$this->dumpClasses();
    }

    protected function dumpClasses() {
        foreach ($this->classes as $c) {
            unset($c->usedAt);
            unset($c->definedAt);
            unset($c->instantiatedAt);
        }
        list ($fh) = $this->xref->getOutputFileHandle($this->reportId, null, "serialized");
        fwrite($fh, serialize($this->classes));
        fclose($fh);
    }
}

// vim: tabstop=4 expandtab
