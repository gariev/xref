<?php
class XRef_ProjectLint_FunctionSignature extends XRef_APlugin implements XRef_IProjectLintPlugin {

    const E_UNKNOWN_FUNCTION            = 'xr091';
    const E_UNQUALIFIED_METHOD          = 'xr092';
    const E_WRONG_NUMBER_OF_ARGS        = 'xr093';
    const E_WRONG_CONSTRUCTOR_ARGS      = 'xr094';
    const E_DEFAULT_CONSTRUCTOR         = 'xr095';

    const F_FULLY_QUALIFIED_FUNC    = 1;    // \Foo\bar();
    const F_NOT_QUALIFIED_FUNC      = 2;    // bar();
    const F_CLASS_METHOD            = 3;    // Foo::bar(); parent::bar();
    const F_CLASS_CONSTRUCTOR       = 4;    // new Foo(); parent::__construct();

    const ELLIPSES_ARGS             = 10000;

    /**
     * @return array - map (errorCode => errorDescription)
     */
    public function getErrorMap() {
        return array(
            self::E_UNKNOWN_FUNCTION => array(
                "severity"  => XRef::WARNING,
                "message"   => "Unknown function (%s)",
            ),
            self::E_UNQUALIFIED_METHOD => array(
                "severity"  => XRef::WARNING,
                "message"   => "Possible call of method (%s) of class (%s) as function",
            ),
            self::E_WRONG_NUMBER_OF_ARGS => array(
                "severity"  => XRef::WARNING,
                "message"   => "Wrong number of arguments for function/method (%s): (%s) instead of (%s)",
            ),
            self::E_WRONG_CONSTRUCTOR_ARGS => array(
                "severity"  => XRef::WARNING,
                "message"   => "Wrong number of arguments for constructor of class (%s): (%s) instead of (%s)",
            ),
            self::E_DEFAULT_CONSTRUCTOR => array(
                "severity"  => XRef::WARNING,
                "message"   => "Default constructor of class (%s) doesn't accept arguments",
            ),
        );
    }


    public function __construct() {
        parent::__construct("function-signature", "Check functions' signatures and parameters");
    }

    // map: filename => list of errors
    private $errors = array();

    /**
     * @param XRef_ParsedFile $pf
     * @param bool $is_library_file
     * @return * any data
     */
    public function createFileSlice(XRef_IParsedFile $pf, $is_library_file = false) {

        /** array of arrays with info about called functions and methods */
        $called_functions = array();
        /** map: (function call info => true), to report any call once only */
        $uniqs = array();
        /** map of function names, checked for existence by function_exists() */
        $checked_for_functions = array();

        $tokens = $pf->getTokens();
        for ($i=0; $i<count($tokens); ++$i) {
            $t = $tokens[$i];

            // $this->foo();
            // Foo\Barr::foo();
            if ($t->text == '(') {
                $p = $t->prevNS();
                if ($p->kind != T_STRING) {
                    continue;
                }

                // array with parts of the name (T_NS_SEPARATOR && T_STRING)
                $function_name_parts = array($p->text);
                $class_name = '';

                $pp = $p->prevNS();
                if ($pp->kind == T_NS_SEPARATOR) {
                    // Foo\bar();
                    // \bar();
                    while ($pp->kind == T_NS_SEPARATOR || $pp->kind == T_STRING) {
                        array_unshift($function_name_parts, $pp->text);
                        $pp = $pp->prevNS();
                    }
                } elseif ($pp->kind == T_OBJECT_OPERATOR) {
                    // $var->bar()
                    // $this->bar();
                    $pp = $pp->prevNS();
                    if ($pp->kind == T_VARIABLE && $pp->text == '$this') {
                        $class_name = 'self'; // will be resolved later
                    } else {
                        // TODO
                        continue;
                    }
                } elseif ($pp->kind == T_DOUBLE_COLON) {
                    // Foo::bar();
                    // Foo\Bar::bar();
                    // self::foo();
                    // static::bar();
                    $pp = $pp->prevNS();
                    while ($pp->kind == T_NS_SEPARATOR || $pp->kind == T_STRING || $pp->kind == T_STATIC) {
                        $class_name = $pp->text . $class_name;
                        $pp = $pp->prevNS();
                    }
                }

                if ($pp->text == '&') {
                    $pp = $pp->prevNS();
                }
                if ($pp->kind == T_FUNCTION) {
                    // skip function declarations
                    continue;
                }

                $arguments = $pf->extractList($t->nextNS());
                $num_of_arguments = count($arguments);
                $from_class = $pf->getClassAt($t->index);
                $from_class_name = ($from_class) ? $from_class->name : '';

                if ($pp->kind == T_NEW) {
                    // new Something();
                    // new ns\Another\Something();
                    // new \Foo();
                    $class_name = implode('', $function_name_parts);
                    if ($class_name == 'static' || $class_name == 'self') {
                        $class_name = $from_class_name;
                    } elseif ($class_name == 'parent') {
                        $class_name = ($from_class && $from_class->extends)
                            ? $from_class->extends[0]
                            : null;
                    } else {
                        $class_name = $pf->qualifyName($class_name, $t->index);
                    }

                    if ($class_name) {
                        $uniq = "new##$class_name#$num_of_arguments";
                        if (!isset($uniqs[$uniq])) {
                            $uniqs[$uniq] = true;
                            $called_functions[] = array(self::F_CLASS_CONSTRUCTOR, null, $class_name, $t->lineNumber, $num_of_arguments);
                        }
                    }
                } elseif ($class_name) {
                    // method call:
                    //  $this->foo();
                    //  self::foo();
                    //  Foo::bar();
                    //  \bar\Baz::foo();
                    if ($class_name == 'static' || $class_name == 'self') {
                        $class_name = $from_class_name;
                    } elseif ($class_name == 'parent') {
                        $class_name = ($from_class && $from_class->extends)
                            ? $from_class->extends[0]
                            : null;
                    } else {
                        $class_name = $pf->qualifyName($class_name, $t->index);
                    }

                    if ($class_name) {
                        if (count($function_name_parts) > 1) {
                            throw new Exception("$class_name::" . implode('', $function_name_parts));
                        }
                        $function_name = $function_name_parts[0];
                        if ($function_name == '__construct') {
                            $uniq = "new##$class_name#$num_of_arguments";
                            if (!isset($uniqs[$uniq])) {
                                $uniqs[$uniq] = true;
                                $called_functions[] = array(self::F_CLASS_CONSTRUCTOR, null, $class_name, $t->lineNumber, $num_of_arguments);
                            }

                        } else {
                            $uniq = "$function_name##$class_name#$num_of_arguments";
                            if (!isset($uniqs[$uniq])) {
                                $uniqs[$uniq] = true;
                                $called_functions[] = array(self::F_CLASS_METHOD, $function_name, $class_name, $t->lineNumber, $num_of_arguments);
                            }
                        }
                    }
                } else {
                    // function call: qualified or unqualified
                    // foo();
                    // foo\bar();
                    // \foo\bar\baz();
                    if (count($function_name_parts) > 1) {
                        $function_name = $pf->qualifyName(implode('', $function_name_parts), $t->index);
                        $uniq = "$function_name##$num_of_arguments";
                        if (!isset($uniqs[$uniq])) {
                            $uniqs[$uniq] = true;
                            $called_functions[] = array(self::F_FULLY_QUALIFIED_FUNC, $function_name, null, $t->lineNumber, $num_of_arguments);
                        }
                    } else {
                        $function_name = $function_name_parts[0];
                        $namespace = $pf->getNamespaceAt($t->index);
                        $namespace_name = ($namespace) ? $namespace->name : '';
                        $uniq = "$function_name##$namespace_name#$num_of_arguments";
                        if (!isset($uniqs[$uniq])) {
                            $uniqs[$uniq] = true;
                            $called_functions[] = array(self::F_NOT_QUALIFIED_FUNC, $function_name, $namespace_name, $t->lineNumber, $num_of_arguments, $from_class_name);
                        }
                    }

                    if ($function_name == 'function_exists') {
                        $n = $t->nextNS();
                        if ($n->kind == T_CONSTANT_ENCAPSED_STRING) {
                            $checked_for_function_name = trim($n->text, '\'"');
                            $checked_for_functions[$checked_for_function_name] = true;
                        }
                    }
                }
            }
        }

        $file_slice = array("called" => $called_functions, "checked" => $checked_for_functions);
        return $file_slice;
    }

    public function checkFileSlice(XRef_IProjectDatabase $db, $file_name, $file_slice) {
        $checked_for_functions = $file_slice["checked"];

        foreach ($file_slice["called"] as $s) {
            list ($kind, $function_name, $extra, $line_number, $num_of_arguments) = $s;

            if ($kind == self::F_CLASS_METHOD) {
                $class_name = $extra;
                $lr = $db->lookupMethod($class_name, $function_name);
                if ($lr->code != XRef_LookupResult::FOUND) {
                    // don't report error here, CheckClassAccess will do
                    // missed methods and missed classes/base classes are covered by CheckClassAccess plugin
                    continue;
                }
            } elseif ($kind == self::F_CLASS_CONSTRUCTOR) {
                $class_name = $extra;
                // try to find php5 constructor
                $lr = $db->lookupMethod($class_name, '__construct');
                if ($lr->code != XRef_LookupResult::FOUND) {
                    // or try to find php4 constructor
                    $lr = $db->lookupMethod($class_name, $class_name);
                }

                if ($lr->code == XRef_LookupResult::FOUND) {
                    // ok, found, check it later
                } elseif ($lr->code == XRef_LookupResult::CLASS_MISSING) {
                    // hm, can't validate because class or base class is missing
                    continue;
                } else {
                    // no constructor found
                    // default constructor with 0 arguments will be called
                    if ($num_of_arguments != 0) {
                        $this->errors[$file_name][] = array(
                            'code'      => self::E_DEFAULT_CONSTRUCTOR,
                            'text'      => $class_name,
                            'params'    => array($class_name),
                            'location'  => array($file_name, $line_number),
                        );
                    }
                    continue;
                }
            } elseif ($kind == self::F_NOT_QUALIFIED_FUNC) {
                // that's tricky -
                // foo() can be
                //  1. current \namespace\foo()
                //  2. global foo()
                //  3. method of current class (self::foo()) without class prefix, error

                $lr = null;
                $namespace = $extra;
                if ($namespace) {
                    // 1. try to find \namespace\foo()
                    $lr = $db->lookupFunction("$namespace\\$function_name");
                    if ($lr->code == XRef_LookupResult::FOUND) {
                        $function_name = "$namespace\\$function_name";
                    }
                }

                if (!$lr || $lr->code != XRef_LookupResult::FOUND) {
                    // 2. try to find global foo()
                    $lr = $db->lookupFunction($function_name);
                }

                if ($lr->code != XRef_LookupResult::FOUND) {
                    // 3. try to find CurrentClass::foo()
                    $from_class = $s[5];
                    if ($from_class) {
                        $lr = $db->lookupMethod($from_class, $function_name);
                        if ($lr->code == XRef_LookupResult::FOUND) {
                            // oops, we did find it
                            // looks like error
                            $this->errors[$file_name][] = array(
                                'code'      => self::E_UNQUALIFIED_METHOD,
                                'text'      => $function_name,
                                'params'    => array($function_name, $from_class),
                                'location'  => array($file_name, $line_number),
                            );
                            continue;
                        }
                    }
                }

                if ($lr->code != XRef_LookupResult::FOUND) {
                    if (! isset($checked_for_functions[$function_name])) {
                        $this->errors[$file_name][] = array(
                            'code'      => self::E_UNKNOWN_FUNCTION,
                            'text'      => $function_name,
                            'params'    => array($function_name),
                            'location'  => array($file_name, $line_number),
                        );
                    }
                    continue;
                }

            } elseif ($kind == self::F_FULLY_QUALIFIED_FUNC) {
                $lr = $db->lookupFunction($function_name);
                if ($lr->code != XRef_LookupResult::FOUND) {
                    if (! isset($checked_for_functions[$function_name])) {
                        $this->errors[$file_name][] = array(
                            'code'      => self::E_UNKNOWN_FUNCTION,
                            'text'      => $function_name,
                            'params'    => array($function_name),
                            'location'  => array($file_name, $line_number),
                        );
                    }
                    continue;
                }
            } else {
                throw new Exception($kind);
            }

            // here check the number of arguments of found method/function
            if ($lr->code != XRef_LookupResult::FOUND) {
                throw new Exception($lr->code);
            }

            $f = $lr->elements[0];

            // if the number of arguments doesn't match the number of parameters,
            // (and the function called doesn't call func_get_args()
            if ($num_of_arguments != count($f->parameters)
                && ($f->flags & XRef_ProjectDatabase::FLAG_CALLS_GET_ARGS ) == 0)
            {

                $min_number_of_arguments = 0;
                foreach ($f->parameters as $p) {
                    if ($p->hasDefaultValue || $p->name == '...') {
                        break;
                    }
                    $min_number_of_arguments++;
                }

                $last_parameter = end($f->parameters);
                $max_number_of_arguments = ($last_parameter && $last_parameter->name == '...') ?
                    self::ELLIPSES_ARGS : count($f->parameters);
                if ($num_of_arguments < $min_number_of_arguments || $num_of_arguments > $max_number_of_arguments) {
                    if ($min_number_of_arguments == $max_number_of_arguments) {
                        $arg_str = $min_number_of_arguments;
                    } elseif ($max_number_of_arguments == self::ELLIPSES_ARGS) {
                        $arg_str = $min_number_of_arguments . "..n";
                    } else {
                        $arg_str = $min_number_of_arguments . ".." . $max_number_of_arguments;
                    }

                    if ($kind == self::F_CLASS_CONSTRUCTOR) {
                        $this->errors[$file_name][] = array(
                            'code'      => self::E_WRONG_CONSTRUCTOR_ARGS,
                            'text'      => $class_name,
                            'params'    => array($class_name, $num_of_arguments, $arg_str),
                            'location'  => array($file_name, $line_number),
                        );
                    } else {
                        $this->errors[$file_name][] = array(
                            'code'      => self::E_WRONG_NUMBER_OF_ARGS,
                            'text'      => $function_name,
                            'params'    => array($function_name, $num_of_arguments, $arg_str),
                            'location'  => array($file_name, $line_number),
                        );
                    }
                }
            }
        }
    }



    /** @return array - map (file name -> list of errors) */
    public function getProjectReport(XRef_IProjectDatabase $db) {
        return $this->errors;
    }

    public function startLintCheck(XRef_IProjectDatabase $db) {
        $this->errors = array();
    }
}

