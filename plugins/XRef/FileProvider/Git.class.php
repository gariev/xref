<?php

class XRef_FileProvider_Git implements XRef_IFileProvider {
    /** @var XRef_ISourceCodeManager */
    private $sourceCodeManager = null;
    /** @var string - either git canonical 40-char revision id, or one of XRef_SourceCodeManager_Git::* constants */
    private $revision = null;
    /** @var array - list of directories and files to exclude from output */
    private $excludePaths = array();

    public function __construct(XRef_ISourceCodeManager $sourceCodeManager, $revision) {
        $this->sourceCodeManager = $sourceCodeManager;

        // revisions:
        // XRef_SourceCodeManager_Git::HEAD     -> will be resolved into current head's hash
        // XRef_SourceCodeManager_Git::CAHED    -> special case (!)
        // XRef_SourceCodeManager_Git::DISK     -> invalid here
        // (anything else, incl. branch name)   -> either will be resolved into hash or will result in exception
        if ($revision != XRef_SourceCodeManager_Git::CACHED) {
            // get the canonical form of revision - 40-char id
            if (!preg_match('#^([a-f0-9]{40})$#', $revision)) {
                $info = $sourceCodeManager->getRevisionInfo($revision);
                if (! isset($info['H'])) {
                    throw new Exception("Invalid revision: $revision");
                }
                $revision = $info['H'];
            }
        }

        $this->revision = $revision;
    }

    public function excludePaths(array $paths) {
        foreach ($paths as $path) {
            // remove starting "./" and trailing "/" if any
            $path = preg_replace('#^\\.[/\\\\]+#', '', $path);
            $path = preg_replace('#[/\\\\]+$#', '', $path);
            $this->excludePaths[$path] = strlen($path);
        }
    }

    public function getFiles() {
        $files = array();

        foreach ($this->sourceCodeManager->getListOfFiles($this->revision) as $filename) {

            // filter by excludePaths list
            $filename_length = strlen($filename);
            foreach ($this->excludePaths as $path => $path_len) {
                // exact filename match
                if ($filename == $path) {
                    continue;
                }
                // directory match: exclude path="Foo/Bar", file="Foo/Bar/baz.php"
                if ($filename_length > $path_len && substr($filename, 0, $path_len) == $path) {
                    $next = substr($filename, $path_len, 1);
                    if ($next == '/' || $next == '\\') {
                        continue;
                    }
                }
            }

            $files[] = $filename;
        }
        return $files;
    }

    public function getFileContent($filename) {
        return $this->sourceCodeManager->getFileContent($this->revision, $filename);
    }

    public function getPersistentId() {
        return (strlen($this->revision) == 40) ? $this->revision : null;
    }
}

