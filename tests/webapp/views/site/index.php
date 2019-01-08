<?php 
use dicr\file\FileInputWidget;
use yii\bootstrap\ActiveForm;
use yii\bootstrap\Html;

/**
 * Test View
 * 
 * @var \yii\web\View $this
 * @var \app\models\TestModel $model
 */
?>

<?php $this->beginPage() ?>

<html style="font-size: 15px">
<head>
	<meta charset="UTF-8"/>
	<title>dicr\\file\\Storage test</title>
	<link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.6.3/css/all.css"/>
	<?php $this->head() ?>
</head>
<body style="font-size: 1rem">
	<?php $this->beginBody() ?>
	
	<div class="container">
		<h1 style="margin-bottom: 2rem">Тест загрузки файлов</h1>
	
		<?php $form = ActiveForm::begin([
			'layout' => 'horizontal',
			'fieldConfig' => [
				'horizontalCssClasses' => [
					'label' => 'col-sm-1',
					'offset' => 'offset-sm-1',
					'wrapper' => 'col-sm-10',
					'error' => '',
					'hint' => '',
				],
			]
		])?>
		
			<?=$form->field($model, 'icon')->widget(FileInputWidget::class, [
				'limit' => 1,
				'accept' => 'image/*',
			])?>
			
			<?=$form->field($model, 'pics[]')->widget(FileInputWidget::class)?>
			
			<div class="form-group">
				<div class="col-sm-10 col-sm-offset-1">
					<?=Html::submitButton('send', [
						'class' => 'btn btn-primary'
					])?>
				</div>
			</div>
			
		<?php $form->end() ?>
	</div>
	
	<?php $this->endBody() ?>
</body>
</html>

<?php $this->endPage() ?>