
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


INSTALLATION
============

Basic installation
------------------

```shell
## get it
curl -o XRef-stable.tgz https://xref-lint.net/releases/XRef-stable.tgz
## install
pear install ./XRef-stable.tgz
## run
xref-lint <your-project-dir>
```

Alternatively, you can get the source code and add its 'bin' directory to your executable path:
```shell
git clone git@github.com:gariev/xref.git
export PATH=$PATH:xref/bin
```

Any of the above will give you working command-line lint tool xref-lint (or xref-lint.bat on Windows platform).

Full installation
-----------------

To get most of the xref package, configure it after the basic installation.

1.  Download and install Smarty template engine. <http://www.smarty.net/>;
    any of versions 2.x or 3.x should work

2.  Create a data directory where XRef will keep its data about your project

3.  Configure web server to run scripts from web-scripts dir XRef/web-scripts/,
    see also sample Apache config file in XRef/examples/httpd.conf

4. Create a config file and place it where XRef can found it
    (see section CONFIG FILE below; also see sample config in config/xref.ini.sample)

5. Set up crontab to run xref-ci to monitor your project,
    see sample cronab script in


ERROR MESSAGES REPORTED BY LINT
===============================

* Use of non-defined variable [error]

    This error message is caused by accessing a variable that was never
    assigned a value. Most often it's because of misspelled variable name
    or refactoring went wrong.

    Sample code:

```php
    function example1() {
        $message = "hello, world";
        echo $Message;  // <-- error: there is no variable $Message
    }
```

* Array autovivification [warning]

    Similar to the above - when a value assigned to a variable that was never
    defined in array context, a new array is instantiated. This may be intended
    behavior or error; that's why this is warning. It's always a good idea
    to initialize array variable with an empty array.

    Sample code:

```php
    function example2($str) {
        $text = explode('', $str);
        $test[] = '!';  // a new array $text is instantiated here
            // is it corrent or array $text should be here?
            // to remove the warning, initialize var before usage:
            // $test = array();
    }
```

* $this is used outside of instance method [error]

    Variable $this is used in function or in static class method.

    Sample code:

```php
    function example3() {
        return $this->foo;  // error: there is no object context!
    }

    class Example{
        public static function example4() {
            $this->invoceMethod();  // no $this in static methods!
        }
    }
```

* Mixed/Lower-case unquoted string literal [warning]

    Unquoted ("bare") strings are either constant names or interpreted as strings.
    Best practice for constants is to use upper-case names for them; so lower-case
    unquoted string either a poorly named constant (rename it) or error.

    Sample code:

```php
    echo time; // should it be time(), $time, 'time' or TIME?
```

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


CONFIG FILE
===========

XRef tools will look for the config file in the following places in order:
* command-line options -c or --config, command-line scripts only
* environment variable XREF\_CONFIG
* file named xref.ini in the directory where xref.init.sample was installed (@data\_dir@/XRef/config)

If no file found, default values will be used - they are good enough to run lint (both command-line and web version),
but not to run xref-doc or xref-ci.

Each of the value below can be overriden by command-line option -d (--define), e.g.

```
xref-lint -d lint.check-global-scope=false -d lint.ignore-error=XA01 ...
```


List of config file parameters:

* **xref.project-name** (string, optional)

    The name of your project, will be mentioned in generated documentation

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
    if present, the xref-ci notificatons will contain links to them

* **xref.storage-manager**  (class name; optional)

    Class name of plugin for persistent storage of XRef's data; XRef_Storage_File by default

* **xref.plugins-dir[]** (array of paths; optional)

    The path where to look for plugins;
    the default XRef library dir will be searched even if not specified.

* **doc.source-code-dir[]** (array of path; required)

    The set of paths where to look for source code of your project to create documentation

* **doc.remove-path** (string; optional)

    Which starting part of source filenames to remove from report; for aesthetics only

* **doc.output-dir** (path; required)

    Where to put generated documentation

* **lint.color** (true/false/auto; optional)

    For command-line lint tool only - should the console output be colorized; it's auto by default.

* **lint.report-level** (errors/warnings/notices; optional)

    Messages with which severity level should be reported by lint; it's warnings by default

* **lint.add-global-var[]** (array of strings, optional)

    If you check usage of variables in global scope (option lint.check-global-scope is set to true),
    and your code depends on global variables defined in other files, you can notify lint about
    these variables by listing them in this list.

* **lint.add-function-signature[]** (array of strings, optional)

    So far lint doesn't know about user functions defined in other files, and doesn't know
    if there are functions that can assign a value to a variable passed by reference.
    You can list such functions (and class methods) in this list.

    Syntax:

        add-function-signature[] = 'my_function($a, $&b)'
        add-function-signature[] = 'MyClass::someMethod($a, $b, &c)'

* **lint.check-global-scope** (boolean; optional)

    Should the lint warn about unknown variables in global scope.
    For some projects these variables are real errors; for other projects it's ok
    because a global scope variable can be initialized in other included file.
    Choose an option that suits your project best; default is true (do check global space).

* **lint.ignore-error** (array of strings; optional)

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

    Examples:

        to[] = you@your.domain
        to[] = "{%ae}"


AUTHOR
======

Igor Gariev <gariev@hotmail.com>

