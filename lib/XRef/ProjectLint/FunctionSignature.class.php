<?php
class XRef_ProjectLint_FunctionSignature extends XRef_APlugin implements XRef_IProjectLintPlugin {

    public function checkFileSlice(XRef_IProjectDatabase $db, $file_name, $file_slice)
    {
        // TODO: Implement checkFileSlice() method.
    }

    public function __construct() {
        parent::__construct("function-signature", "Check functions' signatures and parameters");
    }

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

        for ($i=0; $i<count($tokens); ++$i) {
            $t = $tokens[$i];

            // $this->foo();
            // Foo\Barr::foo();
            if ($t->text == '(') {
                $p = $t->prevNS();
                if ($p->kind != T_STRING) {
                    continue;
                }

                $pp = $p->prevNS();
                if ($pp->kind == T_NS_SEPARATOR) {
                    // Foo\bar();
                    continue; // TODO
                } elseif ($pp->kind == T_OBJECT_OPERATOR) {
                    // something->bar()
                    continue; // TODO
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
                echo $function_name, " ", $num_of_arguments, "\n";
                $lr = $db->lookupFunction($function_name);
                if ($lr->code == XRef_LookupResult::NOT_FOUND) {
                    echo " -> unknown function\n";
                } else {
                    $f = $lr->elements[0];
                    if ($num_of_arguments != count($f->parameters)) {

                        $min_number_of_arguments = 0;
                        foreach ($f->parameters as $p) {
                            if ($p->hasDefaultValue || $p->name = '...') {
                                break;
                            }
                            $min_number_of_arguments++;
                        }

                        $last_parameter = end($f->parameters);
                        $max_number_of_arguments = ($last_parameter->name = '...') ? 10000 : count($f->parameters);
                        if ($num_of_arguments < $min_number_of_arguments || $num_of_arguments > $max_number_of_arguments) {
                            echo " --> wrong number of arguments ($min_number_of_arguments, $num_of_arguments, $max_number_of_arguments)\n";
                            print_r($f);
                        }
                    }
                }
            }
        }
    }

    /**
     * @return array - map (errorCode => errorDescription)
     */
    public function getErrorMap() {
        return array();
    }

    /** @return array - map (file name -> list of errors) */
    public function getProjectReport(XRef_IProjectDatabase $db) {
        return array();
    }

    public function startLintCheck(XRef_IProjectDatabase $db) {
    }
}

