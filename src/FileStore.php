<?php 
namespace dicr\filestore;

use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\base\Model;
use yii\db\ActiveRecord;
use yii\helpers\ArrayHelper;

/**
 * Храниище в файловой системе.
 * 
 * @property string $path базовый путь
 * @property string|null $url базовый url
 * 
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 180624
 */
class FileStore extends Component {
	
	/** @var string путь к директории файлов */
	private $_path;
	
	/** @var string|null URL директории файлов */
	private $_url;
	
	/**
	 * {@inheritDoc}
	 * @see \yii\base\BaseObject::init()
	 */
	public function init() {
		if (!isset($this->_path)) throw new InvalidConfigException('path');
		parent::init();
	}
	
	/**
	 * Возвращает путь
	 *
	 * @param string|array|null $relpath относительный путь
	 * @return string абсолютный путь
	 */
	public function getPath($relpath=null) {
		$path = [$this->_path];
		if (is_array($relpath)) $relpath = implode('/', $relpath);
		$relpath = trim($relpath, '/');
		if ($relpath !== '') $path[] = $relpath;
		return implode('/', $path);
	}
	
	/**
	 * Устанавливает корневой путь
	 *
	 * @param string $path
	 * @throws \dicr\filestore\StoreException
	 * @return static
	 */
	public function setPath(string $path) {
		$path = rtrim($path, '/');
		if ($path === '') throw new \InvalidArgumentException('path');
		$this->_path = \Yii::getAlias($path, true);
		if (!file_exists($this->_path)) throw new StoreException('директория не существует: '.$this->_path);
		if (!is_dir($this->_path)) throw new StoreException('не является директорией: '.$this->_path);
		$this->_path = @realpath($this->_path);
		return $this;
	}
	
	/**
	 * Возвращает url
	 *
	 * @param string|array|null $relpath относительный путь
	 * @return string полный url
	 */
	public function getUrl($relpath=null) {
		$url = [$this->_url];
		if (is_array($relpath)) $relpath = implode('/', $relpath);
		$relpath = trim($relpath, '/');
		if ($relpath !== '') $url[] = $relpath;
		return implode('/', $url);
	}
	
	/**
	 * Устанавливает url
	 *
	 * @param string $url
	 * @retun static
	 */
	public function setUrl(string $url) {
		$this->_url = !empty($url) ? \Yii::getAlias($url, true) : null;
		return $this;
	}
	
	/**
	 * Возвращает элемент файла
	 *
	 * @param string|array $relpath относительный путь файла
	 * @return \dicr\filestore\File
	 */
	public function file($relpath) {
		return new File([
			'store' => $this, 
			'relpath' => $relpath
		]);
	}
	
	/**
	 * Читает содержимое директории
	 *
	 * @param string|array|null $relpath относительное имя директории
	 * @param array $optons
	 * - string $regex регулярная маска имени
	 * - bool $dirs true - только директории, false - только файлы
	 * @throws \dicr\filestore\StoreException
	 * @return \dicr\filestore\File[]
	 */
	public function list($relpath=null, array $options=[]) {
		$regex = ArrayHelper::getValue($options, 'regex');
		$dirs = ArrayHelper::getValue($options, 'dirs');
		
		if (is_array($relpath)) $relpath = implode('/', $relpath);
		$relpath = trim($relpath, '/');
		
		$path = $this->getPath($relpath);
		if (!file_exists($path)) return [];
		
		$dir = opendir($path);
		if (!$dir) throw new StoreException('ошибка чтения каталога: '.$path);
		
		$files = [];
		while ($file = readdir($dir)) {
			if ($file == '.' || $file == '..') continue;
			if (!empty($regex) && !preg_match($regex, $file)) continue;
			if (isset($dirs) && ($dirs && !@is_dir($path.'/'.$file) || !$dirs && is_dir($path.'/'.$file))) continue;
			$files[$file] = $this->file($relpath.'/'.$file);
		}
		closedir($dir);
		ksort($files);
		return array_values($files);
	}
	
	/**
	 * Возвращает путь модели/аттрибута
	 *
	 * @param \yii\base\Model $model модель
	 * @param string|null $attribute имя аттрибута модели
	 * @param string|null $file название файла
	 * @return \dicr\filestore\File
	 */
	public function getModelPath(Model $model, string $attribute=null, string $file=null) {
		if (empty($model)) throw new \InvalidArgumentException('model');
		if ($attribute == '') throw new \InvalidArgumentException('attribute');
		
		$relpath = [
			basename($model->formName())
		];
		
		if ($model instanceof ActiveRecord) {
			$keyName = basename(implode('~', $model->getPrimaryKey(true)));
			if ($keyName !== '') $relpath[] = $keyName;
		}
		
		$attribute = basename(trim($attribute));
		if ($attribute !== '') {
			$relpath[] = $attribute;
		}
		
		$file = basename(trim($file, '/'));
		if ($file !== '') {
			$relpath[] = $file;
		}
		
		return $this->file($relpath);
	}
}