<?php
/**
 * This software is released under the BSD 3-Clause Clear License.
 *
 * Copyright (c) 2017, Mark Clements
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted (subject to the limitations in the disclaimer
 * below) provided that the following conditions are met:
 *
 * * Redistributions of source code must retain the above copyright notice, this
 *   list of conditions and the following disclaimer.
 *
 * * Redistributions in binary form must reproduce the above copyright notice,
 *   this list of conditions and the following disclaimer in the documentation
 *   and/or other materials provided with the distribution.
 *
 * * Neither the name of the copyright holder nor the names of its contributors may
 *   be used to endorse or promote products derived from this software without
 *   specific prior written permission.
 *
 * NO EXPRESS OR IMPLIED LICENSES TO ANY PARTY'S PATENT RIGHTS ARE GRANTED BY THIS
 * LICENSE. THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO,
 * THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE
 * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE
 * GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION)
 * HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT
 * OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH
 * DAMAGE.
 *
 */

// PHPSerialisationFixer()
// Class to fix-up an invlid PHP serialised array.
// If passed a valid serialised array, the output should be identical to the input.
// The class should hopefully fix the two most common causes of bad serialisation:
// * String lengths being incorrect due to corruption (e.g. character-set conversion
//	 or manual editing).
// * The serialised string being truncated (e.g. due to DB length limits being hit).
// The class also handles some other less-common corruptions, however there are
// certain types of corruption that it cannot reasonably detect or fix.  Specifically
// the following are not currently handled:
// * References/recursion where the reference has valid syntax but points to an
//   invalid location (either the specified element does not exist, or it refers to
//	 a non-object when an object is expected).
// * It is possible for an array or object to end up containing multiple instances of
//	 the same key after fixing (though only if it was otherwise invalid).  I am not
//	 sure whether this will result in an invalid serialisation or just cause the
//	 subsequent instances of the array keys to overwrite earlier ones.
// Information based partly on experimentation, but also on a very useful
// description from the PHP internals book, located at
// http://www.phpinternalsbook.com/classes_objects/serialization.html

class PHPSerialisationFixer {

	const pSTRINGTYPE_Normal = 0;
	const pSTRINGTYPE_ClassName = 1;
	const pSTRINGTYPE_CustomObjectContents = 2;

// FixPHPSerialisation()
// Fixes bad PHP serialisation in the supplied string, returning the fixed-up
// version.
// Valid serialisations will be returned unmodified, however you are advised to
// attempt to unserialize() first (with error suppression) and only call this
// function if unserialize() failed as you will get much better performance that way.
// Non-strings are also returned unmodified.
// If you set the $Debug argument to true, then the function will also print some
// useful diagnostic information.
	static function FixPHPSerialisation($BadString, $Debug = false) {
		if ($Debug) {
			print("\nInput:  ");
			var_dump($BadString);
		}

		if (!is_string($BadString))
			$Result = $BadString;

	// If there is no data in the serialisation, we treat this as equivalent to
	// null, and so we return this in its serialised form.
		elseif ($BadString == "")
			$Result = serialize(null);

	// Process the first element of the string.  If there is more than one element
	// then $BadString will end up non-empty.  If this happens, we currently just
	// discard the additional content, however perhaps we could handle this in some
	// more useful way.
	// TODO: Consider if there is a better approach to bad content.
		else
			$Result = self::pProcessNextElement($BadString);

		if ($Debug) {
			print("Result: ");
			var_dump($Result);
			print("Unprocessed: ");
			var_dump($BadString);
		}

		return $Result;
	}

	static function pProcessNextElement(&$UnprocessedString)
	{
		$FirstChar = self::substr($UnprocessedString, 0, 1);
		$UnprocessedString = self::substr($UnprocessedString, 1);

	// Check which type of variable we have encountered - each type is handled
	// separately via a dedicated sub-function.
	// NOTE: PHP will serialise all resource-type variables as integer zero
	//		 (i.e. "i:0;") therefore there is no entry for handling resources in this
	//		 switch statement.
		switch ($FirstChar) {
		// Null values.
			case "N":
				$Result = self::pProcessNullElement($UnprocessedString);
				break;
		// Boolean values.
			case "b":
				$Result = self::pProcessBooleanElement($UnprocessedString);
				break;
		// Integer values.
			case "i":
				$Result = self::pProcessIntegerElement($UnprocessedString);
				break;
		// Double (float) values.
			case "d":
				$Result = self::pProcessDoubleElement($UnprocessedString);
				break;
		// String values.
			case "s":
				$Result = self::pProcessStringElement($UnprocessedString,
													  self::pSTRINGTYPE_Normal);
				break;
		// Array values.
			case "a":
				$Result = self::pProcessArrayElement($UnprocessedString);
				break;
		// Objects.
			case "O":
				$Result = self::pProcessObjectElement($UnprocessedString);
				break;
		// Custom serialisation functions.
			case "C":
				$Result = self::pProcessCustomObjectElement($UnprocessedString);
				break;
		// References.  These use both "R" and "r", depending on whether it is an
		// explicit reference due to assignment via =& or an implicit reference
		// to an object (as objects are always passed by reference).
			case "R":
				$Result = self::pProcessReferenceElement($UnprocessedString, false);
				break;
			case "r":
				$Result = self::pProcessReferenceElement($UnprocessedString, true);
				break;
		// Default, to handle any other specifier we encounter.
		// This won't ever occur in a valid string, but may occur in our corrupted
		// string, in which case we ignore the rest of the string and treat it as
		// null.
			default:
				$Result = serialize(null);
				break;
		}

		return $Result;
	}

	static function pProcessNullElement(&$UnprocessedString) {
	// We expect a semi-colon as the next character.
	// If found, this is a valid element, so we remove the semi-colon from the
	// input string.
	// Otherwise we return null, but leave the unexpected character in the
	// unprocessed string.
		$FirstChar = self::substr($UnprocessedString, 0, 1);
		if ($FirstChar == ";")
			$UnprocessedString = self::substr($UnprocessedString, 1);

	// In either case, null is the value to be returned.
		return serialize(null);
	}

	static function pProcessBooleanElement(&$UnprocessedString) {
	// The remaining part of the syntax will either be ":0;" for false or ":1;" for
	// true.  We accept ":\d*;?" as a fixable boolean reference, with all zero or
	// blank representations equating to false and anything else representing true.
	// If it doesn't match this pattern then we return false and do not modify the
	// unprocessed string at all.
	// As it happens, this is the same as the way we process integer elements, except
	// for a final cast to boolean, so we leverage that function to handle the
	// processing.
		$Result = self::pProcessIntegerElement($UnprocessedString, true);
		$Result = (bool) $Result;

		return serialize($Result);
	}

	static function pProcessIntegerElement(&$UnprocessedString,
										   $SkipReserialisation = false)
	{
	// We process integers in the same way as doubles.  This means that integers
	// which contain decimal parts will be converted to the appropriate integer
	// value, should that occur.
		$Result = self::pProcessDoubleElement($UnprocessedString, true);
		$Result = intval($Result);

		if (!$SkipReserialisation)
			$Result = serialize($Result);

		return $Result;
	}

	static function pProcessDoubleElement(&$UnprocessedString,
										  $SkipReserialisation = false)
	{
		if (preg_match('/^:(-?\d*(\.\d*)?)(;|$)/', $UnprocessedString, $arrMatches)) {
			$Result = floatval($arrMatches[1]);
			$UnprocessedString = self::substr($UnprocessedString,
											  strlen($arrMatches[0]));
		}
		else {
		// Strip a leading colon, if present.
			if (self::substr($UnprocessedString, 0, 1) == ":")
				$UnprocessedString = self::substr($UnprocessedString, 1);

		// If invalid, return a value of zero, represented as a float.
			$Result = 0.0;
		}

		if (!$SkipReserialisation)
			$Result = serialize($Result);

		return $Result;
	}

// pProcessStringElement()
// The second argument is used to handle some variations in string handling that
// may occur in different situations (mainly regarding end-of-string detection).
	static function pProcessStringElement(&$UnprocessedString, $StringType) {
	// If we can't extract a valid string value, we default to returning a
	// serialized empty string.
		$Result = "";

	// Array of recognised quote characters.  Key is the opening character and
	// value is the closing character (which may not be the same).
	// As we are attempting to catch/fix errors, we don't restrict the range of
	// items we check for to just those that we expect based on the string type, but
	// instead always check for any of them.
		$arrRecognisedQuoteChars = array(
									// Standard quote character, normally used for
									// strings.
										'"'	=> '"',
									// Brace character, used for custom object
									// serialisation, which has the format of a
									// string but with brace delimiters.
										"{"	=> "}",
									// Other quote characters.  I'm not sure how
									// these would ever occur, except by someone
									// manually editing the string and making a
									// mistake.  However, we detect them anyway, why
									// not.
										"'" => "'",
									);

	// This variable specifies the character that we expect to follow the string
	// declaration in the input stream.
		if ($StringType == self::pSTRINGTYPE_Normal)
			$EndMarker = ";";
		else
			$EndMarker = ":";

		$ExpectedLength = self::pExtractLength($UnprocessedString);

		if ($ExpectedLength !== false) {
		// Extract the quote character that we expect to be present.  We allow
		// for the unlikely event that a single-quote is used.
			$QuoteChar = self::substr($UnprocessedString, 0, 1);
			if (isset($arrRecognisedQuoteChars[$QuoteChar])) {
				$UnprocessedString = self::substr($UnprocessedString, 1);

			// Set expected closing quote, which may or may not differ from the
			// opening quote.
				$QuoteChar = $arrRecognisedQuoteChars[$QuoteChar];

			// Check for the very common case where the string is the only item
			// left in the input string.
				if (strlen($UnprocessedString) == ($ExpectedLength + 1)
						&& self::substr($UnprocessedString, -1) == $QuoteChar)
				{
					$Result = self::substr($UnprocessedString, 0, -1);
					$UnprocessedString = "";
				}
			// Another common case - where the string length matches the expected
			// length.
			// Note that it is theoretically possible for a string to be mangled in
			// such a way that this test incorrectly passes.  However, I'm not sure
			// whether there is a better way of handling this than to simply assume
			// that what appears correct is actually correct.
				elseif (self::substr($UnprocessedString, $ExpectedLength, 2)
						== $QuoteChar . $EndMarker)
				{
					$Result = self::substr($UnprocessedString, 0, $ExpectedLength);
					$UnprocessedString = self::substr($UnprocessedString,
													  $ExpectedLength + 2);
				}
			// Check for truncation.  In this case the remainder of the input is the
			// contents of the string, excluding any closing quote mark that may be
			// present.
				elseif (strlen($UnprocessedString) <= $ExpectedLength) {
					$Result = $UnprocessedString;
					if (self::substr($Result, -2) == $QuoteChar . $EndMarker)
						$Result = self::substr($Result, 0, -2);
					elseif (self::substr($Result, -1) == $QuoteChar)
						$Result = self::substr($Result, 0, -1);
					$UnprocessedString = "";
				}
			// Check for valid strings that have had an encoding change that has
			// changed their length.  This particular check only handles situations
			// where this is the final element.
				elseif (self::substr($UnprocessedString, -2)
						== $QuoteChar . $EndMarker)
				{
					$Result = self::substr($UnprocessedString, 0, -2);
					$UnprocessedString = "";
				}
				else {
					$NextQuotePos = strpos($UnprocessedString, $QuoteChar);

				// If the closing quote character is not in the string, the entire
				// rest of the input is treated as the result.
					if ($NextQuotePos === false) {
						$Result = $UnprocessedString;
						$UnprocessedString = "";
					}
				// If the closing quote is the final character in the string, then
				// the result is the contents of the defined string.
					elseif ($NextQuotePos == (strlen($UnprocessedString) - 1)) {
						$Result = self::substr($UnprocessedString, 0, -1);
						$UnprocessedString = "";
					}
				// If we get to this point, then we have a mangled string, which
				// contains a closing quote but does not contain a closing-quote +
				// end-marker pair.  The closing quote is not in the expected
				// location nor at the end of the string.  In addition, there are
				// more characters remaining in the unprocessed string than we were
				// expecting in the string.
				// This is a combination of errors that is unlikely to occur in
				// practice.
				// For now we simply return the remainder of the input as the string
				// as until I understand better what scenarios could lead to this
				// situation it is not clear how better we could handle it.
				// TODO: Investigate the code paths in more detail to work out
				//		 when this could be hit, and therefore if there is a more
				//		 intelligent way of handling it.
					else {
						$Result = $UnprocessedString;
						$UnprocessedString = "";
					}
				}
			}
		// If there was no opening quote, we look for the first instance of any
		// recognised closing quote followed by the expected end marker.  If this is
		// found, we return everything up to that point.  Otherwise, the entire rest
		// of the input is treated as the string value.
			else {
				$Regex = '/^(.*)[' . implode("", $arrRecognisedQuoteChars)
					   . ']' . $EndMarker . '/U';
				if (preg_match($Regex, $UnprocessedString, $arrMatches)) {
					$Result = $arrMatches[1];
					$UnprocessedString = self::substr($UnprocessedString,
													  strlen($Result) + 2);
				}
				else {
					$Result = $UnprocessedString;
					$UnprocessedString = "";
				}
			}
		}

		if ($StringType == self::pSTRINGTYPE_Normal) {
			$Result = serialize($Result);
		}

		return $Result;
	}

	static function pProcessArrayElement(&$UnprocessedString) {
	// If we can't extract a valid array representation, we default to returning a
	// serialized empty array.
		$Result = array();

		$ExpectedLength = self::pExtractLength($UnprocessedString);

		if ($ExpectedLength !== false) {
		// If we don't have an opening brace, then create an empty array containing
		// the expected number of elements, all null.
			if (self::substr($UnprocessedString, 0, 1) != "{") {
				for ($i = 0; $i < $ExpectedLength; $i++)
					$Result[] = null;
			}
		// Otherwise, we process all elements in the array, until we come to a
		// closing tag or the end of the string.
			else {
			// Remove the opening brace.
				$UnprocessedString = self::substr($UnprocessedString, 1);

			// Process the rest of the string until we get to the end of the array.
				$ArrayCount = 0;
				$ArrayContents = "";
				while (true) {
				// If there is no string left, break out of the infinite loop.
					if ($UnprocessedString == "") {
						break;
					}
				// If we find a closing brace, remove it from the unprocessed string
				// (along with a subsequent semi-colon, if present) and break out of
				// the infinite loop - our array is complete.
					elseif (self::substr($UnprocessedString, 0, 1) == "}") {
						$UnprocessedString = self::substr($UnprocessedString, 1);
						if (self::substr($UnprocessedString, 0, 1) == ";")
							$UnprocessedString = self::substr($UnprocessedString, 1);
						break;
					}
				// Otherwise, there is more to process.
					else {
						$Key = self::pProcessNextElement($UnprocessedString);
						$Value = self::pProcessNextElement($UnprocessedString);

						$ArrayCount++;
						$ArrayContents .= $Key . $Value;
					}
				}

			// We have built up the serialised string, not the actual array
			// itself, therefore we need to top and tail it ourselves, and return the
			// result here, as opposed to dropping-through and allowing the result
			// to be serialised directly.
				return "a:" . $ArrayCount . ":{" . $ArrayContents . "}";
			}
		}

		return serialize($Result);
	}

	static function pProcessObjectElement(&$UnprocessedString) {
		$ClassName = self::pProcessStringElement($UnprocessedString,
												 self::pSTRINGTYPE_ClassName);

		if ($ClassName !== "") {
			$UnprocessedString = ":" . $UnprocessedString;
			$ClassContents = self::pProcessArrayElement($UnprocessedString, true);

			$ClassContents = self::substr($ClassContents, 1);

			return 'O:' . strlen($ClassName) . ':"' . $ClassName . '"'
					. $ClassContents;
		}

	// If we didn't get a valid class definition, then we return serialized null.
	// This seems more sensible than returning an empty stdClass object which is
	// probably the only other feasible option.
		return serialize(null);
	}

// pProcessCustomObjectElement()
// Custom object serialisations are similar to object serialisations, but instead
// of the object contents being represented in array notation they are represented
// in what is effetively string notation (the length argument specifies the length
// of the contents in bytes).  However, the string is enclosed in brace characters
// instead of quotes.
	static function pProcessCustomObjectElement(&$UnprocessedString) {
		$ClassName = self::pProcessStringElement($UnprocessedString,
												 self::pSTRINGTYPE_ClassName);

		if ($ClassName !== "") {
			$UnprocessedString = ":" . $UnprocessedString;
			$ClassContents = self::pProcessStringElement($UnprocessedString,
											self::pSTRINGTYPE_CustomObjectContents);

			return 'C:' . strlen($ClassName) . ':"' . $ClassName . '":'
					. strlen($ClassContents) . ":{" . $ClassContents . "}";
		}

	// If we didn't get a valid class definition, then we return serialized null.
	// This seems more sensible than returning an empty stdClass object which is
	// probably the only other feasible option.
		return serialize(null);
	}

// pProcessReferenceElement()
// References are syntactically the same as integers, but we should also check that
// they refer to an element that actually exists in the serialised string.
	static function pProcessReferenceElement(&$UnprocessedString, $IsObjectReference)
	{
	// Handle the object reference as if it is an integer, which will parse out the
	// correct value.
		$Result = self::pProcessIntegerElement($UnprocessedString, true);

	// Check the value refers to a valid element in our serialised data string.
	// If $IsObjectReference is true, that element must - in addition - be an
	// object.
	// If the reference lookup fails, return serialized null, rather than attempting
	// to guess where it might have intended to link to.
	// TODO: No idea how we can reliably detect this kind of broken reference!
	//		 It may or may not be possible to implement this check.

	// Re-serialise the reference into a string.
	// We have to do this manually, as a call to serialize() won't generate the
	// correct index.
		if ($IsObjectReference)
			$Result = "r:" . $Result . ";";
		else
			$Result = "R:" . $Result . ";";

		return $Result;
	}

// pExtractLength()
// Extracts and returns the length from the head of the unprocessed string, removing
// it in the process.
// If the string does not contain a valid length specification, false is returned.
// Otherwise the result will be an integer.
	static function pExtractLength(&$UnprocessedString) {
	// We recognise a length value as starting with a colon, followed by zero or
	// more digits, followed by another colon.
		if (preg_match('/^:(\d*):/', $UnprocessedString, $arrMatches)) {
			$UnprocessedString = self::substr($UnprocessedString,
											  strlen($arrMatches[0]));
			return intval($arrMatches[1]);
		}
	// If it doesn't match that pattern then we consider it to be invalid, and false
	// is returned.
		else {
		// Strip a leading colon, if present.
			if (self::substr($UnprocessedString, 0, 1) == ":")
				$UnprocessedString = self::substr($UnprocessedString, 1);

			return false;
		}
	}

// substr()
// Fixes bug in native PHP substr() function, whereby if $Start == strlen($Input)
// it returns false instead of "".  This was fixed in PHP 7, but we use a shim to
// ensure we get consistent and correct results in all PHP versions.
	static function substr($Input, $Start, $Length = null) {
		if ($Start == strlen($Input))
			return "";
		elseif ($Length === null)
			return substr($Input, $Start);
		else
			return substr($Input, $Start, $Length);
	}

} // END class PHPSerialisationFixer

?>