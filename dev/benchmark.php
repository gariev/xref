<?php
require_once '/Users/igariev/dev/PHP-Parser/lib/bootstrap.php';
require_once dirname(__FILE__) . '/../XRef.class.php';

$b = new Benchmark();

$filename = '../predis/lib/Predis/Client.php';
$code = file_get_contents($filename);

my_echo("File", $filename);
my_echo("Code size", strlen($code), "bytes");


$parser = new PHPParser_Parser(new PHPParser_Lexer);
$f = function () use ($parser, $code) { $parser->parse($code); };
my_echo("PHP-Parser", $b->timeIt($f, 30), "sec");

$stmts = $parser->parse($code);
$serialized = serialize($stmts);
my_echo("PHP-P serialized size", strlen($serialized), "bytes");

$compressed = gzcompress($serialized);
my_echo("PHP-P compressed size", strlen($compressed), "bytes");

$gzencoded = gzencode($serialized);
my_echo("PHP-P gzencoded size", strlen($gzencoded), "bytes");

$f = function () use ($serialized) { $stmts = unserialize($serialized); };
my_echo("PHP-P unserialize time", $b->timeIt($f), "sec");

$f = function () use ($compressed) {
    $data = gzuncompress($compressed);
    if (!$data) throw new Exception();
};
my_echo("PHP-P uncompress time", $b->timeIt($f), "sec");

$f = function () use ($gzencoded) {
    $data = (function_exists('gzdeocde'))
        ? gzdecode($gzencoded)
        : gzinflate(substr($gzencoded,10,-8));
    if (!$data) throw new Exception();
};
my_echo("PHP-P gzdecode time", $b->timeIt($f), "sec");

$stmts_array = array_cast_recursive($stmts);
$serialized = serialize($stmts_array);
my_echo("PHP-P array serialized", strlen($serialized), "bytes");

$f = function () use ($serialized) { $stmts = unserialize($serialized); };
my_echo("PHP-P array unserialize time", $b->timeIt($f), "sec");

$xref = new XRef(); // init autoload etc
$parser = new XRef_Parser_PHP();
$f = function () use ($parser, $code, $filename) {
    $pf = $parser->parse($code, $filename);
    $pf->release();
};
my_echo("XRef internal parser", $b->timeIt($f, 30), "sec");

$slices = array();
$pf = $parser->parse($code, $filename);
$project_database = new XRef_ProjectDatabase();
foreach ($xref->getPlugins("XRef_IProjectLintPlugin") as $id => $plugin) {
    $slices[$id] = $plugin->createFileSlice($pf);
}
$slices['_db'] = $project_database->createFileSlice($pf);
$pf->release();

$serialized = serialize($slices);
my_echo("XRef serialized size", strlen($serialized), "bytes");

$compressed = gzcompress($serialized);
my_echo("XRef compressed size", strlen($compressed), "bytes");

$f = function () use ($serialized) { $slices = unserialize($serialized); };
my_echo("XRef unserialize time", $b->timeIt($f), "sec");

function my_echo($prefix, $data, $suffix = null) {
    printf('%-30s %s', $prefix . ':', $data);
    if ($suffix) {
        echo " ", $suffix;
    }
    echo "\n";
}

class Benchmark {

    protected $timerResolution;
    protected $emptyCallTime;

    public function __construct() {
        // 1. find the resolution of the microtimer
        $time1 = microtime(true);
        $time2 = microtime(true);
        while ($time2 <= $time1) {
            $time2 = microtime(true);
        }
        $this->timerResolution = ($time2-$time1);
        $this->emptyCallTime = $this->timeIt(function(){}, 1000);
    }

    public function timeIt($callback, $iterations = 100) {
        $dt = $this->timeItInternal($callback, $iterations);
        while ($dt < $this->timerResolution * 100) {
            if ($dt == 0) {
                $iterations *= 100;
            } else {
                $iterations *= ($this->timerResolution/$dt) * 200;
                $iterations = (int) $iterations;
            }
            $dt = $this->timeItInternal($callback, $iterations);
        }
        return sprintf('%.1e', $dt/$iterations - $this->emptyCallTime);
    }

    protected function timeItInternal($callback, $iterations) {
        $start_time = microtime(true);
        for (; $iterations>0; --$iterations) {
            call_user_func($callback);
        }
        return microtime(true) - $start_time;
    }
}

function array_cast_recursive($value) {
    if (is_array($value)) {
        $a = array();
        foreach ($value as $k => $v) {
            //if (strstr($k, 'attributes') !== false) {
            //    continue;
            //}
            $a[$k] = array_cast_recursive($v);
        }
        return $a;
    } elseif (is_object($value)) {
        $a = (array) $value;
        return array_cast_recursive($a);
    } else {
        return $value;
    }
}
