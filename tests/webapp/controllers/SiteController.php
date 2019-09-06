<?php
namespace app\controllers;

use yii\web\Controller;
use app\models\TestModel;
use yii\web\NotFoundHttpException;

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
	 * @throws NotFoundHttpException
	 * @return string
	 */
	public function actionIndex()
	{
		$model = TestModel::findOne(['id' => 1]);
		if (empty($model)) {
		    $model = new TestModel();
		}

		if (\Yii::$app->request->isPost) {
		    $model->load(\Yii::$app->request->post());
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