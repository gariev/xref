<?php

$includeDir = ("@php_dir@" == "@"."php_dir@") ? dirname(__FILE__) . "/.." : "@php_dir@/XRef";
require_once "$includeDir/XRef.class.php";

class StorageTest extends PHPUnit_Framework_TestCase {
    protected $xref;

    public function __construct() {
        // don't read config file, if any
        XRef::setConfigFileName("default");
        XRef::setConfigValue("xref.data-dir", "tmp");
        $this->xref = new XRef();
    }

    public function testStorage() {
        $storage_manager = $this->xref->getStorageManager();

        // save data
        $storage_manager->saveData("test", "1", array("some data", 42));
        $storage_manager->saveData("test", "2", str_repeat('X', 100*1024));

        // read data
        $data = $storage_manager->restoreData("test", "1");
        $this->assertTrue( ! is_null($data) );
        $this->assertTrue( is_array($data) );
        $this->assertTrue( $data[0] == "some data" );
        $this->assertTrue( $data[1] == 42 );

        $data = $storage_manager->restoreData("test", "2");
        $this->assertTrue( ! is_null($data) );
        $this->assertTrue( is_string($data) );
        $this->assertTrue( strlen($data) == 100*1024 );
        $this->assertTrue( preg_match('#^X+$#', $data) == 1 );

        // check that request for missing data returns null
        $data = $storage_manager->restoreData("test", "3");
        $this->assertTrue( is_null($data) );
    }
}



