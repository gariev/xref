<?php

class XRef_FileProvider_Git implements XRef_IFileProvider {
    /** @var XRef_ISourceCodeManager */
    private $sourceCodeManager = null;
    /** @var string */
    private $revision = null;
    /** @var array - list of directories and files to exclude from output */
    private $excludePaths = array();

    public function __construct(XRef_ISourceCodeManager $sourceCodeManager, $revision) {
        $this->sourceCodeManager = $sourceCodeManager;
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

    public function getFiles(array $filter_by_extensions = array('php')) {
        $files = array();

        $extensions = null;
        if ($filter_by_extensions) {
            $extensions = array_fill_keys($filter_by_extensions, true);
        }

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

            // filter by extensions
            if ($extensions) {
                $ext = strtolower( pathinfo($filename, PATHINFO_EXTENSION) );
                if (! isset($extensions[$ext])) {
                    continue;
                }
            }

            $files[] = $filename;
        }
        return $files;
    }

    public function getFileContent($filename) {
        return $this->sourceCodeManager->getFileContent($this->revision, $filename);
    }

    public function getVersion() {
        return "git:" . $this->revision;
    }
}

