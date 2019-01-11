<?php
namespace dicr\file;

use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\helpers\Url;

/**
 * Abstract Fle Store
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
 */
abstract class AbstractFileStore extends Component implements FileStoreInterface {

    /** @var string|array $url */
    public $url;

    /** @var string режим доступа создаваемых файлов */
    public $access = File::ACCESS_PUBLIC;

    /** @var array конфиг создания файлов */
    public $fileConfig = [
        'class' => File::class
    ];

    /** @var string path separator */
    public $pathSeparator = DIRECTORY_SEPARATOR;

    /**
     * {@inheritDoc}
     * @see \yii\base\BaseObject::init()
     */
    public function init() {

        if (is_string($this->url)) {
            $url = \Yii::getAlias(rtrim($this->url, '/'));
            if (!is_string($url)) {
                throw new InvalidConfigException('url');
            }

            $this->url = $url;
        }

        if (empty($this->fileConfig)) {
            $this->fileConfig = [];
        } elseif (is_string($this->fileConfig)) {
            $this->fileConfig = [
                'class' => $this->fileConfig
            ];
        } elseif (!is_array($this->fileConfig)) {
            throw new InvalidConfigException('fileConfig');
        }

        if (!isset($this->fileConfig['class'])) {
            $this->fileConfig['class'] = File::class;
        }

        parent::init();
    }

    /**
     * Разбивает путь на элементы
     *
     * @param string|array $path
     * @return array
     */
    protected function splitPath($path) {
        if (!is_array($path)) {
            $regex = sprintf('~[%s\\\/]+~uism', preg_quote($this->pathSeparator));
            $path = preg_split($regex, $path, -1, PREG_SPLIT_NO_EMPTY);
        }

        return $path;
    }

    /**
     * Нормализирует относительный путь
     *
     * @param string|array $path
     * @return string
     */
    public function normalizeRelativePath($path) {
        $path = $this->splitPath($path);

        $relpath = [];
        foreach ($path as $p) {
            if ($p == '..') {
                array_pop($relpath);
            } elseif ($p !== '' && $p !== '.') {
                $relpath[] = $p;
            }
        }

        return implode($this->pathSeparator, $relpath);
    }

    /**
     * Возвращает полный путь
     *
     * @param string|array $path
     * @return string
     */
    abstract public function getFullPath($path);

    /**
     * {@inheritDoc}
     * @see \dicr\file\FileStoreInterface::file()
     */
    public function file($path)
    {
        return \Yii::createObject(array_merge($this->fileConfig, [
            'store' => $this,
            'path' => $path
        ]));
    }

    /**
     * Возвращает дочерний элемент относительно path
     *
     * @param string|array $path относительный путь
     * @param string|array $child дочерний относительный путь
     * @throws StoreException
     * @return \dicr\file\File
     */
    public function child($path, $child)
    {
        $path = $this->splitPath($path);
        $path = array_merge($path, $this->splitPath($child));
        $path = $this->normalizeRelativePath($path);

        return $this->file($path);
    }

    /**
     * Проверяет соответствие файла фильтру.
     *
     * @param File $file
     * @param array $filter
     * - string|null $type - фильтр типа элементов (File::TYPE_*)
     * - string|null $access - фильтр доступности (File::ACCESS_*)
     * - bool|null $hidden - возвращать скрытые (начинающиеся с точки)
     * - string|null $regex - регулярная маска имени
     * - callable|null $filter function(string $item) : bool филььтр элементов
     * @throws \dicr\file\StoreException
     * @return boolean
     */
    protected function fileMatchFilter(File $file, array $filter) {

        // фильтруем по типу
        if (!empty($filter['type']) && $file->type != $filter['type']) {
            return false;
        }

        // фильтруем по доступности
        if (!empty($filter['access']) && $file->access != $filter['access']) {
            return false;
        }

        // фильтруем скрытые
        if (isset($filter['hidden']) && $file->isHidden != $filter['hidden']) {
            return false;
        }

        // фильтруем по регулярному выражению
        if (!empty($filter['regex']) && !preg_match($filter['regex'], $file->path)) {
            return false;
        }

        // фильтруем по callback
        if (isset($filter['filter']) && is_callable($filter['filter']) && !call_user_func($filter['filter'], $file)) {
            return false;
        }

        return true;
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\FileStoreInterface::list()
     */
    abstract public function list($path, array $options=[]);

    /**
     * Возвращает URL файла
     *
     * @param string|array $path
     * @return string|null URL файла
     */
    public function getUrl($path) {
        if (empty($this->url)) {
            return null;
        }

        $url = $this->url;
        if (is_array($url)) {
            $url = Url::to($url, true);
        }

        $url = [
            $url
        ];

        $path = $this->normalizeRelativePath($path);
        $url = array_merge($url, $this->splitPath($path));

        return implode('/', $url);
    }

    /**
     * Проверяет существование файла
     *
     * @param string|array $path
     * @throws StoreException
     * @return boolean
     */
    abstract public function isExists($path);

    /**
     * Возвращает тип файла
     *
     * @param string|array $path
     * @throws StoreException
     * @return string File::TYPE_*
     */
    abstract public function getType($path);

    /**
     * Возвращает доступность файла (публичная приватная)
     *
     * @param string|array $path
     * @throws StoreException
     * @return string File::ACCESS_TYPE
     */
    abstract public function getAccess($path);

    /**
     * Устанавливает права доступа
     *
     * @param string|array $path
     * @param string $access File::ACCESS_*
     * @throws StoreException
     * @return static
     */
    abstract public function setAccess($path, string $access);

    /**
     * Возвращает флаг скрытого элемента
     *
     * @param string|array $path
     * @return boolean
     */
    public function isHidden($path)
    {
        $path = $this->normalizeRelativePath($path);
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
    abstract public function getSize($path);

    /**
     * Возвращает время изменения
     *
     * @param string|array $path
     * @throws StoreException
     * @return int timestamp
     */
    abstract public function getMtime($path);

    /**
     * Возвращает MIME-тип файла
     *
     * @param string|array $path
     * @throws StoreException
     * @return string
     */
    abstract public function getMimeType($path);

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
     * Возвращает содержимое файла в строке
     *
     * @param string|array $path
     * @throws StoreException
     * @return string
     */
    abstract public function getContents($path);

    /**
     * Записывает содержиме файла из строки
     *
     * @param string|array $path
     * @param string $contents содержимое
     * @throws StoreException
     * @return int размер записанных даннных
     */
    abstract public function setContents($path, string $contents);

    /**
     * Возвращает открытый поток файла
     *
     * @param string|array $path
     * @throws StoreException
     * @return resource
     */
    abstract public function getStream($path);

    /**
     * Записывает файл из потока
     *
     * @param string|array $path
     * @param resource $stream
     * @throws StoreException
     * @return int кол-во данных
     */
    abstract public function setStream($path, $stream);

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
     * @param string|array $dir
     * return static
     */
    public function checkDir($dir) {
        $dir = $this->normalizeRelativePath($dir);

        if ($dir !== '' && !$this->isExists($dir)) {
            $this->mkdir($dir);
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
}
