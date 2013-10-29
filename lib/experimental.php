<?php


class XRef_ProjectDatabase implements XRef_IProjectDatabase {

    /** map: lc class name --> array with classes */
    private $classes = array( );

    private static $lookupResultNotFound;

    //
    // methods to create the database
    //
    public function __construct() {
        self::$lookupResultNotFound = new XRef_LookupResult(XRef_LookupResult::NOT_FOUND);
    }

    /**
     * Function to summarize content of parsed file.
     *
     * @param XRef_ParsedFile $pf
     * @param bool $is_library_file
     * @return array file summary,
     */
    public function createFileSlice(XRef_IParsedFile $pf, $is_library_file = false) {
        // TODO: add functions and constants
        $slice = array(
            "classes"   => $pf->getClasses(),
        );
        return $slice;
    }

    /**
     * @param  $file_slice
     * @return void
     */
    public function addFileSlice($file_name, $file_slice) {
        foreach ($file_slice["classes"] as /** @var XRef_Class $c */ $c) {
            $class_name = strtolower($c->name);
            if (! isset($this->classes[$class_name])) {
                $this->classes[$class_name] = array();
            }
            $this->classes[$class_name][] = $c;
        }
    }

    public function finalize() {
        $internal_classes = $this->getInternalClasses();
        $this->addFileSlice( "[internal]", array("classes" => $internal_classes) );
    }


    //
    // database query methods
    //
    public function getAllClasses() {
        $all_classes = array();
        foreach ($this->classes as $class_name => $list) {
            $all_classes = array_merge($all_classes, $list);
        }
        return $all_classes;
    }

    // input: class name (string)
    // output: XRef_LookupResult object
    public function lookupClass($class_name) {
        $lc_name = strtolower($class_name);
        if (isset($this->classes[$lc_name])) {
            return new XRef_LookupResult(XRef_LookupResult::FOUND, $this->classes[$lc_name]);
        } else {
            return self::$lookupResultNotFound;
        }
    }

    /**
     * @param string $class_name, e.g. "XRef_ProjectDatabase"
     * @param string $field_kind, e.g. 'methods', 'properties' or 'constants'
     * @param string $field_name, e.g. 'lookupClassField'
     * @param bool $is_case_sensitive
     * @param bool $do_only_parent_lookup
     * @return XRef_LookupResult
     */
    private function lookupClassField($class_name, $field_kind, $field_name, $is_case_sensitive = true, $do_only_parent_lookup = false) {
        $lc_class_name = strtolower($class_name);
        $lc_field_name = strtolower($field_name);
        if (isset($this->classes[$lc_class_name])) {
            $this->classes[$lc_class_name];
            // 1. search the given class
            if (!$do_only_parent_lookup) {
                foreach ($this->classes[$lc_class_name] as /** @var XRef_Class $c */ $c) {
                    foreach ($c->$field_kind as $f) {
                        $name_matches = ($is_case_sensitive)
                                ? $f->name == $field_name
                                : strtolower($f->name) == $lc_field_name;
                        if ($name_matches) {
                            $result = new XRef_LookupResult(XRef_LookupResult::FOUND, array($f));
                            return $result;
                        }
                    }
                }
            }

            // 2. if not found, try parent classes
            foreach ($this->classes[$lc_class_name] as $c) {
                // base classes
                foreach ($c->extends as $parent_class_name) {
                    $result = $this->lookupClassField($parent_class_name, $field_kind, $field_name, $is_case_sensitive);
                    if ($result->code != XRef_LookupResult::NOT_FOUND) {
                        return $result;
                    }
                }
                // used traits
                foreach ($c->uses as $parent_class_name) {
                    $result = $this->lookupClassField($parent_class_name, $field_kind, $field_name, $is_case_sensitive);
                    if ($result->code != XRef_LookupResult::NOT_FOUND) {
                        return $result;
                    }
                }
                // abstract classes can use methods inherited from interfaces
                foreach ($c->implements as $parent_class_name) {
                    $result = $this->lookupClassField($parent_class_name, $field_kind, $field_name, $is_case_sensitive);
                    if ($result->code != XRef_LookupResult::NOT_FOUND) {
                        return $result;
                    }
                }
            }
            // 3. not found anywhere
            return self::$lookupResultNotFound;
        } else {
            $result = new XRef_LookupResult(XRef_LookupResult::CLASS_MISSING);
            $result->missingClassName = $class_name;
            return $result;
        }
    }

    /** @return XRef_LookupResult */
    public function lookupMethod($class_name, $method_name, $parent_class_only = false) {
        return $this->lookupClassField($class_name, 'methods', $method_name, false, $parent_class_only);
    }

    /** @return XRef_LookupResult */
    public function lookupConstant($class_name, $const_name, $parent_class_only = false) {
        return $this->lookupClassField($class_name, 'constants', $const_name, true, $parent_class_only);
    }


    /** @return XRef_LookupResult */
    public function lookupProperty($class_name, $prop_name, $parent_class_only = false) {
        return $this->lookupClassField($class_name, 'properties', $prop_name, true, $parent_class_only);
    }


    //
    // internal methods for reflection on current PHP classes
    //
    private static $internalClasses = null;
    private static $hasTraits;

    private function getInternalClasses() {
        if (! self::$internalClasses) {
            self::$internalClasses = array();
            foreach (get_declared_classes() as $class_name) {
                $rc = new ReflectionClass($class_name);
                if ($rc->isInternal()) {
                    self::$internalClasses[] = $this->getClassByReflection($rc);
                }
            }
            foreach (get_declared_interfaces() as $class_name) {
                $rc = new ReflectionClass($class_name);
                if ($rc->isInternal()) {
                    self::$internalClasses[] = $this->getClassByReflection($rc);
                }
            }
        }
        return self::$internalClasses;
    }

    private function getClassByReflection(ReflectionClass $rc) {
        $class_name = $rc->getName();

        if (! isset(self::$hasTraits)) {
            $rrc = new ReflectionClass("ReflectionClass");
            self::$hasTraits = $rrc->hasMethod("isTrait");
        }

        $c = new XRef_Class;
        $c->index = -1;
        $c->nameIndex = -1;
        $c->bodyStarts = -1;
        $c->bodyEnds = -1;

        if ($rc->isInterface()) {
            $c->kind = T_INTERFACE;
        } elseif (self::$hasTraits && $rc->isTrait()) {
            $c->kind = T_TRAIT;
        } else {
            $c->kind = T_CLASS;
        }
        $c->name = $class_name;

        $parent_class = $rc->getParentClass();
        $c->extends     = ($parent_class) ? array( $parent_class->getName() ) : array();
        $c->implements  = $rc->getInterfaceNames();
        $c->uses        = (self::$hasTraits) ? $rc->getTraitNames() : array();


        foreach ($rc->getMethods() as $rm) {
            $m = $this->getMethodByReflection($rm);
            $m->className = $class_name;
            $c->methods[] = $m;
        }
        foreach ($rc->getConstants() as $name => $value) {
            $const = $this->getConstantByReflection($name);
            $const->className = $class_name;
            $c->constants[] = $const;
        }
        foreach ($rc->getProperties() as $rp) {
            $p = $this->getPropertyByReflection($rp);
            $p->className = $class_name;
            $c->properties[] = $p;
        }

        return $c;
    }

    private function getMethodByReflection(ReflectionMethod $rm) {
        $m = new XRef_Function();
        $m->name = $rm->getName();
        $m->index = $m->bodyStarts = $m->bodyEnds = $m->nameIndex = $m->nameStartIndex = -1;
        $m->isDeclaration = false;
        $m->attributes = $this->getAttributes($rm, true);
        $m->returnsReference = $rm->returnsReference();
        return $m;
    }

    private function getConstantByReflection($name) {
        $const = new XRef_Constant();
        $const->name = $name;
        $const->index = -1;
        $const->attributes = 0;
        return $const;
    }

    private function getPropertyByReflection(ReflectionProperty $rp) {
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

    // report each used construct only once
    private $already_seen = array();
    // array of arrays (className, kind (method|property|const), name)
    private $slice = array();
    // map: filename => XRef_CodeDefect[]
    private $errors = array();

    private function addUsedConstruct($class_name, $key, $name, $line_number, $from_class, $is_static, $check_parent_only) {
        $uniq_key = "$class_name##$key##$name##$from_class##$is_static##$check_parent_only";
        if (!isset($this->already_seen[$uniq_key])) {
            $this->already_seen[$uniq_key] = true;
            $this->slice[] = array($class_name, $key, $name, $line_number, $from_class, $is_static, $check_parent_only);
        }
    }

    public function createFileSlice(XRef_IParsedFile $pf, $is_library_file = false) {
        $this->slice = array();
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
                    $class_name = $t->text;
                    $check_parent_only = ($class_name == 'parent');

                    $from_class = $pf->getClassAt( $t->index );
                    $from_class_name = ($from_class) ? $from_class->name : '';

                    if ($class_name == 'self' || $class_name == 'static' || $class_name == 'parent') {
                        $check_parent_only = ($class_name == 'parent');
                        $class_name = $from_class_name;
                        $from_method = $pf->getMethodAt( $t->index );
                        $is_static_context = !$from_class || !$from_method || XRef::isStatic($from_method->attributes);
                    } else {
                        $is_static_context = true;
                    }

                    $n = $n->nextNS();
                    if ($n->kind == T_STRING) {
                        $nn = $n->nextNS();
                        if ($nn->text == '(') {
                            // Foo::bar()
                            // self::bar() - this can be either static or instance access, depends on context
                            $this->addUsedConstruct($class_name, 'method', $n->text, $t->lineNumber, $from_class_name, $is_static_context, $check_parent_only);
                        } else {
                            // Foo::BAR - constant
                            $const_name = $n->text;
                            $this->addUsedConstruct($class_name, 'constant', $n->text, $t->lineNumber, $from_class_name, true, $check_parent_only);
                        }
                    } elseif ($n->kind == T_VARIABLE) {
                        // Foo::$bar
                        $property_name = substr($n->text, 1);   // skip '$' sign
                        $this->addUsedConstruct($class_name, 'property', $property_name, $t->lineNumber, $from_class_name, true, $check_parent_only);
                    } else {
                        // e.g. self::$$keyName
                        //error_log($n);
                    }
                    continue;
                }
            }
        }
        return $this->slice;
    }


    /** @var array - map: (class name -> array of all definition of this class) */
    private $classes = null;

    public function __construct() {
        parent::__construct("project-check", "Cross-reference integrity check");
    }

    public function startLintCheck(XRef_IProjectDatabase $db) {
        // map: class name => XRef_Class[]
        $this->classes = array();

        // map: fileName --> array of XRef_CodeDefect objects
        $this->errors = array();

        foreach ($db->getAllClasses() as $class) {
            $class_name = strtolower($class->name);
            $this->classes[$class_name][] = $class;
        }

        // are there classes defined twice?
        foreach ($this->classes as $class_name => $list_of_classes) {
            if (count($list_of_classes) > 1) {
                $cd = new XRef_CodeDefect();
                $cd->tokenText = $class_name;
                $cd->errorCode = self::E_SEVERAL_CLASS_DEFINITIONS;
                $cd->severity = XRef::WARNING;
                $cd->message = "Class is defined more than once"; // . implode(", ", $list_of_files);
                $cd->fileName = "(project)";
                $cd->lineNumber = 0;
                $this->errors[ $cd->fileName ][] = $cd;
            }
        }

        // are there missing classes or base classes?
        // TODO
    }

    public function checkFileSlice(XRef_IProjectDatabase $db, $file_name, $file_slice) {
        $seen_errors = array();
        foreach ($file_slice as $u) {
            list($class_name, $key, $name, $line_number, $from_class, $is_static, $check_parent_only) = $u;
            $e = $this->checkAccessError($db, $class_name, $key, $name, $from_class, $is_static, $check_parent_only);
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
                    $this->errors[ $cd->fileName ][] = $cd;
                }
            }
        }
    }

    /** @return array - map (file name -> list of errors) */
    public function getProjectReport(XRef_IProjectDatabase $db) {
        return $this->errors;
    }

    // is $key( = 'property|method') named $name defined in class $class_name?
    // returns array(error_code, error_message)
    private function checkAccessError(XRef_IProjectDatabase $db, $class_name, $key, $name, $from_class, $is_static, $check_parent_only) {
        /** @var $lr XRef_LookupResult */
        $lr = null;
        switch ($key) {
            case 'property':
                $lr = $db->lookupProperty($class_name, $name, $check_parent_only);
                break;
            case 'method':
                $lr = $db->lookupMethod($class_name, $name, $check_parent_only);
                break;
            case 'constant':
                $lr = $db->lookupConstant($class_name, $name, $check_parent_only);
                break;
        }

        if (!$lr || $lr->code == XRef_LookupResult::NOT_FOUND) {
            // definition not found
            if ($key == 'property') {
                $lr_magic = $db->lookupMethod($class_name, '__get', $check_parent_only);
                if ($lr_magic->code == XRef_LookupResult::FOUND) {
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
        } elseif ($lr->code == XRef_LookupResult::CLASS_MISSING) {
            // definition not found because definition of base class is missing
            $base_class_name = $lr->missingClassName;
            $error_code = self::E_MISSING_BASE_CLASS;
            $message = "Can't validate class $class_name because definition of base class '$base_class_name' is missing";
            return array($error_code, XRef::NOTICE, $message, "$error_code/$class_name/$base_class_name");
        } else {
            // got definition, check access
            $attributes = $lr->elements[0]->attributes;
            $found_in_class = strtolower($lr->elements[0]->className);

            // 1. static vs. instance
            if ($key != 'constant' && !($key=='method' && $name=='__construct')) {
                if ($is_static) {
                    if (!XRef::isStatic($attributes)) {
                        $error_code = self::E_ACCESS_INSTANCE_AS_STATIC;
                        $severity = XRef::ERROR;
                        $message = "Trying to access instance $key as static one";
                        $uniq = "$error_code/$from_class/$class_name/$key/$name";
                        return array($error_code, $severity, $message, $uniq);
                    }
                } else {
                    if ($key == 'property' && XRef::isStatic($attributes)) {
                        $error_code = self::E_ACCESS_STATIC_AS_INSTANCE;
                        $severity = XRef::ERROR;
                        $message = "Trying to access static property as instance one";
                        $uniq = "$error_code/$from_class/$class_name/$key/$name";
                        return array($error_code, $severity, $message, $uniq);
                    }
                }
            }

            // 2. public, private, protected
            if (XRef::isPublic($attributes)) {
                // ok
            } elseif (XRef::isPrivate($attributes)) {
                if (!$from_class || $found_in_class != strtolower($from_class)) {
                    $error_code = self::E_PRIVATE_ACCESS;
                    $severity = XRef::ERROR;
                    $message = "Attempt to access private $key of class $found_in_class";
                    $uniq = "$error_code/$from_class/$class_name/$key/$name";
                    return array($error_code, $severity, $message, $uniq);
                }
            } elseif (XRef::isProtected($attributes)) {
                if (!$from_class || !$this->isSubclassOf($from_class, $found_in_class)) {
                    $error_code = self::E_PROTECTED_ACCESS;;
                    $severity = XRef::ERROR;
                    $message = "Attempt to access protected $key of class $found_in_class";
                    $uniq = "$error_code/$from_class/$class_name/$key/$name";
                    return array($error_code, $severity, $message, $uniq);
                }
            } else {
                // shouldn't be here
                throw new Exception("Should be public? $attributes");
            }

            // 3. check that the called method is defined, not only declared.
            // however, allow to call declared methods from abstract classes
            if ($key == 'method' && is_null($lr->elements[0]->bodyStarts)) {
                $lc = $db->lookupClass($from_class);
                if (!$lc || $lc->code != XRef_LookupResult::FOUND || !$lc->elements[0]->isAbstract) {
                    $error_code = self::E_ACCESS_TO_UNDEFINED_METHOD;
                    $severity = XRef::ERROR;
                    $message = "Access to undefined $key of class $class_name";
                    $uniq = "$error_code/$from_class/$class_name/$key/$name";
                    return array($error_code, $severity, $message, $uniq);
                }
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

    public function createFileSlice(XRef_IParsedFile $pf, $is_library_file = false) {
        // names of classes that has constructors and don't call parent class constructors
        // array of array(class_name, line_number);
        $slice = array();

        foreach ($pf->getClasses() as /** @var XRef_Class $class */$class) {
            foreach ($class->methods as $method) {
                if (strtolower($method->name) == '__construct' && $method->bodyStarts > 0) {
                    // ok, this class has constructor
                    $does_call_parent_constructor = false;
                    $t = $pf->getTokenAt($method->bodyStarts);
                    while ($t->index < $method->bodyEnds) {
                        if ($t->text == 'parent') {
                            $t = $t->nextNS();
                            if ($t->kind == T_DOUBLE_COLON) {
                                $t = $t->nextNS();
                                if ($t->text == '__construct') {
                                    $t = $t->nextNS();
                                    if ($t->text == '(') {
                                        // ok, this is a call for the parent class constructor
                                        $does_call_parent_constructor = true;
                                        break;
                                    }
                                }
                            }
                        }
                        $t = $t->nextNS();
                    }

                    if (!$does_call_parent_constructor) {
                        // this is suspicious and,
                        // if there is a parent class with constructor, error-prone
                        $slice[] = array($class->name, $class->lineNumber);
                    }
                    break;
                }
            }
        }
        return $slice;
    }

    public function startLintCheck(XRef_IProjectDatabase $db) {
        $this->errors = array();
    }

    public function checkFileSlice(XRef_IProjectDatabase $db, $file_name, $file_slice) {
        foreach ($file_slice as $c) {
            list ($class_name, $line_number) = $c;
            $lr = $db->lookupMethod($class_name, '__construct', true);
            if ($lr->code == XRef_LookupResult::FOUND) {
                // oops, there is a base-class constructor that won't be called
                $base_class_name = $lr->elements[0]->name;
                $cd = new XRef_CodeDefect();
                $cd->tokenText = $class_name;
                $cd->errorCode = self::E_MISSED_CALL_TO_PARENT_CONSTRUCTOR;
                $cd->severity = XRef::WARNING;
                $cd->message = "Class $class_name doesn't call constructor of it's base class $base_class_name";
                $cd->fileName = $file_name;
                $cd->lineNumber = $line_number;
                $this->errors[ $file_name ][] = $cd;

            }
        }
    }

    /** @return array - map (file name -> list of errors) */
    public function getProjectReport(XRef_IProjectDatabase $db) {
        return $this->errors;
    }
}

