all:
	@echo "Availiable make targets:"
	@echo " test"
	@echo " package"
	@echo " clean"

test:
	## self-test:
	bin/xref-lint --config=default --no-cache \
		--define lint.ignore-missing-class=PEAR \
		--define lint.ignore-missing-class=PHPUnit_Framework_TestCase \
		--define lint.ignore-missing-class=Smarty \
		--define lint.ignore-missing-class=PEAR_PackageFileManager2 \
		--define lint.ignore-missing-class=PHPParser_Lexer \
		--define lint.ignore-missing-class=PHPParser_Parser \
		--define lint.ignore-missing-class=XHProfRuns_Default
	## unittests
	phpunit tests

package: test clean check_clean package.xml
	dos2unix bin/*
	unix2dos bin/xref-doc.bat bin/xref-lint.bat
	pear package
	dos2unix bin/*

package.xml:
	php dev/makePackageFile.php

clean:
	rm -rf package.xml XRef*.tgz

check_clean:
	files=`git status --porcelain`; if [ "$$files" != "" ]; then echo "extra files in dir: $$files"; exit 1; fi

doctest:
	bin/xref-doc \
		-d 'project.source-code-dir[]=.' \
		-d 'project.exclude-path[]=tmp' \
		-d doc.output-dir=tmp \
		-d xref.data-dir=tmp \
		-d xref.smarty-class=/Users/igariev/dev/Smarty-2.6.27/libs/Smarty.class.php
