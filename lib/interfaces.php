<?php

/**
 * file: lib/interfaces.php
 *
 * Declaration of interfaces, abstract classes, plain data object classes etc.
 * The core of the XRef API.
 *
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

/**
 * Interface for parser/tokenizer
 */
interface XRef_IFileParser {
    /**
     * Main method to parse code; returns XRef_IParsedFile object.
     * Parameter $filename must not be the real filename, it's used in messages mostly ("line 123 at file <filename>")
     *
     * @param string $file_content
     * @param string $filename
     * @return XRef_IParsedFile
     */
    public function parse($file_content, $filename);

    /**
     * @return string[] e.g. array('.php')
     */
    public function getSupportedFileExtensions();
}

/**
 * Interface for object with content of one file
 */
interface XRef_IParsedFile {
    /**
     * Returns one of the XRef::FILETYPE_PHP, XRef::FILETYPE_AS3, etc constants
     * @return int
     */
    public function getFileType();

    /**
     * e.g. "Foo/Bar.php"
     * @return string
     */
    public function getFileName();

    /**
     * @return XRef_Token[]
     */
    public function &getTokens();

    /**
     * @param int $index    index of the token in getTokens() array
     * @return XRef_Token
     */
    public function getTokenAt($index);

    /**
     * returns line number of the token at index $index
     * @param int $index
     * @return int
     */
    public function getLineNumberAt($index);

    /**
     * returns class for the token index $index
     * @param int $index
     * @return XRef_Class
     */
    public function getClassAt($index);

    /**
     * returns method or function at the token index $index
     * @param int $index
     * @return XRef_Function
     */
    public function getMethodAt($index);

    /**
     * returns array of all declared/defined methods and functions
     * @return XRef_Function[]
     */
    public function &getMethods();

    /**
     * returns array of all classes
     * @return XRef_Class[]
     */
    public function &getClasses();

    /**
     * returns array of all constants
     * @return XRef_Constant[]
     */
    public function &getConstants();

    /**
     * returns namespace at the given index
     * @param int $index
     * @return XRef_Namespace
     */
    public function getNamespaceAt($index);

    /**
     * Returns fully-qualified name according to namespace in effect at given token index.
     * E.g. (assuming that current namespace is Foo and namespace \Bar\Baz is imported as Baz):
     *      \string     -> string
     *      \Foo\bar    -> Foo\bar
     *      bar         -> Foo\bar
     *      Baz\quxx    -> Bar\Baz\quxx
     *
     * @param string $name
     * @param int $index
     * @return string
     */
    public function qualifyName($name, $index);

    /**
     * input: index of any paired token (e.g. "["), output: index of the corresponding paired token (here, index of closing "]" token)
     * @param int $i
     * @return int
     */
    public function getIndexOfPairedBracket($i);//

    // extracts all elements of list-like constructs,
    // returns array with first token of each list element
    // startToken must be the first token AFTER opening braket
    //      function foo(a, b, &c)  --> array(a, b, &)
    //      list(a, list(b, c), d)  --> array(a, list, d)
    //      for (i=0; i<10; ++i)    --> array(i, i, ++)
    //      function()              --> array()
    public function extractList($startToken, $separatorString=",", $terminatorString=")");

    public function getNumberOfLines();
    public function release();                  // helps PHP garbage collector to free memory

}

/**
 * Plain data object class - one token of parsed source file
 */
class XRef_Token {
    /** basic attributes - token type, text and position in file */
    public $kind;
    public $text;
    public $lineNumber;
    public $index;

    // link to parent
    public $parsedFile;

    public function __construct($parsedFile, $index, $kind, $text, $lineNumber) {
        $this->parsedFile = $parsedFile;
        $this->index = $index;
        $this->kind = $kind;
        $this->text = $text;
        $this->lineNumber = $lineNumber;
    }

    public function isSpace() {
        return $this->kind==T_COMMENT
        || $this->kind==T_DOC_COMMENT
        || $this->kind==T_WHITESPACE;
    }

    // returns next token
    public function next() {
        return $this->parsedFile->getTokenAt($this->index + 1);
    }

    // returns next non-space token
    public function nextNS() {
        for ($i = $this->index+1; true; ++$i) {
            $t = $this->parsedFile->getTokenAt($i);
            if ($t==null || ! $t->isSpace()) {
                return $t;
            }
        }
    }

    // returns previous token
    public function prev() {
        return $this->parsedFile->getTokenAt($this->index - 1);
    }

    // returns previous non-space token
    public function prevNS() {
        for ($i = $this->index-1; true; --$i) {
            $t = $this->parsedFile->getTokenAt($i);
            if ($t==null || ! $t->isSpace()) {
                return $t;
            }
        }
    }

    public function __toString() {
        $filename = $this->parsedFile->getFileName();
        return "'$this->text' at $filename:$this->lineNumber";
    }
}

/**
 * Plain data object class - one defect found by Lint
 * Object doesn't contain references to other objects; serialized size is small
 */
class XRef_CodeDefect {
    public $tokenText;      // string
    public $errorCode;      // string
    public $severity;       // XRef::ERROR, XRef::WARNING or XRef::NOTICE
    public $message;        // string, e.g. "variable is not declared"
    public $fileName;       // string
    public $lineNumber;     // int
    public $inClass;        // string, may be null
    public $inMethod;       // string, may be null
    public $sourceUrl;      // string, may be null, used by templates

    // helper constructors
    public static function fromTokenText($token_text, $error_code, $severity, $message_template, $message_params = null) {
        $code_defect = new XRef_CodeDefect();
        $code_defect->tokenText    = preg_replace_callback('#[^\\x21-\\x7f]#', array('self', 'chr_replace'), $token_text);
        $code_defect->errorCode    = $error_code;
        $code_defect->severity     = $severity;
        $code_defect->message      = (is_null($message_params))
                ? sprintf($message_template, $code_defect->tokenText)
                : vsprintf($message_template, $message_params);
        return $code_defect;
    }

    public static function fromToken(XRef_Token $token, $error_code, $severity, $message_template) {
        $code_defect = self::fromTokenText($token->text, $error_code, $severity, $message_template);
        $file_pos = new XRef_FilePosition($token->parsedFile, $token->index);
        $code_defect->fileName     = $token->parsedFile->getFileName();
        $code_defect->lineNumber   = $file_pos->lineNumber;
        $code_defect->inClass      = $file_pos->inClass;
        $code_defect->inMethod     = $file_pos->inMethod;
        return $code_defect;
    }

    // helper constructor
    public static function fromParseException(XRef_ParseException $e) {
        return self::fromToken($e->token, XRef::ERROR_CODE_CANT_PARSE_FILE, XRef::FATAL, XRef::ERROR_MESSAGE_CANT_PARSE_FILE);
    }

    private static function chr_replace($matches) {
        return '\\x' . sprintf('%02x', ord($matches[0]));
    }
}

/**
 * Interface for generic XRef plugins
 */
interface XRef_IPlugin {
    public function getId();                            // e.g. "xref-lint"
    public function getName();                          // e.g. "Lint report"
    public function setXRef(XRef $xref);                // link to main XRef instance
}

/**
 * Interface for plugins that generate cross-reference documentation
 */
interface XRef_IDocumentationPlugin extends XRef_IPlugin {
    public function generateFileReport(XRef_IParsedFile $pf);   // this is called for each file
    public function generateTotalReport();                      // this is called once, after all files have been analyzed
    public function getReportLink();                            // returns array("Link name" => "http://link-url", ...)
}

/**
 * Interface for plugins that check source code for errors
 */
interface XRef_ILintPlugin extends XRef_IPlugin {
    public function getErrorMap();                      // returns array (errorCode --> errorDescription)
    public function getReport(XRef_IParsedFile $pf);    // returns array of tuples (token, errorCode)
}

/**
 * Abstract class, can be used as base class for other plugins
 */
abstract class XRef_APlugin implements XRef_IPlugin {
    /** @var XRef - reference to main XRef class object */
    protected $xref;
    /** @var string - uniq id of the plugin */
    protected $reportId;
    /** @var string - text description of the plugin */
    protected $reportName;

    /**
     * Plugin classes must implement defualt constructor (constructor without params)
     * and call parent constructor with params
     */
    public function __construct($reportId, $reportName) {
        if (!$reportId || !$reportName) {
            throw new Exception("Child class must call parent constructor with arguments");
        }
        $this->reportId = $reportId;
        $this->reportName = $reportName;
    }
    public function setXRef(XRef $xref) {
        $this->xref = $xref;
    }
    public function getName() {
        return $this->reportName;
    }
    public function getId() {
        return $this->reportId;
    }
    // returns array("report name" => "url", ...)
    public function getReportLink() {
        return array($this->getName() => $this->getId().".html");
    }
}

/**
 * Abstract class, can be used as base class for Lint plugins
 */
abstract class XRef_ALintPlugin extends XRef_APlugin implements XRef_ILintPlugin {
    public function __construct($reportId, $reportName) {
        parent::__construct($reportId, $reportName);
    }

    // array of tuples (token, errorCode)
    protected $report = array();

    protected function addDefect($token, $errorCode) {
        $this->report[] = array($token, $errorCode);
    }
}


/**
 * Interface for persistent storage used by XRef CI server
 */
interface XRef_IPersistentStorage {

    /**
     * @param XRef $xref
     */
    public function setXRef(XRef $xref);

    /**
     * Method to save arbitrary data; in case of failure exeption will be thrown.
     * @param string $domain
     * @param string $key
     * @param mixed $data
     */
    public function saveData($domain, $key, $data);

    /**
     * Method to restore data from persistent storage;
     * @param string $domain
     * @param string $key
     * @return mixed
     */
    public function restoreData($domain, $key);

    /**
     * Method to create lock identified by $key
     * @param string $key
     * @return bool if the lock was acquired
     */
    public function getLock($key);

    /**
     * Method to release the lock
     * @param string $key
     */
    public function releaseLock($key);
}

/**
 * File position object - "something is called from ClassName::MethodName at file:line"
 */
class XRef_FilePosition {
    public $fileName;
    public $lineNumber;
    public $inClass;
    public $inMethod;
    public $startIndex;
    public $endIndex;

    public function __construct(XRef_IParsedFile $pf, $startIndex, $endIndex = 0) {
        $this->fileName     = $pf->getFileName();
        $this->lineNumber   = $pf->getLineNumberAt($startIndex);
        $this->startIndex   = $startIndex;
        $this->endIndex     = ($endIndex) ? $endIndex : $startIndex;

        $class  = $pf->getClassAt($startIndex);
        $method = $pf->getMethodAt($startIndex);
        $this->inClass  = ($class) ? $class->name : null;
        $this->inMethod = ($method) ? $method->name : null;
    }

    public function __toString() {
        return "$this->fileName:$this->lineNumber";
    }
}

interface XRef_ISourceCodeManager {
    public function updateRepository();
    public function getListOfBranches();                            // returns array( "branch name" => "current branch revision", ...);
    public function getListOfFiles($revision);                      // returns array("filename1", "filename2", ...)
    public function getListOfModifiedFiles($revision1, $revision2); // returns array("filename1", "filename2", ...)
    public function getFileContent($revision, $filename);           // returns the content of given file in the given revision
    public function getRevisionInfo($revision);                     // SCM-specific, returns key-value array with info for the given commit
    public function getFileProvider($revision);
}

class XRef_Namespace {
    /** @var int - index of T_NAMESPACE token*/
    public $index;
    /** @var int - line number of T_NAMESPACE token */
    public $lineNumber;
    /** @var string - e.g. 'foo\bar' or '' for global namespace */
    public $name;
    /** @var int - index of '{' or ';' token */
    public $bodyStarts;
    /** @var int - index of '}' token or the last token in file */
    public $bodyEnds;
    /** @var array - map (string -> string), e.g. ('Another' => 'My\Full\Classname') */
    public $importMap = array();
}

class XRef_Class {
    /** @var int - index of T_CLASS (T_INTERFACE or T_TRAIT) token */
    public $index;
    /** @var int - line number of T_CLASS token */
    public $lineNumber;
    /** @var int - one of T_CLASS, T_INTERFACE or T_TRAIT constants */
    public $kind;
    /** @var int - index of the token with the class name */
    public $nameIndex;
    /** @var string - fully qualified name */
    public $name;
    /** @var string[] - list of fq names of extended class (or interfaces) */
    public $extends = array();
    /** @var array - map: (name of extended class/interface) -> array(first index of name, last index of name) */
    public $extendsIndex = array();
    /** @var string[] - list of FQ names of implemented interfaces */
    public $implements = array();
    /** @var array - map: (name of implemented interface) -> array(first index of name, last index of name) */
    public $implementsIndex = array();
    /** @var string[] - list of FQ names of used traits */
    public $uses = array();
    /** @var int - index of '{' token or null */
    public $bodyStarts;
    /** @var int - index of '}' token or null */
    public $bodyEnds;
    /** @var XRef_Function[] - ordered list of all methods */
    public $methods = array();
    /** @var XRef_Property[] - list of all properties */
    public $properties = array();
    /** @var XRef_Constant[] */
    public $constants = array();
    /** @var bool */
    public $isAbstract = false;
}

// common class for functions, methods and closures (anonymous functions)
class XRef_Function {
    /** @var int - index of T_FUNCTION token */
    public $index;
    /** @var int - line number of T_FUNCTION token */
    public $lineNumber;
    /** @var string - FQ name for functions, e.g 'My\Namespace\foo', simple name for class methods, null for closures */
    public $name;
    /** @var int - index of the token with the function name */
    public $nameIndex;
    /** @var string - FQ name of class for methods, null for regular functions */
    public $className;
    /** @var int - index of '{' token (or null for function declarations) */
    public $bodyStarts;
    /** @var int - index of '}' token (or ';' token for function declarations)*/
    public $bodyEnds;
    /** @var boolean */
    public $returnsReference;
    /** @var XRef_FunctionParameter[] - ordered list of parameters */
    public $parameters = array();
    /** @var XRef_FunctionParameter[] - for closures: list of used variables */
    public $usedVariables = array();
    /** @var bool */
    public $isDeclaration;
    /** @var int - bitmask of XRef::MASK_* for methods */
    public $attributes = 0;
    // internal use only, subject to change
    public $flags = 0;
}

class XRef_FunctionParameter {
    /** @var int - the index of the token T_VARIABLE */
    public $index;
    /** @var string - e.g. $x */
    public $name;
    /** @var string - e.g. 'array' or 'Foo\Bar' or null */
    public $typeName;
    /** @var bool */
    public $isPassedByReference;
    /** @var bool */
    public $hasDefaultValue;
}

class XRef_Constant {
    /** @var int - index of T_STRING token in const declaration */
    public $index;
    /** @var int - line number of name token
     * (there may be several constants in one declaration, each may have different line number) */
    public $lineNumber;
    /** @var string - e.g. 'FOO' or 'Foo\BAR' for file constants */
    public $name;
    /** @var string - class name or null for file constants */
    public $className;
    /** @var int - bitmask of XRef::MASK_* for class constants */
    public $attributes;
}

class XRef_Property {
    /** @var int - index of T_VARIABLE token */
    public $index;
    /** @var int - line number of name token
     * (there may be several properties in one (var) declaration) */
    public $lineNumber;
    /** @var string - e.g. $foo */
    public $name;
    /** @var string - e.g. 'string' or 'ClassName' or null */
    public $typeName;
    /** @var string - FQ name of class */
    public $className;
    /** @var int - bitmask of XRef::MASK_* constants */
    public $attributes;

}

/*
 * Simple interface to list files and access their content
 */
interface XRef_IFileProvider {
    /** @param array $paths - list of paths (dir and files) that should be excluded from output */
    public function excludePaths(array $paths);
    /**
     * @return string[] - list of file names
     */
    public function getFiles();
    /**
     * @param string $filename
     * @return string
     */
    public function getFileContent($filename);
    /**
     * returns persistent id (if any) for the given set of files, e.g. revision number
     * @return string
     */
    public function getPersistentId();
}
/**
 * Class for lookup results (find a class, method, function etc).
 * Instances of this class are returned by XRef_IProjectDatabase lookup methods.
 */
class XRef_LookupResult {
    const NOT_FOUND = 0;
    const FOUND = 1;
    const CLASS_MISSING = 2;

    public $code;
    public $elements;
    public $missingClassName;

    public function __construct($code = self::NOT_FOUND, $elements = null) {
        $this->code = $code;
        $this->elements = $elements;
    }
}
/**
 * Project Database - main source of info about classes, methods, constants
 * defined in project.
 */
interface XRef_IProjectDatabase {

    // methods to create database
    public function createFileSlice(XRef_IParsedFile $pf);
    public function addFileSlice($file_name, $slice);
    public function finalize();

    // query methods
    /** @return XRef_Class[] */
    public function getAllClasses();

    /**
     * @param string $class_name - Fully-qualified class name
     * @return XRef_LookupResult
     */
    public function lookupClass($class_name);

    /** @return XRef_LookupResult */
    public function lookupMethod($class_name, $method_name, $parent_class_only = false);

    /** @return XRef_LookupResult */
    public function lookupConstant($class_name, $const_name, $parent_class_only = false);

    /** @return XRef_LookupResult */
    public function lookupProperty($class_name, $prop_name, $parent_class_only = false);
}

interface XRef_IProjectLintPlugin {
    /**
     * @abstract
     * @return array - map (errorCode => errorDescription)
     */
    public function getErrorMap();

    /**
     * Parsing of files is expensive, so minimize them between runs of xref.
     * Instead, parse file once and process it into summary (database & plugin slices),
     * all of which will be serialized and stored.
     *
     * DB slices will make up project database and will be all in memory,
     * so try to keep their size to minimum
     *
     * File summary will be (iteratively) available to lint plugin.
     * TODO: right now all of them are still in memory
     *
     * @param XRef_ParsedFile $pf
     * @param bool $is_library_file
     * @return * any data
     */
    public function createFileSlice(XRef_IParsedFile $pf, $is_library_file = false);

    public function startLintCheck(XRef_IProjectDatabase $db);
    public function checkFileSlice(XRef_IProjectDatabase $db, $file_name, $file_slice);
    /** @return array - map (file name -> list of errors) */
    public function getProjectReport(XRef_IProjectDatabase $db);
}

interface XRef_ILintEngine {
    /** methods to use when parsed files are already available */
    public function addParsedFile(XRef_IParsedFile $pf);
    public function collectReport();

    /** optimized method - may use caching etc */
    public function getReport(XRef_IFileProvider $file_provider);

    /** optimized method for incremental mode */
    public function getIncrementalReport(XRef_IFileProvider $from, XRef_IFileProvider $to, $list_of_modified_files);
}

// vim: tabstop=4 expandtab
