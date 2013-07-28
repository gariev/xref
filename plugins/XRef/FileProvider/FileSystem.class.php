<?php

class XRef_FileProvider_FileSystem implements XRef_IFileProvider {
    protected $paths        = array();
    protected $seenFiles    = array();
    protected $extensions   = null;
    protected $files        = null;     // array ($filename => $file_extension)
    protected $rootDir      = null;
    protected $roodDirStrlen= 0;

    public function __construct($paths) {
        $paths = (is_array($paths)) ? $paths : array($paths);
        foreach ($paths as $path) {
            $p = realpath($path);
            if ($p) {
                $this->paths[] = realpath($p);
            } else {
                error_log("Path '$path' doesn't exist");
            }
        }
        if (count($this->paths) > 0 && is_dir($this->paths[0])) {
            $this->rootDir = $this->paths[0];
            $this->roodDirStrlen = strlen($this->rootDir);
        }
    }

    public function excludePaths(array $paths) {
        foreach ($paths as $path) {
            if ($this->rootDir && file_exists("$this->rootDir/$path")) {
                $p = realpath("$this->rootDir/$path");
            } else {
                $p = realpath($path);
            }

            if ($p) {
                $this->seenFiles[$p] = true;
            } else {
                error_log("Path '$path' doesn't exist");
            }
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
        if (file_exists("$this->rootDir/$filename")) {
            $filename = "$this->rootDir/$filename";
        }
        return file_get_contents($filename);
    }

    /**
     * Internal method to traverse source files from given root; called recursively.
     * No return value, $this->inputFiles is filled with found filenames.
     *
     * @param string $file - file or directory name
     */
    protected function findInputFiles($f) {
        $file = realpath($f);
        if (!$file) {
            error_log("File '$f' doesn't exist");
        }

        // prevent visiting the same dir several times
        if (isset($this->seenFiles[$file])) {
            return;
        } else {
            $this->seenFiles[$file] = 1;
        }

        if (is_dir($file)) {
            $dir_files = scandir($file);
            if ($dir_files === false) {
                error_log("Can't read dir '$file'");
            } else {
                foreach ($dir_files as $filename) {
                    // skip svn/git directories
                    // TODO: create ignore config list
                    if ($filename == '.svn' || $filename == '.git' || $filename == '.build.sand.mk') {
                        continue;
                    }
                    if ($filename == '.' || $filename == '..') {
                        // skip "." and ".." dirs
                        continue;
                    }
                    $this->findInputFiles("$file/$filename");
                }
            }
        } else if (is_file($file)) {
            $add_file = false;
            if ($this->extensions) {
                $ext = strtolower( pathinfo($file, PATHINFO_EXTENSION) );
                if (isset($this->extensions[$ext])) {
                    $add_file = true;
                }
            } else {
                $add_file = true;
            }

            if ($add_file) {
                if ($this->rootDir
                    && strncmp($this->rootDir, $file, $this->roodDirStrlen) == 0
                    && $file[$this->roodDirStrlen] == DIRECTORY_SEPARATOR)
                {
                    $file = substr($file, $this->roodDirStrlen+1);
                }
                // windows hack: replace "\" by "/" so that
                // paths are the same in FileSystem and Git providers
                // TODO: test this on Windows machine
                $this->files[] = str_replace('\\', '/', $file);
            }
        }
    }

    public function getVersion() {
        return "filesystem";
    }
}

