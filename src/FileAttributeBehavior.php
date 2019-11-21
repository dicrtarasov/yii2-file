<?php
/**
 * Copyright (c) 2019.
 *
 * @author Igor A Tarasov <develop@dicr.org>
 */

declare(strict_types = 1);
namespace dicr\file;

use LogicException;
use Yii;
use yii\base\Behavior;
use yii\base\Exception;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\base\Model;
use yii\db\ActiveRecord;
use yii\di\Instance;
use function array_key_exists;
use function array_slice;
use function count;
use function gettype;
use function is_array;

// @formatter:off
/**
 * Файловые аттрибуты модели.
 *
 * <xmp>
 * class TestModel extends Model {
 *  public function behaviors() {
 *      return [
 *          'fileAttribute' => [
 *              'class' => FileAttributeBehavior::class,
 *              'attributes' => [           // файловые аттрибуты модели
 *                  'icon' => 1,            // значение аттрибута - один файл
 *                  'thumb' => 10,          // значение аттрибута - массив до 10 файлов
 *                  'pics' => [             // параметры в виде массива
 *                      'limit' => 0        // массив файлов без ограничений по кол-ву
 *                  ]
 *              ]
 *          ]
 *      ]
 *  }
 * }
 * </xmp>
 *
 * <xmp>
 * attributes - массив обрабатываемых аттрибутов.
 *  Ключ - название аттрибута,
 *  Значение - массив параметров атрибута:
*       - int|null $min - минимальное требуемое кол-во файлов
 *      - int|null $limit - ограничение количества файлов.
 *          - если $limit == 0,
 *              то значение аттрибута - массив файлов \dicr\file\AbstractFile[] без ограничения на кол-во
 *          - если $limit - число,
 *              то значение аттрибута - массив \dicr\file\AbstractFile[] с ограничением кол-ва файлов limit
 *          - если $limit == 1,
 *              то значение атрибута - один файл \dicr\file\AbstractFile
*       - int|null $maxsize - максимальный размер
*       - string|null $type mime-ип загруженных файлов
 * </xmp>
 *
 * Если значение не массив, то оно принимается в качестве limit.
 *
 * Типы значения аттрибутов:
 *    \dicr\file\StoreFile - для аттрибутов с limit = 1;
 *    \dicr\file\StoreFile[] - для аттрибутов с limit != 1;
 *
 * Данный behavior реализует get/set методы для заданных аттрибутов,
 * поэтому эти методы не должны быть отпередены в модели.
 *
 * Для установки значений нужно либо присвоить новые значени типа UploadFile:
 *
 * <xmp>
 * $model->icon = new UploadFile('/tmp/new_file.jpg');
 * </xmp>
 *
 * <xmp>
 * $model->pics = [
 *    new UploadFile('/tmp/pic1.jpg'),
 *    new UploadFIle('/tmp/pic2.jpg')
 * ];
 * </xmp>
 *
 * Для установки значений из загруженных файлах в POST $_FILES нужно вызвать loadAttributes():
 *
 * $model->loadAttributes();
 *
 * По событию afterValidate выполняется проверка файловых аттрибутов на допустимые значения.
 *
 * Для сохранения значений файловых аттрибутов модели behavior реализует метод saveFileAttributes():
 *
 * $model->saveFileAttributes();
 *
 * Если модель типа ActiveRecord, то данный behavior перехватывает событие onSave и вызывает
 * saveAttributes автоматически при сохранении модели:
 *
 * $model->save();
 *
 * При сохранении аттрибута все новые файлы UploadFiles импортируются в хранилище файлов
 * модели и заменяются значениями типа File. Все существующие файлы в хранилище модели, которых
 * нет среди значения аттрибута удаляются.
 *
 * Типичный сценарий обработки запроса POST модели с файловыми аттрибутами:
 *
 * <xmp>
 * if (\Yii::$app->request->isPost
 *      && $model->load(\Yii::$app->request->post())
 *      && $model->loadFileAttributes()
 *      && $model->validate()
 * ) {
 *      if ($model instanceof ActiveRecord) {
 *          $model->save();
 *      } else {
 *          $model->saveFileAttributes();
 *      }
 * }
 * </xmp>
 *
 * Для вывода картинок можно использовать такой сценарий. Если загрузка файлов не удалась
 * или validate модели содержит ошибки и модель не сохранена, то UploadFiles не импортировались,
 * а значит из нужно пропустить при выводе модели:
 *
 * <xmp>
 * <?=$model->icon instanceof StoreFile ? Html::img($model->icon->url) : ''?>
 * </xmp>
 *
 * <xmp>
 * <?php foreach ($model->pics as $pic) {?>
 *      <?php if ($pic instanceof StoreFile) {?>
 *          <?=Html::img($pic->url)?>
 *      <?php }?>
 * <?php } ?>
 * </xmp>
 *
 * @property \dicr\file\StoreFile $fileModelPath путь папки модели
 */
// @formatter:on
class FileAttributeBehavior extends Behavior
{
    /** @var \dicr\file\AbstractFileStore|string|array хранилище файлов */
    public $store = 'fileStore';

    /**
     * @var array конфигурация аттрибутов [ attributeName => limit ]
     *      Ключ - название аттрибута, limit - ограничение кол-ва файлов.
     *      Если $limit == 1, то аттрибут $model->{attribute} имеет тип File,
     *      если $limit !== 1, $model->{attribute} имеет тип массива File[]
     */
    public $attributes;

    /** @var \dicr\file\StoreFile[][] текущие значения аттрибутов [attributeName => \dicr\file\StoreFile[]] */
    private $values = [];

    /** @var \dicr\file\StoreFile путь папки модели */
    private $_modelPath;

    /**
     * {@inheritdoc}
     * @throws \yii\base\InvalidConfigException
     * @see \yii\base\BaseObject::init()
     */
    public function init()
    {
        // owner не инициализирован пока не вызван attach

        // получаем store
        $this->store = Instance::ensure($this->store, AbstractFileStore::class);

        // проверяем наличие аттрибутов
        if (empty($this->attributes) || ! is_array($this->attributes)) {
            throw new InvalidConfigException('attributes');
        }

        // конвертируем упрощеный формат аттрибутов в полный
        foreach ($this->attributes as $attribute => &$params) {
            if (is_array($params)) {
                $params['limit'] = (int)($params['limit'] ?? 0);
            } else {
                $params = ['limit' => (int)$params];
            }
        }

        unset($params);

        parent::init();
    }

    /**
     * {@inheritdoc}
     * @see \yii\base\Behavior::events()
     */
    public function events()
    {
        return [
            Model::EVENT_BEFORE_VALIDATE => 'validateFileAttributes',
            ActiveRecord::EVENT_AFTER_INSERT => 'saveFileAttributes',
            ActiveRecord::EVENT_AFTER_UPDATE => 'saveFileAttributes',
            ActiveRecord::EVENT_AFTER_DELETE => 'deleteFileModelPath'
        ];
    }

    /**
     * Устанавливает путь папки модели.
     * Если путь не установлен, то он рассчитывается автоматически.
     *
     * @param StoreFile $modelPathFile
     */
    public function setFileModelPath(StoreFile $modelPathFile)
    {
        $this->_modelPath = $modelPathFile;
    }

    /**
     * Удаляет папку модели.
     * (нужен для обработчика событий модели).
     *
     * @return \dicr\file\StoreFile путь удаленной директории модели
     * @throws \dicr\file\StoreException
     */
    public function deleteFileModelPath()
    {
        $path = $this->fileModelPath;
        if ($path !== null) {
            $path->delete();
        }

        return $path;
    }

    /**
     * Загружает файловые аттрибуты из $_POST и $FILES
     *
     * @param string $formName имя формы модели
     * @return boolean true если данные некоторых атрибутов были загружены
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     */
    public function loadFileAttributes(string $formName = null)
    {
        $ret = false;

        foreach (array_keys($this->attributes) as $attribute) {
            $ret = $this->loadFileAttribute($attribute, $formName) || $ret;
        }

        return $ret;
    }

    /**
     * Загружает значения аттрибута из данных в $_POST и $_FILES
     *
     * @param string $attribute имя загружаемого файлового аттрибута
     * @param string|null $formName имя формы модели
     * @return bool true если значение было загружено (присутствуют отправленные данные)
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     */
    public function loadFileAttribute(string $attribute, string $formName = null)
    {
        $this->checkOwner();
        $this->checkFileAttribute($attribute);

        // имя формы
        if (empty($formName)) {
            /** @scrutinizer ignore-call */
            $formName = $this->owner->formName();
        }

        // проверяем что была отправка формы с данными аттрибута
        $post = (Yii::$app->request->post())[$formName][$attribute] ?? null;
        $files = UploadFile::instances($formName, $attribute);

        if (! isset($post) && ! isset($files)) {
            return false;
        }

        // путь аттрибута модели (null если это не сохраненная ActiveRecord без id)
        $attributePath = $this->getFileModelPath();

        // новое значение аттрибута
        $value = [];

        // для начала просматриваем данные $_POST с именами старых файлов для сохранения
        if (! empty($post) && $attributePath !== null) {
            foreach ($post as $pos => $name) {
                // пропускаем пустые значения
                $name = basename($name);
                if ($name === '') {
                    continue;
                }

                // устанавливаем в заданной позиции объект File старого файла
                $value[$pos] = $attributePath->child($name);
            }
        }

        // перезаписываем позиции из $_POST загруженными файлами из $_FILE
        if (! empty($files)) {
            foreach ($files as $pos => $file) {
                $value[$pos] = $file;
            }
        }

        // сортируем по позициям и переиндексируем
        ksort($value);
        $this->values[$attribute] = array_values($value);

        return true;
    }

    /**
     * Проверяет подключенную модель
     *
     * @throws InvalidConfigException
     */
    protected function checkOwner()
    {
        if (! ($this->owner instanceof Model)) {
            throw new InvalidConfigException('owner');
        }
    }

    /**
     * Проверяет существование файлового атрибута
     *
     * @param string $attribute
     * @throws Exception
     */
    protected function checkFileAttribute(string $attribute)
    {
        if (! $this->hasFileAttribute($attribute)) {
            throw new Exception('файловый аттрибут "' . $attribute . '" не существует');
        }
    }

    /**
     * Проверяет существование файлового атрибута
     *
     * @param string $attribute
     * @return boolean
     */
    public function hasFileAttribute(string $attribute)
    {
        return array_key_exists($attribute, $this->attributes);
    }

    /**
     * Возвращает папку модели в хранилище файлов.
     *
     * Путь модели в хранилище строится из:
     * {formName}/{primaryKeys}
     *
     * @return \dicr\file\StoreFile|null
     * @throws \yii\base\InvalidConfigException
     */
    public function getFileModelPath()
    {
        if (! isset($this->_modelPath)) {
            // проверяем владельца поведения
            $this->checkOwner();

            // относительный путь
            $relpath = [];

            // добавляем в путь имя формы
            /** @scrutinizer ignore-call */
            $formName = $this->owner->formName();
            if (! empty($formName)) {
                $relpath[] = $formName;
            }

            // для элементов базы данных добавляем id
            if ($this->owner instanceof ActiveRecord) {
                // если модель базы данных еще не сохранена, то нет пути
                if ($this->owner->isNewRecord) {
                    return null;
                }

                // добавляем ключ
                $keyName = basename(implode('~', $this->owner->getPrimaryKey(true)));
                if ($keyName !== '') {
                    $relpath[] = $keyName;
                }
            }

            $this->_modelPath = $this->store->file($relpath);
        }

        return $this->_modelPath;
    }

    /**
     * Проводит валидацию файловых аттрибутов.
     * Добавляет ошибки модели по addError.
     *
     * @return boolean true, если все проверки успешны
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     */
    public function validateFileAttributes()
    {
        $ret = true;

        foreach (array_keys($this->attributes) as $attribute) {
            $res = $this->validateFileAttribute($attribute);

            // пропускаем null когда аттрибут не проходил проверку потому что не инициализирован
            if ($res !== null) {
                $ret = $ret && $res;
            }
        }

        return $ret;
    }

    /**
     * Проводит валидацию файлового аттрибута и загруженных файлов.
     * Добавляет ошибки модели по addError
     *
     * @param string $attribute
     * @return bool|null результаты проверки или null, если атрибут не инициализирован
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     */
    public function validateFileAttribute(string $attribute)
    {
        $this->checkOwner();
        $this->checkFileAttribute($attribute);

        // получаем текущее значение
        $files = $this->values[$attribute] ?? null;

        // если атрибут не был инициализирован, то пропускаем проверку
        if (! isset($files)) {
            return null;
        }

        // получаем парамеры атрибута
        $params = $this->attributes[$attribute];

        // минимальное количество
        if (! empty($params['min']) && count($files) < $params['min']) {
            /** @scrutinizer ignore-call */
            $this->owner->addError($attribute, 'Необходимо не менее ' . (int)$params['min'] . ' количество файлов');
        }

        // максимальное количество
        if (! empty($params['limit']) && count($files) > $params['limit']) {
            /** @scrutinizer ignore-call */
            $this->owner->addError($attribute, 'Максимальное кол-во файлов: ' . (int)$params['limit']);
        }

        // проверяем каждый файл
        foreach ($files as $file) {
            $this->validateFile($attribute, $file);
        }

        /** @scrutinizer ignore-call */
        return empty($this->owner->getErrors($attribute));
    }

    /**
     * Выполняет валидаию файла файлового атибута.
     *
     * @param string $attribute
     * @param mixed $file
     */
    protected function validateFile(string $attribute, $file)
    {
        // параметры аттрибута
        $params = $this->attributes[$attribute] ?? [];

        // проверяем на пустое значение
        if (empty($file)) {
            /** @scrutinizer ignore-call */
            $this->owner->addError($attribute, 'Пустое значение файла');
        } elseif (! ($file instanceof StoreFile)) {
            /** @scrutinizer ignore-call */
            $this->owner->addError($attribute, 'Некоррекный тип значения: ' . gettype($file));
        } elseif (! $file->exists) {
            /** @scrutinizer ignore-call */
            $this->owner->addError($attribute, 'Загружаемый файл не существует: ' . $file->path);
        } elseif ($file->size <= 0) {
            /** @scrutinizer ignore-call */
            $this->owner->addError($attribute, 'Пустой размер файла: ' . $file->name);
        } elseif (isset($params['maxsize']) && $file->size > $params['maxsize']) {
            /** @scrutinizer ignore-call */
            $this->owner->addError($attribute, 'Размер не более ' . Yii::$app->formatter->asSize($params['maxsize']));
        } elseif (isset($params['type']) && ! $file->matchMimeType($params['type'])) {
            /** @scrutinizer ignore-call */
            $this->owner->addError($attribute, 'Неверный тип файла: ' . $file->mimeType);
        } elseif ($file instanceof UploadFile) {
            if (! empty($file->error)) {
                /** @scrutinizer ignore-call */
                $this->owner->addError($attribute, 'Ошибка загрузки файла');
            } elseif (empty($file->name)) {
                /** @scrutinizer ignore-call */
                $this->owner->addError($attribute, 'Не задано имя загруаемого файла: ' . $file->path);
            }
        }
    }

    /**
     * Сохраняет файловые аттрибуты.
     * Выполняет импорт загруженных файлов и удаление старых
     *
     * @return bool резульаты сохранения
     * @throws \dicr\file\StoreException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     */
    public function saveFileAttributes()
    {
        $ret = true;

        foreach (array_keys($this->attributes) as $attribute) {
            $res = $this->saveFileAttribute($attribute);

            // пропускаем если атрибут не инициализирован
            if ($res === false) {
                $ret = false;
            }
        }

        return $ret;
    }

    /**
     * Сохраняет значение аттрибута.
     * Загружает новые файлы (UploadFiles) и удаляется старые Files, согласно текущему значению аттрибута.
     *
     * @param string $attribute
     * @return bool|null результат сохранения или null, если аттрибут не инициализирован
     * @throws \dicr\file\StoreException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     */
    public function saveFileAttribute(string $attribute)
    {
        $this->checkOwner();
        $this->checkFileAttribute($attribute);
        $modelPath = $this->getFileModelPath();

        // проверяем что модель сохранена перед тем как сохранять ее файлы
        if ($modelPath === null) {
            throw new LogicException('модель еще не сохранена');
        }

        // текущее значение аттрибута
        $files = $this->values[$attribute] ?? null;

        // если новые значения не установлены, то сохранять не нужно
        if (! isset($files)) {
            return null;
        }

        // получаем старые файлы
        $oldFiles = $this->listAttributeFiles($attribute);

        // импортируем новые и переименовываем старые во временные имена с точкой
        foreach ($files as $pos => &$file) {
            // некоррекный тип значения
            if (! ($file instanceof StoreFile)) {
                throw new Exception('Неизвестный тип значения файлового аттрибута ' . $attribute);
            }

            // если это загружаемый файл и содержит ошибку загрузки, то пропускаем
            if (($file instanceof UploadFile) && ! empty($file->error)) {
                // если это загружаемый файл и содержит ошибки - пропускаем
                unset($files[$pos]);
                continue;
            }

            // если файл не существует
            if (! $file->exists) {
                // если файл в том же хранилище, то мог быть удален в параллельном запросе
                if ($file->store === $this->store) {
                    unset($files[$pos]);
                    continue;
                }

                // выдаем ошибку
                throw new Exception('Файл не существует: ' . $file->path);
            }

            // если файл в том же хранилище
            if ($file->store === $this->store) {
                // ищем позицию в списке старых
                $oldPos = self::searchFileByName($file, $oldFiles);

                // если файл найден в списке старых, то файл нужно сохранить как есть
                if (isset($oldPos)) {
                    // удаляем из списка старых на удаление
                    unset($oldFiles[$oldPos]);

                    // переименовываем во временное имя
                    $file->name = StoreFile::createStorePrefix('.' . $attribute, mt_rand(), $file->name);
                    continue;
                }
            }

            // импорируем файл под временным именем
            $newFile = $modelPath->child(StoreFile::createStorePrefix('.' . $attribute, mt_rand(), $file->name));
            $newFile->import($file);
            $file = $newFile;
        }

        // перед тем как использовать ссылку нужно деинициализировать
        unset($file);

        // удаляем оставшиеся старые файлы которых не было в списке для сохранения
        foreach ($oldFiles as $file) {
            $file->delete();
        }

        // переименовываем файлы в правильные имена
        ksort($files);
        foreach (array_values($files) as $pos => $file) {
            $file->name = StoreFile::createStorePrefix($attribute, $pos, $file->name);
            $files[$pos] = $file;
        }

        // обновляем значение аттрибута модели
        $this->values[$attribute] = $files;

        return true;
    }

    /**
     * Получает список файлов аттрибута модели.
     *
     * @param string $attribute аттрибут
     * @return \dicr\file\StoreFile[] файлы
     * @throws \dicr\file\StoreException
     * @throws \yii\base\InvalidConfigException
     */
    protected function listAttributeFiles(string $attribute)
    {
        // путь папки модели
        $modelPath = $this->getFileModelPath();

        // если папка не существует, то возвращаем пустой список
        if ($modelPath === null || ! $modelPath->exists) {
            return [];
        }

        // получаем список файлов
        $files = $modelPath->getList([
            'nameRegex' => '~^' . preg_quote($attribute, '~') . '\~\d+\~.+~ui'
        ]);

        // сортируем по полному пути (path/model/id/{attribute}-{pos}-{filename}.ext)
        usort($files, static function(StoreFile $a, StoreFile $b) {
            return strnatcasecmp($a->path, $b->path);
        });

        return $files;
    }

    /**
     * Находит позицию файла в списке файлов по имени.
     *
     * @param \dicr\file\StoreFile $file
     * @param \dicr\file\StoreFile[] $files
     * @return int|null
     */
    protected static function searchFileByName(StoreFile $file, array $files)
    {
        $name = $file->name;

        foreach ($files as $i => $f) {
            if ($f->name === $name) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Удаляет все файлы всех аттритутов
     *
     * @return true
     * @throws \dicr\file\StoreException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     */
    public function deleteFileAttributes()
    {
        foreach (array_keys($this->attributes) as $attribute) {
            $this->deleteFileAttribute($attribute);
        }

        return true;
    }

    /**
     * Удаляет все файлы аттрибута
     *
     * @param string $attribute
     * @return true
     * @throws \dicr\file\StoreException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     */
    public function deleteFileAttribute(string $attribute)
    {
        $this->checkFileAttribute($attribute);
        $this->checkOwner();

        // удаляем файлы аттрибута
        foreach ($this->listAttributeFiles($attribute) as $file) {
            $file->delete();
        }

        // обновляем значение
        $this->values[$attribute] = [];

        return true;
    }

    /**
     * {@inheritdoc}
     * @see \yii\base\BaseObject::__isset()
     */
    public function __isset($name)
    {
        if ($this->hasFileAttribute($name)) {
            return isset($this->attributes[$name]);
        }

        return parent::__isset($name);
    }

    /**
     * {@inheritdoc}
     * @throws \yii\base\Exception
     * @see \yii\base\BaseObject::__get()
     */
    public function __get($name)
    {
        if ($this->hasFileAttribute($name)) {
            return $this->getFileAttribute($name);
        }

        return parent::__get($name);
    }

    /**
     * {@inheritdoc}
     * @throws \yii\base\Exception
     * @see \yii\base\BaseObject::__set()
     */
    public function __set($name, $value)
    {
        if ($this->hasFileAttribute($name)) {
            $this->setFileAttribute($name, $value);
        } else {
            parent::__set($name, $value);
        }
    }

    /**
     * Возвращает значение файлового аттрибута
     *
     * @param string $attribute
     * @param bool $refresh
     * @return null|\dicr\file\StoreFile|\dicr\file\StoreFile[]
     * @throws \yii\base\Exception
     */
    public function getFileAttribute(string $attribute, bool $refresh = false)
    {
        $this->checkFileAttribute($attribute);

        if (! isset($this->values[$attribute]) || $refresh) {
            $this->values[$attribute] = $this->listAttributeFiles($attribute);
        }

        $vals = $this->values[$attribute];

        // если аттрибут имеет скалярный тип, то возвращаем первое значение
        if ((int)$this->attributes[$attribute]['limit'] === 1) {
            return ! empty($vals) ? reset($vals) : null;
        }

        return $vals;
    }

    /**
     * Устанавливает значение файлового аттрибута
     *
     * @param string $attribute
     * @param null|\dicr\file\StoreFile|\dicr\file\StoreFile[] $files
     * @return static
     * @throws \yii\base\Exception
     */
    public function setFileAttribute(string $attribute, $files)
    {
        $this->checkFileAttribute($attribute);

        // конвертируем значение в массив (нельзя (array), потому что Model::toArray)
        if (empty($files)) {
            $files = [];
        } elseif (! is_array($files)) {
            $files = [$files];
        }

        // переиндексируем
        ksort($files);
        $files = array_values($files);

        // проверяем элементы массива
        foreach ($files as $file) {
            if (! ($file instanceof StoreFile)) {
                throw new InvalidArgumentException('files: неокрректный тип элемента');
            }
        }

        // ограничиваем размер по limit
        $limit = $this->attributes[$attribute]['limit'];
        if ($limit > 0) {
            $files = array_slice($files, 0, $limit);
        }

        // обновляем значение в кеше
        $this->values[$attribute] = $files;

        return $this;
    }

    /**
     * {@inheritdoc}
     * @see \yii\base\BaseObject::hasProperty()
     */
    public function hasProperty($name, $checkVars = true)
    {
        if ($this->hasFileAttribute($name)) {
            return true;
        }

        return parent::hasProperty($name, $checkVars);
    }

    /**
     * {@inheritdoc}
     * @see \yii\base\BaseObject::canGetProperty()
     */
    public function canGetProperty($name, $checkVars = true)
    {
        if ($this->hasFileAttribute($name)) {
            return true;
        }

        return parent::canGetProperty($name, $checkVars);
    }

    /**
     * {@inheritdoc}
     * @see \yii\base\BaseObject::canSetProperty()
     */
    public function canSetProperty($name, $checkVars = true)
    {
        if ($this->hasFileAttribute($name)) {
            return true;
        }

        return parent::canSetProperty($name, $checkVars);
    }
}
