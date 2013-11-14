<?php
/**
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

$pluginDir = dirname(__FILE__);
require_once "$pluginDir/Methods.php";

class XRef_Doc_MethodsPHP extends XRef_Plugin_Methods {
    public function __construct() {
        parent::__construct("php-methods", "List of PHP methods and functions", false, XRef::FILETYPE_PHP);
    }
}

// vim: tabstop=4 expandtab

