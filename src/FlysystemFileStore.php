<?php
namespace dicr\file;

use League\Flysystem\AdapterInterface;
use League\Flysystem\Filesystem;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;

/**
 * FileStore based on FLYsystem adapters.
 *
 * @property-read \League\Flysystem\FilesystemInterface $flysystem
 * @property-read \League\Flysystem\AdapterInterface|null $adapter
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
 * @see http://flysystem.thephpleague.com/docs/
 */
class FlysystemFileStore extends AbstractFileStore
{
    const ACCESS_MAP = [
        File::ACCESS_PUBLIC => AdapterInterface::VISIBILITY_PUBLIC,
        File::ACCESS_PRIVATE => AdapterInterface::VISIBILITY_PRIVATE
    ];

    const TYPE_MAP = [
        File::TYPE_DIR => 'dir',
        File::TYPE_FILE => 'file'
    ];

    /** @var \League\Flysystem\Filesystem|callable */
    public $flysystem;

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFileStore::init()
     */
    public function init()
    {
        if (is_callable($this->flysystem)) {
            $this->flysystem = call_user_func($this->flysystem, $this);
        }

        if (!($this->flysystem instanceof Filesystem)) {
            throw new InvalidConfigException('flysystem');
        }

        $config = $this->flysystem->getConfig();
        if (!$config->has('visibility')) {
            $config->set('visibility', self::access2visibility($this->access));
        }

        parent::init();
    }

    /**
     * Возвращает адаптер
     *
     * @return \League\Flysystem\AdapterInterface|NULL
     */
    public function getAdapter() {
        if (is_callable([$this->flysystem, 'getAdapter'])) {
            return $this->flysystem->getAdapter();
        }

        return null;
    }

    /**
     * Конвертирует Flysystem visibility в File::ACCESS_*
     *
     * @param string $visibility File::ACCESS_* type
     * @throws InvalidArgumentException
     * @return string Flysystem visibility type
     */
    protected static function visibility2access(string $visibility) {
        foreach (self::ACCESS_MAP as $acc => $vis) {
            if ($visibility == $vis) {
                return $acc;
            }
        }

        throw new InvalidArgumentException('visibility: '.$visibility);
    }

    /**
     * Конвертирует тип доступа File::ACCESS_* в Flysystem visibility type
     *
     * @param string $access File::ACCESS_*
     * @throws InvalidArgumentException
     * @return string Flysystem visibility
     */
    protected static function access2visibility(string $access) {
        if (isset(self::ACCESS_MAP[$access])) {
            return self::ACCESS_MAP[$access];
        }

        throw new InvalidArgumentException('access: '.$access);
    }

    /**
     * Конвертирует Flysystem type в File::TYPE_*
     *
     * @param string $type File::TYPE_* type
     * @throws InvalidArgumentException
     * @return string Flysystem type
     */
    protected static function fly2filetype(string $type) {
        foreach (self::TYPE_MAP as $filetype => $flytype) {
            if ($type == $flytype) {
                return $filetype;
            }
        }

        throw new InvalidArgumentException('type: ' . $type);
    }

    /**
     * Конвертирует тип File::TYPE_* в Flysystem type
     *
     * @param string $type File::TYPE_*
     * @throws InvalidArgumentException
     * @return string Flysystem type
     */
    protected static function file2flytype(string $type) {
        if (isset(self::TYPE_MAP[$type])) {
            return self::TYPE_MAP[$type];
        }

        throw new InvalidArgumentException('type: '.$type);
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFileStore::getFullPath()
     */
    public function getFullPath($path) {
        $path = $this->normalizeRelativePath($path);

        $adapter = $this->adapter;
        if (isset($adapter) && is_callable([$adapter, 'applyPathPrefix'])) {
            return $adapter->applyPathPrefix($path);
        }

        throw new StoreException($path, new NotSupportedException());
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFileStore::list()
     */
    public function list($path, array $options=[])
    {
        $path = $this->normalizeRelativePath($path);

        $items = null;
        try {
            $items = $this->flysystem->listContents($path, $options['recursive'] ?? false);
        } catch (\Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if (!is_array($items) && !($items instanceof \Traversable)) {
            throw new \ErrorException('not traversable');
        }

        $files = [];
        foreach ($items as $item) {
            // создаем файл
            $file = $this->file($item['path']);

            if ($this->fileMatchFilter($file, $options)) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFileStore::isExists()
     */
    public function isExists($path)
    {
        $path = $this->normalizeRelativePath($path);

        $ret = null;
        try {
            $ret =$this->flysystem->has($path);
        } catch (\Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        return !empty($ret);
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFileStore::getType()
     */
    public function getType($path)
    {
        $path = $this->normalizeRelativePath($path);
        $ret = null;

        try {
            $ret = $this->flysystem->getMetadata($path);
        } catch (\Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if (is_array($ret)) {
            $ret = $ret['type'] ?? false;
        }

        if (empty($ret)) {
            throw new StoreException($path);
        }

        return self::fly2filetype($ret);
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFileStore::getAccess()
     */
    public function getAccess($path)
    {
        $path = $this->normalizeRelativePath($path);
        $ret = null;

        try {
            $ret = $this->flysystem->getVisibility($path);
        } catch (\Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if (is_array($ret)) {
            $ret = $ret['visibility'] ?? false;
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        return self::visibility2access($ret);
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFileStore::setAccess()
     */
    public function setAccess($path, string $access)
    {
        $path = $this->normalizeRelativePath($path);
        $visibility = self::access2visibility($access);
        $ret = null;

        try {
            $ret = $this->flysystem->setVisibility($path, $visibility);
        } catch (\Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFileStore::getSize()
     */
    public function getSize($path)
    {
        $path = $this->normalizeRelativePath($path);
        $ret = null;

        try {
            // FIXME clear stat cache for local filesystem
            @clearstatcache(null, $this->getFullPath($path));
            $ret = $this->flysystem->getSize($path);
        } catch (\Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if (is_array($ret)) {
            $ret = $ret['size'] ?? false;
        }

        if ($ret === false || $ret === null) {
            throw new StoreException($path);
        }

        return $ret;
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFileStore::getMtime()
     */
    public function getMtime($path)
    {
        $path = $this->normalizeRelativePath($path);
        $ret = null;

        try {
            $ret = $this->flysystem->getTimestamp($path);
        } catch (\Exception $ex) {
            throw new StoreException($path, $ex);
        }

        if (is_array($ret)) {
            $ret = $ret['timestamp'] ?? false;
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        return $ret;
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFileStore::getMimeType()
     */
    public function getMimeType($path)
    {
        $path = $this->normalizeRelativePath($path);
        $ret = null;

        try {
            $ret = $this->flysystem->getMimetype($path);
        } catch (\Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if (is_array($ret)) {
            $ret = $ret['mimetype'] ?? false;
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        return $ret;
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFileStore::move()
     */
    public function move($path, $newpath)
    {
        $path = $this->normalizeRelativePath($path);
        $newpath = $this->normalizeRelativePath($newpath);

        if ($path === $newpath) {
            throw new \LogicException('equal path: ' . $path);
        }

        $ret = null;

        try {
            $ret = $this->flysystem->rename($path, $newpath);
        } catch (\Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        return true;
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFileStore::copy()
     */
    public function copy($path, $newpath)
    {
        $path = $this->normalizeRelativePath($path);
        $newpath = $this->normalizeRelativePath($newpath);

        if ($path === $newpath) {
            throw new \LogicException('equal path: ' . $path);
        }

        $ret = null;

        try {
            $ret = $this->adapter->copy($path, $newpath);
        } catch (\Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        return true;
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFileStore::getContents()
     */
    public function getContents($path)
    {
        $path = $this->normalizeRelativePath($path);
        $ret = null;

        try {
            $ret = $this->flysystem->read($path);
        } catch (\Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if (is_array($ret)) {
            $ret = $ret['contents'] ?? false;
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        return $ret;
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFileStore::setContents()
     */
    public function setContents($path, string $contents)
    {
        $path = $this->normalizeRelativePath($path);
        $ret = null;

        try {
            $ret = $this->flysystem->put($path, $contents);
        } catch (\THrowable $ex) {
            throw new StoreException($path, $ex);
        }

        if (is_array($ret)) {
            if (isset($ret['size'])) {
                $ret = $ret['size'];
            } else if (isset($ret['contents'])) {
                $ret = strlen($ret['contents']);
            }
        } elseif (!is_string($ret)) {
            $ret = strlen($ret);
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        return $ret;
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFileStore::getStream()
     */
    public function getStream($path)
    {
        $path = $this->normalizeRelativePath($path);
        $ret = null;

        try {
            $ret = $this->flysystem->readStream($path);
        } catch (\Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if (is_array($ret)) {
            $ret = $ret['stream'] ?? false;
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        return $ret;
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFileStore::setStream()
     * @todo implement return size
     */
    public function setStream($path, $stream)
    {
        $path = $this->normalizeRelativePath($path);
        $ret = null;

        try {
            $ret = $this->flysystem->putStream($path, $stream);
        } catch (\Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        return 1;
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFileStore::mkdir()
     */
    public function mkdir($path)
    {
        $path = $this->normalizeRelativePath($path);
        $ret = null;

        try {
            $ret = $this->flysystem->createDir($path);
        } catch (\Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        return true;
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFileStore::delete()
     */
    public function delete($path)
    {
        $path = $this->normalizeRelativePath($path);
        $type = $this->getType($path);
        $ret = null;

        try {
            $ret = $type === File::TYPE_FILE ?
                $this->flysystem->delete($path) :
                $this->flysystem->deleteDir($path);
        } catch (\Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        return true;
    }
}
