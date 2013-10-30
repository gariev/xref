<?php

/**
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */
class XRef_ProjectLint_MissedParentConstructor extends XRef_APlugin implements XRef_IProjectLintPlugin {

    const E_MISSED_CALL_TO_PARENT_CONSTRUCTOR = "exp20";

    private $errors = array();

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
