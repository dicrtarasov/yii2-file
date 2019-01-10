<?php
namespace dicr\file;

use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;
use yii\helpers\ArrayHelper;

/**
 * Загруженный файл.
 * Файл необхоимо импортировать в директорию модели при ее сохранении.
 * В зависимости от присутствия названия формы и множественности аттрибута,
 * php формирует разную структуру $_FILES.
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2018
 */
class UploadFile extends File
{
    /** @var string наименование файла */
    public $name;

    /** @var int размер файла */
    public $size;

    /** @var string mime-type */
    public $mimeType;

    /** @var int ошибка загрузки */
    public $error;

    /** @var array [$formName => [$attribute => \dicr\file\UploadFile[] ]] кэш распарсенных объектов */
    private static $instances;

    /**
     * Конструктор
     *
     * @param array|string $config конфиг объекта или путь файла
     */
    public function __construct($config = [])
    {
        // конвертируем путь файла в конфиг
        if (is_string($config)) {
            $config = ['path' => $config];
        }

        parent::__construct($config);
    }

    /**
     * {@inheritdoc}
     * @see \yii\base\BaseObject::init()
     */
    public function init()
    {
        if (isset($this->size)) {
            $this->size = (int) $this->size;
        }

        $this->error = (int) $this->error;

        // в случае ошибок name и path может быть пустым
        if (empty($this->error)) {

            // путь должен быть задан
            if (! isset($this->path)) {
                throw new InvalidConfigException('path');
            }

            if (! isset($this->name)) {
                $this->name = $this->path;
            }

            $this->name = basename($this->name);
            if ($this->name == '') {
                throw new InvalidConfigException('name');
            }
        }
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\File::getPath()
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\File::setPath()
     */
    public function setPath(string $path)
    {
        $this->path = rtrim($this->path);
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\File::getFullPath()
     */
    public function getFullPath()
    {
        return $this->path;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\File::getName()
     */
    public function getName(array $options = [])
    {
        return $this->name ?? basename($this->path);
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\File::setName()
     */
    public function setName(string $name)
    {
        $this->name = $name;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\File::rename()
     */
    public function rename()
    {
        throw new NotSupportedException();
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\File::getUrl()
     */
    public function getUrl()
    {
        return null;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\File::getExists()
     */
    public function getExists()
    {
        return @file_exists($this->path);
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\File::getType()
     */
    public function getType()
    {
        return File::TYPE_FILE;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\File::getAccess()
     */
    public function getAccess()
    {
        return File::ACCESS_PRIVATE;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\File::setAccess()
     */
    public function setAccess(string $access)
    {
        throw new NotSupportedException();
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\File::getIsHidden()
     */
    public function getIsHidden()
    {
        return false;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\File::getSize()
     */
    public function getSize()
    {
        if (! isset($this->size)) {
            $this->size = @filesize($this->path);
        }

        if ($this->size === false) {
            throw new StoreException($this->path);
        }

        return $this->size;
    }

    /**
     * Возвращает время изменения файла
     *
     * @throws StoreException
     * @return int timestamp
     */
    public function getMtime()
    {
        return @filemtime($this->path);
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\File::getMimeType()
     */
    public function getMimeType()
    {
        return $this->mimeType;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\File::getContents()
     */
    public function getContents()
    {
        $contents = @file_get_contents($this->path);
        if ($contents === false) {
            throw new StoreException();
        }
        return $contents;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\File::setContents()
     */
    public function setContents()
    {
        throw new NotSupportedException();
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\File::getStream()
     */
    public function getStream()
    {
        $stream = @fopen($this->path, 'rb');
        if ($stream === false) {
            throw new StoreException();
        }
        return $stream;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\File::setStream()
     */
    public function setStream()
    {
        throw new NotSupportedException();
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\File::copy()
     */
    public function copy(string $dst)
    {
        $ret = @copy($this->path, $dst);
        if ($ret === false) {
            throw new StoreException();
        }
        return $this;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\File::move()
     */
    public function move(string $dst)
    {
        $ret = @rename($this->path, $dst);
        if ($ret === false) {
            throw new StoreException();
        }
        return $this;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\File::mkdir()
     */
    public function mkdir()
    {
        throw new NotSupportedException();
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\File::checkDir()
     */
    public function checkDir()
    {
        return $this;
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\File::child()
     */
    public function child(string $relpath)
    {
        throw new NotSupportedException();
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\File::getList()
     */
    public function getList(array $options = [])
    {
        throw new NotSupportedException();
    }

    /**
     * {@inheritdoc}
     * @see \dicr\file\File::delete()
     */
    public function delete()
    {
        // ошибки не важны, потому как загруженные файлы удаляются автоматически
        @unkink($this->path);
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

            $instances[$pos] = new static(
                ['path' => $path,'name' => $name,'mimeType' => $types[$pos] ?? null,'size' => $sizes[$pos] ?? null,
                    'error' => $errors[$pos] ?? null]);
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
        return static::attributeInstances((array) ($data['name'] ?? []), (array) ($data['type'] ?? []),
            (array) ($data['size'] ?? []), (array) ($data['error'] ?? []), (array) ($data['tmp_name'] ?? []));
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
            $instances[$attribute] = static::attributeInstances((array) $data['name'][$attribute],
                (array) ($data['type'][$attribute] ?? []), (array) ($data['size'][$attribute] ?? []),
                (array) ($data['error'][$attribute] ?? []), (array) ($data['tmp_name'][$attribute] ?? []));
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
        $keys = array_keys($data['name']);
        return ! preg_match('~^\d+$~', $keys[0]);
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
