<?php
require_once 'PEAR/PackageFileManager2.php';
require_once dirname(__FILE__) . '/../XRef.class.php';
$version = XRef::version();

PEAR::setErrorHandling(PEAR_ERROR_DIE);
$pfm = new PEAR_PackageFileManager2;
$pfm->setOptions( array(
    'baseinstalldir'    => 'XRef',
    'packagedirectory'  => '.',
    'filelistgenerator' => 'file',
    'ignore'            => array('Makefile', 'dev/'),
    //'installexceptions' => array('bin' => '/'), // hm, doesn't  actually work
    'dir_roles'         => array(
        'bin'       => 'script',
        'config'    => 'data',
        'tests'     => 'test',
        'examples'  => 'doc',
        'templates' => 'data',
        'scripts'   => 'php',
        'web-scripts'=> 'php',
        'plugins'   => 'php',
    ),
    'exceptions'        => array(
        'README.md'         => 'doc', // README would be data, now is doc
    ),
));
$pfm->setPackage('XRef');
$pfm->setSummary('XRef - php source file toolkit');
$pfm->setDescription('XRef - lint and cross-ref documentation generator');
$pfm->setChannel('pear.php.net');
$pfm->setAPIVersion($version);
$pfm->setReleaseVersion($version);
$pfm->setReleaseStability('alpha');
$pfm->setAPIStability('alpha');
$pfm->setNotes("initial release");
$pfm->setPackageType('php');

// dependencies
//$pfm->addDependency("Console_Getopt");

// windows-release
$pfm->addRelease(); // set up a release section
$pfm->setOSInstallCondition('windows');
$pfm->addIgnoreToRelease('bin/xref-lint');
$pfm->addIgnoreToRelease('bin/xref-doc');
$pfm->addIgnoreToRelease('bin/xref-ci');
$pfm->addIgnoreToRelease('bin/git-xref-lint');
$pfm->addInstallAs('bin/xref-lint.bat', 'xref-lint.bat');
$pfm->addInstallAs('bin/xref-doc.bat', 'xref-doc.bat');

// other platforms
$pfm->addRelease();
$pfm->addIgnoreToRelease('bin/xref-lint.bat');
$pfm->addIgnoreToRelease('bin/xref-doc.bat');
$pfm->addInstallAs('bin/xref-lint',     'xref-lint');
$pfm->addInstallAs('bin/xref-doc',      'xref-doc');
$pfm->addInstallAs('bin/xref-ci',       'xref-ci');
$pfm->addInstallAs('bin/git-xref-lint', 'git-xref-lint');

$pfm->setPhpDep('5.2.0');
$pfm->setPearinstallerDep('1.4.0a12');
$pfm->addMaintainer('lead', 'gariev', 'Igor Gariev', 'gariev@hotmail.com');
$pfm->setLicense('PHP License', 'http://www.php.net/license');


$pfm->addGlobalReplacement('package-info', '@version@',    'version');
$pfm->addGlobalReplacement('pear-config',  '@php_bin@',    'php_bin');  // path to php executable
$pfm->addGlobalReplacement('pear-config',  '@bin_dir@',    'bin_dir');  // bin dir
$pfm->addGlobalReplacement('pear-config',  '@php_dir@',    'php_dir');  // lib dir
$pfm->addGlobalReplacement('pear-config',  '@data_dir@',   'data_dir'); // data dir ()
$pfm->addGlobalReplacement('pear-config',  '@doc_dir@',    'doc_dir');  // data dir ()

$pfm->generateContents();

// remove 'baseinstalldir' from scripts
$filelist = $pfm->getFilelist();
foreach ($filelist as $filename => $attrs) {
    if ($attrs['role']=='script') {
        $pfm->setFileAttribute($filename, 'baseinstalldir', '');
    }
}

$pfm->writePackageFile();

// vim: tabstop=4 expandtab
