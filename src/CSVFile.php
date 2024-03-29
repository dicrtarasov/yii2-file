<?php
/*
 * @copyright 2019-2022 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL-3.0-or-later
 * @version 14.02.22 23:14:22
 */

declare(strict_types = 1);
namespace dicr\file;

use Iterator;
use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;
use yii\di\Instance;
use yii\helpers\ArrayHelper;

use function array_map;
use function error_clear_last;
use function error_get_last;
use function fgetcsv;
use function fopen;
use function fputcsv;
use function preg_match;
use function rewind;

/**
 * CSV File.
 * При чтении если не указан открытый handle, то он открывается из filename.
 * При записи если не указан ни handle, ни filename, то в handle открывается в php://temp.
 *
 * @property-read ?int $lineNo номер текущей строки
 * @property-read ?resource $handle указатель файла
 */
class CSVFile extends File implements Iterator
{
    /** кодировка Excel */
    public const CHARSET_EXCEL = 'cp1251';

    /** кодировка по-умолчанию */
    public const CHARSET_DEFAULT = 'utf-8';

    /** кодировка для преобразования при чтении/записи */
    public string $charset = self::CHARSET_DEFAULT;

    /** разделитель полей по-умолчанию */
    public const DELIMITER_DEFAULT = ',';

    /** разделитель полей Excel */
    public const DELIMITER_EXCEL = ';';

    /** разделитель полей */
    public string $delimiter = self::DELIMITER_DEFAULT;

    /** ограничитель полей по-умолчанию */
    public const ENCLOSURE_DEFAULT = '"';

    /** символ ограничения строк в полях */
    public string $enclosure = self::ENCLOSURE_DEFAULT;

    /** символ экранирования по-умолчанию */
    public const ESCAPE_DEFAULT = '\\';

    /** символ для экранирования */
    public string $escape = self::ESCAPE_DEFAULT;

    /** @var ?resource файловый дескриптор */
    protected $_handle;

    /** текущий номер строки файла */
    protected ?int $_lineNo = null;

    /** @var ?string[] текущие данные для Iterable */
    protected ?array $_current;

    /**
     * CSVFile constructor.
     *
     * @throws InvalidConfigException
     */
    public function __construct(array $config = [])
    {
        $store = ArrayHelper::remove($config, 'store');
        $store = empty($store) ? LocalFileStore::root() : Instance::ensure($store, FileStore::class);

        $path = ArrayHelper::remove($config, 'path', '');

        parent::__construct($store, $path, $config);
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        // если задана кодировка по-умолчанию utf-8, то удаляем значение
        if ($this->charset === '' || preg_match('~^utf\-?8$~uim', $this->charset)) {
            $this->charset = self::CHARSET_DEFAULT;
        }
    }

    /**
     * Номер текущей строки
     */
    public function getLineNo(): ?int
    {
        return $this->_lineNo;
    }

    /**
     * Файловый указатель.
     *
     * @return ?resource
     */
    public function getHandle()
    {
        return $this->_handle;
    }

    /**
     * Перематывает указатель в начальное состояние.
     *
     * @noinspection PhpUsageOfSilenceOperatorInspection
     * @throws StoreException
     */
    public function reset(): static
    {
        if (! empty($this->_handle) && @rewind($this->_handle) === false) {
            $err = @error_get_last();
            throw new StoreException('Ошибка перемотки файла: ' . $this->absolutePath . ': ' . $err['message']);
        }

        $this->_lineNo = null;
        $this->_current = null;

        return $this;
    }

    /**
     * Декодирует строку из заданной кодировки.
     *
     * @return string[]
     * @noinspection PhpUsageOfSilenceOperatorInspection
     */
    protected function decodeLine(array $line): array
    {
        if ($this->charset !== self::CHARSET_DEFAULT) {
            $line = array_map(fn($val) => @iconv(
                $this->charset, 'utf-8//TRANSLIT', (string)$val), $line
            );
        }

        return $line;
    }

    /**
     * Кодирует строку в заданную кодировку.
     *
     * @return string[]
     * @noinspection PhpUsageOfSilenceOperatorInspection
     */
    protected function encodeLine(array $line): array
    {
        if ($this->charset !== self::CHARSET_DEFAULT) {
            $charset = $this->charset;
            if (! str_contains($charset, '//')) {
                $charset .= '//TRANSLIT';
            }

            $line = array_map(static fn($val) => @iconv(
                'utf-8', $charset, (string)$val), $line
            );
        }

        return $line;
    }

    /**
     * Читает сроку данных.
     * Если задан charset, то конвертирует кодировку.
     *
     * @return ?string[] текущую строку или null если конец файла
     * @noinspection PhpUsageOfSilenceOperatorInspection
     * @throws StoreException
     */
    public function readLine(): ?array
    {
        // открываем файл
        if (empty($this->_handle)) {
            $this->_handle = $this->getStream('rt+');
            $this->_current = null;
            $this->_lineNo = null;
        }

        // очищаем ошибку перед чтением для определения конца файла
        @error_clear_last();

        // читаем строку
        $line = @fgetcsv(
            $this->_handle, null, $this->delimiter, $this->enclosure, $this->escape
        );

        if ($line !== false) {
            // счетчик текущей строки
            if (isset($this->_lineNo)) {
                $this->_lineNo++;
            } else {
                $this->_lineNo = 0;
            }

            // декодируем данные
            $this->_current = $this->decodeLine($line);
        } else {
            // проверяем была ли ошибка
            $err = error_get_last();
            if (! empty($err)) {
                // в случае ошибки выбрасываем исключение
                @error_clear_last();
                throw new StoreException('Ошибка чтения файла: ' . $this->absolutePath . ': ' . $err['message']);
            }

            // принимаем как конец файла
            $this->_current = null;
        }

        return $this->_current;
    }

    /**
     * Записывает массив данных в файл.
     * Если задан format, то вызывает для преобразования данных в массив.
     * Если задан charset, то кодирует в заданную кодировку.
     *
     * @return int длина записанной строки
     * @noinspection PhpUsageOfSilenceOperatorInspection
     * @throws StoreException
     */
    public function writeLine(array $line): int
    {
        // запоминаем текущую строку
        $this->_current = $line;

        // открываем файл
        if (empty($this->_handle)) {
            // поддерживаем только локальное хранилище
            if (! $this->store instanceof LocalFileStore) {
                throw new StoreException('', new NotSupportedException('Запись только в локальные файлы'));
            }

            // если не задан путь, то открываем временный файл
            $absolutePath = empty($this->_path) ? 'php://temp' : $this->absolutePath;

            /** @noinspection FopenBinaryUnsafeUsageInspection */
            $this->_handle = @fopen($absolutePath, 'w+');
            if ($this->_handle === false) {
                $err = error_get_last();
                @error_clear_last();
                throw new StoreException('Ошибка создания файла: ' . $this->path . ': ' . ($err['message'] ?? ''));
            }
        }

        // кодируем данные
        $line = $this->encodeLine($line);

        // пишем в файл
        $length = @fputcsv(
            $this->_handle, $line, $this->delimiter,
            $this->enclosure, $this->escape
        );

        if ($length === false) {
            $err = error_get_last();
            @error_clear_last();
            throw new StoreException('Ошибка записи в файл: ' . $this->_path . ': ' . ($err['message'] ?? ''));
        }

        // счетчик строк
        if (isset($this->_lineNo)) {
            $this->_lineNo++;
        } else {
            $this->_lineNo = 0;
        }

        return $length;
    }

    // Интерфейс Iterable //////////////////////////////////////////////////////////////////

    /**
     * @inheritDoc
     * @throws StoreException
     */
    public function rewind(): void
    {
        $this->reset();
        $this->readLine();
    }

    /**
     * @inheritDoc
     * @return ?int номер строки, начиная с 1
     */
    public function key(): ?int
    {
        return $this->_lineNo;
    }

    /**
     * @inheritDoc
     * @return ?string[]
     */
    public function current(): ?array
    {
        return $this->_current;
    }

    /**
     * @inheritDoc
     * @throws StoreException
     */
    public function next(): void
    {
        $this->readLine();
    }

    /**
     * @inheritDoc
     */
    public function valid(): bool
    {
        return $this->_current !== null;
    }

    /**
     * Деструктор
     *
     * нельзя закрывать файл, потому что он используется дальше после удаления этого объекта,
     * например в CSVResponseFormatter !!
     */
    /*
    public function __destruct()
    {
        if (!empty($this->handle)) {
            @fclose($this->handle);
        }
    }
    */
}
