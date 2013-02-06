@rem
@rem bin/xref-lint.bat
@rem
@rem This is a wrapper .bat script to run xref-lint from Windows command line
@rem
@rem @author Igor Gariev <gariev@hotmail.com>
@rem @copyright Copyright (c) 2013 Igor Gariev
@rem @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
@rem

@set ATSIGN=@

@if "@php_dir@"=="%ATSIGN%php_dir@" (
    set SCRIPTDIR=%~dp0..\bin-scripts
) else (
    set SCRIPTDIR=@php_dir@\XRef\bin-scripts
)

@if "@php_bin@"=="%ATSIGN%php_bin@" (
    set PHP=php
) else (
    set PHP=@php_bin@
)

"%PHP%" "%SCRIPTDIR%\xref-lint.php" %*
