<?php
/**
 * Documentation plugin that creates a list of all found lint errors/warnings.
 *
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

class XRef_Doc_LintReport extends XRef_APlugin implements XRef_IDocumentationPlugin {
    protected $supportedFileType    = XRef::FILETYPE_PHP;

    /** @var XRef_ILintEngine */
    protected $lintEngine;

    public function __construct() {
        parent::__construct("php-lint", "Lint report");
    }

    public function setXRef(XRef $xref) {
        parent::setXRef($xref);
        $this->lintEngine = (true)
            ? new XRef_LintEngine_ProjectCheck($xref)
            : new XRef_LintEngine_Simple($xref);
    }

    public function generateFileReport(XRef_IParsedFile $pf) {
        if ($pf->getFileType() != $this->supportedFileType) {
            return;
        }
        $this->lintEngine->addParsedFile($pf);
    }

    public function generateTotalReport() {
        $report = $this->lintEngine->collectReport();

        list($fh, $root) = $this->xref->getOutputFileHandle($this->reportId, null);
        fwrite($fh,
            $this->xref->fillTemplate(
                'doc-lint-report.tmpl',
                array(
                    'reportName' => $this->getName(),
                    'reportId'   => $this->getId(),
                    'root'       => $root,
                    'report'     => $report,
                )
            )
        );
        fclose($fh);
    }

    public function getReportLink() {
        $links = parent::getReportLink();
        return array_merge($links, array("Online lint tool" => "bin/xref-lint.php"));
    }
}

// vim: tabstop=4 expandtab
