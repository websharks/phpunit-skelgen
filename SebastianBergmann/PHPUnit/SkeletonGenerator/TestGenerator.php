<?php
namespace SebastianBergmann\PHPUnit\SkeletonGenerator;

/**
 * Class TestGenerator (enhanced by WebSharks™).
 *
 * @package SebastianBergmann\PHPUnit\SkeletonGenerator
 */
class TestGenerator extends AbstractGenerator
{
	/**
	 * @var array Method names (usage counter).
	 *
	 * @note This allows us to create multiple tests for the same
	 *    method (incrementing each test case; when/if needed).
	 */
	protected $methodNameCounter = array();

	/**
	 * Class Constructor (enhanced by WebSharks™).
	 *
	 * @param string $inClassName From command-line arguments (always required).
	 *    The name of the class we're generating a skeleton for.
	 *    This MUST include the full ``\namespace\class``.
	 *
	 * @param string $inSourceFile From command-line arguments (optional).
	 *    The file which contains the ``$inClassName``.
	 *
	 * @param string $outClassName From command-line arguments (optional).
	 *    The class name for the skeleton we're generating here.
	 *
	 * @param string $outSourceFile From command-line arguments (optional).
	 *    The file name for the skeleton we're generating here.
	 *
	 * @throws \RuntimeException Throws exceptions on various types of failure.
	 */
	public function __construct($inClassName, $inSourceFile = '', $outClassName = '', $outSourceFile = '')
	{
		if(!$inClassName) throw new \RuntimeException('Missing `$inClassName`.');

		if($inClassName === 'ns-class' && $inSourceFile && is_file($inSourceFile) && is_readable($inSourceFile))
		{
			$_inSourceFileContents = file_get_contents($inSourceFile);

			if(preg_match('/^\s*namespace\s+(?P<namespace>[^\s{;]+)/m', $_inSourceFileContents, $_m1))
				if(preg_match('/^\s*class\s+(?P<class>[^\s{]+)/m', $_inSourceFileContents, $_m2))
					$inClassName = '\\'.$_m1['namespace'].'\\'.$_m2['class'];

			unset($_inSourceFileContents, $_m1, $_m2); // Housekeeping.
		}
		if(class_exists($inClassName)) // Already loaded (or auto-loaded)?
		{
			$reflector = new \ReflectionClass($inClassName);
			if(!$inSourceFile) $inSourceFile = $reflector->getFileName();
		}
		else // Class is NOT already loaded (and NOT connected to an autoloader).
		{
			if(!$inSourceFile) // Try to find the file.
			{
				$_possibleFilenames = array(
					$inClassName.'.php',
					str_replace(array('_', '\\'), DIRECTORY_SEPARATOR, $inClassName).'.php'
				);
				foreach($_possibleFilenames as $_possibleFilename)
					if(is_file($_possibleFilename)) $inSourceFile = $_possibleFilename;
				unset($_possibleFilenames, $_possibleFilename); // Housekeeping.
			}
			if(!$inSourceFile || !($inSourceFile = realpath($inSourceFile)) || !is_file($inSourceFile))
				throw new \RuntimeException(sprintf('`%1$s` is NOT a file.', $inSourceFile));

			include_once $inSourceFile; // Include the class file now; this should give us the class.
			if(!class_exists($inClassName)) // Throw exception if unable to acquire class to build tests for.
				throw new \RuntimeException(sprintf('Could NOT find class `%1$s` in `%2$s`.', $inClassName, $inSourceFile));
		}
		if(!$outClassName) $outClassName = $inClassName.'Test'; // e.g. `classTest`.
		if(!$outSourceFile) // Create test files in a `/.~unit-tests` sub-directory; default behavior.
			$outSourceFile = dirname($inSourceFile).DIRECTORY_SEPARATOR.'.~unit-tests'.DIRECTORY_SEPARATOR.$outClassName.'.php';
		if(!is_dir(dirname($outSourceFile))) mkdir(dirname($outSourceFile), 0775, TRUE);

		parent::__construct($inClassName, $inSourceFile, $outClassName, $outSourceFile);
	}

	/**
	 * Test Generator (enhanced by WebSharks™).
	 *
	 * @return string Returns test class template file contents.
	 *
	 * @throws \RuntimeException Throws exceptions on various types of failure.
	 */
	public function generate() // WebSharks™ removed the ``$verbose`` argument here.
	{
		$class   = new \ReflectionClass($this->inClassName['fullyQualifiedClassName']);
		$methods = $incompleteMethods = ''; // Initialize these class template variable values.

		$regexAnnotations = '/@assert(?:\-(?P<note>[a-z0-9_\-]+))*[ \t]+'. // @assert-with-possible_note additives.
		                    '(?P<preface>(?:[^\(][^'."\r\n".']+['."\r\n".']+[ \t]*\*[ \t]+(?![@\(]))*)?'. // Optional preface.
		                    '(?P<test>[^'."\r\n".']+)/'; // The actual assertion test — e.g. (something) === 'something'.

		$regexAssertionTest = '/^\((?P<arguments>.*)\)[ \t]+(?P<assertion>[^\s]*)[ \t]+(?P<expected>.*)$/';

		foreach($class->getMethods() as $_method) // Loop through all methods in this class.
		{
			// We skip over the class constructor; abstract methods (definitions only); and protected/private methods too.
			// Also skip any methods NOT actually declared in this class (e.g. we exclude any inherited methods this class may have).

			if(!$_method->isConstructor() && !$_method->isAbstract() && $_method->isPublic() && $_method->getDeclaringClass()->getName() === ltrim($this->inClassName['fullyQualifiedClassName'], '\\'))
			{
				$_regexAnnotationsFound = FALSE; // Initialize this to a FALSE value before each iteration here.

				if(preg_match_all($regexAnnotations, $_method->getDocComment(), $_annotations, PREG_SET_ORDER))
				{
					foreach($_annotations as $_annotation) // Now let's go through each of the annotations.
					{
						$_annotationNote = trim($_annotation['note']); // @assert-with-possible_note additives.
						$_prefaceLines   = preg_split("/[\r\n]+/", trim($_annotation['preface']), -1, PREG_SPLIT_NO_EMPTY); // Preface lines.
						$_annotationTest = trim($_annotation['test']); // The actual assertion test now.

						foreach($_prefaceLines as &$_prefaceLine)
							$_prefaceLine = trim($_prefaceLine, " \t\n\r\0\x0B*");
						$_annotationPreface = trim(implode("\n\t\t\t\t", $_prefaceLines));
						$_annotationPreface .= ($_annotationPreface) ? "\n\n\t\t\t\t" : '';

						if(preg_match($regexAssertionTest, $_annotationTest, $_assertionTest))
						{
							$_assertionTestArguments = trim($_assertionTest['arguments']);
							$_assertionTestAssertion = trim($_assertionTest['assertion']);
							$_assertionTestExpected  = trim($_assertionTest['expected']);

							// Map test type operators/symbols to PHPUnit assertions.

							switch($_assertionTestAssertion)
							{
								case '==':
									$_assertionTestAssertion = 'Equals';
									break;
								case '!=':
									$_assertionTestAssertion = 'NotEquals';
									break;
								case '===':
									$_assertionTestAssertion = 'Same';
									break;
								case '!==':
									$_assertionTestAssertion = 'NotSame';
									break;
								case '>':
									$_assertionTestAssertion = 'GreaterThan';
									break;
								case '>=':
									$_assertionTestAssertion = 'GreaterThanOrEqual';
									break;
								case '<':
									$_assertionTestAssertion = 'LessThan';
									break;
								case '<=':
									$_assertionTestAssertion = 'LessThanOrEqual';
									break;

								case 'throws':
									$_assertionTestAssertion = 'Exception';
									break;

								case 'empty':
									$_assertionTestAssertion = 'Empty';
									break;
								case '!empty':
									$_assertionTestAssertion = 'NotEmpty';
									break;

								case 'instanceof':
									$_assertionTestAssertion = 'InstanceOf';
									break;
								case '!instanceof':
									$_assertionTestAssertion = 'NotInstanceOf';
									break;

								case 'contains-value':
									$_assertionTestAssertion = 'Contains';
									break;
								case '!contains-value':
									$_assertionTestAssertion = 'NotContains';
									break;

								case 'contains-key':
									$_assertionTestAssertion = 'ArrayHasKey';
									break;
								case '!contains-key':
									$_assertionTestAssertion = 'ArrayNotHasKey';
									break;
								case 'contains-property':
									$_assertionTestAssertion = 'ObjectHasAttribute';
									break;
								case '!contains-property':
									$_assertionTestAssertion = 'ObjectNotHasAttribute';
									break;

								case 'is-type':
									$_assertionTestAssertion = 'InternalType';
									break;
								case '!is-type':
									$_assertionTestAssertion = 'NotInternalType';
									break;
								case 'contains-only-type':
									$_assertionTestAssertion = 'ContainsOnly';
									break;
								case '!contains-only-type':
									$_assertionTestAssertion = 'NotContainsOnly';
									break;

								case 'count':
									$_assertionTestAssertion = 'Count';
									break;
								case '!count':
									$_assertionTestAssertion = 'NotCount';
									break;

								case 'file-exists':
									$_assertionTestAssertion = 'FileExists';
									break;
								case '!file-exists':
									$_assertionTestAssertion = 'FileNotExists';
									break;

								case 'matches':
									$_assertionTestAssertion = 'RegExp';
									break;
								case '!matches':
									$_assertionTestAssertion = 'NotRegExp';
									break;

								default:
									// Else we thrown an exception here.
									throw new \RuntimeException('Assertion could NOT be parsed from @assert tag.'.
									                            sprintf(' Got unknown test type: `%1$s`.', $_assertionTestAssertion));
							}
							// Determine template type.

							if($_assertionTestAssertion === 'Exception')
								$_template = 'TestMethodException'; // Exception template.

							else if(in_array($_assertionTestAssertion, array('Empty', 'NotEmpty', 'FileExists', 'FileNotExists'), TRUE))
								$_template = 'TestMethodBool'; // Use 1 argument only (i.e. looking for a boolean response).

							else $_template = 'TestMethod'; // Otherwise, our default template will work just fine.

							$_template .= ($_method->isStatic()) ? 'Static' : ''; // A static method?

							// Assertion type conversions (to reduce the number of templates we need).

							if($_assertionTestAssertion === 'Empty' && strtoupper($_assertionTestExpected) == 'FALSE')
								$_assertionTestAssertion = 'NotEmpty'; // Convert to a TRUE boolean response.

							else if($_assertionTestAssertion === 'NotEmpty' && strtoupper($_assertionTestExpected) == 'FALSE')
								$_assertionTestAssertion = 'Empty'; // Convert to a TRUE boolean response.

							else if($_assertionTestAssertion === 'FileExists' && strtoupper($_assertionTestExpected) == 'FALSE')
								$_assertionTestAssertion = 'FileNotExists'; // Convert to a TRUE boolean response.

							else if($_assertionTestAssertion === 'FileNotExists' && strtoupper($_assertionTestExpected) == 'FALSE')
								$_assertionTestAssertion = 'FileExists'; // Convert to a TRUE boolean response.

							// Determine test method name.

							$_methodName = ucfirst($_origMethodName = $_method->getName());

							if(isset($this->methodNameCounter[$_methodName]))
								$this->methodNameCounter[$_methodName]++;
							else $this->methodNameCounter[$_methodName] = 1;

							if($this->methodNameCounter[$_methodName] > 1)
								$_methodName .= $this->methodNameCounter[$_methodName];

							// Construct test method from template file now.

							$_methodTemplate = new \Text_Template(sprintf('%s%sTemplate%s%s.tpl', dirname(__FILE__), DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR, $_template));
							$_methodTemplate->setVar(array('preface'        => $_annotationPreface,
							                               'arguments'      => $_assertionTestArguments,
							                               'assertion'      => $_assertionTestAssertion,
							                               'expected'       => $_assertionTestExpected,
							                               'className'      => $this->inClassName['className'],
							                               'origMethodName' => $_origMethodName,
							                               'methodName'     => $_methodName));
							$methods .= $_methodTemplate->render();

							$_regexAnnotationsFound = TRUE; // Flag as true here.
						}
					}
					unset($__annotations, $_annotation, $_annotationNote, $_prefaceLines, $_prefaceLine, $_annotationPreface, $_annotationTest);
					unset($_assertionTest, $_assertionTestArguments, $_assertionTestAssertion, $_assertionTestExpected);
					unset($_template, $_methodTemplate, $_origMethodName, $_methodName);
				}
				if(!$_regexAnnotationsFound) // Skeleton for incomplete tests (i.e. methods with no assertions).
				{
					$_methodTemplate = new \Text_Template(sprintf('%s%sTemplate%sIncompleteTestMethod.tpl', dirname(__FILE__), DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR));
					$_methodTemplate->setVar(array('className'      => $this->inClassName['className'],
					                               'methodName'     => ucfirst($_method->getName()),
					                               'origMethodName' => $_method->getName()));
					$incompleteMethods .= $_methodTemplate->render();
					unset($_methodTemplate); // Housekeeping.
				}
			}
		}
		unset($_method, $_regexAnnotationsFound); // Housekeeping.

		$classTemplate = new \Text_Template(sprintf('%s%sTemplate%sTestClass.tpl', dirname(__FILE__), DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR));

		if($this->outClassName['namespace'])
			$namespace_declaration = "\n".'namespace '.ltrim($this->outClassName['namespace'], '\\').';'."\n";
		else $namespace_declaration = ''; // No namespace need in the output file.

		if(preg_match('/@assert[ \t]+\((?P<arguments>.*)\)/', $class->getDocComment(), $_mConstructorArgs))
			$constructorArgs = $_mConstructorArgs['arguments'];
		else $constructorArgs = ''; // No constructor args.

		unset($_mConstructorArgs); // Housekeeping.

		$classTemplate->setVar(array('namespace_declaration' => $namespace_declaration,
		                             'className'             => $this->inClassName['className'],
		                             'testClassName'         => $this->outClassName['className'],
		                             'constructorArgs'       => $constructorArgs,
		                             'methods'               => rtrim($methods.$incompleteMethods),
		                             'date'                  => date('Y-m-d'),
		                             'time'                  => date('H:i:s'),
		                             'version'               => Version::id()));

		return $classTemplate->render(); // Return test class file contents.
	}
}