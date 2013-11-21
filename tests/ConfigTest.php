<?php

$includeDir = ("@php_dir@" == "@"."php_dir@") ? dirname(__FILE__) . "/.." : "@php_dir@/XRef";
require_once "$includeDir/XRef.class.php";

class ConfigTest extends PHPUnit_Framework_TestCase {

    public function testCommandLineParser() {

        // basic test
        $args = array("script.php", "--help");
        list($options, $arguments) = XRef::getCmdOptions($args);
        $this->assertTrue(count($options) == 1);
        $this->assertTrue(count($arguments) == 0);
        $this->assertTrue(isset($options["help"]));
        $this->assertTrue($options["help"] === true);

        // check that the options were cached since last call
        list($options, $arguments) = XRef::getCmdOptions();
        $this->assertTrue(count($options) == 1);
        $this->assertTrue(count($arguments) == 0);
        $this->assertTrue(isset($options["help"]));
        $this->assertTrue($options["help"] === true);
        $this->assertTrue(XRef::needHelp());

        // check that the options are reset
        // WARNING: here the real comman-line options will be read
        // TODO: real options are unknown (phpunit dependent?)
        // so no reliable test can be made (?)
        //
        // list($options, $arguments) = XRef::getCmdOptions(false);
        // print_r( $options );
        // print_r( $arguments );

        // check empty options
        $args = array("script.php");
        list($options, $arguments) = XRef::getCmdOptions($args);
        $this->assertTrue(count($options) == 0);
        $this->assertTrue(count($arguments) == 0);
        $this->assertTrue(!isset($options["help"]));

        // check short --> long options
        $args = array("script.php", "-h", "foo");
        list($options, $arguments) = XRef::getCmdOptions($args);
        $this->assertTrue(count($options) == 1);
        $this->assertTrue(count($arguments) == 0);
        $this->assertTrue(isset($options["help"]));
        $this->assertTrue($options["help"] === 'foo');

        // check custom options with arguments
        XRef::registerCmdOption('a:', "foo-bar=",  '...',  "some help");
        $args = array("script.php", "-a", "baz");
        list($options, $arguments) = XRef::getCmdOptions($args);
        $this->assertTrue(count($options) == 1);
        $this->assertTrue(count($arguments) == 0);
        $this->assertTrue(isset($options["foo-bar"]));
        $this->assertTrue($options["foo-bar"] === "baz");

        $args = array("script.php", "--foo-bar", "baz");
        list($options, $arguments) = XRef::getCmdOptions($args);
        $this->assertTrue(count($options) == 1);
        $this->assertTrue(count($arguments) == 0);
        $this->assertTrue(isset($options["foo-bar"]));
        $this->assertTrue($options["foo-bar"] === "baz");

        $args = array("script.php", "--foo-bar=baz");
        list($options, $arguments) = XRef::getCmdOptions($args);
        $this->assertTrue(count($options) == 1);
        $this->assertTrue(count($arguments) == 0);
        $this->assertTrue(isset($options["foo-bar"]));
        $this->assertTrue($options["foo-bar"] === "baz");

        // check -d (--define) option
        $args = array("script.php", "-d", "foo=bar", "--define", "bz");
        list($options, $arguments) = XRef::getCmdOptions($args);
        $this->assertTrue(count($options) == 1);
        $this->assertTrue(count($arguments) == 0);
        $this->assertTrue(isset($options["define"]));
        $this->assertTrue(is_array($options["define"]));
        $this->assertTrue(count($options["define"]) == 2);
        $this->assertTrue($options["define"][0] == "foo=bar");
        $this->assertTrue($options["define"][1] == "bz");

        // reset and cache to empty (default) values
        list($options, $arguments) = XRef::getCmdOptions( array() );
     }

    public function testConfigValue() {
        // reset and cache to empty (default) values
        list($options, $arguments) = XRef::getCmdOptions( array() );

        // get config by value, save current settings
        $old_config_value = XRef::getConfig();

        $config = XRef::getConfig(true);
        $this->assertTrue(is_array($config));
        $this->assertTrue(!isset($config["foo.bar"]));

        $args = array("test.php", "-d", "foo.bar=true");
        list($options, $arguments) = XRef::getCmdOptions($args);
        $config = XRef::getConfig(true);
        $this->assertTrue(is_array($config));
        $this->assertTrue(isset($config["foo.bar"]));
        $this->assertTrue($config["foo.bar"]);

        $args = array("test.php", "-d", "f.b=true", "--define", "p=false", "-d", "x=y");
        list($options, $arguments) = XRef::getCmdOptions($args);
        $config = XRef::getConfig(true);
        $this->assertTrue(is_array($config));
        $this->assertTrue(!isset($config["foo.bar"]));
        $this->assertTrue(isset($config["f.b"]));
        $this->assertTrue(isset($config["p"]));
        $this->assertTrue(isset($config["x"]));
        $this->assertTrue($config["f.b"] === true);
        $this->assertTrue($config["p"] === false);
        $this->assertTrue($config["x"] === "y");

        // reset command-line options, restore value of the config
        list($options, $arguments) = XRef::getCmdOptions( array() );
        $config_ref = & XRef::getConfig();
        $config_ref = $old_config_value;
    }

}
