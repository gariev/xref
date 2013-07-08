<?php

interface XRef_IFileProvider {
    public function getFiles();
    public function getFileContent($filename);
}

class XRef_FileProvider_Git implements XRef_IFileProvider {
    /** @var XRef_ISourceCodeManager */
    private $sourceCodeManager = null;
    /** @var string */
    private $revision = null;

    public function __construct(XRef_ISourceCodeManager $sourceCodeManager, $revision) {
        $this->sourceCodeManager = $sourceCodeManager;
        $this->revision = $revision;
    }

    public function getFiles() {
        $files = array();
        foreach ($this->sourceCodeManager->getListOfFiles($this->revision) as $filename) {
            $ext = strtolower( pathinfo($filename, PATHINFO_EXTENSION) );
            // TODO: this is hardcoded by now
            if ($ext == 'php') {
                $files[] = $filename;
            }
        }
        return $files;
    }

    public function getFileContent($filename) {
        return $this->sourceCodeManager->getFileContent($this->revision, $filename);
    }
}

class XRef_FileProvider_FileSystem implements XRef_IFileProvider {
    protected $paths        = array();
    protected $seenFiles    = array();
    protected $files        = null;     // array ($filename => $file_extension)

    public function __construct($paths, $excludePaths = null) {
        $this->paths = (is_array($paths)) ? $paths : array($paths);
        if ($excludePaths) {
            if (!is_array($excludePaths)) {
                $excludePaths = array($excludePaths);
            }
            foreach ($excludePaths as $path) {
                // remove starting "./" and trailing "/" if any
                $path = preg_replace('#^\\.[/\\\\]+#', '', $path);
                $path = preg_replace('#[/\\\\]+$#', '', $path);
                $this->seenFiles[$path] = true;
            }
        }
    }


   /**
     * Method traverses given root paths and return found filenames.
     *
     * @return string[]
     */
    public function getFiles() {
        if (!$this->files) {
            $this->files = array();
            foreach ($this->paths as $p) {
                $this->findInputFiles($p);
            }
        }

        return $this->files;
    }

    public function getFileContent($filename) {
        return file_get_contents($filename);
    }

    /**
     * Internal method to traverse source files from given root; called recursively.
     * No return value, $this->inputFiles is filled with found filenames.
     *
     * @param string $file - file or directory name
     */
    protected function findInputFiles($file) {
        // prevent visiting the same dir several times
        if (isset($this->seenFiles[$file])) {
            return;
        } else {
            $this->seenFiles[$file] = 1;
        }

        if (is_dir($file)) {
            foreach (scandir($file) as $filename) {
                // skip svn/git directories
                // TODO: create ignore config list
                if ($filename == '.svn' || $filename == '.git' || $filename == '.build.sand.mk') {
                    continue;
                }
                if ($filename == '.' || $filename == '..') {
                    // skip "." and ".." dirs
                    continue;
                }
                $this->findInputFiles( ($file == '.') ? $filename : "$file/$filename");
            }
        } else if (is_file($file)) {
            $ext = strtolower( pathinfo($file, PATHINFO_EXTENSION) );
            // TODO: this is hardcoded by now
            if ($ext == 'php') {
                $this->files[] = $file;
            }
        }
    }
}

