<?php

/**
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

class XRef_ProjectLint_CheckClassAccess extends XRef_APlugin implements XRef_IProjectLintPlugin {

    const
        E_SEVERAL_CLASS_DEFINITIONS     = "xr061",
        E_ACCESS_TO_UNDEFINED_METHOD    = "xr062",
        E_ACCESS_TO_UNDEFINED_CONSTANT  = "xr063",
        E_ACCESS_TO_UNDEFINED_PROPERTY  = "xr064",
        E_MISSING_CLASS                 = "xr065",
        E_MISSING_BASE_CLASS            = "xr066",
        E_ACCESS_STATIC_AS_INSTANCE     = "xr067",
        E_ACCESS_INSTANCE_AS_STATIC     = "xr068",
        E_PRIVATE_MEMBER                = "xr069",
        E_PROTECTED_MEMBER              = "xr070",
        E_PROTECTED_ACCESS              = "xr071",
        E_MAGIC_GETTER                  = "xr072";

    public function getErrorMap() {
        return array(
            self::E_SEVERAL_CLASS_DEFINITIONS => array(
                "severity"  => XRef::WARNING,
                "message"   => "Class (%s) is defined more than once",
            ),
            self::E_ACCESS_TO_UNDEFINED_METHOD => array(
                "severity"  => XRef::ERROR,
                "message"   => "Method (%s) is not defined in class (%s)",
            ),
            self::E_ACCESS_TO_UNDEFINED_CONSTANT => array(
                "severity"  => XRef::ERROR,
                "message"   => "Constant (%s) is not defined in class (%s)",
            ),
            self::E_ACCESS_TO_UNDEFINED_PROPERTY => array(
                "severity"  => XRef::WARNING,
                "message"   => "Property (%s) is not declared in class (%s)",
            ),
            self::E_MISSING_CLASS => array(
                "severity"  => XRef::WARNING,
                "message"   => "Can't check members of class (%s) because its definition is missing",
            ),
            self::E_MISSING_BASE_CLASS => array(
                "severity"  => XRef::WARNING,
                "message"   => "Can't check members of class (%s) because definition of its base class (%s) is missing",
            ),
            self::E_ACCESS_STATIC_AS_INSTANCE => array(
                "severity"  => XRef::ERROR,
                "message"   => "Property (%s) of class (%s) is static, not instance",
            ),
            self::E_ACCESS_INSTANCE_AS_STATIC => array(
                "severity"  => XRef::ERROR,
                "message"   => "Member (%s) of class (%s) is instance, not static",
            ),
            self::E_PRIVATE_MEMBER => array(
                "severity"  => XRef::ERROR,
                "message"   => "Member (%s) of class (%s) is private",
            ),
            self::E_PROTECTED_MEMBER => array(
                "severity"  => XRef::ERROR,
                "message"   => "Member (%s) of class (%s) is protected",
            ),
            self::E_MAGIC_GETTER => array(
                "severity"  => XRef::NOTICE,
                "message"   => "Can't check properties of class (%s) because it has method __get",
            ),
        );
    }


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
                $this->errors[ XRef::DUMMY_PROJECT_FILENAME ][] = array(
                    'code'      => self::E_SEVERAL_CLASS_DEFINITIONS,
                    'text'      => $class_name,
                    'params'    => array($list_of_classes[0]->name),
                    'location'  => array(XRef::DUMMY_PROJECT_FILENAME, 0),
                );
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
                list($error_code, $uniq, $params) = $e;
                $uniq = "$error_code//$uniq";
                if (! isset($seen_errors[$uniq])) {
                    $seen_errors[$uniq] = true;
                    $this->errors[$file_name][] = array(
                        'code'      => $error_code,
                        'text'      => $name,
                        'params'    => $params,
                        'location'  => array($file_name, $line_number),
                    );
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
                    // can't validate reference to (missing) property,
                    // because it or it's base class has method '__get'
                    return array(self::E_MAGIC_GETTER, $class_name, array($class_name));
                }
            }

            if ($key == 'method' && $name == '__construct') {
                // ok, php creates a default constructor
            } else {
                switch ($key) {
                    case 'method':
                        $error_code = self::E_ACCESS_TO_UNDEFINED_METHOD;
                        break;
                    case 'constant':
                        $error_code = self::E_ACCESS_TO_UNDEFINED_CONSTANT;
                        break;
                    case 'property':
                        $error_code = self::E_ACCESS_TO_UNDEFINED_PROPERTY;
                        break;
                    default:
                        throw new Exception($key);
                }
                $uniq = "$from_class/$class_name/$key/$name";
                return array($error_code, $uniq, array($name, $class_name));
            }
        } elseif ($lr->code == XRef_LookupResult::CLASS_MISSING) {
            // definition not found because definition of either class or its base class is missing
            $missing_class_name = $lr->missingClassName;
            if (! isset($this->ignore_missing_classes[ strtolower($missing_class_name) ])) {
                if (strtolower($missing_class_name) == strtolower($class_name)) {
                    // class definition is missing
                    return array(self::E_MISSING_CLASS, $class_name, array($class_name));
                } else {
                    // base class definition is missing
                    return array(self::E_MISSING_BASE_CLASS, $class_name, array($class_name, $missing_class_name));
                }
            }
        } else {
            // got definition, check access
            $attributes = $lr->elements[0]->attributes;
            $found_in_class = $lr->elements[0]->className;

            // 1. static vs. instance
            if ($key != 'constant' && !($key=='method' && $name=='__construct')) {
                if ($is_static) {
                    if (!XRef::isStatic($attributes)) {
                        // reference to instance method or property as if they were static
                        if ($key == 'method' || $key == 'property') {
                            return array(self::E_ACCESS_INSTANCE_AS_STATIC, "$from_class/$class_name/$key/$name", array($name, $found_in_class));
                        } else {
                            throw new Exception($key);
                        }
                    }
                } else {
                    if ($key == 'property' && XRef::isStatic($attributes)) {
                        // reference to static property as if it were instance
                        return array(self::E_ACCESS_STATIC_AS_INSTANCE, "$from_class/$class_name/$name", array($name, $found_in_class));
                    }
                }
            }

            // 2. public, private, protected
            if (XRef::isPublic($attributes)) {
                // ok
            } elseif (XRef::isPrivate($attributes)) {
                if (!$from_class || strtolower($found_in_class) != strtolower($from_class)) {
                    // attempt to access a private member (method or property) of class $found_in_class
                    // from $class_name
                    return array(self::E_PRIVATE_MEMBER, "$from_class/$class_name/$name", array($name, $found_in_class));
                }
            } elseif (XRef::isProtected($attributes)) {
                if (!$from_class || !$this->isSubclassOf($from_class, $found_in_class)) {
                    return array(self::E_PROTECTED_MEMBER, "$from_class/$class_name/$name", array($name, $found_in_class));
                }
            } else {
                // shouldn't be here
                throw new Exception("Should be public? $attributes");
            }

            // 3. check that the called method is defined, not only declared.
            // however, allow to call declared methods from abstract classes and traits
            if ($key == 'method' && is_null($lr->elements[0]->bodyStarts)) {
                $lc = $db->lookupClass($from_class);
                if ($lc && $lc->code == XRef_LookupResult::FOUND &&
                    ($lc->elements[0]->isAbstract || $lc->elements[0]->kind == T_TRAIT))
                {
                    // ok, allow to call abstract method
                } else {
                    $found_in_class = $lr->elements[0]->className;
                    return array(self::E_ACCESS_TO_UNDEFINED_METHOD, "$from_class/$found_in_class/$name", array($name, $found_in_class));
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
