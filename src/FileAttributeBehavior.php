<?php
/*
 * @copyright 2019-2020 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license MIT
 * @version 25.09.20 00:53:54
 */

declare(strict_types = 1);
namespace dicr\file;

use LogicException;
use RuntimeException;
use Yii;
use yii\base\Behavior;
use yii\base\Exception;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\base\Model;
use yii\db\ActiveRecord;
use yii\di\Instance;

use function array_key_exists;
use function array_keys;
use function array_slice;
use function array_values;
use function basename;
use function count;
use function gettype;
use function implode;
use function is_array;
use function ksort;
use function mt_rand;
use function preg_quote;
use function reset;
use function str_replace;
use function strnatcasecmp;
use function usort;

/**
 * Файловые аттрибуты модели.
 *
 * Добавляет следующие свойства модели:
 * -method bool loadFileAttributes($formName = null)
 * -method saveFileAttributes()
 * -method StoreFile|StoreFile[]|null getFileAttribute(string $attribute, bool $refresh = false)
 *
 * ```php
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
 * ```
 *
 * attributes - массив обрабатываемых аттрибутов.
 *   - ключ - название аттрибута,
 *   - значение - массив параметров атрибута:
 *     - int|null $min - минимальное требуемое кол-во файлов
 *     - int|null $limit - ограничение количества файлов.
 *       - если $limit == 1, то значение атрибута - один файл \dicr\file\AbstractFile
 *       - если $limit == 0, то значение аттрибута - массив файлов \dicr\file\AbstractFile[] без ограничения на кол-во
 *       - если $limit число, то значение - массив \dicr\file\AbstractFile[] с ограничением кол-ва файлов limit
 *     - int|null $maxsize - максимальный размер
 *     - string|null $type mime-ип загруженных файлов
 *
 * Если значение не массив, то оно принимается в качестве limit.
 *
 * Типы значения аттрибутов:
 *    \dicr\file\StoreFile - для аттрибутов с limit = 1;
 *    \dicr\file\StoreFile[] - для аттрибутов с limit != 1;
 *
 * Данный behavior реализует get/set методы для заданных аттрибутов,
 * поэтому эти методы не должны быть определены в модели.
 *
 * Для установки значений нужно либо присвоить новые значения типа StoreFile:
 *
 * ```php
 * $model->icon = new UploadFile('/tmp/new_file.jpg');
 *
 * $model->pics = [
 *    new UploadFile('/tmp/pic1.jpg'),
 *    new UploadFIle('/tmp/pic2.jpg')
 * ];
 * ```
 *
 * Для установки значений из загруженных файлах в POST $_FILES нужно вызвать loadAttributes():
 *
 * ```php
 * $model->loadAttributes();
 * ```
 *
 * По событию afterValidate выполняется проверка файловых аттрибутов на допустимые значения.
 *
 * Для сохранения значений файловых аттрибутов модели behavior реализует метод saveFileAttributes():
 *
 * ```php
 * $model->saveFileAttributes();
 * ```
 *
 * Если модель типа ActiveRecord, то данный behavior перехватывает событие onSave и вызывает
 * saveAttributes автоматически при сохранении модели:
 *
 * ```php
 * $model->save();
 * ```
 *
 * При сохранении аттрибута все новые файлы UploadFiles импортируются в хранилище файлов
 * модели и заменяются значениями типа File. Все существующие файлы в хранилище модели, которых
 * нет среди значения аттрибута удаляются.
 *
 * Типичный сценарий обработки запроса POST модели с файловыми аттрибутами:
 *
 * ```php
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
 * ```
 *
 * Для вывода картинок можно использовать такой сценарий. Если загрузка файлов не удалась
 * или validate модели содержит ошибки и модель не сохранена, то UploadFiles не импортировались,
 * а значит из нужно пропустить при выводе модели:
 *
 * ```php
 * echo $model->icon instanceof StoreFile ? Html::img($model->icon->url) : '';
 *
 * foreach ($model->pics as $pic) {
 *     if ($pic instanceof StoreFile) {
 *         echo Html::img($pic->url);
 *     }
 * }
 * ```
 *
 * @property-read Model $owner
 * @property ?StoreFile $modelFilePath путь папки модели
 * @property-read ?StoreFile $modelThumbPath папка кэша картинок модели
 * @property StoreFile[]|StoreFile|null $fileAttributeValue
 */
class FileAttributeBehavior extends Behavior
{
    /** @var AbstractFileStore|string|array хранилище файлов */
    public $store = 'fileStore';

    /**
     * @var array конфигурация аттрибутов [ attributeName => limit ]
     *      Ключ - название аттрибута, limit - ограничение кол-ва файлов.
     *      Если $limit == 1, то аттрибут $model->{attribute} имеет тип File,
     *      если $limit !== 1, $model->{attribute} имеет тип массива File[]
     */
    public $attributes;

    /**
     * {@inheritdoc}
     * @throws InvalidConfigException
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

        // конвертируем упрощенный формат аттрибутов в полный
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
     */
    public function events()
    {
        return [
            Model::EVENT_BEFORE_VALIDATE => 'validateFileAttributes',
            ActiveRecord::EVENT_AFTER_INSERT => 'saveFileAttributes',
            ActiveRecord::EVENT_AFTER_UPDATE => 'saveFileAttributes',
            ActiveRecord::EVENT_AFTER_DELETE => 'deleteModelFilePath',
        ];
    }

    /**
     * @inheritDoc
     * @throws InvalidConfigException
     */
    public function attach($owner)
    {
        if (! $owner instanceof Model) {
            throw new InvalidConfigException('Некорректный тип владельца: ' . gettype($owner));
        }

        parent::attach($owner);
    }

    /**
     * {@inheritdoc}
     */
    public function __isset($name)
    {
        return $this->hasFileAttribute($name) ?
            isset($this->attributes[$name]) : parent::__isset($name);
    }

    /**
     * {@inheritdoc}
     * @throws Exception
     */
    public function __get($name)
    {
        return $this->hasFileAttribute($name) ?
            $this->getFileAttributeValue($name) : parent::__get($name);
    }

    /**
     * {@inheritdoc}
     * @throws Exception
     */
    public function __set($name, $value)
    {
        if ($this->hasFileAttribute($name)) {
            $this->setFileAttributeValue($name, $value);
        } else {
            parent::__set($name, $value);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function hasProperty($name, $checkVars = true)
    {
        return $this->hasFileAttribute($name) || parent::hasProperty($name, $checkVars);
    }

    /**
     * {@inheritdoc}
     */
    public function canGetProperty($name, $checkVars = true)
    {
        return $this->hasFileAttribute($name) || parent::canGetProperty($name, $checkVars);
    }

    /**
     * {@inheritdoc}
     */
    public function canSetProperty($name, $checkVars = true)
    {
        return $this->hasFileAttribute($name) || parent::canSetProperty($name, $checkVars);
    }

    /**
     * Проверяет существование файлового атрибута
     *
     * @param string $attribute
     * @return bool
     */
    public function hasFileAttribute(string $attribute): bool
    {
        return array_key_exists($attribute, $this->attributes);
    }

    /**
     * Проверяет существование файлового атрибута.
     *
     * @param string $attribute
     * @return $this
     */
    protected function checkIsFileAttribute(string $attribute): self
    {
        if (! $this->hasFileAttribute($attribute)) {
            throw new RuntimeException(
                'Файловый аттрибут: ' . $attribute .
                ' модели: ' . gettype($this->owner) . ' не существует'
            );
        }

        return $this;
    }

    /** @var StoreFile путь папки модели */
    private $_modelFilePath;

    /**
     * Возвращает папку модели в хранилище файлов.
     *
     * Путь модели в хранилище строится из:
     * {formName}/{primaryKeys}
     *
     * @return ?StoreFile (null если модель типа ActiveRecord еще не сохранена и поэтому не имеет значений primaryKey)
     * @throws InvalidConfigException
     */
    public function getModelFilePath(): ?StoreFile
    {
        if (! isset($this->_modelFilePath)) {
            // относительный путь
            $relpath = [];

            // добавляем в путь имя формы
            /** @scrutinizer ignore-call */
            $formName = $this->owner->formName();
            if (! empty($formName)) {
                $relpath[] = $formName;
            }

            // если это модель базы данных и имеется primaryKey
            if (($this->owner instanceof ActiveRecord) && ! empty($this->owner::primaryKey())) {
                // получаем значения ключей
                $primaryKey = $this->owner->getPrimaryKey(true);

                // если модель не сохранена и основные ключи не установлены, то возвращаем null
                foreach ($primaryKey as $val) {
                    if ($val === null) {
                        return null;
                    }
                }

                // добавляем ключи к пути
                $relpath[] = str_replace(
                    $this->store->pathSeparator, '_', implode('-', $primaryKey)
                );
            }

            $this->_modelFilePath = $this->store->file($relpath);
        }

        return $this->_modelFilePath;
    }

    /**
     * Устанавливает путь папки модели.
     * Если путь не установлен, то он рассчитывается автоматически.
     *
     * @param ?StoreFile $path
     * @return $this
     */
    public function setModelFilePath(?StoreFile $path): self
    {
        $this->_modelFilePath = $path;

        return $this;
    }

    /**
     * Папка модели с кэшем картинок.
     *
     * @return ?StoreFile
     * @throws InvalidConfigException
     */
    public function getModelThumbPath(): ?StoreFile
    {
        $modelPath = $this->modelFilePath;

        return $modelPath !== null ? $modelPath->child('dummy.jpg')->thumb()->parent : null;
    }

    /**
     * Удаляет папку с кэшем картинок.
     *
     * @return $this
     * @throws StoreException
     */
    public function deleteModelThumbs(): self
    {
        // удаляем папку с кэшем картинок модели
        try {
            $thumbPath = $this->getModelThumbPath();
            if ($thumbPath !== null) {
                $thumbPath->delete();
            }
        } catch (InvalidConfigException $ex) {
            // у хранилища нет кэша картинок
        }

        return $this;
    }

    /**
     * Удаляет папку модели.
     * (нужен для обработчика событий модели).
     *
     * @return $this
     * @throws StoreException
     * @throws InvalidConfigException
     */
    public function deleteModelFilePath(): self
    {
        // путь папки модели
        $path = $this->getModelFilePath();

        if ($path !== null) {
            // удаляем папку с кэшем картинок модели
            $this->deleteModelThumbs();

            // удаляем папку с файлами модели
            $path->delete();
        }

        return $this;
    }

    /**
     * Получает список файлов аттрибута модели.
     *
     * @param string $attribute аттрибут
     * @return StoreFile[] файлы
     * @throws StoreException
     * @throws InvalidConfigException
     */
    protected function listAttributeFiles(string $attribute): array
    {
        // путь папки модели
        $modelPath = $this->getModelFilePath();

        // если папка не существует, то возвращаем пустой список
        if ($modelPath === null || ! $modelPath->exists) {
            return [];
        }

        // получаем список файлов
        $files = $modelPath->getList([
            'nameRegex' => '~^' . preg_quote($attribute, '~') . '\~\d+\~.+~ui',
        ]);

        // сортируем по полному пути (path/model/id/{attribute}-{pos}-{filename}.ext)
        usort($files, static function (StoreFile $a, StoreFile $b) {
            return strnatcasecmp($a->path, $b->path);
        });

        return $files;
    }

    /** @var StoreFile[][] текущие значения аттрибутов [attributeName => \dicr\file\StoreFile[]] */
    private $values = [];

    /**
     * Возвращает значение файлового аттрибута
     *
     * @param string $attribute
     * @param bool $refresh
     * @return StoreFile[]|StoreFile|null
     * @throws InvalidConfigException
     * @throws StoreException
     */
    public function getFileAttributeValue(string $attribute, bool $refresh = false)
    {
        $this->checkIsFileAttribute($attribute);

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
     * @param StoreFile[]|StoreFile|null $value
     * @return $this
     */
    public function setFileAttributeValue(string $attribute, $value): self
    {
        $this->checkIsFileAttribute($attribute);

        // конвертируем значение в массив (нельзя (array), потому что Model::toArray)
        if (empty($value)) {
            $value = [];
        } elseif (! is_array($value)) {
            $value = [$value];
        }

        // переиндексируем
        ksort($value);
        $value = array_values($value);

        // проверяем элементы массива
        foreach ($value as $file) {
            if (! $file instanceof StoreFile) {
                throw new InvalidArgumentException(
                    'Некорректный тип значения: ' . gettype($file) . ' аттрибута: ' . $attribute
                );
            }
        }

        // ограничиваем размер по limit
        $limit = $this->attributes[$attribute]['limit'];
        if ($limit > 0) {
            $value = array_slice($value, 0, $limit);
        }

        // обновляем значение в кеше
        $this->values[$attribute] = $value;

        return $this;
    }

    /**
     * Загружает значения аттрибута из данных в $_POST и $_FILES
     *
     * @param string $attribute имя загружаемого файлового аттрибута
     * @param ?string $formName имя формы модели
     * @return bool true если значение было загружено (присутствуют отправленные данные)
     * @throws InvalidConfigException
     */
    public function loadFileAttribute(string $attribute, ?string $formName = null): bool
    {
        $this->checkIsFileAttribute($attribute);

        // имя формы
        if (empty($formName)) {
            /** @scrutinizer ignore-call */
            $formName = $this->owner->formName();
        }

        /** @var array $post имена старых файлов с позициями */
        $post = (Yii::$app->request->post())[$formName][$attribute] ?? null;

        /** @var array загруженные новые файлы с позициями $files */
        $files = UploadFile::instances($attribute, $formName);
        if (! isset($post) && ! isset($files)) {
            return false;
        }

        // путь аттрибута модели (null если это не сохраненная ActiveRecord без id)
        $attributePath = $this->getModelFilePath();

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
     * Загружает файловые аттрибуты из $_POST и $FILES
     *
     * @param ?string $formName имя формы модели
     * @return bool true если данные некоторых атрибутов были загружены
     * @throws InvalidConfigException
     */
    public function loadFileAttributes(?string $formName = null): bool
    {
        $ret = false;

        foreach (array_keys($this->attributes) as $attribute) {
            if ($this->loadFileAttribute($attribute, $formName)) {
                $ret = true;
            }
        }

        return $ret;
    }

    /**
     * Выполняет проверку файла файлового атрибута.
     *
     * @param string $attribute
     * @param mixed $file
     * @return $this
     */
    protected function validateFile(string $attribute, $file): self
    {
        // параметры аттрибута
        $params = $this->attributes[$attribute] ?? [];

        // проверяем на пустое значение
        if (empty($file)) {
            $this->owner->addError($attribute, 'Пустое значение файла');
        } elseif (! $file instanceof StoreFile) {
            $this->owner->addError($attribute, 'Некорректный тип значения: ' . gettype($file));
        } elseif (! $file->exists) {
            $this->owner->addError($attribute, 'Загружаемый файл не существует: ' . $file->path);
        } elseif ($file->size <= 0) {
            $this->owner->addError($attribute, 'Пустой размер файла: ' . $file->name);
        } elseif (isset($params['maxsize']) && $file->size > $params['maxsize']) {
            $this->owner->addError($attribute, 'Размер не более ' . Yii::$app->formatter->asSize($params['maxsize']));
        } elseif (isset($params['type']) && ! $file->matchMimeType($params['type'])) {
            $this->owner->addError($attribute, 'Неверный тип файла: ' . $file->mimeType);
        } elseif ($file instanceof UploadFile) {
            if (! empty($file->error)) {
                $this->owner->addError($attribute, 'Ошибка загрузки файла');
            } elseif (empty($file->name)) {
                $this->owner->addError($attribute, 'Не задано имя загружаемого файла: ' . $file->path);
            }
        }

        return $this;
    }

    /**
     * Выполняет проверку файлового аттрибута и загруженных файлов.
     * Добавляет ошибки модели по addError
     *
     * @param string $attribute
     * @return ?bool результаты проверки или null, если атрибут не инициализирован
     */
    public function validateFileAttribute(string $attribute): ?bool
    {
        $this->checkIsFileAttribute($attribute);

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
     * Выполняет проверку файловых аттрибутов.
     * Добавляет ошибки модели по addError.
     *
     * @return bool true, если все проверки успешны
     */
    public function validateFileAttributes(): bool
    {
        $ret = true;

        foreach (array_keys($this->attributes) as $attribute) {
            if ($this->validateFileAttribute($attribute) === false) {
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
     * @return ?bool результат сохранения или null, если аттрибут не инициализирован
     * @throws StoreException
     * @throws InvalidConfigException
     */
    public function saveFileAttribute(string $attribute): ?bool
    {
        $this->checkIsFileAttribute($attribute);
        $modelPath = $this->getModelFilePath();

        // проверяем что модель сохранена перед тем как сохранять ее файлы
        if ($modelPath === null) {
            throw new LogicException('Модель еще не сохранена');
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
            // некорректный тип значения
            if (! $file instanceof StoreFile) {
                throw new RuntimeException('Неизвестный тип значения файлового аттрибута ' . $attribute);
            }

            // если это загружаемый файл и содержит ошибку загрузки, то пропускаем
            if ($file instanceof UploadFile && ! empty($file->error)) {
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
                throw new StoreException('Файл не существует: ' . $file->path);
            }

            // ищем позицию файла в старых файлах модели
            $oldPos = $this->searchModelFilePositionByName($file, $oldFiles);

            // если файл найден в списке старых, то файл нужно сохранить как есть
            if (isset($oldPos)) {
                // удаляем из списка старых на удаление
                unset($oldFiles[$oldPos]);

                // переименовываем во временное имя
                $file->name = StoreFile::createStorePrefix('.' . $attribute, mt_rand(), $file->name);
                continue;
            }

            // импортируем файл под временным именем
            $newFile = $modelPath->child(
                StoreFile::createStorePrefix('.' . $attribute, mt_rand(), $file->name)
            );

            $newFile->import($file);
            $file = $newFile;
        }

        // перед тем как использовать ссылку нужно очистить переменную
        unset($file);

        // удаляем оставшиеся старые файлы которых не было в списке для сохранения
        foreach ($oldFiles as $file) {
            $file->delete();
        }

        // сортируем файлы
        ksort($files);

        // переиндексируем и переименовываем файлы
        $value = [];
        foreach (array_values($files) as $pos => $file) {
            // добавляем индекс позиции
            $file->name = StoreFile::createStorePrefix($attribute, $pos, $file->name);

            // измеряем отметку времени для регенерации thumb и для корректной работы mod_pagespeed
            $file->touch();

            // сохраняем в позиции
            $value[$pos] = $file;
        }

        // очищаем все thumbnail
        $this->deleteModelThumbs();

        // обновляем значение аттрибута модели
        $this->values[$attribute] = $value;

        return true;
    }

    /**
     * Сохраняет файловые аттрибуты.
     * Выполняет импорт загруженных файлов и удаление старых
     *
     * @return bool результаты сохранения
     * @throws StoreException
     * @throws InvalidConfigException
     */
    public function saveFileAttributes(): bool
    {
        $ret = true;
        foreach (array_keys($this->attributes) as $attribute) {
            if ($this->saveFileAttribute($attribute) === false) {
                $ret = false;
            }
        }

        return $ret;
    }

    /**
     * Удаляет все файлы аттрибута.
     *
     * @param string $attribute
     * @return true
     * @throws StoreException
     * @throws InvalidConfigException
     */
    public function deleteFileAttribute(string $attribute): bool
    {
        $this->checkIsFileAttribute($attribute);

        // удаляем файлы аттрибута
        foreach ($this->listAttributeFiles($attribute) as $file) {
            $file->clearThumb();
            $file->delete();
        }

        // обновляем значение
        $this->values[$attribute] = [];

        return true;
    }

    /**
     * Удаляет все файлы всех аттрибутов
     *
     * @return bool
     * @throws StoreException
     * @throws InvalidConfigException
     */
    public function deleteFileAttributes(): bool
    {
        $ret = true;

        foreach (array_keys($this->attributes) as $attribute) {
            if (! $this->deleteFileAttribute($attribute)) {
                $ret = false;
            }
        }

        return $ret;
    }

    /**
     * Находит позицию файла в списке файлов по имени.
     *
     * @param StoreFile $file
     * @param StoreFile[] $files
     * @return ?int
     */
    protected function searchModelFilePositionByName(StoreFile $file, array $files): ?int
    {
        // если файл загружаемый, то не является старым
        if ($file instanceof UploadFile) {
            return null;
        }

        // если файл в другом хранилище, то не является файлом модели
        if ($file->store !== $this->store) {
            return null;
        }

        // если файл не в папке модели, то не является файлом модели
        $modelPath = $this->modelFilePath;
        if ($modelPath !== null && $file->parent->path !== $modelPath->path) {
            return null;
        }

        $name = $file->name;
        foreach ($files as $i => $f) {
            if ($f->name === $name) {
                return $i;
            }
        }

        return null;
    }
}
