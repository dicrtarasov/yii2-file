<?php 
namespace dicr\filestore;

use yii\base\BaseObject;
use yii\base\InvalidConfigException;
use yii\helpers\ArrayHelper;

/**
 * Файл, хранящийся в файловой системе.
 * 
 * Если задано store, то путь файла относительно этого store,
 * иначе путь абсолютный.
 * 
 * @property \dicr\filestore\FileStore $store базовый путь
 * @property string $relpath относительный путь. Если не задан store, то путь считается абсолютным
 * 
 * @property-read string $path полный путь без имени файла
 * @property string $name имя файла без пути
 * @property-read string $url полный url
 * 
 * @property-read bool $exists
 * @property-read bool $readable
 * @property-read bool $writeable
 * @property-read bool $isDir
 * @property-read bool $isFile
 * @property-read int $size
 * @property-read int $time
 * 
 * @property-read \dicr\filestore\File[] $list
 * @property string $content
 * 
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 180624
 */
class File extends BaseObject {
	
	/** @var \dicr\filestore\FileStore */
	private $_store;
	
	/** @var string путь файла */
	private $_path;
	
	/**
	 * {@inheritDoc}
	 * @see \yii\base\BaseObject::init()
	 */
	public function init() {
		parent::init();

		if (!($this->store instanceof FileStore)) {
			throw new InvalidConfigException('store');
		}
		
		if (!isset($this->relpath)) {
			throw new InvalidConfigException('relpath');
		}
	}

	/**
	 * Возвращает хранилище
	 * 
	 * @return \dicr\filestore\FileStore
	 */
	public function getStore() {
		return $this->_store;
	}

	/**
	 * Устанавливает хранилище
	 *  
	 * @param FileStore $store
	 * @return static
	 */
	public function setStore(FileStore $store) {
		if (empty($store)) throw new \InvalidArgumentException('store');
		$this->_store = $store;
	}
	
	/**
	 * Возвращает относительный путь
	 * 
	 * @param string|array|null $relpath путь, относительный текущего файла
	 * @return string путь, относительно store->path
	 */
	public function getRelpath($relpath=null) {
		$path = [];
		if (!empty($this->_path)) $path[] = $this->_path;
		
		if (is_array($relpath)) $relpath = implode('/', $relpath);

		$relpath = trim($relpath, '/');
		if ($relpath !== '') $path[] = $relpath;

		return !empty($path) ? implode('/', $path) : null;
	}
	
	/**
	 * Устанавливает относительный путь
	 * 
	 * @param string|array $path путь, относительно store->path
	 * @return static
	 */
	public function setRelpath($path) {
		// сохраняем старый путь
		$oldpath = null;
		if (isset($this->_path) && $this->exists) {
			$oldpath = $this->path;
		}
		
		// устанавливаем новый путь
		if (is_array($path)) $path = implode('/', $path);
		$path = trim($path, '/');
		if ($path === '') throw new \InvalidArgumentException('path');
		$this->_path = $path;
		
		// если по старому имени был файл, то переименовываем
		if (isset($oldpath) && !@rename($oldpath, $this->path)) {
			throw new StoreException(sprintf('ошибка переименования файла: %s в %s', $oldpath, $this->path));
		}
		
		return $this;
	}
	
	/**
	 * Возвращает полный путь
	 * 
	 * @return string полный путь
	 */
	public function getPath() {
		return !empty($this->store) ? $this->store->getPath($this->relpath) : $this->relpath;
	}
	
	/**
	 * Устаовить путь файла
	 * 
	 * @param string|array $path полный путь
	 * @return static
	 */
	public function setPath($path) {
		if (is_array($path)) $path = implode('/', $path);

		$path = trim($path, '/');
		if ($path == '') throw new \InvalidArgumentException('path');
		
		// если не установлен store, то устанавливаем как абсолютный путь
		if (!empty($this->store)) {
			// вырезаем базовый путь store и получаем относительный
			if (mb_strpos($path, $this->store->path.'/') === 0) {
				$path = substr($path, mb_strlen($this->store->path) + 1);
			}
		}
		
		$this->relpath = $path;
	}
	
	/**
	 * Возвращает имя файла
	 * 
	 * @param array $options опции
	 * - bool $removePrefix удаляить префикс позиции (^\d+~)
	 * - bool $removeExt удалить расширение
	 * @return string|null basename($relpath)
	 */
	public function getName(array $options=[]) {
		$name = basename($this->relpath);
		if ($name == '') return null;
		
		if (ArrayHelper::getValue($options, 'removePrefix')) {
			$matches = null;
			if (preg_match('~^\d+\~(.+)$~uism', $name, $matches)) $name = $matches[1];
		}
		
		if (ArrayHelper::getValue($options, 'removeExt')) {
			$matches = null;
			if (preg_match('~^(.+)\.[^\.]+$~uism', $name, $matches)) $name = $matches[1];
		}
		
		return $name;
	}
	
	/**
	 * Устанавливает имя файла
	 * 
	 * @param string $name новое имя файла
	 * @return static
	 */
	public function setName(string $name) {
		$name = basename($name);
		if ($name === '') throw new \InvalidArgumentException('name');
		
		$relpath = [];
		
		if (!empty($this->relpath)) {
			$dirname = dirname($this->relpath);
			if ($dirname !== '' && $dirname !== '.') $relpath[] = $dirname;
		}
		
		$relpath[] = $name;
		$this->relpath = $relpath;
		return $this;
	}
	
	/**
	 * Возвращает полный URL
	 * 
	 * @return string|null полный URL
	 */
	public function getUrl() {
		return !empty($this->store) ? $this->store->getUrl($this->relpath) : null;;
	}
	
	/**
	 * Возвращает флаг существования файла
	 *
	 * @return bool
	 */
	public function getExists() {
		return @file_exists($this->path);
	}
	
	/**
	 * Возвращает флаг доступности для чтения
	 * 
	 * @return bool 
	 */
	public function getReadable() {
		return @is_readable($this->path);
	}
	
	/**
	 * Возвращает флаг доступности для записи
	 *
	 * @return bool
	 */
	public function getWriteable() {
		return @is_writable($this->path);
	}
	
	/**
	 * Проверяет флаг директории
	 *
	 * @return bool
	 */
	public function getIsDir() {
		return @is_dir($this->path);
	}
	
	/**
	 * Проверяет флаг файла
	 *
	 * @return bool
	 */
	public function getIsFile() {
		return @is_file($this->path);
	}
	
	/**
	 * Возвращает размер
	 *
	 * @throws \dicr\filestore\StoreException
	 * @return int размер в байтах
	 */
	public function getSize() {
		$size = @filesize($this->path);
		if ($size === false) {
			throw new StoreException('ошибка получения размера: '.$this->path);
		}
		return $size;
	}
	
	/**
	 * Возвращает время изменения файла
	 * 
	 * @return int
	 */
	public function getTime() {
		$time = @filemtime($this->path);
		if ($time === false) {
			throw new StoreException('ошибка получения времени: '.$this->path);
		}
		return $time;
	}
	
	/**
	 * Возвращает дочерний файл
	 *
	 * @param string|array $relpath относительный путь
	 * @return self
	 */
	public function child($relpath) {
		if (is_array($relpath)) $relpath = implode('/', $relpath);
		
		$relpath = trim($relpath, '/');
		if ($relpath === '') throw new \InvalidArgumentException('relpath');
		
		return new static([
			'store' => $this->store,
			'relpath' => $this->getRelpath($relpath)
		]);
	}
	
	/**
	 * Возвращает список файлов директории
	 * 
	 * @param array $options
	 * - string|null $regex паттерн имени
	 * - bool|null $dirs true - только директории, false - только файлы
	 * @throws \dicr\filestore\StoreException
	 * @return self[]
	 * @see \dicr\filestore\FileStore::list
	 */
	public function getList(array $options=[]) {
		if (empty($this->store)) throw new InvalidConfigException('store');
		return $this->store->list($this->relpath, $options);
	}
	
	/**
	 * Проверяет наличие/создает каталог для файла.
	 *
	 * @throws \dicr\filestore\StoreException
	 * @return static
	 */
	public function checkDir() {
		$dir = dirname($this->path);
		if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
			throw new StoreException('ошибка создания директории: '.$dir);
		}
		return $this;
	}
	
	/**
	 * Возвращает содержимое файла
	 *
	 * @throws \dicr\filestore\StoreException
	 * @return string|false
	 */
	public function getContent() {
		$content = @file_get_contents($this->path, false);
		if ($content === false) {
			throw new StoreException('ошибка чтения файла: '.$this->path);
		}
		return $content;
	}
	
	/**
	 * Записывает содержимое файла
	 *
	 * @param string $content
	 * @throws \dicr\filestore\StoreException
	 * @return static
	 */
	public function setContent(string $content) {
		$this->checkDir();
		if (@file_put_contents($this->path, $content, false) === false) {
			throw new StoreException('ошибка записи: '.$this->path);
		}
		return $this;
	}
	
	/**
	 * Импорт файла в хранилище
	 *
	 * @param string $src полный путь импортируемого файла
	 * @param array $options опции
	 * - bool $move переместить файл при импорте, иначе скопировать (по-умолчанию false) 
	 * - bool $ifModified - импортировать файл только если время новее или размер отличается (по-умолчанию true)
	 * @throws \dicr\filestore\StoreException
	 * @return static
	 */
	public function import(string $src, array $options=[]) {
		// проверяем аргументы
		if (empty($src)) throw new \InvalidArgumentException('src');
		if (!@is_file($src)) throw new StoreException('исходный файл не найден: '.$src);
		
		// получаем параметры
		$ifModified = (bool)ArrayHelper::getValue($options, 'ifModified', true);
		$move = (bool)ArrayHelper::getValue($options, 'move', false);
		
		// пропускаем старые файлы
		if ($ifModified && $this->exists && @filesize($src) === $this->size && @filemtime($src) <= $this->time) {
			return $this;
		}
		
		// проверяем существование директории
		$this->checkDir();
		
		$func = $move ? 'rename' : 'copy';
		if (!$func($src, $this->path)) {
			throw new StoreException(sprintf('ошибка импортирования %s в %s', $src, $this->path));
		}

		return $this;
	}
	
	/**
	 * Удаляет файл
	 *
	 * @param bool|null $recursive рекурсивно для директорий
	 * @throws \dicr\filestore\StoreException
	 * @return static
	 */
	public function delete(bool $recursive=false) {
		if ($this->exists) {
			if ($this->isDir) {
				if ($recursive) foreach ($this->list as $file) {
					$file->delete(true);
				}
			} else if (!@unlink($this->path)) {
				throw new StoreException('ошибка удаления файла: '.$this->path);
			}
		}
		return $this;
	}
	
	/**
	 * Конвертирует в строку
	 *
	 * @return string path
	 */
	public function __toString() {
		return $this->path;
	}
}
