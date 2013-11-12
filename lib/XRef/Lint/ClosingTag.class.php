<?php
/**
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

/**
 * Plugin to report unneeded closing tags and extra spaces before the opening tag
 *
 */
class XRef_Lint_ClosingTag extends XRef_ALintPlugin {

    public function __construct() {
        parent::__construct("lint-closing-tag", "Lint (use of php closing tag)");
    }

    const E_EXTRA_SPACES    = "xr051";
    const E_CLOSING_TAG     = "xr052";

    public function getErrorMap() {
        return array(
            self::E_EXTRA_SPACES => array(
                "severity"  => XRef::WARNING,
                "message"   => "Spaces before opening tag (%s)",
            ),
            self::E_CLOSING_TAG => array(
                "severity"  => XRef::WARNING,
                "message"   => "Unneeded closing tag (%s)",
            ),
       );
    }

    protected $supportedFileType    = XRef::FILETYPE_PHP;

    public function getReport(XRef_IParsedFile $pf) {
        if ($pf->getFileType() != $this->supportedFileType) {
            return;
        }

        $this->report = array();

        $tokens = $pf->getTokens();
        if (!$tokens) {
            return;     // skip empty php files
        }

        $skip_closing_tag_checks = false;

        // do we have text before the opening tag?
        $t = $tokens[0];
        if ($t->kind != T_OPEN_TAG) {
            if ($t->kind != T_INLINE_HTML) {
                throw new XRef_ParseException($t);
            }

            // is there are spaces only or meaningful html?
            // regexp = optional byte order mark in the beginning + (spaces or nbsp)+
            $regexp = "#^(\\xEF\\xBB\\xBF|\\xFE\\xFF|\\xFF\\xFE|\\x00\\x00\\xFE\\xFF|\\xFF\\xFE\\x00\\x00)?[\\s\\xa0]*$#";
            if (preg_match($regexp, $t->text)) {
                $this->addDefect($t, self::E_EXTRA_SPACES);
            } else {
                $skip_closing_tag_checks = true;
            }
        }

        if (!$skip_closing_tag_checks) {
            $count = count($tokens);
            for ($i=0; $i<$count; ++$i) {
                $t = $tokens[$i];
                // if there is only one closing tag, and there is nothing after it
                // (except maybe a space-only T_INLINE_HTML), report an error
                if ($t->kind == T_CLOSE_TAG) {
                    if ($i == $count-1) {
                        $this->addDefect($t, self::E_CLOSING_TAG);
                    } elseif ($i == $count-2) {
                        $n = $tokens[$count-1];
                        if (preg_match('#^[\\s+\\xa0]*$#', $n->text)) {
                            $this->addDefect($t, self::E_CLOSING_TAG);
                        }
                    }
                    break;
                }
            }
        }

        return $this->report;
    }
}
