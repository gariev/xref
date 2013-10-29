<?php
/**
 * file: lib/ci-tools.php
 *
 * Bunch of functions for work with list of errors, comparing files, diffs etc
 *
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

// input:arrays of XRef_CodeDefect objects
// output: array of XRef_CodeDefect that are new
function XRef_getNewErrors($oldErrors, $currentErrors) {
    // 1. create array of text messages without line number
    $a1 = XRef_createErrorsDigest($oldErrors);
    $a2 = XRef_createErrorsDigest($currentErrors);
    // 2. compare arrays using string equality
    $diff = XRef_phpDiff($a1, $a2);
    $newErrors = array();
    foreach ($diff as $d) {
        if ($d[0]==-1) {
            // this line is in $currentErrors/$a2 only
            $newErrors[] = $currentErrors[ $d[1] ];
        }
    }
    return $newErrors;
}

// input: array (file name => array of XRef_CodeDefect objects)
// output: array (file name => array of XRef_CodeDefect objects)
function XRef_getNewProjectErrors($oldErrors, $currentErrors) {
    $result = array();
    foreach ($currentErrors as $filename => $errors_list) {
        if (!isset($oldErrors[$filename])) {
            $result[$filename] = $errors_list;
        } else {
            $diff = XRef_getNewErrors($oldErrors[$filename], $errors_list);
            if ($diff) {
                $result[$filename] = $diff;
            }
        }
    }
    return $result;
}

// input: array or arrays($lineNumber, $severity, $message, $tokenText, $method)
// output: array of strings, identifying the error, but without line numbers
function XRef_createErrorsDigest($errorList) {
    $result = array();
    foreach ($errorList as $e) {
        $result[] = "$e->tokenText##$e->errorCode##$e->inMethod";
    }
    return $result;
}

//
// function XRef_phpDiff (PHPDiff) is taken with modifications from http://www.holomind.de/phpnet/diff2.src.php,
// published by GPL licence.
// Copyright (C) 2003  Daniel Unterberger <diff.phpnet@holomind.de>
// Copyright (C) 2005  Nils Knappmeier next version
// Contact: d.u.diff@holomind.de <daniel unterberger>
//
// Input:
//      2 arrays of lines
// Output:
//      combined diff in form of array of pairs (index1, index2) for matched lines,
//      index1 or index2 is -1 if the line is missing in left or right array
//
function XRef_phpDiff($t1,$t2) {
    # build a reverse-index array using the line as key and line number as value
    # don't store blank lines, so they won't be targets of the shortest distance
    # search
    $r1 = array();
    $r2 = array();
    foreach($t1 as $i=>$x) if ($x>'') $r1[$x][]=$i;
    foreach($t2 as $i=>$x) if ($x>'') $r2[$x][]=$i;

    $a1=0; $a2=0;   # start at beginning of each list
    $actions=array();
    $result=array();

    # walk this loop until we reach the end of one of the lists
    while ($a1<count($t1) && $a2<count($t2)) {
        # if we have a common element, save it and go to the next
        if ($t1[$a1]==$t2[$a2]) { $result[]=array($a1, $a2); $a1++; $a2++; continue; }

        # otherwise, find the shortest move (Manhattan-distance) from the
        # current location
        $best1=count($t1); $best2=count($t2);
        $s1=$a1; $s2=$a2;
        while(($s1+$s2-$a1-$a2) < ($best1+$best2-$a1-$a2)) {
            $d=-1;
            foreach((array)@$r1[$t2[$s2]] as $n)
                if ($n>=$s1) { $d=$n; break; }
                if ($d>=$s1 && ($d+$s2-$a1-$a2)<($best1+$best2-$a1-$a2))
                { $best1=$d; $best2=$s2; }
                    $d=-1;
                    foreach((array)@$r2[$t1[$s1]] as $n)
                    if ($n>=$s2) { $d=$n; break; }
                    if ($d>=$s2 && ($s1+$d-$a1-$a2)<($best1+$best2-$a1-$a2))
                    { $best1=$s1; $best2=$d; }
                    $s1++; $s2++;
        }
        while ($a1<$best1) { $result[]=array($a1, -1); $a1++; }  # deleted elements
        while ($a2<$best2) { $result[]=array(-1, $a2); $a2++; }  # added elements
    }

    # we've reached the end of one list, now walk to the end of the other
    while($a1<count($t1)) { $result[]=array($a1, -1); $a1++; }  # deleted elements
    while($a2<count($t2)) { $result[]=array(-1, $a2); $a2++; }  # added elements

    return $result;
}


