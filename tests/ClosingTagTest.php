<?php

require_once dirname(__FILE__) . "/BaseLintClass.php";

class ClosingTagTest extends BaseLintClass {

    public function testOpeningSpaces() {

        // empty php file, everything is ok
        $testPhpCode = '<?php
        ';
        $exceptedDefects = array(
        );
        $this->checkPhpCode($testPhpCode, $exceptedDefects);


        // space in the beginning of the file
        $testPhpCode = ' <?php
        ';
        $exceptedDefects = array(
            array('\\x20',   1, XRef::WARNING),
        );
        $this->checkPhpCode($testPhpCode, $exceptedDefects);

        //
        $testPhpCode = '
        Some meaningful html text in the file
        <?php
            // should be ok
        ';
        $exceptedDefects = array(
        );
        $this->checkPhpCode($testPhpCode, $exceptedDefects);

        // BOM at the begining of the file
        $testPhpCode = "\xef\xbb\xbf<?php
        ";
        $exceptedDefects = array(
            array("\\xef\\xbb\\xbf",   1, XRef::WARNING),
        );
        $this->checkPhpCode($testPhpCode, $exceptedDefects);

        // BOM and spaces at the begining of the file
        $testPhpCode = "\xef\xbb\xbf <?php
        ";
        $exceptedDefects = array(
            array("\\xef\\xbb\\xbf\\x20",   1, XRef::WARNING),
        );
        $this->checkPhpCode($testPhpCode, $exceptedDefects);

        // non-breakable space (like from pasting text from skype)
        $testPhpCode = "\xa0<?php
        ";
        $exceptedDefects = array(
            array("\\xa0",   1, XRef::WARNING),
        );
        $this->checkPhpCode($testPhpCode, $exceptedDefects);
     }

    public function testClosingTag() {

        // closing tag
        $testPhpCode = '<?php

        ?>';
        $exceptedDefects = array(
            array("?".">",   3, XRef::WARNING),
        );

        $this->checkPhpCode($testPhpCode, $exceptedDefects);

        // closing tag with spaces
        $testPhpCode = '<?php

        ?>
        ';
        $exceptedDefects = array(
            array("?".">\\x0a",   3, XRef::WARNING),
        );
        $this->checkPhpCode($testPhpCode, $exceptedDefects);

        // closing tag with text, should be ok
        $testPhpCode = '<?php

        ?>
        Some real text
        ';
        $exceptedDefects = array(
        );
        $this->checkPhpCode($testPhpCode, $exceptedDefects);

    }
}

