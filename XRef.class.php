<?php

/**
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

$includeDir =  ("@php_dir@" == "@"."php_dir@") ? dirname(__FILE__) : "@php_dir@/XRef";
require_once "$includeDir/lib/interfaces.php";
require_once "$includeDir/lib/parsers.php";

/**
 *
 * This is a main class, it contains constant declarations, utility methods etc.
 * Actual work is done by library/plugin modules.
 */
class XRef {

    // Enums: file types
    const FILETYPE_PHP = 17;
    const FILETYPE_AS3 = 42;

    // Enums: token kinds
    const T_ONE_CHAR    = 65000; // this is for tokens containing one characker only, e.g. '(', '{' etc
    const T_PACKAGE     = 65001; // AS3 specific
    const T_NULL        = 65002;
    const T_IMPORT      = 65003;
    const T_OVERRIDE    = 65004;
    const T_IN          = 65005;
    const T_EACH        = 65006;
    const T_GET         = 65007;
    const T_SET         = 65008;
    const T_TRUE        = 65009;
    const T_FALSE       = 65010;
    const T_REGEXP      = 65011; // regexp literal for AS3

    // compat mode
    // it's possible to some extend parse PHP 5.3+ code in PHP 5.2 runtime
    // however, have to define missing constants
    public static $compatMode = array();
    static $compatConstants = array(
        "T_NAMESPACE"       => 65100,
        "T_NS_SEPARATOR"    => 65101,
        "T_USE"             => 65102,
        "T_TRAIT"           => 65103,
        "T_GOTO"            => 65104,
    );

    static $tokenNames = array(
        XRef::T_PACKAGE     => "T_PACKAGE",
        XRef::T_NULL        => "T_NULL",
        XRef::T_IMPORT      => "T_IMPORT",
        XRef::T_OVERRIDE    => "T_OVERRIDE",
        XRef::T_IN          => "T_IN",
        XRef::T_EACH        => "T_EACH",
        XRef::T_GET         => "T_GET",
        XRef::T_SET         => "T_SET",
        XRef::T_TRUE        => "T_TRUE",
        XRef::T_FALSE       => "T_FALSE",
        XRef::T_REGEXP      => "T_REGEXP",
    );

    // Enums: lint severity levels
    const FATAL     = -1;   // e.g. can't parse file
    const NOTICE    = 1;
    const WARNING   = 2;
    const ERROR     = 3;
    static $severityNames = array(
        XRef::FATAL     => "fatal",
        XRef::NOTICE    => "notice",
        XRef::WARNING   => "warning",
        XRef::ERROR     => "error",
    );

    // bitmasks for attributes fields
    // e.g. public static function ...
    const MASK_PUBLIC     = 1;
    const MASK_PROTECTED  = 2;
    const MASK_PRIVATE    = 4;
    const MASK_STATIC     = 8;
    const MASK_ABSTRACT   = 16;
    const MASK_FINAL      = 32;
    // map: token kind --> bitmask
    static $attributesMasks = array(
        T_PUBLIC    => XRef::MASK_PUBLIC,
        T_PROTECTED => XRef::MASK_PROTECTED,
        T_PRIVATE   => XRef::MASK_PRIVATE,
        T_STATIC    => XRef::MASK_STATIC,
        T_ABSTRACT  => XRef::MASK_ABSTRACT,
        T_FINAL     => XRef::MASK_FINAL,
    );

    /** constructor */
    public function __construct() {
        spl_autoload_register(array($this, "autoload"), true);

        // compat mode
        foreach (self::$compatConstants as $name => $value) {
            if (!defined($name)) {
                define($name, $value);
                self::$tokenNames[$value] = $name;
                self::$compatMode[$name] = true;
            } elseif (token_name(constant($name))=="UNKNOWN") {
                // oops, someone (e.g. phpunit) but not PHP core
                // has defined this constant
                // don't define it again to prevent "redefine" warning
                self::$tokenNames[ constant($name) ] = $name;
                self::$compatMode[$name] = true;
            }
        }
    }

    public static function version() {
        return "0.1.8p";
    }

    /*----------------------------------------------------------------
     *
     * PLUGIN MANAGEMENT FUNCTIONS
     *
     ---------------------------------------------------------------*/

    /** map (file extension --> parser object), e.g. ('php' => $aPhpParser) */
    protected $parsers      = array();

    /** map (plugin id --> XRef_IPlugin object) */
    protected $plugins      = array();

    /**
     * Returns a list of plugin objects that implements given interface.
     * If no interface name is given, all registered (loaded) plugins will be returned.
     *
     * @param string $interfaceName e.g. "XRef_IDocumentationPlugin"
     * @return XRef_IPlugin[]
     */
    public function getPlugins($interfaceName = null) {
        if (is_null($interfaceName)) {
            return $this->plugins;
        } else {
            $plugins = array();
            foreach ($this->plugins as $id => $plugin) {
                if (is_a($plugin, $interfaceName)) {
                    $plugins[$id] = $plugin;
                }
            }
            return $plugins;
        }
    }

    /**
     * Internal method that registers a given plugin object.
     * Throws exception if plugin with the same ID is already registered.
     * @param XRef_IPlugin $plugin
     */
    private function addPlugin(XRef_IPlugin $plugin) {
        $pluginId = $plugin->getId();
        if (array_key_exists($pluginId, $this->plugins)) {
            throw new Exception("Plugin '$pluginId' is already registered");
        } else {
            $plugin->setXRef($this);
            $this->plugins[$pluginId] = $plugin;
        }
    }

    /**
     * Internal method; it's made public for writing unit tests only
     *
     * @internal
     * @param XRef_IFileParser $parser
     */
    public function addParser(XRef_IFileParser $parser) {
        $extensions = $parser->getSupportedFileExtensions();
        foreach ($extensions as $ext) {
            // should it be case-insensitive?
            $ext = strtolower(preg_replace("#^\\.#", "", $ext));
            if (array_key_exists($ext, $this->parsers)) {
                $p = $this->parsers[$ext];
                $old_class = get_class($p);
                $new_class = get_class($parser);
                throw new Exception("Parser for file extenstion '$ext' already exists ($old_class/$new_class)");
            } else {
                $this->parsers[$ext] = $parser;
            }
        }
    }

    /**
     * Returns a registered (loaded) plugin by its id
     *
     * @param string $pluginId
     * @return XRef_IPlugin
     */
    public function getPluginById($pluginId) {
        return $this->plugins[$pluginId];
    }

    /**
     * Method to load plugins defined in config file.
     * For the name $groupName, plugins/parsers config-defined as $groupName.plugins[] and $groupName.parsers[] will be loaded.
     *
     * @param string $groupName
     */
    public function loadPluginGroup($groupName) {
        $isGroupEmpty = true;

        foreach (self::getConfigValue("$groupName.parsers", array()) as $parserClassName) {
            $parser = new $parserClassName();
            $this->addParser($parser);
            $isGroupEmpty = false;
        }

        foreach (XRef::getConfigValue("$groupName.plugins", array()) as $pluginClassName) {
            $plugin = new $pluginClassName();
            $this->addPlugin($plugin);
            $isGroupEmpty = false;
        }

        if ($isGroupEmpty) {
            throw new Exception("Group '$groupName' is empty - no plugins or parsers are defined");
        }
    }

    /**
     * @return XRef_ISourceCodeManager
     */
    public function getSourceCodeManager() {
        $scmClass = self::getConfigValue("ci.source-code-manager");
        return new $scmClass();
    }

    private $storageManager;

    /**
     * @return XRef_IPersistentStorage
     */
    public function getStorageManager() {
        if (!isset($this->storageManager)) {
            $managerClass = self::getConfigValue("xref.storage-manager");
            $this->storageManager = new $managerClass();
        }
        return $this->storageManager;
    }

    /** directory where the cross-reference will be stored */
    public function setOutputDir($outputDir) {
        $this->outputDir = $outputDir;
        self::createDirIfNotExist($outputDir);
    }

    /**
     * Method finds parser for given fileType and returns parsed file object
     * If $content is null, it will be read from $filename
     * TODO: made fileType optional param - take it from filename
     *
     * @param string $filename
     * @param string $content
     * @return XRef_IParsedFile
     */
    public function getParsedFile($filename, $content = null) {
        $file_type = strtolower( pathinfo($filename, PATHINFO_EXTENSION) );
        $parser = isset($this->parsers[$file_type]) ? $this->parsers[$file_type] : null;
        if (!$parser) {
            throw new Exception("No parser is registered for filetype $file_type ($filename)");
        }
        if ($content==null) {
            $content = file_get_contents($filename);
        }

        $pf = $parser->parse( $content, $filename );
        return $pf;
    }

    /**
     * autoload handler, it's set in constructor
     */
    public function autoload($className) {
        $searchPath = self::getConfigValue("xref.plugins-dir", array());
        $searchPath[] = dirname(__FILE__) . "/plugins";
        $fileName = str_replace('_', '/', $className) . ".class.php";

        foreach ($searchPath as $dirName) {
            $fullFileName = "$dirName/$fileName";
            if (file_exists($fullFileName)) {
                require_once $fullFileName;
                return;
            }
        }
        // TODO: don't use PHP autoload
        // Smarty v3 uses it too which makes proper error reporting impossible - if a plugin is not loaded,
        // maybe it's Smarty plugin and should be loaded by it. Or maybe not, who knows.
        //
        //$message = "Can't autoload class '$className': file $fileName not found in " . implode(", ", $searchPath);
        //error_log($message);
        //throw new Exception($message); // looks like exceptions don't work inside autoload functions?
    }


    /*----------------------------------------------------------------
     *
     * CROSS-REFERENCE DOCUMENTATION
     *
     ---------------------------------------------------------------*/

    protected $outputDir;


    /**
     * Utility method to create file names for given reportId/objectId report
     *
     * @param string $reportId, e.g. 'php-classes'
     * @param string $objectId, e.g. 'SomeClassName'
     * @param string $extension
     * @return tuple("php-classes/SomeClassName.html", "../")
     */
    protected function getOutputPath($reportId, $objectId, $extension = "html") {
        if ($objectId==null) {
            return array("$this->outputDir/$reportId.$extension", "");
        } else {
            $filename = $this->getFileNameForObjectID($objectId, $extension);

            $dirs = preg_split("#/#", $filename);
            $htmlRoot = '';
            $filePath = "$this->outputDir/$reportId";
            for ($i=0; $i<count($dirs); ++$i)   {
                self::createDirIfNotExist($filePath);
                $filePath = $filePath . "/" . $dirs[$i];
                $htmlRoot .= "../";
            }
            return array($filePath, $htmlRoot);
        }
    }

    /**
     * Utility method to open a file handle for given reportId/objectId; caller is responsible for closing the file.
     * See method getOutputPath above
     *
     * @param string $reportId
     * @param string $objectId
     * @param string $extension
     * @return tuple(resource $fileHanle, string $pathToReportRootDir)
     */
    public function getOutputFileHandle($reportId, $objectId, $extension = "html") {
        list ($filename, $htmlRoot) = $this->getOutputPath($reportId, $objectId, $extension);
        $fh = fopen($filename, "w");
        if (!$fh) {
            throw new Exception("Can't write to file '$filename'");
        }
        return array($fh, $htmlRoot);
    }

    /**
     * Translates object name into report file name,
     * e.g. "..\Console\Getopt.php" --> "--/Console/Getopt.php.html";
     *
     * @param string $objectId
     * @param string $extension
     * @return string
     */
    protected function getFileNameForObjectID($objectId, $extension="html") {
        // TODO: create case insensitive file names for Windows
        $objectId = preg_replace("#\\\\#", '/', $objectId);
        $objectId = preg_replace("#[^a-zA-Z0-9\\.\\-\\/]#", '-', $objectId);
        $objectId = preg_replace("#\\.\\.#", '--', $objectId);
        return "$objectId.$extension";
    }

    /**
     * utility method to create dir/throw exception on errors
     * @param string $dirName
     */
    public static function createDirIfNotExist($dirName) {
        if (!is_dir($dirName)) {
            if (!mkdir($dirName)) {
                throw new Exception("Can't create dir $dirName!");
            }
        }
    }

    /*----------------------------------------------------------------
     *
     * Cross-reference (cross-plugin support):
     * links from one object/report to another one
     *
     * ---------------------------------------------------------------*/

    /**
     * Creates a link (string) to given reportId/objectId report
     *
     * @param string $reportId, e.g. "files"
     * @param string $objectId, e.g. "Server/game/include/Some.Class.php"
     * @param string $root,     e.g. "../"
     * @param string $anchor    e.g. "line120"
     * @return string           "../files/Server/game/include/Some.Class.php.html#line120"
     */
    public function getHtmlLinkFor($reportId, $objectId, $root, $anchor=null) {
        if (isset($objectId)) {
            $filename = $this->getFileNameForObjectID($objectId);
            $link = $root . "$reportId/$filename";
        } else {
            $link = $root . "$reportId.html";
        }
        if ($anchor) {
            $link .= "#$anchor";
        }
        return $link;
    }

    // list of links from source file to other reports
    //  $linkDatabase[ $filename ][ startTokenIndex ]   = array(report data)
    //  $linkDatabase[ $filename ][ endTokenIndex ]     = 0;
    protected $linkDatabase = array();

    public function addSourceFileLink(XRef_FilePosition $fp, $reportName, $reportObjectId) {
        if (!array_key_exists($fp->fileName, $this->linkDatabase)) {
            $this->linkDatabase[$fp->fileName] = array();
        }
        // TODO: this is ugly, rewrite this data structure
        // current syntax:
        //  if element is array(report, id),    then this is an open link   <a href="report/id">
        //  if element is 0,                    then this a closing tag     </a>
        $this->linkDatabase[$fp->fileName][$fp->startIndex] = array($reportName, $reportObjectId);
        $this->linkDatabase[$fp->fileName][$fp->endIndex+1] = 0;
    }

    public function &getSourceFileLinks($fileName) {
        return $this->linkDatabase[$fileName];
    }

    /*----------------------------------------------------------------
     *
     * LINT SUPPORT CODE
     *
     * ---------------------------------------------------------------*/


    public function filterFiles($files) {
        // TODO(?): use extension list from parser plugins
        $extensions = array('php' => true );
        $filtered_files = array();

        foreach ($files as $f) {
            $ext = strtolower( pathinfo($f, PATHINFO_EXTENSION) );
            if (! isset($extensions[$ext])) {
                continue;
            }
            $filtered_files[] = $f;
        }
        return $filtered_files;
    }

    /** $lintReportLevel: XRef::ERROR, XRef::WARNING etc */
    protected $lintReportLevel = null;

    /** map error_code -> Array error_description */
    protected $lintErrorMap = null;

    /** map error_code -> true */
    protected $lintIgnoredErrors = null;

    /**
    * Affects what kind of defects the lint plugins will report.
    *
    * @param int $reportLevel - one of constants XRef::NOTICE, XRef::WARNING or XRef::ERROR
    * @return void
    */
    public function setLintReportLevel($reportLevel) {
        $this->lintReportLevel = $reportLevel;
    }


    // experimental
    public function getProjectReport(XRef_IProjectDatabase $db) {
        $plugins = $this->getPlugins("XRef_IProjectLintPlugin");
        $report = array();
        foreach ($plugins as /** @var $plugin XRef_IProjectLintPlugin */ $plugin) {
            $plugin_report = $plugin->getProjectReport($db);
            $report = array_merge_recursive($report, $plugin_report);
        }

        return $report;
    }

    /**
     * @param array $report - array(filename => list of XRef_CodeDefects)
     */
    public function sortAndFilterReport(array $report) {
        // init once
        if (is_null($this->lintIgnoredErrors)) {
            $this->lintIgnoredErrors = array();
            foreach (self::getConfigValue("lint.ignore-error", array()) as $error_code) {
                $this->lintIgnoredErrors[$error_code] = true;
            }
        }

        // also init once
        if (is_null($this->lintReportLevel)) {
            $r = XRef::getConfigValue("lint.report-level", "warning");
            if ($r == "errors" || $r == "error") {
                $reportLevel = XRef::ERROR;
            } elseif ($r == "warnings" || $r == "warning") {
                $reportLevel = XRef::WARNING;
            } elseif ($r == "notice" || $r == "notices") {
                $reportLevel = XRef::NOTICE;
            } elseif (is_numeric($r)) {
                $reportLevel = (int) $r;
            } else {
                throw new Exception("unknown value for config var 'lint.report-level': $r");
            }
            $this->lintReportLevel = $reportLevel;
        }

        $filtered_report = array();
        foreach ($report as $file_name => $defects_list) {
            $filtered_list = array();
            foreach ($defects_list as /** @var XRef_CodeDefect $d */$d) {
                if ($d->severity < $this->lintReportLevel) {
                    continue;
                }
                if (isset($this->lintIgnoredErrors[ $d->errorCode ])) {
                    continue;
                }
                $filtered_list[] = $d;
            }

            if ($filtered_list) {
                $filtered_report[$file_name] = $filtered_list;
            }

        }
        ksort($filtered_report); // sort report by file names
        foreach ($filtered_report as $file_name => $r) {
            // sort by line numbers
            usort($filtered_report[$file_name], array("XRef", "_sortLintReportByLineNumber"));
        }
        return $filtered_report;
    }

    static function _sortLintReportByLineNumber ($a, $b) {
        $la = $a->lineNumber;
        $lb = $b->lineNumber;
        if ($la==$lb) {
            return 0;
        } elseif ($la>$lb) {
            return 1;
        } else {
            return -1;
        }
    }

    /*----------------------------------------------------------------
     *
     * CONFIG FILE METHODS
     *
     * ---------------------------------------------------------------*/
    private static $config;

    /**
     * @return string - the name of the config file
     */
    private static function getConfigFilename() {
        $filename = null;

        // get name of config file from command-line args (-c, --config)
        list($options, $arguments) = self::getCmdOptions();
        if (isset($options["config"])) {
            $filename = $options["config"];
        }

        // get config filename from environment
        if (!$filename) {
            $filename = getenv("XREF_CONFIG");
        }

        // config file in .xref dir in current dir, or in any parent dirs
        if (!$filename) {
            $dir = getcwd();
            while ($dir) {
                if (file_exists("$dir/.xref/xref.ini")) {
                    $filename = "$dir/.xref/xref.ini";
                    break;
                }
                $parent_dir = dirname($dir); // one step up
                if ($parent_dir == $dir) {
                    // top level, can't go up
                    break;
                }
                $dir = $parent_dir;
            }
        }

        // config in installation dir?
        if (!$filename) {
            $f = ("@data_dir@" == "@"."data_dir@") ? dirname(__FILE__) . "/config/xref.ini" : "@data_dir@/XRef/config/xref.ini";
            if (file_exists($f)) {
                $filename = $f;
            }
        }

        // special value for filename - means don't read any config file
        if ($filename && $filename == 'default') {
            $filename = null;
        }

        return $filename;
    }

    /**
     * @return array - the key/value pairs read from config file
     */
    public static function &getConfig($forceReload = false) {
        if (self::$config && !$forceReload) {
            return self::$config;
        }

        $filename = self::getConfigFilename();

        if ($filename) {
            if (XRef::verbose()) {
                echo "Using config $filename\n";
            }
            $ini = parse_ini_file($filename, true);
            if (!$ini) {
                throw new Exception("Error: can parse ini file '$filename'");
            }
        } else {
            // if no file explicitly specified, and default config doesn't exist,
            // don't throw error and provide default config values
            if (XRef::verbose()) {
                echo "Using default config\n";
            }
            $ini = array();
        }


        $config = array();
        foreach ($ini as $sectionName => $section) {
            foreach ($section as $k => $v) {
                $config["$sectionName.$k"] = $v;
            }
        }

        // default config values are for command-line xref tool only;
        // you have to specify a real config for xref-doc, CI and web-based tools
        $defaultConfig = array(
            'xref.storage-manager'  => 'XRef_Storage_File',

            'doc.parsers'           => array('XRef_Parser_PHP'),
            'doc.plugins'           => array(
                'XRef_Doc_ClassesPHP',
                'XRef_Doc_MethodsPHP',
                'XRef_Doc_PropertiesPHP',
                'XRef_Doc_ConstantsPHP',
                'XRef_Doc_SourceFileDisplay',
                'XRef_Lint_UninitializedVars',
                'XRef_Lint_LowerCaseLiterals',
                'XRef_Lint_StaticThis',
                'XRef_Lint_ClosingTag',
                'XRef_Doc_LintReport',          // this plugin creates a documentation page with list of errors found by 3 lint plugins above
             ),

            'lint.color'            => 'auto',
            'lint.report-level'     => 'warnings',
            'lint.parsers'          => array('XRef_Parser_PHP'),
            'lint.plugins'          => array(
                'XRef_Lint_UninitializedVars',
                'XRef_Lint_LowerCaseLiterals',
                'XRef_Lint_StaticThis',
                'XRef_Lint_AssignmentInCondition',
                'XRef_Lint_ClosingTag',
                'XRef_ProjectLint_CheckClassAccess',
                'XRef_ProjectLint_MissedParentConstructor',
                'XRef_Doc_SourceFileDisplay',   // it's needed for web version of lint tool to display formatted source code
            ),
            'lint.add-constant'             => array(),
            'lint.add-function-signature'   => array(),
            'lint.add-global-var'           => array(),
            'lint.ignore-missing-class'     => array(),
            'lint.ignore-error'       => array(),
            'lint.check-global-scope' => true,
            'ci.source-code-manager'  => 'XRef_SourceCodeManager_Git',
        );
        foreach ($defaultConfig as $key => $value) {
            if (!isset($config[$key])) {
                $config[$key] = $value;
            }
        }

        // override values with -d command-line option
        list($options, $arguments) = self::getCmdOptions();
        if (isset($options["define"])) {
            foreach ($options["define"] as $d) {
                list($k, $v) = explode("=", $d, 2);
                if ($v) {
                    if ($v=="true" || $v=="on") {
                        $v = true;
                    } elseif ($v=="false" || $v=="off") {
                        $v = false;
                    }
                }

                $force_array = false;
                if (substr($k, strlen($k)-2) == '[]') {
                    $force_array = true;
                    $k = substr($k, 0, strlen($k)-2);
                }
                if ($force_array || (isset($config[$k]) && is_array($config[$k]))) {
                    if ($v) {
                        $config[$k][] = $v;
                    } else {
                        $config[$k] = array();
                    }
                } else {
                    $config[$k] = $v;
                }
            }
        }

        self::$config = $config;
        return self::$config;
    }

    public function init() {

        $cwd = getcwd();
        // create data dir
        if (!file_exists(".xref")) {
            echo "Creating dir .xref\n";
            if (!mkdir(".xref")) {
                throw new Exception("Can't create dir .xref");
            }
        }

        $config_filename = ".xref/xref.ini";
        if (file_exists($config_filename)) {
            echo "Config file $config_filename exists; won't overwrite\n";
        } else {
            echo "Creating config file $config_filename\n";
            // create config file
            $fh = fopen($config_filename, "w");
            if (!$fh) {
                throw new Exception("Can't create file $config_filename");
            }
            $config = array();

            $config[] = "[project]";
            $config[] = array("name", basename($cwd));
            $config[] = array("source-code-dir[]", realpath($cwd));
            $config[] = null;

            $config[] = "[xref]";
            $config[] = array("data-dir", realpath("$cwd/.xref"));
            $config[] = array("project-check", "true");
            $config[] = array(";smarty-class", "/path/to/Smarty.class.php");
            $config[] = null;

            $config[] = "[doc]";
            $config[] = array(";output-dir", "/path/for/generated/doc");
            $config[] = null;

            if (file_exists(".git")) {
                // TODO: need a better way to check that this is a git project
                // http://stackoverflow.com/questions/957928
                // git rev-parse --show-toplevel
                $config[] = "[git]";
                $config[] = array("repository-dir", realpath($cwd));
                $config[] = null;

                $config[] = "[ci]";
                $config[] = array("incremental", "true");
                $config[] = array("update-repository", "true");
                $config[] = null;

                $config[] = "[mail]";
                $config[] = array("from", "XRef Continuous Integration");
                $config[] = array(";reply-to", "you@your.domain.com");
                $config[] = array(";to[]", "{%ae}");
                $config[] = array(";to[]", "you@your.domain.com");
                $config[] = null;
            }

            foreach ($config as $line) {
                $l = null;
                if (!$line) {
                    $l = "\n";
                } elseif (!is_array($line)) {
                    $l = "$line\n";
                } else {
                    list($k, $v) = $line;
                    if (preg_match('#^\\w+$#', $v)) {
                        $l = sprintf("%-20s= %s\n", $k, $v);
                    } else {
                        $v = preg_replace('#\\\\#', '\\\\', $v);
                        $l = sprintf("%-20s= \"%s\"\n", $k, $v);
                    }
                }
                fwrite($fh, $l);
            }
            fclose($fh);

            // re-read config values from the file
            self::getConfig(true);
        }

        // index files for the first time
        $this->loadPluginGroup('lint');
        $file_provider = new XRef_FileProvider_FileSystem( $cwd );
        $lint_engine = new XRef_LintEngine_ProjectCheck($this);
        $lint_engine->setShowProgressBar(true);
        $lint_engine->setRewriteCache(true);    // re-index files and refill cache
        echo "Indexing files\n";
        $lint_engine->getReport($file_provider);
        echo "\nDone.\n";
        // done
    }

    /** basic console progress bar: 10/100 files processed */
    public static function progressBar($current, $total, $text) {
        $len = strlen($text);
        $text = ($len < 60) ? str_pad($text, 60) : "..." . substr($text, $len-57);
        echo sprintf("\r%4d/%4d %s ", $current, $total, $text);
    }

    /**
     * Returns value of given key from config if defined in config, or default value if supplied, or throws exception.
     *
     * @param string $key
     * @param mixed $defaultValue
     * @return mixed
     */
    public static function getConfigValue($key, $defaultValue=null) {
        $config = self::getConfig();
        if (isset($config[$key])) {
            return $config[$key];
        }
        if (isset($defaultValue)) {
            return $defaultValue;
        }
        throw new Exception("Value of $key is not defined in config file");
    }

    /**
     * Mostly debug/test function - to set up config params to certain values.
     * Changes are not persistent. Function is used in test suite only.
     *
     * @param string $key
     * @param mixed $value
     */
    public static function setConfigValue($key, $value) {
        $config = & self::getConfig();
        $config[$key] = $value;
    }

    /*----------------------------------------------------------------
     *
     * COMMAND-LINE OPTIONS
     *
     * ---------------------------------------------------------------*/
    private static $options;
    private static $arguments;
    private static $needHelp;
    private static $verbose;

    // optionsList: array of arrays (shortOpt, longOpt, usage, description, isArray)
    private static $optionsList = array(
        array('c:', 'config=',  '-c, --config=FILE',    'Path to config file',          false),
        array('v',  'verbose',  '-v, --verbose',        'Be noisy',                     false),
        array('h',  'help',     '-h, --help',           'Print this help and exit',     false),
        array('d:', 'define=',  '-d, --define key=val', 'Override config file values',  true),
    );

    public static function registerCmdOption($shortName, $longName, $usage, $desc, $isArray = false) {
        self::$optionsList[] = array($shortName, $longName, $usage, $desc, $isArray);
    }

    /**
     * Parses command line-arguments and returns found options/arguments.
     *
     *  input (for tests only, by default it takes real command-line option list):
     *      array("scriptname.php", "--help", "-d", "foo=bar", "--config=xref.ini", "filename.php")
     *
     *  output:
     *      array(
     *          array( "help" => true, "define" => array("foo=bar"), "config" => "xref.ini"),
     *          array( "filename.php" )
     *      );
     *
     * @return tuple(array $commandLineOptions, array $commandLineArguments)
     */
    public static function getCmdOptions( $testArgs = null ) {

        if (is_null($testArgs)) {
            if (self::$options) {
                return array(self::$options, self::$arguments);
            }

            if (php_sapi_name() != 'cli') {
                return array(array(), array());
            }
        }

        $shortOptionsList = array();    // array( 'h', 'v', 'c:' )
        $longOptionsList = array();     // array( 'help', 'verbose', 'config=' )
        $renameMap = array();           // array( 'h' => 'help', 'c' => 'config' )
        $isArrayMap = array();          // array( 'help' => false, 'define' => true, )
        foreach (self::$optionsList as $o) {
            $short = $o[0];
            $long = $o[1];
            if ($short) {
                $shortOptionsList[] = $short;
            }
            if ($long) {
                $longOptionsList[] = $long;
            }
            $short = preg_replace('/\W$/', '', $short); // remove ':' and '=' at the end of specificatios
            $long = preg_replace('/\W$/', '', $long);
            if ($short && $long) {
                $renameMap[ $short ] = $long;
            }
            $isArrayMap[ ($long) ? $long : $short ] = $o[4];
        }

        // TODO: write a better command-line parser
        // too bad: the code below (from official Console/Getopt documentation) doesn't work in E_STRICT mode
        // and Console_GetoptPlus is not installed by default on most systems :(
        $error_reporting = error_reporting();
        error_reporting($error_reporting & ~E_STRICT);
        require_once 'Console/Getopt.php';
        $getopt = new Console_Getopt();
        $args = ($testArgs) ? $testArgs : $getopt->readPHPArgv();
        $getoptResult = $getopt->getopt( $args, implode('', $shortOptionsList), $longOptionsList);
        if (PEAR::isError($getoptResult)) {
            throw new Exception( $getoptResult->getMessage() );
        }
        error_reporting($error_reporting);
        // end of Console_Getopt dependent code

        $options = array();
        list($optList, $arguments) = $getoptResult;
        foreach ($optList as $o) {
            list($k, $v) = $o;
            // change default value for command-line options that doesn't require a value
            if (is_null($v)) {
                $v = true;
            }
            // '-a' --> 'a', '--foo' -> 'foo'
            $k = preg_replace('#^-+#', '', $k);

            // force long option names
            if (isset($renameMap[$k])) {
                $k = $renameMap[$k];
            }

            if ($isArrayMap[$k]) {
                if (!isset($options[$k])) {
                    $options[$k] = array();
                }
                $options[$k][] = $v;
            } else {
                $options[$k] = $v;
            }
        }

        self::$options = $options;
        self::$arguments = $arguments;
        return array($options, $arguments);
    }

    /**
     * For CLI scripts only: if -h / --help option was in command-line arguments
     *
     * @return bool
     */
    public static function needHelp() {
        if (! isset(self::$needHelp)) {
            list($options) = self::getCmdOptions();
            self::$needHelp = isset($options['help']) && $options['help'];
        }
        return self::$needHelp;
    }

    public static function showHelpScreen($toolName, $usageString=null) {
        global $argv;
        if (!$usageString) {
            $usageString = "$argv[0] [options]";
        }

        $pathToReadMe = ('@doc_dir@' == '@'.'doc_dir@') ? dirname(__FILE__) . "/README.md" : '@doc_dir@/XRef/README.md';
        $configFile = self::getConfigFilename();
        if (!$configFile) {
            $configFile = "not found; using default values";
        }
        echo "$toolName, v. " . self::version() . "\n";
        echo "Usage:\n";
        echo "  $usageString\n";
        echo "Options:\n";
        foreach (self::$optionsList as $o) {
            list($shortName, $longName, $usage, $desc) = $o;
            echo sprintf("  %-20s %s\n", $usage, $desc);
        }
        echo "Config file:\n";
        echo "  $configFile\n";
        echo "See also: $pathToReadMe\n";
    }

    /**
     * @return bool
     */
    public static function verbose() {
        if (! isset(self::$verbose)) {
            list($options) = self::getCmdOptions();
            self::$verbose = isset($options['verbose']) && $options['verbose'];
        }
        return self::$verbose;
    }

    public static function setVerbose($verbose) {
        self::$verbose = $verbose;
    }

    /*----------------------------------------------------------------
     *
     * TEMPLATE (SMARTY) METHODS
     *
     * ---------------------------------------------------------------*/

    /**
     * Method fills the given template with given template params; return the resulting text
     *
     * @param string $templateName
     * @param array $templateParams
     */
    public function fillTemplate($templateName, $templateParams) {
        $smartyClassPath = self::getConfigValue("xref.smarty-class");
        require_once $smartyClassPath;

        $smartyTmpDir = self::getConfigValue("xref.data-dir");
        self::createDirIfNotExist($smartyTmpDir);
        self::createDirIfNotExist("$smartyTmpDir/smarty");
        self::createDirIfNotExist("$smartyTmpDir/smarty/templates_c");
        self::createDirIfNotExist("$smartyTmpDir/smarty/cache");
        self::createDirIfNotExist("$smartyTmpDir/smarty/configs");

        $defaultTemplateDir = ("@data_dir@" == "@"."data_dir@") ?
            dirname(__FILE__) . "/templates" : "@data_dir@/XRef/templates";
        $templateDir = self::getConfigValue("xref.template-dir", $defaultTemplateDir);

        $smarty = new Smarty();
        if (defined("Smarty::SMARTY_VERSION") ) {
            // smarty v. 3+
            $smarty->setTemplateDir($templateDir);
            $smarty->setCompileDir("$smartyTmpDir/smarty/templates_c");
            $smarty->setCacheDir("$smartyTmpDir/smarty/cache/");
            $smarty->setConfigDir("$smartyTmpDir/smarty/configs");

            // our functions
            $smarty->registerPlugin('function', 'xref_report_link', array($this, "xref_report_link"));
            $smarty->registerPlugin('function', 'xref_severity_str', array($this, "xref_severity_str"));
        } else {
            // smarty v. 2+
            $smarty->template_dir   = $templateDir;
            $smarty->compile_dir    = "$smartyTmpDir/smarty/templates_c";
            $smarty->cache_dir      = "$smartyTmpDir/smarty/cache";
            $smarty->config_dir     = "$smartyTmpDir/smarty/configs";

            // our functions
            $smarty->register_function('xref_report_link', array($this, "xref_report_link"));
            $smarty->register_function('xref_severity_str', array($this, "xref_severity_str"));
        }


        // default params
        $smarty->assign('config', self::getConfig());
        $smarty->assign('version', self::version());

        // template params
        foreach ($templateParams as $k => $v) {
            $smarty->assign($k, $v);
        }

        $result = $smarty->fetch($templateName);
        $smarty->template_objects = array(); // otherwise Smarty v3 leaks memory
        return $result;
    }

    /**
     * Function is called from Smarty templeate, returns formatted URL. Usage exampple (smarty code):
     * <a href='{xref_report_link reportId="files" itemName=$filePos->fileName root=$root lineNumber=$filePos->lineNumber}'>...</a>
     *
     * @return string
     */
    public function xref_report_link($params, $smarty) {
        $itemName = isset($params['itemName']) ? $params['itemName'] : null;        // just to remove warning
        $lineNumber = isset($params['lineNumber']) ? $params['lineNumber'] : null;  // about optional params
        return $this->getHtmlLinkFor( $params['reportId'], $itemName, $params['root'], $lineNumber );
    }

    public function xref_severity_str($params, $smarty) {
        $str = XRef::$severityNames[ $params['severity'] ];
        return ($params['html']) ? "<span class='$str'>$str</span>" : $str;
    }

    public static function isPublic($attriburtes) {
        // either explicit public, or no visibility modifier at all (compat mode with php 4)
        return
            ($attriburtes & self::MASK_PUBLIC) != 0
            || ($attriburtes & self::MASK_PRIVATE) == 0 && ($attriburtes & self::MASK_PROTECTED) == 0;

    }

    public static function isPrivate($attriburtes) {
        return ($attriburtes & self::MASK_PRIVATE);
    }
    public static function isProtected($attriburtes) {
        return ($attriburtes & self::MASK_PROTECTED);
    }
    public static function isStatic($attriburtes) {
        return ($attriburtes & self::MASK_STATIC);
    }


}

// vim: tabstop=4 expandtab
