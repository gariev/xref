<?php

/**
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */
class XRef_ProjectLint_MissedParentConstructor extends XRef_APlugin implements XRef_IProjectLintPlugin {

    const E_MISSED_CALL_TO_PARENT_CONSTRUCTOR = "xr081";
    const E_CANT_TELL_IF_PARENT_CALLED = "xr082";

    /** @var array - map (file name => XRef_CodeDefect[]) */
    private $errors = array();

    public function getErrorMap() {
        return array(
            self::E_MISSED_CALL_TO_PARENT_CONSTRUCTOR => array(
                "severity" => XRef::WARNING,
                "message" => "Class (%s) doesn't call constructor of it's base class (%s)",
            ),
            self::E_CANT_TELL_IF_PARENT_CALLED => array(
                "severity" => XRef::NOTICE,
                "message" => "Class (%s) calls call_user_func() and may call constructor of its base class",
            )

        );
    }

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
            $lr = $db->lookupMethod($class_name, '__construct', $parent_only = true);
            // if the base class has constructor, and the constructor is not abstract
            // (it's valid to declare constructor in PHP's interfaces)
            if ($lr->code == XRef_LookupResult::FOUND && !is_null($lr->elements[0]->bodyStarts)) {
                // oops, child doesn't call parent's constructor
                $base_class_name = $lr->elements[0]->className;

                // check that the method itself doesn't call call_user_func()
                // if it does, that it can indirectly call the base class constructor
                // See Swiftmailer for examples
                $lr = $db->lookupMethod($class_name, '__construct');
                if ($lr
                    && $lr->code == XRef_LookupResult::FOUND
                    && ($lr->elements[0]->flags & XRef_ProjectDatabase::FLAG_CALLS_USER_FUNC) != 0)
                {
                    // notice - can't tell if the parent constructor is called or not
                    $error_descr = array(
                         'code'      => self::E_CANT_TELL_IF_PARENT_CALLED,
                         'text'      => $class_name,
                         'params'    => array($class_name),
                         'location'  => array($file_name, $line_number),
                     );
                } else {
                    // warning
                    $error_descr = array(
                         'code'      => self::E_MISSED_CALL_TO_PARENT_CONSTRUCTOR,
                         'text'      => $class_name,
                         'params'    => array($class_name, $base_class_name),
                         'location'  => array($file_name, $line_number),
                     );
                }

                $this->errors[ $file_name ][] = $error_descr;
            }
        }
    }

    /** @return array - map (file name -> list of errors) */
    public function getProjectReport(XRef_IProjectDatabase $db) {
        return $this->errors;
    }
}
