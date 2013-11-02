<?php

class XRef_FileProvider_FileSystem implements XRef_IFileProvider {
    protected $paths        = array();
    protected $seenFiles    = array();
    protected $files        = null;     // array ($filename => $file_extension)
    protected $rootDir      = null;
    protected $rootDirStrlen= 0;

    public function __construct($paths) {
        $paths = (is_array($paths)) ? $paths : array($paths);
        foreach ($paths as $path) {
            $p = realpath($path);
            if ($p) {
                $this->paths[] = $p;
            } else {
                error_log("Path '$path' doesn't exist");
            }
        }
        if (count($this->paths) > 0 && is_dir($this->paths[0])) {
            $this->rootDir = $this->paths[0];
            $p = preg_replace('#[\\\\/]$#', '', $paths[0]); // remove trailing slash, if any
            // how much of starting path is to trim?
            // $cwd = "/home/igariev"
            //
            // $p = ".",                $rootDir = "/home/igariev",     $realpath="/home/igariev/lib/1.php", $file="lib/1.php"
            // $p = "/home/igariev",    $rootDir = "/home/igariev",     $realpath="/home/igariev/lib/1.php", $file="lib/1.php"
            // $p = "lib",              $rootDir = "/home/igariev/lib", $realpath="/home/igariev/lib/1.php", $file="lib/1.php"
            // $p = "../another",       $rootDir = "/home/another",     $realpath="/home/another/2.php",     $file="/home/another/2.php"
            //
            if ($this->rootDir == $p || $p == '.') {
                $this->rootDirStrlen = strlen($this->rootDir);
            } elseif (!preg_match('#^\\.#', $p)) {
                $pos = strrpos($this->rootDir, $p);
                if ($pos !== false) {
                    $this->rootDirStrlen = $pos - 1;
                }
            }
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
    public function getFiles() {
        $this->files = array();

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
                    if ($filename == '.svn' || $filename == '.git' || $filename == '.xref' || $filename == '.build.sand.mk') {
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
            if ($this->rootDir
                && $this->rootDirStrlen
                && strncmp($this->rootDir, $file, $this->rootDirStrlen) == 0
                && $file[$this->rootDirStrlen] == DIRECTORY_SEPARATOR)
            {
                $file = substr($file, $this->rootDirStrlen+1);
            }
            // windows hack: replace "\" by "/" so that
            // paths are the same in FileSystem and Git providers
            // TODO: test this on Windows machine
            $this->files[] = str_replace('\\', '/', $file);
        }
    }

    public function getPersistentId() {
        return null;
    }
}

