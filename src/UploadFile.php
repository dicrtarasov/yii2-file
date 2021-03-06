<?php
/*
 * @copyright 2019-2021 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license MIT
 * @version 27.01.21 19:27:00
 */

declare(strict_types = 1);
namespace dicr\file;

use Exception;
use InvalidArgumentException;
use LogicException;
use yii\helpers\ArrayHelper;

use function is_array;
use function is_string;

/**
 * Загруженный файл.
 *
 * Так как хранится в LocalFileStore, то наследует File, добавляя
 * отдельное от path имя файла name. Также добавляет возможность
 * установить error, size, и mimeType
 *
 * Файл необходимо импортировать в директорию модели при ее сохранении.
 *
 * В зависимости от присутствия названия формы и множественности аттрибута,
 * php формирует разную структуру $_FILES.
 *
 * @property string $name имя файла
 * @property string $mimeType MIME-тип файла
 * @property int $error ошибка загрузки
 * @property int $size размер файла
 */
class UploadFile extends File
{
    /** @var ?string наименование файла */
    private $_name;

    /** @var int ошибка загрузки */
    private $_error;

    /** @var int размер файла */
    private $_size;

    /** @var string mime-type */
    private $_mimeType;

    /**
     * Конструктор
     *
     * @param array|string $pathconfig
     */
    public function __construct($pathconfig)
    {
        $path = null;
        $config = [];

        if (is_string($pathconfig)) {
            $path = $pathconfig;
        } else {
            $config = $pathconfig;
            $path = ArrayHelper::remove($config, 'path');
        }

        parent::__construct(LocalFileStore::root(), $path, $config);
    }

    /**
     * @inheritdoc
     */
    public function init() : void
    {
        parent::init();

        if (isset($this->_error)) {
            /** @noinspection UnnecessaryCastingInspection */
            $this->_error = (int)$this->_error;
        }

        if (isset($this->_size)) {
            /** @noinspection UnnecessaryCastingInspection */
            $this->_size = (int)$this->_size;
        }
    }

    /**
     * Возвращает файлы аттрибутов формы загруженные в $_FILES или null.
     *
     * @param string $attribute если задан, то возвращает файлы только для аттрибута
     * @param string $formName имя формы для которой возвращает аттрибуты
     * @return static[]|null
     */
    public static function instances(string $attribute = '', string $formName = '') : ?array
    {
        /** @var static[][] */
        static $instances;

        if (! isset($instances)) {
            $instances = self::parseInstances();
        }

        $path = [$formName];
        if ($attribute !== '') {
            $path[] = $attribute;
        }

        // маскируем странную \Exception функции getValue
        try {
            return ArrayHelper::getValue($instances, $path);
        } catch (Exception $ex) {
            throw new LogicException('Неожиданная ошибка', 0, $ex);
        }
    }

    /**
     * Файл аттрибута модели.
     *
     * @param string $attribute
     * @param string $formName
     * @return ?static
     */
    public static function instance(string $attribute, string $formName = '') : ?self
    {
        $files = self::instances($attribute, $formName);

        return empty($files) ? null : reset($files);
    }

    /**
     * Парсит $_FILES и создает объекты.
     *
     * @return UploadFile[] файлы аттрибута
     */
    private static function parseInstances() : array
    {
        $instances = [];

        if (! empty($_FILES)) {
            foreach ($_FILES as $key => $data) {
                if (static::isComplexFormData($data)) {
                    // аттрибуты формы: $key == $formName, $data == аттрибуты в формате формы
                    $instances[$key] = static::parseFormData($data);
                } else {
                    // аттрибут без формы, $formName == '', $key == $attribute, $data == данные в формате одного аттрибуты
                    $instances[''][$key] = static::parseSimpleData($data);
                }
            }
        }

        return $instances;
    }

    /**
     * Определяет формат данных (с названием формы или простой).
     *
     * Формат $_FILES зависит от того есть ли название формы или нет.
     *
     * @param array $data
     * @return bool true если данные формы
     */
    private static function isComplexFormData(array $data) : bool
    {
        // если не установлен name, то ошибка формата данных
        if (! isset($data['name'])) {
            throw new InvalidArgumentException('Некорректная структура данных $_FILES: ' . var_export($data, true));
        }

        // если name не массив - однозначно не форма
        if (! is_array($data['name'])) {
            return false;
        }

        // если в name массив массивов - однозначно форма
        if (is_array(reset($data['name']))) {
            return true;
        }

        // средний вариант определяем по типу ключей в name.
        return ! preg_match('~^\d+$~', (string)array_keys($data['name'])[0]);
    }

    /**
     * Парсит файлы аттрибута, отправленные без имени формы:
     *
     * ```
     * Единственное:
     * <input type="file" name="attribute"/>
     *
     * $_FILES = [
     *   'attribute' => [
     *      'name' => 'test.php',
     *      'type' => 'application/x-php',
     *      'tmp_name' => '/tmp/phpqmYDrQ',
     *      'error' => 0,
     *      'size' => 41
     *   ]
     * ]
     *
     * Множественное:
     * <input type="file" name="attribute[]"/>
     *
     * $_FILES = [
     *   'attribute' => [
     *      'name' => [
     *          0 => '2018-04-23-195938.jpg',
     *          1 => 'test.php'
     *      ],
     *      'type' => [
     *          0 => 'image/jpeg',
     *          1 => 'application/x-php'
     *      ],
     *      'tmp_name' => [
     *          0 => '/tmp/phpQ2c8T7',
     *          1 => '/tmp/phpEarnq1'
     *      ],
     *      'error' => [
     *          0 => 0,
     *          1 => 0
     *      ],
     *      'size' => [
     *          0 => 166980,
     *          1 => 41
     *      ]
     *   ]
     * ]
     * ```
     *
     * @param array $data данные аттрибута
     * @return UploadFile[] файлы аттрибута
     */
    private static function parseSimpleData(array $data) : array
    {
        return static::instancesFromData(
            (array)($data['name'] ?? []),
            (array)($data['type'] ?? []),
            (array)($data['size'] ?? []),
            (array)($data['error'] ?? []),
            (array)($data['tmp_name'] ?? [])
        );
    }

    /**
     * Парсит файлы формы, отправленные с именем формы:
     *
     * ```
     * Единственное:
     * <input type="file" name="formName[attribute]"/>
     *
     * $_FILES = [
     *   'formName' => [
     *      'name' => [
     *          'attribute' => 'test.php'
     *      ],
     *      'type' => [
     *          'attribute' => 'application/x-php'
     *      ],
     *      'tmp_name' => [
     *          'attribute' => '/tmp/phpQ2c8T7'
     *      ],
     *      'error' => [
     *          'attribute' => 0
     *      ],
     *      'size' => [
     *          'attribute' => 41
     *      ]
     *   ]
     * ]
     *
     * Множественное:
     * <input type="file" name="formName[attribute][]"/>
     *
     * $_FILES = [
     *   'formName' => [
     *      'name' => [
     *          'attribute' => [
     *              0 => '2018-04-23-195938.jpg',
     *              1 => 'test.php'
     *          ]
     *      ],
     *      'type' => [
     *          'attribute' => [
     *              0 => 'image/jpeg',
     *              1 => 'application/x-php'
     *          ]
     *      ],
     *      'tmp_name' => [
     *          'attribute' => [
     *              0 => '/tmp/phpqZNTne',
     *              1 => '/tmp/phpvK409l'
     *          ],
     *      ],
     *      'error' => [
     *          'attribute' => [
     *              0 => 0,
     *              1 => 0
     *          ]
     *      ],
     *      'size' => [
     *          'attribute' => [
     *              0 => 166980,
     *              1 => 41
     *          ]
     *      ]
     *   ]
     * ]
     * ```
     *
     * @param array $data array данные аттрибутов формы
     * @return UploadFile[] [$attribute => \dicr\file\UploadFile[]] аттрибуты формы с файлами
     */
    private static function parseFormData(array $data) : array
    {
        $instances = [];

        foreach (array_keys($data['name']) as $attribute) {
            $instances[$attribute] = static::instancesFromData(
                (array)$data['name'][$attribute],
                (array)($data['type'][$attribute] ?? []),
                (array)($data['size'][$attribute] ?? []),
                (array)($data['error'][$attribute] ?? []),
                (array)($data['tmp_name'][$attribute] ?? [])
            );
        }

        return $instances;
    }

    /**
     * Создает объекты для файлов одного аттрибута
     *
     * @param string[] $names имена
     * @param string[] $types типы
     * @param int[] $sizes размеры
     * @param int[] $errors ошибки
     * @param string[] $paths пути
     * @return self[]
     */
    private static function instancesFromData(
        array $names,
        array $types,
        array $sizes,
        array $errors,
        array $paths
    ) : array {
        $instances = [];

        foreach ($names as $pos => $name) {
            // пропускаем файлы, которые не выбраны в форме
            if (empty($name)) {
                continue;
            }

            $path = rtrim($paths[$pos] ?? '', DIRECTORY_SEPARATOR);
            if ($path === '') {
                continue;
            }

            $instances[$pos] = new static([
                'path' => $path,
                'name' => $name,
                'mimeType' => $types[$pos] ?? null,
                'size' => $sizes[$pos] ?? null,
                'error' => $errors[$pos] ?? null
            ]);
        }

        return $instances;
    }

    /**
     * @inheritDoc
     */
    public function getName(array $options = []) : string
    {
        // если имя файла не задано то берем его из пути
        if (! isset($this->_name)) {
            if (! empty($this->_error)) {
                return '';
            }

            $this->_name = parent::getName();
        }

        $name = $this->_name;
        if (! empty($options['removeExt'])) {
            $name = static::removeExtension($name);
        }

        return $name;
    }

    /**
     * Установить имя файла
     *
     * @param string $name
     * @return $this
     */
    public function setName(string $name): File
    {
        $name = $this->_store->basename($name);
        if (empty($name)) {
            throw new InvalidArgumentException('name');
        }

        $this->_name = $name;

        return $this;
    }

    /**
     * Возвращает ошибку
     *
     * @return ?int
     */
    public function getError() : ?int
    {
        return $this->_error;
    }

    /**
     * Устанавливает ошибку
     *
     * @param int|string $error
     * @return $this
     */
    public function setError($error) : self
    {
        $this->_error = (int)$error;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getSize() : int
    {
        if (! isset($this->_size)) {
            if (! empty($this->_error)) {
                return 0;
            }

            $this->_size = parent::getSize();
        }

        return $this->_size;
    }

    /**
     * Устанавливает размер.
     *
     * @param int|string $size
     * @return $this
     */
    public function setSize($size) : self
    {
        if ($size < 0) {
            throw new InvalidArgumentException('size');
        }

        $this->_size = (int)$size;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getMimeType() : string
    {
        if (! isset($this->_mimeType)) {
            if (! empty($this->_error)) {
                return '';
            }

            $this->_mimeType = parent::getMimeType();
        }

        return $this->_mimeType;
    }

    /**
     * Устанавливает MIME-тип.
     *
     * @param string $type
     * @return $this
     */
    public function setMimeType(string $type) : self
    {
        $this->_mimeType = $type;

        return $this;
    }
}
