<?php


class XRef_ProjectDatabase implements XRef_IProjectDatabase {
    /** map: file name --> array with classes, consts, method etc that this file provides */
    public $provides = array( );

    /** map: file name --> array with used constructs */
    public $uses = array();

    private static $internalClasses = null;

    public function __construct() {
        $this->addInternalClasses();
    }

    public function clear() {
        $this->provides = array();
        $this->uses = array();
    }

    public function addParsedFile(XRef_IParsedFile $pf, $isLibraryFile = false) {
        $file_name = $pf->getFileName();
        $this->provides[$file_name] = $pf->getClasses();
        if (!$isLibraryFile) {
            $this->uses[$file_name] = $this->collectUsedConstructs($pf);
        }
    }

    private function addInternalClasses() {
        if (! self::$internalClasses) {
            self::$internalClasses = array();
            foreach (get_declared_classes() as $class_name) {
                $rc = new ReflectionClass($class_name);
                if ($rc->isInternal()) {
                    self::$internalClasses[] = $this->getClass($rc);
                }
            }
            foreach (get_declared_interfaces() as $class_name) {
                $rc = new ReflectionClass($class_name);
                if ($rc->isInternal()) {
                    self::$internalClasses[] = $this->getClass($rc);
                }
            }
        }
        $this->provides["<internal>"] = self::$internalClasses;
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
                        // e.g. self::$$keyName
                        //error_log($n);
                    }
                    continue;
                }
            }
        }
        return $this->used;
    }

}

class XRef_ProjectDatabase_Persistent extends XRef_ProjectDatabase {

    /** map: file name --> sha1 sum of the file content */
    private $projectFiles = array();

    /** @var XRef_IPersistentStorage */
    private $storageManager;

    /** @var XRef */
    private $xref;

    /** @var XRef_IFileProvider */
    private $fileProvider;

    public function __construct($projectName, XRef $xref, XRef_IFileProvider $fileProvider = null) {
        parent::__construct();
        $this->xref = $xref;
        $this->storageManager = $xref->getStorageManager();
        $this->fileProvider = $fileProvider;

        if ($projectName) {
            $this->projectFiles = $this->storageManager->restoreData("project-check", $projectName);
        }

        if (! $this->projectFiles) {
            $this->projectFiles = array();
            if ($this->fileProvider) {
                foreach ($this->fileProvider->getFiles() as $filename) {
                    $this->projectFiles[ $filename ] = null;    // temporary, see 'updateFile' below
                }
            }
        }

        // load the parsed data for each file,
        // or parse file if the data is missing
        $files_to_load = array();
        foreach ($this->projectFiles as $filename => $shasum) {
            if (!$shasum || !$this->loadFile($filename, $shasum, false)) {
                $files_to_load[] = $filename;
            }
        }
        foreach ($files_to_load as $filename) {
            $this->updateFile($filename, false);
        }
    }

    public function clear() {
        $this->projectFiles = array();
        parent::clear();
    }

    // TODO: don't rely on provided list_of_files
    public function update(XRef_IFileProvider $newProvider, $list_of_files) {
        $this->fileProvider = $newProvider;
        foreach ($list_of_files as $filename) {
            $this->updateFile($filename, false);
        }
    }

    public function save($projectName) {
        $this->storageManager->saveData("project-check", $projectName, $this->projectFiles);
    }

    public function updateFile($filename, $isLibraryFile, &$parsed_file = null) {
        $content = $this->fileProvider->getFileContent($filename);
        if (!$content) {
            unset($this->projectFiles[$filename]);
            unset($this->provides[$filename]);
            unset($this->uses[$filename]);
        } else {
            $shasum = sha1($content);
            $this->projectFiles[$filename] = $shasum;
            if (!$this->loadFile($filename, $shasum, $isLibraryFile)) {
                try {
                    if ($parsed_file) {
                        $this->addParsedFile($parsed_file);
                    } else {
                        $pf = $this->xref->getParsedFile($filename, $content);
                        $this->addParsedFile($pf);
                        if (isset($parsed_file)) {
                            $parsed_file = $pf;
                        } else {
                            $pf->release();
                        }
                    }
                } catch (Exception $e) {
                    error_log($e->getMessage());
                }
                $this->saveFile($filename, $shasum, $isLibraryFile);
            }
        }
    }

    private function loadFile($filename, $shasum, $isLibraryFile) {
        if (!$shasum) {
            return false;
        }
        $key = ($isLibraryFile) ? "library-files" : "project-files";
        $data = $this->storageManager->restoreData($key, $shasum);
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

    private function saveFile($filename, $shasum, $isLibraryFile) {
        $key = ($isLibraryFile) ? "library-files" : "project-files";
        $data = array(
            "filename"      => $filename,
            "xrefVersion"   => XRef::version(),
            "provides"      => isset($this->provides[$filename])    ? $this->provides[$filename]    : null,
            "uses"          => isset($this->uses[$filename])        ? $this->uses[$filename]        : null,
        );
        $this->storageManager->saveData($key, $shasum, $data);
    }
}

class ProjectLintPrototype extends XRef_APlugin implements XRef_IProjectLintPlugin {

    const
        E_SEVERAL_CLASS_DEFINITIONS     = "exp01",
        E_ACCESS_TO_UNDEFINED_METHOD    = "exp02",
        E_ACCESS_TO_UNDEFINED_CONSTANT  = "exp03",
        E_ACCESS_TO_UNDEFINED_PROPERTY  = "exp04",
        E_MISSING_BASE_CLASS            = "exp05",
        E_ACCESS_STATIC_AS_INSTANCE     = "exp06",
        E_ACCESS_INSTANCE_AS_STATIC     = "exp07",
        E_PRIVATE_ACCESS                = "exp08",
        E_PROTECTED_ACCESS              = "exp09",
        E_MAGIC_GETTER                  = "exp10";


    /** @var array - map: (class name -> array of all definition of this class) */
    private $classes = null;

    public function __construct() {
        parent::__construct("project-check", "Cross-reference integrity check");
    }


    public function getProjectReport(XRef_IProjectDatabase $pd) {
        $this->classes = array();
        $errors = array(); // map: fileName --> array of XRef_CodeDefect objects
        $seen_errors = array();

        $class_defined_in_file = array();
        foreach ($pd->provides as $file_name => $list_of_classes) {
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
                $cd->errorCode = self::E_SEVERAL_CLASS_DEFINITIONS;
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
        foreach ($pd->uses as $file_name => $usage_list) {
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
        return $errors;
    }

    // is $key( = 'property|method') named $name defined in class $class_name?
    // returns array(error_code, error_message)
    private function checkAccessError($class_name, $key, $name, $from_class, $is_static, $check_parent_only) {
        $d = $this->getDefinition($class_name, $key, $name, $check_parent_only);
        list($found_in_class, $def) = $d;

        if (!$found_in_class) {
            // definition not found
            if ($key=='property') {
                list($found_in_class, $_get_def) = $this->getDefinition($class_name, 'method', '__get');
                if ($found_in_class) {
                    $error_code = self::E_MAGIC_GETTER;
                    $severity = XRef::NOTICE;
                    $message = "Can't validate access to properties of class $class_name because it has method __get";
                    $uniq = "$error_code/$class_name";
                    return array($error_code, $severity, $message, $uniq);
                }
            }

            switch ($key) {
                case 'method':
                    $error_code = self::E_ACCESS_TO_UNDEFINED_METHOD;
                    $severity = XRef::ERROR;
                    break;
                case 'constant':
                    $error_code = self::E_ACCESS_TO_UNDEFINED_CONSTANT;
                    $severity = XRef::ERROR;
                    break;
                case 'property':
                    $error_code = self::E_ACCESS_TO_UNDEFINED_PROPERTY;
                    $severity = XRef::WARNING;
                    break;
                default:
                    throw new Exception($key);
            }
            $message = "Access to undefined $key of class $class_name";
            $uniq = "$error_code/$from_class/$class_name/$key/$name";
            return array($error_code, $severity, $message, $uniq);
        } elseif (!$def) {
            // definition not found because definition of base class is missing
                $error_code = self::E_MISSING_BASE_CLASS;
                $message = "Can't validate class $class_name because definition of base class '$found_in_class' is missing";
                return array($error_code, XRef::NOTICE, $message, "$error_code/$class_name/$found_in_class");

        } else {
            // got definition, check access
            // 1. static vs. instance
            if ($key != 'constant' && !($key=='method' && $name=='__construct')) {
                if ($is_static) {
                    if (!XRef::isStatic($def->attributes)) {
                        $error_code = self::E_ACCESS_INSTANCE_AS_STATIC;
                        $severity = XRef::ERROR;
                        $message = "Trying to access instance $key as static one";
                        $uniq = "$error_code/$from_class/$class_name/$key/$name";
                        return array($error_code, $severity, $message, $uniq);
                    }
                } else {
                    if ($key == 'property' && XRef::isStatic($def->attributes)) {
                        $error_code = self::E_ACCESS_STATIC_AS_INSTANCE;
                        $severity = XRef::ERROR;
                        $message = "Trying to access static property as instance one";
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
                    $error_code = self::E_PRIVATE_ACCESS;
                    $severity = XRef::ERROR;
                    $message = "Attempt to access private $key of class $found_in_class";
                    $uniq = "$error_code/$from_class/$class_name/$key/$name";
                    return array($error_code, $severity, $message, $uniq);
                }
            } elseif (XRef::isProtected($def->attributes)) {
                if (!$from_class || !$this->isSubclassOf($from_class, $found_in_class)) {
                    $error_code = self::E_PROTECTED_ACCESS;;
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

}


class XRef_ProjectLint_MissedParentConstructor extends XRef_APlugin implements XRef_IProjectLintPlugin {

    const E_MISSED_CALL_TO_PARENT_CONSTRUCTOR = "exp20";

    /** @var array - map: (class name -> { "sublasses" => [], hasConstructor=>true|false, callsParent=>true|false } */
    private $classes = null;

    private $errors = array();
    private $seen_classes = array();

    public function __construct() {
        parent::__construct("project-check-missed-parent-constructor", "Project Lint: Missed Parent Constructor");
    }

    private function getOrCreate($class_name, $file_name = null, $line_number = null) {
        $lc_name = strtolower($class_name);
        if (!isset($this->classes[$lc_name])) {
            $this->classes[$lc_name] = (object) array (
                "name" => $class_name,
                "fileName" => $file_name,
                "lineNumber" => $line_number,
                "subclasses" => array(),
                "hasConstructor" => false,
                "callsParentConstructor" => false,
            );
        }
        if ($file_name) {
            $this->classes[$lc_name]->fileName = $file_name;
            $this->classes[$lc_name]->lineNumber = $line_number;
        }
        return $this->classes[$lc_name];
    }

    public function getProjectReport(XRef_IProjectDatabase $pd) {
        $this->classes = array();

        // find classes with constructors and all their subclasses
        foreach ($pd->provides as $file_name => $list_of_classes) {
            foreach ($list_of_classes as /** @var $class XRef_Class */$class) {

                // skip internal classes, like SplFileObject
                // TODO: add explicit flag for internal classes/methods etc
                if ($class->index < 0) {
                    continue;
                }

                $c = $this->getOrCreate($class->name, $file_name, $class->lineNumber);
                foreach ($class->extends as $parent_name) {
                    $parent = $this->getOrCreate($parent_name);
                    $parent->subclasses[] = $class->name;
                }
                foreach ($class->methods as $method) {
                    if ($method->name == '__construct') {
                        $c->hasConstructor = true;
                        break;
                    }
                }
            }
        }

        // find which classes actually calls parent constructors
        foreach ($pd->uses as $file_name => $usage_list) {
            foreach ($usage_list as $u) {
                list($class_name, $key, $name, $line_number, $from_class, $is_static, $check_parent_only) = $u;
                if ($check_parent_only && $from_class && $key == 'method' && $name == '__construct') {
                    $c = $this->getOrCreate($from_class);
                    // TODO: check that it calls constructor from its own constructor
                    $c->callsParentConstructor = true;
                }
            }
        }

        $this->errors = array();
        $this->seen_classes = array();

        foreach ($this->classes as $c) {
            if ($c->hasConstructor) {
                $this->checkSubclasses($c);
            }
        }
        return $this->errors;
    }

    private function checkSubclasses($c) {
        $class_name = $c->name;
        foreach ($c->subclasses as $subclass_name) {
            $lc_name = strtolower($subclass_name);
            if (isset($this->seen_classes[$lc_name])) {
                continue;
            }
            $this->seen_classes[$lc_name] = true;

            $subclass = $this->getOrCreate($subclass_name);
            if ($subclass->hasConstructor && !$subclass->callsParentConstructor) {
                $subclass_name = $subclass->name;
                $file_name = $subclass->fileName;
                $cd = new XRef_CodeDefect();
                $cd->tokenText = $subclass->name;
                $cd->errorCode = self::E_MISSED_CALL_TO_PARENT_CONSTRUCTOR;
                $cd->severity = XRef::WARNING;
                $cd->message = "Class $subclass_name doesn't call constructor of it's base class $class_name";
                $cd->fileName = $file_name;
                $cd->lineNumber = $subclass->lineNumber;
                $this->errors[ $file_name ][] = $cd;
            }
            $this->checkSubclasses($subclass);
        }
    }
}

