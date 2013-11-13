<?php

if ($argc > 1) {
    array_shift($argv);
    foreach ($argv as $code) {
        tokenize($code);
    }
} else {
    $code = fread(STDIN, 1024);
    tokenize($code);
}

function tokenize($code) {
    foreach (token_get_all($code) as $t) {
        if (is_array($t)) {
            $token_name = token_name($t[0]);
            $text = preg_replace_callback('#[^\\x21-\\x7f]#', 'chr_replace', $t[1]);
        } else {
            $token_name = '';
            $text = preg_replace_callback('#[^\\x21-\\x7f]#', 'chr_replace', $t);
        }
        echo sprintf("%-30s %s\n", $token_name, $text);
    }
}

function chr_replace($matches) {
    return '\\x' . sprintf('%02x', ord($matches[0]));
}

