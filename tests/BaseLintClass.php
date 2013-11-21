<?php

$includeDir = ("@php_dir@" == "@"."php_dir@") ? dirname(__FILE__) . "/.." : "@php_dir@/XRef";
require_once "$includeDir/XRef.class.php";

class BaseLintClass extends PHPUnit_Framework_TestCase {

    private $xref;

    public function __construct() {
        // don't read a config file, if any
        XRef::setConfigFileName("default");

        $this->xref = new XRef();
        $this->xref->loadPluginGroup('lint');
        XRef::setConfigValue("lint.check-global-scope", true);
    }

    protected function checkFoundDefect($found_defect, $token_text, $line_number, $severity) {
        $descr = print_r($found_defect, true);   // TODO
        $this->assertTrue($found_defect->tokenText  == $token_text,     "Invalid token:\n$descr");
        $this->assertTrue($found_defect->lineNumber == $line_number,    "Invalid line number:\n$descr");
        $this->assertTrue($found_defect->severity   == $severity,       "Invalid severity:\n$descr");
    }

    protected function checkPhpCode($php_code, $expected_defects) {
        $pf = $this->xref->getParsedFile("filename.php", $php_code);
        $lint_engine = new XRef_LintEngine_Simple($this->xref, false);
        $lint_engine->addParsedFile($pf);
        $pf->release();
        $report = $lint_engine->collectReport();
        $errors = (isset($report["filename.php"])) ? $report["filename.php"] : array();

        $count_found = count($errors);
        $count_expected = count($expected_defects);
        if ($count_found != $count_expected) {
            print_r($report);
            $this->fail( "Wrong number of errors: found=$count_found, expected=$count_expected" );
        } else {
            $this->assertTrue($count_found == $count_expected, "Expected number of defects");
        }

        for ($i=0; $i<count($errors); ++$i) {
            $found_defect = $errors[$i];
            list($token_text, $line_number, $severity) = $expected_defects[$i];
            $this->checkFoundDefect($found_defect, $token_text, $line_number, $severity);
        }
    }

    protected function checkFoundProjectDefect($found_defect, $file_name, $expected_file_name, $token_text, $line_number, $severity) {
        $descr = print_r($found_defect, true);   // TODO
        $this->assertTrue($file_name                == $expected_file_name, "Wrong filename: $file_name / $expected_file_name");
        $this->assertTrue($found_defect->tokenText  == $token_text,         "Invalid token ($found_defect->tokenText, $token_text):\n$descr");
        $this->assertTrue($found_defect->lineNumber == $line_number,        "Invalid line number ($line_number/$found_defect->lineNumber):\n$descr");
        $this->assertTrue($found_defect->severity   == $severity,           "Invalid severity:\n$descr");
    }

    protected function checkProject($files, $expected_defects) {
        $lint_engine = new XRef_LintEngine_ProjectCheck($this->xref, false);
        $file_provider = new XRef_FileProvider_InMemory($files);
        $report = $lint_engine->getReport($file_provider);

        // 1. check that there were no fatal (parse) errors
        // if any file can't be parsed, the rest of the report is invalid
        foreach ($report as $file_name => $list) {
            foreach ($list as $e) {
                if ($e->severity == XRef::FATAL) {
                    throw new Exception("Can't parse file $file_name: $e->message");
                }
            }
        }

        // 2. count errors
        $count_found = 0;
        foreach ($report as $file_name => $list) {
            $count_found += count($list);
        }
        $count_expected = count($expected_defects);
        if ($count_found != $count_expected) {
            print_r($report);
            $this->fail( "Wrong number of errors: found=$count_found, expected=$count_expected" );
        } else {
            $this->assertTrue($count_found == $count_expected, "Expected number of defects");
        }

        // 3. check every entry individually
        $i = 0;
        foreach ($report as $file_name => $list) {
            foreach ($list as $e) {
                list($expected_file_name, $token_text, $line_number, $severity) = $expected_defects[$i];
                $this->checkFoundProjectDefect($e, $file_name, $expected_file_name, $token_text, $severity, $line_number);
                ++$i;
            }
        }
    }
}

