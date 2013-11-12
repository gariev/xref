<?php

/**
 * Simple implementation of XRef_IFileProvider interface that keeps
 * everything in memory - used by unit tests and web-lint only.
 */
class XRef_FileProvider_InMemory implements XRef_IFileProvider {
    /** map: filename => file content */
    protected $files;

    public function __construct($files = array()) {
        $this->files = $files;
    }

    public function getPersistentId() {
        return null;
    }

    /**
     * @param string $filename
     * @return string
     */
    public function getFileContent($filename) {
        return (isset($this->files[$filename])) ? $this->files[$filename] : null;
    }

    /**
     * @param array $filter_by_extensions
     * @return string[] - list of file names
     */
    public function getFiles() {
        return array_keys($this->files);
    }

    /** @param array $paths - list of paths (dir and files) that should be excluded from output */
    public function excludePaths(array $paths) {
        // NOP
    }
}
