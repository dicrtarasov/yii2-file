<?php
namespace dicr\file;

use InvalidArgumentException;
use yii\base\InvalidConfigException;

/**
 * Локальная файловая система.
 *
 * Также поддерживает php wrappers, например ftp://, ssh2://
 *
 * /opt/files
 * zip://test.zip#path/file.txt, context => ['password' => your_pass]
 * ftp://user:pass@server.net/path/file.txt, context => ['ftp' => ['overwrite' => true]]
 * ssh2.sftp://user:pass@example.com:22/path/file.txt
 *
 * @see http://php.net/manual/en/wrappers.php
 * @see http://php.net/manual/en/context.ftp.php
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

    /** @var int флаги для записи file_put_contents (например LOCK_EX) */
    public $writeFlags = 0;

    /** @var string mode for fopen for reading stream */
    public $readMode = 'rb';

    /** @var array|resource stream_context options */
    public $context = [];

    /**
     * @var array публичные права доступа на создаваемые файла и директории.
     *      Приватные получаются путем маски & 0x700
     */
    public $perms = [
        'dir' => 0755,
        'file' => 0644
    ];

    /** @var static instance for root "/" */
    private static $_rootInstance;

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

        if (! isset($this->perms['dir']) || ! isset($this->perms['file'])) {
            throw new InvalidConfigException('perms');
        }

        if (empty($this->context)) {
            $this->context = [];
        }

        if (is_array($this->context)) {
            $this->context = stream_context_create($this->context);
        }

        if (! is_resource($this->context)) {
            throw new InvalidConfigException('context');
        }
    }

    /**
     * Возвращает экземпляр для корневой файловой системы "/"
     *
     * @return static
     */
    public static function root()
    {
        if (! isset(self::$_rootInstance)) {
            self::$_rootInstance = new static([
                'path' => '/',
                'writeFlags' => LOCK_EX
            ]);
        }

        return self::$_rootInstance;
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
     * @return $this
     */
    public function setPath(string $path)
    {
        // решаем алиасы
        $path = \Yii::getAlias($path, true);
        if ($path === false) {
            throw new InvalidArgumentException('path');
        }

        // получаем реальный путь
        $this->_path = realpath($path);
        if ($this->_path === false) {
            throw new StoreException('путь не существует: ' . $path);
        }

        // проверяем что путь директория
        if (!@is_dir($this->_path)) {
            throw new StoreException('не является директорией: ' . $this->_path);
        }

        // обрезаем слэши (корневой путь станет пустым "")
        $this->_path = rtrim($this->_path, $this->pathSeparator);

        return $this;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::absolutePath()
     */
    public function absolutePath($path)
    {
        return $this->buildPath(array_merge([$this->path], $this->filterPath($path)));
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
            return mb_substr($fullPath, mb_strlen($this->path));
        }

        return false;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::list()
     */
    public function list($path, array $filter = [])
    {
        $fullPath = $this->absolutePath($path);

        $iterator = null;
        try {
            if (! empty($filter['recursive'])) {
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
            if (in_array($item->getBaseName(), ['.','..',''])) {
                continue;
            }

            $item = $this->getRelPath($item->getPathname());
            if ($item === false) {
                continue;
            }

            $file = $this->file($item);

            if ($this->fileMatchFilter($file, $filter)) {
                $files[] = $file;
            }
        }

        usort($files, function ($a, $b) {
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
        return @file_exists($this->absolutePath($path));
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::isDir()
     */
    public function isDir($path)
    {
        if (! $this->exists($path)) {
            throw new StoreException('not exists: ' . $this->normalizePath($path));
        }

        return @is_dir($this->absolutePath($path));
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::isFile()
     */
    public function isFile($path)
    {
        if (! $this->exists($path)) {
            throw new StoreException('not exists: ' . $this->normalizePath($path));
        }

        return @is_file($this->absolutePath($path));
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::isPublic()
     */
    public function isPublic($path)
    {
        if (! $this->exists($path)) {
            throw new StoreException('not found: ' . $this->normalizePath($path));
        }

        $perms = @fileperms($this->absolutePath($path));
        if ($perms === false) {
            throw new StoreException('');
        }

        return $this->publicByPerms($this->isDir($path), $perms);
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::setPublic()
     */
    public function setPublic($path, bool $public)
    {
        $absolute = $this->absolutePath($path);
        $perms = $this->permsByPublic($this->isDir($path), $public);

        if (@chmod($absolute, $perms) === false) {
            throw new StoreException('');
        }

        clearstatcache(null, $absolute);

        return $this;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::size()
     */
    public function size($path)
    {
        $size = @filesize($this->absolutePath($path));
        if ($size === false) {
            throw new StoreException('');
        }

        return $size;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::mtime()
     */
    public function mtime($path)
    {
        $time = @filemtime($this->absolutePath($path));
        if ($time === false) {
            throw new StoreException('');
        }

        return $time;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::mimeType()
     */
    public function mimeType($path)
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $type = @$finfo->file($this->absolutePath($path), null, $this->context);
        if ($type === false) {
            throw new StoreException('');
        }

        return $type;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::readContents()
     */
    public function readContents($path)
    {
        $contents = @file_get_contents($this->absolutePath($path), false, $this->context);
        if ($contents === false) {
            throw new StoreException('');
        }

        return $contents;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::writeContents()
     */
    public function writeContents($path, string $contents)
    {
        $path = $this->guardRootPath($path);
        $fullPath = $this->absolutePath($path);
        $exists = $this->exists($path);

        if (! $exists) {
            $this->checkDir($this->dirname($path));
        }

        $bytes = @file_put_contents($fullPath, $contents, $this->writeFlags, $this->context);
        if ($bytes === false) {
            throw new StoreException('');
        }

        try {
            if (! $exists) {
                $this->setPublic($path, $this->public);
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
     * @see \dicr\file\AbstractFileStore::readStream()
     */
    public function readStream($path)
    {
        $stream = @fopen($this->absolutePath($path), 'rb', false, $this->context);
        if (! is_resource($stream)) {
            throw new StoreException('');
        }

        return $stream;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::writeStream()
     */
    public function writeStream($path, $stream)
    {
        $contents = @stream_get_contents($stream);
        if ($contents === false) {
            throw new StoreException('');
        }

        return $this->writeContents($path, $contents);
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::move()
     */
    public function move($path, $newpath)
    {
        $path = $this->guardRootPath($path);
        $newpath = $this->guardRootPath($newpath);

        if ($path === $newpath) {
            throw new \LogicException('path == newpath');
        }

        $this->checkDir($this->dirname($newpath));

        if (@rename($this->absolutePath($path), $this->absolutePath($newpath), $this->context) === false) {
            throw new StoreException('');
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::copy()
     */
    public function copy($path, $newpath)
    {
        $path = $this->guardRootPath($path);
        $newpath = $this->guardRootPath($newpath);

        if ($path === $newpath) {
            throw new \LogicException('path == newpath');
        }

        $this->checkDir($this->dirname($newpath));

        if (@copy($this->absolutePath($path), $this->absolutePath($newpath), $this->context) === false) {
            throw new StoreException('');
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::mkdir()
     */
    public function mkdir($path)
    {
        $path = $this->guardRootPath($path);

        if ($this->exists($path)) {
            throw new StoreException('уже существует: ' . $path);
        }

        $perms = $this->permsByPublic(true, $this->public);

        if (@mkdir($this->absolutePath($path), $perms, true, $this->context) === false) {
            $err = error_get_last();
            error_clear_last();
            throw new StoreException('Ошибка создания директории: ' . $this->absolutePath($path) . ': ' . $err['message']);
        }

        return $this;
    }

    /**
     * Удаляет файл
     *
     * @param string|array $path
     * @throws StoreException
     * @return static
     */
    protected function unlink($path)
    {
        $this->guardRootPath($path);
        $absPath = $this->absolutePath($path);

        if (@unlink($absPath, $this->context) === false) {
            throw new StoreException('');
        }

        return $this;
    }

    /**
     * Удаляет директорию
     *
     * @param string|array $path
     * @throws StoreException
     * @return static
     */
    protected function rmdir($path)
    {
        $this->guardRootPath($path);
        $absPath = $this->absolutePath($path);

        if (@rmdir($absPath, $this->context) === false) {
            throw new StoreException('');
        }

        return $this;
    }

    /**
     * Конвертирует в строку
     *
     * @return string
     */
    public function __toString()
    {
        return $this->absolutePath('');
    }

    /**
     * Возвращает права доступа для заданного типа файла и типа доступа.
     *
     * @param bool $dir - директория или файл
     * @param bool $public - публичный доступ или приватный
     * @return int права доступа
     */
    protected function permsByPublic(bool $dir, bool $public)
    {
        return $this->perms[$dir ? 'dir' : 'file'] & ($public ? 0777 : 0700);
    }

    /**
     * Возвращает тип доступа по правам
     *
     * @param bool $dir - директория или файл
     * @param int $perms права доступа
     * @return bool $public
     */
    protected function publicByPerms(bool $dir, int $perms)
    {
        return ($this->perms[$dir ? 'dir' : 'file'] & 0007) == ($perms & 0007);
    }
}
