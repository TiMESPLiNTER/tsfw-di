<?php

namespace timesplinter\tsfw\di\common;

/**
 * @author Pascal Muenst <dev@timesplinter.ch>
 * @copyright Copyright (c) 2015, TiMESPLiNTER Webdevelopment
 */
class Container
{
	protected $autoWiringSupport = false;
	protected $annotationSupport = false;
	
	protected $dependencies = array();
	protected $instances = array();
	
	protected $needsRebuild = true;

	/**
	 * Adds a new class as a dependency to this container
	 * 
	 * @param string $className
	 * @param array $manualInjections
	 */
	public function add($className, array $manualInjections = array())
	{
		$this->dependencies[$className] = array(
			'to_resolve' => array(
				'manual' => $manualInjections,
				'auto_wiring' => array(),
				'annotations' => array()
			),
			
			'ref' => new \ReflectionClass($className)
		);

		$this->needsRebuild = true;
	}

	/**
	 * Removes a specific class from this container
	 * 
	 * @param string $className
	 */
	public function remove($className)
	{
		if(isset($this->dependencies[$className]) === false)
			return;
		
		unset($this->dependencies[$className]);
			
		$this->needsRebuild = true;
	}

	/**
	 * Returns an instance of the requested class
	 * 
	 * @param string $className
	 *
	 * @return object The requested class instance
	 * 
	 * @throws ContainerException
	 */
	public function get($className)
	{
		if(isset($this->instances[$className]) === false)
			throw new ContainerException('No such dependency to get');
		
		return $this->instances[$className];
	}

	/**
	 * Builds the container and its dependency instances
	 * 
	 * @throws ContainerException
	 */
	public function build()
	{
		if($this->needsRebuild === false)
			return;
		
		// Clean up previous builds of the container
		$this->instances = array();
		
		foreach($this->dependencies as $className => $info) {
			$this->analyzeDependencies($className);
			$this->mergeDependencyInfo($className);
		}
		
		$dependenciesCount = count($this->dependencies);
		$prevInstanceCount = -1;
		$timesEqual = 0;
		
		while($dependenciesCount > ($currentInstancesCount = count($this->instances))) {
			if($prevInstanceCount === $currentInstancesCount) {
				++$timesEqual;
				
				if($timesEqual > $dependenciesCount) {
					$missingDependencies = array_diff(array_keys($this->dependencies), array_keys($this->instances));
					throw new ContainerException('Can not instantiate some dependencies (missing ' . count($missingDependencies) . ').');
				}
			} else {
				$timesEqual = 0;
				$prevInstanceCount = $currentInstancesCount;
			}
			
			foreach($this->dependencies as $className => $info) {
				if(($dependencyInstance = $this->tryToInstantiate($className)) === null) {
					continue;
				}
				
				$this->instances[$className] = $dependencyInstance;
			}
		}
		
		$this->needsRebuild = false;
	}

	/**
	 * Tries to instantiate the given class by using the available information and instances in this container
	 * 
	 * @param string $className
	 *
	 * @return object|null Returns an instance of the class or null if instantiation wasn't possible
	 * 
	 * @throws ContainerException
	 */
	protected function tryToInstantiate($className)
	{
		$args = array();
		
		foreach($this->dependencies[$className]['resolved'] as $paramNo => $paramInfo) {
			if(is_string($paramInfo) === false) {
				$args[$paramNo] = $paramInfo;
				continue;
			}
			
			if(class_exists($paramInfo, false) === true) {
				if($paramInfo === $className) {
					throw new ContainerException('Can not instantiate the dependency ' . $className . ' with itself as a parameter');
				}

				if(isset($this->instances[$paramInfo]) === false) {
					return null;
				} else {
					$args[$paramNo] = $this->instances[$paramInfo];
				}
			} elseif(interface_exists($paramInfo, false) === true) {
				$implementationsAvailable = 0;
				
				if($this->autoWiringSupport === true) {
					$implementations = $this->resolveInterfaceToImplementations($paramInfo);
					$implementationsAvailable = count($implementations);

					if($implementationsAvailable === 1) {
						$implClassName = $implementations[0];
						
						if(isset($this->instances[$implClassName]) === true) {
							$args[$paramNo] = $this->instances[$implClassName];
						} else {
							$this->dependencies[$className]['resolved'][$paramNo] = $implClassName;

							return null;
						}
					}
				}
				
				if($implementationsAvailable === 0) {
					throw new ContainerException('There is no concrete implementation for the interface ' . $paramInfo . ' used by the dependency ' . $className . ' (argument ' . (count($args) + 1) . ')');
				} elseif($implementationsAvailable > 1) {
					unset($args[$paramNo]);
					throw new ContainerException('There are multiple implementations for the interface ' . $paramInfo . ' used by the dependency ' . $className . ' (argument ' . (count($args) + 1) . ')');
				}
			} else {
				$args[$paramNo] = $paramInfo;
			}
		}

		/** @var \ReflectionClass $reflectionClass */
		$reflectionClass = $this->dependencies[$className]['ref'];
		
		if(($constructor = $reflectionClass->getConstructor()) !== null && ($requiredParamCount = $constructor->getNumberOfRequiredParameters()) > ($effectiveParamCount = count($args))) {
			throw new ContainerException('Arguments do not match for dependency ' . $className . ' (' . $requiredParamCount . ' required, ' . $effectiveParamCount . ' given)');
		}
		
		try {
			$myClassInstance = $reflectionClass->newInstanceArgs($args);
		} catch(\ReflectionException $e) {
			throw new ContainerException('The dependency ' . $className . ' does not have a (public) constructor');
		}
		
		return $myClassInstance;
	}

	/**
	 * Resolves an interface to corresponding implementations
	 * 
	 * @param string $interfaceName The interface name to look up
	 *
	 * @return array The available implementations in this container
	 */
	protected function resolveInterfaceToImplementations($interfaceName)
	{
		$implementations = array();
		
		foreach($this->dependencies as $className => $info) {
			/** @var \ReflectionClass $refClass */
			$refClass = $info['ref'];

			if($refClass->implementsInterface($interfaceName) === false)
				continue;

			$implementations[] = $className;
		}
		
		return $implementations;
	}

	/**
	 * @param string $className
	 */
	protected function analyzeDependencies($className)
	{
		if($this->autoWiringSupport === true)
			$this->dependencies[$className]['to_resolve']['auto_wiring'] = $this->getAutoWireInformation($className);
		
		if($this->annotationSupport === true)
			$this->dependencies[$className]['to_resolve']['annotations'] = $this->getAnnotationInformation($className);
	}

	/**
	 * @param string $className
	 */
	protected function mergeDependencyInfo($className)
	{
		$resolveInfo = $this->dependencies[$className]['to_resolve'];
		
		$cleanInfo = $resolveInfo['manual'] + $resolveInfo['annotations'] + $resolveInfo['auto_wiring'];

		if(ksort($cleanInfo) === false)
			return;
		
		$this->dependencies[$className]['resolved'] = $cleanInfo;
	}

	/**
	 * @param string $className
	 *
	 * @return array
	 */
	protected function getAnnotationInformation($className)
	{
		/** @var \ReflectionClass $reflectionClass */
		$reflectionClass = $this->dependencies[$className]['ref'];
		$paramInfo = array();
		
		foreach($reflectionClass->getProperties() as $property) {
			//$property->getDocComment()
		}

		return $paramInfo;
	}

	/**
	 * Collect data about the arguments of the constructor for the given class
	 * 
	 * @param string $className
	 *
	 * @return array
	 */
	public function getAutoWireInformation($className)
	{
		/** @var \ReflectionClass $reflectionClass */
		$reflectionClass = $this->dependencies[$className]['ref'];
		$paramInfo = array();		
		
		if(($constructor = $reflectionClass->getConstructor()) === null)
			return array();
		
		foreach($constructor->getParameters() as $param)
		{
			$exportedParams = \ReflectionParameter::export(
				array(
					$param->getDeclaringClass()->name,
					$param->getDeclaringFunction()->name
				),
				$param->name,
				true
			);

			if(preg_match('/([\w\\\\]+)\s+\$' . $param->name . '/', $exportedParams, $matches) === 0)
				$paramInfo[] = null;
			else
				$paramInfo[] = $matches[1];
		}
		
		return $paramInfo;
	}
	
	/**
	 * @return boolean
	 */
	public function hasAutoWiringSupport()
	{
		return $this->autoWiringSupport;
	}

	/**
	 * Enables/disables auto wiring
	 * 
	 * @param boolean $autoWiringSupport
	 */
	public function setAutoWiringSupport($autoWiringSupport)
	{
		$this->autoWiringSupport = $autoWiringSupport;
	}

	/**
	 * @return boolean
	 */
	public function hasAnnotationSupport()
	{
		return $this->annotationSupport;
	}

	/**
	 * Enables/disables support for annotation injection
	 * 
	 * @param boolean $annotationSupport
	 */
	public function setAnnotationSupport($annotationSupport)
	{
		$this->annotationSupport = $annotationSupport;
	}

	/**
	 * @return bool
	 */
	public function needsRebuild()
	{
		return $this->needsRebuild;
	}
}

/* EOF */