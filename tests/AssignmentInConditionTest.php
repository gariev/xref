<?php

require_once dirname(__FILE__) . "/BaseLintClass.php";

class AssignmentInConditionTest extends BaseLintClass {

    public function testClassConstants() {
        $testPhpCode = '<?php

            $foo = 1; $bar = 2; $baz = 3; $a = 4; $b = 5;

            if ($foo = null) ;              // warning
            if ($bar = 1 && $baz==2) ;      // warning
            if ($a === true && $b = false); // warning

            if ($fh = fopen("file", "w")) ; // ok
            if ($ch = curl_init());         // ok
            if (someFunc($firstParam = 1)); // ok
        ';
        $exceptedDefects = array(
            array('null',   5, XRef::WARNING),
            array('1',      6, XRef::WARNING),
            array('false',  7, XRef::WARNING),
        );
        $this->checkPhpCode($testPhpCode, $exceptedDefects);
    }
}

