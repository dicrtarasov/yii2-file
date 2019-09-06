<?php
namespace dicr\file;

use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;
use yii\di\Instance;
use yii\helpers\ArrayHelper;
use yii\helpers\Url;

/**
 * Abstract Fle Store.
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

    /** @var array конфиг для создания файлов */
    public $fileConfig;

    /** @var string path separator */
    public $pathSeparator = DIRECTORY_SEPARATOR;

    /** @var array|\dicr\file\Thumbnailer конфиг thumbnailer для создания превью */
    public $thumbnailer;

    /**
     * {@inheritdoc}
     * @see \yii\base\BaseObject::init()
     */
    public function init()
    {
        parent::init();

        // проверяем URL
        if (is_string($this->url)) {
            $url = \Yii::getAlias($this->url);
            if ($url === false) {
                throw new InvalidConfigException('url: ' . $this->url);
            }

            $this->url = $url;
        }

        // проверяем thumbnailer
        if (isset($this->thumbnailer)) {
            if (is_array($this->thumbnailer)) {
                if (!isset($this->thumbnailer['class'])) {
                    $this->thumbnailer['class'] = Thumbnailer::class;
                }
            }

            $this->thumbnailer = Instance::ensure($this->thumbnailer, Thumbnailer::class);
        }
    }

    /* === Функции для работы с Path ======================================================================== */

    /**
     * Разбивает путь на элементы
     *
     * @param string|string[] $path
     * @return string[]
     */
    public function splitPath($path)
    {
        if (! is_array($path)) {
            $regex = sprintf('~[%s\\\/]+~ui', preg_quote($this->pathSeparator));
            $path = preg_split($regex, $path, - 1, PREG_SPLIT_NO_EMPTY);
        }

        return $path;
    }

    /**
     * Фильтрует путь, заменяя специальные элементы ../
     *
     * @param string|string[] $path
     * @return string[]
     */
    public function filterPath($path)
    {
        $filtered = [];

        $path = $this->splitPath($path);
        foreach ($path as $p) {
            if ($p == '..') {
                if (empty($filtered)) {
                    throw new StoreException('invalid path: ' . $this->buildPath($path));
                }

                array_pop($filtered);
            } elseif ($p !== '' && $p !== '.') {
                $filtered[] = $p;
            }
        }

        return $filtered;
    }

    /**
     * Фильтрует путь и проверяет на доступ к корневому каталогу.
     *
     * @param string|string[] $path
     * @return string[]
     */
    protected function filterRootPath($path)
    {
        $path = $this->filterPath($path);
        if (empty($path)) {
            throw new StoreException('Корневой путь');
        }

        return $path;
    }

    /**
     * Объединяет элементы пути в путь.
     *
     * @param string|string[] $path
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
     * @param string|string[] $path
     * @return string
     */
    public function normalizePath($path)
    {
        return $this->buildPath($this->filterPath($path));
    }

    /**
     * Возвращает директорию пути
     *
     * @param string|string[] $path
     * @throws \dicr\file\StoreException если путь корневой
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
     * Возвращает имя файла из пути.
     *
     * @param string|string[] $path
     * @throws \dicr\file\StoreException если путь корневой
     * @return string
     */
    public function basename($path)
    {
        $path = $this->splitPath($path);
        if (empty($path)) {
            throw new StoreException('корневой каталог');
        }

        return (string)array_pop($path);
    }

    /**
     * Строит дочерний путь.
     *
     * @param string|string[] $parent родительский
     * @param string|string[] $child дочерний
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
     * @param string|string[] $path
     * @return string
     */
    abstract public function absolutePath($path);

    /* === Работа с URL ================================================================= */

    /**
     * Возвращает URL файла
     *
     * @param string|string[] $path
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

        return implode('/', array_merge([$url], $this->filterPath($path)));
    }

    /* === Создание файла и листинг директории ========================================== */

    /**
     * Создает объект файла с заданным путем.
     *
     * @param string|string[] $path
     * @return \dicr\file\StoreFile
     */
    public function file($path)
    {
        // конфиг файла
        $fileConfig = (array)($this->fileConfig ?: []);

        // добавляем класс по-умолчанию
        if (!isset($fileConfig['class'])) {
            $fileConfig['class'] = StoreFile::class;
        }

        // создаем файл
        return \Yii::createObject($fileConfig, [$this, $path]);
    }

    /**
     * Возвращает список файлов директории
     *
     * @param string|string[] $path
     * @throws \dicr\file\StoreException
     * @param array $filter
     *  - bool|null recursive
     *  - string|null $dir - true - только директории, false - только файлы
     *  - string|null $public - true - публичный доступ, false - приватный доступ
     *  - bool|null $hidden - true - скрытые файлы, false - открытые
     *  - string|null $pathRegex - регулярное выражение пути
     *  - string|null $nameRegex - регулярное выражение имени вайла
     *  - callable|null $filter function(StoreFile $file) : bool филььтр элементов
     * @return \dicr\file\StoreFile[]
     */
    abstract public function list($path, array $filter = []);

    /**
     * Импорт файла в хранилище.
     *
     * @param string|string[]|\dicr\file\AbstractFile $src импортируемый файл
     *  Если путь задан строкой или массивом, то считается абсолютным путем локального файла.
     * @param string|string[] $path относительный путь в хранилище для импорта
     * @param array $options опции
     *  - bool $ifModified - импортировать файл только если время новее или размер отличается (по-умолчанию true)
     * @throws \dicr\file\StoreException
     * @return $this
     */
    public function import($src, $path, array $options = [])
    {
        // проверяем аргументы
        if (is_string($src) || is_array($src)) {
            $src = LocalFileStore::root()->file($src);
        } elseif (!($src instanceof AbstractFile)) {
            throw new \InvalidArgumentException('src');
        }

        // пропускаем существующие файлы более новой версии
        try {
            $ifModified = ArrayHelper::getValue($options, 'ifModified', true);
            if ($ifModified && $this->exists($path) && $this->size($path) === $src->size && $this->mtime($path) >= $src->mtime) {
                return $this;
            }
        } catch (NotSupportedException $ex) {
            // не поддерживаемая операция
        } catch (\Throwable $ex) {
            // для удаленых файлов исключения означают неподдерживаемую функцию
            $absPath = $this->absolutePath($path);
            if (stream_is_local($absPath)) {
                throw new StoreException('Ошибка импорта в ' . $absPath, $ex);
            }
        }

        // копируем
        $srcStream = $src->stream;
        try {
            $this->writeStream($path, $srcStream);
        } finally {
            @fclose($srcStream);
        }

        return $this;
    }

    /* === Файловые операции ============================================================== */

    /**
     * Проверяет существование файла.
     *
     * @param string|string[] $path
     * @throws \dicr\file\StoreException
     * @return boolean
     */
    abstract public function exists($path);

    /**
     * Возвращает флаг директории.
     *
     * @param string|string[] $path
     * @throws \dicr\file\StoreException
     * @return bool
     */
    abstract public function isDir($path);

    /**
     * Возвращает флаг файла.
     *
     * @param string|string[] $path
     * @throws \dicr\file\StoreException
     * @return bool
     */
    abstract public function isFile($path);

    /**
     * Возвращает флаг публичности файла.
     *
     * @param string|string[] $path
     * @throws \dicr\file\StoreException
     * @return bool
     */
    abstract public function isPublic($path);

    /**
     * Устанавливает публичность файла.
     *
     * @param string|string[] $path
     * @param bool $public
     * @throws \dicr\file\StoreException
     * @return $this
     */
    abstract public function setPublic($path, bool $public);

    /**
     * Проверяет признак скрытого файла.
     *
     * @param string|string[] $path
     * @return bool
     */
    public function isHidden($path)
    {
        $path = $this->normalizePath($path);
        if (empty($path)) {
            return false;
        }

        return mb_substr($this->basename($path), 0, 1) == '.';
    }

    /**
     * Возвращает размер файла.
     *
     * @param string|string[] $path
     * @throws \dicr\file\StoreException
     * @return int
     */
    abstract public function size($path);

    /**
     * Возвращает время изменения.
     *
     * @param string|string[] $path
     * @throws \dicr\file\StoreException
     * @return int timestamp
     */
    abstract public function mtime($path);

    /**
     * Возвращает MIME-тип файла.
     *
     * @param string|string[] $path
     * @throws \dicr\file\StoreException
     * @return string
     */
    abstract public function mimeType($path);

    /**
     * Возвращает содержимое файла в строке.
     *
     * @param string|string[] $path
     * @throws \dicr\file\StoreException
     * @return string
     */
    abstract public function readContents($path);

    /**
     * Записывает содержиме файла из строки
     *
     * @param string|string[] $path
     * @param string $contents содержимое
     * @throws \dicr\file\StoreException
     * @return int размер записанных даннных
     */
    abstract public function writeContents($path, string $contents);

    /**
     * Возвращает открытый поток файла.
     *
     * @param string|string[] $path
     * @throws \dicr\file\StoreException
     * @return resource
     */
    abstract public function readStream($path);

    /**
     * Записывает файл из потока.
     *
     * @param string|string[] $path
     * @param resource $stream
     * @throws \dicr\file\StoreException
     * @return int кол-во данных
     */
    abstract public function writeStream($path, $stream);

    /**
     * Копирует файл.
     *
     * @param string|string[] $path
     * @param string|string[] $newpath
     * @throws \dicr\file\StoreException
     * @return $this
     */
    public function copy($path, $newpath)
    {
        $stream = $this->readStream($path);
        try {
            $this->writeStream($stream, $newpath);
        } finally {
            @fclose($stream);
        }

        return $this;
    }

    /**
     * Rename/move file
     *
     * @param string|string[] $path
     * @param string|string[] $newpath
     * @throws \dicr\file\StoreException
     * @return $this
     */
    abstract public function rename($path, $newpath);

    /**
     * Создает директорию.
     *
     * @param string|string[] $path
     * @throws \dicr\file\StoreException
     * @return $this
     */
    abstract public function mkdir($path);

    /**
     * Проверяет/создает директорию.
     *
     * @param string|string[] $dir
     * @throws \dicr\file\StoreException
     * @return $this
     */
    public function checkDir($dir)
    {
        $path = $this->filterPath($dir);
        if (!empty($path)) {
            if (! $this->exists($path)) {
                $this->mkdir($path);
            } elseif (! $this->isDir($path)) {
                throw new StoreException('не является директорией: ' . $dir);
            }
        }

        return $this;
    }

    /**
     * Удаляет файл.
     *
     * @param string|string[] $path
     * @throws \dicr\file\StoreException
     * @return $this
     */
    abstract protected function unlink($path);

    /**
     * Удаляет директорию.
     *
     * @param string|string[] $path
     * @throws \dicr\file\StoreException
     * @return $this
     */
    abstract protected function rmdir($path);

    /**
     * Удаляет рекурсивно директорию/файл.
     *
     * @param string|string[] $path
     * @throws \dicr\file\StoreException
     * @return $this
     */
    public function delete($path)
    {
        $path = $this->filterPath($path);
        if (empty($path)) {
            throw new StoreException('корневой каталог');
        }

        if ($this->exists($path)) {
            if ($this->isDir($path)) {
                foreach ($this->list($path) as $file) {
                    $this->delete($file->path);
                }

                $this->rmdir($path);
            } else {
                $this->unlink($path);
            }
        }

        return $this;
    }

    /**
     * Очищает внуренний кэш файлов PHP.
     *
     * @param string|string[] $path относительный путь
     */
    public function clearStatCache($path)
    {
        @clearstatcache(null, $this->absolutePath($path));
    }

    /**
     * Проверяет соответствие файла фильтру.
     *
     * @param \dicr\file\StoreFile $file
     * @param array $filter
     *     - string|null $dir - true - только директории, false - толькофайлы
     *     - string|null $public - true - публичный доступ, false - приватный доступ
     *     - bool|null $hidden - true - скрытые файлы, false - открытые
     *     - string|null $pathRegex - регулярное выражение пути
     *     - string|null $nameRegex - регулярное выражение имени вайла
     *     - callable|null $filter function(StoreFile $file) : bool филььтр элементов
     * @throws StoreException
     * @return boolean
     */
    protected function fileMatchFilter(StoreFile $file, array $filter)
    {
        // ---- вначале быстрые фильтры --------

        // фильтруем по регулярному выражению пути
        if (! empty($filter['pathRegex']) && ! preg_match($filter['pathRegex'], $file->path)) {
            return false;
        }

        // фильтр по регулярному выражению имени
        if (! empty($filter['nameRegex']) && ! preg_match($filter['nameRegex'], $file->name)) {
            return false;
        }

        // фильтруем по callback
        if (isset($filter['filter']) && is_callable($filter['filter']) && ! call_user_func($filter['filter'], $file)) {
            return false;
        }

        // ----- медленные фильтры

        // фильтруем по типу
        if (isset($filter['dir']) && (boolval($file->isDir) != boolval($filter['dir']))) {
            return false;
        }

        // фильтруем по доступности
        if (isset($filter['public']) && (boolval($file->public) != boolval($filter['public']))) {
            return false;
        }

        // фильтруем скрытые
        if (isset($filter['hidden']) && boolval($file->hidden) != boolval($filter['hidden'])) {
            return false;
        }

        return true;
    }

    /**
     * Выбрасывает исключение с последней ошибкой.
     *
     * @param string $op операция
     * @param string $absPath путь файла
     * @throws StoreException
     */
    protected function throwLastError(string $op = '', string $absPath = '')
    {
        $messages = [];

        // добавляем операцию
        if (!empty($op)) {
            $messages[] = $op;
        }

        if (!empty($absPath)) {
            $messages[] = $absPath;
        }

        // добавляем последнюю ошибку
        $err = @error_get_last();
        @error_clear_last();
        if (!empty($err['message'])) {
            $messages[] = $err['message'];
        }

        // выбрасываем исключеие
        throw new StoreException(implode(': ', $messages));
    }
}
