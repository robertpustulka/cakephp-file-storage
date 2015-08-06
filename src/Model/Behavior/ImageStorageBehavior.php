<?php
namespace Burzum\FileStorage\Model\Behavior;

use Burzum\FileStorage\Model\Behavior\Event\EventDispatcherTrait;
use Burzum\FileStorage\Storage\StorageTrait;
use Burzum\FileStorage\Storage\PathBuilder\PathBuilderTrait;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Event\Event;
use Cake\Event\EventDispatcherInterface;
use Cake\Log\LogTrait;
use Cake\ORM\Behavior;
use Cake\Validation\Validation;

/**
 * FileStorageTable
 *
 * @author Florian Krämer
 * @author Robert Pustułka
 * @copyright 2012 - 2015 Florian Krämer
 * @license MIT
 */
class ImageStorageBehavior extends Behavior implements EventDispatcherInterface {

	use EventDispatcherTrait;
	use LogTrait;
	use PathBuilderTrait;
	use StorageTrait;

/**
 *
 * @var array
 */
	protected $_defaultConfig = [
		'implementedMethods' => [
			'validateImageSize' => 'validateImageSize',
			'getImageVersions' => 'getImageVersions'
		]
	];

/**
 *
 * @param array $config
 * @return void
 */
	public function initialize(array $config) {
		$this->_eventManager = $this->_table->eventManager();

		parent::initialize($config);

		//remove FileStorageBehavior afterSave and afterDelete listeners to keep BC with overwrited callbacks in old tables.
		$fileStorageBehavior = $this->_table->behaviors()->get('FileStorage');
		if ($fileStorageBehavior) {
			$events = ['Model.afterSave', 'Model.afterDelete'];
			foreach ($events as $event) {
				$this->_eventManager->off($event, $fileStorageBehavior);
			}
		}
	}

/**
 * beforeSave callback
 *
 * @param \Cake\Event\Event $event
 * @param \Cake\Datasource\EntityInterface $entity
 * @param array $options
 * @return boolean true on success
 */
	public function beforeSave(Event $event, EntityInterface $entity, $options) {
		$imageEvent = $this->dispatchEvent('ImageStorage.beforeSave', [
			'record' => $entity
		]);
		if ($imageEvent->isStopped()) {
			return false;
		}
		return true;
	}

/**
 * afterSave callback
 *
 * Does not call the parent to avoid that the regular file storage event listener saves the image already
 *
 * @param \Cake\Event\Event $event
 * @param \Cake\Datasource\EntityInterface $entity
 * @param array $options
 * @return boolean
 */
	public function afterSave(Event $event, EntityInterface $entity, $options) {
		if ($entity->isNew()) {
			$this->dispatchEvent('ImageStorage.afterSave', [
				'record' => $entity,
				'storage' => $this->storageAdapter($entity->get('adapter'))
			]);
			$this->_table->deleteOldFileOnSave($entity);
		}
		return true;
	}

/**
 * Get a copy of the actual record before we delete it to have it present in afterDelete
 *
 * @param \Cake\Event\Event $event
 * @param \Cake\Datasource\EntityInterface $entity
 * @return boolean
 */
	public function beforeDelete(Event $event, EntityInterface $entity) {
		$imageEvent = $this->dispatchEvent('ImageStorage.beforeDelete', [
			'record' => $this->_table->record,
			'storage' => $this->storageAdapter($this->_table->record['adapter'])
		]);

		if ($imageEvent->isStopped()) {
			return false;
		}

		return true;
	}

/**
 * After the main file was deleted remove the the thumbnails
 *
 * Note that we do not call the parent::afterDelete(), we just want to trigger the ImageStorage.afterDelete event but not the FileStorage.afterDelete at the same time!
 *
 * @param \Cake\Event\Event $event
 * @param \Cake\Datasource\EntityInterface $entity
 * @param array $options
 * @return boolean
 */
	public function afterDelete(Event $event, EntityInterface $entity, $options) {
		$this->dispatchEvent('ImageStorage.afterDelete', [
			'record' => $entity,
			'storage' => $this->storageAdapter($entity->get('adapter'))
		]);
		return true;
	}

/**
 * Image size validation method
 *
 * @param mixed $check
 * @param array $options is an array with key width or height and a value of array
 *    with two options, operator and value. For example:
 *    array('height' => array('==', 100)) will only be true if the image has a
 *    height of exactly 100px. See the CakePHP core class and method
 *    Validation::comparison for all operators.
 * @return boolean true
 * @see Validation::comparison()
 * @throws \InvalidArgumentException
 */
	public function validateImageSize($check, array $options = []) {
		if (!isset($options['height']) && !isset($options['width'])) {
			throw new \InvalidArgumentException('Missing image size validation options! You must provide a hight and / or width.');
		}

		if (is_string($check)) {
			$imageFile = $check;
		} else {
			$check = array_values($check);
			$check = $check[0];
			if (is_array($check) && isset($check['tmp_name'])) {
				$imageFile = $check['tmp_name'];
			} else {
				$imageFile = $check;
			}
		}

		$imageSizes = $this->_table->getImageSize($imageFile);

		if (isset($options['height'])) {
			$height = Validation::comparison($imageSizes[1], $options['height'][0], $options['height'][1]);
		} else {
			$height = true;
		}

		if (isset($options['width'])) {
			$width = Validation::comparison($imageSizes[0], $options['width'][0], $options['width'][1]);
		} else {
			$width = true;
		}

		if ($height === false || $width === false) {
			return false;
		}

		return true;
	}

/**
 * Gets a list of image versions for a given record.
 *
 * Use this method to get a list of ALL versions when needed or to cache all the
 * versions somewhere. This method will return all configured versions for an
 * image. For example you could store them serialized along with the file data
 * by adding a "versions" field to the DB table and extend this model.
 *
 * Just in case you're wondering about the event name in the method code: It's
 * called FileStorage.ImageHelper.imagePath there because the event is the same
 * as in the helper. No need to introduce yet another event, the existing event
 * already fulfills the purpose. I might rename this event in the 3.0 version of
 * the plugin to a more generic one.
 *
 * @param \Cake\Datasource\EntityInterface $entity An ImageStorage database record
 * @param array $options Options for the version.
 * @return array A list of versions for this image file. Key is the version, value is the path or URL to that image.
 */
	public function getImageVersions(EntityInterface $entity, $options = []) {
		$versions = [];
		$versionData = (array)Configure::read('FileStorage.imageSizes.' . $entity->get('model'));
		$versionData['original'] = isset($options['originalVersion']) ? $options['originalVersion'] : 'original';
		foreach ($versionData as $version => $data) {
			$hash = Configure::read('FileStorage.imageHashes.' . $entity->get('model') . '.' . $version);
			$event = $this->dispatchEvent('ImageVersion.getVersions', [
				'hash' => $hash,
				'image' => $entity,
				'version' => $version,
				'options' => []
			]);
			if ($event->isStopped()) {
				$versions[$version] = str_replace('\\', '/', $event->data['path']);
			}
		}
		return $versions;
	}
}