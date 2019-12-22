<?php
/**
 * @copyright 2019-2019 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL
 * @version 24.11.19 00:29:11
 */

/** @noinspection PhpUsageOfSilenceOperatorInspection */
declare(strict_types=1);

namespace dicr\file;

use DirectoryIterator;
use FilesystemIterator;
use finfo;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use Yii;
use yii\base\InvalidConfigException;
use function in_array;
use function is_array;
use function is_resource;

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
    /** @var static instance for root "/" */
    private static $_rootInstance;

    /** @var int флаги для записи file_put_contents (например LOCK_EX) */
    public $writeFlags = 0;

    /** @var string mode for fopen for reading stream */
    public $readMode = 'rb';

    /** @var array|resource stream_context options */
    public $context;

    /**
     * @var int[] публичные права доступа на создаваемые файла и директории.
     *      Приватные получаются путем маски & 0x700
     */
    public $perms = [
        'dir' => 0755,
        'file' => 0644
    ];

    /** @var string корневой путь */
    protected $_path;

    /**
     * Возвращает экземпляр для корневой файловой системы "/"
     *
     * @return static
     */
    public static function root()
    {
        if (!isset(self::$_rootInstance)) {
            self::$_rootInstance = new static([
                'path' => '/',
                'writeFlags' => LOCK_EX
            ]);
        }

        return self::$_rootInstance;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::init()
     */
    public function init()
    {
        parent::init();

        if (!isset($this->_path)) {
            throw new InvalidConfigException('path');
        }

        $this->writeFlags = (int)$this->writeFlags;

        $this->readMode = trim($this->readMode);
        if (empty($this->readMode)) {
            $this->readMode = 'rb';
        }

        if (!is_resource($this->context)) {
            $this->context = stream_context_create($this->context);
        }

        if (!isset($this->perms['dir'], $this->perms['file']) || !is_array($this->perms)) {
            throw new InvalidConfigException('perms');
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
     * @return $this
     * @throws StoreException
     */
    public function setPath(string $path)
    {
        // решаем алиасы
        /** @noinspection CallableParameterUseCaseInTypeContextInspection */
        $path = Yii::getAlias($path);
        if ($path === false) {
            throw new InvalidArgumentException('path');
        }

        // получаем реальный путь
        $this->_path = $path === '/' ? $path : realpath(/** @scrutinizer ignore-type */ $path);
        if ($this->_path === false) {
            throw new StoreException('Путь не существует: ' . $path);
        }

        // проверяем что путь директория
        if ($this->_path !== '/' && !@is_dir($this->_path)) {
            throw new StoreException('Не является директорией: ' . $this->_path);
        }

        // обрезаем слэши (корневой путь станет пустым "")
        $this->_path = rtrim($this->_path, $this->pathSeparator);

        return $this;
    }

    /**
     * {@inheritdoc}
     * @throws StoreException
     * @throws InvalidConfigException
     * @throws InvalidConfigException
     * @see \dicr\file\AbstractFileStore::list()
     */
    public function list($path, array $filter = [])
    {
        $fullPath = $this->absolutePath($path);

        $iterator = null;
        try {
            if (!empty($filter['recursive'])) {
                $dirIterator = new RecursiveDirectoryIterator($fullPath, FilesystemIterator::CURRENT_AS_FILEINFO);
                $iterator = new RecursiveIteratorIterator($dirIterator, RecursiveIteratorIterator::CHILD_FIRST);
            } else {
                $iterator = new DirectoryIterator($fullPath);
            }
        } catch (Throwable $ex) {
            throw new StoreException($fullPath, $ex);
        }

        $files = [];
        foreach ($iterator as $item) {
            if (in_array($item->getBasename(), ['.', '..', ''], true)) {
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

        usort($files, static function ($a, $b) {
            return $a->path <=> $b->path;
        });

        return $files;
    }

    /**
     * {@inheritdoc}
     * @throws StoreException
     * @see \dicr\file\AbstractFileStore::absolutePath()
     */
    public function absolutePath($path)
    {
        return $this->buildPath(array_merge([$this->path], $this->filterPath($path)));
    }

    /**
     * Возвращает относительный путь по полному.
     *
     * @param string $fullPath полныйпуть
     * @return string|false относительный путь
     */
    public function getRelPath(string $fullPath)
    {
        return mb_strpos($fullPath, $this->path) === 0 ? mb_substr($fullPath, mb_strlen($this->path)) : false;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::isFile()
     */
    public function isFile($path)
    {
        return @is_file($this->absolutePath($path));
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::isPublic()
     */
    public function isPublic($path)
    {
        $absPath = $this->absolutePath($path);

        $perms = @fileperms($absPath);
        if ($perms === false) {
            $this->throwLastError('Проверки типа доступа файла', $absPath);
        }

        return $this->publicByPerms($this->isDir($path), $perms);
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
        return ($this->perms[$dir ? 'dir' : 'file'] & 0007) === ($perms & 0007);
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::isDir()
     */
    public function isDir($path)
    {
        return @is_dir($this->absolutePath($path));
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::size()
     */
    public function size($path)
    {
        $absPath = $this->absolutePath($path);

        $size = @filesize($absPath);
        if ($size === false) {
            $this->throwLastError('Получение размера файла', $absPath);
        }

        return $size;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::mtime()
     */
    public function mtime($path)
    {
        $absPath = $this->absolutePath($path);

        $time = @filemtime($absPath);
        if ($time === false) {
            $this->throwLastError('Получения времени модификации файла', $absPath);
        }

        return $time;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::mimeType()
     */
    public function mimeType($path)
    {
        $absPath = $this->absolutePath($path);

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $type = @$finfo->file($absPath, FILEINFO_NONE, /** @scrutinizer ignore-type */ $this->context);
        if ($type === false) {
            $this->throwLastError('Получение mime-типа', $absPath);
        }

        return $type;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::readContents()
     */
    public function readContents($path)
    {
        $absPath = $this->absolutePath($path);

        $contents = @file_get_contents($absPath, false, /** @scrutinizer ignore-type */ $this->context);
        if ($contents === false) {
            $this->throwLastError('Чтение файла', $absPath);
        }

        return $contents;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::writeContents()
     */
    public function writeContents($path, string $contents)
    {
        return $this->writeStreamOrContents($path, $contents);
    }

    /**
     * Записывает в файл строку, массив или содержимое потока
     *
     * @param string|string[] $path
     * @param string|array|resource $contents
     * @return int кол-во записанных байт
     * @throws StoreException
     */
    public function writeStreamOrContents($path, $contents)
    {
        // фильтруем и проверяем путь
        $path = $this->filterRootPath($path);

        // проверяем существование до создания, так как дальше необходимо будет менять права доступа
        $exists = $this->exists($path);

        // проверяем наличие директории если файл не существует
        if (!$exists) {
            $this->checkDir($this->dirname($path));
        }

        // строим абсолютный путь
        $absPath = $this->absolutePath($path);

        $bytes =
            @file_put_contents($absPath, $contents, $this->writeFlags, /** @scrutinizer ignore-type */ $this->context);
        if ($bytes === false) {
            $this->throwLastError('Запись файла', $absPath);
        }

        try {
            // если файл не существовал, то необходимо установить права доступа
            if ($exists) {
                // если файл существовал, то необходимо сбросить кэш
                $this->clearStatCache($path);
            } else {
                $this->setPublic($path, $this->public);
            }
        } catch (Throwable $ex) {
            // для удаленных систем не обращаем внимание на исключение при установке прав
            if (@stream_is_local($absPath)) {
                throw new StoreException($absPath, $ex);
            }
        }

        return $bytes;
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
     * @see \dicr\file\AbstractFileStore::setPublic()
     */
    public function setPublic($path, bool $public)
    {
        // фильтруем и проверяем путь
        $path = $this->filterRootPath($path);

        // рассчитываем права доступа
        $perms = $this->permsByPublic($this->isDir($path), $public);

        // абсолютный путь
        $absPath = $this->absolutePath($path);

        if (@chmod($absPath, $perms) === false) {
            $this->throwLastError('Смена прав доступа', $absPath);
        }

        $this->clearStatCache($path);

        return $this;
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
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::readStream()
     */
    public function readStream($path)
    {
        $absPath = $this->absolutePath($path);

        $stream = @fopen($absPath, $this->readMode, false, /** @scrutinizer ignore-type */ $this->context);
        if ($stream === false) {
            $this->throwLastError('Открытие файла', $absPath);
        }

        return $stream;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::writeStream()
     */
    public function writeStream($path, $stream)
    {
        return $this->writeStreamOrContents($path, $stream);
    }

    /**
     * {@inheritDoc}
     *
     * Более эффективная версия абстрактного копирования.
     *
     * @see \dicr\file\AbstractFileStore::copy()
     */
    public function copy($path, $newpath)
    {
        // проверяем аргументы
        $path = $this->filterRootPath($path);
        $newpath = $this->filterRootPath($newpath);

        $absPath = $this->absolutePath($path);
        $absNew = $this->absolutePath($newpath);

        if ($absPath === $absNew) {
            throw new StoreException('Копирование файла в себя: ' . $absPath);
        }

        $this->checkDir($newpath);

        if (@copy($absPath, $absNew, /** @scrutinizer ignore-type */ $this->context) === false) {
            $this->throwLastError('Копирование файла', $absNew);
        }

        $this->clearStatCache($newpath);

        return $this;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFileStore::rename()
     */
    public function rename($path, $newpath)
    {
        // фильтруем и проверяем пути
        $path = $this->filterRootPath($path);
        $newpath = $this->filterRootPath($newpath);

        // сравниваем абсолютные пути
        $absPath = $this->absolutePath($path);
        $absNew = $this->absolutePath($newpath);

        // если одинаковые, то нахрен такое программирование
        if ($absPath === $absNew) {
            return $this;
        }

        // проверяем директорию назначения
        $this->checkDir($this->dirname($newpath));

        if (@rename($absPath, $absNew, /** @scrutinizer ignore-type */ $this->context) === false) {
            $this->throwLastError('Переименование файла', $absNew);
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
        // фильтруем и проверяем путь
        $path = $this->filterRootPath($path);

        // получаем абсолютный путь
        $absPath = $this->absolutePath($path);

        // проверяем на существование
        if ($this->exists($path)) {
            if (!$this->isDir($path)) {
                throw new StoreException('Уже существует не директория: ' . $absPath);
            }

            return $this;
        }

        // определяем необходимые права
        $perms = $this->permsByPublic(true, $this->public);

        // создаем иректорию
        if (@mkdir($absPath, $perms, true, /** @scrutinizer ignore-type */ $this->context) === false) {
            $this->throwLastError('Создание директории', $absPath);
        }

        return $this;
    }

    /**
     * Конвертирует в строку
     *
     * @return string
     * @throws StoreException
     */
    public function __toString()
    {
        return $this->absolutePath('');
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFileStore::unlink()
     */
    protected function unlink($path)
    {
        // фильтруем и проверяем путь
        $path = $this->filterRootPath($path);

        // получаем полный путь
        $absPath = $this->absolutePath($path);

        // удаляем
        if (@unlink($absPath, /** @scrutinizer ignore-type */ $this->context) === false) {
            $this->throwLastError('Удаление файла', $absPath);
        }

        $this->clearStatCache($path);

        return $this;
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFileStore::rmdir()
     */
    protected function rmdir($path)
    {
        // фильтруем и проверяем путь
        $path = $this->filterRootPath($path);

        // получаем полный путь
        $absPath = $this->absolutePath($path);

        // удаляем директорию
        if (@rmdir($absPath, /** @scrutinizer ignore-type */ $this->context) === false) {
            $this->throwLastError('Удаление директории', $absPath);
        }

        $this->clearStatCache($path);

        return $this;
    }
}
