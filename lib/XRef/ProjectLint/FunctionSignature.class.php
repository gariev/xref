<?php
class XRef_ProjectLint_FunctionSignature extends XRef_APlugin implements XRef_IProjectLintPlugin {

    const E_UNKNOWN_FUNCTION        = 'xr091';
    const E_UNQUALIFIED_METHOD      = 'xr092';
    const E_WRONG_NUMBER_OF_ARGS    = 'xr093';

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
        );
    }


    public function __construct() {
        parent::__construct("function-signature", "Check functions' signatures and parameters");
    }

    // map: filename => list of errors
    private $errors = array();

    /**
     * Parsing of files is expensive, so minimize them between runs of xref.
     * Instead, parse file once and process it into summary (database & plugin slices),
     * all of which will be serialized and stored.
     *
     * DB slices will make up project database and will be all in memory,
     * so try to keep their size to minimum
     *
     * File summary will be (iteratively) available to lint plugin.
     * TODO: right now all of them are still in memory
     *
     * @param XRef_ParsedFile $pf
     * @param bool $is_library_file
     * @return * any data
     */
    public function createFileSlice(XRef_IParsedFile $pf, $is_library_file = false) {
        $tokens = $pf->getTokens();

        $db = new XRef_ProjectDatabase();
        $db->finalize();
        $file_slice = array();
        $uniqs = array();

        for ($i=0; $i<count($tokens); ++$i) {
            $t = $tokens[$i];

            // $this->foo();
            // Foo\Barr::foo();
            if ($t->text == '(') {
                $p = $t->prevNS();
                if ($p->kind != T_STRING) {
                    continue;
                }

                $function_name = null;
                $class_name = '';

                $pp = $p->prevNS();
                if ($pp->kind == T_NS_SEPARATOR) {
                    // Foo\bar();
                    // \bar();
                    continue; // TODO
                } elseif ($pp->kind == T_OBJECT_OPERATOR) {
                    // something->bar()
                    $pp = $pp->prevNS();
                    if ($pp->kind == T_VARIABLE && $pp->text == '$this') {
                        $class = $pf->getClassAt($t->index);
                        if ($class) {
                            $class_name = $class->name;
                            $function_name = $p->text;
                        } else {
                            continue;
                        }
                    } else {
                        continue; // TODO
                    }
                } elseif ($pp->kind == T_DOUBLE_COLON) {
                    // Foo::bar();
                    // Foo\Bar::bar();
                    continue; // TODO
                } else {
                    $function_name = $p->text;
                }

                if ($pp->text == '&') {
                    $pp = $pp->prevNS();
                }
                if ($pp->kind == T_FUNCTION) {
                    // skip function declarations
                    continue;
                }
                if ($pp->kind == T_NEW) {
                    // new Something();
                    continue; // TODO
                }

                $arguments = $pf->extractList($t->nextNS());
                $num_of_arguments = count($arguments);
                $from_class = $pf->getClassAt($t->index);
                $from_class_name = ($from_class) ? $from_class->name : '';
                $namespace = $pf->getNamespaceAt($t->index);
                $uniq = "$class_name::$function_name#$num_of_arguments#$from_class_name#$namespace";
                if (!isset($uniqs[$uniq])) {
                    $uniqs[$uniq] = true;
                    $file_slice[] = array($class_name, $function_name, $t->lineNumber, $num_of_arguments, $from_class_name, $namespace);
                }
            }
        }
        return $file_slice;
    }

    public function checkFileSlice(XRef_IProjectDatabase $db, $file_name, $file_slice) {
        foreach ($file_slice as $s) {
            list ($class_name, $function_name, $line_number, $num_of_arguments, $from_class, $namespace) = $s;

            if ($class_name) {
                $lr = $db->lookupMethod($class_name, $function_name);
            } else {
                $lr = $db->lookupFunction($function_name);
            }

            if ($lr->code == XRef_LookupResult::CLASS_MISSING) {
                // method not found because class/base class is missing
                // don't report error here, CheckClassAccess will do
                continue;
            } elseif ($lr->code == XRef_LookupResult::NOT_FOUND) {
                // check only missed functions;
                // missed methods are covered by CheckClassAccess plugin
                if (!$class_name) {
                    // check if there is a method with the same name in class
                    if ($from_class) {
                        $lr = $db->lookupMethod($from_class, $function_name);
                    }

                    if ($lr->code == XRef_LookupResult::FOUND) {
                        $this->errors[$file_name][] = array(
                            'code'      => self::E_UNQUALIFIED_METHOD,
                            'text'      => $function_name,
                            'params'    => array($function_name, $from_class),
                            'location'  => array($file_name, $line_number),
                        );
                    } else {
                        $this->errors[$file_name][] = array(
                            'code'      => self::E_UNKNOWN_FUNCTION,
                            'text'      => $function_name,
                            'params'    => array($function_name),
                            'location'  => array($file_name, $line_number),
                        );
                    }
                }
            } elseif ($lr->code == XRef_LookupResult::FOUND) {
                // method/function is found; check the number of arguments
                $f = $lr->elements[0];
                if ($num_of_arguments != count($f->parameters)) {

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
                        $this->errors[$file_name][] = array(
                            'code'      => self::E_WRONG_NUMBER_OF_ARGS,
                            'text'      => $function_name,
                            'params'    => array($function_name, $num_of_arguments, $arg_str),
                            'location'  => array($file_name, $line_number),
                        );
                    }
                }
            } else {
                throw new Exception($lr->code);
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

