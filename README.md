
WHAT IS THIS?
=============

XRef is a set of tools to work with PHP source files. Currently it includes:

* xref-lint

    A lint, i.e. static code analysis tool that finds errors.
    PHP is not a perfect language to catch programmer's errors,
    so it's a good idea to have a tool that will do.
    See section "ERROR MESSAGES REPORTED BY LINT" below.

* xref-doc

    A tool to create cross-reference documentation about your project
    (which classes/methods/properties are defined and where they are used)

* xref-ci

    Continuous Integration tool to monitor your project repository and send e-mail
    notifications about errors.

* GIT lint plugin

    Just type 'git xref-lint' in your git repository root before doing a commit
    and you'll get lint report of modified files

* parser libraries to build your own tools

    XRef is easily extensible - read the section "HOW TO EXTEND THE XREF" below

* Try it online!

    <http://xref-lint.net/>

INSTALLATION
============

```sh
## get it
curl -o XRef-stable.tgz http://xref-lint.net/releases/XRef-stable.tgz
## install
pear install ./XRef-stable.tgz
## run
xref-lint <your-project-dir>
```

Alternatively, you can get the source code and add its 'bin' directory to your executable path:
```sh
git clone git@github.com:gariev/xref.git
export PATH=$PATH:xref/bin
```

Any of the above will give you working command-line lint tool xref-lint (or xref-lint.bat on Windows platform).

Full installation
-----------------

To get most of the xref package, configure it after the basic installation.

1.  Create a config file and place it where XRef can found it
    (see section CONFIG FILE below; also see sample config in config/xref.ini.sample)

2.  Download and install Smarty template engine. <http://www.smarty.net/>;
    any of versions 2.x or 3.x should work, version 2 takes less memory.
    Set the pass to Smarty.class.php file in *xref.smarty-class* config param.

3.  Create a data directory where XRef will keep its data about your project.
    Specify this dir in *xref.data-dir*.

4.  Configure web server to run scripts from web-scripts dir XRef/web-scripts/,
    see also sample Apache config file in XRef/examples/httpd.conf

5. Set up crontab to run xref-ci to monitor your project,
    see sample cronab script in


REPORTED ERRORS
===============

* <a name="XV01"></a> **Use of unknown variable** (severity: error, code: XV01)

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

* <a name="XV02"></a> **Possible use of unknown variable** (severity: warning, code: XV02)

    Similar to the above, but issued when xref can't reliable detect
    which variables can be legitimately present in this scope
    (the relaxed mode).

```php
    // COUNTEREXAMPLE
    //
    // global scope is always in relaxed mode -
    // variables defined in other files can be used here
    //
    function foo($params, $var_name) {
        // functions starts in strict mode, but
        include "other_file.php";       // all of these
        extract($a);                    // makes xref
        $$var_name = 1;                 // switch to
        eval("\$res = $expression_str");// relaxed mode

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

* <a name="XV03"></a> **Possible use of unknown variable as function argument** (severity: warning, code: XV03)

    Similar to the above, caused by using a variable as a parameter to function with unknown signature.

```php
    // COUNTEREXAMPLE

    unknown_function($foo);     // <-- is $foo legitimate here?
                                // maybe, if unknown_function takes param
                                // by reference and assigns value to it

    known_function($bar);       // no warning here - since
                                // known_function() is defined in the same
                                // file, xref will extract its signature

    function known_function(&$param) {
        $param = 1;
    }
```

    How to get rid out of this warning:

        - Initialize the variable with some appropriate value (e.g. null)
          before using it as parameter
        - add function signature with *lint.add-function-signature*
          config file/command-line parameter (see below)

* <a name="XV04"></a> **Array autovivification** (severity: warning, code: XV04)

    Similar to the above - when a value assigned to a variable that was never
    defined in array context, a new array is instantiated. This may be intended
    behavior or error; that's why this is warning. It's always a good idea
    to initialize array variable with an empty array.

    Sample code:

```php
    // COUNTEREXAMPLE
    $text = explode('', $str);
    $test[] = '!';  // <-- a new array $test is instantiated here.
                    // is it intended or array $text should be here?
                    // to remove the warning, initialize var before usage:
                    // $test = array();
```

* <a name="XV05"></a> **Scalar autovivification** (severity: warning, code: XV05)

    Similar to the above, caused by operations like ++, .= or +=
    on variables that were never initialized.
    This may be inteded behaviour or it can mask a real error.
    Initialize the variable before usage

```php
    // COUNTEREXAMPLE
    $sum = 0;
    foreach ($prices as $price) {
        $total += $price;       // <-- warning, variable $total is instantiated here
    }
    return $sum;
```

* <a name="XV06"></a> **Possible attempt to pass non-variable by reference** (severity: error, code: XV06)

    This message is issued if a function takes a parameter by reference,
    but something else but variable is given.

```php
    // COUNTEREXAMPLE
    $last_word = array_pop(explode(" ", $text));        // <--
        // error - array_pop() takes an array by reference and modifies it
        // this code may work but will break in E_ALL | E_STRICT mode
        // use temp variable to fix it:

    $tmp = explode(" ", $text);
    $last_word = array_pop($tmp);   // ok
```

* <a name="XT01"></a> **$this, self:: or parent:: is used outside of instance/class scope** (severity: error, code: XT01)

    Sample code:

```php
    // COUNTEREXAMPLE
    class Foo {
        public static function bar() {
            return $this->bar;      // <-- error: no $this in static method
        }
    }

    function foo {
        return parent::foo();   // <-- error: there is no parent:: or self::
                                // pseudo-classes outside of class context
    }

```

* <a name="XT02"></a> **Possible use of \$this, self:: or parent:: is global scope** (severity: warning, code: XT02)

    Similar to the above, caused by using $this/self/parent in global scope in file that doesn't contain
    other classes and/or methods and, therefore, can be included into body of class method.
    For this codestyle, see Joomla project:

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

* <a name="XL01"></a> **Mixed/Lower-case unquoted string literal** (severity: warning, code: XL01)

    Unquoted ("bare") strings are either constant names or, if no constant with
    this name is defined, are interpreted as strings.
    Best practice for constants is to use upper-case names for them.

    Sample code:

```php
    // COUNTEREXAMPLE
    echo time;              // <-- should it be really "time" or time(), $time, or even some constant TIME?

    // xref knows about constants defined in current file:
    define("foo", 1);
    const bar = 2;
    echo foo + bar;         // ok, no warning here

    // all upper-case literals are assumed to be constants
    echo UPPER_CASE;        // ok, no warning here

```

    If you get warnings about a lower-case constant defined somewhere else, you can disable the warning
    listing the constant in lint.add-constant setting (see below).


* <a name="XL02"> **Possible use of class constant without class prefix** (severity: warning, code: XL02)

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

* <a name="XA01"> **Assignment in conditional expression** (severity: warning, code: XA01)

    Sample code:

```php
    // COUNTEREXAMPLE
    if ($foo = 0) { ... }       // <-- did you mean ($foo == 0)?
    if ($bar = $baz) { ... }    // <-- ($bar == $baz) ?

    // examples below are ok and doesn't trigger warning:
    if ($handle = fopen("file", "w") { ... }    // ok
    if ($ch = curl_init(...)) { ... }           // ok
```


CONFIG FILE
===========

XRef tools will look for the config file in the following places in order:
* command-line options -c or --config, command-line scripts only
* environment variable XREF\_CONFIG
* file named xref.ini in the current directory, or in any of it's parent directories
* file named xref.ini in the directory where xref.init.sample was installed (@data\_dir@/XRef/config)

If no file found, default values will be used - they are good enough to run lint (both command-line and web version),
but not to run xref-doc or xref-ci.

Each of the value below can be overridden by command-line option -d (--define), e.g.

```
xref-lint -d lint.check-global-scope=false -d lint.ignore-error=XA01 ...
```


List of config file parameters:

* **project.name** (string, optional)

    The name of your project, will be mentioned in generated documentation

* **project.source-code-dir[]** (array of path; required)

    The set of paths where to look for source code of your project to create documentation

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

* **doc.output-dir** (path; required)

    Where to put generated documentation

* **lint.color** (true/false/auto; optional)

    For command-line lint tool only - should the console output be colorized; it's auto by default.

* **lint.report-level** (errors/warnings/notices; optional)

    Messages with which severity level should be reported by lint; it's warnings by default

* **lint.add-constant[]** (array of strings, optional)

    If you get warnings about lower-case string literals that are actually global constants
    defined in somewere else, you can list these constants here.

* **lint.add-global-var[]** (array of strings, optional)

    If you check usage of variables in global scope (option lint.check-global-scope is set to true),
    and your code depends on global variables defined in other files, you can notify lint about
    these variables by listing them in this list.

* **lint.add-function-signature[]** (array of strings, optional)

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

* **mail.to[]** (array or e-mail addresses; required)

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
    four interfaces for plugins: XRef_IDocumentationPlugin, XRef_ILintPlugin,
    XRef_IPersistentStorage, XRef_ISourceCodeManager and one interface for
    parsers: XRef_IFileParser.

2. Create your implementation; you may inherit from XRef classes and override
    just needed methods. If class is named My_Plugin place it into file named
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

