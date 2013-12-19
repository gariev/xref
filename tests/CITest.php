<?php

$includeDir = ("@php_dir@" == "@"."php_dir@") ? dirname(__FILE__) . "/.." : "@php_dir@/XRef";
require_once "$includeDir/XRef.class.php";

class CITest extends PHPUnit_Framework_TestCase {
    protected $xref;

    public function __construct() {
        // don't read config file, if any
        XRef::setConfigFileName("default");
        XRef::setConfigValue("git.repository-dir", ".");
        $this->xref = new XRef();
        $this->xref->loadPluginGroup('lint');
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

    public function testCI() {
        $old_rev        = "377d2edc1da549a80b5b44286e7dcaf59cee300a";
        $current_rev    = "190fc6a9fddcae0313decbbbce92e4a83bf47ab9";

        XRef::setConfigValue('mail.reply-to',       'no-reply@xref-lint.net');
        XRef::setConfigValue('mail.from',           'ci-server@xref-lint.net');
        XRef::setConfigValue('mail.to',             array('test@xref-lint.net', '{%ae}', '{%an}@xref-lint.net'));
        XRef::setConfigValue('project.name',        'test');
        XRef::setConfigValue('project.source-url',  'https://github.com/gariev/xref/blob/{%revision}/{%fileName}#L{%lineNumber}');

        XRef::setConfigValue('xref.smarty-class',   '/Users/igariev/dev/Smarty-2.6.27/libs/Smarty.class.php'); // TODO
        XRef::setConfigValue('xref.data-dir',       'tmp'); // TODO

        $scm = $this->xref->getSourceCodeManager();
        $file_provider_old = $scm->getFileProvider($old_rev);
        $file_provider_new = $scm->getFileProvider($current_rev);
        $modified_files = $scm->getListOfModifiedFiles($old_rev, $current_rev);
        $lint_engine = new XRef_LintEngine_ProjectCheck($this->xref, false);
        $errors = $lint_engine->getIncrementalReport($file_provider_old, $file_provider_new, $modified_files);
        list ($recipients, $subject, $body, $headers) = $this->xref->getNotificationEmail($errors, 'tests-git', $old_rev, $current_rev);

        //print_r(array($recipients, $subject, $body, $headers));

        $this->assertTrue(true);
    }

}



