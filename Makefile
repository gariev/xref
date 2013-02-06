all:
	@echo "Availiable make targets:"
	@echo " test"
	@echo " package"
	@echo " clean"

test:
	phpunit tests/*

package: clean package.xml
	dos2unix bin/*
	unix2dos bin/xref-doc.bat bin/xref-lint.bat
	pear package
	dos2unix bin/*

package.xml:
	php dev/makePackageFile.php

clean:
	rm -rf package.xml XRef*.tgz

