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

    public function __construct() {
        self::$lookupResultNotFound = new XRef_LookupResult(XRef_LookupResult::NOT_FOUND);
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
        $internal_classes = $this->getInternalClasses();
        $internal_functions = $this->getInternalFunctions();
        $internal_slice = array("classes" => $internal_classes, "functions" => $internal_functions);
        $this->addFileSlice("[internal]", $internal_slice);
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
    // internal methods collect info about internal/system classes
    // using reflection API
    //
    private static $internalClasses = null;
    private static $hasTraits;

    /**
     * @return XRef_Class[]
     */
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

    private static $internalFunctions = null;
    private function getInternalFunctions() {
        if (! self::$internalFunctions) {
            self::$internalFunctions = array();
            $defined_functions = get_defined_functions();
            foreach ($defined_functions["internal"] as $function_name) {
                $rf = new ReflectionFunction($function_name);
                self::$internalFunctions[] = $this->getFunctionByReflection($rf);
            }
        }
        return self::$internalFunctions;
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
            $m->parameters[] = $p;
        }

        // fix some weirdness - function signatures returned by reflection
        // (or by "php --rf <function-name>") are different from documentation
        if ($m->name == 'define') {
            $m->parameters[2]->hasDefaultValue = true;
        }
        if ($m->name == 'implode') {
            $m->parameters[1]->hasDefaultValue = true;
        }
        if ($m->name == 'spl_autoload_register') {
            if (count($m->parameters) < 2) {
                $p = new XRef_FunctionParameter();
                $p->hasDefaultValue = true;
                $p->name = 'throw';
                $m->parameters[] = $p;
            }
            if (count($m->parameters) < 3) {
                $p = new XRef_FunctionParameter();
                $p->hasDefaultValue = true;
                $p->name = 'prepend';
                $m->parameters[] = $p;
            }
        }

        return $m;
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