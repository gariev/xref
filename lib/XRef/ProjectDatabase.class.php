<?php
/**
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

/**
 * Project database is the main repository about classes, functions, constants etc
 * that are defined in project. The class is heavily used by ProjectLint plugins.
 *
 * To prevent parsing all project files each time the database is needed, it's build
 * from "slices" - summaries of each parsed file. The slices are stored to persistent
 * storage and retrieved when DB is needed:
 *  (source file) --> (parsed file object) --> (db slice) --> (serialized slice) // once for each file
 *  (serialized slice) --> (project database) // each time the DB is needed
 */
class XRef_ProjectDatabase implements XRef_IProjectDatabase {

    /** map: lower-case class name => XRef_Class[] */
    private $classes = array();
    /** map: lower-case function name => XRef_Method[] */
    private $functions = array();

    private static $lookupResultNotFound;

    private static $overrideInternalFunctions = array();

    public function __construct() {
        self::$lookupResultNotFound = new XRef_LookupResult(XRef_LookupResult::NOT_FOUND);

        // fix some weirdness - function signatures returned by reflection
        // (or by "php --rf <function-name>") are inaccurate and
        // different from documentation & from real world
        $php_version = explode('.', phpversion());
        $php_version_id = $php_version[0]*10000 + $php_version[1]*100 + $php_version[2];

        if ($php_version_id < 50300) {
            // hate php 5.2 - introspection is so inaccurate,
            // so instead of relying on it, let's read prepared
            // descriptions of core functions
            $data_dir = ("@data_dir@" == "@"."data_dir@") ?
                  dirname(__FILE__) . "/../../data" : "@data_dir@/XRef/data";
            $php_52_functions_file = $data_dir . "/php5.2.functions.ser";
            $content = file_get_contents($php_52_functions_file);
            if ($content === false) {
                throw new Exception("Can't read data from file '$php_52_functions_file'");
            }
            $functions = unserialize($content);
            if ($functions === false) {
                throw new Exception("Can't unserialize data from '$php_52_functions_file'");
            }
            self::$overrideInternalFunctions = array_merge(self::$overrideInternalFunctions, $functions);
        }

        if ($php_version_id < 50400) {
            // php 5.3 has some weirdness too
            self::$overrideInternalFunctions = array_merge(
                self::$overrideInternalFunctions,
                array(
                    'define'                => 'define($constant_name, $value, $case_insensitive = false)',
                    'implode'               => 'implode($glue, $pieces = null)',    // allow 1-arg version of implode
                    'spl_autoload_register' => 'spl_autoload_register($autoload_function = null, $throw = true, $prepend = false)',
                    // the first param must be passed by reference
                    // all other parameters are optional and do not require pass-by-ref
                    'array_multisort'       => 'array_multisort(&$array, ...)',
                )
            );
        }
    }

    //
    // methods to create the database
    //

    /**
     * Function to summarize content of parsed file.
     * The result will be added to database by addFileSlice() method
     * and can be serialized/stored meanwhile.
     *
     * @param XRef_ParsedFile $pf
     * @param bool $is_library_file
     * @return array file summary,
     */
    public function createFileSlice(XRef_IParsedFile $pf, $is_library_file = false) {
        // TODO: add constants

        // filter functions that are not methods and not closures
        $functions = array();
        foreach ($pf->getMethods() as /** @var XRef_Method $m*/ $m) {
            if (!$m->className && $m->name) {
                $functions[] = $m;
            }
        }

        $slice = array(
            "classes"   => $pf->getClasses(),
            "functions" => $functions,
        );
        return $slice;
    }

    /**
     * Method to add a file slice, returned by createFileSlice(), to database.
     * @param string $file_name
     * @param array $file_slice
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
        foreach ($file_slice["functions"] as /** @var XRef_Method $f */ $f) {
            $function_name = strtolower($f->name);
            if (! isset($this->functions[$function_name])) {
                $this->functions[$function_name] = array();
            }
            $this->functions[$function_name][] = $f;
        }
    }

    /**
     * Method will be called when all slices are added
     * and the database is complete.
     */
    public function finalize() {
        $this->addFileSlice("[internal]", $this->getInternalSlice() );
        $this->addFileSlice("[config]", $this->getConfigSlice() );
    }

    //
    // database query methods
    //

    /**
     * @return XRef_Class[]
     */
    public function getAllClasses() {
        $all_classes = array();
        foreach ($this->classes as $class_name => $list) {
            $all_classes = array_merge($all_classes, $list);
        }
        return $all_classes;
    }

    /**
     * @param string $class_name
     * @return XRef_LookupResult
     */
    public function lookupClass($class_name) {
        $lc_name = strtolower($class_name);
        if (isset($this->classes[$lc_name])) {
            return new XRef_LookupResult(XRef_LookupResult::FOUND, $this->classes[$lc_name]);
        } else {
            return self::$lookupResultNotFound;
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

    /** @return XRef_LookupResult */
    public function lookupFunction($function_name) {
        $lc_name = strtolower($function_name);
        if (isset($this->functions[$lc_name])) {
            return new XRef_LookupResult(XRef_LookupResult::FOUND, $this->functions[$lc_name]);
        } else {
            return self::$lookupResultNotFound;
        }
    }


    /**
     * @private
     *
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

    //
    // internal methods collect info about internal/system classes & functions
    // using reflection API
    //
    private static $internalClassesAndFunctions = null;
    private static $hasTraits;

    /**
     * returns array( "classes" => XRef_Class[], "functions" => XRef_Function[])
     * with internal (system defined) classes, traits, interfaces and functions.
     */
    private function getInternalSlice() {
        if (! self::$internalClassesAndFunctions) {

            $classes = array();
            foreach (get_declared_classes() as $class_name) {
                $rc = new ReflectionClass($class_name);
                if ($rc->isInternal()) {
                    $classes[$class_name] = $this->getClassByReflection($rc);
                }
            }
            foreach (get_declared_interfaces() as $class_name) {
                $rc = new ReflectionClass($class_name);
                if ($rc->isInternal()) {
                    $classes[$class_name] = $this->getClassByReflection($rc);
                }
            }

            $functions = array();
            $defined_functions = get_defined_functions();
            foreach ($defined_functions["internal"] as $function_name) {
                if (isset(self::$overrideInternalFunctions[$function_name])) {
                    $functions[$function_name] = self::getFunctionFromString(self::$overrideInternalFunctions[$function_name]);
                } else {
                    $rf = new ReflectionFunction($function_name);
                    $functions[$function_name] = $this->getFunctionByReflection($rf);
                }
            }

            //
            // add functions that are defined in extensions that the given PHP runtime may miss
            // e.g. my dev box misses apc extension
            $override_list = array(
                "apc_fetch"     => 'apc_fetch($key, &$success = null)',
                'apc_dec'       => 'apc_dec($key, $step = 1, &$success = null)',
                'apc_inc'       => 'apc_inc($key, $step = 1, &$success = null)',
                'pcntl_waitpid' => 'pcntl_waitpid ( $pid, &$status, $options = 0)',
                'pcntl_wait'    => 'pcntl_wait ( &$status, $options = 0)',
            );
            foreach ($override_list as $function_name => $str) {
                if (! isset($functions[$function_name])) {
                    $functions[$function_name] = self::getFunctionFromString($str);
                }
            }

            self::$internalClassesAndFunctions = array(
                "classes"   => array_values($classes),
                "functions" => array_values($functions),
            );
        }

        return self::$internalClassesAndFunctions;
    }

    /**
     * returns array with config-defined functions
     * (see config key "lint.add-function-signature" in README)
     */
    private function getConfigSlice() {
        // config-defined functions & class methods
        $functions = array();
        $classes = array();
        foreach (XRef::getConfigValue("lint.add-function-signature", array()) as $str) {
            $function = self::getFunctionFromString($str);
            if ($function->className) {
                $cl_name = strtolower($function->className);
                if (isset($classes[$cl_name])) {
                    $c = $classes[$cl_name];
                } else {
                    $c = new XRef_Class();
                    $c->index = -1;
                    $c->nameIndex = -1;
                    $c->bodyStarts = -1;
                    $c->bodyEnds = -1;
                    $c->kind = T_CLASS;
                    $c->name = $function->className;
                    $classes[$cl_name] = $c;
                }
                $c->methods[] = $function;
            } else {
                $functions[] = $function;
            }
        }
        return array('classes' => array_values($classes), 'functions' => $functions);
    }

   /**
     * @param ReflectionClass $rc
     * @return XRef_Class
     */
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

    /**
     * @param ReflectionMethod $rm
     * @return XRef_Function
     */
    private function getMethodByReflection(ReflectionMethod $rm) {
        $m = new XRef_Function();
        $m->name = $rm->getName();
        $m->index = $m->bodyStarts = $m->bodyEnds = $m->nameIndex = $m->nameStartIndex = -1;
        $m->isDeclaration = false;
        $m->attributes = $this->getAttributes($rm, true);
        $m->returnsReference = $rm->returnsReference();
        foreach ($rm->getParameters() as /** @var ReflectionParameter $param */ $rp) {
            $p = new XRef_FunctionParameter();
            $p->hasDefaultValue = $rp->isOptional();
            $p->name = $rp->getName();
            $p->isPassedByReference = $rp->isPassedByReference();
            $m->parameters[] = $p;
        }
        return $m;
    }

    /**
     * @param ReflectionMethod $rm
     * @return XRef_Function
     */
    private function getFunctionByReflection(ReflectionFunction $rf) {
        $m = new XRef_Function();
        $m->name = $rf->getName();
        $m->index = $m->bodyStarts = $m->bodyEnds = $m->nameIndex = $m->nameStartIndex = -1;
        $m->isDeclaration = false;
        $m->returnsReference = $rf->returnsReference();
        foreach ($rf->getParameters() as /** @var ReflectionParameter $param */ $rp) {
            $p = new XRef_FunctionParameter();
            $p->hasDefaultValue = $rp->isOptional();
            $p->name = $rp->getName();
            $p->isPassedByReference = $rp->isPassedByReference();
            $m->parameters[] = $p;
        }
        return $m;
    }

    // input: string like
    //      "my_function($a, $b = null, &$c)"
    //      "MyClass::myMethod($a, $b, &$c)"
    //      "namespace\MyClass::method()"
    //      "?::myMethod($a, $b, &$c)"
    // output: XRef_Function object
    public static function getFunctionFromString($str) {
        // TODO: tokenize all $str and get rig of regular expressions
        if (!preg_match('#^\\s*(?:([\\w\\\\]+|\\?)::)?([\\w\\\\]+)\\s*\\((.*)\\)\\s*$#', $str, $matches)) {
            throw new Exception("Can't parse function specification from config file: $str");
        }

        $function = new XRef_Function();
        $function->name = $matches[2];
        $function->className = ($matches[1]) ? $matches[1] : null;
        $function->index = $function->bodyStarts = $function->bodyEnds = $function->nameIndex = $function->nameStartIndex = -1;

        if (strlen($matches[3])) {
            $arg_list = explode(',', $matches[3]);
            for ($i = 0; $i < count($arg_list); ++$i) {
                $t = $arg_list[$i];
                if (preg_match('#^\\s*(&)?\s*(\\$\\w+|\\.\\.\\.)(\\s*=)?#', $t, $matches)) {
                    $p = new XRef_FunctionParameter();
                    $p->isPassedByReference = (bool) $matches[1];
                    $p->name = ($matches[2] == '...') ? '...' : substr($matches[2], 1);
                    $p->hasDefaultValue = count($matches) > 3 && $matches[3];
                    $function->parameters[] = $p;
                } else {
                    throw new Exception("Can't parse function parameter '$t' in $str");
                }
            }
        }

        return $function;
    }


    /**
     * @param string $name
     * @return XRef_Constant
     */
    private function getConstantByReflection($name) {
        $const = new XRef_Constant();
        $const->name = $name;
        $const->index = -1;
        $const->attributes = 0;
        return $const;
    }

    /**
     * @param ReflectionProperty $rp
     * @return XRef_Property
     */
    private function getPropertyByReflection(ReflectionProperty $rp) {
        $p = new XRef_Property();
        $p->name = $rp->getName();
        $p->attributes = $this->getAttributes($rp, false);
        return $p;
    }

    /**
     * @param  $r - instance of any of Reflection* classes
     * @param bool $isMethod
     * @return int - bitmask of XRef::MASK_* attributes
     */
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
