<?php
/*
 * @copyright 2019-2021 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL-3.0-or-later
 * @version 14.05.21 12:06:33
 */

declare(strict_types = 1);
namespace dicr\file;

use Throwable;
use Yii;
use yii\base\Component;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;
use yii\helpers\ArrayHelper;
use yii\helpers\Url;

use function array_merge;
use function array_pop;
use function call_user_func;
use function fclose;
use function implode;
use function is_array;
use function is_callable;
use function is_string;
use function rtrim;
use function stream_is_local;
use function usort;

use const DIRECTORY_SEPARATOR;

/**
 * Abstract Fle Store.
 */
abstract class FileStore extends Component
{
    /** @var string path separator */
    public $pathSeparator = DIRECTORY_SEPARATOR;

    /** @var string|array|null $url базовый URL хранилища */
    public $url;

    /** @var bool публичный доступ к файлам */
    public $public = true;

    /** @var ?array конфиг для создания файлов */
    public $fileConfig;

    /** @var ?array конфиг файлов превью картинок ThumbFile */
    public $thumbFileConfig;

    /**
     * @inheritdoc
     * @throws InvalidConfigException
     */
    public function init(): void
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
    }

    /**
     * Разбивает путь на элементы.
     *
     * @param string|string[] $path
     * @return string[]
     */
    public function splitPath($path): array
    {
        if (! is_array($path)) {
            if ($path === '' || $path === $this->pathSeparator) {
                $path = [];
            } else {
                $path = (array)explode(
                    $this->pathSeparator, trim((string)$path, $this->pathSeparator)
                );
            }
        }

        return $path;
    }

    /**
     * Фильтрует путь, заменяя специальные элементы "../".
     *
     * @param string|string[] $path
     * @return string[]
     */
    public function filterPath($path): array
    {
        $filtered = [];

        foreach ($this->splitPath($path) as $p) {
            if ($p === '..') {
                if (empty($filtered)) {
                    throw new InvalidArgumentException('Invalid path: ' . $this->buildPath($path));
                }

                array_pop($filtered);
            } elseif ($p !== '' && $p !== '.') {
                $filtered[] = $p;
            }
        }

        return $filtered;
    }

    /**
     * Объединяет элементы пути в путь.
     *
     * @param string|string[] $path
     * @return string
     */
    public function buildPath($path): string
    {
        if (is_array($path)) {
            $path = implode($this->pathSeparator, $path);
        }

        return (string)$path;
    }

    /**
     * Нормализует относительный путь
     *
     * @param string|string[] $path
     * @return string
     */
    public function normalizePath($path): string
    {
        return $this->buildPath($this->filterPath($path));
    }

    /**
     * Возвращает полный путь
     *
     * @param string|string[] $path
     * @return string
     */
    abstract public function absolutePath($path): string;

    /**
     * Возвращает URL файла.
     *
     * @param string|string[] $path
     * @return string URL файла
     */
    public function url($path): ?string
    {
        if ($this->url === null) {
            return null;
        }

        if (is_array($this->url)) {
            $this->url = Url::to($this->url);
        }

        return rtrim($this->url, '/') . '/' . $this->normalizePath($path);
    }

    /**
     * Возвращает директорию пути
     *
     * @param string|string[] $path
     * @return string
     */
    public function dirname($path): string
    {
        $path = $this->filterRootPath($path);
        array_pop($path);

        return $this->buildPath($path);
    }

    /**
     * Возвращает имя файла из пути.
     *
     * @param string|string[] $path
     * @return string
     */
    public function basename($path): string
    {
        $path = $this->filterRootPath($path);

        return (string)array_pop($path);
    }

    /**
     * Строит дочерний путь.
     *
     * @param string|string[] $parent родительский
     * @param string|string[] $child дочерний
     * @return string
     */
    public function childname($parent, $child): string
    {
        $parent = $this->splitPath($parent);

        $child = $this->splitPath($child);
        if (empty($child)) {
            throw new InvalidArgumentException('child');
        }

        return $this->normalizePath(array_merge($parent, $child));
    }

    /**
     * Проверяет существование файла.
     *
     * @param string|string[] $path
     * @return bool
     * @throws StoreException
     */
    abstract public function exists($path): bool;

    /**
     * Возвращает флаг файла.
     *
     * @param string|string[] $path
     * @return bool
     * @throws StoreException
     */
    abstract public function isFile($path): bool;

    /**
     * Возвращает флаг директории.
     *
     * @param string|string[] $path
     * @return bool
     * @throws StoreException
     */
    abstract public function isDir($path): bool;

    /**
     * Создает директорию.
     *
     * @param string|string[] $path
     * @return $this
     * @throws StoreException
     */
    abstract public function mkdir($path): self;

    /**
     * Проверяет/создает директорию.
     *
     * @param string|string[] $dir
     * @return $this
     * @throws StoreException
     */
    public function checkDir($dir): self
    {
        $path = $this->filterPath($dir);
        if (! empty($path)) {
            if (! $this->exists($path)) {
                $this->mkdir($path);
            } elseif (! $this->isDir($path)) {
                throw new StoreException('Не является директорией: ' . $this->absolutePath($path));
            }
        }

        return $this;
    }

    /**
     * Возвращает размер файла.
     *
     * @param string|string[] $path
     * @return int
     * @throws StoreException
     */
    abstract public function size($path): int;

    /**
     * Возвращает MIME-тип файла.
     *
     * @param string|string[] $path
     * @return string
     * @throws StoreException
     */
    abstract public function mimeType($path): string;

    /**
     * Возвращает время изменения.
     *
     * @param string|string[] $path
     * @return int timestamp
     * @throws StoreException
     */
    abstract public function mtime($path): int;

    /**
     * Обновляет время модификации файла.
     *
     * @param string[]|string $path
     * @param ?int $time время, если не задано, то time()
     * @throws StoreException
     */
    public function touch($path, ?int $time = null): void
    {
        throw new StoreException('', new NotSupportedException('touch'));
    }

    /**
     * Возвращает флаг публичности файла.
     *
     * @param string|string[] $path
     * @return bool
     * @throws StoreException
     */
    abstract public function isPublic($path): bool;

    /**
     * Устанавливает публичность файла.
     *
     * @param string|string[] $path
     * @param bool $public
     * @return $this
     * @throws StoreException
     */
    abstract public function setPublic($path, bool $public): self;

    /**
     * Проверяет признак скрытого файла.
     *
     * @param string|string[] $path
     * @return bool
     */
    public function isHidden($path): bool
    {
        $path = $this->filterPath($path);
        if (empty($path)) {
            return false;
        }

        return mb_strpos($this->basename($path), '.') === 0;
    }

    /**
     * Возвращает содержимое файла в строке.
     *
     * @param string|string[] $path
     * @return string
     * @throws StoreException
     */
    abstract public function readContents($path): string;

    /**
     * Записывает содержимое файла из строки
     *
     * @param string|string[] $path
     * @param string $contents содержимое
     * @return int размер записанных данных
     * @throws StoreException
     */
    abstract public function writeContents($path, string $contents): int;

    /**
     * Возвращает открытый поток файла.
     *
     * @param string|string[] $path
     * @param ?string $mode режим открытия
     * @return resource
     * @throws StoreException
     */
    abstract public function readStream($path, ?string $mode = null);

    /**
     * Записывает файл из потока.
     *
     * @param string|string[] $path
     * @param resource $stream
     * @return int кол-во данных
     * @throws StoreException
     */
    abstract public function writeStream($path, $stream): int;

    /**
     * Rename/move file
     *
     * @param string|string[] $path
     * @param string|string[] $newpath
     * @return $this
     * @throws StoreException
     */
    abstract public function rename($path, $newpath): self;

    /**
     * Удаляет файл.
     *
     * @param string|string[] $path
     * @return $this
     * @throws StoreException
     */
    abstract protected function unlink($path): self;

    /**
     * Удаляет директорию.
     *
     * @param string|string[] $path
     * @return $this
     * @throws StoreException
     */
    abstract protected function rmdir($path): self;

    /**
     * Удаляет рекурсивно директорию/файл.
     *
     * @param string|string[] $path
     * @return $this
     * @throws StoreException
     */
    public function delete($path): self
    {
        $path = $this->filterRootPath($path);
        if ($this->isFile($path)) {
            $this->unlink($path);
        } elseif ($this->isDir($path)) {
            foreach ($this->list($path) as $file) {
                $this->delete($file->path);
            }

            $this->rmdir($path);
        }

        return $this;
    }

    /**
     * Создает объект файла с заданным путем.
     *
     * @param string|string[] $path
     * @return File
     * @throws InvalidConfigException
     */
    public function file($path): File
    {
        // конфиг файла
        $fileConfig = ($this->fileConfig ?: []) + ['class' => File::class];

        // создаем файл

        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return Yii::createObject($fileConfig, [$this, $path]);
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
     *  - callable|null $filter function(File $file) : bool фильтр элементов
     * @return File[]
     * @throws StoreException
     */
    abstract public function list($path, array $filter = []): array;

    /**
     * Создает ThumbFile.
     *
     * @param array $config
     * @return ThumbFile ThumbFile
     * @throws InvalidConfigException
     */
    protected function createThumb(array $config = []): ThumbFile
    {
        if (empty($this->thumbFileConfig)) {
            throw new InvalidConfigException('ThumbFile для создания превью не настроен');
        }

        // конвертируем в int (из float)
        if (isset($config['width'])) {
            $config['width'] = (int)$config['width'];
        }

        if (isset($config['height'])) {
            $config['height'] = (int)$config['height'];
        }

        // чтобы по-умолчанию не применялись функции watermark и disclaimer из конфига
        // устанавливаем значения в пустые
        $config += [
            'watermark' => '', // по-умолчанию не создавать watermark
            'disclaimer' => '' // по-умолчанию не применять disclaimer
        ];

        // удаляем из параметров значения true, чтобы применились значения из конфига по-умолчанию
        foreach (['noimage', 'watermark', 'disclaimer'] as $field) {
            if (isset($config[$field])) {
                if ($config[$field] === true) {
                    unset($config[$field]);
                } elseif ($config[$field] === false) {
                    $config[$field] = '';
                }
            }
        }

        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return Yii::createObject($config + $this->thumbFileConfig + ['class' => ThumbFile::class,]);
    }

    /**
     * Создает файл предпросмотра картинки.
     *
     * @param string|array|File $file
     * @param array $config
     * @return ThumbFile превью
     * - если thumbFileConfig не настроен, то false
     * - если файл не существует и не задан noimage, то null
     * @throws InvalidConfigException
     */
    public function thumb($file, array $config = []): ThumbFile
    {
        // проверяем аргументы
        if (empty($file)) {
            throw new InvalidArgumentException('file');
        }

        $config['source'] = ($file instanceof File) ? $file : $this->file($file);

        // создаем превью
        return $this->createThumb($config);
    }

    /**
     * Возвращает превью для noimage.
     *
     * @param array $config конфиг превью.
     * @return ThumbFile
     * @throws InvalidConfigException
     */
    public function noimage(array $config = []): ThumbFile
    {
        // создаем превью для пустого файла
        return $this->createThumb(['source' => null] + $config + ['noimage' => true]);
    }

    /**
     * Очищает превью для заданного файла.
     *
     * @param string|array|File $file
     * @return $this
     * @throws StoreException
     * @throws InvalidConfigException
     */
    public function clearThumb($file): self
    {
        // создаем ThumbFile
        $this->createThumb([
            'source' => $file instanceof File ? $file : $this->file($file)
        ])->clear();

        return $this;
    }

    /**
     * Импорт файла в хранилище.
     *
     * @param string|string[]|File $src импортируемый файл
     *  Если путь задан строкой или массивом, то считается абсолютным путем локального файла.
     * @param string|string[] $path относительный путь в хранилище для импорта
     * @param array $options опции
     *  - bool $ifModified - импортировать файл только если время новее или размер отличается (по-умолчанию true)
     * @return $this
     * @throws StoreException
     * @throws InvalidConfigException
     */
    public function import($src, $path, array $options = []): self
    {
        $src = ($src instanceof File) ? $src : $this->file($src);

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
            try {
                fclose($srcStream);
            } catch (Throwable $ex) {
                Yii::error($ex, __METHOD__);
            }
        }

        return $this;
    }

    /**
     * Копирует файл.
     *
     * @param string|string[] $path
     * @param string|string[] $newpath
     * @return $this
     * @throws StoreException
     */
    public function copy($path, $newpath): self
    {
        $stream = $this->readStream($path);
        try {
            $this->writeStream($newpath, $stream);
        } finally {
            try {
                fclose($stream);
            } catch (Throwable $ex) {
                Yii::error($ex);
            }
        }

        return $this;
    }

    /**
     * Очищает внутренний кэш файлов PHP.
     *
     * @param string|string[] $path относительный путь
     * @return $this
     */
    public function clearStatCache($path): self
    {
        try {
            clearstatcache(true, $this->absolutePath($path));
        } catch (Throwable $ex) {
            Yii::error($ex);
        }

        return $this;
    }

    /**
     * Фильтрует путь и проверяет на доступ к корневому каталогу.
     *
     * @param string|string[] $path
     * @return string[]
     */
    protected function filterRootPath($path): array
    {
        $path = $this->filterPath($path);
        if (empty($path)) {
            throw new InvalidArgumentException('Корневой путь');
        }

        return $path;
    }

    /**
     * Проверяет соответствие файла фильтру.
     *
     * @param File $file
     * @param array $filter
     *     - string|null $dir - true - только директории, false - только файлы
     *     - string|null $public - true - публичный доступ, false - приватный доступ
     *     - bool|null $hidden - true - скрытые файлы, false - открытые
     *     - string|null $pathRegex - регулярное выражение пути
     *     - string|null $nameRegex - регулярное выражение имени файла
     *     - callable|null $filter function(File $file) : bool фильтр элементов
     * @return bool
     */
    protected function fileMatchFilter(File $file, array $filter): bool
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
        if (isset($filter['dir']) && $file->isDir !== (bool)$filter['dir']) {
            return false;
        }

        // фильтруем по доступности
        if (isset($filter['public']) && $file->public !== (bool)$filter['public']) {
            return false;
        }

        // фильтруем скрытые
        if (isset($filter['hidden']) && $file->hidden !== (bool)$filter['hidden']) {
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
    protected function throwLastError(string $op = '', string $absPath = ''): void
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
        $err = error_get_last();
        error_clear_last();

        if (! empty($err['message'])) {
            $messages[] = $err['message'];
        }

        // выбрасываем исключение
        throw new StoreException(implode(': ', $messages));
    }

    /**
     * Сортировка файлов по имени.
     *
     * @param File[] $files
     * @return File[]
     */
    protected static function sortByName(array $files): array
    {
        usort($files, static fn(File $a, File $b): int => $a->path <=> $b->path);

        return $files;
    }
}
