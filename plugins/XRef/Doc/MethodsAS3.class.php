<?php
/**
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

$pluginDir = dirname(__FILE__);
require_once "$pluginDir/Methods.php";

class XRef_Doc_MethodsAS3 extends XRef_Plugin_Methods {
    public function __construct() {
        parent::__construct("as3-methods", "List of ActionScript methods", true, XRef::FILETYPE_AS3);
    }
}

// vim: tabstop=4 expandtab

