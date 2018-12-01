<?php 
namespace dicr\filestore;

use yii\base\BaseObject;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\helpers\ArrayHelper;

/**
 * Загруженный файл.
 * Файл необхоимо импортировать в директорию модели при ее сохранении.
 * 
 * В зависимости от присутствия названия формы и множественности аттрибута,
 * php формирует разную структуру $_FILES.
 * 
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2018
 */
class UploadFile extends BaseObject {
	/** @var string наименование файла */
	public $name;
	
	/** @var int размер файла */
	public $size;
	
	/** @var string mime-type */
	public $type;
	
	/** @var int ошибка загрузки */
	public $error;
	
	/** @var string путь временного файла */
	public $path;
	
	/** @var array [$formName => [$attribute => \dicr\filestore\UploadFile[] ]] кэш распарсенных объектов */
	private static $_instances;

	/**
	 * {@inheritDoc}
	 * @see \yii\base\BaseObject::init()
	 */
	public function init() {
		$this->size = (int)$this->size;
		$this->error = (int)$this->error;
		
		// в случае ошибок name и path может быть пустым
		if (empty($this->error)) {
			// путь должен быть задан
			if (!isset($this->path)) throw new InvalidConfigException('path');

			// если имя не задано, берем basename из пути
			if (!isset($this->name)) $this->name = $this->path;
			$this->name = basename($this->name);
			if ($this->name === '') throw new InvalidConfigException('name');
			
		}
	}
	
	/**
	 * Создает объекты для файлов одного аттрибута
	 *
	 * @param array $names имена
	 * @param array $types типы
	 * @param array $sizes размеры
	 * @param array $errors ошибки
	 * @param array $paths пути
	 * @return \dicr\filestore\UploadFile[]
	 */
	protected static function attributeInstances(array $names, array $types, array $sizes, array $errors, array $paths) {
		$instances = [];
		
		foreach ($names as $pos => $name) {
			
			// пропускаем файлы, которые не выбраны в форме
			if (empty($name)) continue;
			
			$instances[$pos] = new static([
				'name' => $name,
				'type' => $types[$pos] ?? null,
				'size' => $sizes[$pos] ?? null,
				'error' => $errors[$pos] ?? null,
				'path' => $paths[$pos] ?? null
			]);
		}
		
		return $instances;
	}
	
	/**
	 * Парсит файлы аттрибута, отправленные без имени формы
	 * 
	 * <input type="file" name="attribute"/>
	 * 
	 * $_FILES = [
	 *	'$attribute' => [
	 *		'name' => 'test.php',
	 *		'type' => 'application/x-php',
	 *		'tmp_name' => '/tmp/phpqmYDrQ',
	 *		'error' => 0,
	 *		'size' => 41
	 *	]
	 * ];
	 * 
	 * 
	 * <input type="file" name="attibute[]"/>
	 *
	 * $_FILES = [
	 *	'$attribute' => [
	 *		'name' => [
	 *			0 => '2018-04-23-195938.jpg',
	 *			1 => 'test.php',
	 *		],
	 *		'type' => [
	 *			0 => 'image/jpeg',
	 *			1 => 'application/x-php',
	 *		],
	 *		'tmp_name' => [
	 *			0 => '/tmp/php9PADWU',
	 *			1 => '/tmp/phpEarnq1',
	 *		],
	 *		'error' => [
	 *			0 => 0,
	 *			1 => 0,
	 *		],
	 *		'size' => [
	 *			0 => 166980,
	 *			1 => 41,
	 *		],
	 *	],
	 * ];
	 * 
	 * @param array $data данные аттрибута
	 * @return \dicr\filestore\UploadFile[] файлы аттрибута
	 */
	protected static function parseAttribData(array $data) {
		return static::attributeInstances(
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
	 * <input type="file" name="formName[attibute]"/>
	 *
	 * $_FILES = [
	 *	'$formName' => [
	 *		'name' => [
	 *			'$attribute' => 'test.php'
	 *		],
	 *		'type' => [
	 *			'$attribute' => 'application/x-php'
	 *		],
	 *		'tmp_name' => [
	 *			'$attribute' => '/tmp/phpQ2c8T7'
	 *		],
	 *		'error' => [
	 *			'$attribute' => 0
	 *		],
	 *		'size' => [
	 *			'$attribute' => 41
	 *		]
	 *	]
	 * ];
	 * 
	 * 
	 * <input type="file" name="formName[attibute][]"/>
	 *
	 * $_FILES = [
	 *	'$formName' => [
	 *		'name' => [
	 *			'$attribute' => [
	 *				0 => '2018-04-23-195938.jpg',
	 *				1 => 'test.php'
	 *			]
	 *		],
	 *		'type' => [
	 *			'$attribute' => [
	 *				0 => 'image/jpeg',
	 *				1 => 'application/x-php'
	 *			]
	 *		],
	 *		'tmp_name' => [
	 *			'$attribute' => [
	 *				0 => '/tmp/phpqZNTne',
	 *				1 => '/tmp/phpvK409l'
	 *			],
	 *		],
	 *		'error' => [
	 *			'$attribute' => [
	 *				0 => 0,
	 *				1 => 0
	 *			]
	 *		],
	 *		'size' => [
	 *			'attribute' => [
	 *				0 => 166980,
	 *				1 => 41
	 *			]
	 *		]
	 *	]
	 * ];
	 * 
	 * @param array $data array данные аттрибутов формы
	 * @return array [$attribute => \dicrilestore\UploadFile[]] аттрибуты формы с файлами
	 */
	protected static function parseFormData(array $data) {
		$instances = [];
		
		foreach (array_keys($data['name']) as $attribute) {
			$instances[$attribute] = static::attributeInstances(
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
	 * Определяет тип структуры данных $_FILES
	 * 
	 * Структура без формы в name содержит либо строку имени файла, либо массив имен с порядковыми значениями.
	 * 
	 * 'name' => '2018-04-23-195938.jpg'
	 * 
	 * или 
	 * 
	 * 'name' => [
	 *		0 => '2018-04-23-195938.jpg',
	 *		1 => 'test.php',
	 *	];
	 *
	 * Структура с именем формы в аттрибуте в name содержит массив имен аттрбутов, значениями которых являются массивы имен файлов
	 * 
	 * 'name' => [
	 *		'$attribute' => 'test.php'
	 *	],
	 * 
	 * 'name' => [
	 *		'$attribute' => [
	 *			0 => '2018-04-23-195938.jpg',
	 *			1 => 'test.php'
	 *		]
	 *	],
	 * 
	 * @param array $data
	 * @throws Exception
	 * @return boolean
	 */
	protected static function detectFormData(array $data) {

		// если не установлен name, то ошибка формата данных
		if (!isset($data['name'])) {
			throw new Exception('Некорректная структура данных $_FILES: '.var_export($data));
		}
		
		// если name не массив - однозначно не форма
		if (!is_array($data['name'])) return false;

		// если в name массив массивов - однозначно форма
		if (is_array(reset($data['name']))) return true;
		
		// средний вариант определяем по типу ключей в name.
		$keys = array_keys($data['name']);
		return !preg_match('~^\d+$~', $keys[0]);
	}
	
	/**
	 * Парсит структуру $_FILES и создает объекты
	 * 
	 * @return array [$formName => [$attribute => \dicrilestore\UploadFile[]]] 
	 * 
	 * [
	 *   // файлы аттрибутов без формы
	 *   '' => [	
	 *   	$attribute => \dicrilestore\UploadFile[]
	 *   ],
	 *   
	 *   // файлы аттрибутов формы
	 *   $formName => [	// файлы аттрибутов с названием формы
	 *   	$attribute => \dicrilestore\UploadFile[]
	 *   ]
	 * ]
	 * 
	 */
	protected static function parseInstances() {
		$instances = [];
		
		if (!empty($_FILES)) foreach ($_FILES as $key => $data) {

			if (static::detectFormData($data)) {
				// аттрибуты формы, $key == $formName, $data == аттрибуты в формате формы 
				$instances[$key] = static::parseFormData($data);
			} else {
				// аттрибут без формы, $formName == '', $key == $attribute, $data == данные в формате одного аттрибуты
				$instances[''][$key] = static::parseAttribData($data);
			}
		}
		
		return $instances;
	}
	
	/**
	 * Возвращает файлы аттрибутов формы загруженные в $_FILES или null.
	 * 
	 * @param string|null $formName имя формы для которой возвращает аттрибуты
	 * @param string|null $attribute если задан, то возвращает файлы только для аттрибута
	 * @return mixed
	 */
	public static function instances(string $formName='', $attribute=null) {
		if (!isset(self::$_instances)) {
			self::$_instances = self::parseInstances();
		}
		
		$formName = trim($formName);
		$path = [$formName];
		if (!empty($attribute)) $path[] = $attribute;
		
		return ArrayHelper::getValue(self::$_instances, $path);
	}
	
	/**
	 * Создает UploadFile инициализированный из File
	 * 
	 * @param File $file
	 * @return \dicr\filestore\UploadFile
	 */
	public static function fromFile(File $file) {
		return new static([
			'path' => $file->path,
			'name' => $file->name
		]);
	}
}
