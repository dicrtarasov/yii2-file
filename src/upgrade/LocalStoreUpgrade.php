<?php
namespace dicr\file\upgrade;

use dicr\file\AbstractFileStore;
use yii\base\Component;
use yii\di\Instance;
use dicr\file\StoreFile;

/**
 * Изменение схемы харнилища файлов при переходе от схемы:
 * {model}/{id}/{attribute}/{id}-{name}
 *
 * к схеме:
 * {model}/{id}/{attibute}-{id}-{name}
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
 *
 */
class LocalStoreUpgrade extends Component
{
    /** @var \dicr\file\AbstractFileStore хранилище для конвертирования */
    public $store;

    /**
     * {@inheritDoc}
     * @see \yii\base\BaseObject::init()
     */
    public function init()
    {
        parent::init();

        $this->store = Instance::ensure($this->store, AbstractFileStore::class);
    }

    /**
     * Выполняет переименовывание файлов.
     */
    public function process()
    {
        foreach ($this->store->list('', ['dir' => true]) as $modelsDir) {
            foreach ($modelsDir->list(['dir' => true]) as $idDir) {
                foreach ($idDir->list(['dir' => true]) as $attrDir) {
                    foreach ($attrDir->list(['dir' => false]) as $file) {
                        // разбираем имя файла
                        $matches = null;
                        if (preg_match(StoreFile::STORE_PREFIX_REGEX, $file->name, $matches)) {
                            $pos = (int)$matches[1];
                            $name = $matches[1];

                            printf("%s : %d : %s : %d : %s\n", $modelsDir->name, $idDir->name, $attrDir->name, $pos, $name);
                        }
                    }
                }
            }
        }
    }
}