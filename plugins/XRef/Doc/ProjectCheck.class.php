<?php
/**
 * Documentation plugin that reports about found integrity problems of the project
 *
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */
include_once dirname(__FILE__) . "/../../../lib/experimental.php";

class XRef_Doc_ProjectCheck extends XRef_APlugin implements XRef_IDocumentationPlugin {
    protected $supportedFileType    = XRef::FILETYPE_PHP;

    protected $project_lint;
    public function __construct() {
        parent::__construct("php-project-check", "Project integrity check");
        $this->project_lint = new ProjectLintPrototype();
    }

    public function setXRef(XRef $xref) {
        parent::setXRef($xref);
        $this->project_lint->setXRef($xref);
    }

    public function generateFileReport(XRef_IParsedFile $pf) {
        if ($pf->getFileType() != $this->supportedFileType) {
            return;
        }
        $this->project_lint->addFile($pf);
    }

    public function generateTotalReport() {
        list($fh, $root) = $this->xref->getOutputFileHandle($this->reportId, null);
        fwrite($fh,
            $this->xref->fillTemplate(
                'doc-lint-report.tmpl',
                array(
                    'reportName' => $this->getName(),
                    'reportId'   => $this->getId(),
                    'root'       => $root,
                    'report'     => $this->project_lint->getErrors(),
                )
            )
        );
        fclose($fh);
    }
}

// vim: tabstop=4 expandtab
