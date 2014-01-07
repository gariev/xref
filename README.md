
WHAT IS XREF?
=============

XRef is a set of tools to work with PHP source files. Currently it includes:

* xref-lint

    A lint, i.e. static code analysis tool that finds errors.
    PHP is not a perfect language to catch programmer's errors,
    so xref will do.
    See section "ERROR MESSAGES REPORTED BY LINT" below.

* xref-ci

    Continuous Integration tool to monitor your project repository and send e-mail
    notifications about errors.

* GIT lint plugin

    Just type 'git xref-lint' in your git repository root before doing a commit
    and you'll get lint report of modified files

* xref-doc

    A tool to create cross-reference documentation about your project
    (which classes/methods/properties are defined and where they are used)

* Try it online!

    <http://xref-lint.net/>

INSTALL
=======

PEAR installation
-----------------

```sh
pear channel-discover pear.xref-lint.net
pear install xref/XRef
```

Install from git/source code
----------------------------

```sh
git clone git@github.com:gariev/xref.git
export PATH=$PATH:xref/bin
```

Any of the above will give you working command-line lint tool xref-lint (or xref-lint.bat on Windows platform).

SETUP
=====

To get most of the xref package, configure it for your project after the installation.

```sh
cd <your-project-dir>
xref-lint --init
```

This will create a xref data directory (.xref), default config file (.xref/xref.ini) and
will index files for faster checks.


1.  Edit the config file (.xref/xref.ini). See section CONFIG FILE below;
    also see sample config in examples/xref.ini.sample

2.  Download and install Smarty template engine. <http://www.smarty.net/>;
    any of versions 2.x or 3.x should work, version 2 takes less memory.
    Set the path to Smarty.class.php file in *xref.smarty-class* param of config file.

3.  Configure web server to run scripts from web-scripts dir XRef/web-scripts/,
    see sample Apache config file in examples/httpd.conf. To view where
    examples and web-scripts are intalled, run 'xref-lint --help'.

4. Set up crontab to run xref-ci to monitor your project,
    see sample cronab script in examples/ci.crontab


REPORTED ERRORS
===============

* <a name="xr001"></a> **Can't parse file (%s)** (severity: fatal, code: xr001)

    There is a syntax error in source PHP file.

* <a name="xr010"></a> **Use of unknown variable (%s)** (severity: error, code: xr010)

    This error is caused by using a variable that was never
    introduced in this scope. Most often it's because of misspelled
    variable name or refactoring that went wrong.

    Sample code:

```php
    // COUNTEREXAMPLE
    function example1() {
        $message = "hello, world";
        echo $Message;  // <-- error: there is no variable $Message
    }
```

* <a name="xr011"></a> **Possible use of unknown variable (%s)** (severity: warning, code: xr011)

    Similar to **xr010**, but raised when xref can't reliable detect
    if variable can be legitimately present in this scope (the relaxed mode).

```php

    <?php
    //
    // COUNTEREXAMPLE
    //
    // global scope is always in relaxed mode -
    // variables defined in other files can be used here
    //
    echo $this_variable_may_be_declared_in_other_file;

    function foo($params, $var_name) {
        // functions starts in strict mode, but
        include "other_file.php";       // all of these
        extract($a);                    // makes xref switch to
        $$var_name = 1;                 // relaxed error-reporting
        eval("\$res = $expression_str");// mode

        echo $foo;                      // <-- can $foo be here? maybe.
    }
```

    How to get rid of this warning:

    - Test for existence of the variable before using it
    - Use doc comment annotations
    - rewrite the code - use array indexing instead of *extract()*,
    - if your project heavily depends on these features, disable
      the error code
      (see xref.ignore-error config parameter/command-line option below)

```php
    //
    // making variables known in relaxed mode
    //

    // checking for a var
    if (isset($foo)) {
        // ok, we know $foo now
    }

    // doc comment annotations
    /** @var MyClass $bar */
    $bar->doSomething();

    // rewrite the code:
    // use array indexing instead of extract, returning for of eval etc
    $baz = $params["baz"];
    $res = eval("return $expression_str");
```

* <a name="xr012"></a> **Possible use of unknown variable as function argument (%s)** (severity: warning, code: xr012)

    Similar to **xr010**, raised when an unknown variable is used as a parameter to a function with unknown signature.

```php
    // COUNTEREXAMPLE

    unknown_function($foo);     // <-- is $foo legitimate here?
                                // maybe, if unknown_function takes param
                                // by reference and assigns value to it

    function known_function(&$param) {
        $param = 1;
    }

    known_function($bar);       // no warning here - since
                                // known_function() is defined in the same
                                // file, xref knows its signature

 ```

    How to get rid out of this warning:

        - Initialize the variable with some appropriate value (e.g. null)
          before using it as parameter
        - add function signature with *lint.add-function-signature*
          config file/command-line parameter (see below)

* <a name="xr013"></a> **Array autovivification (%s)** (severity: warning, code: xr013)

    Similar to the above - when a value assigned to a variable that was never
    defined in array context, a new array is instantiated. This may be intended
    behavior or error; that's why it's a warning. It's always a good idea
    to initialize array variable with an empty array.

    Sample code:

```php
    // COUNTEREXAMPLE
    $letters = explode('', $str);
    $letter[] = '!';    // <-- a new array $letter is instantiated here.
                        // is it intended or array $letters should be here?
                        // to remove the warning, initialize var before usage:
                        // $letter = array();
```

* <a name="xr014"></a> **Scalar autovivification (%s)** (severity: warning, code: xr014)

    Similar to the above, caused by operations like ++, .= or +=
    on variables that were never initialized.
    This may be inteded behaviour or it can mask a real error.
    Initialize the variable in question before usage.

```php
    // COUNTEREXAMPLE
    $sum = 0;
    foreach ($prices as $price) {
        $total += $price;       // <-- warning, new variable $total is instantiated here
    }
    return $sum;
```

* <a name="xr015"></a> **Possible attempt to pass non-variable by reference (%s)** (severity: error, code: xr015)

    This message is issued if a function takes a parameter by reference,
    but something else but a variable is given.

```php
    // COUNTEREXAMPLE
    $last_word = array_pop(explode(" ", $text));        // <--
        // Error - array_pop() takes an array by reference and modifies it
        // this code may work but will break in E_ALL | E_STRICT mode.
        // Use temp variable to fix it:

    $tmp = explode(" ", $text);
    $last_word = array_pop($tmp);   // ok
```

* <a name="xr021"></a> **Mixed/Lower-case unquoted string literal (%s)** (severity: warning, code: xr021)

    Unquoted ("bare") strings are either constant names or, if no constant with
    this name is defined, are interpreted as strings.
    Best practice for constants is to use upper-case names for them.

    Sample code:

```php
    // COUNTEREXAMPLE
    echo time;              // <-- should it be "time" or time(), $time, or even some constant TIME here?

    // xref knows about constants defined in current file:
    define("foo", 1);
    const bar = 2;
    echo foo + bar;         // ok, no warning here

    // all upper-case literals are assumed to be constants
    echo UPPER_CASE;        // ok, no warning here

```

    If you get warnings about a lower-case constant defined somewhere else, you can disable the warning
    listing the constant in lint.add-constant setting (see below).


* <a name="xr022"> **Possible use of class constant without class prefix (%s)** (severity: warning, code: xr022)

    Sample code:

```php
    // COUNTEREXAMPLE
    class Foo {
        const BAR = 1;
        public method bar() {
            echo BAR;           // <-- did you mean self::BAR / Foo::BAR?
        }
    }
```

* <a name="xr031"></a> **($this) is used outside of instance/class scope** (severity: error, code: xr031)

    Sample code:

```php
    // COUNTEREXAMPLE
    class Foo {
        public static function bar() {
            return $this->bar;      // <-- error: no $this in static method
        }
    }

```

* <a name="xr032"></a> **Possible use of ($this) in global scope** (severity: warning, code: xr032)

    Similar to the above, caused by using $this in global scope in file that doesn't contain
    other classes and/or methods and, therefore, can be included into body of class method.
    For example of this codestyle, see Joomla project:

```php
// COUNTEREXAMPLE

// file: main.php
class Foo {
    public function bar() {
        include "method_body.php";
    }
}

// file: method_body.php

    return $this->bar;      // <-- warning here
```

    If your project depends on code like this, disable this error.

* <a name="xr033"></a> **Class keyword (%s) is used outside of instance/class scope** (severity: error, code: xr033)

    Similar to **xr031**, caused by use any of class-context keywords (self::, parent:: or static::) used outside of
    the class constructs. Sample code:

```php
    // COUNTEREXAMPLE
    function bar() {
        return new self();  // <-- error: no self class in regular function
    }

```

* <a name="xr034"></a> **Possible use of class keyword (%s) in global scope** (severity: error, code: xr034)

    Similar to **xr032** and **xr033**, caused by use any of class-context keywords (self::, parent:: or static::)
    in global scope


* <a name="xr041"></a> **Assignment in conditional expression (%s)** (severity: warning, code: xr041)

    Sample code:

```php
    // COUNTEREXAMPLE
    if ($foo = 0) { ... }       // <-- should it be ($foo == 0) here?
    if ($bar = $baz) { ... }    // <-- ($bar == $baz) ?

    // examples below are ok and don't raise warning:
    if ($handle = fopen("file", "w") { ... }    // ok
    if ($ch = curl_init(...)) { ... }           // ok
```

* <a name="xr051"></a> **Spaces before opening tag (%s)** (severity: warning, code: xr051)

    Caused by spaces (new-line, byte-order-marks, etc) before opening php tag (<?php).
    This is not important if output is text-based (e.g HTML or XML), but may break binary output.
    If this warning is not important for your project, disable it with **lint.ignore-error** option.

* <a name="xr052"></a> **Unneeded closing tag (%s)** (severity: warning, code: xr052)

    Similar to the above - all text after closing tag (?>) will be in script's output.
    The best practice is to omit closing tag completely.

* <a name="xr061"></a> **Class (%s) is defined more than once** (severity: warning, code: xr061)

    Caused by two class definitions with the same name.

* <a name="xr062"></a> **Method (%s) is not defined in class (%s)** (severity: error, code: xr062)

    Caused by code that tries to call a method that's not defined neither in the class or any of
    its base classes.

* <a name="xr063"></a> **Constant (%s) is not defined in class (%s)** (severity: error, code: xr063)

    Caused by code that tries to access a class constant, and the class (or its base class) doesn't
    define this constant.

* <a name="xr064"></a> **Property (%s) is not declared in class (%s)** (severity: warning, code: xr064)

    This is a warning, because if code assigns to undeclared property, the property will be created.
    However, this is often an error:

```php
    // COUNTEREXAMPLE
    class A {
        protected $myPropery = null;
        public function setProperty($value) {
            $this->my_property = $value;        // <-- warning
                                                // property 'my_property' will be created here
                                                // is it ok or prop 'myPropery' was meant?
        }
    }
```

* <a name="xr065"></a> **Can't check members of class (%s) because its definition is missing** (severity: warning, code: xr065)

    Caused by reference to any member (property, method or constant) of a class which definition is missing.
    If a single file being checked with enabled option **xref.project-check**, all referenced user-defined classes
    that are defined elsewhere will trigger this warnings; in this case turning the option off may be advisable.

```php
    echo A::MY_CONST;   // warning
                        // there is not definition of class A, so
                        // it's not possible to check if there is a constant MY_CONST
```

    To disable this warning for a specific class, use **lint.ignore-missing-class** option.

* <a name="xr066"></a> **Can't check members of class (%s) because definition of its base class (%s) is missing** (severity: warning, code: xr066)

    Similar to the above, but caused by a missing definition of base class.

```php
    class B extends A {
    }

    echo B::MY_CONST;       // warning
                            // there is no const B::MY_CONST, but it may be inherited from class A,
                            // which definition is missing.
```

* <a name="xr067"></a> **Property (%s) is static, not instance** (severity: error, code: xr067)

    Caused by attempt to access a static class property as it were an instance property.

```php
    // COUNTEREXAMPLE
    class A {
        public static $name = "my name";

        public function test() {
            echo $this->name;       // <-- error
                                    // there is no $this->name, it's self::$name

        }
    }

```

* <a name="xr068"></a> **Member (%s) is instance, not static** (severity: error, code: xr068)

    Caused by attempt to access an instance member (property or method) as if it were static one.

```php
    // COUNTEREXAMPLE
    class A {
        public function test() {}
    }

    echo A::test();     // <-- error
                        // method test() is not static!
```

* <a name="xr069"></a> **Member (%s) of class (%s) is private** (severity: error, code: xr069)

    Caused by attempt to access a private member (property or method) of a class outside of the class.

```php
    // COUNTEREXAMPLE
    class A {
        private $prop = null;
    }
    class B extends A {
        public function test() {
            echo $this->prop;       // <-- error
                                    // class B doesn't declare it's own property 'prop'
                                    // and can't access private prop of its parent class
        }
    }

```

* <a name="xr070"></a> **Member (%s) of class (%s) is protected** (severity: error, code: xr070)

    Similar to the **xr069**, but caused by access to protected member (property or method).

* <a name="xr081"></a> **Class (%s) doesn't call constructor of it's base class (%s)** (severity: warning, code: xr081)

    If child class declares method '__construct' that doesn't call constructor of the parent class,
    fields of the parent class can be left uninitialized.
    This warning is not reported if either 1) child class doesn't declare constructor (then PHP will create a default one
    and will call parent) or if 2) parent class doesn't have a constructor.

```php
    // COUNTEREXAMPLE
    class A {
        public $prop;
        function __construct() { $this->prop = 42; }
    }

    class B extends A {
        function __construct() {
            // warning: there is no call to parent::__construct(),
            // so property A::$prop is uninitialized
            // when instance of class B is created.
        }
    }

    $b = new B();
    echo $b->prop;  // undefined!
```

* <a name="xr091"></a> **Unknown function (%s)** (severity: warning, code: xr091)

    Either the function's name is misspelled, or it's defined somewhere outside of reach of xref.
    Use **lint.add-function-signature[]** config directive to make the function known.
    If a single file being checked with enabled option **xref.project-check**, all referenced user-defined functions
    that are defined elsewhere will trigger this warnings; in this case turning the option off may be advisable.

* <a name="xr092"></a> **Possible call of method (%s) of class (%s) as function** (severity: warning, code: xr092)

    Class method is called without a corresponding prefix ($this-> for instance methods, or self:: or ClassName:: for static methods).

* <a name="xr093"></a> **Wrong number of arguments for function/method (%s): (%s) instead of (%s)** (severity: warning, code: xr093)

* <a name="xr094"></a> **Wrong number of arguments for constructor of class (%s): (%s) instead of (%s)** (severity: warning, code: xr094)

* <a name="xr095"></a> **Default constructor of class (%s) doesn't accept arguments** (severity: warning, code: xr095)

    Attempt to create an object of a class that doesn't define constructor, and to pass some parameters to the default constructor.
    Since default constructor takes no arguments, the parameters will be lost.

```php
    // COUNTEREXAMPLE
    class MyMessage { public $text; }
    $m = new MyMessage("text");     // <-- warning - the text will be lost.
                                    // Class MyMessage doesn't define constructor,
                                    // and default constructor doesn't accept arguments
```

CONFIG FILE
===========

XRef tools will look for the config file in the following places in order:
* path set by command-line options -c (--config), affects command-line scripts only
* environment variable XREF\_CONFIG
* file .xref/xref.ini in the current directory, or in any of it's parent directories

If no file found, default values will be used - they are good enough to run command-line lint tool
but not to run xref-doc or xref-ci.

Each of the value below can be overridden by command-line option -d (--define), e.g.

```
xref-lint -d lint.check-global-scope=false -d lint.ignore-error=xr010 ...
```

List of config file parameters:
------------------------------

* **project.name** (string; optional)

    The name of your project, will be mentioned in generated documentation

* **project.source-code-dir[]** (array of paths; required)

    The set of paths where to look for source code of your project to create
    documentation/lint report

* **project.exclude-path** (array of paths; optional)

    Exclude given files or directories from lint check

* **xref.data-dir** (path, required)

    The path to the directory where XRef will keep project's data

* **xref.smarty-class** (path, required)

    Path to installed Smarty template engine main class,
    e.g. /home/igariev/lib/smarty/Smarty.class.php

* **xref.template-dir** (path, optional)

    Path to directory with (your custom) template files.
    If not set, default templates will be used.

* **xref.script-url** (url, optional)

    URL where PHP scripts from XRef/web-scripts dir are accessible;
    if present, the xref-ci notifications will contain links to them

* **xref.storage-manager**  (class name; optional)

    Class name of plugin for persistent storage of XRef's data; XRef_Storage_File by default

* **xref.plugins-dir[]** (array of paths; optional)

    The path where to look for plugins;
    the default XRef library dir will be searched even if not specified.

* **xref.project-check** (boolean; optional)

    Option to choose between checking all files as a project (i.e. checking cross-reference consistency
    of declarations/references in files), or just checking each file independently. It's enabled by default,
    which results in longer run time but more accurate lint report.

* **doc.output-dir** (path; required)

    Where to put generated documentation

* **lint.color** (true/false/auto; optional)

    For command-line lint tool only - should the console output be colorized; it's auto by default.

* **lint.report-level** (errors/warnings/notices; optional)

    Messages with which severity level should be reported by lint; it's warnings by default

* **lint.add-constant[]** (array of strings; optional)

    If you get warnings about lower-case string literals that are actually global constants
    defined in somewere else, you can list these constants here.

* **lint.add-global-var[]** (array of strings; optional)

    If you check usage of variables in global scope (option lint.check-global-scope is set to true),
    and your code depends on global variables defined in other files, you can notify lint about
    these variables by listing them in this list.

* **lint.add-function-signature[]** (array of strings; optional)

    So far lint doesn't know about user functions defined in other files, and doesn't know
    if there are functions that can assign a value to a variable passed by reference.
    You can list such functions (and class methods) in this list.

    Syntax:

        add-function-signature[] = "my_function($a, $&b)"
        add-function-signature[] = "MyClass::someMethod($a, $b, &c)"

* **lint.check-global-scope** (boolean; optional)

    Should the lint warn about unknown variables in global scope.
    For some projects these variables are real errors; for other projects it's ok
    because a global scope variable can be initialized in other included file.
    Choose an option that suits your project best; default is true (do check global space).

* **lint.ignore-error[]** (array of strings; optional)

    List of error codes not to report for this project.

* **lint.ignore-missing-class[]** (array of class names; optional)

    Don't report about missing definition of the given classes.
    Warning: this option make skip mosts tests on derived (child) classes too,
    since the child class can inherit any method/property from the (missing) base class.

* **lint.parsers[]** (array of class names; optional)

    Which parsers should lint use; by default it's XRef_Parser_PHP

* **lint.plugins[]** (array of class names; optional)

    Which plugins should lint use; by default they are
    XRef_Lint_UninitializedVars, XRef_Lint_LowerCaseLiterals
    and XRef_Lint_StaticThis.

* **ci.source-code-manager** (class name; optional)

    Which plugin is used to work with repository. By default it's XRef_SourceCodeManager_Git

* **ci.update-repository** (boolean; required)

    Should the CI tool to update repository itself; if not, someone else should do it

* **ci.incremental** (boolean; required)

    This option affects which errors will be reported by CI tools - all errors or new only.
    Recommended value is on (true) which significantly decreases the amount of spam.

* **git.repository-dir** (path; required)

    Local path to git repository dir of your project

* **git.update-method** (pull/fetch; required)

    How to update the git repository - by pull or by fetch method

* **git.ignore-branch[]**   (array of strings; optional)

    List the names of branches that shouldn't be reported

* **mail.from** (string; required)

    What name/e-mail address should the continuous integration e-mails be sent from

* **mail.reply-to** (e-mail address; required)

    Reply-to field of e-mails sent by CI

* **mail.to[]** (array of e-mail addresses; required)

    Who are the recipient of CI e-mails. You can specify several addresses here and/or
    use e-mail templates with the fields filled by commit info.
    %an - author of the commit name, %ae - e-mail address of commit author, etc.
    Consult your repository manager doc about supported fields.

    Example:

        to[] = you@your.domain
        to[] = "{%ae}"


HOW TO EXTEND THE XREF
======================

In XRef tools most of the work are done by loadable plugins, and config file
determines which plugin to load for any of action. So, here is the checklist:

1. Find the interface that your plugin should implement
    All interfaces are defined in lib/interfaces.php file; currently there are
    three interfaces for file processing plugins (XRef_IDocumentationPlugin, XRef_ILintPlugin, XRef_IProjectLintPlugin),
    one interface for cache/storage (XRef_IPersistentStorage),
    for source version control systems (XRef_ISourceCodeManager) and
    for parsers (XRef_IFileParser).

2. Create your implementation; you may inherit from XRef classes and override
    only the needed methods. If class is named My_Plugin place it into file named
    My/Plugin.class.php

3. Specifiy the root directory where to look for your plugins in
    xref.plugins-dir[] variable of config file.

4. Tell XRef that your plugin should be loaded in config file.
    Parameters of interest: lint.parsers[], lint.plugins[],
    doc.parsers[], doc.plugins[], xref.storage-manager and
    ci.source-code-manager.


AUTHOR
======

Igor Gariev <gariev@hotmail.com>

