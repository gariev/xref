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

    // explicitly declared methods of the class
    public $declaredMethods = array();  // map: method name --> stdClass(name => )
}

class XRef_Plugin_Classes extends XRef_APlugin implements XRef_IDocumentationPlugin {
    protected $reportId = "php-classes";
    protected $reportName = "List of PHP classes";
    protected $supportedFileType = XRef::FILETYPE_PHP;

    protected function __construct($reportId, $reportName, $supportedFileType) {
        $this->reportId = $reportId;
        $this->reportName = $reportName;
        $this->supportedFileType = $supportedFileType;
    }

    public function getName() {
        return $this->reportName;
    }
    public function getId() {
        return $this->reportId;
    }

    // map: class/interface name --> XRef_Plugin_Classes_Class object
    protected $classes = array();

    protected function getOrCreate($className) {
        if (!array_key_exists($className, $this->classes)) {
            $this->classes[$className] = new XRef_Plugin_Classes_Class();
            $this->classes[$className]->name = $className;
        }
        return $this->classes[$className];
    }

    public function generateFileReport(XRef_IParsedFile $pf) {
        if ($pf->getFileType() != $this->supportedFileType) {
            return;
        }

        // collect all declared classes
        $pf_classes = $pf->getClasses();
        foreach ($pf_classes as $pfc) {
            $name = $pfc->name;
            $c = $this->getOrCreate($name);
            $c->isInterface = $pfc->kind==T_INTERFACE;

            $definedAt = new XRef_FilePosition($pf, $pfc);
            $c->definedAt[] = $definedAt;

            // link from source file HTML page to report page "reportId/objectId"
            $this->xref->addSourceFileLink($definedAt, $this->reportId, $name);

            // extends/implements
            // "class" <name> "extends" <name> "implements"
            $t = $pf->getTokenAt( $pfc->nameEndIndex + 1 )->nextNS();
            if ($t->kind==T_EXTENDS) {
                $t = $t->nextNS();
                if ($t->kind!=T_STRING) {
                    error_log("Unexpected token $t->text at " . $pf->getFileName() . ":$t->lineNumber");
                }

                $ext_name = $t->text;   // extended (base) class name
                $c->extends[] = $ext_name;
                $ext = $this->getOrCreate($ext_name);
                $ext->inheritedBy[] = $name;

                $filePos = new XRef_FilePosition($pf, $t);
                $ext->usedAt[] = $filePos;

                // link from source file HTML page to report page "reportId/objectId"
                $this->xref->addSourceFileLink($filePos, $this->reportId, $ext_name);
                $t = $t->nextNS();
            } // if T_EXTENDS

            if ($t->kind==T_IMPLEMENTS) {
                while (true) {
                    $t = $t->nextNS();
                    if ($t->kind!=T_STRING) {
                        error_log("Unexpected token $t->text at " . $pf->getFileName() . ":$t->lineNumber");
                    }

                    $int_name = $t->text;   // interface class name
                    $c->implements[] = $int_name;
                    $int = $this->getOrCreate($int_name);
                    $int->inheritedBy[] = $name;

                    $filePos = new XRef_FilePosition($pf, $t);
                    $int->usedAt[] = $filePos;

                    // link from source file HTML page to report page "reportId/objectId"
                    $this->xref->addSourceFileLink($filePos, $this->reportId, $int_name);
                    $t = $t->nextNS();
                    if ($t->kind==XRef::T_ONE_CHAR && $t->text==",") {
                        // ok, continue
                    } else {
                        break;
                    }
                }
            } // if T_IMPLEMENTS
        } // foreach declared class

        // fill declared methods
        $pf_methods = $pf->getMethods();
        foreach ($pf_methods as $pfm) {
            $name = $pfm->name;
            $className = $pf->getClassAt( $pfm->startIndex );
            if (!$className) {
                // not a class method, regular function
                continue;
            }
            $class = $this->getOrCreate($className);
            // TODO: case-insensitive method names for PHP
            // TODO: multiple-declared classes
            $class->declaredMethods[$name] = (object) array("name" => $name);
        }

        // collect all places where this class is instantiated or mentioned:
        $tokens = $pf->getTokens();
        for ($i=0; $i<count($tokens); ++$i) {
            $t = $tokens[$i];

            // "new" <name>
            // "new" $className
            // "new" "(" <callable-name> ")"  // AS3
            // "new" "<" <int> ">"            // AS3
            if ($t->kind==T_NEW) {
                $t = $t->nextNS();

                // AS3 specific
                // TODO: these are special class names, don't put them in common pile
                if ($t->kind==XRef::T_ONE_CHAR
                        && $pf->getFileType()==XRef::FILETYPE_AS3
                        && ($t->text=="<" || $t->text=="("))
                {
                    $t = $t->nextNS();
                }

                if ($t->kind!=T_STRING && $t->kind!=T_VARIABLE) {
                    error_log("Unexpected token $t->text at " . $pf->getFileName() . ":$t->lineNumber");
                }

                $name = $t->text;
                $new = $this->getOrCreate($name);

                $filePos = new XRef_FilePosition($pf, $t);
                $new->instantiatedAt[] = $filePos;

                // link from source file HTML page to report page "reportId/objectId"
                $this->xref->addSourceFileLink($filePos, $this->reportId, $name);
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
                        if ($nn->kind!=XRef::T_ONE_CHAR || $nn->text!='(') {
                            continue;
                        }
                    } else {
                        error_log("What's this: $n->text at " . $pf->getFileName() . ":$t->lineNumber");
                        continue;
                    }

                    $className = $p->text;
                    if ($pf->getFileType()==XRef::FILETYPE_PHP) {
                        if ($className=='self') {
                            $className = $pf->getClassAt($p->index);
                            if (!$className) {
                                error_log("Reference to self:: class not inside a class method at " . $pf->getFileName() . ":$t->lineNumber");
                                continue;
                            }
                        }
                        // TODO: super:: class resolution needs 2-pass parser
                    }

                    $c = $this->getOrCreate($className);
                    $filePos = new XRef_FilePosition($pf, $p);
                    $c->usedAt[] = $filePos;
                    $this->xref->addSourceFileLink($filePos, $this->reportId, $p->text);
                } else {
                    error_log("Unexpected token $t->text at " . $pf->getFileName() . ":$t->lineNumber");
                }
            }

            // $foo isinstanceof Bar
            if ($t->kind==T_INSTANCEOF) {
                $n = $t->nextNS();
                if ($n->kind==T_STRING || $n->kind==T_VARIABLE) {
                    $className = $n->text;
                    $c = $this->getOrCreate($className);
                    $filePos = new XRef_FilePosition($pf, $n);
                    $c->usedAt[] = $filePos;
                    $this->xref->addSourceFileLink($filePos, $this->reportId, $className);
                } else {
                    error_log("Invalid argument for isinstanceof at " . $pf->getFileName() . ":$n->lineNumber");
                }
            }

            // is_a($foo, "Bar")
            if ($t->kind==T_STRING && $t->text=="is_a") {
                $n = $t->nextNS();
                if ($n->kind!=XRef::T_ONE_CHAR || $n->text!='(') {
                    error_log("Invalid is_a symbol at " . $pf->getFileName() . ":$n->lineNumber");
                    continue;
                }
                // TODO: add more checks below
                $n  = $n->nextNS(); // var name. Actually, should skip any expression, e.g.: is_a($user->world, "World")
                $n  = $n->nextNS(); // comma
                $n  = $n->nextNS(); // class name literal?
                $className = preg_replace("#[\'\"]#", '', $n->text);

                $c = $this->getOrCreate($className);
                $filePos = new XRef_FilePosition($pf, $n);
                $c->usedAt[] = $filePos;
                $this->xref->addSourceFileLink($filePos, $this->reportId, $className);
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
