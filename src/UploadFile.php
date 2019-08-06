<?php
namespace dicr\file;

use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\helpers\ArrayHelper;

/**
 * Загруженный файл.
 * Файл необхоимо импортировать в директорию модели при ее сохранении.
 * В зависимости от присутствия названия формы и множественности аттрибута,
 * php формирует разную структуру $_FILES.
 *
 * @property string $name имя файла
 * @property string $mimeType MIME-тип файла
 * @property int $error ошибка загрузки
 * @property int $size размер файла
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2018
 */
class UploadFile extends AbstractFile
{
    /** @var string наименование файла */
    private $_name;

    /** @var int ошибка загрузки */
    private $_error;

    /** @var int размер файла */
    private $_size;

    /** @var string mime-type */
    private $_mimeType;

    // @formatter:off
    /** @var array [
     *      $formName => [
     *          $attribute => \dicr\file\UploadFile[]
     *      ]
     *  ] кэш распарсенных объектов */
    // @formatter:on
    private static $instances;

    /**
     * Конструктор
     *
     * @param string|array $config path or config
     */
    public function __construct($pathconfig)
    {
        $path = '';
        $config = [];

        if (is_string($pathconfig)) {
            $path = $pathconfig;
            $config = [];
        } elseif (is_array($pathconfig)) {
            $path = $pathconfig['path'] ?? '';
            $config = $pathconfig;
            unset($config['path']);
        }

        $path = trim($path, DIRECTORY_SEPARATOR);
        if ($path == '') {
            throw new \InvalidArgumentException('path');
        }

        parent::__construct($path, $config);
    }

    /**
     * {@inheritdoc}
     * @see \yii\base\BaseObject::init()
     */
    public function init()
    {
        parent::init();

        if (isset($this->_error)) {
            $this->_error = (int) $this->_error;
        }

        // в случае ошибок name и path может быть пустым
        if (empty($this->_error)) {
            // если имя не задано, то берем его из пути
            $this->_name = basename($this->_name ?: $this->_path);
            if ($this->_name == '') {
                throw new InvalidConfigException('name');
            }
        }

        if (isset($this->_size)) {
            $this->_size = (int) $this->_size;
        }
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFile::normalizePath()
     */
    public function normalizePath($path)
    {
        return LocalFileStore::root()->normalizePath($path);
    }

    /**
     * Возвращает ошибку
     *
     * @return int
     */
    public function getError()
    {
        return $this->_error;
    }

    /**
     * Устанавливает ошибку
     *
     * @param int $error
     * @return $this
     */
    public function setError(int $error)
    {
        $this->_error = $error;

        return $this;
    }

    /**
     * {@inheritDoc}
     * @see \dicr\file\AbstractFile::getName()
     */
    public function getName(array $options = [])
    {
        $name = basename($this->_name);

        if (!empty($options['removeExt'])) {
            $name = pathinfo($name, PATHINFO_FILENAME);
        }

        return $name;
    }

    /**
     * Установить имя файла
     *
     * @param string $name
     * @return $this
     */
    public function setName(string $name)
    {
        $this->_name = basename($name);
        if (empty($this->_name)) {
            throw new \InvalidArgumentException('name');
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFile::getExists()
     */
    public function getExists()
    {
        return LocalFileStore::root()->exists($this->_path);
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFile::getIsDir()
     */
    public function getIsDir()
    {
        return LocalFileStore::root()->isDir($this->_path);
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFile::getIsFile()
     */
    public function getIsFile()
    {
        return LocalFileStore::root()->isFile($this->_path);
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFile::getSize()
     */
    public function getSize()
    {
        if (! isset($this->_size)) {
            $this->_size = LocalFileStore::root()->size($this->_path);
        }

        return $this->_size;
    }

    /**
     * Усанавливает размер.
     *
     * @param int $size
     * @throws \InvalidArgumentException
     */
    public function setSize(int $size)
    {
        if ($size < 0) {
            throw new \InvalidArgumentException('size');
        }

        $this->_size = $size;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFile::getMtime()
     */
    public function getMtime()
    {
        return LocalFileStore::root()->mtime($this->_path);
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFile::getMimeType()
     */
    public function getMimeType()
    {
        if (empty($this->_mimeType)) {
            $this->_mimeType = LocalFileStore::root()->mimeType($this->_path);
        }

        return $this->_mimeType;
    }

    /**
     * Устанавливает MIME-тип.
     *
     * @param string $type
     */
    public function setMimeType(string $type)
    {
        $this->_mimeType = $type;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFile::getContents()
     */
    public function getContents($context = null)
    {
        return LocalFileStore::root()->readContents($this->_path);
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\AbstractFile::getStream()
     */
    public function getStream($context = null)
    {
        return LocalFileStore::root()->readStream($this->_path);
    }

    /**
     * Перемещение с move_uploaded_file
     *
     * @param array|string $path
     * @throws StoreException
     * @return static
     */
    public function move($path)
    {
        LocalFileStore::root()->move($this->_path, $path);

        return $this;
    }

    /**
     * Копирование файла
     *
     * @param string|array $path
     * @return static
     */
    public function copy($path)
    {
        LocalFileStore::root()->copy($this->_path, $path);

        return $this;
    }

    /**
     * Удаляет файл
     *
     * @return static
     */
    public function delete()
    {
        LocalFileStore::root()->delete($this->_path);

        return $this;
    }

    /**
     * Создает объекты для файлов одного аттрибута
     *
     * @param array $names имена
     * @param array $types типы
     * @param array $sizes размеры
     * @param array $errors ошибки
     * @param array $paths пути
     * @return \dicr\file\UploadFile[]
     */
    protected static function attributeInstances(array $names, array $types, array $sizes, array $errors, array $paths)
    {
        $instances = [];

        foreach ($names as $pos => $name) {
            // пропускаем файлы, которые не выбраны в форме
            if (empty($name)) {
                continue;
            }

            $path = rtrim($paths[$pos] ?? '', DIRECTORY_SEPARATOR);

            if ($path === '') {
                throw new Exception('empty upload file path path');
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

    // @formatter:off
    /**
     * Парсит файлы аттрибута, отправленные без имени формы:
     *
     * <xmp><input type="file" name="attribute"/></xmp>
     *
     * <xmp>
     * $_FILES = [
     *  '$attribute' => [
     *      'name' => 'test.php',
     *      'type' => 'application/x-php',
     *      'tmp_name' => '/tmp/phpqmYDrQ',
     *      'error' => 0,
     *      'size' => 41
     *  ]
     * ];
     * </xmp>
     *
     * <xmp><input type="file" name="attibute[]"/></xmp>
     *
     * <xmp>
     * $_FILES = [
     *  '$attribute' => [
     *      'name' => [
     *          0 => '2018-04-23-195938.jpg',
     *          1 => 'test.php',
     *      ],
     *      'type' => [
     *          0 => 'image/jpeg',
     *          1 => 'application/x-php',
     *      ],
     *      'tmp_name' => [
     *          0 => '/tmp/php9PADWU',
     *          1 => '/tmp/phpEarnq1',
     *      ],
     *      'error' => [
     *          0 => 0,
     *          1 => 0,
     *      ],
     *      'size' => [
     *          0 => 166980,
     *          1 => 41,
     *      ],
     *  ],
     * ];
     * </xmp>
     *
     * @param array $data данные аттрибута
     * @return \dicr\file\UploadFile[] файлы аттрибута
     */
    // @formatter:on
    protected static function parseAttribData(array $data)
    {
        return static::attributeInstances(
            (array) ($data['name'] ?? []),
            (array) ($data['type'] ?? []),
            (array) ($data['size'] ?? []),
            (array) ($data['error'] ?? []),
            (array) ($data['tmp_name'] ?? [])
        );
    }

    // @formatter:off
    /**
     * Парсит файлы формы, отправленные с именем формы:
     *
     * <xmp><input type="file" name="formName[attibute]"/></xmp>
     *
     * <xmp>
     * $_FILES = [
     *  '$formName' => [
     *      'name' => [
     *          '$attribute' => 'test.php'
     *      ],
     *      'type' => [
     *          '$attribute' => 'application/x-php'
     *      ],
     *      'tmp_name' => [
     *          '$attribute' => '/tmp/phpQ2c8T7'
     *      ],
     *      'error' => [
     *          '$attribute' => 0
     *      ],
     *      'size' => [
     *          '$attribute' => 41
     *      ]
     *  ]
     * ];
     * </xmp>
     *
     * <xmp><input type="file" name="formName[attibute][]"/></xmp>
     *
     * <xmp>
     * $_FILES = [
     *  '$formName' => [
     *      'name' => [
     *          '$attribute' => [
     *              0 => '2018-04-23-195938.jpg',
     *              1 => 'test.php'
     *          ]
     *      ],
     *      'type' => [
     *          '$attribute' => [
     *              0 => 'image/jpeg',
     *              1 => 'application/x-php'
     *          ]
     *      ],
     *      'tmp_name' => [
     *          '$attribute' => [
     *              0 => '/tmp/phpqZNTne',
     *              1 => '/tmp/phpvK409l'
     *          ],
     *      ],
     *      'error' => [
     *          '$attribute' => [
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
     *  ]
     * ];
     * </xmp>
     *
     * @param array $data array данные аттрибутов формы
     * @return array [$attribute => \dicr\file\UploadFile[]] аттрибуты формы с файлами
     */
    // @formatter:on
    protected static function parseFormData(array $data)
    {
        $instances = [];

        foreach (array_keys($data['name']) as $attribute) {
            $instances[$attribute] = static::attributeInstances(
                (array) $data['name'][$attribute],
                (array) ($data['type'][$attribute] ?? []),
                (array) ($data['size'][$attribute] ?? []),
                (array) ($data['error'][$attribute] ?? []),
                (array) ($data['tmp_name'][$attribute] ?? [])
            );
        }

        return $instances;
    }

    // @formatter:off
    /**
     * Определяет тип структуры данных $_FILES.
     *
     * Структура без формы в name содержит либо строку имени файла, либо массив имен с порядковыми значениями:
     *
     *  <xmp>'name' => '2018-04-23-195938.jpg'</xmp>
     *
     * или
     *
     * <xmp>
     * 'name' => [
     *      0 => '2018-04-23-195938.jpg',
     *      1 => 'test.php',
     * ]
     * </xmp>
     *
     * Структура с именем формы в аттрибуте в name содержит массив имен аттрбутов, значениями которых являются массивы
     * имен файлов:
     *
     * <xmp>
     * 'name' => [
     *      '$attribute' => 'test.php'
     * ]
     * </xmp>
     *
     * или
     *
     * <xmp>
     * 'name' => [
     *      '$attribute' => [
     *          0 => '2018-04-23-195938.jpg',
     *          1 => 'test.php'
     *      ]
     * ]
     * </xmp>
     *
     * @param array $data
     * @throws Exception
     * @return boolean
     */
    // @formatter:on
    protected static function detectFormData(array $data)
    {

        // если не установлен name, то ошибка формата данных
        if (! isset($data['name'])) {
            throw new Exception('Некорректная структура данных $_FILES: ' . var_export($data, true));
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
        return ! preg_match('~^\d+$~', array_key_first($data['name']));
    }

    // @formatter:off
    /**
     * Парсит структуру $_FILES и создает объекты
     *
     * @return array
     * <xmp>[
     *      $formName => [
     *          $attribute => \dicr\file\UploadFile[]
     *      ]
     * ]
     * </xmp>
     */
    // @formatter:on
    protected static function parseInstances()
    {
        $instances = [];

        if (! empty($_FILES)) {
            foreach ($_FILES as $key => $data) {
                if (static::detectFormData($data)) {
                    // аттрибуты формы: $key == $formName, $data == аттрибуты в формате формы
                    $instances[$key] = static::parseFormData($data);
                } else {
                    // аттрибут без формы, $formName == '', $key == $attribute, $data == данные в формате одного аттрибуты
                    $instances[''][$key] = static::parseAttribData($data);
                }
            }
        }

        return $instances;
    }

    /**
     * Возвращает файлы аттрибутов формы загруженные в $_FILES или null.
     *
     * @param string $formName имя формы для которой возвращает аттрибуты
     * @param string $attribute если задан, то возвращает файлы только для аттрибута
     * @return mixed
     */
    public static function instances(string $formName = '', string $attribute = '')
    {
        if (! isset(self::$instances)) {
            self::$instances = self::parseInstances();
        }

        $path = [trim($formName)];

        if ($attribute !== '') {
            $path[] = $attribute;
        }

        return ArrayHelper::getValue(self::$instances, $path);
    }
}
