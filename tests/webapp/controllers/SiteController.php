<?php
/**
 * Copyright (c) 2019.
 *
 * @author Igor (Dicr) Tarasov, develop@dicr.org
 */

declare(strict_types = 1);
namespace app\controllers;

use app\models\TestModel;
use Yii;
use yii\web\Controller;

/**
 * Default test Controller
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
 */
class SiteController extends Controller
{
    /**
     * Индекс
     *
     * @return string
     */
    public function actionIndex()
    {
        $model = TestModel::findOne(['id' => 1]);
        if ($model === null) {
            $model = new TestModel();
        }

        if (Yii::$app->request->isPost) {
            $model->load(Yii::$app->request->post());
            $model->loadFileAttributes();

            if ($model->validate()) {
                $model->save();
                return $this->redirect(['index'], 303);
            }
        }

        return $this->render('index', [
            'model' => $model
        ]);
    }
}
