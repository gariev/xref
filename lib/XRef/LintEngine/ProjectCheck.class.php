<?php

class XRef_LintEngine_ProjectCheck extends XRef_LintEngine_Simple {

    /** @var XRef_IPlugin[] */
    protected $projectCheckPlugins = array();

    /** @var array - map (filename => file slice) */
    protected $slices = array();

    protected $showProgressBar = false;
    protected $projectDatabase = null;

    public function __construct(XRef $xref, $use_cache = true) {
        parent::__construct($xref, $use_cache);
        $this->projectCheckPlugins = $xref->getPlugins("XRef_IProjectLintPlugin");
        $this->fillErrorMap( $this->projectCheckPlugins );
        $this->projectDatabase = new XRef_ProjectDatabase();
    }

    protected function getFileSlices(XRef_IParsedFile $pf) {
        $slices = array();
        $slices["_db"] =  $this->projectDatabase->createFileSlice($pf);
        foreach ($this->projectCheckPlugins as $plugin_id => /** @var XRef_IProjectLintPlugin $plugin */ $plugin) {
            $slices[$plugin_id] = $plugin->createFileSlice($pf);
        }
        return $slices;
    }

    /** methods to use when parsed files are already available */
    public function addParsedFile(XRef_IParsedFile $pf) {
        parent::addParsedFile($pf);

        $slices = $this->getFileSlices($pf);
        $this->slices[ $pf->getFileName() ] = $slices;
    }

    public function collectReport() {
        $report = parent::collectReport();

        // fill the database
        foreach ($this->slices as $filename => $slices) {
            if ($slices) {
                $this->projectDatabase->addFileSlice($filename, $slices["_db"]);
            }
        }
        $this->projectDatabase->finalize();

        // run each plugin for each slice
        foreach ($this->projectCheckPlugins as $plugin_id => /** @var $plugin XRef_IProjectLintPlugin */ $plugin) {
            $plugin->startLintCheck( $this->projectDatabase );
            foreach ($this->slices as $filename => $slice) {
                if ($slice) {
                    $plugin->checkFileSlice($this->projectDatabase, $filename, $slice[$plugin_id]);
                }
            }
            $plugin_report = $plugin->getProjectReport( $this->projectDatabase );

            foreach ($plugin_report as $filename => $errors_list) {
                foreach ($errors_list as $e) {
                    $code = $e['code'];
                    if (! isset($this->errorMap[$code])) {
                        error_log("No descriptions for error code '$code'");
                        continue;
                    }

                    $description = $this->errorMap[$code];
                    if (! isset($description["severity"]) || ! isset($description["message"])) {
                        error_log("Invalid description for error code '$code'");
                        continue;
                    }

                    $code_defect = XRef_CodeDefect::fromTokenText(
                        $e['text'], $code, $description['severity'],
                        $description["message"], $e['params']
                    );
                    $code_defect->fileName = $e['location'][0];
                    $code_defect->lineNumber = $e['location'][1];

                    $report[$filename][] = $code_defect;
                }
            }
        }

        return $this->xref->sortAndFilterReport($report);
    }


    /** optimized method - may use caching etc */
    public function getReport(XRef_IFileProvider $file_provider) {
        $this->loadFilesMap($file_provider);

        $files = $this->xref->filterFiles( $file_provider->getFiles() );
        $this->stats["total_files"] = count($files);
        $count = 1;
        foreach ($files as $filename) {
            if ($this->showProgressBar) {
                XRef::progressBar($count, count($files), $filename);
            }
            $this->report[$filename] = $this->getFileReportCached($file_provider, $filename);
            $this->slices[$filename] = $this->getFileSlicesCached($file_provider, $filename);
            $count++;
        }

        $this->releaseParsedFile();
        $this->saveFilesMap($file_provider);
        return $this->collectReport();
    }

    /** optimized method for incremental mode */
    public function getIncrementalReport(XRef_IFileProvider $from, XRef_IFileProvider $to, $list_of_modified_files)
    {
        $this->loadFilesMap($from);

        $files = $this->xref->filterFiles($list_of_modified_files);

        // collect file errors & slices for modified files
        foreach ($files as $filename) {
            $this->report[$filename] = $this->getFileReportCached($from, $filename);
            $this->slices[$filename] = $this->getFileSlicesCached($from, $filename);
        }
        // collect slices for all other files
        $from_files = $this->xref->filterFiles( $from->getFiles() );
        foreach ($from_files as $filename) {
            if (!isset($this->slices[$filename])) {
                $this->slices[$filename] = $this->getFileSlicesCached($from, $filename);
            }
        }
        $old_report = $this->collectReport();

        // update reports, slices and filesmap for modified files
        $this->projectDatabase = new XRef_ProjectDatabase();
        $this->report = array();
        foreach ($files as $filename) {
            unset( $this->filesMap[$filename] );
            $this->report[$filename] = $this->getFileReportCached($to, $filename);
            $this->slices[$filename] = $this->getFileSlicesCached($to, $filename);
        }
        $new_report =  $this->collectReport();

        $this->releaseParsedFile();
        $this->saveFilesMap($to);
        return XRef_LintEngine_Simple::getNewProjectErrors($old_report, $new_report);
    }


    public function setShowProgressBar($value) {
        $this->showProgressBar = $value;
    }

    protected function getFileSlicesCached(XRef_IFileProvider $file_provider, $filename) {
        return $this->getVersionedCachedData(
            "file-slices", $file_provider, $filename,
            array($this, 'getSlicesFromParsedFile'),
            array($this, 'validateLoadedSlices')
        );
    }

    protected function getSlicesFromParsedFile($pf) {
        if ($pf) {
            $slices = $this->getFileSlices($pf);
        } else {
            $slices = array();
        }
        return $slices;
    }

    protected function validateLoadedSlices($data) {
        return !is_null($data);
        // TODO: check that there is a key for each plugin
    }
}
