<?php

/**
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

class XRef_ProjectLint_CheckClassAccess extends XRef_APlugin implements XRef_IProjectLintPlugin {

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

            // new Foo();
            // new \Bar\Baz();
            // new $class
            // new self()
            if ($t->kind == T_NEW) {
                $n = $t->nextNS();
                $class_name = '';
                while ($n->kind == T_NS_SEPARATOR || $n->kind == T_STRING) {
                    $class_name = $class_name . $n->text;
                    $n = $n->nextNS();
                }
                if ($class_name) {
                    $class_name = $pf->qualifyName($class_name, $t->index);
                    if ($class_name == 'self' || $class_name == 'parent' || $class_name == 'static') {
                        $class = $pf->getClassAt( $t->index );
                        if (!$class) {
                            continue;
                        }
                        $class_name = $class->name;
                    }
                    $this->addUsedConstruct($class_name, 'method', '__construct', $t->lineNumber, $class_name, false, false);
                }
                continue;
            }

            // $this->foo();
            // $this->bar;
            if ($t->kind == T_VARIABLE && $t->text == '$this') {
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
            // \Foo::bar();
            // Foo\Bar::BAZ
            if ($t->kind == T_DOUBLE_COLON) {
                $class_name = '';
                $p = $t->prevNS();
                while ($p->kind == T_NS_SEPARATOR || $p->kind == T_STRING) {
                    $class_name = $p->text . $class_name;
                    $p = $p->prevNS();
                }
                if ($class_name) {
                    $class_name = $pf->qualifyName($class_name, $t->index);
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

                    $n = $t->nextNS();
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

    /** @var array - map (class name => true) for classes that shouldn't create warning, if missing */
    private $ignore_missing_classes = array();

    public function __construct() {
        parent::__construct("project-check", "Cross-reference integrity check");
        $ignore_missing_classes = XRef::getConfigValue("lint.ignore-missing-class", array());
        foreach ($ignore_missing_classes as $class_name) {
            $this->ignore_missing_classes[ strtolower($class_name) ] = true;
        }
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
                $cd = XRef_CodeDefect::fromTokenText(
                    $class_name, self::E_SEVERAL_CLASS_DEFINITIONS, XRef::WARNING,
                    "Class (%s) is defined more than once"
                );
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
                    $cd = XRef_CodeDefect::fromTokenText($name, $error_code, $severity, $message);
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
                    $message = "Can't validate access to properties of class ($class_name) because it has method __get";
                    $uniq = "$error_code/$class_name";
                    return array($error_code, $severity, $message, $uniq);
                }
            }
            if ($key == 'method' && $name == '__construct') {
                // ok, php creates a default constructor
            } else {
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
                $message = "Reference to undefined $key (%s) of class $class_name";
                $uniq = "$error_code/$from_class/$class_name/$key/$name";
                return array($error_code, $severity, $message, $uniq);
            }
        } elseif ($lr->code == XRef_LookupResult::CLASS_MISSING) {
            // definition not found because definition of class or its base class is missing
            $missing_class_name = $lr->missingClassName;
            if (! isset($this->ignore_missing_classes[ strtolower($missing_class_name) ])) {
                $error_code = self::E_MISSING_BASE_CLASS;
                $message = (strtolower($missing_class_name) == strtolower($class_name)) ?
                        "Can't validate reference to class ($class_name) because its definition is missing" :
                        "Can't validate reference to class ($class_name) because definition of its base class ($missing_class_name) is missing";
                return array($error_code, XRef::WARNING, $message, "$error_code/$class_name/$missing_class_name");
            }
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
                        $message = "Reference to instance $key as static one (%s)";
                        $uniq = "$error_code/$from_class/$class_name/$key/$name";
                        return array($error_code, $severity, $message, $uniq);
                    }
                } else {
                    if ($key == 'property' && XRef::isStatic($attributes)) {
                        $error_code = self::E_ACCESS_STATIC_AS_INSTANCE;
                        $severity = XRef::ERROR;
                        $message = "Reference to static property as instance one (%s)";
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
                    $message = "Reference to private $key of class $found_in_class (%s)";
                    $uniq = "$error_code/$from_class/$class_name/$key/$name";
                    return array($error_code, $severity, $message, $uniq);
                }
            } elseif (XRef::isProtected($attributes)) {
                if (!$from_class || !$this->isSubclassOf($from_class, $found_in_class)) {
                    $error_code = self::E_PROTECTED_ACCESS;;
                    $severity = XRef::ERROR;
                    $message = "Reference to protected $key of class $found_in_class (%s)";
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
                    $message = "Reference to undefined $key of class $class_name (%s)";
                    $uniq = "$error_code/$from_class/$class_name/$key/$name";
                    return array($error_code, $severity, $message, $uniq);
                }
            }
        }
        return;
    }

    // TODO: move this into project datatbase
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
