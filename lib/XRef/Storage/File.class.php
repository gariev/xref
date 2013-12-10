<?php
class XRef_Storage_File implements XRef_IPersistentStorage {
    private $dbDir;
    private $locks = array(); // key name -> file handle

    public function __construct() {
        $this->dbDir = XRef::getConfigValue("xref.data-dir");
        XRef::createDirIfNotExist($this->dbDir);
    }

    public function setXRef(XRef $xref) { }

    public function saveData($domain, $key, $data) {
        $filename = $this->getFilenameForKey($domain, $key, true);
        $fh = fopen($filename, "w");
        if (!$fh) {
            throw new Exception("Can't write to file $filename");
        }
        // output of gzencode() takes more space than gzcompress()
        // but is compatible with zgrep, zcat and other z* command-line tools
        fwrite($fh, gzencode(serialize($data)));
        fclose($fh);
    }

    public function restoreData($domain, $key) {
        $filename = $this->getFilenameForKey($domain, $key);
        if (strlen($key) >= 16) {
            $firstLetter = substr($key, 0, 1);
            $filename = "$this->dbDir/$domain/$firstLetter/$key";
        } else {
            $filename = "$this->dbDir/$domain/$key";
        }

        if (!file_exists($filename)) {
            return null;
        }
        $fh = fopen($filename, "r");
        if (!$fh) {
            throw new Exception("Can't open file $filename");
        }
        $data = fread($fh, filesize($filename));
        fclose($fh);

        if ($data === false) {
            throw new Exception("Can't read from file $filename");
        }

        if (substr($data, 0, 2) == "\x1f\x8b") {
            // gz compressed data
            $data = (function_exists('gzdecode')) // since php 5.4
                ? gzdecode($data)
                : gzinflate(substr($data, 10, -8));
            // gzinflate(substr()) works with php gzencoded data,
            // but not with gzip-compressed files with full gz header
            if ($data === false) {
                throw new Exception("Can't gunzip compressed data in file $filename");
            }
        }

        $data = unserialize($data);
        return $data;
    }

    public function getLock($key) {
        $lockFile = $this->getFilenameForKey('lock', $key, true);
        $fhLock = fopen($lockFile, "w");
        if (!$fhLock) {
            throw new Exception("Can't open file $lockFile");
        }

        if (flock($fhLock, LOCK_EX | LOCK_NB)) {
            $this->locks[$key] = $fhLock;
            return true;
        } else {
            fclose($fhLock);
            return false;
        }
    }

    public function releaseLock($key) {
        if (isset($this->locks[$key])) {
            fclose($this->locks[$key]);
            unset($this->locks[$key]);
        }
    }

    private function getFilenameForKey($domain, $key, $create_sub_dirs = false) {
        if ($create_sub_dirs) {
            XRef::createDirIfNotExist("$this->dbDir/$domain");
        }
        $key = urlencode($key);

        // this is a trick - if key is long like md5/sha sum,
        // assume that there will be many objects in this domain and
        // use nested directory structure
        if (strlen($key) >= 16) {
            $firstLetter = substr($key, 0, 1);
            if ($create_sub_dirs) {
                XRef::createDirIfNotExist("$this->dbDir/$domain/$firstLetter");
            }
            $filename = "$this->dbDir/$domain/$firstLetter/$key";
        } else {
            $filename = "$this->dbDir/$domain/$key";
        }

        return $filename;
    }
}

// vim: tabstop=4 expandtab
