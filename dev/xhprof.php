#!/usr/bin/php
<?php

if ($argc < 2) {
    echo "Usage: $argv[0] script-to-be-profiled.php [script options]\n";
    exit(1);
}

$php_file = $argv[1];
array_splice($argv, 1, 1);
echo $php_file, "\n";

xhprof_enable();
require $php_file;
$xhprof_data = xhprof_disable();

include_once "xhprof_lib/utils/xhprof_lib.php";
include_once "xhprof_lib/utils/xhprof_runs.php";

$xhprof_runs = new XHProfRuns_Default();
$run_id = $xhprof_runs->save_run($xhprof_data, "xref");
echo "http://localhost/xhprof/xhprof_html/index.php?run=$run_id&source=xref\n";

