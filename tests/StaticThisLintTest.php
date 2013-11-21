<?php

require_once dirname(__FILE__) . "/BaseLintClass.php";

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
class StaticThisLintTest extends BaseLintClass {

   public function testOuterScopeThis() {
        $testPhpCode = '<?php

            echo $this->foo;
            self::foo();
            parent::foo();
        ';
        $exceptedDefects = array(
            array('$this',  3, XRef::WARNING),
            array('self',   4, XRef::WARNING),
            array('parent', 5, XRef::WARNING),
        );
        $this->checkPhpCode($testPhpCode, $exceptedDefects);
    }

    public function testOutOfContextThis() {
        $testPhpCode = '<?php

        echo $this->foo;        // error
        function foo () {
            echo $this->foo;    // error
            echo self::foo();   // error
            echo parent::foo(); // error
            $foo = new parent;  // error
            echo $foo->parent;  // ok
        }
        class Bar {
            public static function foo() {
                echo $this->f;  // error
                self::foo();    // ok
                parent::foo();  // ok
            }
            public function bar() {
                self::$foo;     // ok
                parent::bar();  // ok
                echo $this->f;  // ok
            }
        }
        echo $this->lastTime(); // error
        echo self::$foo;        // error
        echo parent::bar();     // error
        $foo = new parent();    // error
        echo $foo->parent;      // ok

        ';

        $exceptedDefects = array(
            array('$this', 3, XRef::ERROR),
            array('$this', 5, XRef::ERROR),
            array('self',  6, XRef::ERROR),
            array('parent',7, XRef::ERROR),
            array('parent',8, XRef::ERROR),
            array('$this', 13, XRef::ERROR),
            array('$this', 23, XRef::ERROR),
            array('self',  24, XRef::ERROR),
            array('parent',25, XRef::ERROR),
            array('parent',26, XRef::ERROR),
        );
        $this->checkPhpCode($testPhpCode, $exceptedDefects);

    }

    public function testTraits() {
        $testPhpCode = '<?php

            trait Foo {
                function bar () {
                    echo $this->foo;        // ok
                }
            }
        ';
        $exceptedDefects = array(
        );
        $this->checkPhpCode($testPhpCode, $exceptedDefects);
     }
}

