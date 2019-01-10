<?php
namespace dicr\file;

use yii\base\Behavior;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\base\Model;
use yii\db\ActiveRecord;
use yii\di\Instance;
use yii\base\InvalidArgumentException;

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
 *      - int $limit - ограничение количества файлов.
 *          - если $limit == 0,
 *              то значение аттрибута - массив файлов \dicr\file\File[] без ограничения на кол-во
 *          - если $limit - число,
 *              то значение аттрибута - массив \dicr\file\File[] с ограничением кол-ва файлов limit
 *          - если $limit == 1,
 *              то значение атрибута - один файл \dicr\file\File
 * </xmp>
 *
 * Если значение не массив, то оно принимается в качестве limit.
 *
 * Типы значения аттрибутов:
 *    \dicr\file\File - для аттрибутов с limit = 1;
 *    \dicr\file\File[] - для аттрибутов с limit != 1;
 *
 * Данный behavior реализует get/set методы для заданных аттрибутов,
 * поэтому эти методы не должны быть отпередены в модели.
 *
 * Для установки значений нужно либо присвоить новые значени типа UploadFile:
 *
 * <xmp>
 * $model->icon = new UploadFile('/tmp/new_file.jpg');
 *
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
 * <?=$model->icon instanceof UploadFile ? '' : Html::img($model->icon->url)?>
 *
 * <?php foreach ($model->pics as $pic) {?>
 *      <?php if (!($pic instanceof UploadFile)) {?>
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
    /** @var string|\dicr\file\FileStoreInterface хранилище файлов */
    public $store = 'fileStore';

    /**
     * @var array конфигурация аттрибутов [ attibuteName => limit ]
     *      Ключ - название аттрибута, limit - ограничение кол-ва файлов.
     *      Если $limit == 1, то аттрибут $model->{attribute} имеет тип File,
     *      если $limit !== 1, $model->{attribute} имеет тип массива File[]
     */
    public $attributes;

    /** @var array текущие значения аттрибутов [attibuteName => \dicr\file\File[]]  */
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
        }

        $this->store = Instance::ensure($this->store, FileStoreInterface::class);

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
        return [
            Model::EVENT_BEFORE_VALIDATE => 'validateFileAttributes',
            ActiveRecord::EVENT_AFTER_INSERT => 'saveFileAttributes',
            ActiveRecord::EVENT_AFTER_UPDATE => 'saveFileAttributes',
            ActiveRecord::EVENT_AFTER_DELETE => 'deleteModelFolder'
        ];
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


    if (empty($model)) {
            throw new \InvalidArgumentException('model');
        }

        $relpath = [
            basename($model->formName())
        ];

        if ($model instanceof ActiveRecord) {
            $keyName = basename(implode('~', $model->getPrimaryKey(true)));
            if ($keyName !== '') {
                $relpath[] = $keyName;
            }
        }

        $attribute = basename(trim($attribute, '/'));
        if ($attribute !== '') {
            $relpath[] = $attribute;
        }

        $file = basename(trim($file, '/'));
        if ($file !== '') {
            $relpath[] = $file;
        }

        $relpath = implode('/', $relpath);

        return $this->file($relpath);


    /**
     * Возвращает директори аттрибута
     *
     * @param string $attribute
     * @throws InvalidConfigException
     * @return \dicr\file\File
     */
    protected function getAttributePath(string $attribute = '')
    {
        if (! ($this->owner instanceof Model)) {
            throw new InvalidConfigException('owner');
        }

        return $this->store->getModelPath($this->owner, $attribute);
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
     * Возвращает значение файлового аттрибута
     *
     * @param string $attribute
     * @param bool $refresh
     * @return null|\dicr\file\File|\dicr\file\File[]
     */
    public function getFileAttributeValue(string $attribute, bool $refresh = false)
    {
        $this->checkFileAttribute($attribute);
        $this->checkOwner();

        if (! isset($this->values[$attribute]) || $refresh) {
            $this->values[$attribute] = $this->getAttributePath($attribute)->getList([
                'dirs' => false,
                'skipHidden' => true
            ]);
        }

        $value = $this->values[$attribute];

        return $this->attributes[$attribute]['limit'] == 1 ? array_shift($value) : $value;
    }

    /**
     * Возвращает значение файлового аттрибута
     *
     * @param string $attribute
     * @param null|\dicr\file\File|\dicr\file\File[] $files
     * @return static
     */
    public function setFileAttributeValue(string $attribute, $files)
    {
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
            if (! ($file instanceof File)) {
                throw new InvalidArgumentException('неокрректный тип элемента');
            }
        }

        $this->checkFileAttribute($attribute);

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
        $this->checkFileAttribute($attribute);
        $this->checkOwner();

        if (! ($this->owner instanceof Model)) {
            throw new InvalidConfigException('owner');
        }

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
     * @return boolean true если данные были загружены
     */
    public function loadFileAttributes(string $formName = null)
    {
        $ret = true;
        foreach (array_keys($this->attributes) as $attribute) {
            $res = $this->loadFileAttribute($attribute, $formName);
            if (! $res) {
                $ret = false;
            }
        }
        return $ret;
    }

    /**
     * Проводит валидацию файлового аттрибута.
     * Добавляет ошибки модели по addError
     *
     * @param string $attribute
     * @return bool результаты
     */
    public function validateFileAttribute(string $attribute)
    {
        $this->checkFileAttribute($attribute);
        $this->checkOwner();

        // получаем текущие значения
        $files = $this->values[$attribute] ?? null;

        // если новые значения не установлены, то валидацию проходить не нужно
        if (empty($files)) {
            return true;
        }

        if (! ($this->owner instanceof Model)) {
            throw new InvalidConfigException('owner');
        }

        foreach ($files as $file) {
            if (empty($file)) {
                $this->owner->addError($attribute, 'пустое значение файла');
            } elseif ($file instanceof UploadFile) {
                if (! empty($file->error)) {
                    $this->owner->addError($attribute, 'ошибка загрузки файла');
                } elseif (! isset($file->name) || $file->name == '') {
                    $this->owner->addError($attribute, 'не задано имя загруаемого файла: ' . $file->path);
                } elseif ($file->size !== null && $file->size <= 0) {
                    $this->owner->addError($attribute, 'пустой размер файла: ' . $file->name);
                } elseif (! @is_file($file->fullPath)) {
                    $this->owner->addError($attribute, 'загружаемый файл не существует: ' . $file->fullPath);
                }
            } elseif ($file instanceof File) {
                if (! $file->exists) {
                    $this->owner->addError($attribute, 'старый файл не существует: ' . $file->name);
                }
            } else {
                $this->owner->addError($attribute, 'неизвестный тип значения');
            }
        }

        return empty($this->owner->getErrors($attribute));
    }

    /**
     * Проводит валидацию файловых аттрибутов.
     * Добавляет ошибки модели по addError.
     *
     * @return boolean результат валидации
     */
    public function validateFileAttributes()
    {
        $ret = true;
        foreach (array_keys($this->attributes) as $attribute) {
            $res = $this->validateFileAttribute($attribute);
            if (! $res) {
                $ret = false;
            }
        }

        return $ret;
    }

    /**
     * Удаляет из имени файла технический префикс
     *
     * @param string $name имя файла
     * @return string оригинальное имя без префикса
     */
    protected function removeNamePrefix(string $name)
    {
        $matches = null;
        if (preg_match('~^(\.tmp)?\d+\~(.+)$~uism', $name, $matches)) {
            $name = $matches[2];
        }

        return $name;
    }

    /**
     * Добавляет имени временный префикс.
     * Предварительно удаляется существующий префиск
     *
     * @param string $name
     * @return string
     */
    protected function createTempPrefix(string $name)
    {
        // удаляем текущий префикс
        $name = $this->removeNamePrefix($name);

        // добавляем временный префиск
        return sprintf('.tmp%d~%s', rand(100000, 999999), $name);
    }

    /**
     * Создает временное имя файла, добавляя служебный префикс
     *
     * @param string $name
     * @return string
     */
    protected function createPosPrefix(string $name, int $pos)
    {
        // удаляем текущий префикс
        $name = $this->removeNamePrefix($name);

        // добавляем порядковый префиск
        return sprintf('%d~%s', $pos, $name);
    }

    /**
     * Сохраняет значение аттрибута.
     * Загружает новые файлы (UploadFiles) и удаляется старые Files, согласно текущему значению аттрибута.
     *
     * @param string $attribute
     * @throws \dicr\file\StoreException
     * @return bool true если сохранение выполнено
     */
    public function saveFileAttribute(string $attribute)
    {
        $this->checkFileAttribute($attribute);
        $this->checkOwner();

        // текущее значение аттрибута
        $files = $this->values[$attribute] ?? null;

        // если новые значения не установлены, то сохранять не нужно
        if (! isset($files)) {
            return false;
        } elseif (empty($files)) {
            $files = [];
        } elseif (! is_array($files)) {
            $files = [ // нельзя (array), так как Mode преобразует в toArray();
            $files];
        }

        // готовим путь модели
        $modelPath = $this->getAttributePath($attribute);

        // получаем старые файлы
        $oldFiles = $modelPath->getList(['dirs' => false,'skipHidden' => true]);

        // импортируем новые и переименовываем старые во временные имена с точкой
        foreach ($files as $pos => $file) {
            // пустые элементы удаляем
            if (empty($file)) {
                unset($files[$pos]);
            } elseif ($file instanceof UploadFile) { // новый загруженный файл
                if (! empty($file->error) || empty($file->path)) {
                    unset($files[$pos]); // пропускаем файлы с ошибками
                } else {
                    // создаем и импортируем файл под временным именем
                    $newFile = $modelPath->child($this->createTempPrefix($file->name));
                    $newFile->import($file->fullPath);
                    $files[$pos] = $newFile;
                }
            } elseif ($file instanceof File) { // старый файл
                // ищем в списке старых файлов
                $oldFound = false;
                foreach ($oldFiles as $oldPos => $oldFile) {
                    if ($oldFile->name == $file->name) {
                        $oldFound = true;
                        unset($oldFiles[$oldPos]); // найденый старый файл удаляем из списка
                        break;
                    }
                }

                if (! $oldFound) {
                    unset($files[$pos]); // если файл уже удален, то забываем его
                } else {
                    $file->setName($this->createTempPrefix($file->name), true); // переименовываем во временное имя
                    $files[$pos] = $file;
                }
            } else {
                throw new Exception('неизвестный тип значения фалового аттрибута ' . $attribute);
            }
        }

        if (! empty($files)) {
            // удаляем оставшиеся старые файлы которых не было в списке для сохранения
            foreach ($oldFiles as $oldFile) {
                $oldFile->delete();
            }

            // переиндексируем с новыми порядковыми ключами
            $files = array_values($files);

            // переименовываем файлы в правильные имена
            foreach ($files as $pos => $file) {
                $file->setName($this->createPosPrefix($file->name, $pos), true);
                $files[$pos] = $file;
            }
        } else {
            // удаляем директорию аттрибута
            $modelPath->delete(true);
        }

        // обновляем значение аттрибута модели
        $this->values[$attribute] = $files;

        return true;
    }

    /**
     * Сохраняет файловые аттрибуты.
     * Выполняет импорт загруженных файлов и удаление старых
     *
     * @return boolean
     */
    public function saveFileAttributes()
    {
        $ret = true;
        foreach (array_keys($this->attributes) as $attribute) {
            $res = $this->saveFileAttribute($attribute);
            if (! $res) {
                $ret = false;
            }
        }

        return $ret;
    }

    /**
     * Удаляет все файлы аттрибута
     *
     * @param string $attribute
     * @throws \InvalidArgumentException
     * @return bool
     */
    public function deleteFileAttribute(string $attribute)
    {
        $this->checkFileAttribute($attribute);
        $this->checkOwner();

        // удаляем рекурсивно
        $this->getAttributePath($attribute)->delete(true);

        // запоминаем значение
        $this->values[$attribute] = [];

        return true;
    }

    /**
     * Удаляет все файлы всех аттритутов
     *
     * @return boolean
     */
    public function deleteFileAttributes()
    {
        $ret = true;
        foreach (array_keys($this->attributes) as $attribute) {
            $res = $this->deleteFileAttribute($attribute);
            if (! $res) {
                $ret = false;
            }
        }

        return $ret;
    }

    /**
     * Удаляет папку модели
     *
     * @return \dicr\file\File
     */
    public function deleteModelFolder()
    {
        return $this->getAttributePath('')->delete(true);
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
            return $this->getFileAttributeValue($name);
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
            $this->setFileAttributeValue($name, $value);
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
}
