<?php

$includeDir = ("@php_dir@" == "@"."php_dir@") ? dirname(__FILE__) . "/.." : "@php_dir@/XRef";
require_once "$includeDir/XRef.class.php";

class LintEngineTest extends PHPUnit_Framework_TestCase {

    private $xref;

    public function __construct() {
        $xref = new XRef();
        $xref->loadPluginGroup('lint');
        $this->xref = $xref;
    }

    public function testSimpleEngine() {
        // usage with parsed file
        $lint_engine = new XRef_LintEngine_Simple($this->xref, false);
        $pf = $this->xref->getParsedFile("fileA.php", '<?php echo $foo;');
        $lint_engine->addParsedFile($pf);
        $report = $lint_engine->collectReport();
        $this->assertTrue(count($report) == 1);
        $this->assertTrue(isset($report["fileA.php"]));
        $this->assertTrue(count($report["fileA.php"]) == 1);
        $this->assertTrue($report["fileA.php"][0]->tokenText == '$foo');

        // usage with file provider
        $lint_engine = new XRef_LintEngine_Simple($this->xref, false);
        $file_provider = new XRef_FileProvider_InMemory( array('fileA.php' => '<?php echo $bar;') );
        $report = $lint_engine->getReport($file_provider);
        $this->assertTrue(count($report) == 1);
        $this->assertTrue(isset($report["fileA.php"]));
        $this->assertTrue(count($report["fileA.php"]) == 1);
        $this->assertTrue($report["fileA.php"][0]->tokenText == '$bar');

        // only the php files are checked
        $lint_engine = new XRef_LintEngine_Simple($this->xref, false);
        $file_provider = new XRef_FileProvider_InMemory( array(
            'fileA.php' => '<?php echo $baz;',
            'fileB.txt' => 'some text with $foo',
            'fileC.txt' => '<?php looks like php',
        ));
        $report = $lint_engine->getReport($file_provider);
        $this->assertTrue(count($report) == 1);
        $this->assertTrue(isset($report["fileA.php"]));
        $this->assertTrue(count($report["fileA.php"]) == 1);
        $this->assertTrue($report["fileA.php"][0]->tokenText == '$baz');

        // no project integrity is checked
        $lint_engine = new XRef_LintEngine_Simple($this->xref, false);
        $file_provider = new XRef_FileProvider_InMemory( array(
            'fileA.php' => '<?php class A { const FOO = 1; }',
            'fileB.php' => '<?php echo A::BAR; ',
        ));
        $report = $lint_engine->getReport($file_provider);
        $this->assertTrue(count($report) == 0);

    }

    public function testSimpleEngineIncremental() {
        // old errors are not reported in incremental mode,
        // even if the line numbers are changed
        $lint_engine = new XRef_LintEngine_Simple($this->xref, false);
        $file_provider1 = new XRef_FileProvider_InMemory( array(
            'fileA.php' => '<?php echo $bar;'
        ));
        $file_provider2 = new XRef_FileProvider_InMemory( array(
            'fileA.php' => '<?php

            echo $bar;'
        ));
        $report = $lint_engine->getIncrementalReport($file_provider1, $file_provider2, array("fileA.php"));
        $this->assertTrue(count($report) == 0);

        // new errors in the same file are reported
        $lint_engine = new XRef_LintEngine_Simple($this->xref, false);
        $file_provider1 = new XRef_FileProvider_InMemory( array(
            'fileA.php' => '<?php echo $bar;'
        ));
        $file_provider2 = new XRef_FileProvider_InMemory( array(
            'fileA.php' => '<?php

            echo $bar;  // not reported
            echo $foo;  // new error
            '
        ));
        $report = $lint_engine->getIncrementalReport($file_provider1, $file_provider2, array("fileA.php"));
        $this->assertTrue(count($report) == 1);
        $this->assertTrue(isset($report["fileA.php"]));
        $this->assertTrue(count($report["fileA.php"]) == 1);
        $this->assertTrue($report["fileA.php"][0]->tokenText == '$foo');

        // all errors in new files are reported
        $lint_engine = new XRef_LintEngine_Simple($this->xref, false);
        $file_provider1 = new XRef_FileProvider_InMemory( array(
            'fileA.php' => '<?php echo $bar;'
        ));
        $file_provider2 = new XRef_FileProvider_InMemory( array(
            'fileA.php' => '<?php

            echo $bar;  // not reported
            echo $foo;  // new error
            ',
            'fileB.php' => '<?php echo $qux;',
        ));
        $report = $lint_engine->getIncrementalReport($file_provider1, $file_provider2, array("fileA.php", 'fileB.php'));
        $this->assertTrue(count($report) == 2);
        $this->assertTrue(isset($report["fileA.php"]));
        $this->assertTrue(isset($report["fileB.php"]));
        $this->assertTrue(count($report["fileA.php"]) == 1);
        $this->assertTrue(count($report["fileB.php"]) == 1);
        $this->assertTrue($report["fileA.php"][0]->tokenText == '$foo');
        $this->assertTrue($report["fileB.php"][0]->tokenText == '$qux');
    }

    public function testProjectCheckEngine() {
        // same tests as for Simple engine - they should report the same errors

        // usage with parsed file
        $lint_engine = new XRef_LintEngine_ProjectCheck($this->xref, false);
        $pf = $this->xref->getParsedFile("fileA.php", '<?php echo $foo;');
        $lint_engine->addParsedFile($pf);
        $report = $lint_engine->collectReport();
        $this->assertTrue(count($report) == 1);
        $this->assertTrue(isset($report["fileA.php"]));
        $this->assertTrue(count($report["fileA.php"]) == 1);
        $this->assertTrue($report["fileA.php"][0]->tokenText == '$foo');

        // usage with file provider
        $lint_engine = new XRef_LintEngine_ProjectCheck($this->xref, false);
        $file_provider = new XRef_FileProvider_InMemory( array('fileA.php' => '<?php echo $bar;') );
        $report = $lint_engine->getReport($file_provider);
        $this->assertTrue(count($report) == 1);
        $this->assertTrue(isset($report["fileA.php"]));
        $this->assertTrue(count($report["fileA.php"]) == 1);
        $this->assertTrue($report["fileA.php"][0]->tokenText == '$bar');

        // only the php files are checked
        $lint_engine = new XRef_LintEngine_ProjectCheck($this->xref, false);
        $file_provider = new XRef_FileProvider_InMemory( array(
            'fileA.php' => '<?php echo $baz;',
            'fileB.txt' => 'some text with $foo',
            'fileC.txt' => '<?php looks like php',
        ));
        $report = $lint_engine->getReport($file_provider);
        $this->assertTrue(count($report) == 1);
        $this->assertTrue(isset($report["fileA.php"]));
        $this->assertTrue(count($report["fileA.php"]) == 1);
        $this->assertTrue($report["fileA.php"][0]->tokenText == '$baz');

        // but project integrity is checked now!
        // no project integrity is checked
        $lint_engine = new XRef_LintEngine_ProjectCheck($this->xref, false);
        $file_provider = new XRef_FileProvider_InMemory( array(
            'fileA.php' => '<?php class A { const FOO = 1; }',
            'fileB.php' => '<?php echo A::BAR; ',
        ));
        $report = $lint_engine->getReport($file_provider);
        $this->assertTrue(count($report) == 1);
        $this->assertTrue(isset($report["fileB.php"]));
        $this->assertTrue(count($report["fileB.php"]) == 1);
        $this->assertTrue($report["fileB.php"][0]->tokenText == 'BAR');

    }

    public function testProjectCheckEngineIncremental() {
        // old errors are not reported in incremental mode,
        // even if the line numbers are changed
        $lint_engine = new XRef_LintEngine_ProjectCheck($this->xref, false);
        $file_provider1 = new XRef_FileProvider_InMemory( array(
            'fileA.php' => '<?php echo $bar;'
        ));
        $file_provider2 = new XRef_FileProvider_InMemory( array(
            'fileA.php' => '<?php

            echo $bar;'
        ));
        $report = $lint_engine->getIncrementalReport($file_provider1, $file_provider2, array("fileA.php"));
        $this->assertTrue(count($report) == 0);

        // new errors in the same file are reported
        $lint_engine = new XRef_LintEngine_ProjectCheck($this->xref, false);
        $file_provider1 = new XRef_FileProvider_InMemory( array(
            'fileA.php' => '<?php echo $bar;'
        ));
        $file_provider2 = new XRef_FileProvider_InMemory( array(
            'fileA.php' => '<?php

            echo $bar;  // not reported
            echo $foo;  // new error
            '
        ));
        $report = $lint_engine->getIncrementalReport($file_provider1, $file_provider2, array("fileA.php"));
        $this->assertTrue(count($report) == 1);
        $this->assertTrue(isset($report["fileA.php"]));
        $this->assertTrue(count($report["fileA.php"]) == 1);
        $this->assertTrue($report["fileA.php"][0]->tokenText == '$foo');

        // all errors in new files are reported
        $lint_engine = new XRef_LintEngine_ProjectCheck($this->xref, false);
        $file_provider1 = new XRef_FileProvider_InMemory( array(
            'fileA.php' => '<?php class A {} ',
            'fileB.php' => '<?php class B extends A { public function __construct() {;;} }',
        ));
        $file_provider2 = new XRef_FileProvider_InMemory( array(
           'fileA.php' => '<?php class A { public function __construct() {;;} } ',
           'fileB.php' => '<?php class B extends A { public function __construct() {;;} }',
        ));
        $report = $lint_engine->getIncrementalReport($file_provider1, $file_provider2, array("fileA.php"));
        $this->assertTrue(count($report) == 1);
        $this->assertTrue(isset($report["fileB.php"]));
        $this->assertTrue(count($report["fileB.php"]) == 1);
        $this->assertTrue($report["fileB.php"][0]->tokenText == 'B');

        // and now something interesting - try to edit one file, get an error in another one
        $lint_engine = new XRef_LintEngine_ProjectCheck($this->xref, false);
        $file_provider1 = new XRef_FileProvider_InMemory( array(
            'fileA.php' => '<?php class A { const FOO = 1; } ',
            'fileB.php' => '<?php echo A::FOO; ',
        ));
        $file_provider2 = new XRef_FileProvider_InMemory( array(
            'fileA.php' => '<?php class A { } ',
            'fileB.php' => '<?php echo A::FOO; ',
        ));
        $report = $lint_engine->getIncrementalReport($file_provider1, $file_provider2, array("fileA.php", "fileB.php"));
        $this->assertTrue(count($report) == 1);
        $this->assertTrue(isset($report["fileB.php"]));
        $this->assertTrue(count($report["fileB.php"]) == 1);
        $this->assertTrue($report["fileB.php"][0]->tokenText == 'FOO');

    }

    public function testIncrementalProjectCheckOnDeletedFile() {
        // assert than there are no errors if both files are modified
        $lint_engine = new XRef_LintEngine_ProjectCheck($this->xref, false);
        $file_provider1 = new XRef_FileProvider_InMemory( array(
            'fileA.php' => '<?php class A { const FOO = 1; } ',
            'fileB.php' => '<?php echo A::FOO; ',
        ));
        $file_provider2 = new XRef_FileProvider_InMemory( array(
            'fileA.php' => '<?php class A { } ',
            'fileB.php' => '<?php echo "hi"; ',
        ));
        $report = $lint_engine->getIncrementalReport($file_provider1, $file_provider2, array("fileA.php", "fileB.php"));
        $this->assertTrue(count($report) == 0);

        // and no errors if one file is deleted
        $lint_engine = new XRef_LintEngine_ProjectCheck($this->xref, false);
        $file_provider1 = new XRef_FileProvider_InMemory( array(
            'fileA.php' => '<?php class A { const FOO = 1; } ',
            'fileB.php' => '<?php echo A::FOO; ',
        ));
        $file_provider2 = new XRef_FileProvider_InMemory( array(
            'fileA.php' => '<?php class A { } ',
        ));
        $report = $lint_engine->getIncrementalReport($file_provider1, $file_provider2, array("fileA.php", "fileB.php"));
        $this->assertTrue(count($report) == 0);
    }
}
