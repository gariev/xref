<?php

$includeDir = ("@php_dir@" == "@"."php_dir@") ? dirname(__FILE__) . "/.." : "@php_dir@/XRef";
require_once "$includeDir/XRef.class.php";

class GitTest extends PHPUnit_Framework_TestCase {
    protected $xref;

    public function __construct() {
        // don't read config file, if any
        XRef::setConfigFileName("default");
        XRef::setConfigValue("git.repository-dir", ".");
        $this->xref = new XRef();
    }
    public function setUp() {
        $has_git = false;
        if (file_exists(".git")) {
            exec("git status 2>&1 ", $ouptut, $retval);
            if ($retval == 0) {
                $has_git = true;
            }
        }

        if (!$has_git) {
            $this->markTestSkipped("No git found");
        }
    }

    public function testGitSCM() {
        // ok, we have a scm,
        // TODO: check that this is git scm
        $scm = $this->xref->getSourceCodeManager();
        $this->assertTrue(!is_null($scm));

        // compare 2 revisions
        $list_of_modified_files = $scm->getListOfModifiedFiles('e1e1f0e768e', '334e56e89c7');
        $this->assertTrue( count($list_of_modified_files) == 1 );
        $this->assertTrue( $list_of_modified_files[0] == 'XRef.class.php' );

        // compare another revisions, make sure the file names are returned with "/" separator
        $list_of_modified_files = $scm->getListOfModifiedFiles('6a6e49b3465d2a6bfb53d857bfde13bab33017a1', 'fca1c530ddff15f53680958a5defef034881d614');
        $this->assertTrue( count($list_of_modified_files) == 2 );
        sort($list_of_modified_files);
        $this->assertTrue( $list_of_modified_files[0] == 'lib/ci-tools.php' );
        $this->assertTrue( $list_of_modified_files[1] == 'lib/utils.php' );

        // info of a commit
        $info = $scm->getRevisionInfo("e1e1f0e768e");
        $this->assertTrue( $info["an"] == 'gariev' );
        $this->assertTrue( $info["ae"] == 'gariev@hotmail.com' );
        $this->assertTrue( $info["H"] == 'e1e1f0e768e5903779869d12ed0fea0db73a5b76' );

        // branches
        $branches = $scm->getListOfBranches();
        $this->assertTrue( count($branches) >= 2 );
        $this->assertTrue( isset($branches["origin/master"]) );
        $this->assertTrue( strlen($branches["origin/master"]) == 40 );
        $this->assertTrue( isset($branches["origin/tests-git"]) );
    }

    public function testGitFileProvider() {
        $scm = $this->xref->getSourceCodeManager();

        // file provider automatically converts short revisions into long
        $file_provider = $scm->getFileProvider("377d2edc1d");
        $this->assertTrue( ! is_null($file_provider) );
        $this->assertTrue( $file_provider->getPersistentId() ==  '377d2edc1da549a80b5b44286e7dcaf59cee300a');

        // file provider returns correct list of files
        $list_of_files = $file_provider->getFiles();
        $this->assertTrue( count($list_of_files) == 1 );
        $this->assertTrue( $list_of_files[0] == 'README.md');

        // and content of a file
        $file_content = $file_provider->getFileContent("README.md");
        $this->assertTrue( $file_content == "Don't checkout this branch - it contains only data for some unit tests." );
    }
}



