<?php
/*
 * @copyright 2019-2022 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL-3.0-or-later
 * @version 05.01.22 01:17:27
 */

declare(strict_types = 1);
namespace dicr\file;

use ArrayAccess;
use Traversable;
use Yii;
use yii\base\Arrayable;
use yii\base\Component;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\base\Model;
use yii\data\DataProviderInterface;
use yii\db\Query;
use yii\web\Response;
use yii\web\ResponseFormatterInterface;

use function array_keys;
use function gettype;
use function is_array;
use function is_callable;
use function is_iterable;
use function is_object;

/**
 * CSV File.
 *
 * Конвертирует данные из \yii\web\Response::data в CSV Response::stream, для возврата ответа в виде CSV-файла.
 * В Response::data можно устанавливать значения типа:
 * - null (пустой файл)
 * - array (обычный массив или ассоциативный, если установлены headers)
 * - object
 * - Traversable
 * - yii\base\Arrayable
 * - yii\db\Query
 * - yii\data\DataProviderInterface
 *
 *
 *
 * Для чтения нужно задать либо handle, либо filename. Если не задан handle, то открывается filename.
 * При записи, если не задан handle и filename, то handle открывается в php://temp.
 *
 * @property-read ?string $mimeType тип контента на основании contentType и charset
 */
class CSVResponseFormatter extends Component implements ResponseFormatterInterface
{
    /** @var string форма для Response::formatters */
    public const FORMAT = 'csv';

    /** @var string Content-Type текст */
    public const CONTENT_TYPE_TEXT = 'text/csv';

    /** @var string Content-Type excel */
    public const CONTENT_TYPE_EXCEL = 'application/vnd.ms-excel';

    /** @var ?string Content-Type */
    public ?string $contentType = self::CONTENT_TYPE_TEXT;

    /** @var ?string имя файла для content-dispose attachment */
    public ?string $fileName = null;

    /** @var array конфиг CSVFile */
    public array $csvConfig = [];

    /**
     * @var ?array поля, ассоциативный массив в виде field => title
     *      false - не выводить
     *      true - определить заголовки автоматически
     *      array - заголовки колонок
     */
    public ?array $fields = null;

    /** @var ?callable function($row, CSVResponseFormatter $formatter): array */
    public $format;

    /**
     * Конвертирует данные в Traversable
     */
    protected static function convertData(object|array $data): object|array
    {
        if (empty($data)) {
            return [];
        }

        if (is_iterable($data)) {
            return $data;
        }

        if ($data instanceof Arrayable) {
            return $data->toArray();
        }

        if ($data instanceof Query) {
            return $data->each();
        }

        if ($data instanceof DataProviderInterface) {
            return $data->getModels();
        }

        if (is_object($data)) {
            return (array)$data;
        }

        throw new InvalidArgumentException('Неизвестный тип данных: ' . gettype($data));
    }

    /**
     * Конвертирует строку данных в массив значений
     *
     * @param object|array $row - данные строки
     * @return object|array массив значений
     */
    protected function convertRow(object|array $row): object|array
    {
        if (empty($row)) {
            return [];
        }

        if ($row instanceof Model) {
            return $row->attributes;
        }

        if ($row instanceof Arrayable) {
            return $row->toArray();
        }

        if (is_iterable($row) || ($row instanceof ArrayAccess)) {
            return $row;
        }

        if (is_object($row)) {
            return (array)$row;
        }

        throw new InvalidArgumentException('Неизвестный тип строки данных: ' . gettype($row));
    }

    /**
     * Возвращает mime-тип контента
     */
    public function getMimeType(?CSVFile $csv = null): ?string
    {
        $mimeType = null;

        if (! empty($this->contentType)) {
            $mimeType = $this->contentType;
            if ($csv !== null && stripos($this->contentType, 'charset') === false) {
                $charset = $csv->charset;

                // переводим cp1251 в window-1251
                if (stripos($charset, 'cp1251') !== false) {
                    $charset = 'windows-1251';
                }

                $mimeType .= '; charset=' . $charset;
            }
        }

        return $mimeType;
    }

    /**
     * Форматирует ответ в CSV-файл
     *
     * @param object|array $data данные
     * @throws InvalidConfigException
     * @throws StoreException
     */
    public function formatData(object|array $data): CSVFile
    {
        // CSV-файл для вывода
        $csvFile = new CSVFile($this->csvConfig ?: []);

        if (! empty($data)) {
            // пишем заголовок
            if (! empty($this->fields)) {
                $csvFile->writeLine(array_values($this->fields));
            }

            foreach (self::convertData($data) as $row) {
                if (is_callable($this->format)) {
                    $row = ($this->format)($row, $this);
                }

                $row = $this->convertRow($row);

                $line = [];
                if (! empty($this->fields)) { // если заданы заголовки, то выбираем только заданные поля в заданной последовательности
                    // проверяем доступность прямой выборки индекса из массива
                    if (! is_array($row) && ! ($row instanceof ArrayAccess)) {
                        throw new InvalidConfigException(
                            'Для использования списка полей fields необходимо чтобы' .
                            ' элемент данных был либо array, либо типа ArrayAccess'
                        );
                    }

                    $line = array_map(
                        static fn(string $field): string => $row[$field] ?? '',
                        array_keys($this->fields)
                    );
                } else { // обходим все поля
                    // проверяем что данные доступны для обхода
                    if (! is_array($row) && ! ($row instanceof Traversable)) {
                        throw new InvalidArgumentException(
                            'Элемент данных должен быть либо array, либо типа Traversable'
                        );
                    }

                    // обходим тип iterable ВНИМАНИЕ !!! нельзя array_map
                    foreach ($row as $col) {
                        $line[] = $col;
                    }
                }

                $csvFile->writeLine($line);
            }
        }

        return $csvFile;
    }

    /**
     * @inheritDoc
     * @throws StoreException
     * @throws InvalidConfigException
     * @noinspection ClassMethodNameMatchesFieldNameInspection
     */
    public function format($response = null): Response
    {
        if ($response === null) {
            /** @var Response $response */
            /** @noinspection CallableParameterUseCaseInTypeContextInspection */
            $response = Yii::$app->response;
        }

        // пишем во временный CSVFile (php://temp)
        $csvFile = $this->formatData($response->data);
        $response->data = null;

        // заголовки загрузки файла
        $response->setDownloadHeaders(
            $this->fileName,
            $this->getMimeType($csvFile),
            false,
            ftell($csvFile->handle)
        );

        // перематываем файл в начало
        $csvFile->reset();

        // устанавливаем поток для скачивания
        $response->stream = $csvFile->handle;

        return $response;
    }
}
