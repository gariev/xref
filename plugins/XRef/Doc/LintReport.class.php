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

    public function __construct() {
        parent::__construct("php-lint", "Lint report");
    }

    // array: $filename --> array($filepos, defectLevel, $message)
    protected $totalReport = array();

    public function generateFileReport(XRef_IParsedFile $pf) {
        if ($pf->getFileType() != $this->supportedFileType) {
            return;
        }

        $report = $this->xref->getLintReport($pf);
        if (count($report)) {
            $this->totalReport[ $pf->getFileName() ] = $report;
        }
    }

    public function generateTotalReport() {
        ksort($this->totalReport);

        list($fh, $root) = $this->xref->getOutputFileHandle($this->reportId, null);
        fwrite($fh,
            $this->xref->fillTemplate(
                'doc-lint-report.tmpl',
                array(
                    'reportName' => $this->getName(),
                    'reportId'   => $this->getId(),
                    'root'       => $root,
                    'report'     => $this->totalReport,
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
