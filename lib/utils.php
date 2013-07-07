<?php
class XRef_FileIterator {
    protected $paths        = array();
    protected $excludePath  = array();
    protected $seenFiles    = array();
    protected $inputFiles   = array(); // ($filename => $file_extension)

    public function __construct($paths = null) {
        if ($paths) {
            $this->paths = (is_array($paths)) ? $paths : array($paths);
        }
    }

    /** path where to look for source files */
    public function addPath($path) {
        if (is_array($path)) {
            $this->paths = array_merge($this->paths, $path);
        } else {
            $this->paths[] = $path;
        }
        $this->inputFiles = null; // invalidate cache
    }

    public function excludePath($path) {
        if (is_array($path)) {
            foreach ($path as $p) {
                $this->excludePath($p);
            }
        } else {
            // remove starting "./" and trailing "/" if any
            $path = preg_replace('#^\\.[/\\\\]+#', '', $path);
            $path = preg_replace('#[/\\\\]+$#', '', $path);
            $this->excludePath[] = $path;
        }
        $this->inputFiles = null; // invalidate cache
    }

   /**
     * Method traverses given root paths and return found filenames.
     *
     * @return string[]
     */
    public function getFiles() {
        if (!$this->inputFiles) {
            $this->inputFiles = array();
            $this->seenFiles = array_fill_keys($this->excludePath, true);
            foreach ($this->paths as $p) {
                $this->findInputFiles($p);
            }
        }

        return $this->inputFiles;
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
                $this->inputFiles[$file] = $ext;
            }
        }
    }
}

