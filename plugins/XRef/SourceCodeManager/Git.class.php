<?php
/**
 * Implementation of source code manager for git
 *
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

class XRef_SourceCodeManager_Git implements XRef_ISourceCodeManager {

    /**
     * @return void
     */
    public function updateRepository() {
        if (XRef::getConfigValue("git.update-method", "fetch")=="pull") {
            // here comes a problem:
            // git pull can update files in current directory only
            // shame on git 1.7.6
            $cwd = getcwd();
            chdir( XRef::getConfigValue("git.repository-dir") );
            self::git(array("pull", "--quiet"));
            chdir($cwd);
        } else {
            self::git(array("fetch", "--quiet"));
        }
    }

    /**
     * @return array ("branch-name" => "branch-version", ...)
     */
    public function getListOfBranches() {
        $ignoreBranch = array_fill_keys( XRef::getConfigValue("git.ignore-branch", array()), true );

        $lines = self::git(array("branch", "-v", "-r", "--no-abbrev"), true);
        $branches = array();
        foreach ($lines as $line) {
            // * master
            //   origin/svn/SEG 34959da6a47aaffdbdab0c9d4512da1b5eeba930 Avoid bombing out on excpetiong during blob comparision
            if (!preg_match("#^\\*?\\s*(\\S+)\\s+([0-9a-f]+)#", $line, $matches)) {
                // what's this?
                // origin/HEAD -> origin/master
                continue;
            }
            list($notused, $branchName, $rev) = $matches;

            if (!isset($ignoreBranch[$branchName])) {
                $branches[$branchName] = $rev;
            }
        }

        return $branches;
    }

    /**
     *
     * @param string $revision
     * @return string[]            List of files that were modified from $oldRev to $currentRev
     */
    public function getListOfFiles($revision) {
        return self::git(array("ls-tree", "--name-only", "-r", "'$revision'"), true);
    }


    /**
     *
     * @param string $oldRev
     * @param string $currentRev
     * @return string[]            List of files that were modified from $oldRev to $currentRev
     */
    public function getListOfModifiedFiles($oldRev, $currentRev) {
        return self::git(array("diff", "--name-only", "'$oldRev'", "'$currentRev'"), true);
    }

    /**
     * @param string $revision
     * @param string $filename
     * @return string
     */
    public function getFileContent($revision, $filename) {
        // TODO: cat-file may return SHA1 sum with or without content (--batch options) but to do so,
        //  object ID must be supplied to stdin :(. Use proc_open() here.
        // TODO: current 'git' function implementation strips spaces at end of content,
        //  so sha1 sum of file is different from git's sha1 sum
        $content = self::git(array("cat-file", "blob", "'$revision:$filename'", "2>&1"), false, false);
        return $content;
    }

    /**
     * @param string $revision
     * @return array ('an' => 'author name', 'ae' => 'author e-mail', ...)
     */
    public function getRevisionInfo($revision) {
        $knownFields = array('ae','aE', 'an', 'cn', 'ce', 'cE');
        $formatFields = array();
        foreach ($knownFields as $f) {
            $formatFields[] = "%$f";
        }
        $format = implode($formatFields, '%n');
        $lines = self::git(array("show", "-s", "--format='$format'", "'$revision'"), true);
        $commitInfo = array();
        for ($i=0; $i<count($knownFields); ++$i) {
            $commitInfo[ $knownFields[$i] ] = $lines[$i];
        }
        return $commitInfo;
    }

    /**
     * Internal wrapper to run git executable, returns git output
     * Warning: extra spaces/newlines at command output are trimmed
     *
     * @param string[] $arguments
     * @param boolean $wantarray
     * @param boolean $failOnError
     * @return string[]|string
     */
    private static function git($arguments, $wantarray = false, $failOnError=true) {
        $gitDir     = XRef::getConfigValue("git.repository-dir") . "/.git";

        $arguments = array_merge(array("git", "--git-dir=$gitDir"), $arguments);
        $cmd = implode($arguments, " ");

        // hate php:
        //  - shell_exec can't tell failed run from empty output
        //  - exec returns output in array (even if I need a scalar)
        //  - system returns the last line only
        //  - proc_open/proc_close is good but overcomplicated
        exec($cmd, $output, $return_var);
        if ($return_var != 0) {
            if ($failOnError) {
                die("Can't execute $cmd");
            } else {
                $output = array();
            }
        }

        if ($wantarray) {
            return $output;
        } else {
            return rtrim( implode($output, "\n") );
        }
    }

}
