<?php 
namespace dicr\filestore;

use yii\base\Behavior;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\base\Model;
use yii\db\ActiveRecord;
use yii\helpers\ArrayHelper;
use yii\web\ServerErrorHttpException;

/**
 * Файловые аттрибуты модели.
 * 
 * class TestModel extends Model {
 * 	  public function behaviors() {
 *		return [
 *			'fileAttribute' => [
 *				'class' => FileAttributeBehavior::class,
 * 				'attributes' => [
 *					'icon' => [
 *						'limit' => 1,
 *					], 
 *					'pics' => [
 *						'limit' => false,
 *					]
 *				]
 *			]
 *		];
 *	  }
 * }
 * 
 * attributes - массив обрабатываемых аттрибутов. Ключ - название аттрибута, значение:
 * 	- limit максимальное кол-во файлов,
 *  (опции импорта из File::import)
 * 
 * Данный behavior реализует get/set заданных аттрибутов, поэтому эти свойства этих аттрибутов можно не объявляь в модели.
 * Если задан limit = 1, то get/set возвращает элемент, иначе - массив.
 * 
 * Значением аттрибутов может быть элемент/массив элементов File и UploadFile.
 * 
 * class TestController extends Controller {
 * 	  public action save() {
 * 		$model = new TestModel();
 * 		if (\Yii::$app->request->isPost 
 * 			&& $model->load(\Yii::$app->request->post()) 
 * 			&& $model->loadFileAttributes() 
 * 			&& $model->validate()) {
 * 				if ($model instanceof ActiveRecord) $model->save();
 * 				else $model->saveFileAttributes();
 *		}
 *	 }
 * }
 * 
 * Для загрузки значений аттрибутов модели при обработе POST формы нужно после $model->load() 
 * дополнительно вызвать $model->loadFileAttributes().
 * 
 * Валидация данных аттрибутов выполняется по onBeforeValidate.
 * 
 * Сохранение загруженных файлов для ActiveRecord выполняется автоматически по onAfternsert/onAfterUpdate,
 * а для обычной модели необходимо вызвать $model->saveFileAttributes(), при котором выполнится импортирование загруженных файлов 
 * в директорию модели и удаление лишних. 
 * 
 * view.php:
 * 
 *  <?=Html::img($model->icon->url)?>
 *  
 *  <?php foreach ($model->pics as $pic) {?>
 *     <?=Html::img($pic->url)?>
 *  <?php } ?>
 *  
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2018
 *
 */
class FileAttributeBehavior extends Behavior {
	
	/** @var \yii\base\Model|null the owner of this behavior */
	public $owner;
	
	/** @var string|\dicr\filestore\FileStore хранилище файлов */
	public $store = 'fileStore';
	
	/** 
	 * @var array конфигурация аттрибутов [ attibuteName => limit ] 
	 * Ключ - название аттрибута, limit - ограничение кол-ва файлов. 
	 * Если $limit == 1, то аттрибут $model->{attribute} имеет тип File,
	 * если $limit !== 1, $model->{attribute} имеет тип массива File[]
	 */
	public $attributes;
	
	/** @var array [attibuteName => \dicr\filestore\File[]] */
	private $_values = [];
	
	/**
	 * {@inheritDoc}
	 * @see \yii\base\BaseObject::init()
	 */
	public function init() {
 			
		// проверяем attributes
		if (empty($this->attributes) || !is_array($this->attributes)) {
			throw new InvalidConfigException('attributes должен быть установлены');
		}
		
		// корректируем параметры аттрибутов
		foreach ($this->attributes as $name => $params) {
			// если параметры в виде одного значения, то считаем его лимитом
			if (empty($params)) $params = [];
			else if (is_numeric($params)) {
				$params = ['limit' => $params];
			} else {
				throw new InvalidConfigException('некорректное значение параметров аттрибута: '.$name);
			}
			$this->attributes[$name] = $params;
		}
		
		if (is_string($this->store)) {
			$this->store = \Yii::$app->get($this->store, true);
		} else if (is_array($this->store)) {
			$this->store = \Yii::createObject($this->store);
		} else {
			throw new InvalidConfigException('store');
		}

		// owner не инициализирован пока не вызван attach
		parent::init();
	}
	
	/**
	 * {@inheritDoc}
	 * @see \yii\base\Behavior::events()
	 */
	public function events() {
		return [
			Model::EVENT_BEFORE_VALIDATE => function() {
				$this->validateFileAttributes();
			},
			ActiveRecord::EVENT_AFTER_INSERT => function() {
				$this->saveFileAttributes();
			},
			ActiveRecord::EVENT_AFTER_UPDATE => function() {
				$this->saveFileAttributes();
			}
		];
	}
	
	/**
	 * Возвращает значение файлового аттрибута
	 * 
	 * @param string $attribute
	 * @param bool $refresh
	 * @return null|\dicr\filestore\File|\dicr\filestore\UploadFile|array
	 */
	public function getFileAttributeValue(string $attribute, bool $refresh=false) {
		if (!isset($this->_values[$attribute]) || $refresh) {
			$this->_values[$attribute] = $this->store->getModelPath($this->owner, $attribute)->getList([
				'dirs' => false
			]);
		}
		
		$value = $this->_values[$attribute];
		$limit = (int)($this->attributes[$attribute]['limit'] ?? 0);
		return $limit == 1 ? array_shift($value) : $value;
	}
	
	/**
	 * Возвращает значение файлового аттрибута
	 * 
	 * @param string $attribute
	 * @param null|array|\dicr\filestore\File|\dicrilestore\UploadFile $value
	 * @return static
	 */
	public function setFileAttributeValue(string $attribute, $value) {
		// конвертируем значение в массив (нельзя (array), потому что Model::toArray)
		if (empty($value)) $value = [];
		else if (!is_array($value)) $value = [$value];
		
		// проверяем элементы массива
		foreach ($value as $file) {
			if (!($file instanceof File) && !($file instanceof UploadFile)) {
				throw new \InvalidArgumentException('неокрректный тип элемента');
			}
		}
		
		// преобразуем индексы в числовые позиции
		$value = array_values($value);
		
		// ограничиваем размер по limit
		$limit = (int)($this->attributes[$attribute]['limit'] ?? 0);
		if ($limit > 0) $value = array_slice($value, 0, $limit);
		
		// сохраняем значение в кеше
		$this->_values[$attribute] = $value;
		return $this;
	}
	
	/**
	 * Загружает значения аттрибута из данных в $_POST и $_FILES
	 * 
	 * @param string $attribute имя загружаемого файлового аттрибута
	 * @param string|null $formName имя формы модели
	 * @return bool true если значение было загружено (присутствуют отправленные данные)
	 */
	public function loadFileAttribute(string $attribute, string $formName=null) {
		if (!isset($this->attributes[$attribute])) {
			throw new \InvalidArgumentException('аттрибут "'.$attribute.'" не задан как файловый');
		}
		
		// имя формы
		if (empty($formName)) {
			$formName = $this->owner->formName();
		}
		
		// проверяем что была отправка формы с данными аттрибута
		$post = ArrayHelper::getValue(\Yii::$app->request->post(), [$formName, $attribute], null);
		$files = UploadFile::instances($formName, $attribute);
		if (!isset($post) && !isset($files)) return false;
		
		// путь аттрибута модели 
		$attributePath = $this->store->getModelPath($this->owner, $attribute);
		
		// новое значение аттрибута
		$value = [];
		
		// для начала просматриваем данные $_POST с именами старых файлов для сохранения
		if (!empty($post)) foreach ((array)$post as $pos => $name) {
			// пропускаем пустые значения
			$name = basename($name);
			if ($name === '') continue;

			// устанавливаем в заданной позиции объект File старого файла
			$value[$pos] = $attributePath->child($name);
		}
		
		// перезаписываем позиции из $_POST загруженными файлами из $_FILE
		if (!empty($files)) foreach ((array)$files as $pos => $file) {
			$value[$pos] = $file;
		}
		
		// сортируем по позициям и выбираем значения с порядковыми ключами
		ksort($value);
		
		// устанавливаем значение модели с числовыми ключами порядка
		$this->owner->{$attribute} = array_values($value);
		
		return true;
	}
	
	/**
	 * Загружает файловые аттрибуты из $_POST и $FILES
	 * 
	 * @param string $formName имя формы модели
	 * @return boolean true если данные были загружены
	 */
	public function loadFileAttributes(string $formName=null) {
		$loaded = false;
		
		foreach (array_keys($this->attributes) as $attribute) {
			$res = $this->loadFileAttribute($attribute, $formName);
			$loaded = $loaded || $res;
		}
		
		return $res;
	}
	
	/**
	 * Проводит валидацию файлового аттрибута.
	 * Добавляет ошибки модели по addError
	 * 
	 * @param string $attribute
	 * @return bool результаты 
	 */
	public function validateFileAttribute(string $attribute) {
		if (!isset($this->attributes[$attribute])) {
			throw new \InvalidArgumentException('аттрибут "'.$attribute.'" не задан как файловый');
		}

		// нельзя (array), потому что Model::toArray
		$files = $this->owner->{$attribute};
		if (empty($files)) $files = [];		// null
		else if (!is_array($files)) $files = [$files]; 
		
		// пропускаем валидацию если значение не установлено
		foreach ($files as $file) {
			
			// проверяем на пустое значение
			if (empty($file)) {
				$this->owner->addError($attribute, 'пустое значение файла');
			} else if ($file instanceof File) {
				// проверяем существование старого файла
				
				/** @var \dicr\filestore\File $file */
				if (!$file->exists) {
					$this->owner->addError($attribute, 'старый файл не существует: '.$file->name);
					continue;
				}
			} else if ($file instanceof UploadFile) {
				// проверяем отсутствие ошибок загружаемого файла
				
				/** @var \dicr\filestore\UploadFile $file */
				if (!empty($file->error)) {
					$this->owner->addError($attribute, 'ошибка загрузки файла');
					continue;
				}
				
				if (!isset($file->name) || $file->name === '') {
					$this->owner->addError($attribute, 'не задано имя загруаемого файла: '.$file->path);
				}
				
				if (!@is_file($file->path)) {
					$this->owner->addError($attribute, 'загружаемый файл не существует: '.$file->path);
					continue;
				}
			} else {
				// неизвестный тип значения
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
	public function validateFileAttributes() {
		$ret = true;
		
		foreach (array_keys($this->attributes) as $attribute) {
			$res = $this->validateFileAttribute($attribute);
			if (!$res) $ret = false;
		}
		
		return $ret;
	}
	
	/**
	 * Сохраняет значение аттрибута.
	 * Загружает новые файлы (UploadFiles) и удаляется старые Files, согласно текущему значению аттрибута.
	 * 
	 * @param string $attribute
	 * @param array|null $options опции импорта File::impot
	 * @throws StoreException
	 * @return bool true если сохранение выполнено
	 * @see \dicr\filestore\File::import
	 */
	public function saveFileAttribute(string $attribute, array $options=[]) {
		if (!isset($this->attributes[$attribute])) {
			throw new \InvalidArgumentException('аттрибут "'.$attribute.'" не задан как файловый');
		}
		
		// готовим путь модели
		$modelPath = $this->store->getModelPath($this->owner, $attribute);
		$modelPath->checkDir();
		
		/** @var \dicr\filestore\File[] $oldFiles старые файлы */
		$oldFiles = $modelPath->getList([
			'dirs' => false
		]);
		
		/** @var string[] $oldNames имена старых файлов */
		$oldNames = array_map(function($file) {
			return $file->name;
		}, $oldFiles);
			
		/** @var string[] $saveNames имена старых файлов для сохранения */
		$saveNames = [];
			
		// импортируем новые и переименовываем старые во временные имена с точкой
		$files = $this->owner->{$attribute}; 	// нельзя (array), так как Mode преобразует в toArray();
		if (!is_array($files)) $files = [$files];
		
		foreach ($files as $pos => $file) {
			// пустые элементы удаляем
			if (empty($file)) {
				unset($files[$pos]);
			} else if ($file instanceof File) {	

				// удаляем старые файлы которые не существуют
				if (!in_array($file->name, $oldNames)) unset($files[$pos]);
				else {
					// запоминаем имя старого файла для сохранения
					$saveNames[] = $file->name;
					
					// переименовываем во временное имя
					$matches = null;
					$file->name = sprintf('.%d~%s', rand(100000, 999999),
						preg_match('~^\d+\~(.+)$~uism', $file->name, $matches) ? $matches[1] : $file->name
					);
					
					$files[$pos] = $file;
				}
			} else if ($file instanceof UploadFile) {
				// пропускаем файлы с ошибками
				if (!empty($file->error) || empty($file->name) || empty($file->path)) unset($files[$pos]);
				else {
					// создаем и импортируем файл под временным именем
					$newFile = $modelPath->child(sprintf('.%d~%s', rand(100000, 999999), $file->name));
					$newFile->import($file->path, $options);
					$files[$pos] = $newFile;
				}
			} else {
				throw new Exception('неизвестный тип значения фалового аттрибута '.$attribute);
			}
		}
		
		// удаляем старые файлы которых нет в списке для сохранения
		foreach ($oldFiles as $oldFile) {
			if (!in_array($oldFile->name, $saveNames) && $oldFile->exists) {
				$oldFile->delete();
			}
		}
		
		// переименовываем файлы в правильные имена
		$files = array_values($files);
		foreach (array_values($files) as $pos => &$file) {
			$matches = null;
			if (!preg_match('~^\.\d+\~(.+)$~uism', $file->name, $matches)) {
				throw new ServerErrorHttpException('внутренняя ошибка');
			}
			$file->name = sprintf('%d~%s', $pos, $matches[1]);
		}
		
		// обновляем аттрибут модели
		$this->owner->{$attribute} = $files;
		
		return true;
	}

	/**
	 * Сохраняет файловые аттрибуты.
	 * Выполняет импорт загруженных файлов и удаление старых
	 * 
	 * @param array|null $options опции импорта File::impot
	 * @return boolean
	 */
	public function saveFileAttributes(array $options=[]) {
		$ret = true;
		foreach (array_keys($this->attributes) as $attribute) {
			$res = $this->saveFileAttribute($attribute, $options);
			if (!$res) $ret = false;
		}
		
		return $ret;
	}
	
	/**
	 * {@inheritDoc}
	 * @see \yii\base\BaseObject::__isset()
	 */
	public function __isset($name) {
		if (isset($this->attributes[$name])) {
			return isset($this->owner->{$name});
		}
		
		return parent::__isset($name);
	}
	
	/**
	 * {@inheritDoc}
	 * @see \yii\base\BaseObject::__get()
	 */
	public function __get($name) {
		if (isset($this->attributes[$name])) {
			return $this->getFileAttributeValue($name);
		}
		
		return parent::__get($name);
	}
	
	/**
	 * {@inheritDoc}
	 * @see \yii\base\BaseObject::__set()
	 */
	public function __set($name, $value) {
		if (isset($this->attributes[$name])) {
			return $this->setFileAttributeValue($name, $value);
		}
		
		return parent::__set($name, $value);
	}
	
	/**
	 * {@inheritDoc}
	 * @see \yii\base\BaseObject::hasProperty()
	 */
	public function hasProperty($name, $checkVars = true) {
		if (isset($this->attributes[$name])) {
			return true;
		}
		return parent::hasProperty($name, $checkVars);
	}
	
	/**
	 * {@inheritDoc}
	 * @see \yii\base\BaseObject::canGetProperty()
	 */
	public function canGetProperty($name, $checkVars = true) {
		if (isset($this->attributes[$name])) {
			return true;
		}
		return parent::canGetProperty($name, $checkVars);
	}
	
	/**
	 * {@inheritDoc}
	 * @see \yii\base\BaseObject::canSetProperty()
	 */
	public function canSetProperty($name, $checkVars = true) {
		if (isset($this->attributes[$name])) {
			return true;
		}
		return parent::canSetProperty($name, $checkVars);
	}
}
