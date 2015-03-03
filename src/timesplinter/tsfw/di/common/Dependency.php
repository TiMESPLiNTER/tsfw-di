<?php

namespace timesplinter\tsfw\di\common;

/**
 * @author Pascal Muenst <dev@timesplinter.ch>
 * @copyright Copyright (c) 2015, TiMESPLiNTER Webdevelopment
 */
class Dependency
{
	protected $className;

	/**
	 * @param string $className
	 */
	public function __construct($className)
	{
		$this->className = $className;
	}

	/**
	 * @return string
	 */
	public function getClassName()
	{
		return $this->className;
	}
}

/* EOF */