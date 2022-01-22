<?php
/*
 * @copyright 2019-2022 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL-3.0-or-later
 * @version 23.01.22 01:04:18
 */

declare(strict_types=1);
namespace dicr\file;

use Closure;
use yii\base\Component;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\base\Model;
use yii\db\ActiveRecord;
use yii\di\Instance;

use function in_array;

/**
 * Очистка лишних аттрибутов модели.
 */
class FileAttributeCleaner extends Component
{
    /** @var FileStore|string файловое хранилище */
    public FileStore|string $store;

    /** @var Model модель */
    public Model $model;

    /** @var string[] id существующих моделей */
    public array $existsIds;

    /** @var Closure function(File $nonExists) обработчик */
    public Closure $callback;

    /**
     * @inheritDoc
     * @throws InvalidConfigException
     */
    public function init(): void
    {
        parent::init();

        $this->store = Instance::ensure($this->store);

        if (!isset($this->model)) {
            throw new InvalidConfigException('model');
        }

        if (!isset($this->existsIds)) {
            $this->existsIds = $this->getExistentIds();
        }

        if (!isset($this->callback)) {
            throw new InvalidConfigException('callback');
        }
    }

    /**
     * Находит ключи существующих моделей.
     *
     * @return string[]
     * @throws InvalidConfigException
     */
    private function getExistentIds(): array
    {
        if ($this->model instanceof ActiveRecord) {
            $primaryKey = $this->model::primaryKey();
            if (empty($primaryKey)) {
                throw new InvalidConfigException('Модель не содержит primaryKey');
            }

            $existsIds = [];

            $existsIdsQuery = $this->model::find()
                ->select($primaryKey)
                ->asArray();

            foreach ($existsIdsQuery->each() as $key) {
                $existsIds[] = FileAttributeBehavior::primaryKeyPath(
                    $key,
                    $this->store->pathSeparator
                );
            }
        } else {
            throw new InvalidConfigException('Не заданы existsIds и модель не является ActiveRecord');
        }

        return $existsIds;
    }

    /**
     * Сканирует папку модели и возвращает кол-во найденных папок несуществующих моделей.
     * Для каждой найденной вызывает callback.
     *
     * @return int кол-во найденных
     * @throws Exception
     */
    public function process(): int
    {
        $keysFound = 0;

        $modelsPaths = $this->store->file($this->model->formName())->getList([
            'dir' => true
        ]);

        foreach ($modelsPaths as $modelPath) {
            $id = $modelPath->name;
            if (!in_array($id, $this->existsIds, true)) {
                $keysFound++;
                ($this->callback)($modelPath);
            }
        }

        return $keysFound;
    }
}
