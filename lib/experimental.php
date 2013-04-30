<?php

//TODO: separate storage/update/initialization logic and error-finding logic in 2 classes (?)

class ProjectLintPrototype extends XRef_APlugin {

    private static $masks = array(
        T_PUBLIC    => 1,
        T_PROTECTED => 2,
        T_PRIVATE   => 4,
        T_STATIC    => 8,
        T_ABSTRACT  => 16,
        T_FINAL     => 32,
    );

    /** map: file name --> array with classes, consts, method etc that this file provides */
    private $provides = array( );

    /** map: file name --> array with used constructs */
    private $uses = array();

    /** map: class name -> array with all classes of this name */
    private $classes = array();

    /** map: file name --> sha1 sum of the file content */
    private $projectFiles = array();

    /** list of file names for files we've had to parse */
    private $filesToSave = array();

    private $projectName;

    public function __construct() {
        parent::__construct("project-check", "Cross-reference integrity check");
    }

    public function clearProject() {
        $this->provides = array();
        $this->uses = array();
    }

    public function loadOrCreateProject($revision) {
        $this->clearProject();
        $storage_manager = $this->xref->getStorageManager();
        $source_code_manager = $this->xref->getSourceCodeManager();

        // get the list of files for this project
        $this->projectFiles = $storage_manager->restoreData("project-check", $revision);
        if (is_null($this->projectFiles)) {
            $this->projectFiles = array();
            $filenames = $source_code_manager->getListOfFiles($revision);
            foreach ($filenames as $filename) {
                if (!preg_match("#\\.php\$#", $filename)) {
                    continue;
                }
                $this->projectFiles[ $filename ] = null;    // temporary, see 'updateFile' below
            }
        }

        // load the parsed data for each file,
        // or parse file if the data is missing
        $files_to_load = array();
        foreach ($this->projectFiles as $filename => $shasum) {
            if (!$shasum || !$this->loadFile($filename, $shasum)) {
                $files_to_load[] = $filename;
            }
        }
        foreach ($files_to_load as $filename) {
            $this->updateFile($revision, $filename);
        }
    }

    private function loadFile($filename, $shasum) {
        if (!$shasum) {
            return false;
        }
        $storage_manager = $this->xref->getStorageManager();
        $data = $storage_manager->restoreData("project-files", $shasum);
        if ($data && isset($data["xrefVersion"]) && $data["xrefVersion"] == XRef::version()) {
            if ($data["provides"]) {
                $this->provides[ $filename ] = $data["provides"];
            }
            if ($data["uses"]) {
                $this->uses[ $filename ] = $data["uses"];
            }
            return true;
        } else {
            return false;
        }
    }

    public function updateFile($new_revision, $filename) {
        $source_code_manager = $this->xref->getSourceCodeManager();
        $content = $source_code_manager->getFileContent($new_revision, $filename);
        if (!$content) {
            unset($this->projectFiles[$filename]);
            unset($this->provides[$filename]);
            unset($this->uses[$filename]);
        } else {
            $shasum = sha1($content);
            $this->projectFiles[$filename] = $shasum;
            if (!$this->loadFile($filename, $shasum)) {
                try {
                    $pf = $this->xref->getParsedFile($filename, "php", $content);
                    $this->addFile($pf);
                    $pf->release();
                } catch (Exception $e) {
                    error_log($e->getMessage());
                }
                $data = array(
                    "filename"      => $filename,
                    "version"       => $new_revision,
                    "xrefVersion"   => XRef::version(),
                    "provides"      => isset($this->provides[$filename])    ? $this->provides[$filename]    : null,
                    "uses"          => isset($this->uses[$filename])        ? $this->uses[$filename]        : null,
                );
                $storage_manager = $this->xref->getStorageManager();
                $storage_manager->saveData("project-files", $shasum, $data);
            }
        }
    }

    public function saveProject($revision) {
        $storage_manager = $this->xref->getStorageManager();
        $storage_manager->saveData("project-check", $revision, $this->projectFiles);
    }

    public function addFile(XRef_IParsedFile $pf) {
        $file_name = $pf->getFileName();
        $this->provides[$file_name] = $this->parseClasses($pf);
        $this->uses[$file_name] = $this->collectUsedConstructs($pf);
    }

    public function getErrors() {
        $this->classes = array();
        $errors = array(); // map: fileName --> array of XRef_CodeDefect objects

        $class_defined_in_file = array();
        foreach ($this->provides as $file_name => $list_of_classes) {
            foreach ($list_of_classes as $class_description) {
                $class_name = $class_description['name'];
                $this->classes[$class_name][] = $class_description;
                $class_defined_in_file[$class_name][] = $file_name;
            }
        }

        // are there classes defined twice?
        foreach ($class_defined_in_file as $class_name => $list_of_files) {
            if (count($list_of_files) > 1) {
                $cd = new XRef_CodeDefect();
                $cd->tokenText = $class_name;
                $cd->errorCode = "exp01";
                $cd->severity = XRef::WARNING;
                $cd->message = "Class is defined more than once"; // . implode(", ", $list_of_files);
                $cd->fileName = "(project)";
                $cd->lineNumber = 0;
                $errors[ $cd->fileName ][] = $cd;
            }
        }

        // are there missing classes or base classes?
        // TODO

        // are there references to missed methods/properties?
        foreach ($this->uses as $file_name => $usage_list) {
            foreach ($usage_list as $u) {
                list($class_name, $key, $name, $line_number, $from_class, $is_static) = $u;
                $e = $this->checkAccessError($class_name, $key, $name, $from_class, $is_static);
                if ($e) {
                    list($error_code, $severity, $message) = $e;
                    $cd = new XRef_CodeDefect();
                    $cd->tokenText = $name;
                    $cd->errorCode = $error_code;
                    $cd->severity = $severity; //($key=='method' || $key=='constant') ? XRef::ERROR : XRef::WARNING;
                    $cd->message = $message; //"Access to undefined $key of class $class_name";
                    $cd->fileName = $file_name;
                    $cd->lineNumber = $line_number;
                    $errors[ $cd->fileName ][] = $cd;
                }
            }
        }
        ksort($errors); // sort by the file names
        foreach ($errors as $filename => &$errors_list) {
            usort($errors_list, array("self", "sort_by_line_number_and_token"));
        }
        return $errors;
    }

    private static function sort_by_line_number_and_token($a, $b) {
        if ($a->lineNumber <  $b->lineNumber) {
            return -1;
        } else if ($a->lineNumber >  $b->lineNumber) {
            return 1;
        }
        return strcmp($a->tokenText, $b->tokenText);
    }

    // is $key( = 'property|method') named $name defined in class $class_name?
    // returns array(error_code, error_message)
    private function checkAccessError($class_name, $key, $name, $from_class, $is_static) {
        $d = $this->getDefinition($class_name, $key, $name);
        if ($d === TRUE) {
            // definition not found because definition of base class is missing
        } elseif (!$d) {
            // definition not found
            $found = false;
            if ($key=='property') {
                $d = $this->getDefinition($class_name, 'method', '__get');
                if ($d) {
                    $found = true;
                }
            }
            if (!$found) {
                $error_code = "exp02";
                $severity = ($key=='method' || $key=='constant') ? XRef::ERROR : XRef::WARNING;
                $message = "Access to undefined $key of class $class_name";
                return array($error_code, $severity, $message);
            }
        } else {
            // got definition, check access
            list($found_in_class, $def) = $d;

            // 1. static vs. instance
            if ($key != 'constant') {
                if ($is_static) {
                    if ( ($def["mask"] & self::$masks[T_STATIC]) == 0 ) {
                        $error_code = "exp03";
                        $severity = XRef::ERROR;
                        $message = "Trying to access instance $key as static one";
                        return array($error_code, $severity, $message);
                    }
                } else {
                    if ( ($def["mask"] & self::$masks[T_STATIC]) != 0 && $key == 'property') {
                        $error_code = "exp03";
                        $severity = XRef::ERROR;
                        $message = "Trying to access static $key as instance one";
                        return array($error_code, $severity, $message);
                    }
                }
            }

            // 2. public, private, protected
            if ($def["mask"] & self::$masks[T_PUBLIC]) {
                // ok
            } elseif ($def["mask"] & self::$masks[T_PRIVATE]) {
                if ($found_in_class != $from_class) {
                    $error_code = "exp04";
                    $severity = XRef::ERROR;
                    $message = "Attempt to access private $key of class $found_in_class";
                    return array($error_code, $severity, $message);
                }
            } elseif ($def["mask"] & self::$masks[T_PROTECTED]) {
                if ($found_in_class != $from_class && (!$from_class || !$this->isSubclassOf($from_class, $found_in_class))) {
                    $error_code = "exp04";
                    $severity = XRef::ERROR;
                    $message = "Attempt to access protected $key of class $found_in_class";
                    return array($error_code, $severity, $message);
                }
            } else {
                // default access == public
            }
        }
        return;
    }

    private function isSubclassOf($child_class, $parent_class) {
        if ($child_class == $parent_class) {
            return true;
        }

        // if a class is unknown, or any of the parent classes is missing,
        // assume that child_class may be the child of the parent_class
        if (!isset($this->classes[$child_class])) {
            return true;
        }

        foreach ($this->classes[$child_class] as $c) {
            if (isset($c['extends'])) {
                foreach ($c['extends'] as $parent_class_name) {
                    if ($this->isSubclassOf($parent_class_name, $parent_class)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    // finds a definition of method/property of the given class in class hierarchy
    // input:
    //  e.g ('Foo', 'method', 'bar') for Foo::bar()
    // output:
    //  array(class_name, $definition) if found
    //  true if missing some of base classes
    //  null if definitely can't found
    private function getDefinition($class_name, $key, $name) {
        if (!isset($this->classes[$class_name])) {
            return true;
        }
        if ($key == 'method') {
            $name = strtolower($name);
        }
        foreach ($this->classes[$class_name] as $c) {
            if (isset($c[$key]) && isset($c[$key][$name])) {
                return array($class_name, $c[$key][$name]);
            }
        }
        foreach ($this->classes[$class_name] as $c) {
            if (isset($c['extends'])) {
                foreach ($c['extends'] as $parent_class_name) {
                    $d = $this->getDefinition($parent_class_name, $key, $name);
                    if ($d) {
                        return $d;
                    }
                }
            }
        }
        return null;
    }

    // input: XRef_IParsedFile object
    // output: array of all classes defined in this file
    private function parseClasses(XRef_IParsedFile $pf) {
        $classes = array();
        $tokens = $pf->getTokens();
        for ($i=0; $i<count($tokens); ++$i) {
            $t = $tokens[$i];

            // class ...
            if ($t->kind == T_CLASS || $t->kind == T_INTERFACE || $t->kind==T_TRAIT) {
                $class_descr = array();
                $class_descr['kind'] = token_name($t->kind);
                $t = $t->nextNS();

                // get name
                if ($t->kind != T_STRING) {
                    throw new Exception($t);
                }
                $name = $t->text;
                $t = $t->nextNS();
                $class_descr['name'] = $name;

                // extends ...
                if ($t->kind == T_EXTENDS) {
                    $t = $t->nextNS();
                    list($t, $extendsList) = $this->extractCommaSeparatedStringsList($t);
                    $class_descr['extends'] = $extendsList;
                }

                // implements
                if ($t->kind == T_IMPLEMENTS) {
                    $t = $t->nextNS();
                    list($t, $implementsList) = $this->extractCommaSeparatedStringsList($t);
                    $class_descr['implements'] = $implementsList;
                }

                if ($t->text == '{') {
                    $t = $t->nextNS();
                    list($t, $content) = $this->parseClassContent($pf, $t);
                    foreach ($content as $k => $v) {
                        $class_descr[$k] = $v;
                    }
                } elseif ($t->text == ';') {
                    $t = $t->nextNS();
                } else {
                    throw new Exception($t);
                }

                $classes[] = $class_descr;

                if (!$t) {
                    break;
                }
                $i = $t->index - 1;
            }
        }
        return $classes;
    }

    // report each used construct only once
    private $already_seen = array();
    // className, kind (method|property|const), name
    private $used = array();

    private function addUsedConstruct($class_name, $key, $name, $line_number, $from_class, $is_static) {
        $uniq_key = "$class_name##$key##$name##$from_class##$is_static";
        if (!isset($this->already_seen[$uniq_key])) {
            $this->already_seen[$uniq_key] = true;
            $this->used[] = array($class_name, $key, $name, $line_number, $from_class, $is_static);
        }
    }

    private function collectUsedConstructs(XRef_IParsedFile $pf) {
        $this->used = array();
        $this->already_seen = array();

        $tokens = $pf->getTokens();
        for ($i=0; $i<count($tokens); ++$i) {
            $t = $tokens[$i];

            // $this->foo();
            // $this->bar;
            if ($t->text == '$this') {
                $n = $t->nextNS();
                if ($n->text == '->') {
                    $n = $n->nextNS();
                    if ($n->kind == T_STRING) {
                        $name = $n->text;
                        $n = $n->nextNS();
                        $class_name = $pf->getClassAt( $t->index );
                        if (!$class_name) {
                            continue;
                        }
                        $key = ($n->text == '(') ? 'method' : 'property';
                        $this->addUsedConstruct($class_name, $key, $name, $t->lineNumber, $class_name, false);
                    }
                }
                continue;
            }

            // Foo::bar();
            if ($t->kind == T_STRING) {
                $n = $t->nextNS();
                if ($n->kind == T_DOUBLE_COLON) {

                    // TODO: parent:: class, static::, etc
                    $class_name = $t->text;
                    $from_class = $pf->getClassAt( $t->index );
                    if ($class_name == 'self') {
                        $class_name = $from_class;
                    }

                    $n = $n->nextNS();
                    if ($n->kind == T_STRING) {
                        $nn = $n->nextNS();
                        if ($nn->text == '(') {
                            // Foo::bar()
                            $this->addUsedConstruct($class_name, 'method', $n->text, $t->lineNumber, $from_class, true);
                        } else {
                            // Foo:BAR - constant?
                            $const_name = $n->text;
                            $this->addUsedConstruct($class_name, 'constant', $n->text, $t->lineNumber, $from_class, true);
                        }
                    } elseif ($n->kind == T_VARIABLE) {
                        // Foo::$bar
                        $property_name = substr($n->text, 1);   // skip '$' sign
                        $this->addUsedConstruct($class_name, 'property', $property_name, $t->lineNumber, $from_class, true);
                    } else {
                        error_log($n);
                    }
                    continue;
                }
            }
        }
        return $this->used;
    }

    private function extractCommaSeparatedStringsList($t) {
        if ($t->kind != T_STRING && $t->kind != T_NS_SEPARATOR) {
            throw new Exception($t);
        }

        $list = array();
        while (true) {
            $parts = array();
            while ($t->kind==T_STRING || $t->kind == T_NS_SEPARATOR) {
                $parts[] = $t->text;
                $t = $t->nextNS();
            }
            $list[] = implode($parts);
            if ($t->text != ',') {
                break;
            }
            $t = $t->nextNS();
        }
        return array($t, $list);
    }

    private function parseClassContent($pf, $t) {
        $content = array(
            'constant'  => array(),
            'property'  => array(),
            'method'    => array(),
        );

        $mask = 0;
        while (true) {
            if ($t->text == '}') {
                $t = $t->nextNS();
                break;
            }

            while ($t->kind == T_PUBLIC || $t->kind == T_PRIVATE || $t->kind == T_STATIC || $t->kind == T_PROTECTED || $t->kind == T_ABSTRACT || $t->kind==T_FINAL) {
                $mask |= self::$masks[ $t->kind ];
                $t = $t->nextNS();
            }

            if ($t->kind == T_CONST) {
                $t = $t->nextNS();
                list($t, $c) = $this->parseConstant($t);
                $c["mask"] = $mask;
                $content['constant'][ $c['name'] ] = $c;
                while ($t->text == ',') {
                    $t = $t->nextNS();
                    list($t, $c) = $this->parseConstant($t);
                    $c["mask"] = $mask;
                    $content['constant'][ $c['name'] ] = $c;
                }
                if ($t->text == ';') {
                    $t = $t->nextNS();
                } else {
                    throw new Exception("$t");
                }
                $mask = 0;
            } else if ($t->kind == T_VAR) {
                $t = $t->nextNS();
                if ($t->kind != T_VARIABLE) {
                    throw new Exception($t);
                }
                // do nothing here,
                // the next iteration will fall in the next if()
            } else if ($t->kind == T_VARIABLE) {
                while (true) {
                    if ($t->kind != T_VARIABLE) {
                        throw new Exception($t);
                    }
                    list ($t, $var) = $this->parseProperties($pf, $t);
                    $property_name = substr($var['name'], 1);   // skip '$' sign
                    $var["mask"] = $mask;
                    $content['property'][$property_name] = $var;
                    if ($t->text == ';') {
                        $t = $t->nextNS();
                        break;
                    } elseif ($t->text == ',') {
                        $t = $t->nextNS();
                    } else {
                        throw new Exception($t);
                    }
                }
                $mask = 0;
            } else if ($t->kind == T_FUNCTION) {
                list ($t, $f) = $this->parseMethods($pf, $t);
                $f["mask"] = $mask;
                $content['method'][ strtolower($f['name']) ] = $f;
                $mask = 0;
            } else {
                throw new Exception($t);
            }
        }
        return array($t, $content);
    }

    private function parseConstant($t) {
        if ($t->kind != T_STRING) {
            throw new Exception("$t");
        }
        $name = $t->text;
        $t = $t->nextNS();

        if ($t->text != '=') {
            throw new Exception("$t");
        }
        $t = $t->nextNS();

        $value = $t->text;
        $t = $t->nextNS();  // TODO: value can be an expression, not a single literal
        while ($t->text != ',' && $t->text != ';') {
            $t = $t->nextNS();
        }
        $constant = array('name' => $name, 'value' => $value);
        return array($t, $constant);
    }

    private function parseProperties($pf, $t) {
        if ($t->kind != T_VARIABLE) {
            throw new Exception($t);
        }
        $name = $t->text;
        $t = $t->nextNS();
        if ($t->text == '=') {
            // TODO: parse const expression
            while ($t->text != ';' && $t->text != ',') {
                $t = $t->nextNS();
                if ($t->text == '(') {
                    $t = $pf->getTokenAt( $pf->getIndexOfPairedBracket($t->index) );
                }
            }
        }
        $var = array('name' => $name, 'defaultValue' => '');
        return array($t, $var);
    }

    private function parseMethods($pf, $t) {
        if ($t->kind != T_FUNCTION) {
            throw new Exception($t);
        }
        $t = $t->nextNS();

        if ($t->text == '&') {
            $t = $t->nextNS();
        }

        if ($t->kind != T_STRING) {
            throw new Exception($t);
        }
        $name = $t->text;
        $t = $t->nextNS();

        if ($t->text != '(') {
            throw new Exception($t);
        }
        // TODO: parse argument list here
        $t = $pf->getTokenAt( $pf->getIndexOfPairedBracket($t->index) );
        $t = $t->nextNS();

        if ($t->text == ';') {
            $t = $t->nextNS();
        } else if ($t->text == '{') {
            $t = $pf->getTokenAt( $pf->getIndexOfPairedBracket($t->index) );
            $t = $t->nextNS();
        } else {
            throw new Exception();
        }
        $function = array('name' => $name);
        return array($t, $function);
    }
}


