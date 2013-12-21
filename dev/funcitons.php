<?php


if (count($argv) < 2) {
    echo "Usage:\n";
    echo "$argv[0] --list\tList all internal functions\n";
    echo "$argv[0] --define\tCreate a storable dump for given functions\n";
    echo "$argv[0] <func-name>\tCreate a string representaion\n";
    echo "php5.2 $argv[0] --list | php5.3 $argv[0] --define > php5.2.functions.ser\n";
} elseif ($argv[1] == "--list") {
    $defined_functions = get_defined_functions();
    foreach ($defined_functions["internal"] as $function_name) {
        echo $function_name, "\n";
    }
} elseif ($argv[1] == "--define") {
    $functions = array();   // function name --> function str
    while (($str = fgets(STDIN)) !== false) {
        $function_name = trim($str);
        $func_str = function_signature($function_name);
        if ($func_str) {
            $functions[$function_name] = $func_str;
        }
    }
    echo serialize($functions);
} else {
    for ($i = 1; $i < count($argv); ++$i) {
        $function_name = $argv[$i];
        $sign = function_signature($function_name);
        echo "'$function_name' => '$sign',\n";
    }
}

function function_signature($function_name) {
    try {
        $rf = new ReflectionFunction($function_name);
    } catch (Exception $e) {
        return null;
    }

    $params = array();
    $unknown_param_name = 'unk0';

    foreach ($rf->getParameters() as /** @var ReflectionParameter $param */ $rp) {
        $param = ($rp->isPassedByReference()) ? '&' : '';
        $name = $rp->getName();
        if ($name == '') {
            $name = '$' . $unknown_param_name;
            $unknown_param_name++;
        } elseif ($name =='...') {
        } else {
            $name = '$' . $name;
        }
        $param .= $name;
        if ($rp->isOptional()) {
            $param .= ' = null'; // is there better way to show that it's optional?
        }
        $params[] = $param;
    }

    $str = "";
    $str .= ($rf->returnsReference()) ? '&' : '';
    $str .= $rf->getName();
    $str .= '(' . implode(", ", $params) . ')';

    return $str;
}

