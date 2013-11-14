<?php

/**
 * Implementation of PHP and ActionScript parsers
 * Implementation of ParsedFile interface
 *
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

class XRef_ParseException extends Exception {
    /** @var XRef_Token */
    public $token;

    function __construct(XRef_Token $token, $expected_text = null) {
        $this->token = $token;
        $filename = $token->parsedFile->getFileName();
        $message = ($expected_text)
                ? "Found '$token->text' instead of $expected_text at $filename:$token->lineNumber"
                : "Found '$token->text' at $filename:$token->lineNumber";
        parent::__construct($message);
    }
}

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
                        } elseif ($strtoupper == 'GOTO') {
                            if (isset(XRef::$compatMode["T_GOTO"])) {
                                $t[0] = T_GOTO;
                            }
                        }
                    }
                }
            }
        } // foreach token

        // without this unset(), in PHP 5.2 $t will still reference the last element of $tokens array,
        // which may be overwritten by the next loop
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

        return new XRef_ParsedFile_PHP($tokens, $filename);
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


class XRef_ParsedFile_PHP implements XRef_IParsedFile {
    /** @var string */
    protected $filename;

    /** @var XRef_Token[] */
    protected $tokens;
    protected $numberOfLines;
    protected $fileType;
    protected $tokensCount;

    // list of declared classes, interfaces and traits
    // array of XRef_Class objects
    protected $classes = array();

    // list of all functions (including closures) and methods
    // array of XRef_Function objects
    /** @var XRef_Function[] */
    protected $functions = array();

   // list of all functions (including closures) and methods
    // array of XRef_Function objects
    /** @var XRef_Constant[] */
    protected $constants = array();

    // list of all namespaces, array of XRef_Namespace objects
    protected $namespaces = array();

    // map:     (index of opening "{") --> (index of closing "{")
    //          (index of closing "]") --> (index of opening "[")
    //          ...
    protected $pairedBrackets = array();

    public function __construct(array &$tokens, $filename) {
        $this->filename = $filename;
        $this->fileType = XRef::FILETYPE_PHP;
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
        $this->tokensCount = count($this->tokens);
        $this->matchBrackets();
        $this->parseFileContent();
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
        if ($index < 0 || $index >= $this->tokensCount) {
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

    // search for object(s) with $o->bodyStarts <= $index <= $o->bodyEnds
    // and return the most deeply enclosed object
    // e.g., for closure inside a function return the closure object
    // TODO: replace linear search by faster algorithm
    protected function getObjectAt(&$objectList, $index) {
        $found_object = null;
        foreach ($objectList as $o) {
            if (!$o->bodyStarts) {
                continue; // function declaration without body, etc
            }
            if ($o->bodyStarts > $index) {
                break;
            }
            if ($o->bodyEnds >= $index) {
               $found_object = $o;
            }
        }
        return $found_object;
    }

    /**
     * returns array of all classes, defined in this file
     * @return XRef_Function[]
     */
    public function &getClasses() {
        return $this->classes;
    }

    /**
     * returns array of all declared/defined functions and methods, including anonymous functions
     * @return XRef_Function[]
     */
    public function &getMethods()
    {
        return $this->functions;
    }

    /**
     * returns array of all constants, including class constants
     * @return XRef_Constant[]
     */
    public function &getConstants()
    {
        return $this->constants;
    }

    /**
     * @return XRef_Namespace
     */
    public function getNamespaceAt($index) {
        return $this->getObjectAt($this->namespaces, $index);
    }

    /**
     * @param  $index int
     * @return XRef_Class
     */
    public function getClassAt($index) {
        return $this->getObjectAt($this->classes, $index);
    }

    /**
     * @param  $index int
     * @return XRef_Function
     */
    public function getMethodAt($index) {
        return $this->getObjectAt($this->functions, $index);
    }

    protected function matchBrackets() {
        $bracket_count = 0;
        $stack = array();
        for ($i = 0; $i < $this->tokensCount; ++$i) {
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

                $stack[] = (object) array(
                    "index"         => $i,
                    "bracket_count" => $bracket_count,
                    "lineNumber"    => $t->lineNumber,
                    "expect"        => $expect_bracket,
                    "token"         => $t,
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
                    throw new XRef_ParseException($t, "Unmatched closing bracket");
                }
            }
        }

        if (count($stack)) {
            throw new XRef_ParseException($stack[0]->token, "Unmatched opening bracket");
        }
    }

    private $index;
    private function reset() {
        $this->index = 0;
    }

    /** get token at current position */
    private function current() {
        // NOTE: hot method, duplicate code from getTokenAt
        // return $this->getTokenAt($this->index);
        $index = $this->index;
        if ($index < 0 || $index >= $this->tokensCount) {
            return null;
        } else {
            return $this->tokens[$index];
        }
    }

    /** advance $index pointer to next non-space token and return it */
    private function next() {
        // NOTE: hot method, duplicate code
        // $this->index++; return $this->current();
        $this->index++;
        $index = $this->index;
        if ($index < 0 || $index >= $this->tokensCount) {
            return null;
        } else {
            return $this->tokens[$index];
        }
    }

    /** advance $index pointer to next non-space token and return it */
    private function nextNS() {
        // $t = $this->next();
        // while ($t && $t->isSpace()) {
        //     $t = $this->next();
        // }
        // return $t;

        $this->index++;
        $t = null;
        while ($this->index < $this->tokensCount) {
            $t = $this->tokens[$this->index];
            if (!$t->isSpace()) {
                break;
            }
            $this->index++;
        }
        return $t;

    }

    /**
     * very basic LL(1) parser that is interested in subset of PHP constructs
     */
    protected function parseFileContent() {
        for ($this->index = 0; $this->index < $this->tokensCount; /**nop*/ ) {
            $t = $this->current();

            if ($t->kind == T_NAMESPACE) {
                $this->parseNamespace();
                continue;
            }

            if ($t->kind == T_USE) {
                $this->parseImportedNamespaces();
                continue;
            }

            // functions
            if ($t->kind == T_FUNCTION) {
                $this->parseFunction();
                continue;
            }

            // classes
            if ($t->kind == T_CLASS || $t->kind == T_INTERFACE || $t->kind == T_TRAIT) {
                $this->parseClass();
                continue;
            }

            // constants
            if ($t->kind == T_CONST) {
                $this->parseConstant();
                continue;
            }

            // everything else - just skip till the next non-space token
            $this->nextNS();
        }
    }

    // declared namespaces
    //  namespace foo\bar { ... }
    //  namespace { ... }
    //  namespace foo\bar;
    protected function parseNamespace() {
        $t = $this->current();
        if ($t->kind != T_NAMESPACE) {
            throw new XRef_ParseException($t, "namespace");
        }

        $namespace = new XRef_Namespace();
        $namespace->index = $t->index;
        $namespace->lineNumber = $t->lineNumber;

        $this->nextNS();
        $name = $this->parseTypeName();
        $n = $this->current();
        if ($n->text == ';') {
            // namespace till the next namespace declaration
            // if there is an unfinished namespace, close it
            $openNamespace = $this->getNamespaceAt($t->index);
            if ($openNamespace) {
                $openNamespace->bodyEnds = $namespace->index - 1;
            }
            $namespace->bodyStarts  = $t->index;
            $namespace->bodyEnds    = $this->tokensCount;
            $namespace->name = $name;
        } elseif ($n->text == '{') {
            // namespace till the closing '}'
            $namespace->bodyStarts  = $t->index;
            $namespace->bodyEnds    = $this->getIndexOfPairedBracket( $n->index );
            $namespace->name = $name;
        } else {
            throw new XRef_ParseException($t, "';' or '{'");
        }
        $this->namespaces[] = $namespace;
        $this->nextNS();    // skip the ';' or '}'
    }

    // imported namespaces
    //  use foo\bar as foobar, baz as another_baz;
    //  use foo\bar;
    protected function parseImportedNamespaces() {
        $t = $this->current();
        if ($t->kind != T_USE) {
            throw new XRef_ParseException($t, "use");
        }

        while (true) {
            $this->nextNS();
            $name = $this->parseTypeName();
            if (substr($name, 0, 1) == '\\') {
                // not recommended, but commonly used:
                // use \Foo\Bar as Alias;
                // all namespaces are global anyway and not related to current namespace
                $name = substr($name, 1);
            }

            $t = $this->current();

            if ($t->kind == T_AS) {
                $t = $this->nextNS();
                $alias_name = $t->text;
                $t = $this->nextNS();
            } else {
                $parts = explode('\\', $name);
                $alias_name = $parts[ count($parts)-1 ];
            }

            $currentNamespace = $this->getNamespaceAt($t->index);
            if (!$currentNamespace) {
                if (!$this->namespaces) {
                    // importing into the global namespace,
                    // ok, create one
                    $namespace = new XRef_Namespace();
                    $namespace->bodyStarts = 1;
                    $namespace->bodyEnds = $this->tokensCount;
                    $namespace->name = null;
                    $this->namespaces[] = $namespace;
                    $currentNamespace = $namespace;
                } else {
                    // importing outside a namespace?
                    throw new XRef_ParseException($t);
                }
            }

            $currentNamespace->importMap[$alias_name] = $name;

            if ($t->text == ';') {
                break;
            } elseif ($t->text == ',') {
                continue;
            } else {
                throw new XRef_ParseException($t, "';' or ','");
            }
        }
        $this->nextNS(); // skip the ';'
    }

    // class Foo extends \Bar\Baz implements A, B;
    // class Foo {}
    private function parseClass() {
        $t = $this->current();
        if ($t->kind != T_CLASS && $t->kind != T_INTERFACE && $t->kind != T_TRAIT) {
            throw new XRef_ParseException($t);
        }

        $class = new XRef_Class();
        $class->index = $t->index;
        $class->kind = $t->kind;
        $class->lineNumber = $t->lineNumber;

        // ugly part: backtracking
        $p = $t->prevNS();
        if ($p->kind == T_ABSTRACT) {
            $class->isAbstract = true;
        }

        $t = $this->nextNS();
        if ($t->kind == T_STRING) {
            $class->name = $this->qualifySimpleName($t->text, $t->index);
            $class->nameIndex = $t->index;
        } else {
            throw new XRef_ParseException($t);
        }

        $t = $this->nextNS();
        if ($t->kind == T_EXTENDS) {
            // classes have no multiple inheritance in php,
            // but this code parses interfaces too, and they have
            while (true) {
                $this->nextNS();    // skip "extends" or ","
                $t = $this->current();
                $startIndex = $t->index;
                $extends = $this->parseTypeName();
                $t = $this->current();
                $endIndex = $t->prevNS()->index;
                $name = $this->qualifyName($extends, $t->index);
                $class->extends[] = $name;
                $class->extendsIndex[$name] = array($startIndex, $endIndex);
                if ($t->text != ',') {
                    break;
                }
            }
        }

        $t = $this->current();
        if ($t->kind == T_IMPLEMENTS) {
            while (true) {
                $this->nextNS();    // skip "implements" or ","
                $t = $this->current();
                $startIndex = $t->index;
                $implements = $this->parseTypeName();
                $t = $this->current();
                $endIndex = $t->prevNS()->index;
                $name = $this->qualifyName($implements, $t->index);
                $class->implements[] = $name;
                $class->implementsIndex[$name] = array($startIndex, $endIndex);
                if ($t->text != ',') {
                    break;
                }
            }
        }

        $this->classes[] = $class;

        $t = $this->current();
        $this->nextNS();
        if ($t->text == '{') {
            $class->bodyStarts  = $t->index;
            $class->bodyEnds    = $this->getIndexOfPairedBracket($t->index);
            $this->parseClassBody($class, $class->bodyEnds);
        } else {
            throw new XRef_ParseException($t);
        }
    }

    private function parseClassBody(XRef_Class $class, $lastIndex) {
        $attributes = 0;

        while ($this->index < $lastIndex) {
            $t = $this->current();

            if ($t->kind == T_PUBLIC    ||
                $t->kind == T_PRIVATE   ||
                $t->kind == T_STATIC    ||
                $t->kind == T_PROTECTED ||
                $t->kind == T_ABSTRACT  ||
                $t->kind == T_FINAL
            ) {
                $attributes |= XRef::$attributesMasks[ $t->kind ];
                $t = $this->nextNS();
                continue;
            }

            // functions
            if ($t->kind == T_FUNCTION) {
                $this->parseFunction($class, $attributes);
                $attributes = 0;
                continue;
            }

            // constants
            if ($t->kind == T_CONST) {
                $this->parseConstant($class, $attributes);
                $attributes = 0;
                continue;
            }

            if ($t->kind == T_VAR) {
                $t = $this->nextNS();
                if ($t->kind != T_VARIABLE) {
                    throw new XRef_ParseException($t);
                }
                // do nothing here, the next iteration will take care of $t
                // continue;
            }

            if ($t->kind == T_VARIABLE) {
                while (true) {
                    $this->parseClassProperty($class, $attributes);
                    $t = $this->current();
                    if ($t->text == ',') {
                        $this->nextNS();
                    } elseif ($t->text == ';') {
                        $this->nextNS();
                        break;
                    } else {
                        throw new XRef_ParseException($t);
                    }
                }
                $attributes = 0;
                continue;
            }

            // used traits:
            // use TraitA;
            // use TraitB, TraitC { TraitB::foo insteadof TraitC; }
            // use TraitD { foo as d_doo }
            if ($t->kind == T_USE) {
                $t = $this->nextNS();
                while (true) {
                    $trait_name = $this->parseTypeName();
                    if (!$trait_name) {
                        throw new XRef_ParseException($t);
                    }
                    $class->uses[] = $this->qualifyName($trait_name, $t->index);

                    $t = $this->current();
                    if ($t->text == ';') {
                        $this->nextNS();
                        break;
                    }
                    if ($t->text == '{') {
                        // TODO: parse the trait insteadof/as block
                        $this->index = $this->getIndexOfPairedBracket( $t->index );
                        $this->nextNS();
                        break;
                    }
                    if ($t->text == ',') {
                        $this->nextNS();
                        continue;
                    }

                    // shouldn't be here
                    throw new XRef_ParseException($t);
                }
                continue;
            }

            // shouldn't be here
            throw new XRef_ParseException($t);

        }

        $t = $this->current();
        if ($t->index != $lastIndex) {
            throw new XRef_ParseException($t, "$t->index != $lastIndex");
        }
        if ($t->text != '}') {
            throw new XRef_ParseException($t, "'}'");
        }
        $this->nextNS();    // skip the '}'
    }

    private function parseClassProperty(XRef_Class $class, $attributes) {
        $t = $this->current();
        if ($t->kind != T_VARIABLE) {
            throw new XRef_ParseException($t);
        }

        $prop = new XRef_Property();
        $prop->name = substr($t->text, 1); // strip the leading '$' sign
        $prop->index = $t->index;
        $prop->attributes = $attributes;
        $prop->lineNumber = $t->lineNumber;
        $prop->className = $class->name;
        $class->properties[] = $prop;

        $t = $this->nextNS();
        if ($t->text == '=') {
            // TODO: parse const expression
            $t = $this->nextNS();
            while ($t->text != ';' && $t->text != ',') {
                $t = $this->nextNS();
                if ($t->text == '(') {
                    $this->index = $this->getIndexOfPairedBracket($t->index);
                    $t = $this->nextNS();
                }
            }
        }
    }


    private function parseConstant(XRef_Class $class = null, $attributes = null) {
        $t = $this->current();
        if ($t->kind != T_CONST) {
            throw new Exception($t);
        }
        $t = $this->nextNS();

        while (true) {
            if ($t->kind != T_STRING) {
                throw new XRef_ParseException($t);
            }

            $const = new XRef_Constant;
            $const->index = $t->index;
            $const->lineNumber = $t->lineNumber;
            $const->attributes = $attributes;
            $this->constants[] = $const;
            if ($class) {
                $const->name = $t->text;
                $const->className = $class->name;
                $class->constants[] = $const;
            } else {
                $const->name = $this->qualifySimpleName($t->text, $t->index);
            }

            $t = $this->nextNS();
            if ($t->text != '=') {
                throw new XRef_ParseException($t);
            }

            // skip the constant value
            while ($t->text != ',' && $t->text != ';') {
                $t = $this->nextNS();
            }

            if ($t->text == ';') {
                $this->nextNS();    // skip the ';'
                break;
            }
            $t = $this->nextNS();
        }
    }

    private function parseFunction(XRef_Class $class = null, $attributes = null) {
        $t = $this->current();
        if ($t->kind != T_FUNCTION) {
            throw new Exception($t);
        }

        $function = new XRef_Function();
        $function->index = $t->index;
        $function->lineNumber = $t->lineNumber;
        $function->attributes = $attributes;

        $t = $this->nextNS();
        if ($t->text == '&') {
            $t = $this->nextNS();
            $function->returnsReference = true;
        } else {
            $function->returnsReference = false;
        }

        $function->nameStartIndex = $t->index; // TODO: remove this
        if ($t->kind == T_STRING) {
            $function->nameIndex = $t->index;
            if ($class) {
                $function->name = $t->text;
                $function->className = $class->name;
                $class->methods[] = $function;
            } else {
                $function->name = $this->qualifySimpleName( $t->text, $t->index );
            }
            $t = $this->nextNS();
        } else if ($t->text == '(') {
            // anonymous function
            $function->name = '';
        } else {
            throw new XRef_ParseException($t);
        }

        // list of parameters/arguments
        $parameters = $this->parseFunctionParametersList($t);
        $function->parameters = $parameters;
        $t = $this->current();

        // closures: T_USE
        if (!$function->name && $t->kind == T_USE) {
            $this->nextNS();
            $parameters = $this->parseFunctionParametersList($t);
            $function->usedVariables = $parameters;
        }

        $t = $this->current();
        if ($t->text == ';') {
            $function->isDeclaration= true;
            $function->bodyEnds     = $t->index;
        } else if ($t->text == '{') {
            $function->bodyStarts   = $t->index;
            $function->bodyEnds     = $this->getIndexOfPairedBracket($t->index);
            $function->isDeclaration= false;
        } else {
            throw new XRef_ParseException($t);
        }

        $this->functions[] = $function;
        $this->nextNS();    // skip the ';' or '{'

        // parse function body, if any
        if (!$function->isDeclaration) {
            $this->parseFunctionBody($function->bodyEnds);
        }
    }

    private function parseFunctionBody($closingTokenIndex) {
        while ($this->index < $closingTokenIndex) {
            $t = $this->current();

            // functions
            if ($t->kind == T_FUNCTION) {
                $this->parseFunction();
                continue;
            }

            // classes
            if ($t->kind == T_CLASS || $t->kind == T_INTERFACE || $t->kind == T_TRAIT) {
                $this->parseClass();
                continue;
            }

            // constants
            if ($t->kind == T_CONST) {
                $this->parseConstant();
                continue;
            }

            // everything else - just skip till the next non-space token
            $this->nextNS();
        }

        $t = $this->current();
        if ($t->index != $closingTokenIndex) {
            throw new XRef_ParseException($t);
        }
        if ($t->text != '}') {
            throw new XRef_ParseException($t);
        }
        $this->nextNS();    // skip the '}'
    }

    // ( $a, FooBar &$b, $c = null, $d = array(1, array(2), 3) )
    private function parseFunctionParametersList() {
        $t = $this->current();
        if ($t->text != '(') {
            throw new XRef_ParseException($t, "'('");
        }
        $parameters = array();

        $t = $this->nextNS();
        while ($t->text != ')') {

            $parameter = new XRef_FunctionParameter();
            $type_name = $this->parseTypeName();
            $parameter->typeName = $this->qualifyName($type_name, $t->index);
            $t = $this->current();
            if ($t->text == '&') {
                $parameter->isPassedByReference = true;
                $t = $this->nextNS();
            }
            if ($t->kind != T_VARIABLE) {
                throw new XRef_ParseException($t, 'variable');
            }
            $parameter->name = $t->text;
            $parameter->index = $t->index;
            $t = $this->nextNS();
            if ($t->text == '=') {
                $parameter->hasDefaultValue = true;
                $t = $this->nextNS();
                // TODO: need a better parser for default values
                while ($t->text != ',' && $t->text != ')') {
                    $t = $this->nextNS();
                    if ($t->text == '(') {
                        $this->index = $this->getIndexOfPairedBracket($t->index);
                        $t = $this->nextNS();
                    }
                }
            }
            $parameters[] = $parameter;

            if ($t->text == ',') {
                $this->nextNS();
                continue;
            } elseif ($t->text == ')') {
                break;
            } else {
                throw new XRef_ParseException($t, "')' or ','");
            }
        }
        $this->nextNS();    // skip the ')'

        return $parameters;
    }

    // input: $token
    // output: string $typeName
    // side effects: sets the cursor to the non-space token after the type name
    // e.g.:
    //      string $x ...   --> 'string',   cursor == $x
    //      \Foo\bar $y ... --> '\Foo\bar', cursor == $y
    //      $z              --> '',         cursor isn't changed ($z)
    private function parseTypeName() {
        $name_parts = array();
        $t = $this->current();
        if ($t->isSpace()) {
            $t = $this->nextNS();
        }
        while ($t->kind == T_STRING || $t->kind == T_NS_SEPARATOR || $t->kind == T_ARRAY) {
            $name_parts[] = $t->text;
            $t = $this->next();    // not nextNS()!
        }
        if ($t->isSpace()) {
            $t = $this->nextNS();
        }
        return implode('', $name_parts);
    }

    // input: simple or complex name, e.g. "Foo", "Foo\Bar" or "namespace\Foo"
    // output: fully-qualified name, e.g. "My\NameSpace\Foo" or "Imported\Bar"
    public function qualifyName($name, $index) {
        if (!$name) {
            return $name;
        }

        if ($name == 'self' || $name == 'parent' || $name == 'static') {
            return $name;
        }

        if (substr($name, 0, 1)== '\\') {
            // this is an absolute name, '\foo\bar' --> 'foo\bar'
            return substr($name, 1);
        } else {
            // relative name
            $namespace = $this->getNamespaceAt($index);
            if (!$namespace) {
                // no namespace and no import map
                return $name;
            } else {
                $parts = explode('\\', $name);
                if ($parts[0] == 'namespace') {
                    $parts[0] = $namespace->name;
                } elseif (isset($namespace->importMap[ $parts[0] ])) {
                    $parts[0] = $namespace->importMap[ $parts[0] ];
                } else {
                    if ($namespace->name) {
                        // not default namespace
                        array_unshift($parts, $namespace->name);
                    }
                }
                return implode('\\', $parts);
            }
        }
    }

    // optimization, see also qualifyName()
    // input: simple type name, e.g. 'Foo'
    // output: fully-qualified name, e.g. 'My\NameSpace\Foo'
    private function qualifySimpleName($name, $index) {
        // special keywords/names
        if ($name == 'self' || $name == 'parent' || $name == 'static') {
            return $name;
        }

        $namespace = $this->getNamespaceAt($index);
        if (!$namespace || !$namespace->name) {
            // name is in global namespace
            return $name;
        } else {
            return $namespace->name . '\\' . $name;
        }
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
