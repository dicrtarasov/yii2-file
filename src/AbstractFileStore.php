<?php
/**
 * @copyright 2019-2020 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL
 * @version 26.07.20 07:50:28
 */

/** @noinspection PhpUsageOfSilenceOperatorInspection */
declare(strict_types = 1);

namespace dicr\file;

use InvalidArgumentException;
use Throwable;
use Yii;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\helpers\ArrayHelper;
use yii\helpers\Url;
use function call_user_func;
use function implode;
use function is_array;
use function is_callable;
use function is_string;
use function usort;

/**
 * Abstract Fle Store.
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

    /** @var array|null конфиг файлов превью картинок ThumbFile */
    public $thumbFileConfig;

    /**
     * @inheritdoc
     * @throws InvalidConfigException
     */
    public function init() : void
    {
        parent::init();

        // проверяем URL
        if (is_string($this->url)) {
            $url = Yii::getAlias($this->url);
            if ($url === false) {
                throw new InvalidConfigException('url: ' . $this->url);
            }

            $this->url = (string)$url;
        }

        // проверяем thumbFileConfig
        if (! empty($this->thumbFileConfig) && ! is_array($this->thumbFileConfig)) {
            throw new InvalidConfigException('thumbFileConfig');
        }
    }

    /* === Функции для работы с Path ======================================================================== */

    /**
     * Возвращает директорию пути
     *
     * @param string|string[] $path
     * @return string
     * @throws StoreException если путь корневой
     */
    public function dirname($path) : string
    {
        $path = $this->filterPath($path);
        if (empty($path)) {
            throw new StoreException('корневой каталог');
        }

        array_pop($path);
        return $this->buildPath($path);
    }

    /**
     * Фильтрует путь, заменяя специальные элементы ../
     *
     * @param string|string[] $path
     * @return string[]
     * @throws StoreException
     */
    public function filterPath($path) : array
    {
        $filtered = [];

        $path = $this->splitPath($path);
        foreach ($path as $p) {
            if ($p === '..') {
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
     * Разбивает путь на элементы
     *
     * @param string|string[] $path
     * @return string[]
     */
    public function splitPath($path) : array
    {
        if (! is_array($path)) {
            $regex = sprintf('~[%s\\\/]+~ui', preg_quote($this->pathSeparator, '~'));
            $path = (array)preg_split($regex, $path, - 1, PREG_SPLIT_NO_EMPTY);
        }

        return (array)$path;
    }

    /**
     * Объединяет элементы пути в путь.
     *
     * @param string|string[] $path
     * @return string
     */
    public function buildPath($path) : string
    {
        return is_array($path) ? implode($this->pathSeparator, $path) : (string)$path;
    }

    /**
     * Строит дочерний путь.
     *
     * @param string|string[] $parent родительский
     * @param string|string[] $child дочерний
     * @return string
     * @throws StoreException
     */
    public function childname($parent, $child) : string
    {
        $parent = $this->splitPath($parent);

        $child = $this->splitPath($child);
        if (empty($child)) {
            throw new InvalidArgumentException('child');
        }

        return $this->buildPath($this->filterPath(array_merge($parent, $child)));
    }

    /**
     * Возвращает URL файла
     *
     * @param string|string[] $path
     * @return string|null URL файла
     * @throws StoreException
     */
    public function url($path) : ?string
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

    /**
     * Импорт файла в хранилище.
     *
     * @param string|string[]|AbstractFile $src импортируемый файл
     *  Если путь задан строкой или массивом, то считается абсолютным путем локального файла.
     * @param string|string[] $path относительный путь в хранилище для импорта
     * @param array $options опции
     *  - bool $ifModified - импортировать файл только если время новее или размер отличается (по-умолчанию true)
     * @return $this
     * @throws InvalidConfigException
     * @throws StoreException
     */
    public function import($src, $path, array $options = []) : self
    {
        // проверяем аргументы
        if (is_string($src) || is_array($src)) {
            $src = LocalFileStore::root()->file($src);
        }

        // пропускаем существующие файлы более новой версии
        try {
            $ifModified = ArrayHelper::getValue($options, 'ifModified', true);
            if ($ifModified && $this->exists($path) && $this->size($path) === $src->size &&
                $this->mtime($path) >= $src->mtime) {
                return $this;
            }
        } catch (Throwable $ex) {
            // для удаленных файлов исключения означают неподдерживаемую функцию
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
            /** @scrutinizer ignore-unhandled */
            @fclose($srcStream);
        }

        return $this;
    }

    /**
     * Создает объект файла с заданным путем.
     *
     * @param string|string[] $path
     * @return StoreFile
     * @throws InvalidConfigException
     */
    public function file($path) : StoreFile
    {
        // конфиг файла
        $fileConfig = $this->fileConfig ?: [];

        // добавляем класс по-умолчанию
        if (! isset($fileConfig['class'])) {
            $fileConfig['class'] = StoreFile::class;
        }

        // создаем файл
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return Yii::createObject($fileConfig, [$this, $path]);
    }

    /**
     * Проверяет существование файла.
     *
     * @param string|string[] $path
     * @return bool
     * @throws StoreException
     */
    abstract public function exists($path) : bool;

    /* === Создание файла и листинг директории ========================================== */

    /**
     * Возвращает размер файла.
     *
     * @param string|string[] $path
     * @return int
     * @throws StoreException
     */
    abstract public function size($path) : int;

    /**
     * Возвращает время изменения.
     *
     * @param string|string[] $path
     * @return int timestamp
     * @throws StoreException
     */
    abstract public function mtime($path) : int;

    /**
     * Возвращает полный путь
     *
     * @param string|string[] $path
     * @return string
     */
    abstract public function absolutePath($path) : string;

    /**
     * Записывает файл из потока.
     *
     * @param string|string[] $path
     * @param resource $stream
     * @return int кол-во данных
     * @throws StoreException
     */
    abstract public function writeStream($path, $stream) : int;

    /* === Файловые операции ============================================================== */

    /**
     * Возвращает флаг файла.
     *
     * @param string|string[] $path
     * @return bool
     * @throws StoreException
     */
    abstract public function isFile($path) : bool;

    /**
     * Возвращает флаг публичности файла.
     *
     * @param string|string[] $path
     * @return bool
     * @throws StoreException
     */
    abstract public function isPublic($path) : bool;

    /**
     * Устанавливает публичность файла.
     *
     * @param string|string[] $path
     * @param bool $public
     * @return $this
     * @throws StoreException
     */
    abstract public function setPublic($path, bool $public) : self;

    /**
     * Проверяет признак скрытого файла.
     *
     * @param string|string[] $path
     * @return bool
     * @throws StoreException
     */
    public function isHidden($path) : bool
    {
        $path = $this->normalizePath($path);
        if (empty($path)) {
            return false;
        }

        return mb_strpos($this->basename($path), '.') === 0;
    }

    /**
     * Нормализует относительный путь
     *
     * @param string|string[] $path
     * @return string
     * @throws StoreException
     */
    public function normalizePath($path) : string
    {
        return $this->buildPath($this->filterPath($path));
    }

    /**
     * Возвращает имя файла из пути.
     *
     * @param string|string[] $path
     * @return string
     * @throws StoreException если путь корневой
     */
    public function basename($path) : string
    {
        $path = $this->splitPath($path);
        if (empty($path)) {
            throw new StoreException('корневой каталог');
        }

        return (string)array_pop($path);
    }

    /**
     * Возвращает MIME-тип файла.
     *
     * @param string|string[] $path
     * @return string
     * @throws StoreException
     */
    abstract public function mimeType($path) : string;

    /**
     * Возвращает содержимое файла в строке.
     *
     * @param string|string[] $path
     * @return string
     * @throws StoreException
     */
    abstract public function readContents($path) : string;

    /**
     * Записывает содержимое файла из строки
     *
     * @param string|string[] $path
     * @param string $contents содержимое
     * @return int размер записанных данных
     * @throws StoreException
     */
    abstract public function writeContents($path, string $contents) : int;

    /**
     * Копирует файл.
     *
     * @param string|string[] $path
     * @param string|string[] $newpath
     * @return $this
     * @throws StoreException
     */
    public function copy($path, $newpath) : self
    {
        $stream = $this->readStream($path);
        try {
            $this->writeStream($newpath, $stream);
        } finally {
            /** @scrutinizer ignore-unhandled */
            @fclose($stream);
        }

        return $this;
    }

    /**
     * Возвращает открытый поток файла.
     *
     * @param string|string[] $path
     * @return resource
     * @throws StoreException
     */
    abstract public function readStream($path);

    /**
     * Rename/move file
     *
     * @param string|string[] $path
     * @param string|string[] $newpath
     * @return $this
     * @throws StoreException
     */
    abstract public function rename($path, $newpath) : self;

    /**
     * Проверяет/создает директорию.
     *
     * @param string|string[] $dir
     * @return $this
     * @throws StoreException
     */
    public function checkDir($dir) : self
    {
        $path = $this->filterPath($dir);
        if (! empty($path)) {
            if (! $this->exists($path)) {
                $this->mkdir($path);
            } elseif (! $this->isDir($path)) {
                throw new StoreException('не является директорией: ' . $this->absolutePath($path));
            }
        }

        return $this;
    }

    /**
     * Создает директорию.
     *
     * @param string|string[] $path
     * @return $this
     * @throws StoreException
     */
    abstract public function mkdir($path) : self;

    /**
     * Возвращает флаг директории.
     *
     * @param string|string[] $path
     * @return bool
     * @throws StoreException
     */
    abstract public function isDir($path) : bool;

    /**
     * Удаляет рекурсивно директорию/файл.
     *
     * @param string|string[] $path
     * @return $this
     * @throws StoreException
     */
    public function delete($path) : self
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
     * Возвращает список файлов директории
     *
     * @param string|string[] $path
     * @param array $filter
     *  - bool|null recursive
     *  - string|null $dir - true - только директории, false - только файлы
     *  - string|null $public - true - публичный доступ, false - приватный доступ
     *  - bool|null $hidden - true - скрытые файлы, false - открытые
     *  - string|null $pathRegex - регулярное выражение пути
     *  - string|null $nameRegex - регулярное выражение имени файла
     *  - callable|null $filter function(StoreFile $file) : bool фильтр элементов
     * @return StoreFile[]
     * @throws StoreException
     */
    abstract public function list($path, array $filter = []) : array;

    /**
     * Удаляет директорию.
     *
     * @param string|string[] $path
     * @return $this
     * @throws StoreException
     */
    abstract protected function rmdir($path) : self;

    /**
     * Удаляет файл.
     *
     * @param string|string[] $path
     * @return $this
     * @throws StoreException
     */
    abstract protected function unlink($path) : self;

    /**
     * Очищает внутренний кэш файлов PHP.
     *
     * @param string|string[] $path относительный путь
     * @return $this
     */
    public function clearStatCache($path) : self
    {
        /** @scrutinizer ignore-unhandled */
        @clearstatcache(true, $this->absolutePath($path));
        return $this;
    }

    /**
     * Создает файл предпросмотра картинки.
     *
     * @param string|array|StoreFile $file
     * @param array $config
     * @return ThumbFile|null превью
     * - если thumbFileConfig не настроен, то false
     * - если файл не существует и не задан noimage, то null
     * @throws InvalidConfigException
     * @throws StoreException
     */
    public function thumb($file, array $config = []) : ?ThumbFile
    {
        // проверяем аргументы
        if (empty($file)) {
            throw new InvalidArgumentException('file');
        }

        if (! $file instanceof StoreFile) {
            $file = $this->file($file);
        }

        // создаем превью
        $thumb = $this->createThumb(array_merge($config, [
            'source' => $file
        ]));

        if (empty($thumb)) {
            return null;
        }

        // если файл не существует и не задан noimage, то возвращаем null
        if (! $file->exists && empty($thumb->noimage)) {
            return null;
        }

        // обновляем/создаем файл превью
        if (! $thumb->isReady) {
            $thumb->update();
        }

        return $thumb;
    }

    /**
     * Создает ThumbFile.
     *
     * @param array $config
     * @return ThumbFile ThumbFile
     * @throws InvalidConfigException
     */
    protected function createThumb(array $config = []) : ThumbFile
    {
        if (empty($this->thumbFileConfig)) {
            throw new InvalidConfigException('ThumbFile для создания превью не настроен');
        }

        // устанавливаем парамеры по-умолчанию
        $config = array_merge([
            'noimage' => false,
            'watermark' => false, // по-умолчанию не создавать watermark
            'disclaimer' => false // по-умолчанию не применять disclaimer
        ], $config);

        // удаляем из параметров значения true, чтобы не перезаписывать конфиг по-умолчанию
        foreach (['noimage', 'watermark', 'disclaimer'] as $field) {
            if ($config[$field] === true) {
                unset($config[$field]);
            }
        }

        // добавляем конфиг по-умолчанию
        $config = array_merge($this->thumbFileConfig ?: [], $config);

        // добавляем класс по-умолчанию
        if (empty($config['class'])) {
            $config['class'] = ThumbFile::class;
        }

        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return Yii::createObject($config);
    }

    /**
     * Возвращает превью для noimage.
     *
     * @param array $config конфиг превью.
     * @return ThumbFile
     * @throws InvalidConfigException
     * @throws StoreException
     */
    public function noimage(array $config = []) : ThumbFile
    {
        // создаем превью для пустого файла
        $thumb = $this->createThumb(array_merge([
            'noimage' => true
        ], $config, [
            'source' => null,
        ]));

        // если превью настроено, то обновляем файл
        if (! empty($thumb) && ! $thumb->isReady) {
            $thumb->update();
        }

        return $thumb;
    }

    /**
     * Очищает превью для заданного файла.
     *
     * @param string|array|StoreFile $file
     * @return $this
     * @throws StoreException
     * @throws StoreException
     * @throws InvalidConfigException
     */
    public function clearThumb($file) : self
    {
        // проверяем аргументы
        if (empty($file)) {
            throw new InvalidArgumentException('file');
        }

        if (! $file instanceof StoreFile) {
            $file = $this->file($file);
        }

        // создаем ThumbFile
        $thumb = $this->createThumb([
            'source' => $file
        ]);

        // если настроен, то очищаем все файлы превью
        if (! empty($thumb)) {
            $thumb->clear();
        }

        return $this;
    }

    /**
     * Фильтрует путь и проверяет на доступ к корневому каталогу.
     *
     * @param string|string[] $path
     * @return string[]
     * @throws StoreException
     */
    protected function filterRootPath($path) : array
    {
        $path = $this->filterPath($path);
        if (empty($path)) {
            throw new StoreException('Корневой путь');
        }

        return $path;
    }

    /**
     * Проверяет соответствие файла фильтру.
     *
     * @param StoreFile $file
     * @param array $filter
     *     - string|null $dir - true - только директории, false - только файлы
     *     - string|null $public - true - публичный доступ, false - приватный доступ
     *     - bool|null $hidden - true - скрытые файлы, false - открытые
     *     - string|null $pathRegex - регулярное выражение пути
     *     - string|null $nameRegex - регулярное выражение имени файла
     *     - callable|null $filter function(StoreFile $file) : bool фильтр элементов
     * @return bool
     */
    protected function fileMatchFilter(StoreFile $file, array $filter) : bool
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
        if (isset($filter['dir']) && (bool)$file->isDir !== (bool)$filter['dir']) {
            return false;
        }

        // фильтруем по доступности
        if (isset($filter['public']) && (bool)$file->public !== (bool)$filter['public']) {
            return false;
        }

        // фильтруем скрытые
        if (isset($filter['hidden']) && (bool)$file->hidden !== (bool)$filter['hidden']) {
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
    protected function throwLastError(string $op = '', string $absPath = '') : void
    {
        $messages = [];

        // добавляем операцию
        if (! empty($op)) {
            $messages[] = $op;
        }

        if (! empty($absPath)) {
            $messages[] = $absPath;
        }

        // добавляем последнюю ошибку
        $err = @error_get_last();
        /** @scrutinizer ignore-unhandled */
        @error_clear_last();
        if (! empty($err['message'])) {
            $messages[] = $err['message'];
        }

        // выбрасываем исключение
        throw new StoreException(implode(': ', $messages));
    }

    /**
     * Сортировка файлов по имени.
     *
     * @param StoreFile[] $files
     * @return StoreFile[]
     */
    protected static function sortByName(array $files) : array
    {
        usort($files, static function(StoreFile $a, StoreFile $b) {
            return $a->path <=> $b->path;
        });

        return $files;
    }
}
