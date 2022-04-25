<?php

declare(strict_types=1);

use PhpCsFixer\Fixer\ReturnNotation\ReturnAssignmentFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;
use Zing\CodingStandard\Set\ECSSetList;

return static function (ECSConfig $ecsConfig): void {
    $ecsConfig->sets([ECSSetList::PHP_72, ECSSetList::CUSTOM]);
    $ecsConfig->parallel();
    $ecsConfig->skip([
        \PHP_CodeSniffer\Standards\PSR1\Sniffs\Methods\CamelCapsMethodNameSniff::class => [
            __DIR__ . '/tests/OssAdapterTest.php',
        ],
        \PhpCsFixer\Fixer\PhpUnit\PhpUnitMethodCasingFixer::class => [__DIR__ . '/tests/OssAdapterTest.php'],
        \PhpCsFixer\Fixer\PhpUnit\PhpUnitTestAnnotationFixer::class => [__DIR__ . '/tests/OssAdapterTest.php'],
        \SlevomatCodingStandard\Sniffs\TypeHints\ReturnTypeHintSniff::class,
        // bug
        ReturnAssignmentFixer::class,
    ]);
    $ecsConfig->paths([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/ecs.php', __DIR__ . '/rector.php']);
};
