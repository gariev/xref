<?php

//TODO: separate storage/update/initialization logic and error-finding logic in 2 classes (?)

class ProjectLintPrototype extends XRef_APlugin {

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

    public function loadOrCreateProject($revision) {
        $storage_manager = $this->xref->getStorageManager();
        $source_code_manager = $this->xref->getSourceCodeManager();
        $this->provides = array();
        $this->uses = array();

        // get the list of files for this project
        $this->projectFiles = $storage_manager->restoreData("project-check", $revision);
        if (is_null($this->projectFiles)) {
            error_log("igariev: Project $revision is not found");
            $this->projectFiles = array();
            $filenames = $source_code_manager->getListOfFiles($revision);
            foreach ($filenames as $filename) {
                if (!preg_match("#\\.php\$#", $filename)) {
                    continue;
                }
                $this->projectFiles[ $filename ] = null;    // temporary, see 'updateFile' below
            }
        } else {
            error_log("Project $revision found");
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
                    error_log("igariev: parsing file $filename ($new_revision)");
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
                list($class_name, $key, $name, $line_number) = $u;
                if (!$this->isDefined($class_name, $key, $name)) {
                    $cd = new XRef_CodeDefect();
                    $cd->tokenText = $name;
                    $cd->errorCode = "exp02";
                    $cd->severity = ($key=='method') ? XRef::ERROR : XRef::WARNING;
                    $cd->message = "Access to undefined $key";
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
    private function isDefined($class_name, $key, $name) {
        if (!isset($this->classes[$class_name])) {
            return true; // actually, should return "unknown"
        }

        foreach ($this->classes[$class_name] as $c) {
            if (isset($c[$key]) && isset($c[$key][$name])) {
                return true;
            }
        }
        foreach ($this->classes[$class_name] as $c) {
            if (isset($c['extends'])) {
                foreach ($c['extends'] as $parent_class_name) {
                    if ($this->isDefined($parent_class_name, $key, $name)) {
                        return true;
                    }
                }
            }
        }
        return false;
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
                $i = $t->index;
            }
        }
        return $classes;
    }

    private function collectUsedConstructs(XRef_IParsedFile $pf) {
        $used = array();            // className, kind (method|property|const), name
        $already_seen = array();    // report each used construct only once
        $tokens = $pf->getTokens();
        for ($i=0; $i<count($tokens); ++$i) {
            $t = $tokens[$i];
            if ($t->text == '$this') {
                $n = $t->nextNS();
                if ($n->text == '->') {
                    $n = $n->nextNS();
                    if ($n->kind == T_STRING) {
                        $name = $n->text;
                        $n = $n->nextNS();
                        if ($n->text == '(') {
                            $key = 'method';
                            $name = strtolower($name);
                        } else {
                            $key = 'property';
                        }
                        $class_name = $pf->getClassAt( $t->index );
                        if (!$class_name) {
                            throw new Exception($t);
                        }
                        $uniq_key = "$class_name##$key##$name";
                        if (!isset($already_seen[$uniq_key])) {
                            $already_seen[$uniq_key] = true;
                            $used[] = array($class_name, $key, $name, $t->lineNumber);
                        }
                    }
                }
            }
        }
        return $used;
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
            'constants'     => array(),
            'property'    => array(),
            'method'       => array(),
        );

        while (true) {
            if ($t->text == '}') {
                $t = $t->nextNS();
                break;
            }

            while ($t->kind == T_PUBLIC || $t->kind == T_PRIVATE || $t->kind == T_STATIC || $t->kind == T_PROTECTED || $t->kind == T_ABSTRACT || $t->kind==T_FINAL) {
                $t = $t->nextNS();
            }

            if ($t->kind == T_CONST) {
                $t = $t->nextNS();
                list($t, $c) = $this->parseConstant($t);
                $content['constants'][ $c['name'] ] = $c;
                while ($t->text == ',') {
                    $t = $t->nextNS();
                    list($t, $c) = $this->parseConstant($t);
                    $content['constants'][ $c['name'] ] = $c;
                }
                if ($t->text == ';') {
                    $t = $t->nextNS();
                } else {
                    throw new Exception("$t");
                }
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
                    list ($t, $var) = $this->parseProperties($t);
                    $property_name = substr($var['name'], 1);   // skip '$' sign
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
            } else if ($t->kind == T_FUNCTION) {
                list ($t, $f) = $this->parseMethods($pf, $t);
                $content['method'][ strtolower($f['name']) ] = $f;
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

    private function parseProperties($t) {
        if ($t->kind != T_VARIABLE) {
            throw new Exception($t);
        }
        $name = $t->text;
        $t = $t->nextNS();
        if ($t->text == '=') {
            // TODO: parse const expression
            while ($t->text != ';') {
                $t = $t->nextNS();
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


