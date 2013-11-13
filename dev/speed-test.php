<?php
require_once '/Users/igariev/dev/PHP-Parser/lib/bootstrap.php';
require_once dirname(__FILE__) . '/../XRef.class.php';

const ITERATIONS = 300;
$filename = '../predis/lib/Predis/Client.php';
$code = file_get_contents($filename);

echo "File:\t\t\t$filename\n";
echo "Code size:\t\t", strlen($code), " bytes\n";
echo "Number of iterations:\t", ITERATIONS, "\n";

echo "PHP-Parser:\t\t";
$parser = new PHPParser_Parser(new PHPParser_Lexer);
$time = time();
for ($i = 0; $i < ITERATIONS; ++$i) {
    $stmts = $parser->parse($code);
}
echo (time() - $time), " seconds\n";


echo "XRef internal parser:\t";
$xref = new XRef(); // init autoload etc
$parser = new XRef_Parser_PHP();

$time = time();
for ($i = 0; $i < ITERATIONS; ++$i) {
    $pf = $parser->parse($code, $filename);
    $pf->release();
}
echo (time() - $time), " seconds\n";



