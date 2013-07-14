<?php

class XRef_FileProvider_FileSystem implements XRef_IFileProvider {
    protected $paths        = array();
    protected $seenFiles    = array();
    protected $extensions   = null;
    protected $files        = null;     // array ($filename => $file_extension)

    public function __construct($paths) {
        $this->paths = (is_array($paths)) ? $paths : array($paths);
    }

    public function excludePaths(array $paths) {
        foreach ($paths as $path) {
            // remove starting "./" and trailing "/" if any
            $path = preg_replace('#^\\.[/\\\\]+#', '', $path);
            $path = preg_replace('#[/\\\\]+$#', '', $path);
            $this->seenFiles[$path] = true;
        }
    }

   /**
     * Method traverses given root paths and return found filenames.
     *
     * @return string[]
     */
    public function getFiles(array $filter_by_extensions = array('php')) {
        $this->files = array();

        if ($filter_by_extensions) {
            $this->extensions = array_fill_keys($filter_by_extensions, true);
        }

        foreach ($this->paths as $p) {
            $this->findInputFiles($p);
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
            if ($this->extensions) {
                $ext = strtolower( pathinfo($file, PATHINFO_EXTENSION) );
                if (isset($this->extensions[$ext])) {
                    $this->files[] = $file;
                }
            } else {
                $this->files[] = $file;
            }
        }
    }
}

