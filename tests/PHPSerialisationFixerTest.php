<?php

require_once(dirname(__FILE__) . "/../classPHPSerialisationFixer.php");

class PHPSerialisationFixerTest extends PHPUnit_Framework_TestCase {

///////////////////////////////////
// DATA PROVIDERS

	function ValidSerialisationProvider() {
	// Resources serialise the same as integer zero, but we include an example for
	// completeness.
		$Resource = imagecreate(20, 20);

	// Set up some self-references, to check these are handled correctly.
		$arr = array();
		$arr[] =& $arr;

		$obj = new stdClass;
		$obj->ObjectAssignedByReference =& $obj;
		$obj->ObjectAssignedImplicitly = $obj;
		$obj->ArrayAssignedByReference =& $arr;
		$obj->ArrayAssignedByValue = $arr;

		$arrRawTestData = array(
							null,
							false,
							true,
							0,
							0.0,
							4,
							4.5,
							-10,
							-124.152,
							"",
							"test",
							'"',
							"'",
							":",
							";",
							"{",
							"}",
							array(),
							new stdClass,
							$Resource,
							array("fish", "balloon"),
							array(1, 2, 'dog' => 'fish', array(4, 2, "boat"), null),
							$arr,
							$obj,
							new pTestHelper_ScopedVariables(),
						);

	// Set up some objects with a custom serialisation.
		$arrCustomObjectData = array(
						"simple",
						"simple with : character",
						"simple with ; character",
						"simple with } character",
						"simple with { character",
						"simple with \0 character",
						"test}with:multiple;odd{characters\0",
					);

		foreach ($arrCustomObjectData as $Data)
			$arrRawTestData[] = new pTestHelper_CustomSerialisation($Data);

		$arrTestData = array();
		foreach ($arrRawTestData as $Value) {
			$arrTestData[] = array(serialize($Value));
		}

		return $arrTestData;
	}

	function InvalidSerialisationProvider() {
		$arrTestData = array(
				// Change of string length.  Normally would occur due to encoding
				// issues, but I've gone for easier-to-read examples.
					array('s:5:"hello!";', 's:6:"hello!";'),
					array('s:5:"hell";', 's:4:"hell";'),

				// Truncations (string).
				// Our starting point is the serialisation: s:5:"hello";
					array('s:5:"hello";',	's:5:"hello";'),
					array('s:5:"hello"',	's:5:"hello";'),
					array('s:5:"hello',		's:5:"hello";'),
					array('s:5:"hell',		's:4:"hell";'),
					array('s:5:"hel',		's:3:"hel";'),
					array('s:5:"he',		's:2:"he";'),
					array('s:5:"h',			's:1:"h";'),
					array('s:5:"',			's:0:"";'),
					array('s:5:',			's:0:"";'),
					array('s:5',			's:0:"";'),
					array('s:',				's:0:"";'),
					array('s',				's:0:"";'),
					array('',				'N;'),

				// Truncations (string) containing quote character.
				// Our starting point is the serialisation: s:3:"a"b";
					array('s:3:"a"b";',		's:3:"a"b";'),
					array('s:3:"a"b"',		's:3:"a"b";'),
					array('s:3:"a"b',		's:3:"a"b";'),
					array('s:3:"a"',		's:1:"a";'),
					array('s:3:"a',			's:1:"a";'),
					array('s:3:"',			's:0:"";'),
					array('s:3:',			's:0:"";'),
					array('s:3',			's:0:"";'),
					array('s:',				's:0:"";'),
					array('s',				's:0:"";'),
					array('',				'N;'),

				// Some odd examples, that are unlikely to occur in practice, but
				// which we currently handle.
				// If no opening quote character for a string, we look for a valid
				// closer, or else use the whole remainder of the string.
					array('s:5:foo',		's:3:"foo";'),
					array('s:5:foo";',		's:3:"foo";'),
					array('s:5:foo"bar;',	's:8:"foo"bar;";'),
					array('s:5:foo"bar"',	's:8:"foo"bar"";'),
					array('s:5:foo"bar";',	's:7:"foo"bar";'),
				);

		return $arrTestData;
	}

///////////////////////////////////
// TESTS

	function testLoadComponent() {
		$this->assertTrue(class_exists("PHPSerialisationFixer"));
	}

/**
 * Checks that any valid serialisations are returned unmodified.
 * @dataProvider ValidSerialisationProvider
 */
	function testValidSerialisations($OriginalString) {
		$FixedString = PHPSerialisationFixer::Fix($OriginalString);
		$this->assertSame($OriginalString, $FixedString);
	}

/**
 * Checks that any invalid serialisations are fixed in the manner we expect.
 * @dataProvider InvalidSerialisationProvider
 */
	function testInvalidSerialisations($Input, $ExpectedOutput) {
		$ActualOutput = PHPSerialisationFixer::Fix($Input);
		$this->assertSame($ExpectedOutput, $ActualOutput);
	}

} // END class PHPSerialisationFixerTest

//////////////////////////////////
// HELPER CLASSES

class pTestHelper_CustomSerialisation implements Serializable {

	function __construct($Output) {
		$this->pOutput = $Output;
	}

    function serialize() {
		return $this->pOutput;
    }

    function unserialize($data) {
    }

}

class pTestHelper_ScopedVariables {
	public $PublicVar = "public";
	private $PrivateVar = "private";
	protected $ProtectedVar = "protected";
}

?>