<?php
/**
 * @author Florian Krämer
 * @copyright 2012 - 2015 Florian Krämer
 * @license MIT
 */
namespace Burzum\FileStorage\Storage\PathBuilder;

class LocalPathBuilder extends BasePathBuilder {

/**
 * Default settings.
 *
 * @var array
 */
	protected $_defaultConfig = array(
		'urlPrefix' => '/',
	);
}
