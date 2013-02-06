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
        XRef::createDirIfNotExist("$this->dbDir/$domain");
        // this is a trick - if key is long like md5/sha sum,
        // assume that there will be many objects in this domain and
        // use nested directory structure
        if (strlen($key) >= 16) {
            $firstLetter = substr($key, 0, 1);
            XRef::createDirIfNotExist("$this->dbDir/$domain/$firstLetter");
            $filename = "$this->dbDir/$domain/$firstLetter/$key";
        } else {
            $filename = "$this->dbDir/$domain/$key";
        }

        $fh = fopen($filename, "w");
        if (!$fh) {
            throw new Exception("Can't write to file $filename");
        }

        fwrite($fh, serialize($data));
        fclose($fh);
    }

    public function restoreData($domain, $key) {
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
            throw new Exception("Can't read from file $filename");
        }
        $data = unserialize( fread($fh, filesize($filename)) );
        fclose($fh);
        return $data;
    }

    public function getLock($key) {
        XRef::createDirIfNotExist("$this->dbDir/locks");
        $lockFile = "$this->dbDir/locks/$key";
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

}

// vim: tabstop=4 expandtab
