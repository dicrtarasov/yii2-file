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

    /** @var string|array конфиг создания файлов */
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
            $this->url = \Yii::getAlias(rtrim($this->url, '/'));
            if ($this->url === false) {
                throw new InvalidConfigException('url');
            }
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
     * Нормализирует относительный путь
     *
     * @param string $path
     * @return string
     */
    public function normalizeRelativePath(string $path) {
        return trim($path, $this->pathSeparator);
    }

    /**
     * Возвращает полный путь
     *
     * @param string $path
     * @return string
     */
    abstract public function getFullPath(string $path);

    /**
     * {@inheritDoc}
     * @see \dicr\file\FileStoreInterface::file()
     */
    public function file(string $path)
    {
        return \Yii::createObject(array_merge($this->fileConfig, [
            'store' => $this,
            'path' => $path
        ]));
    }

    /**
     * Возвращает дочерний элемент относительно path
     *
     * @param string $path относительный путь
     * @param string $child дочерний относительный путь
     * @throws StoreException
     * @return \dicr\file\File
     */
    public function child(string $path, string $child)
    {
        $relpath = [];

        $path = $this->normalizeRelativePath($path);
        if ($path !== '') {
            $relpath[] = $path;
        }

        $child = $this->normalizeRelativePath($child);
        if ($child !== '') {
            $relpath[] = $child;
        }

        return $this->file(implode($this->pathSeparator, $relpath));
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
        if (isset($filter['hidden']) && $file->hidden != $filter['hidden']) {
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
    abstract public function list(string $path, array $options=[]);

    /**
     * Возвращает URL файла
     *
     * @param string $path
     * @return string|null URL файла
     */
    public function getUrl(string $path) {
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

        $path = trim($path, '\\/');
        if ($path !== '') {
            $url[] = $path;
        }

        return implode('/', $url);
    }

    /**
     * Проверяет существование файла
     *
     * @param string $path
     * @throws StoreException
     * @return boolean
     */
    abstract public function isExists(string $path);

    /**
     * Возвращает тип файла
     *
     * @param string $path
     * @throws StoreException
     * @return string File::TYPE_*
     */
    abstract public function getType(string $path);

    /**
     * Возвращает доступность файла (публичная приватная)
     *
     * @param string $path
     * @throws StoreException
     * @return string File::ACCESS_TYPE
     */
    abstract public function getAccess(string $path);

    /**
     * Устанавливает права доступа
     *
     * @param string $path
     * @param string $access File::ACCESS_*
     * @throws StoreException
     * @return static
     */
    abstract public function setAccess(string $path, string $access);

    /**
     * Возвращает флаг скрытого элемента
     *
     * @param string $path
     * @return boolean
     */
    public function isHidden(string $path)
    {
        $name = basename($path);
        return mb_substr($name, 0, 1) == '.';
    }

    /**
     * Возвращает размер файла
     *
     * @param string $path
     * @throws StoreException
     * @return int
     */
    abstract public function getSize(string $path);

    /**
     * Возвращает время изменения
     *
     * @param string $path
     * @throws StoreException
     * @return int timestamp
     */
    abstract public function getMtime(string $path);

    /**
     * Возвращает MIME-тип файла
     *
     * @param string $path
     * @throws StoreException
     * @return string
     */
    abstract public function getMimeType(string $path);

    /**
     * Rename/move file
     *
     * @param string $path
     * @param string $newpath
     * @throws StoreException
     * @return static
     */
    abstract public function move(string $path, string $newpath);

    /**
     * Copy file
     *
     * @param string $path
     * @param string $newpath
     * @throws StoreException
     * @return static
     */
    abstract public function copy(string $path, string $newpath);

    /**
     * Возвращает содержимое файла в строке
     *
     * @param string $path
     * @throws StoreException
     * @return string
     */
    abstract public function getContents(string $path);

    /**
     * Записывает содержиме файла из строки
     *
     * @param string $path
     * @param string $contents содержимое
     * @throws StoreException
     * @return int размер записанных даннных
     */
    abstract public function setContents(string $path, string $contents);

    /**
     * Возвращает открытый поток файла
     *
     * @param string $path
     * @throws StoreException
     * @return resource
     */
    abstract public function getStream(string $path);

    /**
     * Записывает файл из потока
     *
     * @param string $path
     * @param resource $stream
     * @throws StoreException
     * @return int кол-во данных
     */
    abstract public function setStream(string $path, $stream);

    /**
     * Создает директорию
     *
     * @param string $path
     * @throws StoreException
     * @return static
     */
    abstract public function mkdir(string $path);

    /**
     * Проверяет/создает директорию
     *
     * @throws StoreException
     * @param string $dir
     * return static
     */
    public function checkDir(string $dir) {
        if ($dir !== '' && !$this->isExists($dir)) {
            $this->mkdir($dir);
        }
        return $this;
    }

    /**
     * Удаляет файл/директорию
     *
     * @param string $path
     * @throws StoreException
     * @return static
     */
    abstract public function delete(string $path);
}
