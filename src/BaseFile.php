<?php
namespace dicr\file;


/**
 * Базовый локальный файл.
 *
 * @property string $name
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2018
 */
class BaseFile extends AbstractFile
{
    /**
     * Конструктор
     *
     * @param $config
     */
    public function __construct($config = [])
    {
        if (!is_array($config)) {
            $config = [
                'path' => $config
            ];
        }

        $path = $config['path'] ?? '';
        unset($config['path']);

        parent::__construct($path, $config);
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFile::getName()
     */
    public function getName()
    {
        return (string)static::basename(static::splitPath($this->path));
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFile::getExists()
     */
    public function getExists()
    {
        return @file_exists($this->path);
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFile::getIsDir()
     */
    public function getIsDir()
    {
        return @is_dir($this->path);
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFile::getIsFile()
     */
    public function getIsFile()
    {
        return @is_file($this->path);
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFile::getSize()
     */
    public function getSize()
    {
        $size = @filesize($this->path);
        if ($size === false) {
            throw new StoreException('');
        }

        return $size;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFile::getMtime()
     */
    public function getMtime()
    {
        $time = @filemtime($this->path);
        if ($time === false) {
            throw new StoreException('');
        }

        return $time;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFile::getMimeType()
     */
    public function getMimeType($context=null)
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $type = @$finfo->file($this->path, null, $context);
        if ($type === false) {
            throw new StoreException('');
        }

        return $type;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFile::getContents()
     */
    public function getContents($context=null)
    {
        $contents = @file_get_contents($this->path, false, $context);
        if ($contents === false) {
            throw new StoreException('');
        }

        return $contents;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFile::getStream()
     */
    public function getStream($context=null)
    {
        $stream = @fopen($this->path, 'rb', false, $context);
        if (! is_resource($stream)) {
            throw new StoreException('');
        }

        return $stream;
    }

    /**
     * Перемещение с move_uploaded_file
     *
     * @param array|string $path
     * @param resource|null $context
     * @throws StoreException
     * @return static
     */
    public function move($path, $context=null)
    {
        $path = $this->normalizePath($path);
        if ($path == '') {
            throw new \InvalidArgumentException('path');
        }

        $ret = @rename($this->path, $path, $context);
        if ($ret === false) {
            throw new StoreException('');
        }

        return $this;
    }

    /**
     * Копирует в заданный путь
     *
     * @param string|array $path
     * @param resource|null $context
     * @throws StoreException
     * @return static
     */
    public function copy($path, $context=null)
    {
        $path = $this->normalizePath($path);

        $ret = @copy($this->path, $path, $context);
        if ($ret === false) {
            throw new StoreException('');
        }

        return $this;
    }

    /**
     * Удаляет файл
     *
     * @return static
     */
    public function delete()
    {
        static::deleteRecursive($this->path);
        return $this;
    }

    /**
     * Рекурсивное удалени
     *
     * @param string|array $path
     * @param resource|null $context
     * @throws StoreException
     */
    public static function deleteRecursive($path, $context = null) {
        if (is_array($path)) {
            $path = static::buildPath($path);
        }

        if ($path == '' || $path == DIRECTORY_SEPARATOR) {
            throw new \InvalidArgumentException('path');
        }

        if (@is_dir($path)) {
            $dir = @opendir($path, $context);
            if ($dir === false) {
                throw new StoreException();
            }

            try {
                while (($file = @readdir($dir)) !== false) {
                    if ($file === '.' || $file === '..') {
                        continue;
                    }
                    static::deleteRecursive($path . '/' . $file, $context);
                }
            } finally {
                if (@is_resource($dir)) {
                    closedir($dir);
                }
            }

            if (!rmdir($path, $context)) {
                throw new StoreException();
            }

        } elseif (!@unlink($path, $context)) {
            throw new StoreException();
        }

        @clearstatcache(null, $path);
    }
}
