<?php
namespace dicr\file;

use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;

/**
 * Local file store.
 *
 * Also support ftp://, ssh2:// throught wrappers:
 * @see http://php.net/manual/en/wrappers.php
 *
 * /opt/files
 *
 * zip://test.zip#path/file.txt, context => ['password' => your_pass]
 *
 * ftp://user:pass@server.net/path/file.txt
 * @see http://php.net/manual/en/context.ftp.php
 *
 * ssh2.sftp://user:pass@example.com:22/path/file.txt
 * @see http://php.net/manual/en/wrappers.ssh2.php
 *
 * @property string $path путь корня файлового хранилища
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
 */
class LocalFileStore extends AbstractFileStore
{
    /** @var string корневой путь */
    protected $_path;

    /** @var int флаги для записи file_put_contents (LOCK_EX) */
    public $writeFlags = 0;

    /** @var string mode for fopen for reading stream */
    public $readMode = 'rb';

    /** @var array|resource stream_context options */
    public $context = [];

    /**
     * @var array публичные права доступа на создаваемые файла и директории.
     *      Приватные получаются путем маски & 0x700
     */
    public $perms = [File::TYPE_DIR => 0755,File::TYPE_FILE => 0644];

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::init()
     */
    public function init()
    {
        parent::init();

        if (empty($this->path)) {
            throw new InvalidConfigException('path');
        }

        if (is_array($this->context)) {
            $this->context = stream_context_create($this->context);
        }

        if (!is_resource($this->context)) {
            throw new InvalidConfigException('context');
        }
    }

    /**
     * Возвращает путь
     *
     * @return string
     */
    public function getPath()
    {
        return $this->_path;
    }

    /**
     * Установить корневой путь
     *
     * @param string $path
     * @return static
     */
    public function setPath(string $path)
    {
        $path = \Yii::getAlias($path);
        if ($path === false) {
            throw new InvalidArgumentException('path');
        }

        $path = rtrim($path, $this->pathSeparator);
        if ($path === '') {
            throw new InvalidArgumentException('path');
        }

        $this->_path = $path;

        return $this;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::getFullPath()
     */
    public function getFullPath($path)
    {
        $p = [$this->path];

        $path = $this->normalizeRelativePath($path);
        if ($path !== '') {
            $p[] = $path;
        }

        return implode($this->pathSeparator, $p);
    }

    /**
     * Возвращает относительный путь по полному
     *
     * @param string $fullPath полныйпуть
     * @return string|false относительный путь
     */
    public function getRelPath(string $fullPath)
    {
        if (mb_strpos($fullPath, $this->path) === 0) {
            $relpath = mb_substr($fullPath, mb_strlen($this->path));
            return $this->normalizeRelativePath($relpath);
        }

        return false;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::list()
     */
    public function list($path, array $options = [])
    {
        if (! $this->isExists($path)) {
            return [];
        }

        $fullPath = $this->getFullPath($path);

        $iterator = null;
        try {
            if (! empty($options['recursive'])) {
                $dirIterator = new \RecursiveDirectoryIterator($fullPath, \FilesystemIterator::CURRENT_AS_FILEINFO);
                $iterator = new \RecursiveIteratorIterator($dirIterator, \RecursiveIteratorIterator::CHILD_FIRST);
            } else {
                $iterator = new \DirectoryIterator($fullPath);
            }
        } catch (\Throwable $ex) {
            throw new StoreException($fullPath, $ex);
        }

        $files = [];
        foreach ($iterator as $item) {
            if (in_array($item->getBaseName(), ['.','..'])) {
                continue;
            }

            $item = $this->getRelPath($item->getPathname());
            if ($item === false) {
                continue;
            }

            $file = $this->file($item);
            if ($this->fileMatchFilter($file, $options)) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::isExists()
     */
    public function isExists($path)
    {
        $path = $this->normalizeRelativePath($path);
        if ($path === '') {
            return true;
        }

        return @file_exists($this->getFullPath($path));
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::getType()
     */
    public function getType($path)
    {
        $path = $this->normalizeRelativePath($path);
        if ($path === '') {
            return File::TYPE_DIR;
        }

        $fullPath = $this->getFullPath($path);
        if (! @file_exists($fullPath)) {
            throw new StoreException('not exits: ' . $path);
        }

        return @is_dir($fullPath) ? File::TYPE_DIR : File::TYPE_FILE;
    }

    /**
     * Возвращает права доступа для заданного типа файла и типа доступа.
     *
     * @param string $type тип файла File::TYPE_*
     * @param string $access тип доступа File::ACCESS_*
     * @return int права доступа
     */
    protected function permsByAccess(string $type, string $access)
    {
        $perms = $this->perms[$type] ?? null;
        if (! isset($perms)) {
            throw new InvalidArgumentException('type');
        }

        switch ($access) {
            case File::ACCESS_PUBLIC:
                break;

            case File::ACCESS_PRIVATE:
                $perms &= 0700;
                break;

            default:
                throw new InvalidArgumentException('access');
        }

        return $perms;
    }

    /**
     * Возвращает тип доступа по правам
     *
     * @param string $type тип файла File::TYPE_*
     * @param int $perms права доступа
     * @return string режим доступа File::ACCESS_*
     */
    protected function accessByPerms(string $type, int $perms)
    {
        $publicPerms = $this->perms[$type] ?? null;
        if (! isset($publicPerms)) {
            throw new InvalidArgumentException('type');
        }

        return ($publicPerms & 0007) == ($perms & 0007) ? File::ACCESS_PUBLIC : File::ACCESS_PRIVATE;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::getAccess()
     */
    public function getAccess($path)
    {
        $fullPath = $this->getFullPath($path);
        if (! @file_exists($fullPath)) {
            throw new StoreException($path);
        }

        $perms = @fileperms($fullPath);
        if ($perms === false) {
            throw new StoreException();
        }

        $access = $this->accessByPerms(@is_dir($fullPath) ? File::TYPE_DIR : File::TYPE_FILE, $perms);
        return $access;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::setAccess()
     */
    public function setAccess($path, string $access)
    {
        $path = $this->normalizeRelativePath($path);
        if ($path === '') {
            throw new StoreException('root path');
        }

        $fullPath = $this->getFullPath($path);
        if (! @file_exists($fullPath)) {
            throw new StoreException($path);
        }

        $perms = $this->permsByAccess(@is_dir($fullPath) ? File::TYPE_DIR : File::TYPE_FILE, $access);

        if (@chmod($fullPath, $perms) === false) {
            throw new StoreException();
        }

        clearstatcache(null, $fullPath);

        return $this;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::getSize()
     */
    public function getSize($path)
    {
        $path = $this->normalizeRelativePath($path);
        if ($path === '') {
            throw new StoreException('root path');
        }

        $size = @filesize($this->getFullPath($path));
        if ($size === false) {
            throw new StoreException();
        }
        return $size;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::getMtime()
     */
    public function getMtime($path)
    {
        $time = @filemtime($this->getFullPath($path));
        if ($time === false) {
            throw new StoreException();
        }

        return $time;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::getMimeType()
     */
    public function getMimeType($path)
    {
        $path = $this->normalizeRelativePath($path);
        if ($path === '') {
            throw new StoreException('root path');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $type = @$finfo->file($this->getFullPath($path), null, $this->context);
        if ($type === false) {
            throw new StoreException();
        }

        return $type;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::move()
     */
    public function move($path, $newpath)
    {
        $path = $this->normalizeRelativePath($path);
        if ($path === '') {
            throw new StoreException('root path');
        }

        $newpath = $this->normalizeRelativePath($newpath);
        if ($newpath === '') {
            throw new StoreException('root path');
        }

        if ($path === $newpath) {
            throw new \LogicException('path == newpath');
        }

        $this->checkDir(dirname($newpath));

        if (@rename($this->getFullPath($path), $this->getFullPath($newpath), $this->context) === false) {
            throw new StoreException();
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::copy()
     */
    public function copy($path, $newpath)
    {
        $path = $this->normalizeRelativePath($path);
        if ($path === '') {
            throw new StoreException('root path');
        }

        $newpath = $this->normalizeRelativePath($newpath);
        if ($newpath === '') {
            throw new StoreException('root path');
        }

        if ($path === $newpath) {
            throw new \LogicException('path == newpath');
        }

        $this->checkDir(dirname($newpath));

        if (@copy($this->getFullPath($path), $this->getFullPath($newpath), $this->context) === false) {
            throw new StoreException();
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::getContents()
     */
    public function getContents($path)
    {
        $path = $this->normalizeRelativePath($path);
        if ($path === '') {
            throw new StoreException('root path');
        }

        $ret = @file_get_contents($this->getFullPath($path), false, $this->context);

        if ($ret === false) {
            throw new StoreException();
        }

        return $ret;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::setContents()
     */
    public function setContents($path, string $contents)
    {
        $path = $this->normalizeRelativePath($path);
        if ($path === '') {
            throw new StoreException('root path');
        }

        $fullPath = $this->getFullPath($path);
        $exists = @file_exists($fullPath);

        if (! $exists) {
            $this->checkDir(dirname($path));
        }

        $bytes = @file_put_contents($fullPath, $contents, $this->writeFlags, $this->context);
        if ($bytes === false) {
            throw new StoreException();
        }

        try {
            if (! $exists) {
                $this->setAccess($path, $this->access);
            } else {
                clearstatcache(null, $fullPath);
            }
        } catch (\Throwable $ex) {
            if (stream_is_local($fullPath)) {
                throw new StoreException($path, $ex);
            }
        }

        return $bytes;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::getStream()
     */
    public function getStream($path)
    {
        $path = $this->normalizeRelativePath($path);
        if ($path === '') {
            throw new StoreException('root path');
        }

        $stream = @fopen($this->getFullPath($path), $this->readMode, false, $this->context);
        if ($stream === false) {
            throw new StoreException();
        }

        return $stream;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::setStream()
     */
    public function setStream($path, $stream)
    {
        $path = $this->normalizeRelativePath($path);
        if ($path === '') {
            throw new StoreException('root path');
        }

        $contents = @stream_get_contents($stream);
        if ($contents === false) {
            throw new StoreException();
        }

        return $this->setContents($path, $contents);
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::mkdir()
     */
    public function mkdir($path)
    {
        $path = $this->normalizeRelativePath($path);
        if ($path == '') {
            throw new StoreException('root path');
        }

        $fullPath = $this->getFullPath($path);
        if (! @is_dir($fullPath)) {
            $perms = $this->permsByAccess(File::TYPE_DIR, $this->access);

            if (@mkdir($fullPath, $perms, true, $this->context) === false) {
                throw new StoreException();
            }
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::delete()
     */
    public function delete($path)
    {
        $path = $this->normalizeRelativePath($path);
        if ($path == '') {
            throw new StoreException('delete root path');
        }

        $fullPath = $this->getFullPath($path);
        if (! file_exists($fullPath)) {
            throw new StoreException($path);
        }

        $delTree = null;

        $delTree = function ($path) use (&$delTree) {
            if (@is_dir($path)) {
                $dir = @opendir($path, $this->context);
                if ($dir === false) {
                    throw new StoreException();
                }

                try {
                    while (($file = readdir($dir)) !== false) {
                        if ($file === '.' || $file === '..') {
                            continue;
                        }
                        $delTree($path . '/' . $file);
                    }
                } finally {
                    if (! empty($dir)) {
                        @closedir($dir);
                    }
                }

                if (@rmdir($path, $this->context) === false) {
                    throw new StoreException();
                }
            } elseif (@unlink($path, $this->context) === false) {
                throw new StoreException();
            }
        };

        $delTree($fullPath);

        clearstatcache(null, $fullPath); // for ssh2 wrapper

        return $this;
    }

    /**
     * Конвертирует в строку
     *
     * @return string
     */
    public function __toString() {
        return $this->path;
    }
}
