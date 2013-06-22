<?php
/**
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */


class XRef_Plugin_Properties_Property {
    public $name;
    public $declaredAt      = array();  // public $bar;
    public $usedAt          = array();  // XRef_FilePosition -- $foo->bar
}

class XRef_Doc_PropertiesPHP extends XRef_APlugin implements XRef_IDocumentationPlugin {
    protected $supportedFileType    = XRef::FILETYPE_PHP;

    public function __construct() {
        parent::__construct("php-properties", "List of properties of PHP classes");
    }

    // map: property name --> XRef_Plugin_Properties_Property object
    protected $properties = array();

    protected function getOrCreate($propertyName) {
        if (!array_key_exists($propertyName, $this->properties)) {
            $this->properties[$propertyName] = new XRef_Plugin_Properties_Property();
            $this->properties[$propertyName]->name = $propertyName;
        }
        return $this->properties[$propertyName];
    }

    public function generateFileReport(XRef_IParsedFile $pf) {
        if ($pf->getFileType() != $this->supportedFileType) {
            return;
        }

        $tokens = $pf->getTokens();

        // Property declared:
        // (public|protected|private)  $foo;
        for ($i=0; $i<count($tokens); ++$i) {
            $t = $tokens[$i];

            if ($t->kind==T_PUBLIC || $t->kind==T_PROTECTED || $t->kind==T_PRIVATE) {
                $n = $t->nextNS();
                if ($n->kind==T_VARIABLE) {
                    $name = $n->text;
                    if (substr($name, 0,1)=='$') {
                        $name = substr($name, 1);
                    } else {
                        error_log("Strange property name: $name");
                    }
                    $p = $this->getOrCreate($name);
                    $filePos = new XRef_FilePosition($pf, $n->index);
                    $p->declaredAt[] = $filePos;
                    $this->xref->addSourceFileLink($filePos, $this->reportId, $name);
                }
            }
        }

        // property used:
        // $foo->bar->baz
        for ($i=0; $i<count($tokens); ++$i) {
            $t = $tokens[$i];

            if ($t->kind==T_OBJECT_OPERATOR) {
                $t = $t->nextNS();
                $n = $t->nextNS();
                if ($n->kind==XRef::T_ONE_CHAR && $n->text=='(') {
                    // method call: $foo->bar()
                    continue;
                }

                $name = $t->text;
                $p = $this->getOrCreate($name);

                $filePos = new XRef_FilePosition($pf, $t->index);
                $p->usedAt[] = $filePos;
                // link from source file HTML page to report page "reportId/objectId"
                $this->xref->addSourceFileLink($filePos, $this->reportId, $name);
                continue;
            }
        }
    }

    public function generateTotalReport() {

        $names = array_keys($this->properties);
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
                    'objects'    => $this->properties,
                )
            )
        );
        fclose($fh);


        // page for each property
        foreach ($names as $name) {
            $p = $this->properties[$name];
            list($fh, $root) = $this->xref->getOutputFileHandle($this->reportId, $name);
            fwrite($fh,
                $this->xref->fillTemplate(
                    'doc-property-report.tmpl',
                    array(
                        'reportName'    => $this->getName(),
                        'reportId'      => $this->getId(),
                        'root'          => $root,
                        'p'             => $p,
                        'title'         => 'Property'
                    )
                )
            );
            fclose($fh);
        }
    }
}


// vim: tabstop=4 expandtab
