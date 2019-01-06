<?php
namespace dicr\file;

use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\base\Model;
use yii\db\ActiveRecord;

/**
 * Храниище в файловой системе.
 *
 * @property string $path базовый путь
 * @property string|null $url базовый url
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 180624
 */
class FileStore extends Component
{

    /** @var string путь к директории файлов */
    private $path;

    /** @var string|null URL директории файлов */
    private $url;

    /** @var int права на файлы */
    public $filemode = 0644;

    /** @var int права на директории */
    public $dirmode = 0755;

    /**
     * {@inheritdoc}
     * @see \yii\base\BaseObject::init()
     */
    public function init()
    {
        if (! isset($this->path)) {
            throw new InvalidConfigException('path');
        }
        parent::init();
    }

    /**
     * Возвращает путь
     *
     * @param string|null $relative относительный путь от базового
     * @return string абсолютный путь
     */
    public function getPath(string $relative = '')
    {
        $path = [
            $this->path
        ];

        $relative = trim($relative, '/');
        if ($relative != '') {
            $path[] = $relative;
        }

        return implode('/', $path);
    }

    /**
     * Устанавливает корневой путь
     *
     * @param string $path
     * @throws \dicr\file\StoreException
     * @return static
     */
    public function setPath(string $path)
    {
        $path = rtrim($path, '/');
        if ($path === '') {
            throw new \InvalidArgumentException('path');
        }

        $path = \Yii::getAlias($path, true);
        $path = realpath($path);

        if (! @file_exists($path)) {
            throw new StoreException('директория не существует: ' . $path);
        }

        if (! @is_dir($path)) {
            throw new StoreException('не является директорией: ' . $path);
        }

        $this->path = $path;

        return $this;
    }

    /**
     * Возвращает url
     *
     * @param string|null $relative относительный путь
     * @return string
     */
    public function getUrl(string $relative = '')
    {
        if ($this->url == '') {
            return null;
        }

        $url = [
            $this->url
        ];

        $relative = trim($relative, '/');
        if ($relative != '') {
            $url[] = $relative;
        }

        return implode('/', $url);
    }

    /**
     * Устанавливает url
     *
     * @param string $url
     * @retun static
     */
    public function setUrl(string $url)
    {
        $this->url = \Yii::getAlias($url, true);
        if (empty($this->url)) {
            $this->url = null;
        }

        return $this;
    }

    /**
     * Возвращает элемент файла
     *
     * @param string $relpath относительный путь файла
     * @return \dicr\file\File
     */
    public function file(string $relpath)
    {
        return new File([
            'store' => $this,
            'path' => $relpath
        ]);
    }

    /**
     * Читает содержимое директории
     *
     * @param string $relpath относительное имя директории
     * @param array $optons - string $regex регулярная маска имени
     *        - bool $dirs true - только директории, false - только файлы
     *        - bool $skipHidden - пропускать файлы/директори, начинающиеся с точки (скрытые)
     * @throws \dicr\file\StoreException
     * @return \dicr\file\File[]
     */
    public function list(string $relpath = '', array $options = [])
    {
        $relpath = trim($relpath, '/');
        $path = $this->getPath($relpath);
        if (! @file_exists($path)) {
            return [];
        }

        $dir = @opendir($path);
        if ($dir === false) {
            throw new StoreException(null);
        }

        $files = [];
        while ($file = readdir($dir)) {
            // пропускаем служебные файлы
            if ($file == '.' || $file == '..') {
                continue;
            }

            // пропускаем скрытые файлы
            if (! empty($options['skipHidden']) && substr($file, 0, 1) == '.') {
                continue;
            }

            // пропускаем по регулярному выражению
            if (! empty($options['regex']) && ! preg_match($options['regex'], $file)) {
                continue;
            }

            if (isset($options['dirs']) &&
                (! empty($options['dirs']) && ! @is_dir($path . '/' . $file) ||
                empty($options['dirs']) && is_dir($path . '/' . $file))) {
                continue;
            }

            $files[$file] = $this->file($relpath . '/' . $file);
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
     * @return \dicr\file\File
     */
    public function getModelPath(Model $model, string $attribute = '', string $file = '')
    {
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
    }
}
