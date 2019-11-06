<?php
/**
 * Copyright (c) 2019.
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 */

declare(strict_types = 1);
namespace dicr\file;

use Exception;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Filesystem;
use Throwable;
use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;
use function call_user_func;
use function is_array;
use function is_callable;
use function is_object;
use function strlen;

/**
 * FileStore based on FLYsystem adapters.
 *
 * @property-read \League\Flysystem\AdapterInterface|null $adapter
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
 * @see http://flysystem.thephpleague.com/docs/
 */
class FlysystemFileStore extends AbstractFileStore
{
    /** @var \League\Flysystem\Filesystem|callable */
    public $flysystem;

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::init()
     */
    public function init()
    {
        if (is_callable($this->flysystem)) {
            $this->flysystem = call_user_func(/** @scrutinizer ignore-type */ $this->flysystem, $this);
        }

        if (! ($this->flysystem instanceof Filesystem)) {
            throw new InvalidConfigException('flysystem');
        }

        $config = $this->flysystem->getConfig();
        if (! $config->has('visibility')) {
            $config->set('visibility', self::access2visibility($this->public));
        }

        parent::init();
    }

    /**
     * Конвертирует тип доступа public в Flysystem visibility type
     *
     * @param bool $public
     * @return string \League\Flysystem\AdapterInterface::VISIBILITY_PUBLIC
     */
    protected static function access2visibility(bool $public)
    {
        return $public ? AdapterInterface::VISIBILITY_PUBLIC : AdapterInterface::VISIBILITY_PRIVATE;
    }

    /**
     * Возвращает адаптер
     *
     * @return \League\Flysystem\AdapterInterface|NULL
     */
    public function getAdapter()
    {
        if (is_object($this->flysystem) && method_exists($this->flysystem, 'getAdapter')) {
            return $this->flysystem->getAdapter();
        }

        return null;
    }

    /**
     * {@inheritdoc}
     * @throws \yii\base\InvalidConfigException
     * @see \dicr\file\AbstractFileStore::list()
     */
    public function list($path, array $options = [])
    {
        $path = $this->normalizePath($path);

        if (! $this->exists($path)) {
            return [];
        }

        try {
            $items = $this->flysystem->listContents($path, $options['recursive'] ?? false);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        $files = [];
        foreach ($items as $item) {
            // создаем файл
            $file = $this->file($item['path']);

            // фильтруем
            if ($this->fileMatchFilter($file, $options)) {
                $files[] = $file;
            }
        }

        // сортируем
        usort($files, static function($a, $b) {
            return $a->path <=> $b->path;
        });

        return $files;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::exists()
     */
    public function exists($path)
    {
        $path = $this->normalizePath($path);

        try {
            $ret = $this->flysystem->has($path);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        return ! empty($ret);
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::isDir()
     */
    public function isDir($path)
    {
        return $this->getType($path) === 'dir';
    }

    /**
     * Возвращает тип файл/диретория
     *
     * @param string|array $path
     * @return string dir|file
     * @throws \dicr\file\StoreException
     */
    public function getType($path)
    {
        $path = $this->normalizePath($path);

        try {
            $ret = $this->flysystem->getMetadata($path);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if (is_array($ret)) {
            $ret = $ret['type'] ?? false;
        }

        if (empty($ret)) {
            throw new StoreException('Ошибка получения типа файла: ' . $path);
        }

        return $ret;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::isFile()
     */
    public function isFile($path)
    {
        return $this->getType($path) === 'file';
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::isPublic()
     */
    public function isPublic($path)
    {
        $path = $this->normalizePath($path);

        try {
            $ret = $this->flysystem->getVisibility($path);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if (empty($ret)) {
            throw new StoreException($path);
        }

        return self::visibility2access($ret);
    }

    /**
     * Возвращает тип доступа (публичность) по типу Slysystem
     *
     * @param string $visibility \League\Flysystem\AdapterInterface::VISIBILITY_*
     * @return bool флаг public
     */
    protected static function visibility2access(string $visibility)
    {
        return $visibility === AdapterInterface::VISIBILITY_PUBLIC;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::setPublic()
     */
    public function setPublic($path, bool $public)
    {
        $path = $this->normalizePath($path);
        $visibility = self::access2visibility($public);

        try {
            $ret = $this->flysystem->setVisibility($path, $visibility);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        $this->clearStatCache($path);

        return $this;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::size()
     */
    public function size($path)
    {
        $path = $this->normalizePath($path);

        try {
            $ret = $this->flysystem->getSize($path);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        return $ret;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::mtime()
     */
    public function mtime($path)
    {
        $path = $this->normalizePath($path);

        try {
            $ret = $this->flysystem->getTimestamp($path);
        } catch (Exception $ex) {
            throw new StoreException($path, $ex);
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        return $ret;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::mimeType()
     */
    public function mimeType($path)
    {
        $path = $this->normalizePath($path);

        try {
            $ret = $this->flysystem->getMimetype($path);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        return $ret;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::readContents()
     */
    public function readContents($path)
    {
        $path = $this->normalizePath($path);

        try {
            $ret = $this->flysystem->read($path);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        return $ret;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::writeContents()
     */
    public function writeContents($path, string $contents)
    {
        $path = $this->normalizePath($path);

        try {
            $ret = $this->flysystem->put($path, $contents);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        // глючный flysystem возвращает bool вместо size, поэтому костыль
        $ret = strlen($contents);

        $this->clearStatCache($path);

        return $ret;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::readStream()
     */
    public function readStream($path)
    {
        $path = $this->normalizePath($path);

        try {
            $ret = $this->flysystem->readStream($path);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        return $ret;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::writeStream()
     */
    public function writeStream($path, $stream)
    {
        $path = $this->normalizePath($path);

        try {
            $ret = $this->flysystem->putStream($path, $stream);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        $this->clearStatCache($path);

        return 1;
    }

    /**
     * {@inheritdoc}
     * @throws \yii\base\NotSupportedException
     * @see \dicr\file\AbstractFileStore::copy()
     */
    public function copy($path, $newpath)
    {
        $path = $this->normalizePath($path);
        $newpath = $this->normalizePath($newpath);

        if ($path === $newpath) {
            throw new StoreException('Копирование файла в себя: ' . $this->absolutePath($path));
        }

        try {
            $ret = $this->flysystem->copy($path, $newpath);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        $this->clearStatCache($newpath);

        return $this;
    }

    /**
     * {@inheritdoc}
     * @throws \yii\base\NotSupportedException
     * @throws \dicr\file\StoreException
     * @see \dicr\file\AbstractFileStore::absolutePath()
     */
    public function absolutePath($path)
    {
        $path = $this->normalizePath($path);

        $adapter = $this->adapter;
        if (isset($adapter) && is_callable([$adapter, 'applyPathPrefix'])) {
            return $adapter->applyPathPrefix($path);
        }

        throw new NotSupportedException('адаптер не поддерживает метод applyPathPrefix');
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::rename()
     */
    public function rename($path, $newpath)
    {
        $path = $this->normalizePath($path);
        $newpath = $this->normalizePath($newpath);

        if ($path === $newpath) {
            return $this;
        }

        try {
            $ret = $this->flysystem->rename($path, $newpath);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        $this->clearStatCache($path);

        return $this;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::mkdir()
     */
    public function mkdir($path)
    {
        $path = $this->normalizePath($path);

        try {
            $ret = $this->flysystem->createDir($path);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::unlink()
     */
    protected function unlink($path)
    {
        $path = $this->normalizePath($this->filterRootPath($path));

        try {
            $ret = $this->flysystem->delete($path);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        $this->clearStatCache($path);

        return $this;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::rmdir()
     */
    protected function rmdir($path)
    {
        $path = $this->normalizePath($this->filterRootPath($path));

        try {
            $ret = $this->flysystem->deleteDir($path);
        } catch (Throwable $ex) {
            throw new StoreException($path, $ex);
        }

        if ($ret === false) {
            throw new StoreException($path);
        }

        $this->clearStatCache($path);

        return $this;
    }
}
