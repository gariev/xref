Version 0.1.0
==============
First public release (alpha)

Version 0.1.3
==============
- lint: added "strict" and "relaxed" mode of checking variables usage
- lint: extended list of functions that can assign values to passed-by-reference variables
- lint: json output
- web-tools: fix for servers with "magic_quotes" turned on
- lint: "static" variables declarations inside function makes them declared

Version 0.1.4
==============
- lint: better support for functions that take variables by reference and initialize them

Version 0.1.5
==============
- lint: PHP 5.3+ features (namespaces, anonymous functions)
- lint: new error "Possible attempt to pass non-variable by reference"

Version 0.1.6
==============
- lint: PHP 5.4+ features (traits)
- lint: new error "Use of self:: or parent:: outside of class scope"

Version 0.1.7
==============
- lint: accuracy improvements:
    - out-of-order assignment/usage of variables in loops
    - allowing lower/mixed-case constants defined in the same file
    - eval triggers relaxed mode now

Version 0.1.8
==============
- lint:
    - list of all errors with help URLs
    - new warning: assignment in conditions
    - new warning: class constants used without class prefix
    - command lint option -d (--define)
    - ability to disable errors by their codes
    - experimental support for DocComments

Version 1.0.0
=============
- lint:
    - project check (can check all files in the project and make cross-reference checks)
