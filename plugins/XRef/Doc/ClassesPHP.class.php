<?php
/**
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

$pluginDir = dirname(__FILE__);
require_once "$pluginDir/Classes.php";

class XRef_Doc_ClassesPHP extends XRef_Plugin_Classes {
    public function __construct() {
        parent::__construct("php-classes", "List of PHP classes", XRef::FILETYPE_PHP);
    }
}

// vim: tabstop=4 expandtab

