<?php
namespace dicr\file;

use yii\base\Component;
use yii\base\InvalidCallException;
use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;
use yii\helpers\Url;

/**
 * Abstract Fle Store
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
 */
abstract class AbstractFileStore extends Component
{

    /** @var string|array $url */
    public $url;

    /** @var bool публичный доступ к файлам */
    public $public = true;

    /** @var array|string конфиг создания файлов */
    public $fileConfig = ['class' => StoreFile::class];

    /** @var string path separator */
    public $pathSeparator = DIRECTORY_SEPARATOR;

    /** @var string|array|Thumbnailer создание превью файлов */
    public $thumbnailer;

    /**
     * {@inheritdoc}
     * @see \yii\base\BaseObject::init()
     */
    public function init()
    {
        parent::init();

        if (is_string($this->url)) {
            $url = \Yii::getAlias($this->url);
            if (! is_string($url)) {
                throw new InvalidConfigException('url');
            }

            $this->url = $url ?: null;
        }

        if (empty($this->fileConfig)) {
            $this->fileConfig = [];
        } elseif (is_string($this->fileConfig)) {
            $this->fileConfig = ['class' => $this->fileConfig];
        } elseif (! is_array($this->fileConfig)) {
            throw new InvalidConfigException('fileConfig');
        }

        if (! isset($this->fileConfig['class'])) {
            $this->fileConfig['class'] = StoreFile::class;
        } elseif (! is_a($this->fileConfig['class'], StoreFile::class, true)) {
            throw new InvalidCallException(
                'file class: ' . $this->fileConfig['class'] . ' must extends ' . StoreFile::class);
        }

        if (isset($this->thumbnailer)) {
            if (is_string($this->thumbnailer)) {
                $this->thumbnailer = \Yii::getAlias($this->thumbnailer, true);
            } elseif (is_array($this->thumbnailer)) {
                if (! isset($this->thumbnailer['class'])) {
                    $this->thumbnailer['class'] = Thumbnailer::class;
                }

                $this->thumbnailer = \Yii::createObject($this->thumbnailer);
            }

            if (! ($this->thumbnailer instanceof Thumbnailer)) {
                throw new InvalidConfigException('thumbnailer');
            }
        }
    }

    /**
     * Разбивает путь на элементы
     *
     * @param string|array $path
     * @return array
     */
    public function splitPath($path)
    {
        if (! is_array($path)) {
            $regex = sprintf('~[%s\\\/]+~uism', preg_quote($this->pathSeparator));
            $path = preg_split($regex, $path, - 1, PREG_SPLIT_NO_EMPTY);
        }

        return $path;
    }

    /**
     * Фильтрует путь
     *
     * @param string|array $path
     * @return array
     */
    public function filterPath($path)
    {
        $path = $this->splitPath($path);

        $filtered = [];

        foreach ($path as $p) {
            if ($p == '..') {
                array_pop($filtered);
            } elseif ($p !== '' && $p !== '.') {
                $filtered[] = $p;
            }
        }

        return $filtered;
    }

    /**
     * Объединяет элементы в строку
     *
     * @param array|string $path
     * @return string
     */
    public function buildPath($path)
    {
        if (is_array($path)) {
            $path = implode($this->pathSeparator, $path);
        }

        return $path;
    }

    /**
     * Нормализирует относительный путь
     *
     * @param string|array $path
     * @return string
     */
    public function normalizePath($path)
    {
        return $this->buildPath($this->filterPath($path));
    }

    /**
     * Возвращает директорию пути
     *
     * @param string|array $path
     * @throws StoreException если путь корневой
     * @return string
     */
    public function dirname($path)
    {
        $path = $this->filterPath($path);

        if (empty($path)) {
            throw new StoreException('корневой каталог');
        }

        array_pop($path);

        return $this->buildPath($path);
    }

    /**
     * Возвращает имя файла из пути
     *
     * @param string|array $path
     * @throws StoreException
     * @return string
     */
    public function basename($path)
    {
        $path = $this->splitPath($path);

        if (empty($path)) {
            throw new StoreException('корневой каталог');
        }

        return (string) array_pop($path);
    }

    /**
     * Строит дочерний путь
     *
     * @param string|array $parent родительский
     * @param string|array $child дочерний
     * @return string
     */
    public function childname($parent, $child)
    {
        $parent = $this->splitPath($parent);
        $child = $this->splitPath($child);

        if (empty($child)) {
            throw new \InvalidArgumentException('child');
        }

        return $this->buildPath($this->filterPath(array_merge($parent, $child)));
    }

    /**
     * Возвращает полный путь
     *
     * @param string|array $path
     * @return string
     */
    abstract public function absolutePath($path);

    /**
     * Возвращает URL файла
     *
     * @param string|array $path
     * @return string|null URL файла
     */
    public function url($path)
    {
        if (empty($this->url)) {
            return null;
        }

        $url = $this->url;

        if (is_array($url)) {
            $url = Url::to($url, true);
        }

        $url = [$url];

        $url = array_merge($url, $this->filterPath($path));

        return implode('/', $url);
    }

    /**
     * Создает объект файла с заданным путем
     *
     * @param string|array $path
     *
     * @return StoreFile
     */
    public function file($path)
    {
        return \Yii::createObject($this->fileConfig, [$this,$path]);
    }

    /**
     * Возвращает список файлов директории
     *
     * @param string|array $path
     * @throws StoreException
     * @param array $filter - bool|null recursive
     *        - string|null $dir - true - только директории, false - толькофайлы
     *        - string|null $public - true - публичный доступ, false - приватный доступ
     *        - bool|null $hidden - true - скрытые файлы, false - открытые
     *        - string|null $regex - регулярная маска имени
     *        - callable|null $filter function(StoreFile $file) : bool филььтр элементов
     * @return StoreFile[]
     */
    abstract public function list($path, array $filter = []);

    /**
     * Проверяет существование файла
     *
     * @param string|array $path
     * @throws StoreException
     * @return boolean
     */
    abstract public function exists($path);

    /**
     * Возвращает флаг директории
     *
     * @param string|array $path
     * @throws StoreException
     * @return boolean
     */
    abstract public function isDir($path);

    /**
     * Возвращает флаг файла
     *
     * @param string|array $path
     * @throws StoreException
     * @return boolean
     */
    abstract public function isFile($path);

    /**
     * Возвращает флаг публичности файла
     *
     * @param string|array $path
     * @throws StoreException
     * @return boolean
     */
    abstract public function isPublic($path);

    /**
     * Устанавливает публичность файла
     *
     * @param string|array $path
     * @param bool $public
     * @throws StoreException
     * @return static
     */
    abstract public function setPublic($path, bool $public);

    /**
     * Возвращает флаг скрытого элемента
     *
     * @param string|array $path
     * @return boolean
     */
    public function isHidden($path)
    {
        $path = $this->normalizePath($path);
        $name = basename($path);
        return mb_substr($name, 0, 1) == '.';
    }

    /**
     * Возвращает размер файла
     *
     * @param string|array $path
     * @throws StoreException
     * @return int
     */
    abstract public function size($path);

    /**
     * Возвращает время изменения
     *
     * @param string|array $path
     * @throws StoreException
     * @return int timestamp
     */
    abstract public function mtime($path);

    /**
     * Возвращает MIME-тип файла
     *
     * @param string|array $path
     * @throws StoreException
     * @return string
     */
    abstract public function mimeType($path);

    /**
     * Возвращает содержимое файла в строке
     *
     * @param string|array $path
     * @throws StoreException
     * @return string
     */
    abstract public function readContents($path);

    /**
     * Записывает содержиме файла из строки
     *
     * @param string|array $path
     * @param string $contents содержимое
     * @throws StoreException
     * @return int размер записанных даннных
     */
    abstract public function writeContents($path, string $contents);

    /**
     * Возвращает открытый поток файла
     *
     * @param string|array $path
     * @throws StoreException
     * @return resource
     */
    abstract public function readStream($path);

    /**
     * Записывает файл из потока
     *
     * @param string|array $path
     * @param resource $stream
     * @throws StoreException
     * @return int кол-во данных
     */
    abstract public function writeStream($path, $stream);

    /**
     * Rename/move file
     *
     * @param string|array $path
     * @param string|array $newpath
     * @throws StoreException
     * @return static
     */
    abstract public function move($path, $newpath);

    /**
     * Copy file
     *
     * @param string|array $path
     * @param string|array $newpath
     * @throws StoreException
     * @return static
     */
    abstract public function copy($path, $newpath);

    /**
     * Создает директорию
     *
     * @param string|array $path
     * @throws StoreException
     * @return static
     */
    abstract public function mkdir($path);

    /**
     * Проверяет/создает директорию
     *
     * @throws StoreException
     * @param string|array $dir return static
     */
    public function checkDir($dir)
    {
        $dir = $this->normalizePath($dir);

        if ($dir != '') {
            if (! $this->exists($dir)) {
                $this->mkdir($dir);
            } elseif (! $this->isDir($dir)) {
                throw new StoreException('not a directory: ' . $dir);
            }
        }

        return $this;
    }

    /**
     * Удаляет файл/директорию
     *
     * @param string|array $path
     * @throws StoreException
     * @return static
     */
    abstract public function delete($path);


    /**
     * Создание превью
     *
     * @param string|array $path
     * @param array $options
     * @throws NotSupportedException
     * @throws StoreException
     * @return \dicr\file\ThumbFile
     * @see Thumbnailer#thumbnail
     */
    public function thumb($path, array $options=[]) {
        $origFile = $this->file($path);
        if ($origFile->path == '') {
            throw new \InvalidArgumentException('path');
        }

        if (!($this->thumbnailer instanceof Thumbnailer)) {
            throw new NotSupportedException('thumbnailer');
        }

        return $this->thumbnailer->thumbnail($origFile, $options);
    }

    /**
     * Защита корневого каталога
     *
     * @param string|array $path
     * @throws StoreException
     * @return string нормалиованный путь
     */
    protected function guardRootPath($path)
    {
        $path = $this->normalizePath($path);
        if ($path === '') {
            throw new StoreException('доступ к корневому каталогу');
        }

        return $path;
    }

    /**
     * Проверяет соответствие файла фильтру.
     *
     * @param StoreFile $file
     * @param array $filter - string|null $dir - true - только директории, false - толькофайлы
     *        - string|null $public - true - публичный доступ, false - приватный доступ
     *        - bool|null $hidden - true - скрытые файлы, false - открытые
     *        - string|null $regex - регулярная маска имени
     *        - callable|null $filter function(StoreFile $file) : bool филььтр элементов
     * @throws StoreException
     * @return boolean
     */
    protected function fileMatchFilter(StoreFile $file, array $filter)
    {

        // фильтруем по типу
        if (isset($filter['dir']) && $file->isDir != $filter['dir']) {
            return false;
        }

        // фильтруем по доступности
        if (isset($filter['public']) && $file->public != $filter['public']) {
            return false;
        }

        // фильтруем скрытые
        if (isset($filter['hidden']) && $file->hidden != $filter['hidden']) {
            return false;
        }

        // фильтруем по регулярному выражению
        if (! empty($filter['regex']) && ! preg_match($filter['regex'], $file->path)) {
            return false;
        }

        // фильтруем по callback
        if (isset($filter['filter']) && is_callable($filter['filter']) && ! call_user_func($filter['filter'], $file)) {
            return false;
        }

        return true;
    }
}
