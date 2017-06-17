# PHPSerializationFixer
A tool to fix corrupted PHP serialised strings.

# Background

It's happened to the best of us.  You've stored a bunch of data into your database
using PHP's ````serialize()```` function and everything has been working well for a
while.  However, your users have suddenly started reporting blank pages or PHP fatal
errors.  Upon some digging, it turns out your PHP serialisations have somehow been
corrupted!

Oh no!

Anyone who's been in this situation knows that once this happens your choice boils
down to two options: spend hours going through the data and piecing it back together,
in order to salvage what you can; or taking the quicker option and wiping the invalid
data altogether.

Not any more!

This repository provides a class which can be used to repair common serialisation
problems which should, at the very least, allow you to de-serialise the string and
recover the maximum amount of data possible.

# Approach

This tool is designed to fix two of the most common causes of data corruption in
PHP serialised strings.

* **Corruption caused by the string being truncated.**
  <br>For example, when ````VARCHAR(255)```` turns out not to have been such a good
  idea after all.
* **Corruption caused by encoding changes.**
  <br>For example, when you find you've been accidentally sending UTF-8 data to
  a latin1 field and your database has been helpfully converting your multi-byte
  characters into single-byte represenations, or vice-versa.

In addition, the tool attempts to handle other bad input it encounteres in a
sensible manner, though other use-cases are less well-defined.

Finally, there is one over-arching principal, which is absolutely key and
(thankfully) very easy to write unit tests for:

* **The tool must not corrupt valid strings.**
  <br>If provided with a valid PHP serialised string, the tool will
  return the input string, unmodified.

This final point is paramount - it is better that corruption goes unfixed than
the tool potentially introduces further corruption!

# Usage

To use the tool, you just need the single class file,
````classPHPSerialisationFixer.php````.  All other files in this repository are
for testing/documentation purposes and are not required to use the tool.

## Example

````php
require_once("/path/to/classPHPSerialisationFixer.php");
$FixedString = PHPSerialisationFixer::FixPHPSerialisation($CorruptString);
````

## Recomendations for production code

The above example shows how to use the tool to fix known-bad strings.  However,
if you plan to use the tool to find and fix errors on-the-fly as part of your
production code, for performance reasons you are recommended to only attempt
a fix when a bad string is encountered.

Here is an example of how you might use the tool for on-the-fly fixes:


````php
<?php

// Include PHPSerialisationFixer class.
	require_once("/path/to/classPHPSerialisationFixer.php");

// Unserialise the string, using @ to suppress any PHP errors.
	$Unserialised = @unserialize($Serialised);

// Check for bad unserisalisation.  This check covers the fact that
// the string may be a serialised version of boolean false.  If this is
// not going to be the case for you (e.g. if you know your data is always
// an array or object) then the second part of the if statement can
// be removed.
	if ($Unserialised === false && $Serialised != "b:0;") {
	// Fix up the string.  You can't recover what isn't there, but this will
	// extract as much information as possible.
		$FixedString = PHPSerialisationFixer::FixPHPSerialisation($Serialised);

	// Unserialise the fixed-up string.
		$Unserialised = unserialize($Serialised);

	// You probably also want to log the error somewhere, so you are aware
	// of it.  Bad serialisations are only ever a symptom of some other,
	// deeper problem!

	}

?>
````

# Contributing

## Reporting bugs

Please use the Github issue tracker to report any issues you encounter when using the tool.

If you encounter a serialisation that is fixed in a surprising way that you think could be
improved upon, please include both the original serialisation and the expected output of
the tool as part of your error report.

## Code contributions

Pull requests for fixes or enhancements will be gratefully received.

This project conforms to a coding standard, but this is not publicly documented.  PRs
will not be rejected for reasons of coding style (they will simply be fixed as part of the
merge process) however, please do not attempt to refactor or 'fix' the coding style.  PRs
that contain refactoring will be rejected.

Please make sure you add/update unit tests where appropriate.

## Testing

The ````tests```` folder contains unit tests for the tool, which require
[PHPUnit](https://phpunit.de/) in order to run.  If you have PHPUnit installed, you simply
need to run ````phpunit .```` from within the ````tests```` directory.

# License

This tool is licensed under the BSD 3-Clause Clear license.  See [LICENSE.txt](LICENSE.txt) for details.
