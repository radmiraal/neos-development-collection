<?php
namespace TYPO3\TypoScript\Core;

/*                                                                        *
 * This script belongs to the TYPO3 Flow package "TypoScript".            *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU General Public License, either version 3 of the   *
 * License, or (at your option) any later version.                        *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Utility\Arrays;
use TYPO3\Flow\Reflection\ObjectAccess;

/**
 * TypoScript Runtime
 *
 * TypoScript Rendering Process
 * ============================
 *
 * During rendering, all TypoScript objects form a tree.
 *
 * When a TypoScript object at a certain $typoScriptPath is invoked, it has
 * access to all variables stored in the $context (which is an array).
 *
 * The TypoScript object can then add or replace variables to this context using pushContext()
 * or pushContextArray(), before rendering sub-TypoScript objects. After rendering
 * these, it must call popContext() to reset the context to the last state.
 */
class Runtime {

	/**
	 * Internal constants defining how evaluateInternal should work in case of an error.
	 */
	const BEHAVIOR_EXCEPTION = 'Exception';
	const BEHAVIOR_RETURNNULL = 'NULL';

	/**
	 * @var \TYPO3\Eel\CompilingEvaluator
	 * @Flow\Inject
	 */
	protected $eelEvaluator;

	/**
	 * Contains list of contexts
	 * @var array
	 */
	protected $renderingStack = array();

	/**
	 * Default context with helper definitions
	 * @var array
	 */
	protected $defaultContextVariables;

	/**
	 * @var array
	 */
	protected $typoScriptConfiguration;

	/**
	 * @var \TYPO3\Flow\Mvc\Controller\ControllerContext
	 */
	protected $controllerContext;

	/**
	 * @var array
	 */
	protected $settings;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\Flow\Log\SystemLoggerInterface
	 */
	protected $systemLogger;

	/**
	 * Constructor for the TypoScript Runtime
	 * @param array $typoScriptConfiguration
	 * @param \TYPO3\Flow\Mvc\Controller\ControllerContext $controllerContext
	 */
	public function __construct(array $typoScriptConfiguration, \TYPO3\Flow\Mvc\Controller\ControllerContext $controllerContext) {
		$this->typoScriptConfiguration = $typoScriptConfiguration;
		$this->controllerContext = $controllerContext;
	}

	/**
	 * Inject settings of this package
	 *
	 * @param array $settings The settings
	 * @return void
	 */
	public function injectSettings(array $settings) {
		$this->settings = $settings;
	}

	/**
	 * Completely replace the context array with the new $contextArray.
	 *
	 * Purely internal method, should not be called outside of TYPO3.TypoScript.
	 *
	 * @param array $contextArray
	 * @return void
	 */
	public function pushContextArray(array $contextArray) {
		$this->renderingStack[] = $contextArray;
	}

	/**
	 * Push a new context object to the rendering stack
	 *
	 * @param string $key the key inside the context
	 * @param mixed $context
	 * @return void
	 */
	public function pushContext($key, $context) {
		$newContext = $this->getCurrentContext();
		$newContext[$key] = $context;
		$this->renderingStack[] = $newContext;
	}

	/**
	 * Remove the topmost context objects and return them
	 *
	 * @return array the topmost context objects as associative array
	 */
	public function popContext() {
		return array_pop($this->renderingStack);
	}

	/**
	 * Get the current context array
	 *
	 * @return array the array of current context objects
	 */
	public function getCurrentContext() {
		return $this->renderingStack[count($this->renderingStack) - 1];
	}

	/**
	 * Evaluate an absolute TypoScript path and return the result
	 *
	 * @param string $typoScriptPath
	 * @return mixed the result of the evaluation, can be a string but also other data types
	 */
	public function evaluate($typoScriptPath) {
		return $this->evaluateInternal($typoScriptPath, self::BEHAVIOR_RETURNNULL);
	}

	/**
	 * Render an absolute TypoScript path and return the result.
	 *
	 * Compared to $this->evaluate, this adds some more comments helpful for debugging.
	 *
	 * @param string $typoScriptPath
	 * @throws \Exception
	 * @throws \TYPO3\Flow\Configuration\Exception\InvalidConfigurationException
	 * @return string
	 */
	public function render($typoScriptPath) {
		try {
			$output = $this->evaluateInternal($typoScriptPath, self::BEHAVIOR_EXCEPTION);

			if (isset($this->settings['debugMode']) && $this->settings['debugMode'] === TRUE) {
				$output = sprintf('%1$s<!-- Beginning to render TS path "%2$s" (Context: %3$s) -->%4$s%1$s<!-- End to render TS path "%2$s" (Context: %3$s) -->',
					chr(10),
					$typoScriptPath,
					implode(', ', array_keys($this->getCurrentContext())),
					$output
				);
			}
			if (is_string($output)) {
				$output = trim($output);
			}
			return $output;
		} catch (\Exception $exception) {
			return $this->handleRenderingException($typoScriptPath, $exception);
		}
	}

	/**
	 * Handle an Exception thrown while rendering TypoScript according to
	 * settings specified in TYPO3.TypoScript.handleRenderingExceptions
	 *
	 * @param array $typoScriptPath
	 * @param \Exception $exception
	 * @throws \TYPO3\Flow\Mvc\Exception\StopActionException
	 * @throws InvalidConfigurationException
	 * @throws \Exception|\TYPO3\Flow\Exception
	 * @return string
	 */
	public function handleRenderingException($typoScriptPath, $exception) {
		if ($exception instanceof \TYPO3\Flow\Mvc\Exception\StopActionException) {
			throw $exception;
		}
		if ($exception instanceof \TYPO3\TypoScript\Exception\RuntimeException) {
			$typoScriptPath = $exception->getTypoScriptPath();
			$exception = $exception->getPrevious();
		}
		$referenceCode = ($exception instanceof \TYPO3\Flow\Exception) ? $exception->getReferenceCode() : NULL;
		switch ($this->settings['handleRenderingExceptions']) {
			case 'throw':
				throw $exception;
			case 'htmlMessage':
				if (isset($referenceCode)) {
					$output = sprintf('<div class="neos-rendering-exception">Exception while rendering <div class="typoscript-path">%s:</div> <div class="exception-message">%s (%s)</div></div>', htmlspecialchars($typoScriptPath), $exception->getMessage(), $referenceCode);
				} else {
					$output = sprintf('<div class="neos-rendering-exception">Exception while rendering <div class="typoscript-path">%s:</div> <div class="exception-message">%s</div></div>', htmlspecialchars($typoScriptPath), $exception->getMessage());
				}
				break;
			case 'xmlComment':
				if (isset($referenceCode)) {
					$output = sprintf('<!-- Exception while rendering %s: %s (%s) -->', htmlspecialchars($typoScriptPath), $exception->getMessage(), $referenceCode);
				} else {
					$output = sprintf('<!-- Exception while rendering %s -->', htmlspecialchars($typoScriptPath), $exception->getMessage());
				}
				break;
			case 'plainText':
				if (isset($referenceCode)) {
					$output = sprintf('Exception while rendering %s: %s (%s)', $typoScriptPath, $exception->getMessage(), $referenceCode);
				} else {
					$output = sprintf('Exception while rendering %s: %s', $typoScriptPath, $exception->getMessage());
				}
				break;
			case 'suppress':
				$output = '';
				break;
			default:
				throw new InvalidConfigurationException('The option "' . $this->settings['handleRenderingExceptions'] . '" is not valid for Setting TYPO3.TypoScript.handleRenderingExceptions. Please choose between throw, htmlMessage, plainText, xmlComment and suppress.', 1368788926);
		}
		$this->systemLogger->logException($exception);
		return $output;
	}

	/**
	 * Determine if the given TypoScript path is renderable, which means it exists
	 * and has an implementation.
	 *
	 * @param string $typoScriptPath
	 * @return boolean
	 */
	public function canRender($typoScriptPath) {
		$typoScriptConfiguration = $this->getConfigurationForPath($typoScriptPath);

		return $this->canRenderWithConfiguration($typoScriptConfiguration);
	}

	/**
	 * Internal evaluation if given configuration is renderable.
	 *
	 * @param array $typoScriptConfiguration
	 * @return boolean
	 */
	protected function canRenderWithConfiguration(array $typoScriptConfiguration) {
			// DEPRECATED implementationClassName is deprecated since Sprint 10, use @class instead
		if (!(isset($typoScriptConfiguration['__meta']['class']) || isset($typoScriptConfiguration['implementationClassName'])) || !isset($typoScriptConfiguration['__objectType'])) {
			return FALSE;
		}

		return TRUE;
	}

	/**
	 * Internal evaluation method of absolute $typoScriptpath
	 *
	 * @param string $typoScriptPath
	 * @param string $behaviorIfPathNotFound one of BEHAVIOR_EXCEPTION or BEHAVIOR_RETURNNULL
	 * @return mixed
	 */
	protected function evaluateInternal($typoScriptPath, $behaviorIfPathNotFound) {
		$typoScriptConfiguration = $this->getConfigurationForPath($typoScriptPath);

		if (!$this->canRenderWithConfiguration($typoScriptConfiguration)) {
			if ($behaviorIfPathNotFound === self::BEHAVIOR_EXCEPTION) {
				if (!isset($typoScriptConfiguration['__objectType'])) {
					throw new \TYPO3\TypoScript\Exception\MissingTypoScriptObjectException('No "' . $typoScriptPath . '" TypoScript object found. Please make sure to define one in your TypoScript configuration.', 1332493990);
				} else {
					throw new \TYPO3\TypoScript\Exception\MissingTypoScriptImplementationException('The TypoScript object at path "' . $typoScriptPath . '" could not be rendered: Missing implementation class name for "' . $typoScriptConfiguration['__objectType'] . '". Add @class in your TypoScript configuration.', 1332493995);
				}
			} else {
				return NULL;
			}
		}
		$typoScriptObjectType = $typoScriptConfiguration['__objectType'];

			// DEPRECATED implementationClassName is deprecated since Sprint 10, use @class instead
		$tsObjectClassName = isset($typoScriptConfiguration['__meta']['class']) ? $typoScriptConfiguration['__meta']['class'] : $typoScriptConfiguration['implementationClassName'];

		if (!preg_match('#<[^>]*>$#', $typoScriptPath)) {
				// Only add typoscript object type to last path part if not already set
			$typoScriptPath .= '<' . $typoScriptObjectType . '>';
		}
		if (!class_exists($tsObjectClassName)) {
			throw new \TYPO3\TypoScript\Exception(sprintf('The implementation class "%s" defined for TypoScript object of type "%s" does not exist (defined at %s).', $tsObjectClassName, $typoScriptObjectType, $typoScriptPath), 1347952109);
		}
		$tsObject = new $tsObjectClassName($this, $typoScriptPath, $typoScriptObjectType);
		$this->setOptionsOnTsObject($tsObject, $typoScriptConfiguration);

			// modify context if @override is specified
		if (isset($typoScriptConfiguration['__meta']['override'])) {
			$contextArray = $this->getCurrentContext();
			foreach ($typoScriptConfiguration['__meta']['override'] as $overrideKey => $overrideValue) {
				$contextArray[$overrideKey] = $this->evaluateProcessor('@override.' . $overrideKey, $tsObject, $overrideValue);
			}
			$this->pushContextArray($contextArray);
		}

		$processorsForTypoScriptObject = $this->getProcessors($tsObject->getInternalProcessors(), '__all');

		foreach ($processorsForTypoScriptObject as $processor) {
			if ($processor instanceof \TYPO3\TypoScript\RuntimeAwareProcessorInterface) {
				$processor->beforeInvocation($this, $tsObject, $typoScriptPath);
			}
		}

		try {
			$output = $tsObject->evaluate();
		} catch (\TYPO3\Flow\Mvc\Exception\StopActionException $stopActionException) {
			throw $stopActionException;
		} catch (\TYPO3\TypoScript\Exception\RuntimeException $runtimeException) {
			throw $runtimeException;
		} catch (\Exception $exception) {
			throw new \TYPO3\TypoScript\Exception\RuntimeException('An exception occurred while rendering "' . $typoScriptPath . '". Please see the nested exception for details.', 1368517488, $exception, $typoScriptPath);
		}

		foreach ($processorsForTypoScriptObject as $processor) {
			$output = $processor->process($output);
		}

		foreach ($processorsForTypoScriptObject as $processor) {
			if ($processor instanceof \TYPO3\TypoScript\RuntimeAwareProcessorInterface) {
				$processor->afterInvocation($this, $tsObject, $typoScriptPath);
			}
		}

		if (isset($typoScriptConfiguration['__meta']['override'])) {
			$this->popContext();
		}

		return $output;
	}

	/**
	 * Get the TypoScript Configuration for the given TypoScript path
	 *
	 * @param string $typoScriptPath
	 * @return array
	 */
	protected function getConfigurationForPath($typoScriptPath) {
		$pathParts = explode('/', $typoScriptPath);

		$configuration = $this->typoScriptConfiguration;

		$pathUntilNow = '';
		if (isset($configuration['__prototypes'])) {
			$currentPrototypeDefinitions = $configuration['__prototypes'];
		} else {
			$currentPrototypeDefinitions = array();
		}

		foreach ($pathParts as $pathPart) {
			$pathUntilNow .= '/' . $pathPart;
			if (preg_match('#^([^<]*)(<(.*?)>)?$#', $pathPart, $matches)) {
				$currentPathSegment = $matches[1];

				if (isset($configuration[$currentPathSegment]) && is_array($configuration[$currentPathSegment])) {
					$configuration = $configuration[$currentPathSegment];
				} else {
					$configuration = array();
				}

				if (isset($configuration['__prototypes'])) {
					$currentPrototypeDefinitions = Arrays::arrayMergeRecursiveOverrule($currentPrototypeDefinitions, $configuration['__prototypes']);
				}

				if (isset($matches[3])) {
					$currentPathSegmentType = $matches[3];
				} elseif (isset($configuration['__objectType'])) {
					$currentPathSegmentType = $configuration['__objectType'];
				} else {
					$currentPathSegmentType = NULL;
				}

				if ($currentPathSegmentType !== NULL) {
					if (isset($currentPrototypeDefinitions[$currentPathSegmentType])) {

							// FIRST, we build up the prototype inheritance hierarchy by
							// walking up the inheritance tree. The $prototypeInheritanceHierarchy
							// is sorted from the most generic to the most specific type.
						$prototypeInheritanceHierarchy = array($currentPathSegmentType);

							// TODO PERFORMANCE: the inheritance hierarchy is *fixed* and could also be calculated *once* for a given
							// TypoScript; maybe even at the end of the parsing step. This is because we only allow top-
							// level prototype inheritance of the form "prototype(foo) < prototype(bar)", but NOT
							// "some.thing.prototype(foo) < prototype(bar)".
						$currentPrototypeName = $currentPathSegmentType;
						while (isset($currentPrototypeDefinitions[$currentPrototypeName]['__prototypeObjectName'])) {
							$currentPrototypeName = $currentPrototypeDefinitions[$currentPrototypeName]['__prototypeObjectName'];
							array_unshift($prototypeInheritanceHierarchy, $currentPrototypeName);
						}

							// SECOND, we now FLATTEN the prototype inheritance hierarchy, effectively building an
							// array with the indirections through the prototype inheritance resolved.
						$flattenedPrototype = array();
						foreach ($prototypeInheritanceHierarchy as $singlePrototypeName) {
							if (isset($currentPrototypeDefinitions[$singlePrototypeName])) {
								$flattenedPrototype = Arrays::arrayMergeRecursiveOverrule($flattenedPrototype, $currentPrototypeDefinitions[$singlePrototypeName]);
							}
						}

							// THIRD, we merge the flattened prototype with the current configuration (in that order),
							// to make sure that the current configuration (not being defined in the prototype) wins.
						$configuration = Arrays::arrayMergeRecursiveOverrule($flattenedPrototype, $configuration);

							// FOURTH, if context-dependent prototypes are set (such as prototype("foo").prototype("baz")),
							// we update the current prototype definitions.
							// This also takes care of inheritance, as we use the $flattenedPrototype as basis (TODO TESTCASE)
						if (isset($flattenedPrototype['__prototypes'])) {
							$currentPrototypeDefinitions = Arrays::arrayMergeRecursiveOverrule($currentPrototypeDefinitions, $flattenedPrototype['__prototypes']);
						}
					}

					$configuration['__objectType'] = $currentPathSegmentType;
				}

			} else {
				throw new \TYPO3\TypoScript\Exception('Path Part ' . $pathPart . ' not well-formed', 1332494645);
			}
		}

		return $configuration;
	}

	/**
	 * Set options on the given TypoScript obect
	 *
	 * @param \TYPO3\TypoScript\TypoScriptObjects\AbstractTypoScriptObject $tsObject
	 * @param string $typoScriptConfiguration
	 */
	protected function setOptionsOnTsObject(\TYPO3\TypoScript\TypoScriptObjects\AbstractTypoScriptObject $tsObject, array $typoScriptConfiguration) {
		foreach ($typoScriptConfiguration as $key => $value) {
				// DEPRECATED implementationClassName is deprecated since Sprint 10, use @class instead
			if ($key === '@class' || $key === 'implementationClassName') {
					// The @class property is already handled by the TypoScript runtime
				continue;
			}
			if ($key === '__processors') {
				$tsObject->setInternalProcessors($value);
			}

				// skip keys which start with __, as they are purely internal.
			if ($key[0] === '_' && $key[1] === '_') continue;
			ObjectAccess::setProperty($tsObject, $key, $value);
		}
	}

	/**
	 * Evaluate the processors for $variableName
	 *
	 * @param string $variableName
	 * @param \TYPO3\TypoScript\TypoScriptObjects\AbstractTypoScriptObject $tsObject
	 * @param mixed $value
	 * @return mixed
	 */
	public function evaluateProcessor($variableName, \TYPO3\TypoScript\TypoScriptObjects\AbstractTypoScriptObject $tsObject, $value) {
		if (is_array($value) && isset($value['__eelExpression'])) {
			$contextVariables = array_merge($this->getDefaultContextVariables(), $this->getCurrentContext());

			if (!isset($contextVariables['context']) && isset($contextVariables['node'])) {
					// DEPRECATED since sprint release 10; should be removed lateron.
				$contextVariables['context'] = new \TYPO3\Eel\FlowQuery\FlowQuery(array($contextVariables['node']));
			}
			if (isset($contextVariables['q'])) {
				throw new \TYPO3\TypoScript\Exception('Context variable "q" not allowed, as it is already reserved for FlowQuery use.', 1344325040);
			}
			$contextVariables['q'] = function($element) {
				return new \TYPO3\Eel\FlowQuery\FlowQuery(array($element));
			};
			if (isset($contextVariables['this'])) {
				throw new \TYPO3\TypoScript\Exception('Context variable "this" not allowed, as it is already reserved for a pointer to the current TypoScript object.', 1344325044);
			}
			$contextVariables['this'] = $tsObject;

			$context = new \TYPO3\Eel\ProtectedContext($contextVariables);
			$context->whitelist('q');
			$value = $this->eelEvaluator->evaluate($value['__eelExpression'], $context);
		}

		$processors = $this->getProcessors($tsObject->getInternalProcessors(), $variableName);
		foreach ($processors as $processor) {
			$value = $processor->process($value);
		}

		return $value;
	}

	/**
	 * Instantiate and return all processors for a given $processorConfiguration and $propertyName,
	 * in the right ordering.
	 *
	 * @param array $processorConfiguration
	 * @param string $propertyName
	 * @return array<\TYPO3\TypoScript\ProcessorInterface> the fully initialized processors, ready for further use.
	 */
	protected function getProcessors(array $processorConfiguration, $propertyName) {
		$processors = array();
		if (!isset($processorConfiguration[$propertyName])) {
			return array();
		}
		ksort($processorConfiguration[$propertyName]);
		foreach ($processorConfiguration[$propertyName] as $singleProcessorConfiguration) {
			$processorClassName = $singleProcessorConfiguration['__processorClassName'];
			$processor = new $processorClassName();
			unset($singleProcessorConfiguration['__processorClassName']);

			foreach ($singleProcessorConfiguration as $propertyName => $propertyValue) {
				if (!ObjectAccess::setProperty($processor, $propertyName, $propertyValue)) {
					throw new \TYPO3\TypoScript\Exception(sprintf('Property "%s" could not be set on processor "%s".', $propertyName, $processorClassName), 1332493740);
				}
			}

			$processors[] = $processor;
		}
		return $processors;
	}

	/**
	 * @return \TYPO3\Flow\Mvc\Controller\ControllerContext
	 */
	public function getControllerContext() {
		return $this->controllerContext;
	}

	/**
	 * @return array
	 */
	protected function getDefaultContextVariables() {
		if ($this->defaultContextVariables === NULL) {
			$this->defaultContextVariables = array();
			if (isset($this->settings['defaultContext']) && is_array($this->settings['defaultContext'])) {
				foreach ($this->settings['defaultContext'] as $variableName => $objectType) {
					$base = &$this->defaultContextVariables;
					$variablePathNames = explode('.', $variableName);
					foreach ($variablePathNames as $pathName) {
						if (!isset($base[$pathName])) {
							$base[$pathName] = array();
						}
						$base = &$base[$pathName];
					}
					$base = new $objectType();
				}
			}

		}
		return $this->defaultContextVariables;
	}

}
?>