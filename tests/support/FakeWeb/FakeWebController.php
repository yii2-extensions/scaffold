<?php

declare(strict_types=1);

namespace yii\scaffold\tests\support\FakeWeb;

use yii\web\Controller;

/**
 * Test fixture: a controller that extends {@see Controller} (web) instead of {@see \yii\console\Controller}, used to
 * cover the defensive `instanceof` guard in {@see \yii\scaffold\Commands\HelpController::actionIndex()}.
 *
 * @author Wilmer Arambula <terabytesoftw@gmail.com>
 * @since 0.1
 */
final class FakeWebController extends Controller {}
