<?php
/**
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <icewind@owncloud.com>
 *
 * @copyright Copyright (c) 2015, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OC\Files\Cache;

use OC\Files\Filesystem;
use OC\Hooks\BasicEmitter;

/**
 * Propagates changes in etag and mtime up the filesystem tree
 *
 * @package OC\Files\Cache
 */
class ChangePropagator extends BasicEmitter {
	/**
	 * @var string[]
	 */
	protected $changedFiles = array();

	/**
	 * @var \OC\Files\View
	 */
	protected $view;

	/**
	 * @param \OC\Files\View $view
	 */
	public function __construct(\OC\Files\View $view) {
		$this->view = $view;
	}

	public function addChange($path) {
		$this->changedFiles[] = $path;
	}

	public function getChanges() {
		return $this->changedFiles;
	}

	/**
	 * propagate the registered changes to their parent folders
	 *
	 * @param int $time (optional) the mtime to set for the folders, if not set the current time is used
	 */
	public function propagateChanges($time = null) {
		$changes = $this->getChanges();
		$this->changedFiles = [];
		if (!$time) {
			$time = time();
		}
		foreach ($changes as $change) {
			/**
			 * @var \OC\Files\Storage\Storage $storage
			 * @var string $internalPath
			 */

			$absolutePath = $this->view->getAbsolutePath($change);
			$mount = $this->view->getMount($change);
			$storage = $mount->getStorage();
			$internalPath = $mount->getInternalPath($absolutePath);
			if ($storage) {
				$propagator = $storage->getPropagator();
				$propagatedEntries = $propagator->propagateChange($internalPath, $time);

				foreach ($propagatedEntries as $entry) {
					$absolutePath = Filesystem::normalizePath($mount->getMountPoint() . '/' . $entry['path']);
					$relativePath = $this->view->getRelativePath($absolutePath);
					$this->emit('\OC\Files', 'propagate', [$relativePath, $entry]);
				}
			}
		}
	}

	/**
	 * @return string[]
	 */
	public function getAllParents() {
		$parents = array();
		foreach ($this->getChanges() as $path) {
			$parents = array_values(array_unique(array_merge($parents, $this->getParents($path))));
		}
		return $parents;
	}

	/**
	 * get all parent folders of $path
	 *
	 * @param string $path
	 * @return string[]
	 */
	protected function getParents($path) {
		$parts = explode('/', $path);

		// remove the singe file
		array_pop($parts);
		$result = array('/');
		$resultPath = '';
		foreach ($parts as $part) {
			if ($part) {
				$resultPath .= '/' . $part;
				$result[] = $resultPath;
			}
		}
		return $result;
	}
}
