<?php
/**
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */


class XRef_Doc_Services_Service {
    public $name;
    public $phpMethodName;
    public $calledFrom = array();   // XRef_FilePosition
}

class XRef_Doc_Services extends XRef_APlugin implements XRef_IDocumentationPlugin {

    public function __construct() {
        parent::__construct("services", "List of services (ActionScript --> PHP)");
    }

    protected $services = array();

    protected function getOrCreate($name, $php_method_name) {
        if (!array_key_exists($name, $this->services)) {
            $this->services[$name] = new XRef_Doc_Services_Service();
            $this->services[$name]->name = $name;
            $this->services[$name]->phpMethodName = strtolower($php_method_name);
        }
        return $this->services[$name];
    }

    public function generateFileReport(XRef_IParsedFile $pf) {
        if ($pf->getFileType()!=XRef::FILETYPE_AS3) {
            return;
        }

        $tokens = $pf->getTokens();
        for ($i=0; $i<count($tokens); ++$i) {
            $t = $tokens[$i];

            // signedCall("UserService.updateEnergy");
            // signedWorldAction('placeFromStorage', m_params);
            if ($t->kind==T_STRING
                    && ($t->text=="signedCall" || $t->text=="signedWorldAction"))
            {
                $p = $t->prevNS();
                if ($p->kind == T_FUNCTION) {
                    // skip function definition, like "function signedCall(..."
                    continue;
                }
                $n = $t->nextNS();
                if ($n!=null && $n->kind==XRef::T_ONE_CHAR && $n->text=="(") {
                    $nn = $n->nextNS();
                    if ($nn->kind!=T_CONSTANT_ENCAPSED_STRING) {
                        error_log("Unexpected token $nn->text at " . $pf->getFileName() . ":$nn->lineNumber");
                    } else {
                        $name = preg_replace("#[\"']#", "", $nn->text);
                        if ($t->text=="signedCall") {
                            $parts = explode('.', $name, 2);
                            $phpName = $parts[1];
                        } else {
                            $phpName = "on$name";
                        }
                        $s = $this->getOrCreate($name, $phpName);
                        $calledFrom = new XRef_FilePosition($pf, $nn);
                        $s->calledFrom[] = $calledFrom;
                        $this->xref->addSourceFileLink($calledFrom, $this->reportId, $name);
                    }
                }
            }
        }
    }

    public function generateTotalReport() {
        $names = array_keys($this->services);
        sort($names);
        $count = count($names);

        $phpMethodsPlugin = $this->xref->getPluginById("php-methods");

        // index page
        list ($fh, $root) = $this->xref->getOutputFileHandle($this->reportId, null);
        fwrite($fh,
            $this->xref->fillTemplate(
                'doc-total-report.tmpl',
                array(
                    'reportName' => $this->getName(),
                    'reportId'   => $this->getId(),
                    'root'       => $root,
                    'names'      => $names,
                )
            )
        );
        fclose($fh);

        // page for each method
        foreach ($names as $name) {
            $s = $this->services[$name];

            $phpMethod = null;
            if ($phpMethodsPlugin) {
                if ($s->phpMethodName) {
                    $phpMethod = $phpMethodsPlugin->getMethodByName($s->phpMethodName);
                }
            }

            list($fh, $root) = $this->xref->getOutputFileHandle($this->reportId, $name);
            fwrite($fh,
                $this->xref->fillTemplate(
                    'doc-services-report.tmpl',
                    array(
                        'reportName'    => $this->getName(),
                        'reportId'      => $this->getId(),
                        'title'         => 'Service',
                        'root'          => $root,
                        's'             => $s,
                        'phpMethod'     => $phpMethod,
                    )
                )
            );
            fclose($fh);
        }
    }
}

// vim: tabstop=4 expandtab
