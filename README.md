# Файловая библиотека для Yii2

Содержит следующий функционал:

- `FileStore` - абстракция файлового хранилища;
    - `LocaFileStore` - реализация хранилища в локальной файловой системе;
    - `FtpFileStore` - хранилище файлов на FTP;
    - `SftpFileStore` - хранилище файлов через SFTP;
    - `FlysystemFileStore` - хранилище через библиотеку Flysystem;

- `File` - абстракция файла, поддержка операций с файлом;
    - `UploadFile` - загрузка файлов в хранилище из другого файла или данных `$_FILES`;
    - `ThumbFile` - создание превью картинок с кешем на диске;
    - `CSVFile` - абстракция для работы с CSV-файлами;
        - `CSVResponseFormatter` - формирование ответа в виде CSV-файла;

- `FileAttributeBehavior` - поддержка файловых аттрибутов моделей;

- `FileInputWidget` - поддержка полей форм для загрузки и редактирования файлов/картинок аттрибутов моделей;

## Работа с файловым хранилищем

Конфигурация компонента на примере локального хранилища файлов:

```php
$config = [
    'components' => [
        // хранилище файлов
        'fileStore' => [
            'class' => dicr\file\LocalFileStore::class,
            'path' => '@webroot/files', // базовый путь на диске
            'url' => '@web/files' // базовый URL для скачивания (опционально)
        ]
    ]
];
```

Использование:

```php
/** @var dicr\file\FileStore $store получаем настроенный компонент хранилища */
$store = Yii::$app->get('fileStore');

// или например хранилище в локальной файловой системе
$store2 = dicr\file\LocalFileStore::root();

// список файлов хранилища в директории pdf
$files = $store->list('pdf');

// Получаем файл по имени
$file = $store->file('pdf/my-report.pdf');

// выводим содержимое файла
echo $file->content;

// выводим url файла в хранилище
echo $file->url;
```

## Создание превью картинок

Для работы с превью нужно настроить кеш картинок в локальной файловой системе:

```php
$config = [
    'components' => [
        // хранилище для превью картинок
        'thumbStore' => [
            'class' => dicr\file\LocalFileStore::class,
            'path' => '@webroot/thumb',
            'url' => '@web/thumb',
        ],
    
        // основное хранилище файлов
        'fileStore' => [
            'class' => dicr\file\LocalFileStore::class,
            'path' => '@webroot/files',
            // конфигурация компонента для создания превью
            'thumbFileConfig' => [
                'store' => 'thumbStore', // компонент хранилища для кэша картинок
                'noimage' => '@webroot/res/img/noimage.png' // заглушка для создания превью несуществующих файлов моделей
            ]
        ]
    ]
];
```

Использование превью:

```php
use yii\helpers\Html;
use dicr\file\FileStore;

/** @var FileStore $store */
$store = Yii::$app->get('fileStore');

// получаем файл из хранилища
$file = $store->file('images/image.jpg');

// выводим картинку превью
echo Html::img($file->thumb(['width' => 320, 'height' => 240])->url);
```

## Файловые аттрибуты модели

Пример модели товара:

```php
use yii\db\ActiveRecord;
use dicr\file\File;
use dicr\file\FileAttributeBehavior;

/**
 * @property-read ?File $image одна картинка
 * @property-read File[] $docs набор файлов документов
 * 
 * FileAttributeBehavior
 * 
 * @method bool loadFileAttributes($formName = null)
 * @method saveFileAttributes()
 * @method File|File[]|null getFileAttribute(string $attribute, bool $refresh = false)
 */
class Product extends ActiveRecord
{
    /**
     * @inheritDoc
     */
    public function behaviors() : array
    {
        return [
            // добавляем файловые аттрибуты
            'file' => [
                'class' => FileAttributeBehavior::class,
                'attributes' => [
                    'image' => 1, // одна картинка
                    'docs' => 0 // неограниченное кол-во файлов
                ]
            ]   
        ];       
    }

    /**
     * @inheritDoc
     */
    public function load($data, $formName = null) : bool
    {
        $ret = parent::load($data, $formName);

        // загружаем файловые аттрибуты
        if ($this->loadFileAttributes($formName)) {
            $ret = true;
        }

        return $ret;
    }
}
```

Использование файловых аттрибутов:

```php
use dicr\file\UploadFile;use yii\db\ActiveRecord;use yii\helpers\Html;

/**
 * @var ActiveRecord $model
 */

// добавляем картинку товару
$model->image = new UploadFile('/tmp/newimage.jpg');

// сохраняем
$model->save();

// выводим превью картинки товара
echo Html::img((string)$model->image->thumb(['width' => 320, 'height' => 200]));

// выводим ссылки загрузки файлов товара
foreach ($model->docs ?: [] as $doc) {
    echo Html::a($doc->name, $doc->url);
}
```

Форма редактирования файлов товара:

```php
use dicr\file\FileInputWidget;use yii\db\ActiveRecord;
use yii\widgets\ActiveForm;

/**
 * @var ActiveForm $form
 * @var ActiveRecord $model
 */

// поле с виджетом редактирования картинки
echo $form->field($model, 'image')->widget(FileInputWidget::class, [
  'layout' => 'images',
  'limit' => 1,
  'accept' => 'image/*',
  'removeExt' => true
]);

// поле с виджетом редактирования документов
echo $form->field($model, 'docs')->widget(FileInputWidget::class, [
  'layout' => 'files',
  'limit' => 0,
  'removeExt' => true
]);
```
