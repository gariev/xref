<?php

/**
 * Implementation of PHP and ActionScript parsers
 * Implementation of ParsedFile interface
 *
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */


class XRef_Parser_PHP implements XRef_IFileParser {
    public function parse($file_content, $filename) {
        $file_content = preg_replace("#\\r\\n#", "\n", $file_content);
        $file_content = preg_replace("#\\r#", "\n", $file_content);

        // @ is to silence warnings when php 5.2 runtime parses code for php 5.3,
        // especially namespace foo\bar;
        $tokens = @token_get_all($file_content);

        // compat mode: parse PHP 5.3+ code in old PHP 5.2 rte :(
        $namespace_compat_mode = false;

        if (XRef::$compatMode) {
            $count = count($tokens);
            for ($i=0; $i<$count; ++$i) {
                $t = & $tokens[$i];
                if (!is_string($t)) {
                    list ($kind, $text) = $t;
                    if ($kind==T_STRING) {
                        $strtoupper = strtoupper($text);
                        if ($strtoupper == 'USE') {
                            if (isset(XRef::$compatMode["T_USE"])) {
                                // compat mode: in PHP 5.3 rte use is reserved word and should be T_USE, not T_STRING
                                $t[0] = T_USE;
                            }
                        } elseif ($strtoupper == 'NAMESPACE') {
                            if (isset(XRef::$compatMode["T_NAMESPACE"]) && self::isAtStartOfExpression($tokens, $i)) {
                                $t[0] = T_NAMESPACE;
                                $namespace_compat_mode = true;
                            }
                        } elseif ($strtoupper == 'TRAIT') {
                            if (isset(XRef::$compatMode["T_TRAIT"]) && self::isAtStartOfExpression($tokens, $i)) {
                                $t[0] = T_TRAIT;
                            }
                        }
                    }
                }
            }
        } // foreach token

        // without this unset(), in PHP 5.2 $t will still reference the last element of $tokens array,
        // which may be overwriteen by the next loop
        unset($t);

        if ($namespace_compat_mode) {
            // special pass to insert "\" namespace separator dropped by PHP 5.2 executable
            // e.g. in "namespace foo\bar";
            $tmp_array = array();
            $is_inside_namespace_decl = false;
            $is_before_first_ns_element = true;

            foreach ($tokens as $t) {
                if ($is_inside_namespace_decl) {
                    if (!is_string($t)) {
                        list ($kind, $text) = $t;
                        if ($kind==T_STRING) {
                            if ($is_before_first_ns_element) {
                                $is_before_first_ns_element = false;
                            } else {
                                $tmp_array[] = array(T_NS_SEPARATOR, '\\');
                            }
                        } elseif ($kind==T_WHITESPACE || $kind==T_COMMENT || $kind==T_DOC_COMMENT) {
                            // nop
                        } else {
                            $is_inside_namespace_decl = false;
                        }
                    } else {
                        $is_inside_namespace_decl = false;
                    }
                } else {
                    if (!is_string($t)) {
                        list ($kind, $text) = $t;
                        if ($kind==T_NAMESPACE) {
                            $is_inside_namespace_decl = true;
                            $is_before_first_ns_element = true;
                        }
                    }
                }
                $tmp_array[] = $t;
            }

            $tokens = $tmp_array;
        }

        return new XRef_ParsedFile($tokens, $filename, XRef::FILETYPE_PHP);
    }

    public function getSupportedFileExtensions() {
        return array("php");
    }

    // returns true if token at position $i is a first token
    // of an expression, i.e. if it follows ';', '}', (end of previous expression or block)
    // or '{' (start of the new block) or php opening tag
    private static function isAtStartOfExpression(&$tokens, $i) {
        // backtracking
        for ($j = $i-1; $j>=0; --$j) {
            $t = $tokens[$j];
            if (is_string($t)) {
                return ($t == ';' || $t == '}' || $t == '{');
            } else {
                list ($kind, $text) = $t;
                if ($kind==T_WHITESPACE || $kind==T_COMMENT || $kind==T_DOC_COMMENT) {
                    continue;
                }
                return $kind==T_OPEN_TAG;
            }
            return false;
        }
    }
}

class XRef_Parser_AS3 extends XRef_Parser_PHP {

    public function parse($file_content, $filename) {
        // a hack:
        // tokens (keywords, strings, comments etc) are similar in php and as3,
        // so we can use php's builtin tokenizer to parse as3
        $file_content = preg_replace("#\\r\\n#", "\n", $file_content);
        $file_content = preg_replace("#\\r#", "\n", $file_content);

        // at this point, no \r are left in source code
        // insert a space symbol (\r) into opening/closing php tags, if any
        $file_content = preg_replace("#<\\?#", "<\r?", $file_content);
        $file_content = preg_replace("#\\?>#", "?\r>", $file_content);

        $tokens = @token_get_all("<" . "?php $file_content ?" . ">");
        array_shift($tokens);
        array_pop($tokens);
        array_pop($tokens);

        // remove space \r chars from tokens
        $tokens = array_filter($tokens, "XRef_Parser_AS3::nonSlashR");

        // php tokenizer fails on AS regular expression syntax (/regex here/)
        // Extra pass here catches and re-assembles regexps from PHP tokens
        // TODO: this is ugly, is there better solution?
        // TODO: PHP v 5.2 and 5.3 fails differently; parsing of AS with regexp tokens is not guaranteed :(
        $tmp_tokens = array();
        $current_re_token = '';

        for($i=0; $i<count($tokens); ++$i) {
            $t = &$tokens[$i];
            if ($current_re_token) {

                // $t is part of the re token
                if (is_string($t)) {
                    $current_re_token .= $t;
                } else {
                    list ($kind, $text) = $t;
                    $current_re_token .= $text;
                }

                // is it the end of the current re token?
                if (is_string($t) && $t=='/') {
                    $tmp_tokens[] = array(XRef::T_REGEXP, $current_re_token);
                    $current_re_token = null;
                }

            } else {
                // $t is outside of re token
                if (is_string($t) && $t=='/') {
                    // is this a start of new RE token?
                    //      = /regex...
                    //      str.replace( /regex...
                    //      (list, /regex...

                    // find the previous token
                    for ($j=$i-1; $j>=0; --$j) {
                        $p = &$tokens[$j];
                        // skip whitespaces
                        if (!is_string($p) && ($p[0]==T_WHITESPACE || $p[0]==T_COMMENT || $p[0]==T_DOC_COMMENT)) {
                            continue;
                        }

                        if (is_string($p) && ($p=="=" || $p=="(" || $p=="," || $p=="[")) {
                            $current_re_token = $t;
                        }

                        break;
                    }
                }

                // still outside of re token
                if (!$current_re_token) {
                    $tmp_tokens[] = $t;
                }
            }
        }
        $tokens = $tmp_tokens;

        foreach ($tokens as &$t) {
            if (!is_string($t)) {
                list ($kind, $text) = $t;
                if ($kind==T_FUNCTION && $text!="function") {
                    // e.g. listener:Function
                    $t[0] = T_STRING;
                } elseif ($kind==T_ARRAY && $text!="array") {
                    // e.g. new Array()
                    $t[0] = T_STRING;
                } elseif ($kind==T_LIST && $text!="list") {
                    $t[0] = T_STRING;
                } elseif ($kind==T_INTERFACE && $text!="interface") {
                    $t[0] = T_STRING;
                } elseif ($kind==T_CLASS && $text!="class") {
                    $t[0] = T_STRING;
                } elseif ($kind==T_GLOBAL && $text!="global") {
                    $t[0] = T_STRING;
                } elseif ($kind==T_STRING) {
                    switch ($text) {
                        case "package":
                            $t[0] = XRef::T_PACKAGE;
                            break;
                        case "import":
                            $t[0] = XRef::T_IMPORT;
                            break;
                        case "override":
                            $t[0] = XRef::T_OVERRIDE;
                            break;
                        case "in":
                            $t[0] = XRef::T_IN;
                            break;
                        case "each":
                            $t[0] = XRef::T_EACH;
                            break;
                        case "null":
                            $t[0] = XRef::T_NULL;
                            break;
                        case "get":
                            $t[0] = XRef::T_GET;
                            break;
                        case "set":
                            $t[0] = XRef::T_SET;
                            break;
                        case "true":
                            $t[0] = XRef::T_TRUE;
                            break;
                        case "false":
                            $t[0] = XRef::T_FALSE;
                            break;
                        default:
                    }
                } // if kind==string
            } // if token is (kind, text)
        } // foreach token

        return new XRef_ParsedFile($tokens, $filename, XRef::FILETYPE_AS3);
    }

    public function getSupportedFileExtensions() {
        return array("as");
    }

    // hate PHP 5.2 - no anonymous functions
    static function nonSlashR($t) { return is_array($t) || $t!="\r"; }
}

class XRef_ParsedFile implements XRef_IParsedFile {
    protected $filename;
    protected $tokens;
    protected $numberOfLines;
    protected $fileType;

    // list of declared classes
    // map: class name --> XRef_NamedStatement object
    protected $classes; // classes, abstract classes and interfaces
    protected $methods;
    protected $packages;
    protected $namespaces;

    // map:     (index of opening "{") --> (index of closing "{")
    //          (index of closing "]") --> (index of opening "[")
    //          ...
    protected $pairedBrackets = array();

    public function __construct(array &$tokens, $filename, $fileType) {
        $this->filename = $filename;
        $this->fileType = $fileType;
        $this->tokens = array();

        $lineNumber = 1;
        $index = 0;
        foreach ($tokens as $native_token) {
            if (is_string($native_token)) {
                $kind = XRef::T_ONE_CHAR;
                $text = $native_token;
            } else {
                list($kind, $text) = $native_token;
            }
            $t = new XRef_Token($this, $index, $kind, $text, $lineNumber);
            $this->tokens[$index++] = $t;
            $lineNumber += substr_count($text, "\n");
        }
        $this->numberOfLines = $lineNumber;

        $this->matchBrackets();

        // find out classes and function boundaries
        $this->classes = array_merge(
            $this->extractNamedStatements(T_CLASS),
            $this->extractNamedStatements(T_INTERFACE),
            $this->extractNamedStatements(T_TRAIT)
        );

        $this->methods =
            $this->extractNamedStatements(T_FUNCTION);

        // No namespaces before 5.3
        if (defined("T_NAMESPACE")) {
            $this->namespaces =
                $this->extractNamedStatements(T_NAMESPACE);
        }

        $this->packages =
            $this->extractNamedStatements(XRef::T_PACKAGE);
    }

    public function getFileType() {
        return $this->fileType;
    }
    public function getFileName() {
        return $this->filename;
    }

    public function &getTokens() {
        return $this->tokens;
    }

    public function getTokenAt($index) {
        if ($index<0 || $index>=count($this->tokens)) {
            return null;
        } else {
            return $this->tokens[$index];
        }
    }

    public function getLineNumberAt($index) {
        $t = $this->getTokenAt($index);
        if ($t!=null) {
            return $t->lineNumber;
        } else {
            return -1;
        }
    }

    protected function getObjectAt(&$objectList, $index) {
        foreach ($objectList as $o) {
            if ($o->startIndex<=$index && $index<=$o->endIndex) {
                return $o->name;
            }
        }
        return;

    }

    public function getClassAt($index) {
        return $this->getObjectAt($this->classes, $index);
    }

    public function getMethodAt($index) {
        return $this->getObjectAt($this->methods, $index);
    }

    protected function matchBrackets() {
        $bracket_count = 0;
        $stack = array();
        for ($i = 0; $i < count($this->tokens); ++$i) {
            $t = $this->tokens[$i];

            // opening brackets
            if (
                ($t->kind==XREF::T_ONE_CHAR
                    && ($t->text=="{" || $t->text=="("  || $t->text=="["))
                || $t->kind==T_CURLY_OPEN                   // {$
                || $t->kind==T_DOLLAR_OPEN_CURLY_BRACES)    // ${
            {
                if ($t->text=='(') {
                    $expect_bracket = ')';
                } elseif ($t->text=='[') {
                    $expect_bracket = ']';
                } else {
                    $expect_bracket = '}';
                }
                // hate PHP - the commented code doesn't work as code above
                //$expect_bracket =
                //  ($t->text=="(") ? ')' :
                //  ($t->text=="[") ? ']' : '}';

                $stack[] = (object) array(
                    "index"         => $i,
                    "bracket_count" => $bracket_count,
                    "lineNumber"    => $t->lineNumber,
                    "expect"        => $expect_bracket,
                    "text"          => $t->text,
                );
                $bracket_count++;
                continue;
            }

            // closing brackets
            if ($t->kind==XREF::T_ONE_CHAR
                    && ($t->text=="}" || $t->text==")" || $t->text=="]"))
            {
                $bracket_count--;
                $top = array_pop($stack);
                if ($top!=null && $top->bracket_count == $bracket_count && $top->expect==$t->text) {
                    $this->pairedBrackets[$top->index] = $i;
                    $this->pairedBrackets[$i] = $top->index;
                } else {
                    throw new Exception("Unmatched closing bracket '$t->text' at $this->filename:" . $t->lineNumber);
                }
            }
        }

        if (count($stack)) {
            $t = $stack[0];
            throw new Exception("Unmatched opening bracket '$t->text' at $this->filename: $t->lineNumber");
        }
    }

    // extracts classes, interfaces, namespaces and functions, e.g.:
    //      class       "name" ... "{" ... "}"
    //      function    "name" ... ";"
    protected function extractNamedStatements($tokenKind) {

        $result = array();
        $isWaitingForName = false;
        $isWaitingForCurly = false;
        $statement = new XRef_NamedStatement();

        for ($i = 0; $i < count($this->tokens); ++$i) {
            $t = $this->tokens[$i];

            if ($t->kind == $tokenKind) {
                $statement->startIndex = $i;
                $statement->kind = $t->kind;
                $isWaitingForName = true;
                continue;
            }

            if ($isWaitingForName && !$t->isSpace()) {
                if ($tokenKind==T_FUNCTION && $this->fileType==XRef::FILETYPE_PHP) {
                    // php specific - function &asdf(), skip &
                    if ($t->text=='&') {
                        continue;
                    }
                }

                if ($tokenKind==T_FUNCTION && $this->fileType==XRef::FILETYPE_AS3) {
                    // as3 specific - anonymous functions,
                    // skip completely
                    if ($t->text=='(') {
                        $isWaitingForName = false;
                        $isWaitingForCurly = false;
                        continue;
                    }
                    // as3 - function getters: function get foo(
                    if (($t->kind==XRef::T_GET || $t->kind==XRef::T_SET)
                            && $t->nextNS()!= null
                            && $t->nextNS()->kind==T_STRING)
                    {
                        continue;
                    }
                }

                $statement->name = $t->text;
                $statement->nameStartIndex = $i;
                $statement->nameEndIndex = $i;
                $isWaitingForName = false;
                $isWaitingForCurly = true;
                continue;
            }

            if ($isWaitingForCurly && $t->kind==XRef::T_ONE_CHAR && ($t->text=="{" ||  $t->text==";")) {
                $statement->isDeclaration = ($t->text==";"); // declared methods vs. defined;
                $statement->endIndex = ($t->text=="{") ? $this->pairedBrackets[$i] : $i;
                if (!$statement->endIndex) {
                    throw new Exception("No matched curly for $i");
                }
                $result[] = $statement;
                $statement = new XRef_NamedStatement();
                $isWaitingForCurly = false;
            }
        }

        return $result;
    }

    public function extractList($startToken, $separatorString=",", $terminatorString=")") {
        $result = array();

        $t = $startToken;
        while ($t->text != $terminatorString) {
            $result[] = $t;
            while ($t->text != $separatorString && $t->text != $terminatorString) {
                // advance to the next separator,
                if ($t->text == '(') {
                    // fast forward to matching ")", if needed
                    $t = $this->getTokenAt( $this->getIndexOfPairedBracket( $t->index ) );
                }
                if ($t->text=='"') {
                    // trick: strings with variables in them are several tokens, scan till the end of the string
                    $t = $t->nextNS();
                    while ($t->text != '"') {
                        $t = $t->nextNS();
                    }
                }
                $t = $t->nextNS();
            }
            if ($t->text == $separatorString) {
                $t = $t->nextNS();
            }
        }
        return $result;
    }

    public function &getMethods() {
        return $this->methods;
    }

    public function &getClasses() {
        return $this->classes;
    }

    public function getNumberOfLines() {
        return $this->numberOfLines;
    }

    public function release() {
        unset($this->tokens);
    }

    public function getIndexOfPairedBracket($index) {
        if (isset($this->pairedBrackets[$index])) {
            return $this->pairedBrackets[$index];
        } else {
            throw new Exception("No matching bracket for index $index");
        }
    }
}

// vim: tabstop=4 expandtab
