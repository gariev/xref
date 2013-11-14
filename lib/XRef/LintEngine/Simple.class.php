<?php

class XRef_LintEngine_Simple implements XRef_ILintEngine {
    /** @var XRef */
    protected $xref;
    /** @var XRef_IPersistentStorage */
    protected $storageManager = null;
    /** @var array - map: (error code => error description) */
    protected $errorMap = array();
    /** @var array - map: (filename => array with XRef_CodeDefect objects) */
    protected $report = array();
    /** @var array - map: (filename => unique id based on file content) */
    protected $filesMap = array();
    /** @var array - map (plugin id => XRef_ILintPlugin object) */
    protected $plugins = array();
    /** @var bool - when true, cache won't be read, only written */
    protected $rewriteCache = false;

    /** @var array - stats: mostly for internal use/debug/verbose mode */
    protected $stats;

    /** @var XRef_ParsedFile - optimization */
    protected $pf;
    protected $pfFileId;
    protected $parseException;

    public function __construct(XRef $xref, $use_cache = true) {
        $this->xref = $xref;

        // do we have a storage manager?
        if ($use_cache) {
            try {
                $this->storageManager = $xref->getStorageManager();
            } catch(Exception $e) {
                // NOP
            }
        }

        // error map (error code => error description)
        // TODO: make it static
        $this->plugins = $xref->getPlugins("XRef_ILintPlugin");
        $this->fillErrorMap($this->plugins);
        $this->stats = array('total_files' => 0, 'parsed_files' => 0, 'cache_hit' => 0);
    }

    /** methods to use when parsed files are already available */
    public function addParsedFile(XRef_IParsedFile $pf) {
        $this->report[ $pf->getFileName() ] = $this->getFileReport($pf);
    }

    /** returns total report
     * @return array - map (filename => XRefCodeDefect[])
     */
    public function collectReport() {
        return $this->xref->sortAndFilterReport( $this->report );
    }

    /**
     * optimized version - will use cache and return report for all files
     *
     * @param XRef_IFileProvider $file_provider
     * @return array - map (file name => XRef_CodeDefect[])
     */
    public function getReport(XRef_IFileProvider $file_provider) {
        $this->loadFilesMap($file_provider);

        $files = $this->xref->filterFiles( $file_provider->getFiles() );
        $this->stats["total_files"] = count($files);

        foreach ($files as $filename) {
            $this->report[$filename] = $this->getFileReportCached($file_provider, $filename);
        }

        $this->saveFilesMap($file_provider);
        $this->releaseParsedFile();
        return $this->collectReport();
    }

    /**
     * even more optimized version:
     *      getIncrementalReport($from, $to, $list_of_files) = getReport($to) - getReport($from),
     * but only the modified files ($list_of_files) will be analyzed/parsed/processed
     *
     * @param XRef_IFileProvider $from
     * @param XRef_IFileProvider $to
     * @param array $list_of_modified_files -  list of files,
     *          that are different between $from and $to
     * @return array - map (file name => XRef_CodeDefect[])
     */
    public function getIncrementalReport(XRef_IFileProvider $from, XRef_IFileProvider $to, $list_of_modified_files) {
        $this->loadFilesMap($from);
        $has_files_map = (boolean) $this->filesMap;

        $files = $this->xref->filterFiles($list_of_modified_files);
        foreach ($files as $filename) {
            $this->report[$filename] = $this->getFileReportCached($from, $filename);
        }
        $old_report = $this->collectReport();
        $this->report = array();

        foreach ($files as $filename) {
            // remove info about this file from filesMap
            // getFileReportCached() will update the filesMap
            unset( $this->filesMap[$filename] );
            $this->report[$filename] = $this->getFileReportCached($to, $filename);
        }
        $new_report = $this->collectReport();

        // save the updated filesMap:
        // filesMap($to) == filesMap($from) + update for modified files
        if ($has_files_map) {
            $this->saveFilesMap($to);
        }
        $this->releaseParsedFile();

        return self::getNewProjectErrors($old_report, $new_report);
    }

    public function getStats() {
        return $this->stats;
    }

    // optimization: load files map (map: filename => file id) from cache, if any
    // if we have the map, then we can get file id without reading the file content
    protected function loadFilesMap(XRef_IFileProvider $file_provider) {
        $this->filesMap = array();
        if ($this->storageManager && !$this->rewriteCache) {
            $id = $file_provider->getPersistentId();
            if ($id) {
                $map = $this->storageManager->restoreData("files-map", $id);
                if ($map) {
                    $this->filesMap = $map;
                }
            }
        }
    }

    protected function saveFilesMap(XRef_IFileProvider $file_provider) {
        if ($this->filesMap && $this->storageManager) {
            $id = $file_provider->getPersistentId();
            if ($id) {
                $this->storageManager->saveData("files-map", $id, $this->filesMap);
            }
        }
    }

    /**
     * returns the list of errors (XRef_CodeDefect objects) for one file.
     * if possible, will use cache (load/save the report).
     * In best case (when both filesMap and cached report are present),
     * there will be no access to content of the file at all.
     */
    protected function getFileReportCached(XRef_IFileProvider $file_provider, $filename) {
        return $this->getVersionedCachedData(
            "file-lint", $file_provider, $filename,
            array($this, 'getReportFromParsedFile'),
            array($this, 'validateLoadedReport')
        );
    }


    // $pf is either XRef_ParsedFile, false if file can't be parsed, or null, if file doesn't exist
    protected function getReportFromParsedFile($pf) {
        if ($pf) {
            return $this->getFileReport($pf);
        } elseif (is_null($pf)) {
            return array();
        } else {
            return array( XRef_CodeDefect::fromParseException($this->parseException) );
        }
    }

    protected function validateLoadedReport($data) {
        return !is_null($data);
    }

    public function setRewriteCache($value) {
        $this->rewriteCache = $value;
    }

    protected function getVersionedCachedData(
        $cache_domain_key, XRef_IFileProvider $file_provider, $filename,
        $callback_get_data, $callback_validate_data)
    {
        // try to load cached data for this file
        // 1. find the id for this file
        if (isset($this->filesMap[$filename])) {
            $file_id = $this->filesMap[$filename];
        } else {
            $file_content = $file_provider->getFileContent($filename);
            if (!$file_content) {
                // file doesn't exist in this revision
                return call_user_func($callback_get_data, null);
            }
            $file_id = sha1($file_content);
            $this->filesMap[$filename] = $file_id;  // update/create filesMap
        }

        // 2. load the data for this file_id & check version
        $data = null;
        if ($this->storageManager && !$this->rewriteCache) {
            $d = $this->storageManager->restoreData($cache_domain_key, $file_id);
            if (isset($d) && isset($d["xrefVersion"]) && $d["xrefVersion"] == XRef::version()) {
                $data = $d["data"];
            }
        }

        if (call_user_func($callback_validate_data, $data)) {
            $this->stats["cache_hit"]++;
        } else {
            // 3. cached report not found, parse the file and run the lint plugins
            if (!isset($file_content)) {
                $file_content = $file_provider->getFileContent($filename);
            }

            $pf = $this->getParsedFile($file_id, $filename, $file_content);
            $data = call_user_func($callback_get_data, $pf);
            // 4. save the report
            if ($this->storageManager) {
                $d = array(
                    "data"          => $data,
                    "filename"      => $filename,
                    "xrefVersion"   => XRef::version(),
                    "uniq"          => $file_provider->getPersistentId(),
                );
                $this->storageManager->saveData($cache_domain_key, $file_id, $d);
            }
        }
        return $data;
    }

    // returns XRef_ParsedFile object or, if file can't be parsed,
    // returns false and sets $this->parseException field
    protected function getParsedFile($file_id, $filename, $file_content) {
        if (!$this->pf || $this->pfFileId != $file_id) {

            // release the old file, if any
            if ($this->pf) {
                $this->pf->release();
                $this->pf = null;
            }

            try {
                $pf = $this->xref->getParsedFile($filename, $file_content);
                $this->pf = $pf;
                $this->pfFileId = $file_id;
                $this->parseException = null;

                $this->stats["parsed_files"]++;
            } catch(Exception $e) {
                $this->pf = false;
                $this->parseException = $e;
            }
        }

        return $this->pf;
    }

    protected function releaseParsedFile() {
        if ($this->pf) {
            $this->pf->release();
            $this->pf = null;
        }
        $this->parseException = null;
    }

    /**
     * Runs all registered lint plugins for the given parsed file.
     *
     * @param XRef_IParsedFile $pf - parsed file object
     * @return XRef_CodeDefect[]
     */
    protected function getFileReport(XRef_IParsedFile $pf) {

        $report = array();
        foreach ($this->plugins as $pluginId => $plugin) {
            $found_defects = $plugin->getReport($pf);
            if ($found_defects) {
                foreach ($found_defects as $d) {
                    list($token, $error_code) = $d;
                    if (! isset($this->errorMap[$error_code])) {
                        error_log("No descriptions for error code '$error_code'");
                        continue;
                    }

                    $description = $this->errorMap[ $error_code ];
                    if (! isset($description["severity"]) || ! isset($description["message"])) {
                        error_log("Invalid description for error code '$error_code'");
                        continue;
                    }

                    $report[] = XRef_CodeDefect::fromToken($token, $error_code, $description["severity"], $description["message"]);
                }
            }
        }
        return $report;
    }

    // functions to manipulate errors diffs (incremenatl error reporting)

    // input:arrays of XRef_CodeDefect objects
    // output: array of XRef_CodeDefect that are new
    protected static function getNewErrors($oldErrors, $currentErrors) {
        // 1. create array of text messages without line number
        $a1 = self::createErrorsDigest($oldErrors);
        $a2 = self::createErrorsDigest($currentErrors);
        // 2. compare arrays using string equality
        $diff = XRef_phpDiff($a1, $a2);
        $newErrors = array();
        foreach ($diff as $d) {
            if ($d[0]==-1) {
                // this line is in $currentErrors/$a2 only
                $newErrors[] = $currentErrors[ $d[1] ];
            }
        }
        return $newErrors;
    }

    // input: array (file name => array of XRef_CodeDefect objects)
    // output: array (file name => array of XRef_CodeDefect objects)
    protected static function getNewProjectErrors($oldErrors, $currentErrors) {
        $result = array();
        foreach ($currentErrors as $filename => $errors_list) {
            if (!isset($oldErrors[$filename])) {
                $result[$filename] = $errors_list;
            } else {
                $diff = self::getNewErrors($oldErrors[$filename], $errors_list);
                if ($diff) {
                    $result[$filename] = $diff;
                }
            }
        }
        return $result;
    }

    // input: array or arrays($lineNumber, $severity, $message, $tokenText, $method)
    // output: array of strings, identifying the error, but without line numbers
    protected static function createErrorsDigest($errorList) {
        $result = array();
        foreach ($errorList as $e) {
            $result[] = "$e->tokenText##$e->errorCode##$e->inMethod";
        }
        return $result;
    }

    protected function fillErrorMap($plugins) {
        foreach ($plugins as $pluginId => /** @var XRef_ILintPlugin $plugin */ $plugin) {
            foreach ($plugin->getErrorMap() as $error_code => $error_description) {
                if (isset($this->errorMap[ $error_code ])) {
                    throw new Exception("Plugin " . $plugin->getId() . " tries to redefine error code '$error_code'");
                }
                $this->errorMap[$error_code] = $error_description;
            }
        }
    }

}

//
// function XRef_phpDiff (PHPDiff) is taken with modifications from http://www.holomind.de/phpnet/diff2.src.php,
// published by GPL licence.
// Copyright (C) 2003  Daniel Unterberger <diff.phpnet@holomind.de>
// Copyright (C) 2005  Nils Knappmeier next version
// Contact: d.u.diff@holomind.de <daniel unterberger>
//
// Input:
//      2 arrays of lines
// Output:
//      combined diff in form of array of pairs (index1, index2) for matched lines,
//      index1 or index2 is -1 if the line is missing in left or right array
//
function XRef_phpDiff($t1,$t2) {
    # build a reverse-index array using the line as key and line number as value
    # don't store blank lines, so they won't be targets of the shortest distance
    # search
    $r1 = array();
    $r2 = array();
    foreach($t1 as $i=>$x) if ($x>'') $r1[$x][]=$i;
    foreach($t2 as $i=>$x) if ($x>'') $r2[$x][]=$i;

    $a1=0; $a2=0;   # start at beginning of each list
    $actions=array();
    $result=array();

    # walk this loop until we reach the end of one of the lists
    while ($a1<count($t1) && $a2<count($t2)) {
        # if we have a common element, save it and go to the next
        if ($t1[$a1]==$t2[$a2]) { $result[]=array($a1, $a2); $a1++; $a2++; continue; }

        # otherwise, find the shortest move (Manhattan-distance) from the
        # current location
        $best1=count($t1); $best2=count($t2);
        $s1=$a1; $s2=$a2;
        while(($s1+$s2-$a1-$a2) < ($best1+$best2-$a1-$a2)) {
            $d=-1;
            foreach((array)@$r1[$t2[$s2]] as $n)
                if ($n>=$s1) { $d=$n; break; }
                if ($d>=$s1 && ($d+$s2-$a1-$a2)<($best1+$best2-$a1-$a2))
                { $best1=$d; $best2=$s2; }
                    $d=-1;
                    foreach((array)@$r2[$t1[$s1]] as $n)
                    if ($n>=$s2) { $d=$n; break; }
                    if ($d>=$s2 && ($s1+$d-$a1-$a2)<($best1+$best2-$a1-$a2))
                    { $best1=$s1; $best2=$d; }
                    $s1++; $s2++;
        }
        while ($a1<$best1) { $result[]=array($a1, -1); $a1++; }  # deleted elements
        while ($a2<$best2) { $result[]=array(-1, $a2); $a2++; }  # added elements
    }

    # we've reached the end of one list, now walk to the end of the other
    while($a1<count($t1)) { $result[]=array($a1, -1); $a1++; }  # deleted elements
    while($a2<count($t2)) { $result[]=array(-1, $a2); $a2++; }  # added elements

    return $result;
}


