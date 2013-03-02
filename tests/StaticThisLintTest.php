<?php

require_once dirname(__FILE__) . "/BaseLintTest.php";

/*
Test for $this used out of instance method context.

Special case: file where no other methods or classes defined and has $this
may be included in other file (joomla):
     <?php
     $this->foo;             // warning: $this in global scope
     EOF

All other cases:
     <?php
     echo $this->foo;        // error
     function foo {
         echo $this->foo;    // error
     }
     class Bar {
         $this->bar;         // error
         public static function foo() {
             $this;          // error
         }
         public function bar() {
             $this;          // ok
     }

*/
class StaticThisLintTest extends BaseLintTest {

   public function testOuterScopeThis() {
        $testPhpCode = '
        <?php
            echo $this->foo;
        ';
        $exceptedDefects = array(
            array('$this', 3, XRef::WARNING),
        );
        $this->checkPhpCode($testPhpCode, $exceptedDefects);
    }

    public function testOutOfContextThis() {
        $testPhpCode = '
        <?php
        echo $this->foo;        // error
        function foo () {
            echo $this->foo;    // error
        }
        class Bar {
            $this->bar;         // error
            public static function foo() {
                $this;          // error
            }
            public function bar() {
                $this;          // ok
            }
        }
        echo $this->lastTime(); // error
        ';

        $exceptedDefects = array(
            array('$this', 3, XRef::ERROR),
            array('$this', 5, XRef::ERROR),
            array('$this', 8, XRef::ERROR),
            array('$this', 10, XRef::ERROR),
            array('$this', 16, XRef::ERROR),
        );
        $this->checkPhpCode($testPhpCode, $exceptedDefects);
    }
}

