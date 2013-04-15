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
 * SYNOPSYS
 *
 * <code>
 *      require_once "XRef/XRef.class.php";
 *
 *      $fileName = "test.php";
 *      $fileContent = file_get_contents($fileName);
 *
 *      try {
 *          $parser = new XRef_IFileParser_Implementation(...);
 *          $parsedFileObject = $parser->parse($fileContent, $fileName);
 *      } catch (Exception $e) {
 *          echo "Can't parse $filename: " . $e->getMessage() . "\n";
 *      }
 *
 *      // tokens
 *      $tokens = $parsedFileObject->getTokens(); // array of tokens
 *      foreach ($tokens as $token) {
 *          echo $token->text;
 *      }
 *
 *      // tokens API: print names of all functions
 *      // function foo(...)
 *      foreach ($tokens as $t) {
 *          if ($t->kind==T_FUNCTION) {
 *              $functionNameToken = $t->nextNS(); // next non-space token
 *              echo $functionNameToken->text, "\n";
 *          }
 *      }
 *
 *      // high-level details: classes and methods
 *      foreach ($parsedFileObject->getClasses() as $className => $classDetails) {
 *          $startingLineNumber = $parsedFileObject->getLineNumberAt( $classDetails->startIndex );
 *          $endingLineNumber = $parsedFileObject->getLineNumberAt( $classDetails->endIndex );
 *
 *          echo "Class $className is between lines $startingLineNumber and $endingLineNumber\n";
 *      }
 *
 *      // braces and parenthesis
 *      for ($i=0; $i<count($tokens); ++$i) {
 *              $t = $tokens[$i];
 *              if ($t->text == '(') {
 *                  $closingParenthesis = $parsedFileObject()
 *              }
 *      }
 *
 *      // at the end: clean-up the memory
 *      $parsedFileObject->release();
 * </code>
 *
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
     * returns class name for the token index $index
     * @param int $index
     * @return string|null
     */
    public function getClassAt($index);

    /**
     * returns method name at the token index $index
     * @param int $index
     * @return string|null
     */
    public function getMethodAt($index);

    /**
     * returns array of all declared/defined methods
     * @return XRef_NamedStatement[]
     */
    public function &getMethods();

    /**
     * returns array of all classes
     * @return XRef_NamedStatement[]
     */
    public function &getClasses();

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

    // helper constructor
    public static function fromToken($token, $errorCode, $severity, $message) {
        $filePos = new XRef_FilePosition($token->parsedFile, $token);
        $codeDefect = new XRef_CodeDefect();
        $codeDefect->tokenText    = $token->text;
        $codeDefect->errorCode    = $errorCode;
        $codeDefect->severity     = $severity;
        $codeDefect->message      = $message;
        $codeDefect->fileName     = $token->parsedFile->getFileName();
        $codeDefect->lineNumber   = $filePos->lineNumber;
        $codeDefect->inClass      = $filePos->inClass;
        $codeDefect->inMethod     = $filePos->inMethod;
        return $codeDefect;
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
 * Interface for plugins that generates documentation
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
    /** reference to main XRef class object */
    protected $xref;
    /** string, uniq id of the plugin */
    protected $reportId;
    /** string, text description of the plugin */
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
 * data structure for definitions/declarations of classes, methods etc.
 * "funciton" name ... "{" ... "}"
 * "class" name ... "{" ... "}"
 */
class XRef_NamedStatement {
    public $name;
    public $kind;           // matched token kind, e.g. T_CLASS
    public $startIndex;
    public $endIndex;
    public $nameStartIndex; // for PHP, nameStartIndex==nameEndIndex
    public $nameEndIndex;   // in AS3, name may be of several tokens: package foo.bar.baz;
    public $isDeclaration;
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

    public function __construct(XRef_IParsedFile $pf, $s) {
        $this->fileName     = $pf->getFileName();

        if (is_a($s, "XRef_NamedStatement")) {
            $this->lineNumber   = $pf->getLineNumberAt( $s->startIndex );
            $this->startIndex   = $s->nameStartIndex;
            $this->endIndex     = $s->nameEndIndex;
        } elseif (is_a($s, "XRef_Token")) {
            $this->lineNumber   = $pf->getLineNumberAt( $s->index );
            $this->startIndex   = $s->index;
            $this->endIndex     = $s->index;
        } else {
            throw new Exception("Can't instantiate XRef_FilePosition from object of class " . get_class($s));
        }
        $this->inClass  = $pf->getClassAt($this->startIndex);
        $this->inMethod = $pf->getMethodAt($this->startIndex);
    }
}

interface XRef_ISourceCodeManager {
    public function updateRepository();
    public function getListOfBranches();                            // returns array( "branch name" => "current branch revision", ...);
    public function getListOfFiles($revision);                      // returns array("filename1", "filename2", ...)
    public function getListOfModifiedFiles($revision1, $revision2); // returns array("filename1", "filename2", ...)
    public function getFileContent($revision, $filename);           // returns the content of given file in the given revision
    public function getRevisionInfo($revision);                     // SCM-specific, returns key-value array with info for the given commit
}

// vim: tabstop=4 expandtab
