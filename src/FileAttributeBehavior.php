<?php
namespace dicr\file;

use yii\base\Behavior;
use yii\base\Exception;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\base\Model;
use yii\db\ActiveRecord;

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
 *              то значение аттрибута - массив файлов \dicr\file\AsbtractFile[] без ограничения на кол-во
 *          - если $limit - число,
 *              то значение аттрибута - массив \dicr\file\AsbtractFile[] с ограничением кол-ва файлов limit
 *          - если $limit == 1,
 *              то значение атрибута - один файл \dicr\file\AsbtractFile
*       - int|null $maxsize - максимальный размер
*       - string|null $type mime-ип загруженных файлов
 * </xmp>
 *
 * Если значение не массив, то оно принимается в качестве limit.
 *
 * Типы значения аттрибутов:
 *    \dicr\file\AsbtractFile - для аттрибутов с limit = 1;
 *    \dicr\file\AsbtractFile[] - для аттрибутов с limit != 1;
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
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2018
 */
// @formatter:on
class FileAttributeBehavior extends Behavior
{
    /** @var \dicr\file\AbstractFileStore|string|array хранилище файлов */
    public $store = 'fileStore';

    /**
     * @var array конфигурация аттрибутов [ attibuteName => limit ]
     *      Ключ - название аттрибута, limit - ограничение кол-ва файлов.
     *      Если $limit == 1, то аттрибут $model->{attribute} имеет тип File,
     *      если $limit !== 1, $model->{attribute} имеет тип массива File[]
     */
    public $attributes;

    /** @var array текущие значения аттрибутов [attibuteName => \dicr\file\StoreFile[]]  */
    private $values = [];

    /**
     * {@inheritdoc}
     * @see \yii\base\BaseObject::init()
     */
    public function init()
    {
        // owner не инициализирован пока не вызван attach

        // получаем store
        if (is_string($this->store)) {
            $this->store = \Yii::$app->get($this->store, true);
        } elseif (is_array($this->store)) {
            $this->store = \Yii::createObject($this->store);
        }

        if (!($this->store instanceof AbstractFileStore)) {
            throw new InvalidConfigException('store');
        }

        // проверяем наличие аттрибутов
        if (empty($this->attributes) || ! is_array($this->attributes)) {
            throw new InvalidConfigException('attributes');
        }

        // конвертируем упрощеный формат аттрибутов в полный
        foreach ($this->attributes as $attribute => $params) {
            if (is_array($params)) {
                $params['limit'] = (int) ($params['limit'] ?? 0);
            } else {
                $params = ['limit' => (int) $params];
            }
            $this->attributes[$attribute] = $params;
        }

        parent::init();
    }

    /**
     * {@inheritdoc}
     * @see \yii\base\Behavior::events()
     */
    public function events()
    {
        return [Model::EVENT_BEFORE_VALIDATE => 'validateFileAttributes',
            ActiveRecord::EVENT_AFTER_INSERT => 'saveFileAttributes',
            ActiveRecord::EVENT_AFTER_UPDATE => 'saveFileAttributes',
            ActiveRecord::EVENT_AFTER_DELETE => 'deleteModelFolder'];
    }

    /**
     * Возвращает значение файлового аттрибута
     *
     * @param string $attribute
     * @param bool $refresh
     * @return null|\dicr\file\AbstractFile|\dicr\file\AbstractFile[]
     */
    public function getFileAttribute(string $attribute, bool $refresh = false)
    {
        if (! isset($this->values[$attribute]) || $refresh) {
            $attributePath = $this->getAttributePath($attribute);


            $this->values[$attribute] = $attributePath->exists ? $this->getAttributePath($attribute)->getList([
                'dir' => false,
                'hidden' => false
            ]) : [];

            // сортируем фо номеру позиции
            usort($this->values[$attribute], function($a, $b) {
                return strnatcasecmp($a->path, $b->path);
            });
        }

        $value = $this->values[$attribute];

        // если аттрибут имеет скалярный тип, то возвращаем первое значение
        return $this->attributes[$attribute]['limit'] == 1 ? array_shift($value) : $value;
    }

    /**
     * Возвращает значение файлового аттрибута
     *
     * @param string $attribute
     * @param null|\dicr\file\AbstractFile|\dicr\file\AbstractFile[] $files
     * @return static
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
            if (! ($file instanceof AbstractFile)) {
                throw new InvalidArgumentException('неокрректный тип элемента');
            }
        }

        // ограничиваем размер по limit
        $limit = $this->attributes[$attribute]['limit'];
        if ($limit > 0) {
            $files = array_slice($files, 0, $limit);
        }

        // сохраняем значение в кеше
        $this->values[$attribute] = $files;

        return $this;
    }

    /**
     * Загружает значения аттрибута из данных в $_POST и $_FILES
     *
     * @param string $attribute имя загружаемого файлового аттрибута
     * @param string|null $formName имя формы модели
     * @return bool true если значение было загружено (присутствуют отправленные данные)
     */
    public function loadFileAttribute(string $attribute, string $formName = null)
    {
        $this->checkOwner();
        $this->checkFileAttribute($attribute);

        // имя формы
        if (empty($formName)) {
            $formName = $this->owner->formName();
        }

        // проверяем что была отправка формы с данными аттрибута
        $post = (\Yii::$app->request->post())[$formName][$attribute] ?? null;
        $files = UploadFile::instances($formName, $attribute);

        if (! isset($post) && ! isset($files)) {
            return false;
        }

        // путь аттрибута модели
        $attributePath = $this->getAttributePath($attribute);

        // новое значение аттрибута
        $value = [];

        // для начала просматриваем данные $_POST с именами старых файлов для сохранения
        if (! empty($post)) {
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
     * @param string $formName имя формы модели
     * @return boolean true если данные некоторых атрибутов были загружены
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
     * Сравнивает Mime-тип с поддержкой шаблонов
     *
     * @param string $mime сравниваемый Mime-тип
     * @param string $required шаблонный
     * @return bool результат
     */
    protected static function matchMimeType(string $mime, string $required)
    {
        $regex = '~^' . str_replace(['/', '*'], ['\\/', '.+'], $required) . '$~uism';
        return (bool)preg_match($mime, $regex);
    }

    /**
     * Проводит валидацию файлового аттрибута и загруженных файлов.
     * Добавляет ошибки модели по addError
     *
     * @param string $attribute
     * @return bool|null результаты проверки или null, если атрибут не инициализирован
     */
    public function validateFileAttribute(string $attribute)
    {
        $this->checkOwner();
        $this->checkFileAttribute($attribute);

        // получаем текущее значение
        $files = $this->values[$attribute] ?? null;

        // если атрибут не был инициализирован, то пропускаем проверку
        if (!isset($files)) {
            return null;
        }

        // получаем парамеры атрибута
        $params = $this->attributes[$attribute];

        // минимальное количество
        if (!empty($params['min']) && count($files) < $params['min']) {
            $this->owner->addError($attribute, 'Необходимо не менее '. intval($params['min']) . ' количество файлов');
        }

        // максимальное количество
        if (!empty($params['limit']) && count($files) > $params['limit']) {
            $this->owner->addError($attribute, 'Максимальное кол-во файлов: ' . intval($params['limit']));
        }

        // проверяем каждый файл
        foreach ($files as $i => $file) {
            if (empty($file)) {
                $this->owner->addError($attribute, 'пустое значение файла');
            } elseif ($file instanceof UploadFile) {
                if (! empty($file->error)) {
                    $this->owner->addError($attribute, 'ошибка загрузки файла');
                    unset($files[$i]);
                } elseif (! isset($file->name) || $file->name == '') {
                    $this->owner->addError($attribute, 'не задано имя загруаемого файла: ' . $file->path);
                    unset($files[$i]);
                } elseif (! $file->exists) {
                    $this->owner->addError($attribute, 'загружаемый файл не существует: ' . $file->path);
                    unset($files[$i]);
                } elseif ((int) $file->size <= 0) {
                    $this->owner->addError($attribute, 'пустой размер файла: ' . $file->name);
                    unset($files[$i]);
                } elseif (isset($params['maxsize']) && $file->size > $params['maxsize']) {
                    $this->owner->addError($attribute, 'Размер не более ' . \Yii::$app->formatter->asSize($params['maxsize']));
                    unset($files[$i]);
                } elseif (isset($params['type']) && !self::matchMimeType($file->mimeType, $params['type'])) {
                    $this->owner->addError($attribute, 'Неверный тип файла: ' . $file->mimeType);
                    unset($files[$i]);
                }
            } elseif (!($file instanceof AbstractFile)) {
                $this->owner->addError($attribute, 'неизвестный тип значения');
                unset($files[$i]);
            }
        }

        return empty($this->owner->getErrors($attribute));
    }

    /**
     * Проводит валидацию файловых аттрибутов.
     * Добавляет ошибки модели по addError.
     *
     * @return boolean true, если все проверки успешны
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
     * Сохраняет значение аттрибута.
     * Загружает новые файлы (UploadFiles) и удаляется старые Files, согласно текущему значению аттрибута.
     *
     * @param string $attribute
     * @throws \dicr\file\StoreException
     * @return bool|null результат сохранения или null, если аттрибут не инициализирован
     */
    public function saveFileAttribute(string $attribute)
    {
        $this->checkOwner();
        $this->checkFileAttribute($attribute);

        // текущее значение аттрибута
        $files = $this->values[$attribute] ?? null;

        // если новые значения не установлены, то сохранять не нужно
        if (! isset($files)) {
            return null;
        }

        // готовим путь модели
        $modelPath = $this->getAttributePath($attribute);

        // получаем старые файлы
        $oldFiles = $modelPath->exists ? $modelPath->getList([
            'dir' => false,
            'hidden' => false
        ]) : [];

        // импортируем новые и переименовываем старые во временные имена с точкой
        foreach ($files as $pos => $file) {
            // пустые элементы удаляем
            if (empty($file)) {
                unset($files[$pos]);
            } elseif ($file instanceof UploadFile) { // новый загруженный файл
                if (! empty($file->error) || empty($file->path)) {
                    unset($files[$pos]); // пропускаем файлы с ошибками
                } else {
                    $files[$pos] = static::importNewFile($modelPath, $file); // создаем и импортируем файл под временным именем
                }
            } elseif ($file instanceof StoreFile) { // старый файл
                // если файл принадлежит другому store, то импортируем также как и UploadFile
                if ($file->store !== $modelPath->store) {
                    $files[$pos] = static::importNewFile($modelPath, $file); // создаем и импортируем файл под временным именем
                } elseif (! static::matchOldFile($oldFiles, $file)) { // ищем в списке старых файлов
                    unset($files[$pos]); // если файл уже удален, то забываем его
                } else {
                    static::renameWithTemp($file); // переименовываем во временное имя
                }
            } else {
                throw new Exception('неизвестный тип значения фалового аттрибута ' . $attribute);
            }
        }

        // удаляем оставшиеся старые файлы которых не было в списке для сохранения
        static::deleteOldFiles($oldFiles);

        if (! empty($files)) {
            // переименовываем файлы в правильные имена
            $files = static::renameWithPos($files);
        } else {
            // удаляем директорию аттрибута
            $modelPath->delete();
        }

        // обновляем значение аттрибута модели
        $this->values[$attribute] = $files;

        return true;
    }

    /**
     * Сохраняет файловые аттрибуты.
     * Выполняет импорт загруженных файлов и удаление старых
     *
     * @return bool резульаты сохранения
     */
    public function saveFileAttributes()
    {
        $ret = true;

        foreach (array_keys($this->attributes) as $attribute) {
            $res = $this->saveFileAttribute($attribute);

            // пропускаем если атрибут не инициализирован
            if ($res !== null) {
                $ret = $ret && $res;
            }
        }

        return $ret;
    }

    /**
     * Удаляет все файлы аттрибута
     *
     * @param string $attribute
     * @throws \InvalidArgumentException
     * @return true
     */
    public function deleteFileAttribute(string $attribute)
    {
        $this->checkFileAttribute($attribute);
        $this->checkOwner();

        // удаляем рекурсивно
        $this->getAttributePath($attribute)->delete();

        // запоминаем новое значение
        $this->values[$attribute] = [];

        return true;
    }

    /**
     * Удаляет все файлы всех аттритутов
     *
     * @return true
     */
    public function deleteFileAttributes()
    {
        foreach (array_keys($this->attributes) as $attribute) {
            $this->deleteFileAttribute($attribute);
        }

        return true;
    }

    /**
     * Удаляет папку модели
     *
     * @return \dicr\file\StoreFile путь удаленной директории модели
     */
    public function deleteModelFolder()
    {
        return $this->getAttributePath('')->delete();
    }

    /**
     * {@inheritdoc}
     * @see \yii\base\BaseObject::__isset()
     */
    public function __isset($name)
    {
        if ($this->isFileAttribute($name)) {
            return isset($this->attributes[$name]);
        }

        return parent::__isset($name);
    }

    /**
     * {@inheritdoc}
     * @see \yii\base\BaseObject::__get()
     */
    public function __get($name)
    {
        if ($this->isFileAttribute($name)) {
            return $this->getFileAttribute($name);
        }

        return parent::__get($name);
    }

    /**
     * {@inheritdoc}
     * @see \yii\base\BaseObject::__set()
     */
    public function __set($name, $value)
    {
        if ($this->isFileAttribute($name)) {
            $this->setFileAttribute($name, $value);
        } else {
            parent::__set($name, $value);
        }
    }

    /**
     * {@inheritdoc}
     * @see \yii\base\BaseObject::hasProperty()
     */
    public function hasProperty($name, $checkVars = true)
    {
        if ($this->isFileAttribute($name)) {
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
        if ($this->isFileAttribute($name)) {
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
        if ($this->isFileAttribute($name)) {
            return true;
        }

        return parent::canSetProperty($name, $checkVars);
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
     * @return boolean
     */
    protected function isFileAttribute(string $attribute)
    {
        return array_key_exists($attribute, $this->attributes);
    }

    /**
     * Проверяет существование файлового атрибута
     *
     * @param string $attribute
     * @throws Exception
     */
    protected function checkFileAttribute(string $attribute)
    {
        if (! $this->isFileAttribute($attribute)) {
            throw new Exception('файловы аттрибут "' . $attribute . '" не существует');
        }
    }

    /**
     * Возвращает директори аттрибута
     *
     * @param string $attribute
     * @throws InvalidConfigException
     * @return \dicr\file\StoreFile
     */
    protected function getAttributePath(string $attribute = '')
    {
        // проверяем алвдельца поведения
        $this->checkOwner();

        // проверяем что файловый аттрибут существует
        if ($attribute !== '') {
            $this->checkFileAttribute($attribute);
        }

        // добавляем в путь имя формы
        $relpath = [$this->owner->formName()];

        // для элементов базы данных добавляем id
        if ($this->owner instanceof ActiveRecord) {
            $keyName = basename(implode('~', $this->owner->getPrimaryKey(true)));
            if ($keyName !== '') {
                $relpath[] = $keyName;
            }
        }

        // добавляем в путь имя аттрибута
        if ($attribute !== '') {
            $relpath[] = $attribute;
        }

        return $this->store->file($relpath);
    }

    /**
     * Импортирует новый файл.
     * Либо UploadFile, либо StoreFile, у которого другой store.
     *
     * @param StoreFile $attributePath
     * @param AbstractFile $file файл для импорта
     * @return \dicr\file\StoreFile новый импортированный файл
     */
    protected static function importNewFile(StoreFile $attributePath, AbstractFile $file)
    {
        $newFile = $attributePath->child(StoreFile::setTempPrefix($file->name));
        $newFile->contents = $file->contents;
        return $newFile;
    }

    /**
     * Переименовывает файл во временное имя
     *
     * @param StoreFile $file
     * @return StoreFile
     */
    protected static function renameWithTemp(StoreFile $file)
    {
        $file->name = StoreFile::setTempPrefix($file->name);
        return $file;
    }

    /**
     * Переименовывает файлы, добавляя префик позиции
     *
     * @param StoreFile[] $files
     * @return StoreFile[]
     */
    protected static function renameWithPos(array $files)
    {
        // переиндексируем
        $files = array_values($files);

        foreach ($files as $pos => $file) {
            $file->name = StoreFile::setPosPrefix($file->name, $pos);
            $files[$pos] = $file;
        }

        return $files;
    }

    /**
     * Находит файл среди старых и удаляет найденный из списка
     *
     * @param StoreFile[] $oldFiles старый файлы
     * @param StoreFile $file файл для поиска
     * @return boolean true если старый файл найден и удален из списка
     */
    protected static function matchOldFile(array &$oldFiles, StoreFile $file)
    {
        $found = false;
        foreach ($oldFiles as $i => $oldFile) {
            if ($oldFile->name == $file->name) {
                $found = true;
                unset($oldFiles[$i]); // найденый старый файл удаляем из списка
                break;
            }
        }
        return $found;
    }

    /**
     * Удаляет старые файлы
     *
     * @param StoreFile[] $files старые файлы для удаления
     */
    protected static function deleteOldFiles(array &$files)
    {
        foreach ($files as $i => $file) {
            $file->delete();
            unset($files[$i]);
        }

        unset($files);
    }
}
