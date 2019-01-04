<?php
namespace dicr\file;

use yii\base\Behavior;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\base\Model;
use yii\db\ActiveRecord;
use yii\di\Instance;

/**
 * Файловые аттрибуты модели.
 * class TestModel extends Model {
 * public function behaviors() {
 * return [
 * 'fileAttribute' => [
 * 'class' => FileAttributeBehavior::class,
 * 'attributes' => [
 * 'icon' => 1,
 * 'pics' => false
 * ]
 * ]
 * ];
 * }
 * }
 * attributes - массив обрабатываемых аттрибутов.
 * Ключ - название аттрибута,
 * значение - limit максимальное кол-во файлов.
 * Данный behavior реализует get/set заданных аттрибутов, поэтому эти свойства этих аттрибутов можно не объявляь в
 * модели.
 * Если задан limit = 1, то get/set возвращает элемент, иначе - массив.
 * Значением аттрибутов может быть элемент/массив элементов File и UploadFile.
 * class TestController extends Controller {
 * public action save() {
 * $model = new TestModel();
 * if (\Yii::$app->request->isPost
 * && $model->load(\Yii::$app->request->post())
 * && $model->loadFileAttributes()
 * && $model->validate()) {
 * if ($model instanceof ActiveRecord) $model->save();
 * else $model->saveFileAttributes();
 * }
 * }
 * }
 * Для загрузки значений аттрибутов модели при обработе POST формы нужно после $model->load()
 * дополнительно вызвать $model->loadFileAttributes().
 * Валидация данных аттрибутов выполняется по onBeforeValidate.
 * Сохранение загруженных файлов для ActiveRecord выполняется автоматически по onAfternsert/onAfterUpdate,
 * а для обычной модели необходимо вызвать $model->saveFileAttributes(), при котором выполнится импортирование
 * загруженных файлов
 * в директорию модели и удаление лишних.
 * view.php:
 * <?=Html::img($model->icon->url)?>
 * <?php foreach ($model->pics as $pic) {?>
 * <?=Html::img($pic->url)?>
 * <?php } ?>
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2018
 */
class FileAttributeBehavior extends Behavior
{
    /** @var \yii\base\Model|null the owner of this behavior */
    public $owner;

    /** @var string|\dicr\file\FileStore хранилище файлов */
    public $store = 'fileStore';

    /**
     * @var array конфигурация аттрибутов [ attibuteName => limit ]
     *      Ключ - название аттрибута, limit - ограничение кол-ва файлов.
     *      Если $limit == 1, то аттрибут $model->{attribute} имеет тип File,
     *      если $limit !== 1, $model->{attribute} имеет тип массива File[]
     */
    public $attributes;

    /** @var array текущие значения аттрибутов [attibuteName => \app\lib\store\File[]]  */
    private $values = [];

    /**
     * {@inheritdoc}
     * @see \yii\base\BaseObject::init()
     */
    public function init()
    {
        if (empty($this->attributes) || ! is_array($this->attributes)) {
            throw new InvalidConfigException('attributes');
        }

        if (is_string($this->store)) {
            $this->store = \Yii::$app->get($this->store, true);
        }

        Instance::ensure($this->store, FileStore::class);

        // owner не инициализирован пока не вызван attach
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
     * @throws Exception
     */
    protected function checkOwnAttribute(string $attribute)
    {
        if (! array_key_exists($attribute, $this->attributes)) {
            throw new Exception('файловы аттрибут "' . $attribute . '" не существует');
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
        $this->checkOwnAttribute($attribute);

        if (! isset($this->values[$attribute]) || $refresh) {
            $modelPath = $this->store->getModelPath($this->owner, $attribute);
            $this->values[$attribute] = $modelPath->getList([
                'dirs' => false,
                'skipHidden' => true
            ]);
        }

        $value = $this->values[$attribute];
        $limit = (int) ($this->attributes[$attribute] ?? 0);

        return $limit == 1 ? array_shift($value) : $value;
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
        $this->checkOwnAttribute($attribute);

        // конвертируем значение в массив (нельзя (array), потому что Model::toArray)
        if (empty($files)) {
            $files = [];
        } elseif (! is_array($files)) {
            $files = [
                $files
            ];
        }

        // переиндексируем
        ksort($files);
        $files = array_values($files);

        // проверяем элементы массива
        foreach ($files as $file) {
            if (! ($file instanceof File)) {
                throw new \InvalidArgumentException('неокрректный тип элемента');
            }
        }

        // ограничиваем размер по limit
        $limit = (int) ($this->attributes[$attribute] ?? 0);
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
        $this->checkOwnAttribute($attribute);

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
        $attributePath = $this->store->getModelPath($this->owner, $attribute);

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
        $this->checkOwnAttribute($attribute);

        // получаем текущие значения
        $files = $this->values[$attribute] ?? null;

        // если новые значения не установлены, то валидацию проходить не нужно
        if (empty($files)) {
            return true;
        }

        foreach ($files as $file) {
            if (empty($file)) {
                $this->owner->addError($attribute, 'пустое значение файла');
            } elseif ($file instanceof UploadFile) {
                if (! empty($file->error)) {
                    $this->owner->addError($attribute, 'ошибка загрузки файла');
                } elseif (! isset($file->name) || $file->name == '') {
                    $this->owner->addError($attribute, 'не задано имя загруаемого файла: ' . $file->path);
                } elseif (empty($file->size)) {
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
        if (! isset($this->attributes[$attribute])) {
            throw new \InvalidArgumentException('аттрибут "' . $attribute . '" не задан как файловый');
        }

        // текущее значение аттрибута
        $files = $this->values[$attribute] ?? null;

        // если новые значения не установлены, то сохранять не нужно
        if (! isset($files)) {
            return false;
        } elseif (empty($files)) {
            $files = [];
        } elseif (! is_array($files)) {
            $files = [ // нельзя (array), так как Mode преобразует в toArray();
                $files
            ];
        }

        // готовим путь модели
        $modelPath = $this->store->getModelPath($this->owner, $attribute);

        // получаем старые файлы
        $oldFiles = $modelPath->getList([
            'dirs' => false,
            'skipHidden' => true
        ]);

        // импортируем новые и переименовываем старые во временные имена с точкой
        foreach ($files as $pos => $file) {
            // пустые элементы удаляем
            if (empty($file)) {
                unset($files[$pos]);
            } elseif ($file instanceof UploadFile) { // новый загруженный файл
                if (! empty($file->error) || empty($file->name) || empty($file->path)) {
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
     * @return \dicr\file\FileAttributeBehavior
     */
    public function deleteFileAttribute(string $attribute)
    {
        if (! isset($this->attributes[$attribute])) {
            throw new \InvalidArgumentException('аттрибут "' . $attribute . '" не задан как файловый');
        }

        // получаем папку аттрибута
        $attributePath = $this->store->getModelPath($this->owner, $attribute);

        // удаляем рекурсивно
        $attributePath->delete(true);

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
        return $this->store->getModelPath($this->owner)->delete(true);
    }

    /**
     * {@inheritdoc}
     * @see \yii\base\BaseObject::__isset()
     */
    public function __isset($name)
    {
        if (array_key_exists($name, $this->attributes)) {
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
        if (array_key_exists($name, $this->attributes)) {
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
        if (array_key_exists($name, $this->attributes)) {
            return $this->setFileAttributeValue($name, $value);
        }

        return parent::__set($name, $value);
    }

    /**
     * {@inheritdoc}
     * @see \yii\base\BaseObject::hasProperty()
     */
    public function hasProperty($name, $checkVars = true)
    {
        if (array_key_exists($name, $this->attributes)) {
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
        if (array_key_exists($name, $this->attributes)) {
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
        if (array_key_exists($name, $this->attributes)) {
            return true;
        }

        return parent::canSetProperty($name, $checkVars);
    }
}
