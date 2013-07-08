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

    private $internalClasses = null;

    public function __construct() {
        parent::__construct("project-check", "Cross-reference integrity check");
        $this->addInternalClasses();
    }

    public function clearProject() {
        $this->provides = array();
        $this->uses = array();
        $this->addInternalClasses();
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
                    $pf = $this->xref->getParsedFile($filename, $content);
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
        $this->provides[$file_name] = $pf->getClasses();
        $this->uses[$file_name] = $this->collectUsedConstructs($pf);
    }

    public function addFiles(XRef_IFileProvider $file_provider) {
        foreach ($file_provider->getFiles() as $filename) {
            try {
                $pf = $this->xref->getParsedFile($filename);
                $this->addFile($pf);
                $pf->release(); // help PHP garbage collector to free memory
            } catch(Exception $e) {
                error_log("Can't process file '$filename': " . $e->getMessage() . "\n" . $e->getLine() . "\n");
            }
        }
    }

    public function addLibraryFile(XRef_IParsedFile $pf) {
        $file_name = $pf->getFileName();
        $this->provides[$file_name] = $pf->getClasses();
        $this->uses[$file_name] = array();
    }

    public function addLibraryFiles(XRef_IFileProvider $file_provider = null) {
        if ($file_provider) {
            foreach ($file_provider->getFiles() as $filename) {
                try {
                    $pf = $this->xref->getParsedFile($filename);
                    $this->addLibraryFile($pf);
                    $pf->release(); // help PHP garbage collector to free memory
                } catch(Exception $e) {
                    error_log("Can't process file '$filename': " . $e->getMessage() . "\n" . $e->getLine() . "\n");
                }
            }
        }
    }

    private function addInternalClasses() {
        if (! $this->internalClasses) {
            $this->internalClasses = array();
            foreach (get_declared_classes() as $class_name) {
                $rc = new ReflectionClass($class_name);
                if ($rc->isInternal()) {
                    $this->internalClasses[] = $this->getClass($rc);
                }
            }
            foreach (get_declared_interfaces() as $class_name) {
                $rc = new ReflectionClass($class_name);
                if ($rc->isInternal()) {
                    $this->internalClasses[] = $this->getClass($rc);
                }
            }
        }
        $this->provides["<internal>"] = $this->internalClasses;
    }


    private function getClass(ReflectionClass $rc) {
        $class_name = $rc->getName();
        $rrc = new ReflectionClass("ReflectionClass");
        $has_traits = $rrc->hasMethod("isTrait");

        $c = new XRef_Class;
        $c->index = -1;
        $c->nameIndex = -1;
        $c->bodyStarts = -1;
        $c->bodyEnds = -1;

        if ($rc->isInterface()) {
            $c->kind = T_INTERFACE;
        } elseif ($has_traits && $rc->isTrait()) {
            $c->kind = T_TRAIT;
        } else {
            $c->kind = T_CLASS;
        }
        $c->name = $class_name;

        $parent_class = $rc->getParentClass();
        $c->extends     = ($parent_class) ? array( $parent_class->getName() ) : array();
        $c->implements  = $rc->getInterfaceNames();
        $c->uses        = ($has_traits) ? $rc->getTraitNames() : array();


        foreach ($rc->getMethods() as $rm) {
            $m = $this->getMethod($rm);
            $m->className = $class_name;
            $c->methods[] = $m;
        }
        foreach ($rc->getConstants() as $name => $value) {
            $const = $this->getConstant($name);
            $const->className = $class_name;
            $c->constants[] = $const;
        }
        foreach ($rc->getProperties() as $rp) {
            $p = $this->getProperty($rp);
            $p->className = $class_name;
            $c->properties[] = $p;
        }

        return $c;
    }

    private function getMethod(ReflectionMethod $rm) {
        $m = new XRef_Function();
        $m->name = $rm->getName();
        $m->index = $m->bodyStarts = $m->bodyEnds = $m->nameIndex = $m->nameStartIndex = -1;
        $m->isDeclaration = false;
        $m->attributes = $this->getAttributes($rm, true);
        $m->returnsReference = $rm->returnsReference();
        return $m;
    }

    private function getConstant($name) {
        $const = new XRef_Constant();
        $const->name = $name;
        $const->index = -1;
        $const->attributes = 0;
        return $const;
    }

    private function getProperty(ReflectionProperty $rp) {
        $p = new XRef_Property();
        $p->name = $rp->getName();
        $p->attributes = $this->getAttributes($rp, false);
        return $p;
    }


    private function getAttributes($r, $isMethod = false) {
        $attributes = 0;
        if ($r->isPrivate()) {
            $attributes |= XRef::MASK_PRIVATE;
        }
        if ($r->isProtected()) {
            $attributes |= XRef::MASK_PROTECTED;
        }
        if ($r->isPublic()) {
            $attributes |= XRef::MASK_PUBLIC;
        }
        if ($r->isStatic()) {
            $attributes |= XRef::MASK_STATIC;
        }
        if ($isMethod && $r->isFinal()) {
            $attributes |= XRef::MASK_FINAL;
        }
        if ($isMethod && $r->isAbstract()) {
            $attributes |= XRef::MASK_ABSTRACT;
        }
        return $attributes;
    }

    public function getErrors() {
        $this->classes = array();
        $errors = array(); // map: fileName --> array of XRef_CodeDefect objects
        $seen_errors = array();

        $class_defined_in_file = array();
        foreach ($this->provides as $file_name => $list_of_classes) {
            foreach ($list_of_classes as $class) {
                $class_name = strtolower($class->name);
                $this->classes[$class_name][] = $class;
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
                list($class_name, $key, $name, $line_number, $from_class, $is_static, $check_parent_only) = $u;
                $e = $this->checkAccessError($class_name, $key, $name, $from_class, $is_static, $check_parent_only);
                if ($e) {
                    list($error_code, $severity, $message, $uniq) = $e;
                    if (! isset($seen_errors[$uniq])) {
                        $seen_errors[$uniq] = true;
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
    private function checkAccessError($class_name, $key, $name, $from_class, $is_static, $check_parent_only) {
        $d = $this->getDefinition($class_name, $key, $name, $check_parent_only);
        list($found_in_class, $def) = $d;

        if (!$found_in_class) {
            // definition not found
            $found = false;
            if ($key=='property') {
                list($_get_found, $_get_def) = $this->getDefinition($class_name, 'method', '__get');
                if ($_get_found) {
                    $found = true;
                }
            }
            if (!$found) {
                $error_code = "exp02";
                $severity = ($key=='method' || $key=='constant') ? XRef::ERROR : XRef::WARNING;
                $message = "Access to undefined $key of class $class_name";
                $uniq = "$error_code/$from_class/$class_name/$key/$name";
                return array($error_code, $severity, $message, $uniq);
            }
        } elseif (!$def) {
            // definition not found because definition of base class is missing
                $error_code = "exp04";
                $message = "Can't validate class $class_name because definition of base class '$found_in_class' is missing";
                return array($error_code, XRef::WARNING, $message, "$error_code/$class_name/$found_in_class");

        } else {
            // got definition, check access
            // 1. static vs. instance
            if ($key != 'constant' && !($key=='method' && $name=='__construct')) {
                if ($is_static) {
                    if (!XRef::isStatic($def->attributes)) {
                        $error_code = "exp03";
                        $severity = XRef::ERROR;
                        $message = "Trying to access instance $key as static one";
                        $uniq = "$error_code/$from_class/$class_name/$key/$name";
                        return array($error_code, $severity, $message, $uniq);
                    }
                } else {
                    if (XRef::isStatic($def->attributes) && $key == 'property') {
                        $error_code = "exp03";
                        $severity = XRef::ERROR;
                        $message = "Trying to access static $key as instance one";
                        $uniq = "$error_code/$from_class/$class_name/$key/$name";
                        return array($error_code, $severity, $message, $uniq);
                    }
                }
            }

            // 2. public, private, protected
            if (XRef::isPublic($def->attributes)) {
                // ok
            } elseif (XRef::isPrivate($def->attributes)) {
                if (!$from_class || $found_in_class != strtolower($from_class)) {
                    $error_code = "exp04";
                    $severity = XRef::ERROR;
                    $message = "Attempt to access private $key of class $found_in_class";
                    $uniq = "$error_code/$from_class/$class_name/$key/$name";
                    return array($error_code, $severity, $message, $uniq);
                }
            } elseif (XRef::isProtected($def->attributes)) {
                if (!$from_class || !$this->isSubclassOf($from_class, $found_in_class)) {
                    $error_code = "exp04";
                    $severity = XRef::ERROR;
                    $message = "Attempt to access protected $key of class $found_in_class";
                    $uniq = "$error_code/$from_class/$class_name/$key/$name";
                    return array($error_code, $severity, $message, $uniq);
                }
            } else {
                // shouldn't be here
                throw new Exception("Should be public? $def->attributes");
            }
        }
        return;
    }

    private function isSubclassOf($child_class, $parent_class) {
        $child_class = strtolower($child_class);
        $parent_class = strtolower($parent_class);

        if ($child_class == $parent_class) {
            return true;
        }

        // if a class is unknown, or any of the parent classes is missing,
        // assume that child_class may be the child of the parent_class
        if (!isset($this->classes[$child_class])) {
            return true;
        }

        foreach ($this->classes[$child_class] as $c) {
            foreach ($c->extends as $parent_class_name) {
                if ($this->isSubclassOf($parent_class_name, $parent_class)) {
                    return true;
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
    private function getDefinition($orig_class_name, $key, $name, $check_parent_only = false) {
        $class_name = strtolower($orig_class_name);
        if (!isset($this->classes[$class_name])) {
            // class $class_name not found
            return array($orig_class_name, null);
        }
        if ($key == 'method') {
            $name = strtolower($name);
        }

        if (!$check_parent_only) {
            foreach ($this->classes[$class_name] as /** @var $c XRef_Class */$c) {
                if ($key == 'method') {
                    $name = strtolower($name);
                    foreach ($c->methods as $m) {
                        if (strtolower($m->name) == $name) {
                            return array($class_name, $m);
                        }
                    }
                } elseif ($key == 'property') {
                    foreach ($c->properties as $p) {
                        if ($p->name == $name) {
                            return array($class_name, $p);
                        }
                    }
                } elseif ($key == 'constant') {
                    foreach ($c->constants as $c) {
                        if ($c->name == $name) {
                            return array($class_name, $c);
                        }
                    }
                 } else {
                    throw new Exception($key);
                }
            }
        }


        foreach ($this->classes[$class_name] as $c) {
            foreach ($c->extends as $parent_class_name) {
                $d = $this->getDefinition($parent_class_name, $key, $name);
                list($found_in_class, $def) = $d;
                if ($found_in_class) {
                    return $d;
                }
            }

            // constants and methods can be inherited from used traits
            if ($key == 'constant' || $key == 'method') {
                foreach ($c->uses as $parent_class_name) {
                    $d = $this->getDefinition($parent_class_name, $key, $name);
                    if ($d) {
                        return $d;
                    }
                }
            }

            // constants can be inherited from interfaces too
            // and abstract classes can use methods inherited from interfaces
            if ($key == 'constant' || ($key == 'method' && $c->isAbstract)) {
                foreach ($c->implements as $parent_class_name) {
                    $d = $this->getDefinition($parent_class_name, $key, $name);
                    if ($d) {
                        return $d;
                    }
                }
            }
        }
        return array(null, null);
    }

    // report each used construct only once
    private $already_seen = array();
    // className, kind (method|property|const), name
    private $used = array();

    private function addUsedConstruct($class_name, $key, $name, $line_number, $from_class, $is_static, $check_parent_only) {
        $uniq_key = "$class_name##$key##$name##$from_class##$is_static";
        if (!isset($this->already_seen[$uniq_key])) {
            $this->already_seen[$uniq_key] = true;
            $this->used[] = array($class_name, $key, $name, $line_number, $from_class, $is_static, $check_parent_only);
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
                        $class = $pf->getClassAt( $t->index );
                        if (!$class) {
                            continue;
                        }
                        $class_name = $class->name;
                        $key = ($n->text == '(') ? 'method' : 'property';
                        $this->addUsedConstruct($class_name, $key, $name, $t->lineNumber, $class_name, false, false);
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
                    $from_method = $pf->getMethodAt( $t->index );

                    $from_class_name = ($from_class) ? $from_class->name : '';
                    $is_static_context = true;
                    $check_parent_only = false;
                    if ($class_name == 'self') {
                        $class_name = $from_class_name;
                    } elseif ($class_name == 'static') {
                        $class_name = $from_class_name;
                        $is_static_context = !$from_class || !$from_method || XRef::isStatic($from_method->attributes);
                    } elseif ($class_name == 'parent') {
                        $class_name = $from_class_name;
                        $is_static_context = !$from_class || !$from_method || XRef::isStatic($from_method->attributes);
                        $check_parent_only = true;
                    }

                    $n = $n->nextNS();
                    if ($n->kind == T_STRING) {
                        $nn = $n->nextNS();
                        if ($nn->text == '(') {
                            // Foo::bar()
                            $this->addUsedConstruct($class_name, 'method', $n->text, $t->lineNumber, $from_class_name, $is_static_context, $check_parent_only);
                        } else {
                            // Foo:BAR - constant?
                            $const_name = $n->text;
                            $this->addUsedConstruct($class_name, 'constant', $n->text, $t->lineNumber, $from_class_name, $is_static_context, $check_parent_only);
                        }
                    } elseif ($n->kind == T_VARIABLE) {
                        // Foo::$bar
                        $property_name = substr($n->text, 1);   // skip '$' sign
                        $this->addUsedConstruct($class_name, 'property', $property_name, $t->lineNumber, $from_class_name, $is_static_context, $check_parent_only);
                    } else {
                        error_log($n);
                    }
                    continue;
                }
            }
        }
        return $this->used;
    }
}


