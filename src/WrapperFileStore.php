<?php
namespace dicr\file;

use League\Flysystem\NotSupportedException;
use yii\base\InvalidArgumentException;

/**
 * File store based on PHP Wrappers
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
 * @see http://php.net/manual/en/wrappers.php
 */
class WrapperFileStore extends LocalFileStore {
{
    public function init() {
        parent::init();

        if (!empty($this->contextOptions)) {
            $this->context = stream_context_create($this->contextOptions);
        }
    }

    public function setPath(string $path) {
        $path = rtrim($path, $this->pathSeparator);
        if ($path === '') {
            throw new InvalidArgumentException('path');
        }

        $this->_path = $path;
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFileStore::list()
     */
    public function list(string $path, array $options = [])
    {
        throw new NotSupportedException('list: ' . $path);
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFileStore::isExists()
     */
    public function isExists(string $path) {
        $path = $this->normalizeRelativePath($path);
        if ($path == '') {
            return true;
        }

        return @file_exists($this->getFullPath($path));
    }

    public function getType(string $path) {

    }
}